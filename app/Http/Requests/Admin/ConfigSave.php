<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ConfigSave extends FormRequest
{
    public const RULES = [
        'app_name' => 'sometimes|required|string|max:80',
        'app_url' => 'nullable|url:http,https|max:2048',
        'subscribe_url' => 'nullable|string|max:4096',
        'force_https' => 'boolean',
        'subscribe_path' => 'sometimes|required|string|min:1|max:64|regex:/^[A-Za-z0-9_-]+$/|not_in:api,assets,storage',
        'show_protocol_to_server_enable' => 'boolean',
        'server_token' => 'nullable|string|min:16|max:256',
        'server_pull_interval' => 'integer|min:10|max:3600',
        'server_push_interval' => 'integer|min:10|max:3600',
        'server_ws_enable' => 'boolean',
        'server_ws_url' => 'nullable|string|max:2048|regex:#^wss?://[^\s]+$#',
        'safe_mode_enable' => 'boolean',
        'secure_path' => 'sometimes|required|string|min:8|max:64|regex:/^[A-Za-z0-9_-]+$/|not_in:api,assets,storage',
        'password_limit_enable' => 'boolean',
        'password_limit_count' => 'integer|min:1|max:20',
        'password_limit_expire' => 'integer|min:1|max:1440',
        'subscribe_template_singbox' => 'nullable|string|max:262144',
        'subscribe_template_clash' => 'nullable|string|max:262144',
        'subscribe_template_clashmeta' => 'nullable|string|max:262144',
        'subscribe_template_stash' => 'nullable|string|max:262144',
        'subscribe_template_surge' => 'nullable|string|max:262144',
        'subscribe_template_surfboard' => 'nullable|string|max:262144',
    ];

    public function rules(): array
    {
        return self::RULES;
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $unknown = array_diff(array_keys($this->all()), array_keys(self::RULES));
            foreach ($unknown as $key) {
                $validator->errors()->add($key, '不支持的设置项');
            }
            $subscribe = $this->input('subscribe_path', admin_setting('subscribe_path', 's'));
            $admin = $this->input('secure_path', admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))));
            if ($subscribe === $admin) {
                $validator->errors()->add('subscribe_path', '订阅路径不能与管理路径相同');
            }
            $subscribeUrl = $this->input('subscribe_url');
            foreach (is_string($subscribeUrl) ? preg_split('/\s*,\s*/', $subscribeUrl, -1, PREG_SPLIT_NO_EMPTY) : [] as $url) {
                if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)
                    || parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_QUERY) !== null || parse_url($url, PHP_URL_FRAGMENT) !== null) {
                    $validator->errors()->add('subscribe_url', '订阅域名须为完整的 http(s) URL，不能包含凭据、查询或片段');
                }
            }
        });
    }
}
