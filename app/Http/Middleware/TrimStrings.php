<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\TrimStrings as Middleware;

class TrimStrings extends Middleware
{
    /**
     * 不需要去除前后空格的字段名
     * @var array<int, string>
     */
    protected $except = [
        'password',
        'password_confirmation',
        'settings.password',
        'settings.username',
        'settings.obfs.password',
        'old_password',
        'new_password',
        'new_password_confirmation',
        'encrypted_data',
        'content',
        'profile.base_yaml',
        'subscribe_template_mihomo',
        'subscribe_template_clashmeta',
        'subscribe_template_stash',
        'signature'
    ];
}
