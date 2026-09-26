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
