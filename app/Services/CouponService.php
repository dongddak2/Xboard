<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Coupon;
use App\Models\Order;

// ManduVPN coupon phase: currency-aware coupons (CNY/KRW), new-user-only, minimum amount.
// Every amount is in the ORDER currency's minor unit, the same unit as Order.total_amount:
// CNY = fen (x100, legacy xboard), KRW = won (no minor unit). No currency conversion, ever.
class CouponService
{
    public const TYPE_FIXED = 1;
    public const TYPE_PERCENT = 2;
    public const CURRENCIES = ['CNY', 'KRW'];

    public $coupon;
    public $planId;
    public $userId;
    public $period;
    public $currency; // order currency; null = no order context (coupon/check without payment_id)
    public $amount;   // order amount before discount, minor unit of $currency

    public function __construct($code)
    {
        $this->coupon = Coupon::where('code', $code)
            ->lockForUpdate()
            ->first();
    }

    // Currency a fixed coupon is denominated in; legacy rows (NULL) were always CNY fen.
    public static function fixedCurrency(Coupon $coupon): ?string
    {
        if ((int) $coupon->type !== self::TYPE_FIXED) {
            return null;
        }
        return strtoupper((string) ($coupon->currency ?: 'CNY'));
    }

    // Integer discount in the order's minor unit; percentage is floored, never above the amount.
    public function discountFor(int $amount): int
    {
        $value = (int) $this->coupon->value;
        $discount = (int) $this->coupon->type === self::TYPE_FIXED ? $value : intdiv($amount * $value, 100);
        return max(0, min($discount, $amount));
    }

    public function use(Order $order): bool
    {
        $this->setPlanId($order->plan_id);
        $this->setUserId($order->user_id);
        $this->setPeriod($order->period);
        $this->setCurrency($order->currency ?: 'CNY');
        $this->setAmount((int) $order->total_amount);
        $this->check();
        $order->discount_amount = $this->discountFor((int) $order->total_amount);
        if ($this->coupon->limit_use !== NULL) {
            if ($this->coupon->limit_use <= 0)
                return false;
            $this->coupon->limit_use = $this->coupon->limit_use - 1;
            if (!$this->coupon->save()) {
                return false;
            }
        }
        return true;
    }

    public function getId()
    {
        return $this->coupon->id;
    }

    public function getCoupon()
    {
        return $this->coupon;
    }

    public function setPlanId($planId)
    {
        $this->planId = $planId;
    }

    public function setUserId($userId)
    {
        $this->userId = $userId;
    }

    public function setPeriod($period)
    {
        if ($period) {
            $this->period = PlanService::getPeriodKey($period);
        }
    }

    public function setCurrency($currency)
    {
        $this->currency = $currency ? strtoupper((string) $currency) : null;
    }

    public function setAmount($amount)
    {
        $this->amount = $amount === null ? null : (int) $amount;
    }

    // Paid = processing/completed/discounted with money actually paid. Pending and cancelled
    // orders never count, and the order being created is not in the table yet.
    public static function hasPaidOrder(int $userId): bool
    {
        return Order::where('user_id', $userId)
            ->whereIn('status', [Order::STATUS_PROCESSING, Order::STATUS_COMPLETED, Order::STATUS_DISCOUNTED])
            ->where(function ($q) {
                $q->where('total_amount', '>', 0)->orWhere('balance_amount', '>', 0);
            })
            ->exists();
    }

    public function checkLimitUseWithUser(): bool
    {
        // Pending orders hold the coupon too, and the locking read makes two concurrent orders of
        // the same user serialize on it instead of both counting 0.
        $usedCount = Order::where('coupon_id', $this->coupon->id)
            ->where('user_id', $this->userId)
            ->where('status', '!=', Order::STATUS_CANCELLED)
            ->lockForUpdate()
            ->get(['id'])
            ->count();
        if ($usedCount >= $this->coupon->limit_use_with_user)
            return false;
        return true;
    }

    public function check()
    {
        if (!$this->coupon || !$this->coupon->show) {
            throw new ApiException(__('Invalid coupon'));
        }
        if ($this->coupon->limit_use <= 0 && $this->coupon->limit_use !== NULL) {
            throw new ApiException(__('This coupon is no longer available'));
        }
        if (time() < $this->coupon->started_at) {
            throw new ApiException(__('This coupon has not yet started'));
        }
        if (time() > $this->coupon->ended_at) {
            throw new ApiException(__('This coupon has expired'));
        }
        if ($this->coupon->limit_plan_ids && $this->planId) {
            if (!in_array($this->planId, $this->coupon->limit_plan_ids)) {
                throw new ApiException(__('The coupon code cannot be used for this subscription'));
            }
        }
        if ($this->coupon->limit_period && $this->period) {
            if (!in_array($this->period, $this->coupon->limit_period)) {
                throw new ApiException(__('The coupon code cannot be used for this period'));
            }
        }
        $this->checkCurrencyAndAmount();
        if ($this->coupon->new_user_only && $this->userId && self::hasPaidOrder((int) $this->userId)) {
            throw new ApiException(__('This coupon is only available to new users'));
        }
        if ($this->coupon->limit_use_with_user !== NULL && $this->userId) {
            if (!$this->checkLimitUseWithUser()) {
                throw new ApiException(__('The coupon can only be used :limit_use_with_user per person', [
                    'limit_use_with_user' => $this->coupon->limit_use_with_user
                ]));
            }
        }
    }

    protected function checkCurrencyAndAmount(): void
    {
        if ($this->currency === null) {
            return;
        }
        $fixed = self::fixedCurrency($this->coupon);
        if ($fixed !== null && $fixed !== $this->currency) {
            throw new ApiException(__('This coupon cannot be used with this currency'));
        }
        if ($this->amount === null) {
            return;
        }
        if ($this->coupon->min_amount !== null) {
            // A minimum only has meaning in the coupon's own currency (percentage coupons carry none).
            $minCurrency = $fixed ?? ($this->coupon->currency ? strtoupper((string) $this->coupon->currency) : null);
            if ($minCurrency !== $this->currency || $this->amount < (int) $this->coupon->min_amount) {
                throw new ApiException(__('The order amount does not meet the minimum for this coupon'));
            }
        }
        if ($this->amount - $this->discountFor($this->amount) <= 0) {
            throw new ApiException(__('This coupon cannot reduce the order amount to zero'));
        }
    }
}
