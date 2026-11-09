import { h, icon, button, iconButton, badge, input, select, field, textarea, checkbox, secretInput, formValues, panel, emptyState, notice, table, toast, runAction, openDialog, confirmAction } from '../ui.js';
import { data } from '../format.js';
import { outboundPayload, outboundFormValues, outboundProtocolName, outboundAddress, OUTBOUND_TYPES, SHADOWSOCKS_METHODS, UTLS_FINGERPRINTS } from '../outbounds.js';

export function outboundsPanel(outbounds, context, reload) {
  const search = input('outbound-search', '', { type: 'search', placeholder: '搜索出站名称、地址或协议', 'aria-label': '搜索出站' });
  const list = h('div');
  const toolbar = h('div', { class: 'toolbar' },
    h('div', { class: 'search-field' }, icon('search'), search),
    h('span', { class: 'toolbar-spacer' }),
    button('导入链接', { icon: 'link', onClick: () => importOutbound(context, reload) }),
    button('添加出站', { tone: 'primary', icon: 'plus', onClick: () => outboundEditor(null, context, reload) }));

  function render() {
    const term = search.value.trim().toLowerCase();
    const filtered = outbounds.filter(item => `${item.name} ${outboundAddress(item)} ${outboundProtocolName(item)} ${item.notes ?? ''}`.toLowerCase().includes(term));
    list.replaceChildren();
    if (!filtered.length) {
      list.append(emptyState(term ? '没有找到匹配出站' : '把现成节点添加为出口', term ? '试试其他名称、主机地址或协议。' : '粘贴单个节点的分享链接，或手动填写连接信息；然后在入口节点中选择它。', null, 'globe'));
      return;
    }
    list.append(table([
      { label: '出站', render: item => h('div', { class: 'row-identity' }, h('strong', {}, item.name), h('span', { class: 'mono muted' }, outboundAddress(item))) },
      { label: '协议', render: item => h('span', { class: 'protocol-tag' }, outboundProtocolName(item)) },
      { label: '使用此出站的入口', render: item => h('div', { class: 'stack-sm' }, badge(`${item.usage_count ?? 0} 个入口`, item.usage_count ? 'success' : 'muted'),
        (item.used_by ?? []).length ? h('small', { class: 'muted outbound-usage', title: item.used_by.map(node => node.name).join('、') }, item.used_by.map(node => node.name).join('、')) : h('small', { class: 'muted' }, '尚未分配给节点')) },
      { label: '备注', render: item => h('span', { class: 'muted outbound-notes' }, item.notes || '—') },
      { label: '操作', className: 'actions-cell', render: item => {
        const remove = iconButton(`删除出站 ${item.name}`, 'trash', () => confirmAction({
          title: '删除出站', description: `确认删除“${item.name}”？这只移除保存的连接信息，不会卸载远端节点。`, label: '删除出站',
          onConfirm: async () => { await context.api.post('server/outbound/drop', { id: item.id }); toast('出站已删除'); await reload(); },
        }), 'danger-text');
        if (item.usage_count > 0) {
          remove.disabled = true;
          remove.title = '请先修改所有使用此出站的入口节点，再删除出站。';
        }
        return h('div', { class: 'row-actions' }, iconButton(`编辑出站 ${item.name}`, 'edit', event => runAction(event.currentTarget, async () => {
          const result = data(await context.api.get('server/outbound/get', { id: item.id }, { signal: context.signal }));
          if (!context.signal.aborted) outboundEditor({ ...item, ...result }, context, reload);
        })), remove);
      } },
    ], filtered));
  }
  search.addEventListener('input', render);
  render();
  return h('div', { class: 'stack-lg' },
    notice('入口选择出站后，成员仍连接原入口，流量由所选出站访问目标。支持 VLESS、Hysteria2、SOCKS5、Shadowsocks、Trojan 和 VMess。'),
    panel(null, h('div', {}, toolbar, list)));
}

function closeOnNavigation(editor, signal) {
  const leave = () => editor.dialog.close();
  signal.addEventListener('abort', leave, { once: true });
  editor.dialog.addEventListener('close', () => signal.removeEventListener('abort', leave), { once: true });
}

