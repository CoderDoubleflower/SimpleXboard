import { h, field, input, select, textarea, notice } from '../ui.js';
import { CERTIFICATE_MODES, DNS_PROVIDERS, certificateMode, dnsProvider } from '../certificates.js';

const controlNames = {
  cert_config: 'cert_mode',
  'cert_config.cert_mode': 'cert_mode',
  'cert_config.mode': 'cert_mode',
  'cert_config.cert_file': 'cert_file',
  'cert_config.key_file': 'cert_key_file',
  'cert_config.cert_content': 'cert_content',
  'cert_config.key_content': 'cert_key_content',
  'cert_config.domain': 'cert_domain',
  'cert_config.email': 'cert_email',
  'cert_config.http_port': 'cert_http_port',
  'cert_config.dns_provider': 'cert_dns_provider',
  'cert_config.dns_env': 'cert_dns_env',
};

export function certificateFields(config = null) {
  const saved = config && typeof config === 'object' && !Array.isArray(config) ? config : {};
  const initial = certificateMode(saved);
  const options = [{ value: '', label: '请选择服务端证书来源' }, ...CERTIFICATE_MODES];
  if (initial && !options.some(item => item.value === initial)) options.push({ value: initial, label: `${initial}（不可用于 Hysteria，请重新选择）` });
  const source = select('cert_mode', options, initial, { required: true });
  const certFile = input('cert_file', saved.cert_file ?? '', { required: true, spellcheck: false, placeholder: '/etc/xboard-node/certs/fullchain.pem' });
  const keyFile = input('cert_key_file', saved.key_file ?? '', { required: true, spellcheck: false, placeholder: '/etc/xboard-node/certs/privkey.pem' });
  const certContent = textarea('cert_content', saved.cert_content ?? '', { required: true, rows: 5, spellcheck: false, autocomplete: 'off', class: 'input mono code-input', placeholder: '-----BEGIN CERTIFICATE-----\n…\n-----END CERTIFICATE-----' });
  const keyContent = textarea('cert_key_content', saved.key_content ?? '', { required: true, rows: 5, spellcheck: false, autocomplete: 'off', class: 'input mono code-input', placeholder: '-----BEGIN PRIVATE KEY-----\n…\n-----END PRIVATE KEY-----' });
  const domain = input('cert_domain', saved.domain ?? '', { placeholder: 'hy.example.com', spellcheck: false });
  const email = input('cert_email', saved.email ?? '', { type: 'email', placeholder: 'admin@example.com' });
  const httpPort = input('cert_http_port', saved.http_port ?? '', { type: 'number', min: 0, max: 65535, step: 1, placeholder: '80' });
  const providerName = dnsProvider(saved.dns_provider)?.value ?? saved.dns_provider ?? '';
  const providerOptions = [{ value: '', label: '请选择 DNS 服务商' }, ...DNS_PROVIDERS];
  if (providerName && !providerOptions.some(item => item.value === providerName)) providerOptions.push({ value: providerName, label: `${providerName}（不支持，请重新选择）` });
  const provider = select('cert_dns_provider', providerOptions, providerName, { required: true });
  const env = textarea('cert_dns_env', JSON.stringify(saved.dns_env ?? {}, null, 2), { rows: 5, spellcheck: false, autocomplete: 'off', class: 'input mono code-input' });
  const credentialHint = h('p', { class: 'field-hint', id: 'certificate-dns-hint' });
  env.setAttribute('aria-describedby', 'certificate-dns-hint');
  const status = h('div');

  const files = h('div', { class: 'form-grid' },
    field('证书文件路径', certFile, { required: true, hint: '填写 VPS 上的 PEM 证书或完整证书链路径。' }),
    field('私钥文件路径', keyFile, { required: true, hint: '填写同一 VPS 上与证书配对的私钥路径。节点运行用户必须可读取这两个文件。' }));
  const content = h('div', { class: 'form-grid' },
    field('PEM 证书内容', certContent, { required: true, wide: true, hint: '可包含叶证书及中间证书链。面板会检查证书格式及其与私钥是否配对。' }),
    field('PEM 私钥内容', keyContent, { required: true, wide: true, hint: '填写与证书配对的未加密私钥。' }));
  const domainField = field('证书域名', domain, { required: true, hint: '证书中的域名应覆盖客户端使用的 TLS SNI；它与连接地址可以不同。' });
  const emailField = field('ACME 联系邮箱（可选）', email);
  const domainGroup = h('div', { class: 'form-grid' }, domainField, emailField);
  const portGroup = field('HTTP 验证监听端口（可选）', httpPort, { hint: '留空或 0 使用 80。公网 TCP 80 必须能到达此端口；自定义本地端口不会改变 ACME 的公网验证端口。' });
  const dns = h('div', { class: 'form-grid' }, field('DNS 服务商', provider, { required: true, wide: true }),
    field('DNS API 凭据（JSON）', env, { wide: true }), h('div', { class: 'field-wide' }, credentialHint));
  const element = h('div', { class: 'stack-lg' },
    h('p', { class: 'field-hint' }, 'Hysteria 必须有服务端证书和私钥。SNI 用于客户端识别服务端；“跳过证书验证”只改变客户端校验行为，不会生成证书。'),
    field('服务端证书来源', source, { required: true }), status, files, content, domainGroup, portGroup, dns);
  const controls = [source, certFile, keyFile, certContent, keyContent, domain, email, httpPort, provider, env];
  const errors = new Map();

  function clearError(control) {
    errors.get(control.name)?.remove();
    errors.delete(control.name);
    control.removeAttribute('aria-invalid');
    const ids = (control.getAttribute('aria-describedby') || '').split(' ').filter(id => id && id !== `error-${control.name}`);
    if (ids.length) control.setAttribute('aria-describedby', ids.join(' ')); else control.removeAttribute('aria-describedby');
    control.setCustomValidity('');
  }

  function showError(control, message, focus = false) {
    clearError(control);
    const error = h('p', { class: 'field-error', id: `error-${control.name}`, role: 'alert' }, message);
    errors.set(control.name, error);
    control.closest('.field').append(error);
    control.setAttribute('aria-invalid', 'true');
    control.setAttribute('aria-describedby', [control.getAttribute('aria-describedby'), error.id].filter(Boolean).join(' '));
    if (focus) setTimeout(() => { if (control.isConnected) { control.focus(); control.scrollIntoView({ block: 'center' }); } }, 0);
  }

  for (const control of controls) {
    control.addEventListener('input', () => clearError(control));
    control.addEventListener('change', () => clearError(control));
    control.addEventListener('invalid', () => {
      const label = control.closest('.field')?.querySelector('label')?.textContent.replace(/\s*\*$/, '') || '此字段';
      showError(control, control.validity.valueMissing ? `请填写${label}。` : control.validationMessage);
    });
  }

  function describeCredentials() {
    const selected = dnsProvider(provider.value);
    const example = Object.fromEntries((selected?.required ?? []).map(keys => [keys[0], '填写实际值']));
    env.placeholder = JSON.stringify(example, null, 2);
    const requirements = selected?.required.map(keys => keys[0]).join('、');
    credentialHint.textContent = selected?.value === 'route53'
      ? '可填写 AWS_ACCESS_KEY_ID、AWS_SECRET_ACCESS_KEY 等；已在 VPS 配置 IAM 角色或 AWS 环境身份时可保留 {}。'
      : selected ? `必需：${requirements}。${selected.alternative ? '也可使用 GODADDY_API_KEY 与 GODADDY_API_SECRET。' : ''}${['azure', 'googleclouddns'].includes(selected.value) ? '认证信息可使用 VPS 已配置的云环境身份。' : '将提示中的键名与实际凭据写成 JSON，所有值均需使用双引号。'}`
        : '选择 DNS 服务商后，按提示填写所需凭据。';
  }

  function sync() {
    for (const control of controls) clearError(control);
    const mode = source.value;
    const groups = [[files, ['file']], [content, ['content']], [domainGroup, ['http', 'dns', 'self']], [portGroup, ['http']], [dns, ['dns']]];
    for (const [group, modes] of groups) {
      const active = modes.includes(mode);
      group.hidden = !active;
      for (const control of group.querySelectorAll('input,select,textarea')) control.disabled = !active;
    }
    domain.required = mode === 'http' || mode === 'dns';
    domainField.querySelector('.required').hidden = !domain.required;
    emailField.hidden = mode === 'self';
    email.disabled = !['http', 'dns'].includes(mode);
    const messages = {
      file: ['文件由节点在启动时读取；路径是否存在、权限和证书加载结果会由节点检查。', 'info'],
      content: ['证书和私钥会随节点配置下发，用于启动服务端 TLS。', 'info'],
      http: ['由节点自动签发并续期。请先将证书域名解析到此 VPS，并放行公网 TCP 80。签发成功后核心才能启动。', 'info'],
      dns: ['由节点通过 DNS API 自动签发并续期，无需开放 HTTP 验证端口。凭据需要对应域名的 DNS 修改权限。', 'info'],
      self: ['由节点生成自签名证书，域名留空时使用 localhost。客户端需信任该证书，或主动开启上方“跳过证书验证”。', 'warning'],
    };
    const message = messages[mode];
    status.replaceChildren(...(message ? [notice(...message)] : []));
  }
  source.addEventListener('change', sync);
  provider.addEventListener('change', describeCredentials);
  describeCredentials();
  sync();

  return {
    element,
    reportError(error) {
      const entries = error.field ? [[error.field, error.message]] : Object.entries(error.errors ?? {});
      for (const [path, messages] of entries) {
        const name = controlNames[path] ?? (path.startsWith('cert_config.dns_env.') ? 'cert_dns_env' : null);
        const control = controls.find(item => item.name === name && !item.disabled);
        if (control) { showError(control, Array.isArray(messages) ? messages[0] : String(messages), true); return true; }
      }
      return false;
    },
  };
}
