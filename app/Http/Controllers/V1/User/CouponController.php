<?php

namespace App\Http\Controllers\V1\User;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\CouponResource;
use App\Models\Payment;
use App\Models\Plan;
use App\Services\CouponService;
use App\Services\PlanService;
use Illuminate\Http\Request;

class CouponController extends Controller
{
    public function check(Request $request)
    {
        if (empty($request->input('code'))) {
            return $this->fail([422, __('Coupon cannot be empty')]);
        }
        $couponService = new CouponService($request->input('code'));
        $couponService->setPlanId($request->input('plan_id'));
        $couponService->setUserId($request->user()->id);
        $couponService->setPeriod($request->input('period'));

        // ManduVPN coupon phase: with payment_id the server resolves currency and plan price the
        // same way order/save does and returns a display-only preview; order/save re-validates.
        $preview = null;
        if ($request->filled('payment_id')) {
            $payment = Payment::where('id', $request->input('payment_id'))->where('enable', 1)->first();
            $plan = Plan::find($request->input('plan_id'));
            if (!$payment || !$plan || !$request->input('period')) {
                throw new ApiException(__('Payment method is not available'));
            }
            $currency = strtoupper($payment->currency ?: 'CNY');
            $key = PlanService::getPeriodKey((string) $request->input('period'));
            $price = $currency === 'KRW' ? (optional($plan->prices_krw)[$key] ?? null) : (optional($plan->prices)[$key] ?? null);
            if (!is_numeric($price) || $price <= 0) {
                throw new ApiException(__('This payment period cannot be purchased, please choose another period'));
            }
            $amount = $currency === 'KRW' ? (int) $price : (int) round($price * 100);
            $couponService->setCurrency($currency);
            $couponService->setAmount($amount);
            $couponService->check();
            $discount = $couponService->discountFor($amount);
            $preview = ['currency' => $currency, 'amount' => $amount, 'discount_amount' => $discount, 'total_amount' => $amount - $discount];
        } else {
            $couponService->check();
        }
        $data = CouponResource::make($couponService->getCoupon())->toArray($request);
        if ($preview) {
            $data['preview'] = $preview;
        }
        return $this->success($data);
    }
}
