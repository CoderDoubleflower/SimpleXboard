const ROUTING_FIELDS = ['route_ids', 'custom_routes', 'custom_outbounds'];
export const OUTBOUND_TYPES = [
  { value: 'vless', label: 'VLESS + TLS / REALITY' },
  { value: 'hysteria2', label: 'Hysteria2' },
  { value: 'socks', label: 'SOCKS5' },
  { value: 'shadowsocks', label: 'Shadowsocks' },
  { value: 'trojan', label: 'Trojan' },
  { value: 'vmess', label: 'VMess' },
];
export const SHADOWSOCKS_METHODS = ['aes-128-gcm', 'aes-256-gcm', 'chacha20-ietf-poly1305', '2022-blake3-aes-128-gcm', '2022-blake3-aes-256-gcm', '2022-blake3-chacha20-poly1305'];
export const UTLS_FINGERPRINTS = ['chrome', 'firefox', 'edge', 'safari', '360', 'qq', 'ios', 'android', 'random', 'randomized'];

export function hasCustomRouting(node = {}) {
  return ROUTING_FIELDS.some(key => {
    const value = node?.[key];
    if (Array.isArray(value)) return value.length > 0;
    if (value && typeof value === 'object') return Object.keys(value).length > 0;
    return value !== undefined && value !== null && value !== '' && value !== false;
  });
}

export function outboundProtocolName(outbound) {
  return ({ vless: 'VLESS', hysteria2: 'Hysteria2', socks: 'SOCKS5', shadowsocks: 'Shadowsocks', trojan: 'Trojan', vmess: 'VMess' })[outbound?.type] ?? String(outbound?.type ?? '未知协议');
}

export function outboundAddress(outbound) {
  const server = String(outbound?.server ?? outbound?.settings?.server ?? '');
  const port = outbound?.server_port ?? outbound?.settings?.server_port;
  const host = server.includes(':') && !server.startsWith('[') ? `[${server}]` : server;
  return port ? `${host}:${port}` : host;
}

export function nodeOutboundLabel(node, outbounds = []) {
  if (node?.outbound_id != null) {
    return outbounds.find(item => Number(item.id) === Number(node.outbound_id))?.name ?? `出站 #${node.outbound_id}`;
  }
  return hasCustomRouting(node) ? '高级配置管理' : '直连';
}

export function outboundFormValues(outbound = {}) {
  const settings = outbound.settings ?? {};
  const tls = settings.tls ?? {};
  const type = outbound.type ?? 'vless';
  const fresh = !outbound.type;
  return {
    name: outbound.name ?? '', type,
    server: settings.server ?? '', server_port: settings.server_port ?? 443,
    notes: outbound.notes ?? '',
    uuid: settings.uuid ?? '', username: settings.username ?? '', password: settings.password ?? '',
    method: settings.method ?? 'aes-128-gcm',
    vmess_security: settings.security ?? 'auto', alter_id: settings.alter_id ?? 0,
    tls_enabled: Boolean(tls.enabled),
    security: tls.reality?.enabled || (fresh && type === 'vless') ? 'reality' : 'tls',
    server_name: tls.server_name ?? '', tls_insecure: Boolean(tls.insecure),
    alpn: (tls.alpn ?? []).join(', '),
    flow: settings.flow ?? (fresh && type === 'vless' ? 'xtls-rprx-vision' : ''),
    utls_enabled: Boolean(tls.utls?.enabled) || fresh,
    fingerprint: tls.utls?.fingerprint ?? 'chrome',
    public_key: tls.reality?.public_key ?? '', short_id: tls.reality?.short_id ?? '',
    obfs_enabled: Boolean(settings.obfs), obfs_password: settings.obfs?.password ?? '',
    up_mbps: settings.up_mbps ?? '', down_mbps: settings.down_mbps ?? '',
  };
}

function invalid(field, message) {
  throw Object.assign(new Error(message), { field });
}

function positiveInteger(value, field, label, max = Number.MAX_SAFE_INTEGER) {
  const number = Number(value);
  if (!String(value ?? '').trim() || !Number.isSafeInteger(number) || number < 1 || number > max) {
    invalid(field, max === Number.MAX_SAFE_INTEGER ? `${label}须为有效的正整数。` : `${label}须为 1–${max} 的整数。`);
  }
  return number;
}

