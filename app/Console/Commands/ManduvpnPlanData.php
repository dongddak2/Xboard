<?php

namespace App\Console\Commands;

use App\Models\Plan;
use App\Models\Server;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ManduVPN RC: the plan-architecture operator tool for PLAN_STRUCTURE_PLAN.md S4-S7.
 *
 * WHY A COMMAND AND NOT PlanController::save: that controller's `force_update` rewrites
 * group_id/transfer_enable/speed_limit/device_limit on EVERY EXISTING USER of the plan. This tool never calls it
 * and never touches a single v2_user row.
 *
 * Safety model (PLAN_STRUCTURE_PLAN.md §4 "공통 보호 규칙"):
 *   - DRY RUN IS THE DEFAULT. Every subcommand prints exactly what it would do and writes nothing unless --apply
 *     is passed, so this can be reviewed and rehearsed before the Owner approves a real write.
 *   - seed inserts NEW rows only, always show=0 sell=0, and refuses to modify an existing row.
 *   - cutover is ONE transaction: it flips the seeded tier rows to show=1 sell=1 and stops the old compat rows
 *     from selling (sell=0) while LEAVING renew=1, so existing subscribers can still renew. Never deletes a row.
 *   - Before writing anything, cutover records the previous show/sell/renew of every row it will change into a
 *     state file, and `rollback` restores exactly those flags. Nothing else is ever restored.
 *   - validate refuses the unit mistake PLAN_STRUCTURE_PLAN calls the most common accident: `prices` is in YUAN
 *     and `prices_krw` is in WON, and PlanResource/OrderController multiply the CNY value by 100.
 *
 * Prices are NOT hardcoded: the plan set comes from a JSON file, because the real amounts are an Owner decision
 * (RC_BACKEND.md OD-2). The tool is complete and executable the moment that file is filled in.
 *
 * OD-2 RESOLVED (OWNER_DECISIONS.md §F-1): the existing price table stays exactly as configured; no amount is
 * recomputed and no new amount is entered. This tool already satisfies that, and structurally rather than by
 * convention: `prices` / `prices_krw` appear in the INSERT of a brand-new row and nowhere else. Every UPDATE in
 * this file - in cutover and in rollback - writes only show / sell / renew / updated_at. A definition whose name
 * already exists is SKIPped, so re-running seed can never overwrite a live plan's prices. Keep it that way: if a
 * price ever has to change, that is an Owner-approved admin edit, not a seed.
 *
 * Usage:
 *   php artisan manduvpn:plan-data status
 *   php artisan manduvpn:plan-data validate --file=storage/app/manduvpn-plans.json
 *   php artisan manduvpn:plan-data seed     --file=storage/app/manduvpn-plans.json            # dry run
 *   php artisan manduvpn:plan-data seed     --file=storage/app/manduvpn-plans.json --apply
 *   php artisan manduvpn:plan-data cutover                                                    # dry run
 *   php artisan manduvpn:plan-data cutover  --apply
 *   php artisan manduvpn:plan-data rollback --apply
 */
class ManduvpnPlanData extends Command
{
    protected $signature = 'manduvpn:plan-data
        {action : validate|seed|cutover|rollback|status}
        {--file= : JSON plan definition (required for validate and seed)}
        {--apply : actually write. Without it the command is a dry run and writes nothing}
        {--state=manduvpn-plan-cutover-state.json : rollback state file, relative to storage/app}';

    protected $description = 'ManduVPN plan architecture: validate / seed (hidden) / atomic cutover / rollback. Dry run by default.';

    /** Tiers recognised by PlanService; a plan name is never used to infer a tier. */
    private const TIERS = ['legacy', 'basic', 'pro', 'master', 'vip'];
    private const AUDIENCES = ['public', 'partner', 'admin_only'];

    public function handle(): int
    {
        $action = (string) $this->argument('action');
        return match ($action) {
            'validate' => $this->doValidate(),
            'seed' => $this->doSeed(),
            'cutover' => $this->doCutover(),
            'rollback' => $this->doRollback(),
            'status' => $this->doStatus(),
            default => $this->bail("unknown action '$action' (validate|seed|cutover|rollback|status)"),
        };
    }

    // ---------------------------------------------------------------- definition file

