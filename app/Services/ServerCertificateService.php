<?php

namespace App\Services;

use App\Models\Server;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Validate the certificate source; only the node can inspect files on its VPS. */
class ServerCertificateService
{
    private const MODES = ['file', 'content', 'http', 'dns', 'self'];

    // xboard-node internal/cert/dnsproviders: each group accepts one credential alias.
    private const DNS_CREDENTIALS = [
        'cloudflare' => [['CLOUDFLARE_DNS_API_TOKEN', 'CF_API_TOKEN', 'CLOUDFLARE_API_TOKEN']],
        'alidns' => [['ALICLOUD_ACCESS_KEY_ID', 'ALI_ACCESS_KEY_ID', 'ALIYUN_ACCESS_KEY_ID'], ['ALICLOUD_ACCESS_KEY_SECRET', 'ALI_ACCESS_KEY_SECRET', 'ALIYUN_ACCESS_KEY_SECRET']],
        'tencentcloud' => [['TENCENTCLOUD_SECRET_ID', 'TENCENT_SECRET_ID'], ['TENCENTCLOUD_SECRET_KEY', 'TENCENT_SECRET_KEY']],
        'route53' => [], // The AWS credential chain may resolve credentials on the node.
        'azure' => [['AZURE_SUBSCRIPTION_ID'], ['AZURE_RESOURCE_GROUP_NAME', 'AZURE_RESOURCE_GROUP']],
        'bunny' => [['BUNNY_ACCESS_KEY', 'BUNNY_API_KEY']],
        'desec' => [['DESEC_TOKEN', 'DESEC_API_TOKEN']],
        'digitalocean' => [['DO_AUTH_TOKEN', 'DIGITALOCEAN_TOKEN', 'DIGITALOCEAN_API_TOKEN']],
        'duckdns' => [['DUCKDNS_TOKEN', 'DUCKDNS_API_TOKEN']],
        'gandi' => [['GANDI_BEARER_TOKEN', 'GANDI_API_TOKEN', 'GANDI_PERSONAL_ACCESS_TOKEN']],
        'godaddy' => [['GODADDY_API_TOKEN']],
        'googleclouddns' => [['GCE_PROJECT', 'GOOGLE_PROJECT_ID', 'GCP_PROJECT']],
        'hetzner' => [['HETZNER_API_TOKEN', 'HETZNER_DNS_API_TOKEN']],
        'huaweicloud' => [['HUAWEICLOUD_ACCESS_KEY_ID', 'HUAWEI_ACCESS_KEY_ID'], ['HUAWEICLOUD_SECRET_ACCESS_KEY', 'HUAWEI_SECRET_ACCESS_KEY']],
        'linode' => [['LINODE_TOKEN', 'LINODE_API_TOKEN']],
        'namecheap' => [['NAMECHEAP_API_KEY'], ['NAMECHEAP_API_USER', 'NAMECHEAP_USER']],
        'namesilo' => [['NAMESILO_API_TOKEN']],
        'netlify' => [['NETLIFY_PERSONAL_ACCESS_TOKEN', 'NETLIFY_AUTH_TOKEN', 'NETLIFY_API_TOKEN']],
        'ovh' => [['OVH_APPLICATION_KEY'], ['OVH_APPLICATION_SECRET'], ['OVH_CONSUMER_KEY']],
        'porkbun' => [['PORKBUN_API_KEY'], ['PORKBUN_API_SECRET_KEY', 'PORKBUN_SECRET_API_KEY']],
        'vultr' => [['VULTR_API_TOKEN', 'VULTR_API_KEY']],
    ];

    private const DNS_ALIASES = [
        'cf' => 'cloudflare', 'aliyun' => 'alidns', 'tencent' => 'tencentcloud',
        'aws' => 'route53', 'do' => 'digitalocean', 'gcp' => 'googleclouddns',
        'gcloud' => 'googleclouddns', 'huawei' => 'huaweicloud',
    ];

