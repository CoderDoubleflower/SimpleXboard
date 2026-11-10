export const MAINTENANCE_ACTIONS = [
  { value: 'install', label: '安装接入' },
  { value: 'upgrade', label: '升级 / 替换' },
  { value: 'uninstall', label: '卸载节点' },
];

// Keep the server's complete, quoted command intact. The browser only chooses
// an operation; it must never reconstruct commands or append destructive flags.
export function maintenanceOperation(commands, action = 'install', purge = false) {
  if (!MAINTENANCE_ACTIONS.some(item => item.value === action)) throw new Error('请选择有效的节点维护操作。');
  const removeConfig = action === 'uninstall' && purge === true;
  const key = removeConfig ? 'purge_command' : `${action}_command`;
  const command = commands?.[key];
  if (typeof command !== 'string' || !command.trim()) throw new Error('维护命令不完整，请重新加载后再试。');
  const label = removeConfig ? '卸载并删除配置命令' : ({ install: '安装接入命令', upgrade: '升级命令', uninstall: '卸载命令' })[action];
  return { action, command, label, purge: removeConfig };
}
