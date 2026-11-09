#!/usr/bin/env python3
"""Exercise VPS maintenance commands against run-stack.py's disposable API.

The browser copies the real API commands but never executes them. Verify that
maintenance, clipboard fallbacks and canceled deletion leave the token and VPS
record unchanged; only the explicit deletion submit may change the record.
"""
import json
import os
from pathlib import Path
import re
from urllib.parse import urlsplit

from playwright.sync_api import expect, sync_playwright


MASK = '[data-secret-command],[name="machine-command"],[name="machine-token"],[name="manual-copy"],input[type="email"],.admin-profile h3,.account-label'


def main():
    base = os.environ['E2E_BASE_URL'].rstrip('/')
    if os.environ.get('SIMPLEXBOARD_E2E') != '1' or urlsplit(base).hostname != '127.0.0.1':
        raise RuntimeError('Use tests/Support/run-stack.py with its disposable loopback database.')
    admin_path = os.environ['E2E_ADMIN_PATH'].strip('/')
    artifacts = Path(os.environ['E2E_ARTIFACT_DIR'])
    artifacts.mkdir(parents=True, exist_ok=True)
    report = {'status': 'failed', 'checks': [], 'screenshots': [], 'page_errors': [], 'scope': 'real-disposable-api-and-ui; commands-copied-only'}
    stage = 'login'

    def passed(name):
        report['checks'].append(name)
        print('PASS ' + name, flush=True)

    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(headless=True, executable_path=os.environ.get('E2E_CHROMIUM_PATH') or None, args=['--no-sandbox'])
        context = browser.new_context(viewport={'width': 1440, 'height': 1000}, locale='zh-CN')
        context.grant_permissions(['clipboard-read', 'clipboard-write'], origin=base)
        page = context.new_page()
        page.set_default_timeout(15000)
        page.on('pageerror', lambda error: report['page_errors'].append(str(error)))
        mutations = []
        page.on('request', lambda request: mutations.append(urlsplit(request.url).path)
                if request.method == 'POST' and '/server/machine/' in urlsplit(request.url).path else None)

        def api(endpoint, body=None, expected=200, response_only=False):
            token = page.evaluate("sessionStorage.getItem('simplexboard:admin-session')")
            assert token, 'Administrator session missing'
            headers = {'Authorization': token if token.startswith('Bearer ') else 'Bearer ' + token, 'Accept': 'application/json'}
            url = base + '/api/v2/' + admin_path + '/' + endpoint
            response = context.request.get(url, headers=headers) if body is None else context.request.post(url, data=body, headers=headers)
            assert response.status == expected, f'{endpoint}: expected HTTP {expected}, got {response.status}'
            if response_only:
                return response
            value = response.json()
            return value.get('data', value)

        def dialog(title):
            result = page.get_by_role('dialog', name=title, exact=True)
            expect(result).to_be_visible()
            return result

        def close(item, label='关闭'):
            item.locator('.dialog-footer').get_by_role('button', name=label, exact=True).click()
            expect(item).to_have_count(0)

        def no_overflow():
            assert page.evaluate('Math.max(document.documentElement.scrollWidth, document.body.scrollWidth) <= innerWidth + 1'), 'Horizontal page overflow'
            opened = page.locator('dialog[open]').last
            if opened.count():
                assert opened.evaluate('(element) => element.scrollWidth <= element.clientWidth + 1'), 'Horizontal dialog overflow'

        def screenshot(name):
            no_overflow()
            expect(page.locator('.toast')).to_have_count(0, timeout=10000)
            path = artifacts / ('maintenance-' + name + '.png')
            page.screenshot(path=str(path), full_page=False, animations='disabled', mask=[page.locator(MASK)], mask_color='#94a3b8')
            report['screenshots'].append(path.name)

        def copied(item, label, expected):
            item.get_by_role('button', name='复制' + label, exact=True).click()
            expect(page.locator('.toast-success').last).to_have_text(label + '已复制，请在目标 VPS 终端执行')
            assert page.evaluate('navigator.clipboard.readText()') == expected, 'Clipboard differs from the selected API command'
            expect(page.get_by_role('dialog', name='手动复制', exact=True)).to_have_count(0)

        try:
            page.goto(base + '/' + admin_path)
            page.get_by_label('管理员邮箱', exact=True).fill(os.environ['E2E_ADMIN_EMAIL'])
            page.get_by_label('密码', exact=True).fill(os.environ['E2E_ADMIN_PASSWORD'])
            page.get_by_role('button', name='登录管理台', exact=True).click()
            expect(page.get_by_role('navigation', name='主导航')).to_be_visible()

            stage = 'real-command-api'
            machine_name = '节点维护验收 VPS'
            machine = api('server/machine/save', {'name': machine_name, 'notes': '只验证命令生成与复制，未连接目标 VPS', 'is_active': True})
            identifier = machine['id']
            original_token = api('server/machine/getToken?id=' + str(identifier))['token']
            response = api('server/machine/maintenanceCommands?id=' + str(identifier), response_only=True)
            assert 'no-store' in response.headers.get('cache-control', '')
            commands = response.json()['data']
            assert commands['version'] and commands['platforms'] == ['linux-amd64', 'linux-arm64']
            assert commands['install_command'] == machine['install_command'] == api('server/machine/installCommand?id=' + str(identifier))['command']
            assert original_token in commands['install_command']
            for key in ('upgrade_command', 'uninstall_command', 'purge_command'):
                assert original_token not in commands[key] and '--token' not in commands[key] and '--machine-id' not in commands[key]
            assert '--purge' not in commands['uninstall_command'] and '--purge' in commands['purge_command']
            passed('真实维护 API 返回三种操作与显式 purge 命令，安装旧接口兼容且响应 no-store')

            def unchanged():
                assert api('server/machine/getToken?id=' + str(identifier))['token'] == original_token, 'A read/copy operation rotated the machine token'
                current = next(item for item in api('server/machine/fetch') if item['id'] == identifier)
                assert current['name'] == machine_name and current['is_active'], 'A read/copy operation changed or deleted the VPS record'
                assert not mutations, 'A read/copy operation posted a machine mutation'

            page.get_by_role('navigation', name='主导航').get_by_role('link', name='VPS 与节点', exact=True).click()
            expect(page.locator('#page-content .loading-state')).to_have_count(0)
            page.get_by_role('tab', name=re.compile(r'^VPS ')).click()
            screenshot('desktop-vps-card')

            stage = 'legacy-install-shortcut'
            page.get_by_role('button', name='接入命令', exact=True).click()
            form = dialog('接入 VPS · ' + machine_name)
            expect(form.get_by_label('接入命令', exact=True)).to_have_value(commands['install_command'])
            expect(form.get_by_label('机器令牌', exact=True)).to_have_value(original_token)
            form.get_by_role('button', name='复制接入命令', exact=True).click()
            expect(page.locator('.toast-success').last).to_have_text('接入命令已复制')
            assert page.evaluate('navigator.clipboard.readText()') == commands['install_command']
            close(form)
            unchanged()
            passed('原接入命令快捷入口和真实复制保持兼容，不轮换令牌')

            stage = 'maintenance-actions'
            page.get_by_role('button', name='节点维护 ' + machine_name, exact=True).click()
            form = dialog('节点维护 · ' + machine_name)
            expect(form.get_by_label('安装接入命令', exact=True)).to_have_value(commands['install_command'])
            copied(form, '安装接入命令', commands['install_command'])
            install_tab = form.get_by_role('tab', name='安装接入', exact=True)
            install_tab.focus()
            install_tab.press('ArrowRight')
            expect(form.get_by_role('tab', name='升级 / 替换', exact=True)).to_be_focused()
            expect(form.get_by_label('升级命令', exact=True)).to_have_value(commands['upgrade_command'])
            expect(form.get_by_role('tabpanel')).to_contain_text('保留配置、凭据和机器绑定')
            expect(form.get_by_role('tabpanel')).to_contain_text('尝试恢复旧程序')
            copied(form, '升级命令', commands['upgrade_command'])
            screenshot('desktop-upgrade')
            form.get_by_role('tab', name='卸载节点', exact=True).click()
            expect(form.get_by_label('卸载命令', exact=True)).to_have_value(commands['uninstall_command'])
            purge = form.get_by_role('checkbox', name='同时删除当前配置与凭据（备份保留）')
            expect(purge).not_to_be_checked()
            expect(form.get_by_role('tabpanel')).to_contain_text('这台 VPS 上的全部节点实例')
            copied(form, '卸载命令', commands['uninstall_command'])
            purge.check()
            expect(form.get_by_label('卸载并删除配置命令', exact=True)).to_have_value(commands['purge_command'])
            expect(form.get_by_role('alert')).to_contain_text('并删除当前配置与凭据')
            copied(form, '卸载并删除配置命令', commands['purge_command'])
            form.get_by_role('tab', name='升级 / 替换', exact=True).click()
            form.get_by_role('tab', name='卸载节点', exact=True).click()
            expect(form.get_by_role('checkbox', name='同时删除当前配置与凭据（备份保留）')).not_to_be_checked()
            expect(form.get_by_label('卸载命令', exact=True)).to_have_value(commands['uninstall_command'])
            unchanged()
            passed('三个维护操作与 purge 复制完整命令；键盘切换可用，离开卸载即恢复保留配置默认')

            stage = 'clipboard-fallbacks'
            page.evaluate('''() => {
              window.maintenanceClipboardWrite = navigator.clipboard.writeText.bind(navigator.clipboard);
              window.maintenanceExecCommand = document.execCommand;
              navigator.clipboard.writeText = async () => { throw new DOMException('Test denial', 'NotAllowedError'); };
            }''')
            copied(form, '卸载命令', commands['uninstall_command'])
            expect(page.locator('.toast')).to_have_count(0, timeout=10000)
            page.evaluate('document.execCommand = () => false')
            form.get_by_role('button', name='复制卸载命令', exact=True).click()
            manual = dialog('手动复制')
            expect(page.locator('.toast-success')).to_have_count(0)
            control = manual.get_by_label('待复制内容', exact=True)
            expect(control).to_have_value(commands['uninstall_command'])
            expect(control).to_be_focused()
            assert control.evaluate('(element) => element.value.slice(element.selectionStart, element.selectionEnd)') == commands['uninstall_command']
            control.press('Control+C')
            assert page.evaluate('navigator.clipboard.readText()') == commands['uninstall_command']
            close(manual)
            expect(form).to_be_visible()
            page.evaluate('''() => {
              navigator.clipboard.writeText = window.maintenanceClipboardWrite;
              document.execCommand = window.maintenanceExecCommand;
            }''')
            close(form)
            unchanged()
            passed('Clipboard API 拒绝后仍真实复制；全部自动复制失败时无虚假成功，可手动复制并返回维护弹窗')

            stage = 'mobile-maintenance'
            page.set_viewport_size({'width': 390, 'height': 844})
            screenshot('mobile-vps-card')
            page.get_by_role('button', name='节点维护 ' + machine_name, exact=True).click()
            form = dialog('节点维护 · ' + machine_name)
            expect(form.get_by_label('安装接入命令', exact=True)).to_have_value(commands['install_command'])
            form.get_by_role('tab', name='升级 / 替换', exact=True).click()
            copied(form, '升级命令', commands['upgrade_command'])
            screenshot('mobile-upgrade')
            form.get_by_role('tab', name='卸载节点', exact=True).click()
            form.get_by_role('checkbox', name='同时删除当前配置与凭据（备份保留）').check()
            warning = form.get_by_role('alert')
            warning.scroll_into_view_if_needed()
            box = warning.bounding_box()
            assert box and box['y'] >= 0 and box['y'] + box['height'] <= 844, 'Destructive scope is outside the mobile viewport'
            screenshot('mobile-uninstall-purge')
            copied(form, '卸载并删除配置命令', commands['purge_command'])
            close(form)
            unchanged()
            passed('390px 维护入口、升级和危险卸载说明无横向溢出，真实复制与关闭可用')

            stage = 'delete-record-command-retry'
            page.set_viewport_size({'width': 1440, 'height': 1000})
            command_route = '**/server/machine/maintenanceCommands?*'
            page.route(command_route, lambda route: route.abort())
            page.get_by_role('button', name='删除 VPS ' + machine_name, exact=True).click()
            form = dialog('删除 VPS 记录')
            expect(form.get_by_role('alert')).to_contain_text('数据暂时无法读取')
            expect(form.get_by_role('button', name='删除记录', exact=True)).to_be_disabled()
            unchanged()
            page.unroute(command_route)
            form.get_by_role('button', name='重新加载', exact=True).click()
            expect(form.get_by_label('卸载命令', exact=True)).to_have_value(commands['uninstall_command'])
            expect(form.get_by_role('button', name='删除记录', exact=True)).to_be_enabled()
            expect(form).to_contain_text('此操作不会卸载 VPS 上的节点程序')
            copied(form, '卸载命令', commands['uninstall_command'])
            unchanged()
            screenshot('desktop-delete-record')
            close(form, '取消')
            unchanged()
            passed('删除记录前可先保存卸载命令；读取失败禁用删除并支持重试，复制和取消均无后台变更')

            stage = 'explicit-record-deletion'
            page.get_by_role('button', name='删除 VPS ' + machine_name, exact=True).click()
            form = dialog('删除 VPS 记录')
            expect(form.get_by_role('button', name='删除记录', exact=True)).to_be_enabled()
            with page.expect_response(lambda response: response.request.method == 'POST' and urlsplit(response.url).path.endswith('/server/machine/drop')) as waiting:
                form.get_by_role('button', name='删除记录', exact=True).click()
            assert waiting.value.ok, 'Explicit record deletion failed'
            expect(form).to_have_count(0)
            expect(page.get_by_role('heading', name=machine_name, exact=True)).to_have_count(0)
            assert all(item['id'] != identifier for item in api('server/machine/fetch'))
            assert len(mutations) == 1 and mutations[0].endswith('/server/machine/drop'), 'Unexpected machine mutation'
            api('server/machine/getToken?id=' + str(identifier), expected=422)
            passed('只有明确点击“删除记录”才提交一次删除，记录随后不可读取；未执行任何 VPS 命令')
            assert not report['page_errors'], 'Browser reported a JavaScript error'
            report['status'] = 'passed'
        except Exception as error:
            report['failure'] = {'stage': stage, 'type': type(error).__name__}
            try:
                screenshot('failure')
            except Exception:
                pass
            raise
        finally:
            (artifacts / 'node-maintenance-results.json').write_text(json.dumps(report, ensure_ascii=False, indent=2) + '\n')
            browser.close()


if __name__ == '__main__':
    main()
