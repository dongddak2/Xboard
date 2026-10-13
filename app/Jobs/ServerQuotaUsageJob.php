<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\ServerQuotaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * ManduVPN SERVER-QUOTA-1: records RAW per-user, per-node traffic for the per-server cap.
 *
 * This is the fourth job dispatched from UserService::trafficFetch(), beside TrafficFetchJob, StatUserJob
 * and StatServerJob. It exists because none of those three can answer "how much has THIS user spent on
 * THIS node": StatUser has no server_id and groups by server_rate, so two servers sharing a rate merge
 * into one row, and - decisively - all three multiply by the plan's traffic rate, while this cap is
 * defined on raw bytes. Dividing the multiplier back out would put rounding loss inside a decision about
 * someone's paid service, so the raw figure is recorded here, at the only point it exists.
 *
 * ---------------------------------------------------------------------------------------------------
 * THIS ACCOUNTING CAN OVER-COUNT, AND THAT DIRECTION IS A DECISION RATHER THAN AN OVERSIGHT.
 *
 * No node report payload carries a stable batch identifier - not UniProxyController::push, whose body is
 * a bare {uid: [u, d]} map, and not the IKEv2 report schema. Measured: zero occurrences of any
 * idempotency, nonce, sequence or request-id field across the server controllers and their middleware.
 * The queue is at-least-once (tries = 3 with backoff, and handle() rethrows), so a retried batch can be
 * applied twice and usage can be counted twice.
 *
 * The alternative was deriving a key, and it was rejected because it fails in the WORSE direction:
 *   - the only IKEv2 candidate is generated_at, which is agent-chosen and second-granular, so two
 *     legitimate reports inside one second would collapse into one;
 *   - the UniProxy path offers nothing but a hash of the body, which discards a genuine repeat report
 *     whose contents happen to be identical.
 * Both of those UNDER-count. An over-count is visible to the person it harms - they can see the figure on
 * their own dashboard and dispute it - whereas an under-count is invisible to the user AND the operator.
 * So we take the failure that announces itself.
 *
 * BODY-HASH DEDUPLICATION IS BARRED. Its failure mode is dropping real data, which is the invisible
 * direction. Do not add it.
 * A disputed figure is resolved with the admin cap lever, never by editing recorded data.
 * ---------------------------------------------------------------------------------------------------
 *
 * THE PERIOD COMES FROM THE REPORT, NOT FROM THE CLOCK AT EXECUTION TIME. $reportedAt is captured in
 * trafficFetch() and carried here. The existing jobs do the opposite - StatUserJob computes record_at
 * with date() inside handle() - so a delayed or retried batch is attributed to the day it RAN. At a
 * period boundary that is precisely a free quota reset, which is why this job is given the instant
 * instead of asking for it.
 *
 * IKEV2 IS ABSENT BY CONSTRUCTION, NOT BY A BRANCH HERE. The IKEv2 node API never calls trafficFetch():
 * its report() validates traffic and answers 'traffic_accounting' => 'deferred', because the agent
 * deliberately does not implement traffic accounting yet and sends no byte counters at all. So no row is
 * ever written for an IKEv2 node, and ServerQuotaService reports those nodes as UNKNOWN rather than as
 * zero. Do not add an IKEv2 branch here; there is nothing to record until the agent sends something.
 */
class ServerQuotaUsageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected array $data;
    protected array $server;
    protected string $nodeType;
    protected int $reportedAt;

    public $tries = 3;
    public $timeout = 60;
    public $maxExceptions = 3;

    public function backoff(): array
    {
        return [1, 5, 10];
    }

    public function __construct(array $server, array $data, string $nodeType, int $reportedAt)
    {
        $this->onQueue('stat');
        $this->server = $server;
        $this->data = $data;
        $this->nodeType = $nodeType;
        $this->reportedAt = $reportedAt;
    }

    public function handle(): void
    {
        if (!Schema::hasTable(ServerQuotaService::USAGE_TABLE)) {
            // The migration is written and not applied. Recording is simply not active yet; the cap reads
            // as UNKNOWN meanwhile, which fails open rather than blocking anyone.
            return;
        }
        $serverId = (int) ($this->server['id'] ?? 0);
        if ($serverId < 1 || $this->data === []) {
            return;
        }

        // The entitlement owner, not the reporter: the cap governs a user's right to use a particular
        // node, so usage is attributed to the node that right attaches to. A child reporting through a
        // parent must land on the CHILD's id, or a relay topology change would silently redistribute
        // recorded usage and could push someone over a cap without them using anything.
        // $server here is the node the user actually connected to, which is that leaf. If the relay work
        // ever makes trafficFetch() receive a parent on a child's behalf, THIS is the line that has to
        // change, and the two tasks have to agree on it.
        $ownerId = $serverId;

        $anchors = User::whereIn('id', array_keys($this->data))
            ->pluck(ServerQuotaService::ANCHOR_COLUMN, 'id');

        $now = time();
        $missingAnchor = 0;
        foreach ($this->data as $uid => $v) {
            $u = (int) ($v[0] ?? 0);
            $d = (int) ($v[1] ?? 0);
            if ($u === 0 && $d === 0) {
                continue;
            }
            $anchor = $anchors[$uid] ?? null;
            if (!is_numeric($anchor) || (int) $anchor <= 0) {
                // No anchor means no period to attribute this to. Skipping leaves the figure UNMEASURED,
                // which ServerQuotaService reports as UNKNOWN and treats as fail-open - deliberately, by
                // ruling: denying service to someone who has paid is worse than briefly not capping.
                $missingAnchor++;
                continue;
            }
            $periodStart = ServerQuotaService::periodStart((int) $anchor, $this->reportedAt);

            try {
                DB::transaction(function () use ($uid, $ownerId, $periodStart, $u, $d, $now) {
                    // Atomic increment first. The UNIQUE index on
                    // (user_id, server_id, node_type, period_start_at) is what makes this safe under
                    // concurrent reports: whichever transaction inserts first wins, and the other one's
                    // retry finds the row and increments it instead of racing a read-modify-write.
                    $affected = DB::table(ServerQuotaService::USAGE_TABLE)
                        ->where('user_id', (int) $uid)
                        ->where('server_id', $ownerId)
                        ->where('node_type', $this->nodeType)
                        ->where('period_start_at', $periodStart)
                        ->update([
                            'u' => DB::raw('u + ' . $u),
                            'd' => DB::raw('d + ' . $d),
                            'updated_at' => $now,
                        ]);

                    if (!$affected) {
                        DB::table(ServerQuotaService::USAGE_TABLE)->insert([
                            'user_id' => (int) $uid,
                            'server_id' => $ownerId,
                            'node_type' => $this->nodeType,
                            'period_start_at' => $periodStart,
                            // RAW bytes exactly as reported. No plan traffic rate is applied here, and
                            // applying one would silently change what every configured cap means.
                            'u' => $u,
                            'd' => $d,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }
                }, 3);
            } catch (\Exception $e) {
                Log::error('ServerQuotaUsageJob failed for user ' . $uid . ' on node ' . $ownerId . ': ' . $e->getMessage());
                throw $e;
            }
        }

        if ($missingAnchor > 0) {
            // Visible rather than silent: these users' usage is not being recorded, so their cap is not
            // being enforced, and that has to be noticeable.
            Log::warning('ServerQuotaUsageJob: ' . $missingAnchor . ' user(s) on node ' . $ownerId
                . ' have no ' . ServerQuotaService::ANCHOR_COLUMN . ', so their usage is unrecorded and their cap is not enforced');
        }
    }
}
