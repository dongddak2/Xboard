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
            'total_amount' => 'required',
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
            'period.required' => '订阅周期不能为空',
            'period.in' => '订阅周期格式有误'
        ];
    }
}
