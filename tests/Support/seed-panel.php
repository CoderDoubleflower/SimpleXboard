<?php

// Deliberately unavailable to production callers; run through run-stack.py.
if (getenv('SIMPLEXBOARD_E2E') !== '1' || !getenv('E2E_WORK_DIR')) {
    throw new RuntimeException('Only the disposable integration test stack may run this fixture.');
}
require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config('database.default') !== 'sqlite'
    || !str_starts_with(config('database.connections.sqlite.database'), getenv('E2E_WORK_DIR') . '/')) {
    throw new RuntimeException('Refusing a non-test database.');
}

use App\Models\Server;
use App\Models\User;
use App\Services\UserService;
use App\Utils\Helper;
use Illuminate\Support\Facades\Hash;

$serverToken = bin2hex(random_bytes(24));
admin_setting([
    'app_name' => 'SimpleXboard', 'secure_path' => getenv('E2E_ADMIN_PATH'),
    'app_url' => getenv('E2E_BASE_URL'), 'subscribe_url' => getenv('E2E_BASE_URL'),
    'server_token' => $serverToken, 'server_ws_enable' => true, 'server_ws_url' => getenv('E2E_WS_URL'),
    'server_pull_interval' => 60, 'server_push_interval' => 60, 'password_limit_enable' => true,
]);
$admin = User::query()->forceCreate([
    'name' => '管理员', 'email' => getenv('E2E_ADMIN_EMAIL'), 'password' => Hash::make(getenv('E2E_ADMIN_PASSWORD')),
    'is_admin' => true, 'banned' => false, 'uuid' => Helper::guid(true), 'token' => Helper::guid(),
]);
$private = random_bytes(32);
$public = \ParagonIE_Sodium_Compat::crypto_scalarmult_base($private);
$keys = ['private_key' => Helper::base64EncodeUrlSafe($private), 'public_key' => Helper::base64EncodeUrlSafe($public)];
$nodes = [];
$nodes[] = Server::create([
    'name' => '测试 REALITY', 'type' => 'vless', 'host' => '127.0.0.1', 'port' => '24443', 'server_port' => 24443,
    'enabled' => true, 'show' => true, 'sort' => 1,
    'protocol_settings' => [
        'tls' => 2, 'flow' => 'xtls-rprx-vision', 'network' => 'tcp',
        'reality_settings' => $keys + ['server_name' => 'www.example.com', 'short_id' => '0123abcd'],
        'utls' => ['enabled' => true, 'fingerprint' => 'chrome'],
    ],
]);
$nodes[] = Server::create([
    'name' => '测试 Hysteria2', 'type' => 'hysteria', 'host' => '127.0.0.1', 'port' => '24444', 'server_port' => 24444,
    'enabled' => true, 'show' => true, 'sort' => 2,
    'cert_config' => ['cert_mode' => 'self', 'domain' => 'localhost'],
    'protocol_settings' => ['version' => 2, 'tls' => ['server_name' => 'localhost', 'allow_insecure' => true],
        'bandwidth' => ['up' => 100, 'down' => 100], 'obfs' => ['open' => true, 'type' => 'salamander', 'password' => bin2hex(random_bytes(12))]],
]);
$member = app(UserService::class)->createUser(['name' => '测试成员', 'email' => 'member@example.test']);
$member->save();
$file = getenv('E2E_FIXTURE_FILE');
file_put_contents($file, json_encode([
    'admin' => ['id' => $admin->id, 'email' => $admin->email],
    'nodes' => collect($nodes)->map(fn ($node) => ['id' => $node->id, 'type' => $node->type, 'name' => $node->name, 'machine_id' => null])->all(),
    'member' => ['id' => $member->id, 'token' => $member->token, 'uuid' => $member->uuid],
    'server_token' => $serverToken,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
chmod($file, 0600);
echo "Isolated administrator, member and two protocol nodes created.\n";