export function outboundPayload(values, current = null) {
  const name = String(values.name ?? '').trim();
  if (!name) invalid('name', '请填写出站名称。');
  const type = values.type;
  if (!OUTBOUND_TYPES.some(item => item.value === type)) invalid('type', '请选择支持的出站协议。');
  const server = String(values.server ?? '').trim().replace(/^\[([^\]]+)\]$/, '$1');
  if (!server || /[\s/?#@]/.test(server)) invalid('server', '请填写有效的主机名或 IP，不要包含协议前缀或路径。');
  const settings = { server, server_port: positiveInteger(values.server_port, 'server_port', '出站端口', 65535) };
  const tls = { enabled: true, insecure: Boolean(values.tls_insecure) };
  const serverName = String(values.server_name ?? '').trim();
  if (serverName) tls.server_name = serverName;
  const alpn = [...new Set(String(values.alpn ?? '').split(/[\s,]+/).filter(Boolean))];
  if (alpn.length) tls.alpn = alpn;

  if (type === 'vless' || type === 'vmess') {
    const uuid = String(values.uuid ?? '').trim();
    if (!/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/i.test(uuid)) invalid('uuid', '请填写现成节点的有效 UUID。');
    settings.uuid = uuid;
    const reality = type === 'vless' && values.security === 'reality';
    if (type === 'vless') {
      const flow = String(values.flow ?? '').trim();
      if (!['', 'xtls-rprx-vision'].includes(flow)) invalid('flow', 'Flow 仅支持留空或 xtls-rprx-vision。');
      if (flow) settings.flow = flow;
      if (!['reality', 'tls'].includes(values.security)) invalid('security', '请选择 TLS 或 REALITY。');
    } else {
      const security = String(values.vmess_security ?? 'auto');
      if (!['auto', 'aes-128-gcm', 'chacha20-poly1305', 'none', 'zero'].includes(security)) invalid('vmess_security', '请选择支持的 VMess 加密方式。');
      settings.security = security;
      const alterId = Number(values.alter_id ?? 0);
      if (!Number.isSafeInteger(alterId) || alterId < 0 || alterId > 65535) invalid('alter_id', 'Alter ID 须为 0–65535 的整数。');
      settings.alter_id = alterId;
    }
    if (reality || (values.utls_enabled && (type === 'vless' || values.tls_enabled))) {
      const fingerprint = String(values.fingerprint ?? '').trim();
      if (!UTLS_FINGERPRINTS.includes(fingerprint)) invalid('fingerprint', '请选择支持的客户端指纹。');
      tls.utls = { enabled: true, fingerprint };
    }
    if (reality) {
      if (!serverName) invalid('server_name', 'REALITY 出站需要填写现成节点的 SNI。');
      const publicKey = String(values.public_key ?? '').trim();
      if (!/^[A-Za-z0-9_-]{43}$/.test(publicKey)) invalid('public_key', 'REALITY 公钥应为 43 位 Base64URL 字符，请从现成节点复制。');
      const shortId = String(values.short_id ?? '').trim();
      if (!/^(?:[0-9a-f]{2}){0,8}$/i.test(shortId)) invalid('short_id', 'Short ID 须为不超过 16 位的偶数位十六进制字符，也可留空。');
      tls.reality = { enabled: true, public_key: publicKey };
      if (shortId) tls.reality.short_id = shortId;
      tls.insecure = false;
    }
  } else if (type === 'hysteria2' || type === 'trojan' || type === 'shadowsocks') {
    const password = String(values.password ?? '');
    if (!password) invalid('password', `请填写 ${outboundProtocolName({ type })} 出站密码。`);
    settings.password = password;
    if (type === 'shadowsocks') {
      const method = String(values.method ?? '').trim();
      if (!SHADOWSOCKS_METHODS.includes(method)) invalid('method', '请选择支持的 Shadowsocks 加密方式。');
      settings.method = method;
    }
    if (type === 'hysteria2' && values.obfs_enabled) {
      const password = String(values.obfs_password ?? '');
      if (!password) invalid('obfs_password', '启用 Salamander 后请填写混淆密码。');
      settings.obfs = { type: 'salamander', password };
    }
    if (type === 'hysteria2') {
      for (const [key, label] of [['up_mbps', '上行带宽'], ['down_mbps', '下行带宽']]) {
        if (String(values[key] ?? '').trim()) settings[key] = positiveInteger(values[key], key, label, 100000);
      }
    }
  } else if (type === 'socks') {
    settings.version = '5';
    const username = String(values.username ?? '');
    const password = String(values.password ?? '');
    if (Boolean(username) !== Boolean(password)) invalid(username ? 'password' : 'username', 'SOCKS5 认证的用户名与密码须同时填写；无需认证时请都留空。');
    for (const [field, value] of [['username', username], ['password', password]]) {
      if (new TextEncoder().encode(value).length > 255) invalid(field, 'SOCKS5 用户名和密码最多各 255 字节。');
    }
    if (username) { settings.username = username; settings.password = password; }
  }
  if (type === 'trojan' && values.utls_enabled) {
    const fingerprint = String(values.fingerprint ?? '').trim();
    if (!UTLS_FINGERPRINTS.includes(fingerprint)) invalid('fingerprint', '请选择支持的客户端指纹。');
    tls.utls = { enabled: true, fingerprint };
  }
  if (['vless', 'hysteria2', 'trojan'].includes(type) || (type === 'vmess' && values.tls_enabled)) settings.tls = tls;
  return { ...(current?.id ? { id: current.id } : {}), name, type, settings, notes: String(values.notes ?? '').trim() || null };
}
