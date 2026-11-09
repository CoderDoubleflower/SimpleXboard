import test from 'node:test';
import assert from 'node:assert/strict';
import { maintenanceOperation } from '../src/node-maintenance.js';

const commands = Object.freeze({
  install_command: "sudo bash '/tmp/installer' install --machine-token 'a$b; token'\n",
  upgrade_command: "sudo bash '/tmp/installer' upgrade\n",
  uninstall_command: "sudo bash '/tmp/installer' uninstall\n",
  purge_command: "sudo bash '/tmp/installer' uninstall --purge\n",
});

test('maintenance copies the selected server command exactly, including quoting and newlines', () => {
  for (const action of ['install', 'upgrade', 'uninstall']) {
    assert.equal(maintenanceOperation(commands, action).command, commands[`${action}_command`]);
  }
  assert.equal(maintenanceOperation(commands).command, commands.install_command);
});

test('configuration removal requires an explicit boolean choice in uninstall', () => {
  for (const value of [undefined, false, 'true', '1', 1, null]) {
    const choice = maintenanceOperation(commands, 'uninstall', value);
    assert.equal(choice.command, commands.uninstall_command);
    assert.equal(choice.purge, false);
  }
  const purge = maintenanceOperation(commands, 'uninstall', true);
  assert.equal(purge.command, commands.purge_command);
  assert.equal(purge.purge, true);
  assert.match(purge.label, /删除配置/);
  for (const action of ['install', 'upgrade']) {
    assert.equal(maintenanceOperation(commands, action, true).command, commands[`${action}_command`]);
  }
});

test('missing maintenance commands cannot fall back to another operation or expose the install token', () => {
  for (const action of ['upgrade', 'uninstall']) {
    for (const missing of [undefined, null, '', '  ', 123]) {
      assert.throws(() => maintenanceOperation({ ...commands, [`${action}_command`]: missing }, action), /维护命令不完整/);
    }
  }
  assert.throws(() => maintenanceOperation({ ...commands, purge_command: '' }, 'uninstall', true), /维护命令不完整/);
  assert.throws(() => maintenanceOperation(commands, 'purge'), /有效的节点维护操作/);
});
