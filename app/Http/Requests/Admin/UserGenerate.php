<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator;

class UserGenerate extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'generate_count' => 'nullable|integer|min:1|max:500|required_without:email_prefix',
            'expired_at' => 'nullable|integer',
            'plan_id' => 'nullable|integer|exists:v2_plan,id',
            'email_prefix' => 'nullable|string',
            'email_suffix' => 'required|string',
            'password' => 'nullable|string|min:8'
        ];
    }

    protected function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $prefix = $this->input('email_prefix');
            $count = (int) $this->input('generate_count', 0);
            $prefix = $prefix
                ? $prefix . ($count ? '_' . $count : '')
                : 'xxxxxx';

            $email = User::normalizeEmail($prefix . '@' . $this->input('email_suffix'));
            if (Validator::make(['email' => $email], ['email' => 'required|email:strict|max:64'])->fails()) {
                $validator->errors()->add('email_suffix', '生成邮箱格式无效或规范化后长度超过64个字符');
            }
        });
    }

    public function messages()
    {
        return [
            'generate_count.integer' => '生成数量必须为数字',
            'generate_count.max' => '生成数量最大为500个'
        ];
    }
}
