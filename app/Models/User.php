<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens;
    protected $table = 'v2_user';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp'
    ];
    // ManduVPN RC (security): password_salt / password_algo are legacy SSPanel password material and were being
    // serialized to the admin browser by user/fetch's select('*'). Nothing needs them client-side - every use in
    // the codebase is a PHP property read or write (Helper::multiPasswordVerify, the password-reset paths), which
    // $hidden does not affect. Hiding them on the MODEL fixes every serializer at once (user/fetch,
    // getUserInfoById, the staff endpoints) instead of one endpoint at a time.
    // Deliberately NOT hidden here because removing them is a cross-agent response-contract change that needs
    // WEB/APP sign-off: token, uuid, subscribe_url. See RC_BACKEND.md §5.
    protected $hidden = ['password', 'password_salt', 'password_algo'];

    // ManduVPN HY2-CRED-1: THE ONE PLACE THAT INVALIDATES THE SHORT-LIVED HYSTERIA2 CREDENTIAL.
    // Rotating uuid must not leave the old Hysteria2 credential alive - if it did, "reset security" would be
    // WORSE than before, because the user would believe they had rotated something that still works for a whole
    // TTL. There are three rotation paths (V1/User/UserController@resetSecurity, V2/Admin/UserController@
    // resetSecret and the console reset:user, which walks every user), and all three assign uuid on a User model
    // and call save(). Hooking the model once therefore covers all three AND any path added later, which copying
    // the same call into three controllers would not. wasChanged('uuid') keeps every other save free.
    // Known limit, asserted in the RC suite rather than left implicit: a mass update (User::where(..)->update())
    // does not fire model events, so no rotation path may be rewritten into one.
    protected static function booted(): void
    {
        static::deleting(function (self $user) {
            // Only the new private contact row; the caller still controls account deletion.
            if (\Illuminate\Support\Facades\Schema::hasTable('v2_user_contact')) {
                \Illuminate\Support\Facades\DB::table('v2_user_contact')->where('user_id', $user->id)->delete();
            }
        });
        static::saved(function (self $user) {
            // wasRecentlyCreated: a fresh row reports every attribute as changed, and a brand-new user cannot
            // have a credential yet, so creation would otherwise run a pointless DELETE per user.
            if (!$user->wasRecentlyCreated && $user->wasChanged('uuid')) {
                \App\Services\Hy2CredentialService::invalidate((int) $user->id);
            }
        });
    }

    // 获取邀请人信息
    public function invite_user()
    {
        return $this->belongsTo(self::class, 'invite_user_id', 'id');
    }

    // 获取用户套餐
    public function plan()
    {
        return $this->belongsTo(Plan::class, 'plan_id', 'id');
    }

    public function group()
    {
        return $this->belongsTo(ServerGroup::class, 'group_id', 'id');
    }

    // 获取用户邀请码列表
    public function codes()
    {
        return $this->hasMany(InviteCode::class, 'user_id', 'id');
    }

    public function orders()
    {
        return $this->hasMany(Order::class, 'user_id', 'id');
    }

    public function stat()
    {
        return $this->hasMany(StatUser::class, 'user_id', 'id');
    }

    // 关联工单列表
    public function tickets()
    {
        return $this->hasMany(Ticket::class, 'user_id', 'id');
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id', 'id');
    }
}
