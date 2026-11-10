export const CERTIFICATE_MODES = [
  { value: 'file', label: 'VPS 本地证书文件' },
  { value: 'http', label: '自动签发 · HTTP 验证' },
  { value: 'dns', label: '自动签发 · DNS 验证' },
  { value: 'content', label: '粘贴证书与私钥（PEM）' },
  { value: 'self', label: '由节点生成自签名证书' },
];

// Keep the supported names and credential alternatives aligned with
// xboard-node/internal/cert/dnsproviders. Each group requires one nonempty key.
export const DNS_PROVIDERS = [
  { value: 'cloudflare', label: 'Cloudflare', aliases: ['cf'], required: [['CLOUDFLARE_DNS_API_TOKEN', 'CF_API_TOKEN', 'CLOUDFLARE_API_TOKEN']] },
  { value: 'alidns', label: '阿里云 DNS', aliases: ['aliyun'], required: [['ALICLOUD_ACCESS_KEY_ID', 'ALI_ACCESS_KEY_ID', 'ALIYUN_ACCESS_KEY_ID'], ['ALICLOUD_ACCESS_KEY_SECRET', 'ALI_ACCESS_KEY_SECRET', 'ALIYUN_ACCESS_KEY_SECRET']] },
  { value: 'tencentcloud', label: '腾讯云 DNS', aliases: ['tencent'], required: [['TENCENTCLOUD_SECRET_ID', 'TENCENT_SECRET_ID'], ['TENCENTCLOUD_SECRET_KEY', 'TENCENT_SECRET_KEY']] },
  { value: 'route53', label: 'Amazon Route 53', aliases: ['aws'], required: [] },
  { value: 'azure', label: 'Azure DNS', required: [['AZURE_SUBSCRIPTION_ID'], ['AZURE_RESOURCE_GROUP_NAME', 'AZURE_RESOURCE_GROUP']] },
  { value: 'bunny', label: 'Bunny', required: [['BUNNY_ACCESS_KEY', 'BUNNY_API_KEY']] },
  { value: 'desec', label: 'deSEC', required: [['DESEC_TOKEN', 'DESEC_API_TOKEN']] },
  { value: 'digitalocean', label: 'DigitalOcean', aliases: ['do'], required: [['DO_AUTH_TOKEN', 'DIGITALOCEAN_TOKEN', 'DIGITALOCEAN_API_TOKEN']] },
  { value: 'duckdns', label: 'Duck DNS', required: [['DUCKDNS_TOKEN', 'DUCKDNS_API_TOKEN']] },
  { value: 'gandi', label: 'Gandi', required: [['GANDI_BEARER_TOKEN', 'GANDI_API_TOKEN', 'GANDI_PERSONAL_ACCESS_TOKEN']] },
  { value: 'godaddy', label: 'GoDaddy', required: [['GODADDY_API_TOKEN']], alternative: [['GODADDY_API_KEY'], ['GODADDY_API_SECRET']] },
  { value: 'googleclouddns', label: 'Google Cloud DNS', aliases: ['gcp', 'gcloud'], required: [['GCE_PROJECT', 'GOOGLE_PROJECT_ID', 'GCP_PROJECT']] },
  { value: 'hetzner', label: 'Hetzner', required: [['HETZNER_API_TOKEN', 'HETZNER_DNS_API_TOKEN']] },
  { value: 'huaweicloud', label: '华为云 DNS', aliases: ['huawei'], required: [['HUAWEICLOUD_ACCESS_KEY_ID', 'HUAWEI_ACCESS_KEY_ID'], ['HUAWEICLOUD_SECRET_ACCESS_KEY', 'HUAWEI_SECRET_ACCESS_KEY']] },
  { value: 'linode', label: 'Linode', required: [['LINODE_TOKEN', 'LINODE_API_TOKEN']] },
  { value: 'namecheap', label: 'Namecheap', required: [['NAMECHEAP_API_KEY'], ['NAMECHEAP_API_USER', 'NAMECHEAP_USER']] },
  { value: 'namesilo', label: 'NameSilo', required: [['NAMESILO_API_TOKEN']] },
  { value: 'netlify', label: 'Netlify', required: [['NETLIFY_PERSONAL_ACCESS_TOKEN', 'NETLIFY_AUTH_TOKEN', 'NETLIFY_API_TOKEN']] },
  { value: 'ovh', label: 'OVH', required: [['OVH_APPLICATION_KEY'], ['OVH_APPLICATION_SECRET'], ['OVH_CONSUMER_KEY']] },
  { value: 'porkbun', label: 'Porkbun', required: [['PORKBUN_API_KEY'], ['PORKBUN_API_SECRET_KEY', 'PORKBUN_SECRET_API_KEY']] },
  { value: 'vultr', label: 'Vultr', required: [['VULTR_API_TOKEN', 'VULTR_API_KEY']] },
];

