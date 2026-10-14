<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PlanController extends Controller
{
    public function fetch(Request $request)
    {
        $plans = Plan::orderBy('sort', 'ASC')
            ->with([
                'group:id,name'
            ])
            ->withCount('users')
            ->get();

        return $this->success($plans);
    }

    public function save(Request $request)
    {
        $params = $request->validate([
            'id' => 'nullable|integer',
            'name' => 'required|string',
            'content' => 'nullable|string',
            'reset_traffic_method' => 'integer|nullable',
            'transfer_enable' => 'integer|required',
            'prices' => 'array|nullable',
            'prices_krw' => 'array|nullable',
            'group_id' => 'integer|nullable',
            'speed_limit' => 'integer|nullable',
            'device_limit' => 'integer|nullable',
            'capacity_limit' => 'integer|nullable',
            'audience' => 'sometimes|in:public,partner,admin_only', // ManduVPN PP-A
            'service_tier' => 'nullable|in:legacy,basic,pro,master,vip', // ManduVPN PP-A: explicit, never parsed from names (PLAN-ARCH-1A: + legacy)
        ]);
        $tier = $params['service_tier'] ?? ($request->input('id') ? Plan::whereKey($request->input('id'))->value('service_tier') : null);
        if ($tier === 'master') {
            $params['audience'] = 'admin_only';
            $params['device_limit'] = null;
            $params['transfer_enable'] = 999999;
            $params['show'] = 0; $params['sell'] = 0; $params['renew'] = 0;
        }
        if ($request->input('id')) {
            $plan = Plan::find($request->input('id'));
            if (!$plan) {
                return $this->fail([400202, '该订阅不存在']);
            }
            DB::beginTransaction();
            // update user group id and transfer
            try {
                if ($request->input('force_update')) {
                    User::where('plan_id', $plan->id)->update([
                        'group_id' => $params['group_id'],
                        'transfer_enable' => $params['transfer_enable'] * 1073741824,
                        'speed_limit' => $params['speed_limit'],
                        'device_limit' => $params['device_limit'],
                    ]);
                }
                $plan->update($params);
                DB::commit();
                return $this->success(true);
            } catch (\Exception $e) {
                DB::rollBack();
                \Log::error($e);
                return $this->fail([500, '保存失败']);
            }
        }
        if (!Plan::create($params)) {
            return $this->fail([500, '创建失败']);
        }
        return $this->success(true);
    }

    public function drop(Request $request)
    {
        if (Order::where('plan_id', $request->input('id'))->first()) {
            return $this->fail([400201, '该订阅下存在订单无法删除']);
        }
        if (User::where('plan_id', $request->input('id'))->first()) {
            return $this->fail([400201, '该订阅下存在用户无法删除']);
        }
        if ($request->input('id')) {
            $plan = Plan::find($request->input('id'));
            if (!$plan) {
                return $this->fail([400202, '该订阅不存在']);
            }
        }
        return $this->success($plan->delete());
    }

    public function update(Request $request)
    {
        $updateData = $request->only([
            'show',
            'renew',
            'sell'
        ]);

        $plan = Plan::find($request->input('id'));
        if (!$plan) {
            return $this->fail([400202, '该订阅不存在']);
        }

        try {
            if ($plan->service_tier === 'master') $updateData = ['show'=>0,'sell'=>0,'renew'=>0];
            $plan->update($updateData);
        } catch (\Exception $e) {
            \Log::error($e);
            return $this->fail([500, '保存失败']);
        }

        return $this->success(true);
    }

    public function sort(Request $request)
    {
        $params = $request->validate([
            'ids' => 'required|array'
        ]);

        try {
            DB::beginTransaction();
            foreach ($params['ids'] as $k => $v) {
                if (!Plan::find($v)->update(['sort' => $k + 1])) {
                    throw new \Exception();
                }
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error($e);
            return $this->fail([500, '保存失败']);
        }
        return $this->success(true);
    }
}
