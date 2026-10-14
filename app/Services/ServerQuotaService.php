<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * ManduVPN: per-user, per-server traffic cap (Owner decision 2026-10-13, with the purchase-anchor correction).
 *
 * WHAT THE CAP IS. A monthly allowance in GB that an admin sets ON A SERVER, spent PER USER. It is not a
 * server-wide total. Exceeding it restricts THAT NODE ONLY - other entitled nodes and other users are
 * untouched. A server with no configured cap is UNLIMITED, and no default amount is ever invented.
 *
 * WHY THE EXISTING AGGREGATES CANNOT BE USED, measured rather than assumed:
 *   - StatUser has no server_id, and it groups by server_rate, so two servers sharing a rate MERGE INTO ONE
 *     ROW - it could never answer a per-server question even with a column added.
 *   - More decisively, the value is already multiplied by the plan rate (StatUserJob:66-67,76-77 and
 *     TrafficFetchJob:42-43), and this cap is defined on RAW bytes. Dividing the multiplier back out would
 *     put rounding loss inside a decision about someone's paid service.
 *   So raw bytes exist only at the ingestion boundary, UserService::trafficFetch(), and usage is recorded
 *   there into its own per-(user, server, type, period) record. This was never "extend the aggregate".
 *
 * THE PERIOD IS ANCHORED TO THE PURCHASE, NOT THE CALENDAR. Calendar months and a first-of-month reset are
 * DEAD - if any document still says otherwise, that document is stale. A period is exactly PERIOD_DAYS from
 * the user's stored anchor, counted as anchor + n * 30 days, and n is derived from the ORIGINAL anchor so
 * that an additive renewal cannot hand out a fresh allowance. Switching or reconnecting to a node never
 * restarts a period either, because nothing in here reads a connection event.
 *   Deliberately NOT used: UserService::calcResetDayByMonthFirstDay() (calendar) and
 *   calcResetDayByExpireDay() (the day-of-month of the expiry). Both are the superseded basis.
 *
 * LIFETIME ACCOUNTS. expired_at === NULL means no expiry; it does NOT mean no quota. Such a user gets the
 * activation-time anchor and the same 30-day periods, and the cap applies normally.
 *
 * THE RECORDED FIGURE CAN BE AN OVER-COUNT, AND THAT DIRECTION WAS CHOSEN DELIBERATELY. No node report
 * carries a stable batch identifier, and the queue is at-least-once, so a retried batch can be counted
 * twice. Deriving a key was rejected because every candidate UNDER-counts - the IKEv2 one is agent-chosen
 * and second-granular, and the UniProxy path has nothing but a body hash that would discard a genuine
 * repeat - and an under-count is invisible to the user and the operator alike, while an over-count is
 * visible to the person it harms, who has the figure on their own dashboard to dispute.
 * SO, FOR THE ADMIN NOTES: a transient node error or a queue retry can cause usage to be counted twice. A
 * disputed figure is resolved with the per-server cap lever, never by editing recorded data. Body-hash
 * deduplication is barred - its failure mode is dropping real data.
 *
 * IKEV2 NODES HAVE NO INPUT AT ALL, so they report UNKNOWN rather than zero. The IKEv2 node API never
 * calls trafficFetch(): its report answers 'traffic_accounting' => 'deferred' because the agent
 * deliberately does not implement traffic accounting yet and sends no byte counters. The UniProxy family -
 * shadowsocks, trojan, vmess and hysteria, which is what the Owner now calls Cyber - does report, with
 * delta semantics established by the existing jobs' behaviour, so the cap is real there.
 *
 * ENFORCEMENT IS BOUNDED BY THE REPORT INTERVAL, and this code cannot shorten that. A node reports usage
 * periodically, so a user crosses the cap some time before the backend can know it, and an established
 * session is not torn down by anything here - restriction takes effect when the node next fetches its user
 * list. No figure is quoted for that interval on purpose: it is node-side configuration and belongs to
 * another cell, and a number invented here would become the number everyone cites.
 */
class ServerQuotaService
{
    /** A period is exactly this many days from the anchor. Not a calendar month. */
    public const PERIOD_DAYS = 30;
    public const PERIOD_SECONDS = self::PERIOD_DAYS * 86400;

