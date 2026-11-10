import { h, button, badge, input, select, field, textarea, checkbox, panel, loading, errorState, notice, toast, copyText, runAction, openDialog, confirmAction } from '../ui.js';
import { data, errorMessage } from '../format.js';
import { SUBSCRIPTION_CLIENTS, TEMPLATE_PRESETS, copyProfile, rememberTemplate, ruleLines, referenceLines, selectedCategories, newRuleProvider, referenceLabel, chainTargetOptions, templateExport, templatePayload } from '../subscription-templates.js';

export function createSubscriptionTemplateEditor(context) {
  const records = new Map();
  let selected = 'mihomo';
  let started = false;
  let requestSequence = 0;
  const status = h('div', { class: 'template-draft-status', 'aria-live': 'polite' });
  const content = h('div', { class: 'stack-lg' });
  const selector = select('template-client', SUBSCRIPTION_CLIENTS, selected);
  const element = h('div', { class: 'subscription-template-editor stack-lg' },
    panel('订阅模板', h('div', { class: 'panel-body stack-md' },
      h('div', { class: 'template-toolbar' }, field('客户端格式', selector), status),
      h('p', { class: 'muted' }, '分别编辑 mihomo 和 Stash 的分流规则与客户端链路。保存后，成员下次更新订阅时生效。'),
      h('p', { class: 'field-hint' }, '切换客户端会保留当前页的草稿；离开设置页前请保存或导出。'))), content);

  const hasChanges = record => JSON.stringify(record.draft) !== JSON.stringify(record.original);
  function updateStatus() {
    const record = records.get(selected);
    status.replaceChildren(record ? badge(hasChanges(record) ? '有未保存修改' : '已保存', hasChanges(record) ? 'warning' : 'success') : badge('正在读取'));
  }
  function invalidate(record) {
    record.preview = null;
    updateStatus();
    record.previewHost?.replaceChildren(h('p', { class: 'field-hint' }, '点击“生成预览”查看当前草稿的完整 YAML。预览不会保存模板。'));
  }
  function download(text, filename, type) {
    const url = URL.createObjectURL(new Blob([text], { type }));
    const link = h('a', { href: url, download: filename });
    document.body.append(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
  }

  function importTemplate(record, client) {
    const source = textarea('template-import-content', '', { rows: 10, class: 'input mono template-editor', required: true, placeholder: '粘贴 sublink-worker 配置 JSON、转换链接，或从这里导出的模板 JSON。' });
    const file = input('template-import-file', '', { type: 'file', accept: '.json,.txt,application/json,text/plain' });
    const fileError = h('div');
    file.addEventListener('change', async () => {
      const chosen = file.files?.[0];
      if (!chosen) return;
      fileError.replaceChildren();
      if (chosen.size > 524288) { fileError.append(notice('文件超过 512 KiB，请缩小后重试。', 'error')); return; }
      try { source.value = await chosen.text(); }
      catch { fileError.append(notice('文件读取失败，请重试或直接粘贴内容。', 'error')); }
    });
    openDialog({
      title: `导入 ${client === 'stash' ? 'Stash' : 'mihomo'} 模板`,
      description: '导入到当前草稿，检查预览并保存后才会影响成员订阅。转换链接仅用于读取其中的配置参数。',
      large: true,
      content: h('div', { class: 'stack-md' }, field('选择配置文件', file), fileError, field('配置内容或转换链接', source, { required: true }), notice('基础 YAML 中的外部节点凭据会随模板导出，请只导入你准备提供给成员的节点。')),
      submitLabel: '导入到草稿',
      onSubmit: async () => data(await context.api.post('subscription-template/import', { client, content: source.value, profile: copyProfile(record.draft) }, { signal: context.signal })),
      onSuccess: result => {
        record.draft = copyProfile(result.profile);
        record.warnings = result.warnings ?? [];
        record.error = null;
        invalidate(record);
        if (selected === client) render(record, client);
        toast('模板已导入草稿，请检查并保存');
      },
    });
  }

  function render(record, client) {
    const profile = record.draft;
    const metadata = record.metadata;
    const nodes = metadata.nodes ?? [];
    const controls = new Map();
    const errors = h('div', { class: 'template-errors', tabindex: '-1' });
    const warnings = h('div', { class: 'stack-md' }, (record.warnings ?? []).map(message => notice(message, 'warning')));
    const form = h('form', { class: 'stack-lg', autocomplete: 'off' });
    const control = (key, element) => { controls.set(key, element); return element; };
    const bind = (element, update, event = 'input') => {
      element.addEventListener(event, () => { update(element.value); invalidate(record); });
      return element;
    };
    function clearErrors() {
      record.error = null;
      errors.replaceChildren();
      for (const node of controls.values()) node.removeAttribute('aria-invalid');
      form.querySelectorAll('.template-field-error').forEach(node => node.remove());
    }
    function showError(error) {
      if (context.signal.aborted) return;
      record.error = error;
      if (selected !== client) return;
      if (!errors.isConnected) { render(record, client); return; }
      clearErrors();
      record.error = error;
      const details = [];
      for (const [key, messages] of Object.entries(error?.errors ?? {})) {
        let path = key.replace(/^profile\./, '');
        let target = controls.get(path);
        while (!target && path.includes('.')) { path = path.slice(0, path.lastIndexOf('.')); target = controls.get(path); }
        if (!target) continue;
        target.setAttribute('aria-invalid', 'true');
        const message = [].concat(messages).filter(item => typeof item === 'string').join('；');
        target.closest('.field')?.append(h('p', { class: 'template-field-error', role: 'alert' }, message));
        details.push(button(message, { className: 'template-error-link', onClick: () => {
          let parent = target.parentElement;
          while (parent) { if (parent.tagName === 'DETAILS') parent.open = true; parent = parent.parentElement; }
          target.focus();
        } }));
      }
      errors.replaceChildren(notice(errorMessage(error), 'error'));
      if (details.length) errors.append(h('div', { class: 'stack-sm' }, details));
      errors.focus();
    }
    const policies = () => [...new Set(['DIRECT', 'REJECT', profile.default_policy || '节点选择', ...(metadata.policies ?? []), ...selectedCategories(profile, metadata.presets)])];
    const policyListId = `template-policy-options-${client}`;
    const policyList = h('datalist', { id: policyListId });
    function updatePolicyOptions() { policyList.replaceChildren(...policies().map(name => h('option', { value: name }))); }
    updatePolicyOptions();
    const policyControl = (name, value, change) => bind(input(name, value, { list: policyListId, required: true, maxlength: 255, placeholder: '节点选择、DIRECT 或策略组名称' }), change);

    const preset = control('preset', select('template-preset', TEMPLATE_PRESETS, profile.preset));
    const categoryHost = control('selected_rules', h('div', { class: 'template-category-grid', tabindex: '-1' }));
    const categoryHint = h('p', { class: 'field-hint' });
    function renderCategories() {
      const chosen = selectedCategories(profile, metadata.presets);
      categoryHint.textContent = profile.preset === 'original'
        ? '继续使用原有 YAML 规则；下方新增的自定义规则和规则集排在原有规则之前。'
        : '按所选分类生成规则并替换基础 YAML 的 rules；自定义规则最先匹配，兜底规则放在最后。点击分类可切换为自选。';
      categoryHost.hidden = profile.preset === 'original';
      categoryHost.replaceChildren(...(metadata.categories ?? []).map((category, index) => {
        const item = checkbox(`template-category-${client}-${index}`, category.label || category.name, chosen.includes(category.name), category.default_policy === 'DIRECT' ? '默认直连' : category.name);
        item.querySelector('input').addEventListener('change', event => {
          const next = new Set(selectedCategories(profile, metadata.presets));
          if (event.target.checked) next.add(category.name); else next.delete(category.name);
          profile.preset = 'custom';
          profile.selected_rules = [...next];
          preset.value = 'custom';
          updatePolicyOptions();
          renderChains();
          invalidate(record);
        });
        return item;
      }));
    }
    preset.addEventListener('change', () => {
      profile.preset = preset.value;
      if (preset.value !== 'custom' && preset.value !== 'original') profile.selected_rules = [...(metadata.presets?.[preset.value] ?? [])];
      renderCategories(); updatePolicyOptions(); renderChains(); invalidate(record);
    });
    renderCategories();
    const defaultPolicy = control('default_policy', policyControl('template-default-policy', profile.default_policy, value => { profile.default_policy = value; updatePolicyOptions(); }));
    defaultPolicy.addEventListener('change', () => renderChains());
    const ruleFormat = control('rule_format', bind(select('template-rule-format', [{ value: 'yaml', label: 'YAML（通用兼容）' }, { value: 'mrs', label: 'MRS（较小体积）' }], profile.rule_format), value => { profile.rule_format = value; }, 'change'));
    const baseUrl = control('rule_base_url', bind(input('template-rule-base-url', profile.rule_base_url, { type: 'url', required: true }), value => { profile.rule_base_url = value; }));
    const rulesSection = panel('规则预设', h('div', { class: 'panel-body stack-md' },
      h('div', { class: 'form-grid' }, field('分流预设', preset), field('默认策略', defaultPolicy, { hint: '预设规则未匹配时使用此策略；保留原规则时沿用原有兜底规则。' })),
      categoryHint, categoryHost,
      h('details', { class: 'template-details' }, h('summary', {}, '规则集来源与格式'), h('div', { class: 'form-grid' },
        field('预设规则格式', ruleFormat), field('预设规则源', baseUrl, { hint: 'Geo 数据根地址，可换为自己的镜像。其下须提供 geosite 与 geoip 目录。' })))), { description: '使用 sublink-worker 的规则分类，保留独立策略组。' });

    const providerHost = h('div', { class: 'stack-md' });
    function renderProviders(focusIndex) {
      providerHost.replaceChildren(...(profile.rule_providers ?? []).map((provider, index) => {
        const key = `rule_providers.${index}.`;
        const name = control(key + 'name', bind(input(`provider-${client}-${index}-name`, provider.name, { required: true, maxlength: 100, placeholder: '例如 work-domains' }), value => { provider.name = value; }));
        const url = control(key + 'url', bind(input(`provider-${client}-${index}-url`, provider.url, { type: 'url', required: true, placeholder: 'https://example.com/rules.yaml' }), value => { provider.url = value; }));
        const behavior = control(key + 'behavior', select(`provider-${client}-${index}-behavior`, [{ value: 'domain', label: 'domain · 域名' }, { value: 'ipcidr', label: 'ipcidr · IP 网段' }, { value: 'classical', label: 'classical · 混合规则' }], provider.behavior));
        const format = control(key + 'format', select(`provider-${client}-${index}-format`, [{ value: 'yaml', label: 'YAML' }, { value: 'text', label: 'text' }, { value: 'mrs', label: 'MRS' }], provider.format));
        function syncFormat() {
          const mrs = format.querySelector('option[value="mrs"]');
          mrs.disabled = provider.behavior === 'classical';
          if (mrs.disabled && provider.format === 'mrs') { provider.format = 'yaml'; format.value = 'yaml'; }
        }
        behavior.addEventListener('change', () => { provider.behavior = behavior.value; syncFormat(); invalidate(record); });
        format.addEventListener('change', () => { provider.format = format.value; invalidate(record); });
        syncFormat();
        const interval = control(key + 'interval', bind(input(`provider-${client}-${index}-interval`, provider.interval, { type: 'number', min: 60, max: 2592000, step: 1, required: true }), value => { provider.interval = value === '' ? '' : Number(value); }));
        const policy = control(key + 'policy', policyControl(`provider-${client}-${index}-policy`, provider.policy, value => { provider.policy = value; }));
        const move = delta => {
          const next = index + delta;
          [profile.rule_providers[index], profile.rule_providers[next]] = [profile.rule_providers[next], profile.rule_providers[index]];
          invalidate(record); renderProviders(next);
        };
        return h('section', { class: 'template-item' },
          h('div', { class: 'template-item-heading' }, h('h3', {}, `规则集 ${index + 1}`), h('div', { class: 'inline-actions' },
            button('上移', { disabled: index === 0, 'aria-label': `上移规则集 ${index + 1}`, onClick: () => move(-1) }),
            button('下移', { disabled: index === profile.rule_providers.length - 1, 'aria-label': `下移规则集 ${index + 1}`, onClick: () => move(1) }),
            button('移除', { tone: 'danger-outline', 'aria-label': `移除规则集 ${index + 1}`, onClick: () => { profile.rule_providers.splice(index, 1); invalidate(record); renderProviders(); } }))),
          h('div', { class: 'form-grid' }, field('规则集名称', name, { required: true }), field('匹配后策略', policy, { required: true }), field('下载地址', url, { wide: true, required: true }), field('规则内容类型', behavior), field('文件格式', format), field('更新间隔（秒）', interval)));
      }));
      if (!profile.rule_providers?.length) providerHost.append(h('p', { class: 'template-empty' }, '尚未添加额外规则集。预设分类的规则集会自动生成。'));
      if (focusIndex !== undefined) providerHost.querySelector(`#field-provider-${client}-${focusIndex}-name`)?.focus();
    }
    renderProviders();
    const providersSection = panel('额外规则集', h('div', { class: 'panel-body stack-md' },
      h('p', { class: 'field-hint' }, '按列表顺序匹配，位于自定义规则之后、预设规则之前。下载由客户端完成。'), providerHost), {
      action: button('添加规则集', { icon: 'plus', onClick: () => { profile.rule_providers ??= []; profile.rule_providers.push(newRuleProvider(profile.default_policy)); invalidate(record); renderProviders(profile.rule_providers.length - 1); } }),
    });

    const customRules = control('custom_rules', bind(textarea('template-custom-rules', (profile.custom_rules ?? []).join('\n'), { rows: 9, spellcheck: false, class: 'input mono template-editor', placeholder: 'DOMAIN-SUFFIX,example.com,节点选择\nDOMAIN,home.example.com,DIRECT\nIP-CIDR,192.168.1.0/24,DIRECT,no-resolve' }), value => { profile.custom_rules = ruleLines(value); }));
    const customSection = panel('自定义规则', h('div', { class: 'panel-body stack-md' },
      field('按优先级排列规则', customRules, { hint: '每行一条，从上到下匹配；移动行即可调整优先级。支持 # 开头的整行注释，不填写 MATCH / FINAL。' }),
      h('p', { class: 'field-hint' }, '可用策略包括 DIRECT、REJECT、节点名称、基础 YAML 中的策略组及已选择的分类组。')),
      { description: '这里的规则优先于规则集与预设分类。' });

    const chainHost = control('chain_rules', h('div', { class: 'stack-md', tabindex: '-1' }));
    function renderChains(focusIndex) {
      chainHost.replaceChildren(...(profile.chain_rules ?? []).map((chain, index) => {
        const nodeOptions = nodes.map(node => ({ value: node.ref ?? `node:${node.id}`, label: `${node.name} · #${node.id}` }));
        const choices = [{ value: '', label: '选择先经过的入口…' }, ...chainTargetOptions(metadata, profile), { value: '__custom__', label: '填写模板节点或策略组名称…' }];
        const knownEntry = choices.some(option => option.value === chain.entry) ? chain.entry : '__custom__';
        const entry = control(`chain_rules.${index}.entry`, select(`chain-${client}-${index}-entry`, choices, knownEntry));
        const customEntry = input(`chain-${client}-${index}-custom-entry`, knownEntry === '__custom__' ? chain.entry : '', { placeholder: '例如 proxy:香港入口 或 group:入口选择', maxlength: 255 });
        const customEntryField = field('入口引用', customEntry, { hint: '使用 proxy:节点名称 或 group:策略组名称；节点名称也可直接填写。' });
        customEntryField.hidden = knownEntry !== '__custom__';
        customEntry.required = knownEntry === '__custom__';
        entry.required = true;
        const route = h('p', { class: 'template-chain-route' });
        function updateRoute() {
          const entryName = referenceLabel(chain.entry, nodes) || '选择入口';
          const exits = (chain.exit_nodes ?? []).map(name => referenceLabel(name, nodes)).join(' / ') || '选择落地';
          route.textContent = `客户端 → ${entryName} → ${exits} → 目标网站`;
        }
        entry.addEventListener('change', () => {
          const custom = entry.value === '__custom__';
          customEntryField.hidden = !custom;
          customEntry.required = custom;
          chain.entry = custom ? customEntry.value.trim() : entry.value;
          invalidate(record); updateRoute();
          if (custom) customEntry.focus();
        });
        customEntry.addEventListener('input', () => { chain.entry = customEntry.value.trim(); invalidate(record); updateRoute(); });
        const knownRefs = new Set(nodeOptions.map(node => node.value));
        const exits = h('fieldset', { class: 'template-node-choices' }, h('legend', {}, '最终落地节点（可多选）'));
        for (const [nodeIndex, node] of nodes.entries()) {
          const ref = node.ref ?? `node:${node.id}`;
          const availability = node.enabled === false || node.enabled === 0 ? '已停用' : node.show === false || node.show === 0 ? '订阅隐藏' : '';
          const item = checkbox(`chain-${client}-${index}-exit-${nodeIndex}`, `${node.name} · #${node.id}`, (chain.exit_nodes ?? []).includes(ref), availability);
          item.querySelector('input').addEventListener('change', event => {
            const next = new Set(chain.exit_nodes ?? []);
            if (event.target.checked) next.add(ref); else next.delete(ref);
            chain.exit_nodes = [...next]; invalidate(record); updateRoute();
          });
          exits.append(item);
        }
        if (!nodes.length) exits.append(h('p', { class: 'field-hint' }, '暂无可选面板节点，可先创建节点，或在下方填写基础 YAML 中的外部节点。'));
        const extraExits = control(`chain_rules.${index}.exit_nodes`, textarea(`chain-${client}-${index}-extra-exits`, (chain.exit_nodes ?? []).filter(ref => !knownRefs.has(ref)).join('\n'), { rows: 2, class: 'input mono', placeholder: 'proxy:美国落地\nproxy:日本落地' }));
        extraExits.addEventListener('input', () => {
          chain.exit_nodes = [...new Set([...(chain.exit_nodes ?? []).filter(ref => knownRefs.has(ref)), ...referenceLines(extraExits.value)])];
          invalidate(record); updateRoute();
        });
        updateRoute();
        return h('section', { class: 'template-item' },
          h('div', { class: 'template-item-heading' }, h('h3', {}, `链路 ${index + 1}`), button('移除链路', { tone: 'danger-outline', 'aria-label': `移除链路 ${index + 1}`, onClick: () => { profile.chain_rules.splice(index, 1); invalidate(record); renderChains(); } })),
          route, h('div', { class: 'form-grid' }, field('先经过的入口', entry, { required: true }), customEntryField), exits,
          h('details', { class: 'template-details', open: Boolean(extraExits.value) }, h('summary', {}, '模板中的其他落地节点'), field('额外落地节点引用', extraExits, { hint: '每行一个 proxy:节点名称。导入时找不到的节点引用会保留在这里，便于修正。' })));
      }));
      if (!profile.chain_rules?.length) chainHost.append(h('p', { class: 'template-empty' }, '尚未配置客户端链路。添加后，所选落地节点会先通过指定入口连接。'));
      if (focusIndex !== undefined) chainHost.querySelector(`#field-chain-${client}-${focusIndex}-entry`)?.focus();
    }
    renderChains();
    const chainsSection = panel('链式代理', h('div', { class: 'panel-body stack-md' },
      h('p', { class: 'field-hint' }, '这是客户端的连接路径；VPS 节点端的出站配置仍在“VPS 与节点”中管理。面板节点按 ID 关联，改名后自动更新。'),
      client === 'stash' ? notice('链式代理需要 Stash iOS / tvOS 3.2.5+ 或 macOS 4.1+。') : null,
      chainHost, h('p', { class: 'field-hint' }, '入口与落地都须在成员的可用订阅中。策略组入口不能包含当前落地节点；保存和预览会检查循环引用。')),
      { action: button('添加链路', { icon: 'plus', onClick: () => { profile.chain_rules ??= []; profile.chain_rules.push({ exit_nodes: [], entry: '' }); invalidate(record); renderChains(profile.chain_rules.length - 1); } }) });

    const baseYaml = control('base_yaml', bind(textarea('template-base-yaml', profile.base_yaml, { rows: 22, spellcheck: false, class: 'input mono template-editor', required: true }), value => { profile.base_yaml = value; }));
    const advanced = panel(null, h('details', { class: 'template-details template-advanced' }, h('summary', {}, '高级：基础 YAML 与策略组'),
      h('div', { class: 'stack-md' },
        notice('基础 YAML 中手工填写的外部代理节点及凭据，会提供给使用此模板的全部成员。面板节点仍按每位成员的授权自动生成。', 'warning'),
        field('基础 YAML', baseYaml, { hint: '保留 DNS、代理组及客户端选项。组内使用 $nodes 引入成员可用节点；新增自定义组后可直接在规则或链路中引用其名称。' }))));

    const previewHost = h('div', { class: 'stack-md' });
    record.previewHost = previewHost;
    const member = control('user_id', select('template-preview-member', [{ value: '', label: '示例预览（使用占位凭据）' }, ...(metadata.members ?? []).map(item => ({ value: String(item.id), label: item.name || item.email || `成员 #${item.id}` }))], record.userId ?? ''));
    const previewHint = h('div');
    function setPreviewHint() {
      previewHint.replaceChildren(member.value ? notice('成员预览包含此成员的真实订阅凭据，请勿分享给其他人；结果遵守其节点权限。', 'warning') : h('p', { class: 'field-hint' }, '示例预览用于检查配置结构。要确认某个成员的完整可用链路，请选择该成员。'));
    }
    member.addEventListener('change', () => { record.userId = member.value; record.preview = null; renderPreview(); setPreviewHint(); });
    setPreviewHint();
    function renderPreview() {
      const preview = record.preview;
      if (!preview) { previewHost.replaceChildren(h('p', { class: 'field-hint' }, '点击“生成预览”查看当前草稿的完整 YAML。预览不会保存模板。')); return; }
      const output = textarea('template-preview-yaml', preview.yaml, { rows: 22, readonly: true, spellcheck: false, class: 'input mono template-editor' });
      previewHost.replaceChildren(...(preview.warnings ?? []).map(message => notice(message, 'warning')),
        field('最终订阅 YAML', output), h('div', { class: 'inline-actions' },
          button('复制 YAML', { icon: 'copy', onClick: () => copyText(output.value, '预览 YAML 已复制') }),
          button('下载 YAML', { icon: 'download', onClick: () => download(output.value, `${client}-preview.yaml`, 'text/yaml;charset=utf-8') })));
    }
    renderPreview();
    const previewButton = button('生成预览', { icon: 'eye', onClick: event => runAction(event.currentTarget, async () => {
      clearErrors();
      if (!form.reportValidity()) return;
      try {
        const draft = templatePayload(profile);
        const snapshot = JSON.stringify(draft);
        const userId = member.value;
        const payload = { client, profile: draft, ...(userId ? { user_id: Number(userId) } : {}) };
        const result = data(await context.api.post('subscription-template/preview', payload, { signal: context.signal }));
        if (context.signal.aborted) return;
        // Edits can continue during a request; do not label an older result as
        // the preview of a newer draft or a different member.
        if (JSON.stringify(templatePayload(record.draft)) !== snapshot || (record.userId ?? '') !== userId) { toast('草稿已变化，请重新生成预览', 'error'); return; }
        record.preview = result;
        if (selected === client) renderPreview();
      } catch (error) { showError(error); }
    }) });
    const previewSection = panel('查看最终配置', h('div', { class: 'panel-body stack-md' },
      h('div', { class: 'template-toolbar' }, field('预览范围', member), previewButton), previewHint, previewHost));

    const save = button('保存当前模板', { tone: 'primary', type: 'submit', icon: 'check', disabled: Boolean(record.saving), 'aria-busy': record.saving ? 'true' : undefined });
    record.saveControl = save;
    const reset = button('放弃当前修改', { onClick: () => {
      if (!hasChanges(record)) return;
      confirmAction({ title: '放弃当前模板修改', description: '当前客户端的草稿将恢复为最近一次保存的模板。另一客户端的草稿保留。', label: '恢复已保存模板', danger: false, onConfirm: () => {
        record.draft = copyProfile(record.original); record.warnings = []; record.error = null; invalidate(record); if (selected === client) render(record, client);
      } });
    } });
    form.addEventListener('submit', event => {
      event.preventDefault();
      if (record.saving) return;
      runAction(save, async () => {
        clearErrors();
        if (!form.reportValidity()) return;
        record.saving = true;
        try {
          const submitted = templatePayload(profile);
          const snapshot = JSON.stringify(profile);
          const result = data(await context.api.post('subscription-template/save', { client, profile: submitted }, { signal: context.signal }));
          if (context.signal.aborted) return;
          record.original = copyProfile(result.profile ?? submitted);
          if (JSON.stringify(record.draft) === snapshot) record.draft = copyProfile(record.original);
          record.warnings = result.warnings ?? [];
          updateStatus();
          if (selected === client) render(record, client);
          toast(`${client === 'stash' ? 'Stash' : 'mihomo'} 模板已保存`);
        } catch (error) { showError(error); }
        finally {
          record.saving = false;
          if (record.saveControl?.isConnected) {
            record.saveControl.disabled = false;
            record.saveControl.removeAttribute('aria-busy');
          }
        }
      });
    });
    form.append(policyList, errors, warnings,
      h('div', { class: 'template-draft-actions' }, button('导入配置', { icon: 'upload', onClick: () => importTemplate(record, client) }),
        button('导出模板 JSON', { icon: 'download', onClick: () => download(templateExport(client, record.draft), `simplexboard-${client}-template.json`, 'application/json;charset=utf-8') }), reset),
      rulesSection, customSection, providersSection, chainsSection, advanced, previewSection,
      h('div', { class: 'settings-form-footer template-save-footer' }, h('p', { class: 'field-hint' }, '只保存当前客户端模板，成员原有订阅地址继续有效。'), save));
    content.replaceChildren(form);
    updateStatus();
    if (record.error) showError(record.error);
  }

  async function load(client) {
    selected = client;
    const sequence = ++requestSequence;
    const existing = records.get(client);
    if (existing) { render(existing, client); return; }
    content.replaceChildren(loading('正在读取订阅模板…'));
    updateStatus();
    try {
      const result = data(await context.api.get('subscription-template/fetch', { client }, { signal: context.signal }));
      if (context.signal.aborted) return;
      const record = rememberTemplate(records, client, result);
      if (sequence === requestSequence && selected === client) render(record, client);
    } catch (error) {
      if (!context.signal.aborted && sequence === requestSequence) { content.replaceChildren(errorState(error, () => load(client))); status.replaceChildren(badge('读取失败', 'danger')); }
    }
  }
  selector.addEventListener('change', () => load(selector.value));
  return { element, activate() { if (started) return; started = true; load(selected); } };
}