const knownFields = ['cert_mode', 'mode', 'cert_file', 'key_file', 'cert_content', 'key_content', 'domain', 'email', 'http_port', 'dns_provider', 'dns_env'];
const object = value => Boolean(value && typeof value === 'object' && !Array.isArray(value));
const nonempty = value => typeof value === 'string' && value.trim() !== '';

function invalid(field, message) {
  const error = new Error(message);
  error.field = `cert_config${field ? '.' + field : ''}`;
  throw error;
}

export function certificateMode(config) {
  if (!object(config)) return '';
  for (const key of ['cert_mode', 'mode']) {
    if (nonempty(config[key])) return config[key].trim().toLowerCase();
  }
  if (nonempty(config.cert_content) && nonempty(config.key_content)) return 'content';
  if (nonempty(config.cert_file) && nonempty(config.key_file)) return 'file';
  return '';
}

export function dnsProvider(value) {
  const name = typeof value === 'string' ? value.trim().toLowerCase() : '';
  return DNS_PROVIDERS.find(provider => provider.value === name || provider.aliases?.includes(name));
}

function requiredText(config, key, label) {
  if (!nonempty(config[key]) || config[key].includes('\0')) invalid(key, `请填写${label}。`);
  return config[key].trim();
}

export function validateCertificate(config) {
  if (!object(config)) invalid('', 'Hysteria 必须配置服务端 TLS 证书，请选择证书来源。');
  for (const key of ['cert_mode', 'mode']) {
    if (config[key] !== undefined && config[key] !== null && typeof config[key] !== 'string') invalid('cert_mode', '服务端证书来源格式无效。');
  }
  if (nonempty(config.cert_mode) && nonempty(config.mode) && config.cert_mode.trim().toLowerCase() !== config.mode.trim().toLowerCase()) {
    invalid('cert_mode', 'cert_mode 与 mode 冲突，请只保留一个一致的证书来源。');
  }
  for (const key of ['domain', 'email', 'cert_file', 'key_file', 'cert_content', 'key_content', 'dns_provider']) {
    if (config[key] !== undefined && config[key] !== null && typeof config[key] !== 'string') invalid(key, '证书配置字段必须是字符串。');
  }
  if (config.http_port !== undefined && config.http_port !== null && config.http_port !== '') {
    if (!Number.isInteger(config.http_port) || config.http_port < 0 || config.http_port > 65535) invalid('http_port', 'HTTP 验证监听端口须为 1–65535，留空或 0 使用 80。');
  }
  if (config.dns_env !== undefined && config.dns_env !== null && (!object(config.dns_env) || Object.values(config.dns_env).some(value => typeof value !== 'string'))) {
    invalid('dns_env', 'DNS API 凭据须为 JSON 对象，且每个值都必须是字符串。');
  }
  const mode = certificateMode(config);
  if (!CERTIFICATE_MODES.some(item => item.value === mode)) invalid('cert_mode', 'Hysteria 必须选择有效的服务端证书来源，SNI 或跳过证书验证不能代替证书。');
  const normalized = { ...config, cert_mode: mode };
  delete normalized.mode;
  if (mode === 'file') {
    normalized.cert_file = requiredText(config, 'cert_file', 'VPS 上的证书文件路径');
    normalized.key_file = requiredText(config, 'key_file', 'VPS 上的私钥文件路径');
  } else if (mode === 'content') {
    requiredText(config, 'cert_content', 'PEM 证书内容');
    requiredText(config, 'key_content', 'PEM 私钥内容');
    if (!/-----BEGIN CERTIFICATE-----[\s\S]+-----END CERTIFICATE-----/.test(config.cert_content)) invalid('cert_content', '证书内容须为 PEM 格式，包含 BEGIN CERTIFICATE 和 END CERTIFICATE。');
    if (!/-----BEGIN ((?:RSA |EC )?PRIVATE KEY)-----[\s\S]+-----END \1-----/.test(config.key_content)) invalid('key_content', '私钥内容须为未加密的 PEM 私钥，包含 BEGIN PRIVATE KEY、RSA PRIVATE KEY 或 EC PRIVATE KEY。');
  }
  if (mode === 'http' || mode === 'dns') {
    normalized.domain = requiredText(config, 'domain', '要签发证书的域名');
    if (/[\s/:]/.test(normalized.domain)) invalid('domain', '证书域名只能填写域名，不能包含协议、端口或路径。');
    if (mode === 'http' && normalized.domain.includes('*')) invalid('domain', 'HTTP 验证不支持通配符证书，请改用 DNS 验证。');
    if (config.email !== undefined && config.email !== null && config.email !== '') {
      if (typeof config.email !== 'string' || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(config.email.trim())) invalid('email', '请填写有效的 ACME 联系邮箱，或留空。');
      normalized.email = config.email.trim();
    }
  }
  if (mode === 'dns') {
    const provider = dnsProvider(config.dns_provider);
    if (!provider) invalid('dns_provider', '请选择节点支持的 DNS 服务商。');
    const env = config.dns_env ?? {};
    if (!object(env) || Object.values(env).some(value => typeof value !== 'string')) invalid('dns_env', 'DNS API 凭据须为 JSON 对象，且每个值都必须是字符串。');
    const satisfies = groups => groups.every(keys => keys.some(key => nonempty(env[key])));
    if (!satisfies(provider.required) && !(provider.alternative && satisfies(provider.alternative))) {
      invalid('dns_env', `请填写 ${provider.label} 所需凭据：${provider.required.map(keys => keys[0]).join('、')}${provider.alternative ? '，或 ' + provider.alternative.map(keys => keys[0]).join(' + ') : ''}。`);
    }
    normalized.dns_provider = provider.value;
    normalized.dns_env = env;
  }
  return normalized;
}

