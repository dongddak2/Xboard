<?php

namespace App\Http\Requests\Admin;

use App\Models\Plan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OrderAssign extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'plan_id' => 'required',
            'email' => 'required',
            // ManduVPN QA-W2: the amount unit depends on the currency below - CNY is in FEN, KRW is in plain
            // WON (Money treats KRW as zero-decimal). It was previously unvalidated, so a non-numeric string
            // could land in this integer column.
            'total_amount' => 'required|numeric|min:0',
            // ManduVPN QA-W2: an admin grant had no way to declare KRW, so the order was created with
            // currency NULL, which checkout reads as CNY (OrderController::checkout). With only a KRW gateway
            // enabled the customer could then never pay it - the currency-mismatch guard refused it with 400.
            // v2_payment.currency stays the authority for what a gateway charges; this is only the order's own
            // snapshot, exactly as the checkout path writes it. Optional, and omitting it keeps the previous
            // behaviour (CNY).
            'currency' => 'nullable|string|in:CNY,KRW',
            // ManduVPN: legacy periods + the 12 days_N periods (source of truth: Plan::DAYS_BASED_PERIODS); days_31 etc. stay invalid.
            'period' => ['required', Rule::in(array_merge(
                ['month_price', 'quarter_price', 'half_year_price', 'year_price', 'two_year_price', 'three_year_price', 'onetime_price', 'reset_price'],
                array_map(fn ($d) => 'days_' . $d, Plan::DAYS_BASED_PERIODS)
            ))]
        ];
    }

    public function messages()
    {
        return [
            'plan_id.required' => '订阅不能为空',
            'email.required' => '邮箱不能为空',
            'total_amount.required' => '支付金额不能为空',
            'total_amount.numeric' => '支付金额格式有误',
            'total_amount.min' => '支付金额不能为负数',
            'currency.in' => 'currency must be CNY or KRW',
            'period.required' => '订阅周期不能为空',
            'period.in' => '订阅周期格式有误'
        ];
    }
}