export function importOutbound(context, reload) {
  const uri = textarea('outbound_uri', '', { rows: 4, required: true, spellcheck: false, autocomplete: 'off', placeholder: '例如 vless://…、socks5://… 或 ss://…', class: 'input mono code-input' });
  const editor = openDialog({
    title: '导入出站链接', description: '解析单个现成节点的分享链接，核对连接信息后再保存。',
    content: h('div', { class: 'stack-lg' },
      field('节点分享链接', uri, { required: true, hint: '支持 VLESS、Hysteria2、SOCKS5、Shadowsocks、Trojan、VMess 的单节点链接。VLESS、Trojan 和 VMess 当前支持 TCP 传输。' }),
      notice('链接可能包含账号和密码。解析只填入待确认表单，不会创建出站或修改正在运行的节点。')),
    submitLabel: '解析并预览',
    onSubmit: async () => {
      const value = uri.value.trim();
      uri.disabled = true;
      try { return data(await context.api.post('server/outbound/import', { uri: value }, { signal: context.signal })); }
      finally { uri.disabled = false; }
    },
    onSuccess: result => { if (!context.signal.aborted) outboundEditor(result, context, reload, { imported: true }); },
  });
  closeOnNavigation(editor, context.signal);
  return editor;
}

const apiFieldNames = {
  name: 'name', type: 'type', notes: 'notes',
  'settings.server': 'server', 'settings.server_port': 'server_port',
  'settings.uuid': 'uuid', 'settings.password': 'password', 'settings.flow': 'flow',
  'settings.username': 'username', 'settings.method': 'method', 'settings.security': 'vmess_security', 'settings.alter_id': 'alter_id',
  'settings.tls.enabled': 'tls_enabled',
  'settings.tls.server_name': 'server_name', 'settings.tls.insecure': 'tls_insecure',
  'settings.tls.utls.fingerprint': 'fingerprint', 'settings.tls.reality.public_key': 'public_key',
  'settings.tls.reality.short_id': 'short_id', 'settings.obfs.password': 'obfs_password',
  'settings.up_mbps': 'up_mbps', 'settings.down_mbps': 'down_mbps',
};

function fieldError(control, message) {
  const group = control?.closest('.field, .check-field');
  if (!group) return;
  group.querySelector('.field-error')?.remove();
  if (message) {
    const id = `${control.id}-error`;
    control.setAttribute('aria-invalid', 'true');
    control.setAttribute('aria-errormessage', id);
    group.append(h('p', { id, class: 'field-error' }, message));
  } else {
    control.removeAttribute('aria-invalid');
    control.removeAttribute('aria-errormessage');
  }
}

