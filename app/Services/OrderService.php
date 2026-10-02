<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Jobs\OrderHandleJob;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class OrderService
{
    const STR_TO_TIME = [
        Plan::PERIOD_MONTHLY => 1,
        Plan::PERIOD_QUARTERLY => 3,
        Plan::PERIOD_HALF_YEARLY => 6,
        Plan::PERIOD_YEARLY => 12,
        Plan::PERIOD_TWO_YEARLY => 24,
        Plan::PERIOD_THREE_YEARLY => 36
    ];
    public $order;
    public $user;

    public function __construct(Order $order)
    {
        $this->order = $order;
    }

    public function open()
    {
        $order = $this->order;
        $this->user = User::find($order->user_id);
        $plan = Plan::find($order->plan_id);

        if ($order->refund_amount) {
            $this->user->balance = $this->user->balance + $order->refund_amount;
        }
        try {
            DB::beginTransaction();
            if ($order->surplus_order_ids) {
                Order::whereIn('id', $order->surplus_order_ids)->update([
                    'status' => Order::STATUS_DISCOUNTED
                ]);
            }
            switch ((string) $order->period) {
                case Plan::PERIOD_ONETIME:
                    $this->buyByOneTime($plan);
                    break;
                case Plan::PERIOD_RESET_TRAFFIC:
                    $this->buyByResetTraffic();
                    break;
                default:
                    $this->buyByPeriod($order, $plan);
            }

            switch ((int) $order->type) {
                case Order::STATUS_PROCESSING:
                    $this->openEvent(admin_setting('new_order_event_id', 0));
                    break;
                case Order::TYPE_RENEWAL:
                    $this->openEvent(admin_setting('renew_order_event_id', 0));
                    break;
                case Order::TYPE_UPGRADE:
                    $this->openEvent(admin_setting('change_order_event_id', 0));
                    break;
            }

            $this->setSpeedLimit($plan->speed_limit);
            $this->setDeviceLimit($plan->device_limit);

            // ManduVPN PP-C2: BASIC single-server entitlement follows the plan applied above; decided inside this
            // transaction so plan/group/expiry and assigned_server_id commit or roll back together (no eligible
            // BASIC node -> exception -> rollback -> nothing changes).
            \App\Services\BasicServerAssignmentService::applyTo($this->user);

            if (!$this->user->save()) {
                throw new \Exception('用户信息保存失败');
            }
            $order->status = Order::STATUS_COMPLETED;
            if (!$order->save()) {
                throw new \Exception('订单信息保存失败');
            }
            // ManduVPN RC: referral reward POINTS on the invitee's first completed PAID order
            // (RC_CONTRACTS.md §4). Inside this transaction, so the reward rolls back with the order if
            // provisioning fails. Exactly-once is the DB's job (UNIQUE invitee_user_id), so a renewal or a
            // second order grants nothing. grantForOrder() never throws: a points problem must not roll back an
            // order that has already been paid and provisioned. Points are NOT cash - commission_balance and
            // balance are untouched and the existing commission logic is unchanged.
            PointsService::grantForOrder($order, $this->user);
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error($e);
            throw new ApiException(__('Activation failed'));
        }
    }


    public function setOrderType(User $user)
    {
        $order = $this->order;
        if ($order->period === Plan::PERIOD_RESET_TRAFFIC) {
            $order->type = Order::TYPE_RESET_TRAFFIC;
        } else if ($user->plan_id !== NULL && $order->plan_id !== $user->plan_id && ($user->expired_at > time() || $user->expired_at === NULL)) {
            if (!(int) admin_setting('plan_change_enable', 1))
                throw new ApiException(__('Changing your subscription is not available, please contact support or submit a ticket'));
            $order->type = Order::TYPE_UPGRADE;
            if ((int) admin_setting('surplus_enable', 1))
                $this->getSurplusValue($user, $order);
            if ($order->surplus_amount >= $order->total_amount) {
                $order->refund_amount = $order->surplus_amount - $order->total_amount;
                $order->total_amount = 0;
            } else {
                $order->total_amount = $order->total_amount - $order->surplus_amount;
            }
        } else if ($user->expired_at > time() && $order->plan_id == $user->plan_id) { // 用户订阅未过期且购买订阅与当前订阅相同 === 续费
            $order->type = Order::TYPE_RENEWAL;
        } else { // 新购
            $order->type = Order::TYPE_NEW_PURCHASE;
        }
    }

    public function setVipDiscount(User $user)
    {
        $order = $this->order;
        if ($user->discount) {
            $order->discount_amount = $order->discount_amount + ($order->total_amount * ($user->discount / 100));
        }
        $order->total_amount = $order->total_amount - $order->discount_amount;
    }

    public function setInvite(User $user): void
    {
        $order = $this->order;
        if ($user->invite_user_id && ($order->total_amount <= 0))
            return;
        $order->invite_user_id = $user->invite_user_id;
        $inviter = User::find($user->invite_user_id);
        if (!$inviter)
            return;
        $isCommission = false;
        switch ((int) $inviter->commission_type) {
            case 0:
                $commissionFirstTime = (int) admin_setting('commission_first_time_enable', 1);
                $isCommission = (!$commissionFirstTime || ($commissionFirstTime && !$this->haveValidOrder($user)));
                break;
            case 1:
                $isCommission = true;
                break;
            case 2:
                $isCommission = !$this->haveValidOrder($user);
                break;
        }

        if (!$isCommission)
            return;
        if ($inviter && $inviter->commission_rate) {
            $order->commission_balance = $order->total_amount * ($inviter->commission_rate / 100);
        } else {
            $order->commission_balance = $order->total_amount * (admin_setting('invite_commission', 10) / 100);
        }
    }

    private function haveValidOrder(User $user)
    {
        return Order::where('user_id', $user->id)
            ->whereNotIn('status', [0, 2])
            ->first();
    }

    private function getSurplusValue(User $user, Order $order)
    {
        if ($user->expired_at === NULL) {
            $this->getSurplusValueByOneTime($user, $order);
        } else {
            $this->getSurplusValueByPeriod($user, $order);
        }
    }


    private function getSurplusValueByOneTime(User $user, Order $order)
    {
        $lastOneTimeOrder = Order::where('user_id', $user->id)
            ->where('period', Plan::PERIOD_ONETIME)
            ->where('status', Order::STATUS_COMPLETED)
            ->orderBy('id', 'DESC')
            ->first();
        if (!$lastOneTimeOrder)
            return;
        $nowUserTraffic = $user->transfer_enable / 1073741824;
        if (!$nowUserTraffic)
            return;
        $paidTotalAmount = ($lastOneTimeOrder->total_amount + $lastOneTimeOrder->balance_amount);
        if (!$paidTotalAmount)
            return;
        $trafficUnitPrice = $paidTotalAmount / $nowUserTraffic;
        $notUsedTraffic = $nowUserTraffic - (($user->u + $user->d) / 1073741824);
        $result = $trafficUnitPrice * $notUsedTraffic;
        $orderModel = Order::where('user_id', $user->id)->where('period', '!=', Plan::PERIOD_RESET_TRAFFIC)->where('status', Order::STATUS_COMPLETED);
        $order->surplus_amount = $result > 0 ? $result : 0;
        $order->surplus_order_ids = array_column($orderModel->get()->toArray(), 'id');
    }

    /**
     * Prorates the residual value of a user's still-valid completed orders when
     * they switch plans mid-cycle. Legacy month-based orders (monthly/quarterly/...)
     * keep the exact original calendar-month math (unchanged). New day-based orders
     * (days_30..days_360) are summed separately in real days and layered on top via
     * a combined strtotime() relative string, so when no day-based order is present
     * in the history this produces byte-for-byte the same result as before.
     */
    private function getSurplusValueByPeriod(User $user, Order $order)
    {
        $orders = Order::where('user_id', $user->id)
            ->whereNotIn('period', [Plan::PERIOD_RESET_TRAFFIC, Plan::PERIOD_ONETIME])
            ->where('status', Order::STATUS_COMPLETED)
            ->get()
            ->toArray();
        if (!$orders)
            return;
        $orderAmountSum = 0;
        $orderMonthSum = 0;
        $orderDaysSum = 0;
        $lastValidateAt = 0;
        foreach ($orders as $item) {
            $periodKey = PlanService::getPeriodKey($item['period']);
            if (Plan::isDaysPeriod($periodKey)) {
                $days = Plan::daysPeriodToDays($periodKey);
                if (strtotime("+{$days} days", $item['created_at']) < time())
                    continue;
                $lastValidateAt = $item['created_at'];
                $orderDaysSum = $days + $orderDaysSum;
            } else {
                $period = self::STR_TO_TIME[$periodKey];
                if (strtotime("+{$period} month", $item['created_at']) < time())
                    continue;
                $lastValidateAt = $item['created_at'];
                $orderMonthSum = $period + $orderMonthSum;
            }
            $orderAmountSum = $orderAmountSum + ($item['total_amount'] + $item['balance_amount'] + $item['surplus_amount'] - $item['refund_amount']);
        }
        if (!$lastValidateAt)
            return;
        $expiredAtByOrder = strtotime("+{$orderMonthSum} month +{$orderDaysSum} days", $lastValidateAt);
        if ($expiredAtByOrder < time())
            return;
        $orderSurplusSecond = $expiredAtByOrder - time();
        $orderRangeSecond = $expiredAtByOrder - $lastValidateAt;
        $avgPrice = $orderAmountSum / $orderRangeSecond;
        $orderSurplusAmount = $avgPrice * $orderSurplusSecond;
        if (!$orderSurplusSecond || !$orderSurplusAmount)
            return;
        $order->surplus_amount = $orderSurplusAmount > 0 ? $orderSurplusAmount : 0;
        $order->surplus_order_ids = array_column($orders, 'id');
    }

    public function paid(string $callbackNo)
    {
        $order = $this->order;
        if ($order->status !== Order::STATUS_PENDING)
            return true;
        $order->status = Order::STATUS_PROCESSING;
        $order->paid_at = time();
        $order->callback_no = $callbackNo;
        if (!$order->save())
            return false;
        try {
            OrderHandleJob::dispatchSync($order->trade_no);
        } catch (\Exception $e) {
            \Log::error($e);
            return false;
        }
        return true;
    }

    public function cancel(): bool
    {
        $order = $this->order;
        try {
            DB::beginTransaction();
            // ManduVPN coupon phase: only the pending -> cancelled transition releases the balance and
            // the coupon use. The conditional update lets that happen at most once per order: a second
            // cancel, a stale copy, or a cancel racing a payment changes 0 rows and releases nothing.
            $transitioned = Order::where('id', $order->id)
                ->where('status', Order::STATUS_PENDING)
                ->update(['status' => Order::STATUS_CANCELLED]);
            if ($transitioned !== 1) {
                DB::rollBack();
                return false;
            }
            $order->status = Order::STATUS_CANCELLED;
            if ($order->coupon_id) {
                \App\Models\Coupon::where('id', $order->coupon_id)->whereNotNull('limit_use')->increment('limit_use');
            }
            if ($order->balance_amount) {
                $userService = new UserService();
                if (!$userService->addBalance($order->user_id, $order->balance_amount)) {
                    throw new \Exception('Failed to add balance.');
                }
            }
            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error($e);
            return false;
        }
    }

    private function setSpeedLimit($speedLimit)
    {
        $this->user->speed_limit = $speedLimit;
    }

    private function setDeviceLimit($deviceLimit)
    {
        $this->user->device_limit = $deviceLimit;
    }

    private function buyByResetTraffic()
    {
        $this->user->u = 0;
        $this->user->d = 0;
    }

    private function buyByPeriod(Order $order, Plan $plan)
    {
        // change plan process
        if ((int) $order->type === Order::TYPE_UPGRADE) {
            $this->user->expired_at = time();
        }
        $this->user->transfer_enable = $plan->transfer_enable * 1073741824;
        // 从一次性转换到循环
        if ($this->user->expired_at === NULL)
            $this->buyByResetTraffic();
        // 新购
        if ($order->type === Order::TYPE_NEW_PURCHASE)
            $this->buyByResetTraffic();
        $this->user->plan_id = $plan->id;
        $this->user->group_id = $plan->group_id;
        $this->user->expired_at = $this->getTime($order->period, $this->user->expired_at);
    }

    private function buyByOneTime(Plan $plan)
    {
        $this->buyByResetTraffic();
        $this->user->transfer_enable = $plan->transfer_enable * 1073741824;
        $this->user->plan_id = $plan->id;
        $this->user->group_id = $plan->group_id;
        $this->user->expired_at = NULL;
    }

    /**
     * Computes the new expiry timestamp for a period. Legacy periods
     * (monthly/quarterly/half_yearly/yearly/two_yearly/three_yearly) keep the
     * original calendar-month arithmetic, unchanged. New "days_N" periods
     * (days_30..days_360) extend by exactly N days instead of calendar months.
     */
    private function getTime($str, $timestamp)
    {
        if ($timestamp < time()) {
            $timestamp = time();
        }
        $str = PlanService::getPeriodKey($str);
        if (Plan::isDaysPeriod($str)) {
            $days = Plan::daysPeriodToDays($str);
            return strtotime("+{$days} days", $timestamp);
        }
        switch ($str) {
            case Plan::PERIOD_MONTHLY:
                return strtotime('+1 month', $timestamp);
            case Plan::PERIOD_QUARTERLY:
                return strtotime('+3 month', $timestamp);
            case Plan::PERIOD_HALF_YEARLY:
                return strtotime('+6 month', $timestamp);
            case Plan::PERIOD_YEARLY:
                return strtotime('+12 month', $timestamp);
            case Plan::PERIOD_TWO_YEARLY:
                return strtotime('+24 month', $timestamp);
            case Plan::PERIOD_THREE_YEARLY:
                return strtotime('+36 month', $timestamp);
        }
    }

    private function openEvent($eventId)
    {
        switch ((int) $eventId) {
            case 0:
                break;
            case 1:
                $this->buyByResetTraffic();
                break;
        }
    }
}
