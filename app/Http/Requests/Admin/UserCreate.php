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
            'email' => 'required|email:strict|max:64|unique:v2_user,email',
            'password' => 'required|string|min:8',
            'plan_id' => 'nullable|integer|exists:v2_plan,id',
            'expired_at' => 'nullable|integer|min:1',
            // Member quotas use bytes, unlike the transitional Plan's GiB value.
            'transfer_enable' => 'sometimes|integer|min:1',
            'speed_limit' => 'nullable|integer|min:0',
            'device_limit' => 'nullable|integer|min:0',
        ];
    }
}
