<?php
namespace App\Services;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Master is administrator-assigned only. Expiry, bans and server groups remain enforced. */
final class MasterAccess
{
    public static function planIds($query): void
    {
        $query->select('id')->from('v2_plan')->where('service_tier','master')->where('audience','admin_only');
    }
    public static function includeUnlimitedTraffic($query): void
    {
        $query->whereRaw('u + d < transfer_enable')->orWhereIn('plan_id',fn ($q) => self::planIds($q));
    }
    public static function exclude($query): void
    {
        $query->whereNull('plan_id')->orWhereNotIn('plan_id',fn ($q) => self::planIds($q));
    }
    public static function isUser(User $user): bool
    {
        return $user->plan_id !== null && Plan::whereKey($user->plan_id)
            ->where('service_tier','master')->where('audience','admin_only')->exists();
    }
    public static function userIds(array $ids): array
    {
        $result=[];
        foreach (array_chunk($ids,500) as $chunk) {
            foreach (DB::table('v2_user')->whereIn('id',$chunk)
                ->whereIn('plan_id',fn ($q) => self::planIds($q))->pluck('id') as $id) $result[]=(int)$id;
        }
        return array_values(array_unique($result));
    }
}
