<?php
namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Server;
use Illuminate\Support\Facades\Schema;

// Authenticated catalog only; the connection entitlement endpoints are unchanged.
class ServerCatalogController extends Controller
{
    public function fetch()
    {
        $query = Plan::where('show', 1)->where('sell', 1);
        if (Schema::hasColumn('v2_plan', 'audience')) {
            $query->where('audience', 'public');
        }
        $plans = $query->get(['name', 'group_id', 'service_tier']);
        $rows = [];
        foreach (Server::where('show', 1)->whereNull('parent_id')->whereIn('type', ['ikev2', 'hysteria'])->get() as $server) {
            $names = [];
            foreach ($plans as $plan) {
                if (!in_array((int) $plan->group_id, array_map('intval', $server->group_ids ?? []), true)) continue;
                $name = match ($plan->service_tier) {
                    'legacy' => $server->type === 'ikev2' ? 'Basic' : null,
                    'basic', 'pro' => 'Pro', 'master' => 'Premium', 'vip' => null,
                    default => preg_match('/^(베이직|basic|프로|pro)(?:\s|·|$)/iu', $plan->name) ? 'Pro'
                        : (preg_match('/^(프리미엄|premium)(?:\s|·|$)/iu', $plan->name) ? 'Premium' : null),
                };
                if ($name !== null && $name !== '') $names[] = $name;
            }
            $names = array_values(array_unique($names));
            if ($names !== []) $rows[] = ['id' => $server->id, 'name' => $server->name, 'type' => $server->type, 'plan_names' => $names];
        }
        return response()->json(['data' => $rows])->header('Cache-Control', 'no-store');
    }
}