    private function loadDefinition(): ?array
    {
        $file = (string) $this->option('file');
        if ($file === '') {
            $this->error('--file is required for this action');
            return null;
        }
        $path = str_starts_with($file, '/') || preg_match('/^[A-Za-z]:/', $file) ? $file : base_path($file);
        if (!is_file($path)) {
            $this->error("plan definition not found: $path");
            return null;
        }
        $json = json_decode((string) file_get_contents($path), true);
        if (!is_array($json) || !isset($json['plans']) || !is_array($json['plans'])) {
            $this->error('plan definition must be JSON of the shape {"plans":[ ... ]}');
            return null;
        }
        return $json;
    }

    /**
     * Full validation of a definition file plus the live data it depends on.
     * Returns [errors, warnings]. Nothing here writes.
     */
    private function validateDefinition(array $def): array
    {
        $errors = [];
        $warnings = [];
        $plans = $def['plans'];
        $periodKeys = array_map(fn ($d) => "days_$d", Plan::DAYS_BASED_PERIODS);

        $byTierDevice = [];
        $seenNames = [];
        foreach ($plans as $i => $p) {
            $at = "plans[$i]";
            $name = trim((string) ($p['name'] ?? ''));
            if ($name === '') {
                $errors[] = "$at.name must not be empty";
            } elseif (isset($seenNames[$name])) {
                $errors[] = "$at.name duplicates an earlier entry: $name";
            } else {
                $seenNames[$name] = true;
            }
            $tier = (string) ($p['service_tier'] ?? '');
            if (!in_array($tier, self::TIERS, true)) {
                $errors[] = "$at.service_tier must be one of " . implode('|', self::TIERS);
            }
            $audience = (string) ($p['audience'] ?? 'public');
            if (!in_array($audience, self::AUDIENCES, true)) {
                $errors[] = "$at.audience must be one of " . implode('|', self::AUDIENCES);
            }
            $device = $p['device_limit'] ?? null;
            if (!is_int($device) || $device < 1 || $device > 100) {
                $errors[] = "$at.device_limit must be an integer 1..100";
            }
            $groupId = $p['group_id'] ?? null;
            if (!is_int($groupId) || $groupId < 1) {
                $errors[] = "$at.group_id must be an existing v2_server_group id";
            } elseif (!DB::table('v2_server_group')->where('id', $groupId)->exists()) {
                $errors[] = "$at.group_id $groupId does not exist";
            }
            if (!is_int($p['transfer_enable'] ?? null) || (int) $p['transfer_enable'] < 1) {
                $errors[] = "$at.transfer_enable must be a positive integer (GB)";
            }
            // device_limit must be UNIQUE within a tier: it is the selector axis in the purchase UI.
            if (is_string($tier) && is_int($device)) {
                $key = "$audience|$tier|$device";
                if (isset($byTierDevice[$key])) {
                    $errors[] = "$at duplicates (audience=$audience, tier=$tier, device_limit=$device) - device_limit must be unique within a tier";
                }
                $byTierDevice[$key] = $name;
            }

            // Prices. CNY is YUAN, KRW is WON. Both optional per period, but at least one period must sell.
            $cny = $p['prices'] ?? [];
            $krw = $p['prices_krw'] ?? [];
            if (!is_array($cny) || !is_array($krw)) {
                $errors[] = "$at.prices and $at.prices_krw must be objects keyed by period";
                continue;
            }
            foreach (array_keys($cny + $krw) as $k) {
                if (!in_array($k, $periodKeys, true) && $k !== Plan::PERIOD_RESET_TRAFFIC) {
                    $errors[] = "$at unknown period key '$k' (expected days_30..days_360 or reset_traffic)";
                }
            }
            if ($cny === [] && $krw === []) {
                $errors[] = "$at has no prices at all";
            }
            foreach ($cny as $k => $v) {
                if (!is_int($v) && !is_float($v)) { $errors[] = "$at.prices[$k] must be a number (YUAN)"; continue; }
                if ($v <= 0) { $errors[] = "$at.prices[$k] must be > 0"; continue; }
                // A CNY figure in the thousands is almost certainly a WON amount pasted into the wrong field,
                // or fen instead of yuan. PLAN_STRUCTURE_PLAN calls this the 100x accident.
                if ($v >= 1000) $warnings[] = "$at.prices[$k]=$v looks like WON or fen, not YUAN - prices is in YUAN and is multiplied by 100 downstream";
            }
            foreach ($krw as $k => $v) {
                if (!is_int($v)) { $errors[] = "$at.prices_krw[$k] must be an integer (WON, no conversion)"; continue; }
                if ($v <= 0) { $errors[] = "$at.prices_krw[$k] must be > 0"; continue; }
                if ($v < 1000) $warnings[] = "$at.prices_krw[$k]=$v looks like YUAN, not WON - prices_krw is stored as plain WON";
            }
            // RC_BACKEND.md OD-1: PlanService::validatePurchase() requires a CNY price regardless of currency,
            // so a period priced only in KRW cannot be bought today.
            foreach (array_keys($krw) as $k) {
                if (!isset($cny[$k])) {
                    $errors[] = "$at.prices_krw[$k] has no matching prices[$k]: PlanService::validatePurchase() requires a CNY price for every purchasable period (RC_BACKEND.md OD-1). Add a CNY price, or get LEAD approval to change that code path";
                }
            }
        }

        // PARTNER rows must pair exactly with a public row on service_tier + device_limit, or a partner sees both.
        foreach ($plans as $i => $p) {
            if ((string) ($p['audience'] ?? 'public') !== 'partner') {
                continue;
            }
            $key = 'public|' . (string) ($p['service_tier'] ?? '') . '|' . (int) ($p['device_limit'] ?? 0);
            if (!isset($byTierDevice[$key])) {
                $errors[] = "plans[$i] is a partner row with no public counterpart at the same service_tier + device_limit - PlanService::getVisiblePlans() would show a partner BOTH rows";
            }
        }

        // LEGACY tier is auto-assigned a single server: an empty eligible node set is a 422 fail-closed at purchase.
        foreach ($plans as $i => $p) {
            if ((string) ($p['service_tier'] ?? '') !== 'legacy' || !is_int($p['group_id'] ?? null)) {
                continue;
            }
            $eligible = Server::whereJsonContains('group_ids', (int) $p['group_id'])
                ->where('type', 'ikev2')->where('show', true)->whereNull('parent_id')->count();
            if ($eligible < 1) {
                $errors[] = "plans[$i] is a legacy plan whose group_id {$p['group_id']} has 0 eligible IKEv2 nodes (type=ikev2, show=1, parent_id IS NULL): BasicServerAssignmentService::resolve() would 422 fail-closed on every purchase";
            }
        }
        return [$errors, $warnings];
    }