    /**
     * Per-server caps, in GB, as {server_id: gb}. Stored in the existing settings storage - ONE row, and no
     * schema change for the cap itself.
     *
     * Why not on the server row's protocol_settings: Server::castSettingsWithConfig() rebuilds that column
     * from PROTOCOL_CONFIGURATIONS[type] and keeps only the keys that configuration defines, so a cap stored
     * there would be SILENTLY DROPPED on the next admin save (measured in RAO-67). A settings row is also
     * sufficient for enforcement, which only ever needs the cap for ONE node at a time.
     */
    public const KEY_CAPS = 'manduvpn_server_quota_gb';

    public const BYTES_PER_GB = 1073741824;

    /** The user column holding the purchase anchor. Written once at first activation, never by a renewal. */
    public const ANCHOR_COLUMN = 'quota_anchor_at';

    /** The per-(user, server, type, period) usage table. */
    public const USAGE_TABLE = 'v2_server_user_usage';

    /** state() statuses. 'unknown' is never rendered as a zero. */
    public const STATUS_UNLIMITED = 'unlimited';
    public const STATUS_OK = 'ok';
    public const STATUS_OVER = 'over';
    public const STATUS_UNKNOWN = 'unknown';

    // ---------------------------------------------------------------- pure period arithmetic
    // Takes its data as arguments and touches no framework, so the fixtures exercise it with no database.

    /** How many whole periods have elapsed since the anchor. 0 during the first period; never negative. */
    public static function periodIndex(int $anchor, int $now): int
    {
        if ($now <= $anchor) {
            return 0;
        }
        return intdiv($now - $anchor, self::PERIOD_SECONDS);
    }

    /**
     * The start of the period containing $now, as anchor + n * 30 days.
     * Derived from the ORIGINAL anchor, which is what stops an additive renewal from granting a free reset.
     */
    public static function periodStart(int $anchor, int $now): int
    {
        return $anchor + (self::periodIndex($anchor, $now) * self::PERIOD_SECONDS);
    }

    /** The real next reset instant - the end of the current period, not a nominal date. */
    public static function nextResetAt(int $anchor, int $now): int
    {
        return self::periodStart($anchor, $now) + self::PERIOD_SECONDS;
    }

    /** GB to bytes. Null, zero or garbage means UNLIMITED, expressed as null. */
    public static function capBytesFrom($gb): ?int
    {
        if (!is_int($gb) && !(is_string($gb) && ctype_digit($gb))) {
            return null;
        }
        $gb = (int) $gb;
        return $gb > 0 ? $gb * self::BYTES_PER_GB : null;
    }

    /** The cap for one server out of an already-resolved {server_id: gb} map. Null = unlimited. */
    public static function capBytesFor($serverId, array $caps): ?int
    {
        if (!is_int($serverId) && !(is_string($serverId) && ctype_digit((string) $serverId))) {
            return null;
        }
        $key = (string) (int) $serverId;
        return array_key_exists($key, $caps) ? self::capBytesFrom($caps[$key]) : null;
    }