export function outboundEditor(outbound, context, reload, { imported = false } = {}) {
  const initial = outboundFormValues(outbound ?? {});
  const type = select('type', OUTBOUND_TYPES, initial.type);
  const protocolBody = h('div', { class: 'form-grid' });
  const drafts = { [initial.type]: initial };
  let previousType = initial.type;
  let editor;
  const content = h('div', { class: 'stack-lg' },
    imported ? notice('链接已解析。请核对名称、地址和认证信息，点击“保存出站”后才会创建记录。', 'success') : null,
    outbound?.usage_count > 0 ? notice(`此出站正被 ${outbound.usage_count} 个入口使用。保存后会同步连接信息，相关连接可能短暂中断。`, 'warning') : null,
    h('div', { class: 'form-grid' },
      field('出站名称', input('name', initial.name, { required: true, maxlength: 255, placeholder: '例如：日本 · 现成落地节点' }), { required: true }),
      field('出站协议', type),
      field('出站地址', input('server', initial.server, { required: true, spellcheck: false, placeholder: 'exit.example.com 或 IP' }), { required: true, hint: '填写远端节点地址，不包含协议前缀或端口。' }),
      field('出站端口', input('server_port', initial.server_port, { required: true, type: 'number', min: 1, max: 65535, step: 1 }), { required: true })),
    h('section', { class: 'form-section' }, h('h3', {}, '认证与安全'), protocolBody),
    field('备注', textarea('notes', initial.notes, { maxlength: 2000, placeholder: '地区、用途或凭据来源，便于区分' })));

  function renderProtocol(values) {
    protocolBody.replaceChildren();
    if (type.value === 'socks') {
      protocolBody.append(
        field('用户名（可选）', input('username', values.username, { autocomplete: 'off', maxlength: 255 })),
        field('密码（可选）', secretInput('password', values.password, { maxlength: 255 })),
        h('p', { class: 'field-hint field-wide' }, '使用 SOCKS5 协议。无需认证时，用户名和密码都留空；转发 UDP 还需远端 SOCKS5 服务支持。'));
      return;
    }
    if (type.value === 'shadowsocks') {
      protocolBody.append(
        field('加密方式', select('method', SHADOWSOCKS_METHODS.map(value => ({ value, label: value })), values.method)),
        field('密码 / 密钥', secretInput('password', values.password, { required: true, spellcheck: false }), { required: true, hint: '2022 加密方式需要远端提供的 Base64 密钥；带身份密钥时保留完整的冒号分隔内容。' }));
      return;
    }
    const serverName = input('server_name', values.server_name, { spellcheck: false, placeholder: '与现成节点的 SNI 保持一致' });
    const insecure = checkbox('tls_insecure', '跳过证书校验', Boolean(values.tls_insecure), '仅用于已确认需要跳过校验的 TLS 节点。');
    const alpn = field('ALPN（可选）', input('alpn', values.alpn, { placeholder: type.value === 'hysteria2' ? '例如 h3，留空使用协议默认值' : '例如 h2, http/1.1' }), { hint: '多个值用逗号分隔，与远端节点保持一致。' });
    if (type.value === 'vless') {
      const security = select('security', [{ value: 'reality', label: 'REALITY' }, { value: 'tls', label: 'TLS' }], values.security);
      const utls = checkbox('utls_enabled', '启用 uTLS 指纹', Boolean(values.utls_enabled));
      const utlsControl = utls.querySelector('input');
      let tlsUtls = Boolean(values.utls_enabled);
      const fingerprint = select('fingerprint', UTLS_FINGERPRINTS.map(value => ({ value, label: value })), values.fingerprint);
      const reality = h('div', { class: 'form-grid field-wide' },
        field('REALITY 公钥', input('public_key', values.public_key, { class: 'input mono', spellcheck: false, required: true, pattern: '[A-Za-z0-9_\\-]{43}', maxlength: 43 }), { wide: true, required: true, hint: '使用现成节点的公钥。连接远端不需要私钥，也不应重新生成密钥。' }),
        field('Short ID', input('short_id', values.short_id, { class: 'input mono', spellcheck: false, pattern: '([0-9a-fA-F]{2}){0,8}', maxlength: 16, placeholder: '与现成节点保持一致' }), { wide: true, hint: '不超过 16 位的偶数位十六进制字符；远端允许时可留空。' }));
      const showSecurity = () => {
        const isReality = security.value === 'reality';
        reality.hidden = !isReality;
        for (const control of reality.querySelectorAll('input')) control.disabled = !isReality;
        serverName.required = isReality;
        insecure.hidden = isReality;
        insecure.querySelector('input').disabled = isReality;
        utlsControl.checked = isReality || tlsUtls;
        utlsControl.disabled = isReality;
        fingerprint.disabled = !utlsControl.checked;
        fingerprint.required = utlsControl.checked;
      };
      security.addEventListener('change', showSecurity);
      utlsControl.addEventListener('change', () => { tlsUtls = utlsControl.checked; showSecurity(); });
      protocolBody.append(
        field('UUID', secretInput('uuid', values.uuid, { required: true, spellcheck: false, pattern: '[0-9a-fA-F]{8}(-[0-9a-fA-F]{4}){3}-[0-9a-fA-F]{12}' }), { required: true, wide: true }),
        field('安全方式', security),
        field('Flow', select('flow', [{ value: 'xtls-rprx-vision', label: 'xtls-rprx-vision' }, { value: '', label: '不设置 Flow' }], values.flow)),
        field('服务器名称 / SNI', serverName, { wide: true, hint: 'REALITY 必填；TLS 留空时使用出站地址。' }),
        reality, utls, field('客户端指纹', fingerprint), alpn, insecure,
        h('p', { class: 'field-hint field-wide' }, 'VLESS 出站当前使用 TCP 传输，支持 TLS 与 REALITY。'));
      showSecurity();
    } else if (type.value === 'hysteria2') {
      const obfs = checkbox('obfs_enabled', '启用 Salamander 混淆', Boolean(values.obfs_enabled));
      const password = secretInput('obfs_password', values.obfs_password);
      const obfsField = field('混淆密码', password, { required: true });
      const toggleObfs = () => {
        const enabled = obfs.querySelector('input').checked;
        obfsField.hidden = !enabled;
        for (const control of password.querySelectorAll('input,button')) control.disabled = !enabled;
        password.querySelector('input').required = enabled;
      };
      obfs.querySelector('input').addEventListener('change', toggleObfs);
      protocolBody.append(
        field('Hysteria2 密码', secretInput('password', values.password, { required: true, spellcheck: false }), { required: true, wide: true }),
        field('服务器名称 / SNI', serverName, { hint: '留空时使用出站地址，须与远端证书匹配。' }), alpn,
        field('上行带宽（Mbps）', input('up_mbps', values.up_mbps, { type: 'number', min: 1, max: 100000, step: 1, placeholder: '使用协议默认值' })),
        field('下行带宽（Mbps）', input('down_mbps', values.down_mbps, { type: 'number', min: 1, max: 100000, step: 1, placeholder: '使用协议默认值' })),
        obfs, obfsField, h('div', { class: 'field-wide' }, insecure));
      toggleObfs();
    } else {
      const vmess = type.value === 'vmess';
      const tls = checkbox('tls_enabled', '启用 TLS', Boolean(values.tls_enabled), '与现成 VMess 节点的安全设置保持一致。');
      const utls = checkbox('utls_enabled', '启用 uTLS 指纹', Boolean(values.utls_enabled));
      const fingerprint = select('fingerprint', UTLS_FINGERPRINTS.map(value => ({ value, label: value })), values.fingerprint);
      const tlsFields = h('div', { class: 'form-grid field-wide' }, field('服务器名称 / SNI', serverName, { hint: '留空时使用出站地址，须与远端证书匹配。' }), alpn, utls, field('客户端指纹', fingerprint), h('div', { class: 'field-wide' }, insecure));
      const toggleTls = () => {
        const enabled = !vmess || tls.querySelector('input').checked;
        tlsFields.hidden = !enabled;
        for (const control of tlsFields.querySelectorAll('input,select,button')) control.disabled = !enabled;
        fingerprint.disabled = !enabled || !utls.querySelector('input').checked;
      };
      tls.querySelector('input').addEventListener('change', toggleTls);
      utls.querySelector('input').addEventListener('change', toggleTls);
      if (vmess) {
        protocolBody.append(
          field('UUID', secretInput('uuid', values.uuid, { required: true, spellcheck: false, pattern: '[0-9a-fA-F]{8}(-[0-9a-fA-F]{4}){3}-[0-9a-fA-F]{12}' }), { required: true, wide: true }),
          field('加密方式', select('vmess_security', ['auto', 'aes-128-gcm', 'chacha20-poly1305', 'none', 'zero'].map(value => ({ value, label: value })), values.vmess_security)),
          field('Alter ID', input('alter_id', values.alter_id, { type: 'number', required: true, min: 0, max: 65535, step: 1 }), { hint: '通常为 0，请以远端节点的参数为准。' }),
          h('div', { class: 'field-wide' }, tls));
      } else protocolBody.append(field('Trojan 密码', secretInput('password', values.password, { required: true, spellcheck: false }), { required: true, wide: true }));
      protocolBody.append(tlsFields, h('p', { class: 'field-hint field-wide' }, `${outboundProtocolName({ type: type.value })} 出站当前使用 TCP 传输。`));
      toggleTls();
    }
  }

  type.addEventListener('change', () => {
    drafts[previousType] = formValues(editor.form);
    previousType = type.value;
    renderProtocol(drafts[type.value] ?? { ...outboundFormValues(type.value === 'vless' ? {} : { type: type.value }), tls_enabled: true });
  });
  renderProtocol(initial);
  editor = openDialog({
    title: outbound?.id ? '编辑出站' : '添加出站', description: '保存现成代理的连接信息，供入口节点统一选择。',
    content, large: true, submitLabel: '保存出站',
    onSubmit: async form => {
      try {
        const payload = outboundPayload(formValues(form), outbound);
        const controls = [...content.querySelectorAll('input,select,textarea,button')].map(control => [control, control.disabled]);
        for (const [control] of controls) control.disabled = true;
        try { return await context.api.post('server/outbound/save', payload, { signal: context.signal }); }
        finally { for (const [control, disabled] of controls) control.disabled = disabled; }
      } catch (error) {
        let first = null;
        const messages = error.field ? { [error.field]: [error.message] } : (error.errors ?? {});
        for (const [key, values] of Object.entries(messages)) {
          const name = apiFieldNames[key] ?? key;
          const control = form.elements.namedItem(name);
          if (!control?.matches?.('input,select,textarea') || control.disabled) continue;
          fieldError(control, Array.isArray(values) ? values.join('；') : String(values));
          first ??= control;
        }
        first?.focus();
        throw error;
      }
    },
    onSuccess: () => { if (!context.signal.aborted) { toast(outbound?.id ? '出站已更新，正在同步到关联入口' : '出站已保存，可在节点中选择'); reload(); } },
  });
  editor.form.addEventListener('input', event => {
    if (event.target.matches('input,select,textarea')) fieldError(event.target, '');
  });
  editor.form.addEventListener('focusout', event => {
    const control = event.target;
    if (control.matches('input,select,textarea') && !control.disabled) fieldError(control, control.validity.valid ? '' : control.validationMessage);
  });
  closeOnNavigation(editor, context.signal);
  return editor;
}
