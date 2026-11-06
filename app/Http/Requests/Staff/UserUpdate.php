<?php

namespace App\Http\Requests\Staff;

use App\Http\Requests\Concerns\NormalizesEmail;
use Illuminate\Foundation\Http\FormRequest;

class UserUpdate extends FormRequest
{
    use NormalizesEmail;
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'email' => 'required|email:strict|max:64',
            'password' => 'nullable',
            'transfer_enable' => 'numeric|min:0',
            'expired_at' => 'nullable|integer',
            'banned' => 'required|in:0,1',
            'plan_id' => 'nullable|integer|exists:v2_plan,id',
            'u' => 'integer',
            'd' => 'integer',
        ];
    }

    public function messages()
    {
        return [
            'email.required' => '邮箱不能为空',
            'email.email' => '邮箱格式不正确',
            'transfer_enable.numeric' => '流量格式不正确',
            'expired_at.integer' => '到期时间格式不正确',
            'banned.required' => '是否封禁不能为空',
            'banned.in' => '是否封禁格式不正确',
            'plan_id.integer' => '订阅计划格式不正确',
            'u.integer' => '上行流量格式不正确',
            'd.integer' => '下行流量格式不正确',
        ];
    }
}
