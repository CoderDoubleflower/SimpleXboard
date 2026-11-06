<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ConfigSave;
use App\Models\SubscribeTemplate;
use Illuminate\Support\Facades\DB;

class ConfigController extends Controller
{
    private const TEMPLATES = ['singbox', 'clash', 'clashmeta', 'stash', 'surge', 'surfboard'];

    public function fetch()
    {
        $data = [
            'site' => [
                'app_name' => admin_setting('app_name', 'SimpleXboard'),
                'app_url' => admin_setting('app_url', ''),
                'subscribe_url' => admin_setting('subscribe_url', ''),
                'force_https' => (bool) admin_setting('force_https', false),
            ],
            'subscribe' => [
                'subscribe_path' => admin_setting('subscribe_path', 's'),
                'show_protocol_to_server_enable' => (bool) admin_setting('show_protocol_to_server_enable', false),
            ],
            'server' => [
                'server_token' => admin_setting('server_token', ''),
                'server_pull_interval' => (int) admin_setting('server_pull_interval', 60),
                'server_push_interval' => (int) admin_setting('server_push_interval', 60),
                'server_ws_enable' => (bool) admin_setting('server_ws_enable', true),
                'server_ws_url' => admin_setting('server_ws_url', ''),
            ],
            'safe' => [
                'secure_path' => admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))),
                'safe_mode_enable' => (bool) admin_setting('safe_mode_enable', false),
                'password_limit_enable' => (bool) admin_setting('password_limit_enable', true),
                'password_limit_count' => (int) admin_setting('password_limit_count', 5),
                'password_limit_expire' => (int) admin_setting('password_limit_expire', 15),
            ],
            'subscribe_template' => [],
        ];
        foreach (self::TEMPLATES as $type) {
            $content = subscribe_template($type) ?? '';
            $data['subscribe_template']['subscribe_template_' . $type] = is_string($content)
                ? $content : json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        return $this->success($data);
    }

    public function save(ConfigSave $request)
    {
        DB::transaction(function () use ($request) {
            $settings = [];
            foreach ($request->validated() as $key => $value) {
                if (str_starts_with($key, 'subscribe_template_')) {
                    SubscribeTemplate::setContent(substr($key, strlen('subscribe_template_')), $value);
                } else {
                    $settings[$key] = $value;
                }
            }
            if ($settings) {
                admin_setting($settings);
            }
        });
        $path = admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key'))));
        return $this->success(['saved' => true, 'secure_path' => $path, 'admin_url' => '/' . $path]);
    }
}
