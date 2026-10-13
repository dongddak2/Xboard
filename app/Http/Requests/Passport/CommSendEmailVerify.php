<?php

namespace App\Http\Requests\Passport;

use Illuminate\Foundation\Http\FormRequest;

class CommSendEmailVerify extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'email' => 'required|email:strict',
            // See AuthRegister: the region VALUES are owned by SignupEmailPolicyService::REGIONS.
            'purpose' => 'sometimes|string|in:signup,password_reset',
            'region' => 'required_unless:purpose,password_reset|nullable|string'
        ];
    }

    public function messages()
    {
        return [
            'email.required' => __('Email can not be empty'),
            'email.email' => __('Email format is incorrect'),
            'region.required_unless' => __('Signup region is required')
        ];
    }
}
