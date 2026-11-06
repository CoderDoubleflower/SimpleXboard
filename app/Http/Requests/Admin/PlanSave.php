<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** Admin-only transitional configuration; no sale, pricing or product fields. */
class PlanSave extends FormRequest
{
    public function rules(): array
    {
        return [
            'id' => 'nullable|integer|exists:v2_plan,id',
            'name' => 'required|string|max:255',
            'reset_traffic_method' => 'nullable|integer|between:0,4',
            'transfer_enable' => 'required|integer|min:1',
            'group_id' => 'required|integer|exists:v2_server_group,id',
            'speed_limit' => 'nullable|integer|min:0',
            'device_limit' => 'nullable|integer|min:0',
            'force_update' => 'sometimes|boolean',
        ];
    }
}