    public function normalize(mixed $config): ?array
    {
        if ($config === null) return null;
        if (!is_array($config) || ($config !== [] && array_is_list($config))) {
            $this->invalid('', '服务端证书配置必须是 JSON 对象。');
        }
        foreach (['cert_mode', 'mode'] as $key) {
            if (isset($config[$key]) && !is_string($config[$key])) {
                $this->invalid('cert_mode', '服务端证书来源格式无效。');
            }
        }
        $mode = strtolower(trim($config['cert_mode'] ?? ''));
        $alias = strtolower(trim($config['mode'] ?? ''));
        if ($mode !== '' && $alias !== '' && $mode !== $alias) {
            $this->invalid('cert_mode', 'cert_mode 与 mode 冲突，请只保留一个一致的证书来源。');
        }
        $mode = $mode ?: $alias;
        if ($mode === '') {
            if ($this->filled($config['cert_content'] ?? null) && $this->filled($config['key_content'] ?? null)) $mode = 'content';
            elseif ($this->filled($config['cert_file'] ?? null) && $this->filled($config['key_file'] ?? null)) $mode = 'file';
        }
        $config['cert_mode'] = $mode;
        unset($config['mode']);
        foreach (['domain', 'email', 'cert_file', 'key_file', 'dns_provider'] as $key) {
            if (is_string($config[$key] ?? null)) $config[$key] = trim($config[$key]);
        }
        if (is_string($config['dns_provider'] ?? null)) {
            $provider = strtolower($config['dns_provider']);
            $config['dns_provider'] = self::DNS_ALIASES[$provider] ?? $provider;
        }
        // JSON [] is not a Go map[string]string. Omission retains the node defaults.
        if (($config['dns_env'] ?? null) === []) unset($config['dns_env']);
        return $config;
    }

    /** Omitted edit fields retain their saved values; an explicit null clears them. */
    public function validateForNode(array $changes, ?Server $current = null): ?array
    {
        $type = $changes['type'] ?? $current?->type;
        $config = array_key_exists('cert_config', $changes) ? $changes['cert_config'] : $current?->cert_config;
        // Other protocols need their own compatibility audit before enforcing TLS here.
        if ($type !== Server::TYPE_HYSTERIA) return $config;

        $config = $this->normalize($config);
        if ($config === null) {
            $this->invalid('', 'Hysteria 必须配置服务端 TLS 证书；SNI 或跳过证书验证不能代替证书。');
        }
        $mode = $config['cert_mode'];
        $requiredString = ['required', 'string', 'not_regex:/[\x00]/'];
        $rules = [
            'cert_config.cert_mode' => 'required|string|in:' . implode(',', self::MODES),
            'cert_config.domain' => 'nullable|string|max:253',
            'cert_config.email' => 'nullable|string',
            'cert_config.cert_file' => 'nullable|string',
            'cert_config.key_file' => 'nullable|string',
            'cert_config.cert_content' => 'nullable|string',
            'cert_config.key_content' => 'nullable|string',
            'cert_config.dns_provider' => 'nullable|string',
            'cert_config.dns_env' => 'nullable|array',
            'cert_config.dns_env.*' => 'string',
            'cert_config.http_port' => 'nullable|integer|between:0,65535',
        ];
        foreach (match ($mode) {
            'file' => ['cert_file', 'key_file'],
            'content' => ['cert_content', 'key_content'],
            'http', 'dns' => ['domain'],
            default => [],
        } as $field) $rules['cert_config.' . $field] = $requiredString;
        if (in_array($mode, ['http', 'dns'], true)) $rules['cert_config.email'] = 'nullable|string|email:rfc';
        if ($mode === 'dns') $rules['cert_config.dns_provider'] = 'required|string|in:' . implode(',', array_keys(self::DNS_CREDENTIALS));

        $validator = Validator::make(['cert_config' => $config], $rules, [
            'required' => '请填写:attribute。',
            'string' => ':attribute 必须是字符串。',
            'not_regex' => ':attribute 不能包含空字符。',
            'cert_config.cert_mode.required' => 'Hysteria 必须选择服务端证书来源。',
            'cert_config.cert_mode.in' => 'Hysteria 必须选择有效的服务端证书来源，不能使用 none。',
            'cert_config.dns_provider.in' => '请选择节点支持的 DNS 服务商。',
            'cert_config.dns_env.array' => 'DNS API 凭据须为 JSON 对象。',
            'cert_config.email.email' => '请填写有效的 ACME 联系邮箱，或留空。',
            'cert_config.http_port.between' => 'HTTP 验证监听端口须为 1–65535，留空或 0 使用 80。',
        ], [
            'cert_config.cert_mode' => '服务端证书来源', 'cert_config.domain' => '证书域名',
            'cert_config.cert_file' => 'VPS 上的证书文件路径', 'cert_config.key_file' => 'VPS 上的私钥文件路径',
            'cert_config.cert_content' => 'PEM 证书内容', 'cert_config.key_content' => 'PEM 私钥内容',
            'cert_config.dns_provider' => 'DNS 服务商', 'cert_config.dns_env.*' => 'DNS API 凭据值',
        ]);
        $validator->validate();

        if (in_array($mode, ['http', 'dns'], true)) {
            if (preg_match('/[\s\x00\/:]/', $config['domain'])) {
                $this->invalid('domain', '证书域名只能填写域名，不能包含协议、端口或路径。');
            }
            if ($mode === 'http' && str_contains($config['domain'], '*')) {
                $this->invalid('domain', 'HTTP 验证不支持通配符证书，请改用 DNS 验证。');
            }
        }
        if ($mode === 'dns') $this->validateDns($config);
        if ($mode === 'content') $this->validatePem($config);
        if (isset($config['http_port'])) $config['http_port'] = (int) $config['http_port'];
        return $config;
    }

