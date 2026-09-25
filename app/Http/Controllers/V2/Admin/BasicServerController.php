<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\BasicServerAdminService;
use Illuminate\Http\Request;

// ManduVPN PP-C3: admin endpoints for the BASIC single-server assignment (logic lives in BasicServerAdminService).
class BasicServerController extends Controller
{
    public function fetch(Request $request)
    {
        $request->validate(['id' => 'required|integer']);
        $user = User::find($request->input('id'));
        if (!$user) {
            return $this->fail([400202, '用户不存在']);
        }
        return $this->success(BasicServerAdminService::describe($user));
    }

    public function assign(Request $request)
    {
        $request->validate(['id' => 'required|integer', 'server_id' => 'required|integer']);
        $user = User::find($request->input('id'));
        if (!$user) {
            return $this->fail([400202, '用户不存在']);
        }
        BasicServerAdminService::assign($user, (int) $request->input('server_id'));
        return $this->success(BasicServerAdminService::describe(User::find($user->id)));
    }

    public function reassign(Request $request)
    {
        $request->validate(['id' => 'required|integer']);
        $user = User::find($request->input('id'));
        if (!$user) {
            return $this->fail([400202, '用户不存在']);
        }
        BasicServerAdminService::reassign($user);
        return $this->success(BasicServerAdminService::describe(User::find($user->id)));
    }
}