    // ---------------------------------------------------------------- actions

    private function doValidate(): int
    {
        $def = $this->loadDefinition();
        if ($def === null) {
            return 1;
        }
        [$errors, $warnings] = $this->validateDefinition($def);
        $this->line('plans in definition: ' . count($def['plans']));
        foreach ($warnings as $w) {
            $this->warn("WARN  $w");
        }
        foreach ($errors as $e) {
            $this->error("ERROR $e");
        }
        if ($errors) {
            $this->error(count($errors) . ' error(s). Nothing may be seeded until these are fixed.');
            return 1;
        }
        $this->info('validation PASS' . ($warnings ? ' (' . count($warnings) . ' warning(s) - read them, a unit mistake is silent)' : ''));
        return 0;
    }

    private function doSeed(): int
    {
        $def = $this->loadDefinition();
        if ($def === null) {
            return 1;
        }
        [$errors, $warnings] = $this->validateDefinition($def);
        foreach ($warnings as $w) {
            $this->warn("WARN  $w");
        }
        if ($errors) {
            foreach ($errors as $e) {
                $this->error("ERROR $e");
            }
            return $this->bail('validation failed - refusing to seed');
        }
        $apply = (bool) $this->option('apply');
        $now = time();
        $insert = [];
        foreach ($def['plans'] as $p) {
            $name = trim((string) $p['name']);
            if (Plan::where('name', $name)->exists()) {
                // Never update an existing row: that is what force_update does and it rewrites live users.
                $this->warn("SKIP  a plan named '$name' already exists - existing rows are never modified");
                continue;
            }
            $insert[] = [
                'name' => $name,
                'service_tier' => (string) $p['service_tier'],
                'audience' => (string) ($p['audience'] ?? 'public'),
                'group_id' => (int) $p['group_id'],
                'transfer_enable' => (int) $p['transfer_enable'],
                'device_limit' => (int) $p['device_limit'],
                'speed_limit' => isset($p['speed_limit']) && $p['speed_limit'] !== null ? (int) $p['speed_limit'] : null,
                'prices' => json_encode($p['prices'] ?? [], JSON_UNESCAPED_UNICODE),
                'prices_krw' => json_encode($p['prices_krw'] ?? [], JSON_UNESCAPED_UNICODE),
                'content' => isset($p['content']) ? (string) $p['content'] : null,
                'reset_traffic_method' => (int) ($p['reset_traffic_method'] ?? Plan::RESET_TRAFFIC_FOLLOW_SYSTEM),
                'capacity_limit' => isset($p['capacity_limit']) && $p['capacity_limit'] !== null ? (int) $p['capacity_limit'] : null,
                'sort' => (int) ($p['sort'] ?? 0),
                // S4: seeded HIDDEN and UNSELLABLE. The store only changes at cutover, atomically.
                'show' => 0,
                'sell' => 0,
                'renew' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        if (!$insert) {
            $this->info('nothing to insert');
            return 0;
        }
        $this->line('');
        $this->line('would INSERT ' . count($insert) . ' new plan row(s), all show=0 sell=0:');
        foreach ($insert as $row) {
            $this->line(sprintf('  + %-34s tier=%-7s audience=%-10s dev=%-2d group=%-3d cny=%s krw=%s',
                $row['name'], $row['service_tier'], $row['audience'], $row['device_limit'], $row['group_id'],
                $row['prices'], $row['prices_krw']));
        }
        $this->line('would UPDATE 0 existing row(s), DELETE 0 row(s), touch 0 v2_user row(s)');
        if (!$apply) {
            $this->warn('DRY RUN - nothing was written. Re-run with --apply after Owner approval.');
            return 0;
        }
        $existingIds = Plan::pluck('id')->all();
        DB::transaction(function () use ($insert) {
            DB::table('v2_plan')->insert($insert);
        });
        $created = Plan::whereNotIn('id', $existingIds)->pluck('id')->all();
        $this->info('inserted plan ids: ' . implode(',', $created));
        $this->warn('these rows are hidden and unsellable. Run the frozen regressions now, then `cutover` when they pass.');
        return 0;
    }

    private function doCutover(): int
    {
        $apply = (bool) $this->option('apply');
        // Tier rows that exist but are not yet on sale are what we are switching on.
        $toEnable = Plan::whereNotNull('service_tier')->where(function ($q) {
            $q->where('show', 0)->orWhere('sell', 0);
        })->get();
        // Old compatibility rows (no explicit tier) stop selling but keep renewing.
        $toRetire = Plan::whereNull('service_tier')->where('sell', 1)->get();

        if ($toEnable->isEmpty()) {
            return $this->bail('no tier rows to enable - seed first');
        }
        // A partial cutover empties the store: plan-view.ts::storeTabs hides every service_tier=NULL row the
        // moment ANY tier row becomes sellable. So every tier row flips in one transaction, or none does.
        $this->line('would ENABLE (show=1, sell=1):');
        foreach ($toEnable as $p) {
            $this->line(sprintf('  ^ id=%-4d %-34s tier=%-7s audience=%-10s dev=%d', $p->id, $p->name, $p->service_tier, $p->audience ?: 'public', (int) $p->device_limit));
        }
        $this->line('would RETIRE (sell=0, renew LEFT AT 1 so existing subscribers can still renew):');
        foreach ($toRetire as $p) {
            $this->line(sprintf('  v id=%-4d %-34s renew stays %d', $p->id, $p->name, (int) $p->renew));
        }
        $this->line('would DELETE 0 row(s), touch 0 v2_user row(s)');
        $legacyProblem = $toEnable->filter(fn ($p) => $p->service_tier === 'legacy'
            && Server::whereJsonContains('group_ids', (int) $p->group_id)->where('type', 'ikev2')->where('show', true)->whereNull('parent_id')->count() < 1);
        if ($legacyProblem->isNotEmpty()) {
            return $this->bail('refusing cutover: legacy plan id(s) ' . $legacyProblem->pluck('id')->implode(',') . ' have 0 eligible IKEv2 nodes, so every purchase would 422');
        }
        if (!$apply) {
            $this->warn('DRY RUN - nothing was written. Re-run with --apply after Owner approval.');
            return 0;
        }
        // State file first: rollback must be possible even if the process dies right after commit.
        $state = [
            'created_at' => time(),
            'flags' => $toEnable->concat($toRetire)->map(fn ($p) => [
                'id' => (int) $p->id, 'show' => (int) $p->show, 'sell' => (int) $p->sell, 'renew' => (int) $p->renew,
            ])->values()->all(),
        ];
        $statePath = storage_path('app/' . $this->option('state'));
        if (file_put_contents($statePath, json_encode($state, JSON_PRETTY_PRINT)) === false) {
            return $this->bail("could not write the rollback state file: $statePath");
        }
        $this->info("rollback state written: $statePath");
        DB::transaction(function () use ($toEnable, $toRetire) {
            DB::table('v2_plan')->whereIn('id', $toEnable->pluck('id'))->update(['show' => 1, 'sell' => 1, 'updated_at' => time()]);
            if ($toRetire->isNotEmpty()) {
                // renew is deliberately not touched.
                DB::table('v2_plan')->whereIn('id', $toRetire->pluck('id'))->update(['sell' => 0, 'updated_at' => time()]);
            }
        });
        $this->info('cutover committed. Run the web checkers and the frozen regressions now.');
        $this->warn('to undo: php artisan manduvpn:plan-data rollback --apply');
        return 0;
    }

    private function doRollback(): int
    {
        $statePath = storage_path('app/' . $this->option('state'));
        if (!is_file($statePath)) {
            return $this->bail("no rollback state file at $statePath - nothing to restore");
        }
        $state = json_decode((string) file_get_contents($statePath), true);
        if (!is_array($state) || !isset($state['flags']) || !is_array($state['flags'])) {
            return $this->bail('rollback state file is not readable');
        }
        $this->line('would restore show/sell/renew on ' . count($state['flags']) . ' row(s):');
        foreach ($state['flags'] as $f) {
            $cur = Plan::find($f['id']);
            $this->line(sprintf('  id=%-4d show %s->%d  sell %s->%d  renew %s->%d', $f['id'],
                $cur ? (int) $cur->show : '?', $f['show'], $cur ? (int) $cur->sell : '?', $f['sell'], $cur ? (int) $cur->renew : '?', $f['renew']));
        }
        $this->line('would DELETE 0 row(s) - rollback restores flags only, it never removes a seeded plan');
        if (!(bool) $this->option('apply')) {
            $this->warn('DRY RUN - nothing was written. Re-run with --apply.');
            return 0;
        }
        DB::transaction(function () use ($state) {
            foreach ($state['flags'] as $f) {
                DB::table('v2_plan')->where('id', (int) $f['id'])->update([
                    'show' => (int) $f['show'], 'sell' => (int) $f['sell'], 'renew' => (int) $f['renew'], 'updated_at' => time(),
                ]);
            }
        });
        $this->info('flags restored.');
        return 0;
    }

    /** Read-only inventory: what the live plan table looks like right now (PLAN_STRUCTURE_PLAN S0). */
    private function doStatus(): int
    {
        $this->line(sprintf('%-5s %-34s %-8s %-11s %-4s %-6s %-5s %-5s %-6s', 'id', 'name', 'tier', 'audience', 'dev', 'group', 'show', 'sell', 'renew'));
        foreach (Plan::orderBy('sort')->orderBy('id')->get() as $p) {
            $this->line(sprintf('%-5d %-34s %-8s %-11s %-4d %-6d %-5d %-5d %-6d',
                $p->id, mb_substr((string) $p->name, 0, 34), (string) ($p->service_tier ?: '-'), (string) ($p->audience ?: 'public'),
                (int) $p->device_limit, (int) $p->group_id, (int) $p->show, (int) $p->sell, (int) $p->renew));
        }
        $sellableTier = Plan::whereNotNull('service_tier')->where('sell', 1)->where('show', 1)->count();
        $sellableCompat = Plan::whereNull('service_tier')->where('sell', 1)->where('show', 1)->count();
        $this->line('');
        $this->line("sellable tier rows: $sellableTier   sellable compat rows: $sellableCompat");
        if ($sellableTier > 0 && $sellableCompat > 0) {
            $this->warn('BOTH tier and compat rows are sellable. plan-view.ts::storeTabs hides compat rows as soon as a tier row sells, so the compat rows are not actually reachable in the store.');
        }
        return 0;
    }

    private function bail(string $message): int
    {
        $this->error($message);
        return 1;
    }
}
