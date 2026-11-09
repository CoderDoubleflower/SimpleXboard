<?php

namespace App\Services;

use App\Models\Server;
use App\Models\ServerOutbound;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ServerOutboundService
{
    public const TYPES = ['vless', 'hysteria2', 'socks', 'shadowsocks', 'trojan', 'vmess'];
    public const SHADOWSOCKS_METHODS = [
        'aes-128-gcm', 'aes-256-gcm', 'chacha20-ietf-poly1305',
        '2022-blake3-aes-128-gcm', '2022-blake3-aes-256-gcm', '2022-blake3-chacha20-poly1305',
    ];
    private const FINGERPRINTS = ['chrome', 'firefox', 'edge', 'safari', '360', 'qq', 'ios', 'android', 'random', 'randomized'];

    /** A deliberately small, validated subset of sing-box's client options. */
    public function validateSettings(string $type, array $settings): array
    {
        if (!in_array($type, self::TYPES, true)) {
            throw ValidationException::withMessages(['type' => '出站协议仅支持 VLESS、Hysteria2、SOCKS5、Shadowsocks、Trojan 和 VMess。']);
        }
        $keys = match ($type) {
            'vless' => 'server,server_port,uuid,flow,tls',
            'hysteria2' => 'server,server_port,password,tls,obfs,up_mbps,down_mbps',
            'socks' => 'server,server_port,version,username,password',
            'shadowsocks' => 'server,server_port,method,password',
            'trojan' => 'server,server_port,password,tls',
            'vmess' => 'server,server_port,uuid,security,alter_id,tls',
        };
        $hostRule = function ($attribute, $value, $fail) {
            if (!is_string($value) || !$this->validHost($value)) $fail('请填写有效的主机名或 IP，不要包含协议、路径或端口。');
        };
        $rules = [
            'settings' => ['required', 'array:' . $keys],
            'settings.server' => ['required', 'string', 'max:253', $hostRule],
            'settings.server_port' => 'required|integer|between:1,65535',
        ];
        if (in_array($type, ['vless', 'hysteria2', 'trojan', 'vmess'], true)) {
            $tlsKeys = 'enabled,server_name,insecure,alpn';
            if ($type !== 'hysteria2') $tlsKeys .= ',utls';
            if ($type === 'vless') $tlsKeys .= ',reality';
            $rules += [
                'settings.tls' => [$type === 'vmess' ? 'sometimes' : 'required', 'array:' . $tlsKeys, 'required_array_keys:enabled'],
                'settings.tls.enabled' => 'required_with:settings.tls|boolean|in:1',
                'settings.tls.server_name' => ['nullable', 'string', 'max:253', $hostRule],
                'settings.tls.insecure' => 'sometimes|boolean',
                'settings.tls.alpn' => 'sometimes|array|min:1|max:16',
                'settings.tls.alpn.*' => ['required', 'string', 'max:255', 'regex:/^[\x21-\x7e]+$/'],
            ];
            if ($type !== 'hysteria2') {
                $rules += [
                    'settings.tls.utls' => 'sometimes|array:enabled,fingerprint|required_array_keys:enabled,fingerprint',
                    'settings.tls.utls.enabled' => 'required_with:settings.tls.utls|boolean|in:1',
                    'settings.tls.utls.fingerprint' => 'required_with:settings.tls.utls|string|in:' . implode(',', self::FINGERPRINTS),
                ];
            }
        }
        if (in_array($type, ['vless', 'vmess'], true)) $rules['settings.uuid'] = 'required|uuid';
        if (in_array($type, ['hysteria2', 'trojan', 'shadowsocks'], true)) {
            $rules['settings.password'] = ['required', 'string', 'max:2048', 'not_regex:/[\x00-\x1f\x7f]/'];
        }
        if ($type === 'vless') {
            $rules += [
                'settings.flow' => 'nullable|string|in:xtls-rprx-vision',
                'settings.tls.reality' => 'sometimes|array:enabled,public_key,short_id|required_array_keys:enabled,public_key',
                'settings.tls.reality.enabled' => 'required_with:settings.tls.reality|boolean|in:1',
                'settings.tls.reality.public_key' => ['required_with:settings.tls.reality', 'string', function ($attribute, $value, $fail) {
                    if (!is_string($value) || !preg_match('/^[A-Za-z0-9_-]{43}$/D', $value)
                        || strlen((string) base64_decode(strtr($value, '-_', '+/') . '=', true)) !== 32) {
                        $fail('REALITY 公钥必须是 32 字节的 Base64URL 公钥。');
                    }
                }],
                'settings.tls.reality.short_id' => ['nullable', 'string', 'regex:/^(?:[0-9a-fA-F]{2}){0,8}$/D'],
            ];
        } elseif ($type === 'hysteria2') {
            $rules += [
                'settings.obfs' => 'sometimes|array:type,password|required_array_keys:type,password',
                'settings.obfs.type' => 'required_with:settings.obfs|in:salamander',
                'settings.obfs.password' => ['required_with:settings.obfs', 'string', 'max:2048', 'not_regex:/[\x00-\x1f\x7f]/'],
                'settings.up_mbps' => 'sometimes|integer|between:1,100000',
                'settings.down_mbps' => 'sometimes|integer|between:1,100000',
            ];
        } elseif ($type === 'socks') {
            $credentialLength = function ($attribute, $value, $fail) {
                if (is_string($value) && strlen($value) > 255) $fail('SOCKS5 用户名和密码最多各 255 字节。');
            };
            $rules += [
                'settings.version' => 'sometimes|string|in:5',
                'settings.username' => ['required_with:settings.password', 'string', 'min:1', 'not_regex:/[\x00-\x1f\x7f]/', $credentialLength],
                'settings.password' => ['required_with:settings.username', 'string', 'min:1', 'not_regex:/[\x00-\x1f\x7f]/', $credentialLength],
            ];
        } elseif ($type === 'shadowsocks') {
            $rules['settings.method'] = 'required|in:' . implode(',', self::SHADOWSOCKS_METHODS);
        } elseif ($type === 'vmess') {
            $rules += [
                'settings.security' => 'sometimes|in:auto,none,zero,aes-128-gcm,chacha20-poly1305',
                'settings.alter_id' => 'sometimes|integer|between:0,65535',
            ];
        }
        $validator = Validator::make(['settings' => $settings], $rules, [
            'array' => ':attribute 含有不支持的配置字段或格式错误。',
            'accepted' => ':attribute 必须启用。',
        ]);
        $validator->after(function ($validator) use ($settings, $type) {
            if (!empty($settings['tls']['reality']) && is_array($settings['tls']['reality'])) {
                if (empty($settings['tls']['server_name'])) $validator->errors()->add('settings.tls.server_name', 'REALITY 出站必须填写 SNI。');
                if (!empty($settings['tls']['insecure'])) $validator->errors()->add('settings.tls.insecure', 'REALITY 出站不支持跳过证书验证选项。');
            }
            if ($type === 'shadowsocks' && is_string($settings['method'] ?? null)
                && str_starts_with($settings['method'], '2022-') && is_string($settings['password'] ?? null)) {
                $method = $settings['method'];
                $keys = explode(':', $settings['password']);
                $length = $method === '2022-blake3-aes-128-gcm' ? 16 : 32;
                $valid = $method !== '2022-blake3-chacha20-poly1305' || count($keys) === 1;
                foreach ($keys as $key) {
                    $decoded = base64_decode($key, true);
                    $valid = $valid && $decoded !== false && strlen($decoded) === $length && base64_encode($decoded) === $key;
                }
                if (!$valid) $validator->errors()->add('settings.password', 'Shadowsocks 2022 密钥须为正确长度、带填充的标准 Base64；ChaCha20 仅支持一个密钥。');
            }
        });
        $validated = $validator->validate()['settings'];
        $validated['server_port'] = (int) $validated['server_port'];
        if (isset($validated['tls'])) {
            $validated['tls']['enabled'] = true;
            $validated['tls']['insecure'] = (bool) ($validated['tls']['insecure'] ?? false);
            if (empty($validated['tls']['server_name'])) unset($validated['tls']['server_name']);
            if (isset($validated['tls']['alpn'])) $validated['tls']['alpn'] = array_values(array_unique($validated['tls']['alpn']));
            if (isset($validated['tls']['utls'])) $validated['tls']['utls']['enabled'] = true;
        }
        if (isset($validated['uuid'])) $validated['uuid'] = strtolower($validated['uuid']);
        if ($type === 'vless') {
            if (empty($validated['flow'])) unset($validated['flow']);
            if (isset($validated['tls']['reality'])) {
                $validated['tls']['reality']['enabled'] = true;
                $validated['tls']['reality']['short_id'] = strtolower($validated['tls']['reality']['short_id'] ?? '');
                $validated['tls']['utls'] ??= ['enabled' => true, 'fingerprint' => 'chrome'];
            }
            if (isset($validated['tls']['utls'])) $validated['tls']['utls']['enabled'] = true;
        } elseif ($type === 'hysteria2') {
            foreach (['up_mbps', 'down_mbps'] as $key) if (isset($validated[$key])) $validated[$key] = (int) $validated[$key];
        } elseif ($type === 'socks') {
            $validated['version'] = '5';
        } elseif ($type === 'vmess') {
            $validated['security'] ??= 'auto';
            $validated['alter_id'] = (int) ($validated['alter_id'] ?? 0);
        }
        return $validated;
    }

    /** Parse a single share URI without fetching a subscription or contacting it. */
    public function import(string $uri): array
    {
        $uri = trim($uri);
        if (strlen($uri) > 8192 || preg_match('/[\x00-\x20\x7f]/', $uri) || preg_match('/%(?![0-9a-fA-F]{2})/', $uri)) {
            $this->invalidUri('分享链接格式无效，请粘贴一条完整链接。');
        }
        if (str_starts_with(strtolower($uri), 'vmess://')) return $this->importVmess(substr($uri, 8));
        // Legacy Shadowsocks encodes the complete method:password@host:port.
        if (str_starts_with(strtolower($uri), 'ss://')) {
            [$authority, $fragment] = array_pad(explode('#', substr($uri, 5), 2), 2, null);
            if (!str_contains($authority, '@')) {
                $decoded = $this->decodeBase64($authority);
                $separator = strrpos($decoded, '@');
                if ($separator === false) $this->invalidUri('Shadowsocks 链接缺少服务器地址。');
                $credentials = explode(':', substr($decoded, 0, $separator), 2);
                if (count($credentials) !== 2) $this->invalidUri('Shadowsocks 链接缺少加密方式或密码。');
                // A decoded legacy password can itself contain /, ?, #, @ or %.
                $uri = 'ss://' . rawurlencode($credentials[0]) . ':' . rawurlencode($credentials[1])
                    . '@' . substr($decoded, $separator + 1) . ($fragment === null ? '' : '#' . $fragment);
            }
        }
        try { $parts = parse_url($uri); } catch (\ValueError) { $parts = false; }
        $aliases = ['vless' => 'vless', 'hysteria2' => 'hysteria2', 'hy2' => 'hysteria2', 'socks' => 'socks', 'socks5' => 'socks', 'ss' => 'shadowsocks', 'trojan' => 'trojan'];
        if (!$parts || !isset($aliases[strtolower($parts['scheme'] ?? '')])) {
            $this->invalidUri('仅支持单条 VLESS、Hysteria2、SOCKS5、Shadowsocks、Trojan 或 VMess 分享链接。');
        }
        $type = $aliases[strtolower($parts['scheme'])];
        if (!isset($parts['host']) || ($type !== 'socks' && !isset($parts['user']))
            || !in_array($parts['path'] ?? '', ['', '/'], true)) {
            $this->invalidUri('分享链接必须包含正确的身份凭据、服务器地址和有效的根路径。');
        }
        $query = $this->query($parts['query'] ?? '');
        $allowed = match ($type) {
            'vless' => ['encryption', 'security', 'type', 'headerType', 'flow', 'sni', 'serverName', 'alpn', 'fp', 'pbk', 'sid', 'allowInsecure', 'insecure'],
            'hysteria2' => ['sni', 'alpn', 'insecure', 'allowInsecure', 'obfs', 'obfs-password', 'upmbps', 'downmbps'],
            'trojan' => ['security', 'type', 'sni', 'peer', 'alpn', 'fp', 'allowInsecure', 'insecure'],
            default => [],
        };
        if (array_diff(array_keys($query), $allowed)) $this->invalidUri('分享链接包含暂不支持的参数；当前不支持 WebSocket、gRPC、端口跳跃或 Shadowsocks 插件。');
        $server = $parts['host'];
        if (str_starts_with($server, '[') && str_ends_with($server, ']')) $server = substr($server, 1, -1);
        $tls = $this->uriTls($query);
        $settings = ['server' => $server, 'server_port' => $parts['port'] ?? 443];
        if ($type === 'vless') {
            if (isset($parts['pass']) || !isset($parts['port'])) $this->invalidUri('VLESS 链接须包含 UUID 和端口，不能使用用户名密码格式。');
            if (!in_array($query['type'] ?? 'tcp', ['tcp', 'raw'], true) || ($query['headerType'] ?? 'none') !== 'none') {
                $this->invalidUri('当前仅支持 TCP 传输的 VLESS 出站，不支持 WebSocket、gRPC 或 TCP 伪装。');
            }
            if (($query['encryption'] ?? 'none') !== 'none') $this->invalidUri('当前 VLESS 出站仅支持 encryption=none。');
            $security = $query['security'] ?? '';
            if (!in_array($security, ['tls', 'reality'], true)) $this->invalidUri('VLESS 出站须显式使用 security=tls 或 security=reality。');
            $settings['uuid'] = rawurldecode($parts['user']);
            if (isset($query['flow'])) $settings['flow'] = $query['flow'];
            if ($security === 'reality') {
                $tls['reality'] = ['enabled' => true, 'public_key' => $query['pbk'] ?? '', 'short_id' => $query['sid'] ?? ''];
            } elseif (isset($query['pbk']) || isset($query['sid'])) {
                $this->invalidUri('REALITY 参数只能与 security=reality 一起使用。');
            }
        } elseif ($type === 'hysteria2') {
            $settings['password'] = rawurldecode($parts['user']) . (isset($parts['pass']) ? ':' . rawurldecode($parts['pass']) : '');
            if (isset($query['obfs']) || isset($query['obfs-password'])) {
                $settings['obfs'] = ['type' => $query['obfs'] ?? '', 'password' => $query['obfs-password'] ?? ''];
            }
            foreach (['upmbps' => 'up_mbps', 'downmbps' => 'down_mbps'] as $key => $field) {
                if (isset($query[$key])) $settings[$field] = $query[$key];
            }
        } elseif ($type === 'trojan') {
            if (!in_array($query['type'] ?? 'tcp', ['tcp', 'raw'], true) || ($query['security'] ?? 'tls') !== 'tls') {
                $this->invalidUri('Trojan 出站仅支持 TCP + TLS。');
            }
            $settings['password'] = rawurldecode($parts['user']) . (isset($parts['pass']) ? ':' . rawurldecode($parts['pass']) : '');
        } elseif ($type === 'socks') {
            if (!isset($parts['port'])) $this->invalidUri('SOCKS5 链接必须包含端口。');
            $settings['version'] = '5';
            if (isset($parts['user'])) {
                [$settings['username'], $settings['password']] = $this->uriCredentials($parts);
            }
        } elseif ($type === 'shadowsocks') {
            if (!isset($parts['port'])) $this->invalidUri('Shadowsocks 链接必须包含端口。');
            [$settings['method'], $settings['password']] = $this->uriCredentials($parts);
        }
        if (in_array($type, ['vless', 'hysteria2', 'trojan'], true)) $settings['tls'] = $tls;
        $settings = $this->validateSettings($type, $settings);
        return ['name' => $this->uriName(rawurldecode($parts['fragment'] ?? ''), $server), 'type' => $type, 'settings' => $settings];
    }

    private function importVmess(string $encoded): array
    {
        [$encoded, $fragment] = array_pad(explode('#', $encoded, 2), 2, null);
        try { $data = json_decode($this->decodeBase64($encoded), true, 16, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { $this->invalidUri('VMess 链接须为 Base64 编码的标准 JSON 配置。'); }
        $allowed = ['v', 'ps', 'add', 'port', 'id', 'aid', 'scy', 'net', 'type', 'host', 'path', 'tls', 'sni', 'alpn', 'fp', 'allowInsecure', 'insecure'];
        if (!is_array($data) || array_is_list($data) || array_diff(array_keys($data), $allowed)) {
            $this->invalidUri('VMess JSON 格式无效或包含不支持的字段。');
        }
        foreach ($data as $value) {
            if (!is_scalar($value) && $value !== null) $this->invalidUri('VMess JSON 字段须为字符串、数字或布尔值。');
        }
        if (isset($data['v']) && !in_array($data['v'], ['2', 2], true)) $this->invalidUri('仅支持版本 2 的 VMess 分享链接。');
        if (!in_array($data['net'] ?? 'tcp', ['tcp', 'raw', ''], true)
            || !in_array($data['type'] ?? 'none', ['none', ''], true)
            || !empty($data['host']) || !empty($data['path'])) {
            $this->invalidUri('VMess 出站仅支持原生 TCP，暂不支持 WebSocket、gRPC 或 HTTP 伪装。');
        }
        if (!in_array($data['tls'] ?? '', ['', 'none', 'tls'], true)) $this->invalidUri('VMess TLS 选项仅支持 tls 或留空。');
        $settings = [
            'server' => $data['add'] ?? '', 'server_port' => $data['port'] ?? null,
            'uuid' => $data['id'] ?? '', 'security' => $data['scy'] ?? 'auto', 'alter_id' => $data['aid'] ?? 0,
        ];
        $tlsFields = array_intersect_key($data, array_flip(['sni', 'alpn', 'fp', 'insecure', 'allowInsecure']));
        if (($data['tls'] ?? '') === 'tls') {
            // Empty optional strings are common in v2rayN exports.
            $settings['tls'] = $this->uriTls(array_filter($tlsFields, fn ($v) => $v !== '' && $v !== null));
        } elseif (array_filter($tlsFields, fn ($v) => $v !== '' && $v !== null && $v !== false && $v !== '0' && $v !== 0)) {
            $this->invalidUri('VMess 未启用 TLS，但链接中仍有 TLS 参数。');
        }
        $settings = $this->validateSettings('vmess', $settings);
        $name = $fragment !== null ? rawurldecode($fragment) : ($data['ps'] ?? '');
        if (!is_string($name)) $this->invalidUri('VMess 节点名称须为字符串。');
        return ['name' => $this->uriName($name, $settings['server']), 'type' => 'vmess', 'settings' => $settings];
    }

    private function uriTls(array $query): array
    {
        $tls = ['enabled' => true, 'insecure' => false];
        $snis = array_intersect_key($query, array_flip(['sni', 'serverName', 'peer']));
        if (count($snis) > 1) $this->invalidUri('SNI 参数重复，请仅保留 sni。');
        if ($snis) $tls['server_name'] = reset($snis);
        if (isset($query['insecure'], $query['allowInsecure'])) $this->invalidUri('证书验证参数重复。');
        if (isset($query['insecure']) || isset($query['allowInsecure'])) $tls['insecure'] = $this->uriBoolean($query['insecure'] ?? $query['allowInsecure']);
        if (isset($query['alpn'])) {
            if (!is_string($query['alpn'])) $this->invalidUri('ALPN 须为逗号分隔的字符串。');
            $tls['alpn'] = explode(',', $query['alpn']);
        }
        if (isset($query['fp'])) $tls['utls'] = ['enabled' => true, 'fingerprint' => $query['fp']];
        return $tls;
    }

    private function uriCredentials(array $parts): array
    {
        if (isset($parts['pass'])) return [rawurldecode($parts['user']), rawurldecode($parts['pass'])];
        $credentials = explode(':', $this->decodeBase64(rawurldecode($parts['user'] ?? '')), 2);
        if (count($credentials) !== 2) $this->invalidUri('身份凭据须为 username:password 或其 Base64 编码。');
        return $credentials;
    }

    private function decodeBase64(string $value): string
    {
        if ($value === '' || !preg_match('/^[A-Za-z0-9+\/_-]+={0,2}$/D', $value)) $this->invalidUri('分享链接包含无效的 Base64 编码。');
        $unpadded = rtrim(strtr($value, '-_', '+/'), '=');
        $decoded = base64_decode($unpadded . str_repeat('=', (4 - strlen($unpadded) % 4) % 4), true);
        if ($decoded === false || rtrim(base64_encode($decoded), '=') !== $unpadded) $this->invalidUri('分享链接包含无效的 Base64 编码。');
        return $decoded;
    }

    private function uriName(string $name, string $server): string
    {
        $name = trim($name);
        if (preg_match('/[\x00-\x1f\x7f]/', $name) || mb_strlen($name) > 255) $this->invalidUri('分享链接中的名称过长或包含控制字符。');
        return $name !== '' ? $name : $server;
    }

    /** Omitted update fields retain their saved values when checking conflicts. */
    public function assertSelection(array $changes, ?Server $current = null): void
    {
        $outboundId = array_key_exists('outbound_id', $changes) ? $changes['outbound_id'] : $current?->outbound_id;
        if (!$outboundId) return;
        foreach (['custom_outbounds', 'custom_routes', 'route_ids'] as $key) {
            $value = array_key_exists($key, $changes) ? $changes[$key] : $current?->{$key};
            if (!empty($value)) {
                throw ValidationException::withMessages(['outbound_id' => '指定出站不能与高级 custom_outbounds、custom_routes 或 route_ids 同时使用，请先清除冲突配置。']);
            }
        }
    }

    public function appendNodeConfig(Server $node, array $config): array
    {
        if (!$node->outbound_id) return $config;
        $this->assertSelection([], $node);
        $outbound = ServerOutbound::find($node->outbound_id);
        if (!$outbound) throw new \RuntimeException('指定出站不存在，拒绝将节点回退为直连。');
        $settings = $this->validateSettings($outbound->type, $outbound->settings);
        $config['custom_outbounds'] = [['tag' => $outbound->tag(), 'protocol' => $outbound->type, 'settings' => $settings]];
        $config['default_outbound'] = $outbound->tag();
        return $config;
    }

    private function query(string $raw): array
    {
        if ($raw === '') return [];
        $query = [];
        foreach (explode('&', $raw) as $pair) {
            if ($pair === '' || !str_contains($pair, '=')) $this->invalidUri('分享链接参数格式无效。');
            [$key, $value] = explode('=', $pair, 2);
            $key = rawurldecode($key);
            if ($key === '' || array_key_exists($key, $query)) $this->invalidUri('分享链接包含空参数名或重复参数。');
            $query[$key] = rawurldecode($value);
        }
        return $query;
    }

    private function uriBoolean(mixed $value): bool
    {
        if (is_bool($value)) return $value;
        if (!is_string($value) && !is_int($value)) $this->invalidUri('证书验证选项须为 0、1、true 或 false。');
        $value = (string) $value;
        if (!in_array(strtolower($value), ['0', '1', 'true', 'false'], true)) $this->invalidUri('证书验证选项须为 0、1、true 或 false。');
        return in_array(strtolower($value), ['1', 'true'], true);
    }

    private function validHost(string $host): bool
    {
        return filter_var($host, FILTER_VALIDATE_IP) !== false
            || (bool) preg_match('/^(?=.{1,253}$)[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*\.?$/D', $host);
    }

    private function invalidUri(string $message): never
    {
        throw ValidationException::withMessages(['uri' => $message]);
    }
}
