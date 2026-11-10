import { hasCustomRouting } from './outbounds.js';
import { certificateFromForm, validateCertificate } from './certificates.js';

const GIB = 1073741824;
export const PATH_PATTERN = '[A-Za-z0-9_\\-]+';

export function optionalNumber(value, label, { integer = true, min = 0 } = {}) {
  if (value === '' || value === null || value === undefined) return null;
  const parsed = Number(value);
  if (!Number.isFinite(parsed) || parsed < min || (integer && !Number.isSafeInteger(parsed))) throw new Error(`${label}格式无效。`);
  return parsed;
}

export function quotaBytes(value) {
  const amount = optionalNumber(value, '流量上限', { integer: false });
  if (amount === null) return null;
  const result = Math.round(amount * GIB);
  if (!Number.isSafeInteger(result)) throw new Error('流量上限过大。');
  return result;
}

export function gibValue(value) { return value === null || value === undefined ? '' : String(Number(value) / GIB); }

export function trafficRate(value) {
  const text = typeof value === 'string' || typeof value === 'number' ? String(value).trim() : '';
  if (!/^(?:\d+(?:\.\d{1,2})?|\.\d{1,2})$/.test(text) || Number(text) > 100) {
    throw new Error('流量倍率须为 0–100，最多保留 2 位小数。');
  }
  return Number(text);
}

export function jsonObject(text, label = 'JSON') {
  let result;
  try { result = JSON.parse(text || '{}'); }
  catch { throw new Error(`${label}格式无效，请检查引号、逗号和括号。`); }
  if (!result || Array.isArray(result) || typeof result !== 'object') throw new Error(`${label}必须是一个对象。`);
  return result;
}

export function memberPayload(values, serverIds = [], id = null) {
  const name = String(values.name ?? '').trim();
  const email = String(values.email ?? '').trim();
  if (!name && !email) throw new Error('请填写成员名称或邮箱。');
  let expiredAt = null;
  if (values.expired_at) {
    expiredAt = Math.floor(new Date(values.expired_at).getTime() / 1000);
    if (!Number.isFinite(expiredAt) || expiredAt < 1) throw new Error('到期时间格式无效。');
  }
  return {
    ...(id ? { id } : {}), name: name || null, email: email || null,
    transfer_enable: quotaBytes(values.quota), expired_at: expiredAt,
    speed_limit: optionalNumber(values.speed_limit, '速度限制'),
    device_limit: optionalNumber(values.device_limit, '设备限制'),
    access_mode: values.access_mode === 'restricted' ? 'restricted' : 'all',
    server_ids: values.access_mode === 'restricted' ? [...new Set(serverIds.map(Number))] : [],
    remarks: String(values.remarks ?? '').trim() || null,
  };
}

export function nodePayload(values, current = null) {
  const type = values.type || current?.type || 'vless';
  const protocol = jsonObject(values.protocol_json, '协议参数 JSON');
  if (type === 'vless') {
    protocol.tls = Number(values.vless_tls);
    protocol.network = String(values.network || 'tcp');
    protocol.flow = values.flow || null;
    if (protocol.tls === 2) {
      protocol.reality_settings = {
        ...protocol.reality_settings,
        server_name: values.reality_server_name || null,
        server_port: optionalNumber(values.reality_server_port, 'REALITY 目标端口', { min: 1 }),
        public_key: values.reality_public_key || null,
        private_key: values.reality_private_key || null,
        short_id: values.reality_short_id || '',
        allow_insecure: false,
      };
      protocol.utls = { ...protocol.utls, enabled: true, fingerprint: values.utls_fingerprint || 'chrome' };
    } else if (protocol.tls === 1) {
      protocol.tls_settings = { ...protocol.tls_settings, server_name: values.tls_server_name || null, allow_insecure: Boolean(values.tls_insecure) };
    }
  } else if (type === 'hysteria') {
    protocol.version = Number(values.hy_version || 2);
    protocol.tls = { ...protocol.tls, server_name: values.tls_server_name || null, allow_insecure: Boolean(values.tls_insecure) };
    protocol.obfs = { ...protocol.obfs, open: Boolean(values.hy_obfs), type: 'salamander', password: values.hy_obfs_password || null };
    protocol.bandwidth = { ...protocol.bandwidth, up: optionalNumber(values.hy_up, '上行带宽'), down: optionalNumber(values.hy_down, '下行带宽') };
    protocol.hop_interval = optionalNumber(values.hy_hop, '跳跃间隔');
  }
  const advanced = jsonObject(values.advanced_json, '节点高级配置 JSON');
  const retained = {};
  for (const key of ['route_ids', 'excludes', 'ips', 'parent_id', 'spectific_key', 'custom_routes', 'custom_outbounds', 'cert_config']) {
    if (Object.hasOwn(advanced, key)) retained[key] = advanced[key];
    else if (current && Object.hasOwn(current, key)) retained[key] = current[key];
  }
  if (type === 'hysteria') {
    if (Object.hasOwn(values, 'cert_mode')) {
      if (Object.hasOwn(advanced, 'cert_config')) {
        const error = new Error('请在“服务端 TLS 证书”中配置证书，并移除高级 JSON 中重复的 cert_config，避免两个来源互相覆盖。');
        error.field = 'advanced_json';
        throw error;
      }
      retained.cert_config = certificateFromForm(values, current?.cert_config);
    } else retained.cert_config = validateCertificate(retained.cert_config);
  }
  const outboundId = optionalNumber(Object.hasOwn(values, 'outbound_id') ? values.outbound_id : current?.outbound_id, '出站节点', { min: 1 });
  if (outboundId !== null && hasCustomRouting(retained)) {
    throw new Error('所选出站与高级路由配置冲突。请先将 route_ids、custom_routes 和 custom_outbounds 清空为 []，或取消选择出站以保留高级配置。');
  }
  return {
    ...retained, ...(current?.id ? { id: current.id } : {}), type,
    name: String(values.name ?? '').trim(), host: String(values.host ?? '').trim(),
    port: String(values.port ?? '').trim(), server_port: optionalNumber(values.server_port, '监听端口', { min: 1 }),
    machine_id: optionalNumber(values.machine_id, 'VPS'),
    outbound_id: outboundId,
    enabled: Boolean(values.enabled), show: Boolean(values.show),
    tags: String(values.tags ?? '').split(',').map(tag => tag.trim()).filter(Boolean),
    transfer_enable: quotaBytes(values.quota),
    rate: trafficRate(values.rate === undefined ? current?.rate ?? 1 : values.rate),
    protocol_settings: protocol,
  };
}