    public function errorsForNode(Server $node): array
    {
        try {
            $this->validateForNode([], $node);
            return [];
        } catch (ValidationException $error) {
            return $error->errors();
        }
    }

    /** Unbound subscription aliases use the existing parent health semantics. */
    public function errorsForHealthSource(Server $node): array
    {
        if ($node->healthSourceId() === (int) $node->id) return $this->errorsForNode($node);
        // Do not attach the parent (including its private certificate) to a client payload.
        $source = Server::find($node->healthSourceId());
        return $this->errorsForNode($source ?? $node);
    }

    private function validateDns(array $config): void
    {
        $env = $config['dns_env'] ?? [];
        if ($env !== [] && array_is_list($env)) $this->invalid('dns_env', 'DNS API 凭据须为 JSON 对象，键为服务商的环境变量名称。');
        $groups = self::DNS_CREDENTIALS[$config['dns_provider']];
        $satisfies = fn (array $required) => collect($required)->every(fn ($aliases) => collect($aliases)->contains(fn ($key) => $this->filled($env[$key] ?? null)));
        if ($satisfies($groups)) return;
        if ($config['dns_provider'] === 'godaddy' && $satisfies([['GODADDY_API_KEY'], ['GODADDY_API_SECRET']])) return;
        $missing = array_map(fn ($aliases) => implode(' / ', $aliases), $groups);
        $alternative = $config['dns_provider'] === 'godaddy' ? '，或 GODADDY_API_KEY + GODADDY_API_SECRET' : '';
        $this->invalid('dns_env', '请填写 DNS 服务商所需凭据：' . implode('、', $missing) . $alternative . '。');
    }

    private function validatePem(array $config): void
    {
        // Pass PEM content only: never let OpenSSL treat admin input as a panel file URI.
        if (!preg_match('/\A\s*-----BEGIN CERTIFICATE-----/', $config['cert_content'])) {
            $this->invalid('cert_content', '证书内容必须是 PEM 格式。');
        }
        $cert = @openssl_x509_read($config['cert_content']);
        if ($cert === false) $this->invalid('cert_content', '无法加载 PEM 证书，请检查证书内容。');
        if (!preg_match('/\A\s*-----BEGIN (?:RSA |EC )?PRIVATE KEY-----/', $config['key_content'])) {
            $this->invalid('key_content', '私钥内容必须是未加密的 PEM 私钥。');
        }
        $key = @openssl_pkey_get_private($config['key_content']);
        if ($key === false) $this->invalid('key_content', '无法加载 PEM 私钥，请使用未加密的完整私钥。');
        if (!openssl_x509_check_private_key($cert, $key)) {
            $this->invalid('key_content', '服务端证书与私钥不匹配。');
        }
    }

    private function filled(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages(['cert_config' . ($field === '' ? '' : '.' . $field) => $message]);
    }
}
