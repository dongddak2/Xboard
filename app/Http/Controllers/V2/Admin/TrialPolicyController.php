<?php

namespace App\Http\Controllers\V2\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Services\TrialService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// ManduVPN TR-1b: admin-managed free-trial policy (admin middleware via the existing admin "config" route group).
// Not part of the generic ConfigSave. Saving affects FUTURE grants only (the grant snapshots values onto the user).
class TrialPolicyController extends Controller
{
    public function fetch()
    {
        [$policy, $errors] = TrialService::policy();
        return $this->success($this->describe($policy, $errors));
    }

    public function save(Request $request)
    {
        $in = [];
        foreach (array_keys(TrialService::KEYS) as $k) {
            if (!$request->exists($k)) {
                throw new ApiException("all trial policy fields are required (missing $k)", 422);
            }
            $in[$k] = $request->input($k);
        }
        [$policy, $errors] = TrialService::validate($in);
        if ($errors) {
            throw new ApiException(implode('; ', $errors), 422);
        }
        $stored = [
            'enable' => $policy['enable'] ? '1' : '0',
            'duration_hours' => (string) $policy['duration_hours'],
            'transfer_gb' => (string) $policy['transfer_gb'],
            'device_limit' => (string) $policy['device_limit'],
            'speed_limit' => $policy['speed_limit'] === null ? '' : (string) $policy['speed_limit'],
            'group_id' => $policy['group_id'] === null ? '' : (string) $policy['group_id'],
        ];
        DB::transaction(function () use ($stored) {
            foreach (TrialService::KEYS as $k => $name) {
                $row = ['value' => $stored[$k], 'updated_at' => now()];
                if (DB::table('v2_settings')->where('name', $name)->exists()) {
                    DB::table('v2_settings')->where('name', $name)->update($row);
                } else {
                    DB::table('v2_settings')->insert($row + ['group' => 'manduvpn', 'type' => 'input', 'name' => $name, 'created_at' => now()]);
                }
            }
        });
        Log::info('ManduVPN TR-1b trial policy saved', ['admin_id' => (int) optional($request->user())->id, 'enable' => $stored['enable']]);
        return $this->success($this->describe($policy, []));
    }

    private function describe(array $policy, array $errors): array
    {
        $warnings = [];
        $nodes = $policy['group_id'] !== null ? TrialService::shownNodeCount((int) $policy['group_id']) : null;
        if ($nodes === 0) {
            $warnings[] = 'GROUP_HAS_NO_SHOWN_NODES';
        }
        return [
            'policy' => $policy,
            'valid' => !$errors,
            'errors' => array_values($errors),
            'warnings' => $warnings,
            'group_shown_nodes' => $nodes,
            'trial_users_total' => DB::table('v2_user')->whereNotNull('trial_granted_at')->count(),
            'trial_users_active' => DB::table('v2_user')->whereNotNull('trial_granted_at')->whereNull('plan_id')->where('expired_at', '>', time())->count(),
        ];
    }
}
