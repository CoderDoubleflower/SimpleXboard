<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UserFetch extends FormRequest
{
    public function rules(): array
    {
        return [
            'current' => 'sometimes|integer|min:1',
            'pageSize' => 'sometimes|integer|min:1|max:100',
            'search' => 'sometimes|nullable|string|max:120',
            'banned' => 'sometimes|nullable|boolean',
            'filter' => 'prohibited', 'sort' => 'prohibited',
        ];
    }
}