    /**
     * The whole per-node figure a dashboard shows and enforcement reads, from the SAME computation - so the
     * displayed number and the blocked number cannot drift apart.
     *
     * $usedBytes === null means NOT MEASURED, which is a different state from zero.
     * $anchor === null means the period is indeterminate for this user.
     *
     * ---------------------------------------------------------------------------------------------------
     * DELIBERATE EXCEPTION TO THE FAIL-CLOSED RULE. DO NOT "FIX" THIS TO FAIL CLOSED.
     * Everywhere else in this codebase an unresolvable policy refuses the request: signup domains, node
     * eligibility, the partial-tiering guard. Here it does the opposite, on purpose and by ruling.
     * If the anchor or the usage figure is indeterminate, over_limit is FALSE - the user keeps service.
     * The reason is which failure you would rather explain to the person it happens to: failing closed here
     * denies service to someone who has PAID for it, because of a number we failed to measure. Briefly not
     * enforcing a cap is the lesser harm, and it is visible rather than silent, because the status is
     * reported as 'unknown' and the condition is logged.
     * Fail CLOSED remains the rule for access policy. This exception covers quota accounting only.
     * ---------------------------------------------------------------------------------------------------
     */
    public static function state(?int $anchor, ?int $usedBytes, ?int $capBytes, int $now): array
    {
        $resetAt = $anchor === null ? null : self::nextResetAt($anchor, $now);
        $periodStart = $anchor === null ? null : self::periodStart($anchor, $now);

        if ($capBytes === null) {
            // Unlimited: there is no allowance to spend, so used is reported but remaining is meaningless.
            return [
                'status' => self::STATUS_UNLIMITED,
                'cap_bytes' => null,
                'used_bytes' => $usedBytes,
                'remaining_bytes' => null,
                'period_start_at' => $periodStart,
                'reset_at' => $resetAt,
                'over_limit' => false,
            ];
        }

        if ($anchor === null || $usedBytes === null) {
            // See the block comment above: indeterminate fails OPEN, and says so rather than showing 0.
            return [
                'status' => self::STATUS_UNKNOWN,
                'cap_bytes' => $capBytes,
                'used_bytes' => null,
                'remaining_bytes' => null,
                'period_start_at' => $periodStart,
                'reset_at' => $resetAt,
                'over_limit' => false,
            ];
        }

        $over = $usedBytes >= $capBytes;
        return [
            'status' => $over ? self::STATUS_OVER : self::STATUS_OK,
            'cap_bytes' => $capBytes,
            'used_bytes' => $usedBytes,
            'remaining_bytes' => max(0, $capBytes - $usedBytes),
            'period_start_at' => $periodStart,
            'reset_at' => $resetAt,
            'over_limit' => $over,
        ];
    }

    // ---------------------------------------------------------------- settings and model backed

