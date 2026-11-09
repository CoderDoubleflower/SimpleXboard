import { h, icon, button, field, textarea, checkbox, notice, loading, errorState, tabs, copyText, runAction, openDialog, toast } from '../ui.js';
import { data } from '../format.js';
import { MAINTENANCE_ACTIONS, maintenanceOperation } from '../node-maintenance.js';

function commandView(commands, action, prefix) {
  let operation = maintenanceOperation(commands, action);
  const description = h('div');
  const purge = checkbox(`${prefix}-purge`, '同时删除当前配置与凭据（备份保留）', false, '仅在目标 VPS 执行命令时删除 /etc/xboard-node。');
  const purgeControl = purge.querySelector('input');
  const command = textarea(`${prefix}-command`, operation.command, { readonly: true, rows: 4, spellcheck: false, class: 'input mono code-input', ...(action === 'install' ? { 'data-secret-command': true } : {}) });
  const commandField = field(operation.label, command);
  const copy = button(`复制${operation.label}`, { icon: 'copy', tone: action === 'uninstall' ? 'danger-outline' : 'primary', onClick: event => runAction(event.currentTarget, () => copyText(operation.command, `${operation.label}已复制，请在目标 VPS 终端执行`)) });
  const detail = h('p', { class: 'field-hint maintenance-detail' });

  function render() {
    operation = maintenanceOperation(commands, action, purgeControl.checked);
    command.value = operation.command;
    commandField.querySelector('label').textContent = operation.label;
    copy.replaceChildren(icon('copy'), `复制${operation.label}`);
    if (action === 'install') {
      description.replaceChildren(notice('在目标 VPS 安装并接入节点服务。已有标准安装会转为保留配置的升级，不会重新绑定机器。命令含机器令牌，请勿公开分享。'));
      detail.textContent = '安装完成后，在面板将入口节点绑定到这台 VPS，并等待机器心跳。';
    } else if (action === 'upgrade') {
      description.replaceChildren(notice('保留配置、凭据和机器绑定。替换前自动备份旧程序及完整配置；重启或健康检查失败时尝试恢复旧程序。升级会短暂中断这台 VPS 上的节点。', 'warning'));
      detail.replaceChildren('备份目录：', h('code', {}, '/var/backups/simplexboard-node/'), '。如自动恢复也失败，终端会报错并保留备份，便于手动恢复。');
    } else {
      description.replaceChildren(notice(operation.purge
        ? '执行后将停止并卸载这台 VPS 上的全部节点实例，并删除当前配置与凭据。再次接入需要重新配置。面板记录和历史备份会保留。'
        : '执行后将停止并卸载这台 VPS 上的全部节点实例。默认保留当前配置与凭据、历史备份；面板中的 VPS 和节点记录不会删除。', operation.purge ? 'error' : 'warning'));
      detail.textContent = operation.purge ? '删除范围：/etc/xboard-node。请先确认不再需要其中的节点配置与机器凭据。' : '保留目录：/etc/xboard-node。以后可用原配置重新安装。';
    }
  }
  purgeControl.addEventListener('change', render);
  render();
  return h('div', { class: 'stack-md maintenance-command' }, description, action === 'uninstall' ? purge : null, commandField, h('div', { class: 'inline-actions' }, copy), detail);
}

function releaseInfo(commands) {
  const platforms = (commands.platforms ?? []).map(platform => ({ 'linux-amd64': 'Linux x86_64', 'linux-arm64': 'Linux ARM64' })[platform] ?? platform);
  return h('p', { class: 'field-hint maintenance-detail' }, `目标版本 ${commands.version} · ${platforms.join(' / ')}。适用于标准 systemd 安装；Docker 和自定义安装请使用对应维护方式。`);
}

function openMachineCommands(machine, context, { deleting = false, reload } = {}) {
  if (context.signal.aborted) return null;
  const controller = new AbortController();
  const content = h('div', { class: 'stack-md' });
  let commands = null;
  const editor = openDialog({
    title: deleting ? '删除 VPS 记录' : `节点维护 · ${machine.name}`,
    description: deleting ? `移除“${machine.name}”的面板记录。` : '在目标 VPS 的终端执行命令；面板只负责生成和复制。',
    large: true,
    content,
    ...(deleting ? {
      submitLabel: '删除记录', danger: true,
      onSubmit: async () => {
        if (!commands) throw new Error('请先读取卸载命令，再删除记录。');
        await context.api.post('server/machine/drop', { id: machine.id }, { signal: context.signal });
      },
      onSuccess: () => { toast('VPS 记录已删除'); reload(); },
    } : {}),
  });
  const submit = editor.form.querySelector('button[type="submit"]');
  if (submit) submit.disabled = true;
  const leave = () => editor.dialog.close();
  context.signal.addEventListener('abort', leave, { once: true });
  editor.dialog.addEventListener('close', () => {
    controller.abort();
    context.signal.removeEventListener('abort', leave);
  }, { once: true });

  async function load() {
    commands = null;
    if (submit) submit.disabled = true;
    content.replaceChildren(loading('正在读取节点维护命令…'));
    try {
      const result = data(await context.api.get('server/machine/maintenanceCommands', { id: machine.id }, { signal: controller.signal }));
      for (const action of MAINTENANCE_ACTIONS) maintenanceOperation(result, action.value);
      maintenanceOperation(result, 'uninstall', true);
      if (controller.signal.aborted || context.signal.aborted || !editor.dialog.open) return;
      if (deleting) {
        content.replaceChildren(
          notice('删除记录会使机器令牌失效，并解除关联节点的机器绑定。此操作不会卸载 VPS 上的节点程序，也不会删除 VPS 本身。', 'warning'),
          h('h3', { class: 'maintenance-heading' }, '需要卸载节点？请先保存下方命令'),
          h('p', { class: 'field-hint' }, '复制或在 VPS 执行卸载命令，都不会删除面板记录。只有点击下方“删除记录”才会移除本记录。'),
          commandView(result, 'uninstall', `delete-machine-${machine.id}`),
        );
      } else {
        const navigation = h('div', { class: 'maintenance-tabs' });
        const details = h('div', { role: 'tabpanel', id: `machine-maintenance-${machine.id}` });
        function show(action) {
          const bar = tabs(MAINTENANCE_ACTIONS, action, show);
          bar.setAttribute('aria-label', '节点维护操作');
          for (const [index, tab] of [...bar.children].entries()) {
            tab.id = `maintenance-${machine.id}-${MAINTENANCE_ACTIONS[index].value}`;
            tab.setAttribute('aria-controls', details.id);
          }
          details.setAttribute('aria-labelledby', `maintenance-${machine.id}-${action}`);
          navigation.replaceChildren(bar);
          // Reopening uninstall always starts with configuration preserved.
          details.replaceChildren(commandView(result, action, `maintenance-${machine.id}`));
        }
        show('install');
        content.replaceChildren(navigation, details, releaseInfo(result));
      }
      commands = result;
      if (submit) submit.disabled = false;
    } catch (error) {
      if (!controller.signal.aborted && !context.signal.aborted) content.replaceChildren(errorState(error, load));
    }
  }
  load();
  return editor;
}

export function machineMaintenance(machine, context) {
  return openMachineCommands(machine, context);
}

export function deleteMachine(machine, context, reload) {
  return openMachineCommands(machine, context, { deleting: true, reload });
}