export function certificateFromForm(values, current = null) {
  const mode = String(values.cert_mode ?? '').trim().toLowerCase();
  const config = object(current) ? { ...current } : {};
  // Preserve extensions, but never keep stale credentials from another source.
  for (const key of knownFields) delete config[key];
  config.cert_mode = mode;
  if (mode === 'file') {
    config.cert_file = values.cert_file ?? '';
    config.key_file = values.cert_key_file ?? '';
  } else if (mode === 'content') {
    config.cert_content = values.cert_content ?? '';
    config.key_content = values.cert_key_content ?? '';
  } else if (['http', 'dns', 'self'].includes(mode)) {
    if (values.cert_domain) config.domain = String(values.cert_domain).trim();
    if (mode !== 'self' && values.cert_email) config.email = String(values.cert_email).trim();
    if (mode === 'http' && values.cert_http_port !== '' && values.cert_http_port !== undefined) config.http_port = Number(values.cert_http_port);
    if (mode === 'dns') {
      config.dns_provider = values.cert_dns_provider ?? '';
      try { config.dns_env = JSON.parse(values.cert_dns_env || '{}'); }
      catch { invalid('dns_env', 'DNS API 凭据 JSON 格式无效，请检查引号、逗号和括号。'); }
      if (!object(config.dns_env)) invalid('dns_env', 'DNS API 凭据须为 JSON 对象。');
    }
  }
  return validateCertificate(config);
}