    /** The configured caps, normalized to {"<server_id>": gb}. A malformed row yields no caps at all. */
    public static function caps(): array
    {
        // THIS DEFAULT MUST STAY EMPTY, AND THE REASON IS A TRAP THAT ARMS ITSELF.
        // admin_setting() resolves a STORED NULL to the default it is given (Helpers/Functions.php:23 is
        // `->get($key) ?? $default`). That is harmless here ONLY because the default is [] - absent, stored
        // null and {} all converge on "no caps", which means unlimited, and there is no Owner default cap
        // to resurrect. The signup domain keys had the same chain and it WAS a defect there, because their
        // default was a populated list, so clearing the admin field silently restored it.
        // So: IF THIS DEFAULT EVER STOPS BEING EMPTY, THIS KEY ACQUIRES THAT DEFECT BY INHERITANCE - no new
        // mistake required, just a changed default. Whoever gives it a non-empty default must read the raw
        // value with a sentinel instead, the way SignupEmailPolicyService::resolveDomains() does.
        // This safety also rests on the no-invented-cap-amounts rule: one rule holding up another.
        $raw = admin_setting(self::KEY_CAPS, []);
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $serverId => $gb) {
            if (!is_int($serverId) && !(is_string($serverId) && ctype_digit((string) $serverId))) {
                continue;
            }
            if (self::capBytesFrom($gb) === null) {
                continue; // unset, zero or garbage - that server stays unlimited
            }
            $out[(string) (int) $serverId] = (int) $gb;
        }
        return $out;
    }

    /** The cap for one server, in bytes. Null = unlimited. */
    public static function capBytes(int $serverId): ?int
    {
        return self::capBytesFor($serverId, self::caps());
    }

    /**
     * The user's stored purchase anchor, or null when it is indeterminate.
     * Never derived on read: a derived anchor would make a past period's boundary move whenever an unrelated
     * order event changed, and for a cap that governs entitlement a mutable past is disqualifying. The
     * one-time derivation lives in the migration instead.
     */
    public static function anchorOf(User $user): ?int
    {
        $anchor = $user->{self::ANCHOR_COLUMN} ?? null;
        return is_numeric($anchor) && (int) $anchor > 0 ? (int) $anchor : null;
    }

    /**
     * Raw bytes this user has spent on this node in the current period, or null when NOT MEASURED.
     *
     * Returns null - not 0 - when the usage table is absent, because the migration is written and not
     * applied. A missing table is an unmeasured figure, and reporting it as zero would silently claim the
     * user has used nothing.
     */
    public static function usedBytes(int $userId, int $serverId, string $nodeType, int $periodStart): ?int
    {
        if (!Schema::hasTable(self::USAGE_TABLE)) {
            Log::warning('ServerQuotaService: ' . self::USAGE_TABLE . ' is absent, usage is unmeasured and the cap is not enforced');
            return null;
        }
        $row = DB::table(self::USAGE_TABLE)
            ->where('user_id', $userId)
            ->where('server_id', $serverId)
            ->where('node_type', $nodeType)
            ->where('period_start_at', $periodStart)
            ->first();
        if (!$row) {
            // No row yet in this period is a genuine zero: the period started and nothing was reported.
            return 0;
        }
        return (int) $row->u + (int) $row->d;
    }

    /** The full per-node figure for one user, for both the dashboard and enforcement. */
    public static function stateFor(User $user, int $serverId, string $nodeType, ?int $now = null): array
    {
        $now = $now ?? time();
        $anchor = self::anchorOf($user);
        $capBytes = MasterAccess::isUser($user) ? null : self::capBytes($serverId);
        if ($capBytes === null) {
            // Unlimited server: do not query usage at all.
            return self::state($anchor, null, null, $now);
        }
        $used = $anchor === null
            ? null
            : self::usedBytes((int) $user->id, $serverId, $nodeType, self::periodStart($anchor, $now));
        return self::state($anchor, $used, $capBytes, $now);
    }

    /**
     * The user ids among $userIds that are over this node's cap, for the node credential list.
     * An empty array means "restrict nobody", which is also what an unmeasurable state produces.
     *
     * TWO QUERIES REGARDLESS OF HOW MANY USERS, because a node list can hold thousands. Per-user periods
     * make that slightly awkward: every user has their OWN anchor, so they are each in a different period
     * and there is no single period_start_at to filter on. So the usage query over-fetches by the set of
     * distinct period starts and the exact (user, period) pairing is re-applied in PHP.
     */
    public static function overLimitUserIds(array $userIds, int $serverId, string $nodeType, ?int $now = null): array
    {
        if ($userIds === []) {
            return [];
        }
        $capBytes = self::capBytes($serverId);
        if ($capBytes === null) {
            return []; // unlimited server: nothing to enforce, and no query is made
        }
        $now = $now ?? time();

        // Query 1: the anchors. A user whose anchor is indeterminate never enters $periodByUser, so they
        // can never appear in the returned list - that is the fail-OPEN direction, by ruling. See state().
        $periodByUser = [];
        foreach (User::whereIn('id', $userIds)->where(fn ($q) => MasterAccess::exclude($q))->pluck(self::ANCHOR_COLUMN, 'id') as $id => $anchor) {
            if (is_numeric($anchor) && (int) $anchor > 0) {
                $periodByUser[(int) $id] = self::periodStart((int) $anchor, $now);
            }
        }
        if ($periodByUser === []) {
            return [];
        }
        if (!Schema::hasTable(self::USAGE_TABLE)) {
            // Written-and-not-applied migration: usage is unmeasured, so restrict nobody rather than
            // treating an absent table as "everyone has used zero" or as "everyone is over".
            Log::warning('ServerQuotaService: ' . self::USAGE_TABLE . ' is absent, the cap is not enforced on node ' . $serverId);
            return [];
        }

        // Query 2: usage rows for those users, over the set of periods in play.
        $rows = DB::table(self::USAGE_TABLE)
            ->where('server_id', $serverId)
            ->where('node_type', $nodeType)
            ->whereIn('user_id', array_keys($periodByUser))
            ->whereIn('period_start_at', array_values(array_unique($periodByUser)))
            ->get(['user_id', 'period_start_at', 'u', 'd']);

        $over = [];
        foreach ($rows as $row) {
            $uid = (int) $row->user_id;
            // Re-apply the exact pairing: another user's period can match the IN clause without being this
            // user's period, and that row must not count towards them.
            if (!isset($periodByUser[$uid]) || (int) $row->period_start_at !== $periodByUser[$uid]) {
                continue;
            }
            if (((int) $row->u + (int) $row->d) >= $capBytes) {
                $over[] = $uid;
            }
        }
        // A user with no row at all is at zero for this period, so they are correctly absent from $over.
        return $over;
    }
}
