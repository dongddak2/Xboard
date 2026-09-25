<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CouponGenerate extends FormRequest
{
    // ManduVPN coupon phase: a fixed coupon needs an explicit currency (CNY/KRW), also when a
    // legacy NULL row is edited; percentage coupons carry no currency and therefore no minimum.
    protected function prepareForValidation(): void
    {
        $this->merge([
            'new_user_only' => filter_var($this->input('new_user_only', false), FILTER_VALIDATE_BOOLEAN),
            'currency' => $this->filled('currency') ? strtoupper((string) $this->input('currency')) : null,
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'generate_count' => 'nullable|integer|max:500',
            'name' => 'required',
            'type' => 'required|in:1,2',
            'value' => 'required|integer|min:1',
            'currency' => 'nullable|required_if:type,1|in:CNY,KRW',
            'new_user_only' => 'boolean',
            'min_amount' => 'nullable|integer|min:1',
            'started_at' => 'required|integer',
            'ended_at' => 'required|integer|gt:started_at',
            'limit_use' => 'nullable|integer',
            'limit_use_with_user' => 'nullable|integer',
            'limit_plan_ids' => 'nullable|array',
            'limit_period' => 'nullable|array',
            'code' => ''
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if ((int) $this->input('type') !== 2) {
                return;
            }
            if ($this->input('currency') !== null) {
                $v->errors()->add('currency', 'percentage coupons cannot have a currency');
            }
            if ($this->input('min_amount') !== null) {
                $v->errors()->add('min_amount', 'percentage coupons cannot have a minimum amount');
            }
            if ((int) $this->input('value') > 100) {
                $v->errors()->add('value', 'percentage cannot exceed 100');
            }
        });
    }

    public function messages()
    {
        return [
            'generate_count.integer' => '生成数量必须为数字',
            'generate_count.max' => '生成数量最大为500个',
            'name.required' => '名称不能为空',
            'type.required' => '类型不能为空',
            'type.in' => '类型格式有误',
            'value.required' => '金额或比例不能为空',
            'value.integer' => '金额或比例格式有误',
            'value.min' => 'value must be at least 1',
            'currency.required_if' => 'fixed-amount coupons need a currency (CNY or KRW)',
            'currency.in' => 'currency must be CNY or KRW',
            'min_amount.integer' => 'min_amount must be an integer',
            'min_amount.min' => 'min_amount must be at least 1',
            'started_at.required' => '开始时间不能为空',
            'started_at.integer' => '开始时间格式有误',
            'ended_at.required' => '结束时间不能为空',
            'ended_at.integer' => '结束时间格式有误',
            'ended_at.gt' => 'ended_at must be after started_at',
            'limit_use.integer' => '最大使用次数格式有误',
            'limit_use_with_user.integer' => '限制用户使用次数格式有误',
            'limit_plan_ids.array' => '指定订阅格式有误',
            'limit_period.array' => '指定周期格式有误'
        ];
    }
}
