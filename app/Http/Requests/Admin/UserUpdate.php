<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Concerns\NormalizesEmail;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UserUpdate extends FormRequest
{
    use NormalizesEmail;

    public function rules(): array
    {
        return [
            'id' => 'required|integer|exists:v2_user,id',
            'name' => 'sometimes|nullable|string|max:120',
            'email' => ['sometimes', 'nullable', 'email:strict', 'max:64', Rule::unique('v2_user', 'email')->ignore((int) $this->input('id'))],
            'transfer_enable' => 'sometimes|nullable|integer|min:0',
            'expired_at' => 'sometimes|nullable|integer|min:0',
            'banned' => 'sometimes|boolean',
            'speed_limit' => 'sometimes|nullable|integer|min:0',
            'device_limit' => 'sometimes|nullable|integer|min:0',
            'remarks' => 'sometimes|nullable|string|max:2000',
            'access_mode' => 'sometimes|in:all,restricted',
            'server_ids' => 'sometimes|array',
            'server_ids.*' => 'integer|distinct|exists:v2_server,id',
            'password' => 'prohibited', 'is_admin' => 'prohibited', 'is_staff' => 'prohibited',
            'plan_id' => 'prohibited', 'group_id' => 'prohibited',
            'u' => 'prohibited', 'd' => 'prohibited', 'uuid' => 'prohibited', 'token' => 'prohibited',
        ];
    }
}
