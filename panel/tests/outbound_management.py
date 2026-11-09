#!/usr/bin/env python3
"""Verify outbound management in the real panel on run-stack.py's disposable app.

Exercises all six supported forms and share-link imports, stored credential
round-trips, node selection, legacy routing conflicts, and referenced deletion.
No external proxy is contacted; the node integration suite owns traffic checks.
"""
import base64
import json
import os
from pathlib import Path
import re
from urllib.parse import urlencode, urlsplit

from playwright.sync_api import expect, sync_playwright


UUID = 'c6e27b9a-4ce5-414d-814b-713b30caa7fe'
MASK = '.secret-input input,input[type="email"],.admin-profile h3,.account-label,[name="outbound_uri"]'


def main():
    base = os.environ['E2E_BASE_URL'].rstrip('/')
    if os.environ.get('SIMPLEXBOARD_E2E') != '1' or urlsplit(base).hostname != '127.0.0.1':
        raise RuntimeError('Use tests/Support/run-stack.py with its disposable loopback database.')
    admin_path = os.environ['E2E_ADMIN_PATH'].strip('/')
    artifacts = Path(os.environ['E2E_ARTIFACT_DIR'])
    artifacts.mkdir(parents=True, exist_ok=True)
    report = {'status': 'failed', 'checks': [], 'screenshots': [], 'page_errors': [], 'scope': 'real-disposable-api-and-ui'}
    page = None
    stage = 'login'

    def passed(name):
        report['checks'].append(name)
        print('PASS ' + name, flush=True)

    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(headless=True, executable_path=os.environ.get('E2E_CHROMIUM_PATH') or None, args=['--no-sandbox'])
        context = browser.new_context(viewport={'width': 1440, 'height': 1000}, locale='zh-CN')
        page = context.new_page()
        page.set_default_timeout(15000)
        page.on('pageerror', lambda error: report['page_errors'].append(str(error)))
        saves = {'node': 0}

        def request_seen(request):
            if request.method == 'POST' and urlsplit(request.url).path.endswith('/server/manage/save'):
                saves['node'] += 1

        page.on('request', request_seen)

        def token_headers():
            token = page.evaluate("sessionStorage.getItem('simplexboard:admin-session')")
            if not token:
                raise AssertionError('Administrator session missing')
            return {'Authorization': token if token.startswith('Bearer ') else 'Bearer ' + token, 'Accept': 'application/json'}

        def api(endpoint, body=None, expected=200):
            url = base + '/api/v2/' + admin_path + '/' + endpoint
            response = context.request.get(url, headers=token_headers()) if body is None else context.request.post(url, data=body, headers=token_headers())
            if response.status != expected:
                raise AssertionError(f'{endpoint}: expected HTTP {expected}, got {response.status}')
            value = response.json()
            return value.get('data', value)

        def action(endpoint, click):
            with page.expect_response(lambda response: response.request.method == 'POST' and urlsplit(response.url).path.endswith('/' + endpoint)) as waiting:
                click()
            response = waiting.value
            if not response.ok:
                raise AssertionError(f'{endpoint}: HTTP {response.status}')
            value = response.json()
            if value.get('status') == 'fail':
                raise AssertionError(f'{endpoint}: API reported failure')
            return value.get('data', value)

        def dialog(title):
            item = page.get_by_role('dialog', name=title, exact=True)
            expect(item).to_be_visible()
            return item

        def show_tab(prefix):
            expect(page.locator('#page-content .loading-state')).to_have_count(0)
            page.get_by_role('tab', name=re.compile('^' + prefix + ' ')).click()

        def summary():
            return api('server/outbound/fetch')

        def record(identifier):
            return api('server/outbound/get?id=' + str(identifier))

        def no_overflow(label):
            assert page.evaluate('Math.max(document.documentElement.scrollWidth, document.body.scrollWidth) <= innerWidth + 1'), label + ': horizontal page overflow'
            opened = page.locator('dialog[open]')
            if opened.count():
                assert opened.evaluate('(el) => el.scrollWidth <= el.clientWidth + 1'), label + ': horizontal dialog overflow'

        def screenshot(name):
            no_overflow(name)
            path = artifacts / ('outbound-' + name + '.png')
            page.screenshot(path=str(path), full_page=False, animations='disabled', mask=[page.locator(MASK)], mask_color='#94a3b8')
            report['screenshots'].append(path.name)

        def field(form, name):
            return form.locator('[name="' + name + '"]')

        def fill_common(form, name, type_):
            field(form, 'type').select_option(type_)
            field(form, 'name').fill(name)
            field(form, 'server').fill('127.0.0.1')
            field(form, 'server_port').fill('24443')

        try:
            page.goto(base + '/' + admin_path)
            page.get_by_label('管理员邮箱', exact=True).fill(os.environ['E2E_ADMIN_EMAIL'])
            page.get_by_label('密码', exact=True).fill(os.environ['E2E_ADMIN_PASSWORD'])
            page.get_by_role('button', name='登录管理台', exact=True).click()
            page.get_by_role('navigation', name='主导航').get_by_role('link', name='VPS 与节点', exact=True).click()
            expect(page.locator('#page-content .loading-state')).to_have_count(0)

            stage = 'import-six-protocols'
            pair = api('server/manage/generateRealityKeyPair', {})
            vless = 'vless://' + UUID + '@127.0.0.1:443?' + urlencode({'security': 'reality', 'type': 'tcp', 'sni': 'exit.example.test', 'flow': 'xtls-rprx-vision', 'fp': 'chrome', 'pbk': pair['public_key'], 'sid': 'aabb'}) + '#VLESS'
            vmess = {'v': '2', 'ps': 'VMess', 'add': '127.0.0.1', 'port': '443', 'id': UUID, 'aid': '0', 'scy': 'auto', 'net': 'tcp', 'type': 'none', 'host': '', 'path': '', 'tls': 'tls', 'sni': 'exit.example.test'}
            links = {
                'vless': vless,
                'hysteria2': 'hy2://ui-password@127.0.0.1:443?sni=exit.example.test&obfs=salamander&obfs-password=obfs-password#HY2',
                'socks': 'socks5://ui-user:ui-password@127.0.0.1:1080#SOCKS5',
                'shadowsocks': 'ss://' + base64.urlsafe_b64encode(b'aes-128-gcm:ui-password').decode().rstrip('=') + '@127.0.0.1:8388#Shadowsocks',
                'trojan': 'trojan://ui-password@127.0.0.1:443?security=tls&type=tcp&sni=exit.example.test#Trojan',
                'vmess': 'vmess://' + base64.b64encode(json.dumps(vmess).encode()).decode(),
            }
            imported = {}
            show_tab('出站')
            for type_, uri in links.items():
                before = len(summary())
                page.get_by_role('button', name='导入链接', exact=True).click()
                form = dialog('导入出站链接')
                field(form, 'outbound_uri').fill(uri)
                parsed = action('server/outbound/import', lambda: form.get_by_role('button', name='解析并预览', exact=True).click())
                form = dialog('添加出站')
                expect(field(form, 'type')).to_have_value(type_)
                assert len(summary()) == before, type_ + ': import saved before confirmation'
                name = '导入 · ' + type_
                field(form, 'name').fill(name)
                saved = action('server/outbound/save', lambda: form.get_by_role('button', name='保存出站', exact=True).click())
                expect(page.locator('dialog[open]')).to_have_count(0)
                stored = record(saved['id'])
                assert stored['settings'] == parsed['settings'], type_ + ': imported settings changed during form save'
                imported[type_] = stored
                passed(type_ + '：真实链接解析、确认前不保存、保存后完整回填')

            for item in summary():
                assert 'settings' not in item, 'List response exposes outbound credentials'
            screenshot('desktop-list')

            stage = 'manual-six-protocols'
            shared = {'server': '127.0.0.1', 'server_port': 24443}
            tls = {'enabled': True, 'insecure': False, 'server_name': 'exit.example.test'}
            for type_ in links:
                page.get_by_role('button', name='添加出站', exact=True).click()
                form = dialog('添加出站')
                fill_common(form, '手动 · ' + type_, type_)
                if type_ == 'vless':
                    field(form, 'security').select_option('tls')
                    field(form, 'uuid').fill(UUID)
                    field(form, 'flow').select_option('')
                    field(form, 'utls_enabled').uncheck()
                    field(form, 'server_name').fill('exit.example.test')
                    expected = {**shared, 'uuid': UUID, 'tls': tls}
                elif type_ == 'hysteria2':
                    field(form, 'password').fill(' manual secret ')
                    field(form, 'server_name').fill('exit.example.test')
                    field(form, 'obfs_enabled').check()
                    field(form, 'obfs_password').fill(' obfs secret ')
                    field(form, 'up_mbps').fill('100')
                    field(form, 'down_mbps').fill('200')
                    expected = {**shared, 'password': ' manual secret ', 'tls': tls, 'obfs': {'type': 'salamander', 'password': ' obfs secret '}, 'up_mbps': 100, 'down_mbps': 200}
                elif type_ == 'socks':
                    # Leave both optional credentials empty to exercise anonymous SOCKS5.
                    expected = {**shared, 'version': '5'}
                    screenshot('desktop-socks-form')
                elif type_ == 'shadowsocks':
                    field(form, 'method').select_option('chacha20-ietf-poly1305')
                    field(form, 'password').fill(' manual secret ')
                    expected = {**shared, 'method': 'chacha20-ietf-poly1305', 'password': ' manual secret '}
                elif type_ == 'trojan':
                    field(form, 'password').fill(' manual secret ')
                    field(form, 'server_name').fill('exit.example.test')
                    field(form, 'utls_enabled').check()
                    field(form, 'fingerprint').select_option('firefox')
                    expected = {**shared, 'password': ' manual secret ', 'tls': {**tls, 'utls': {'enabled': True, 'fingerprint': 'firefox'}}}
                else:
                    field(form, 'uuid').fill(UUID)
                    field(form, 'vmess_security').select_option('auto')
                    field(form, 'alter_id').fill('0')
                    field(form, 'tls_enabled').uncheck()
                    expected = {**shared, 'uuid': UUID, 'security': 'auto', 'alter_id': 0}
                saved = action('server/outbound/save', lambda: form.get_by_role('button', name='保存出站', exact=True).click())
                expect(page.locator('dialog[open]')).to_have_count(0)
                assert record(saved['id'])['settings'] == expected, type_ + ': manual form lost or changed settings'
                passed(type_ + '：手动表单保存与数据库配置一致')

            stage = 'mobile-edit-and-reload'
            page.set_viewport_size({'width': 390, 'height': 844})
            page.reload()
            expect(page.get_by_role('heading', name='VPS 与节点', exact=True)).to_be_visible()
            show_tab('出站')
            screenshot('mobile-list')
            for type_, stored in imported.items():
                page.get_by_role('button', name='编辑出站 ' + stored['name'], exact=True).click()
                form = dialog('编辑出站')
                expect(field(form, 'type')).to_have_value(type_)
                if type_ in ('socks', 'vless'):
                    screenshot('mobile-' + type_ + '-form')
                no_overflow(type_)
                field(form, 'notes').fill('移动端回填验收')
                action('server/outbound/save', lambda: form.get_by_role('button', name='保存出站', exact=True).click())
                expect(page.locator('dialog[open]')).to_have_count(0)
                assert record(stored['id'])['settings'] == stored['settings'], type_ + ': mobile edit changed credentials'
            passed('390px：六协议重新读取、编辑保存与无横向溢出')

            stage = 'node-selection-and-capability'
            page.set_viewport_size({'width': 1440, 'height': 1000})
            machine = api('server/machine/save', {'name': '出站验收 VPS', 'is_active': True})
            advertised = context.request.post(base + '/api/v2/server/machine/nodes', data={'machine_id': machine['id'], 'token': machine['token']}, headers={'X-SimpleXboard-Features': 'managed-outbound-v1', 'Accept': 'application/json'})
            assert advertised.ok, 'Could not announce test machine capability'
            page.reload()
            expect(page.locator('#page-content .loading-state')).to_have_count(0)
            show_tab('节点')
            page.get_by_role('button', name='添加节点', exact=True).first.click()
            form = dialog('添加节点')
            expect(form.get_by_text(re.compile('独立节点请先创建'))).to_be_visible()
            socks_id = imported['socks']['id']
            assert field(form, 'outbound_id').locator('option[value="' + str(socks_id) + '"]').is_disabled(), 'Unsupported independent node can choose a new exit'
            field(form, 'name').fill('出站验收入口')
            field(form, 'host').fill('entry.example.test')
            field(form, 'server_port').fill('24445')
            field(form, 'port').fill('24445')
            field(form, 'machine_id').select_option(str(machine['id']))
            expect(field(form, 'outbound_id').locator('option[value="' + str(socks_id) + '"]')).to_be_enabled()
            field(form, 'outbound_id').select_option(str(socks_id))
            field(form, 'reality_server_name').fill('exit.example.test')
            action('server/manage/generateRealityKeyPair', lambda: form.get_by_role('button', name='一键生成密钥和 Short ID', exact=True).click())
            action('server/manage/save', lambda: form.get_by_role('button', name='创建节点', exact=True).click())
            expect(page.locator('dialog[open]')).to_have_count(0)
            saved_node = next(item for item in api('server/manage/getNodes') if item['name'] == '出站验收入口')
            assert saved_node['outbound_id'] == socks_id
            expect(page.get_by_text('出口：' + imported['socks']['name'], exact=True)).to_be_visible()
            passed('节点创建：新版机器可选出站，未接入的独立节点有清晰升级提示')

            stage = 'referenced-deletion'
            show_tab('出站')
            expect(page.get_by_role('button', name='删除出站 ' + imported['socks']['name'], exact=True)).to_be_disabled()
            used = next(item for item in summary() if item['id'] == socks_id)
            assert used['usage_count'] == 1 and any(node['id'] == saved_node['id'] for node in used['used_by'])
            api('server/outbound/drop', {'id': socks_id}, expected=422)
            passed('被引用出站显示关联入口，并同时在界面和 API 阻止删除')

            stage = 'legacy-routing-conflict'
            show_tab('节点')
            page.get_by_role('button', name='编辑节点 出站验收入口', exact=True).click()
            form = dialog('编辑节点')
            expect(field(form, 'outbound_id')).to_have_value(str(socks_id))
            form.locator('summary').filter(has_text='高级连接配置').click()
            legacy = {'custom_outbounds': [{'tag': 'legacy-exit', 'protocol': 'socks', 'settings': {'server': '127.0.0.1', 'server_port': 1080}}]}
            field(form, 'advanced_json').fill(json.dumps(legacy))
            expect(form.get_by_role('alert').filter(has_text='高级路由配置冲突')).to_be_visible()
            assert field(form, 'outbound_id').evaluate('(el) => el.validity.customError')
            before = saves['node']
            form.get_by_role('button', name='保存节点', exact=True).click()
            assert saves['node'] == before, 'Conflicting routing was submitted'
            field(form, 'outbound_id').select_option('')
            action('server/manage/save', lambda: form.get_by_role('button', name='保存节点', exact=True).click())
            expect(page.get_by_text('出口：高级配置管理', exact=True)).to_be_visible()
            preserved = next(item for item in api('server/manage/getNodes') if item['id'] == saved_node['id'])
            assert preserved['outbound_id'] is None and preserved['custom_outbounds'] == legacy['custom_outbounds']
            page.get_by_role('button', name='编辑节点 出站验收入口', exact=True).click()
            form = dialog('编辑节点')
            expect(field(form, 'outbound_id').locator('option:checked')).to_have_text('不选托管出站（保留高级配置）')
            form.locator('summary').filter(has_text='高级连接配置').click()
            field(form, 'advanced_json').fill(json.dumps({'custom_outbounds': [], 'custom_routes': [], 'route_ids': []}))
            action('server/manage/save', lambda: form.get_by_role('button', name='保存节点', exact=True).click())
            expect(page.get_by_text('出口：直连', exact=True).first).to_be_visible()
            passed('高级路由冲突阻止提交，取消托管出站保留原配置，明确清空后才显示直连')

            stage = 'unreferenced-deletion'
            show_tab('出站')
            page.get_by_role('button', name='删除出站 ' + imported['socks']['name'], exact=True).click()
            form = dialog('删除出站')
            action('server/outbound/drop', lambda: form.get_by_role('button', name='删除出站', exact=True).click())
            assert all(item['id'] != socks_id for item in summary())
            passed('解除引用后可以删除出站')
            assert not report['page_errors'], 'Browser reported a JavaScript error'
            report['status'] = 'passed'
        except Exception as error:
            report['failure'] = {'stage': stage, 'type': type(error).__name__}
            if page:
                try:
                    screenshot('failure')
                except Exception:
                    pass
            raise
        finally:
            (artifacts / 'outbound-management-results.json').write_text(json.dumps(report, ensure_ascii=False, indent=2) + '\n')
            browser.close()


if __name__ == '__main__':
    main()
