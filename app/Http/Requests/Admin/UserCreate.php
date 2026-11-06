<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Concerns\NormalizesEmail;
use Illuminate\Foundation\Http\FormRequest;

class UserCreate extends FormRequest
{
    use NormalizesEmail;

    public function rules(): array
    {
        return [
            'name' => 'nullable|required_without:email|string|max:120',
            'email' => 'nullable|required_without:name|email:strict|max:64|unique:v2_user,email',
            'transfer_enable' => 'sometimes|nullable|integer|min:0',
            'expired_at' => 'nullable|integer|min:0',
            'speed_limit' => 'nullable|integer|min:0',
            'device_limit' => 'nullable|integer|min:0',
            'remarks' => 'nullable|string|max:2000',
            'access_mode' => 'sometimes|in:all,restricted',
            'server_ids' => 'sometimes|array',
            'server_ids.*' => 'integer|distinct|exists:v2_server,id',
            'password' => 'prohibited', 'is_admin' => 'prohibited', 'is_staff' => 'prohibited',
            'plan_id' => 'prohibited', 'group_id' => 'prohibited',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->input('access_mode', 'all') !== 'restricted' && $this->input('server_ids')) {
                $validator->errors()->add('server_ids', '指定节点时必须使用restricted访问模式');
            }
        });
    }
}
