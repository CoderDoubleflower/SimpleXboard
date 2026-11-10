#!/usr/bin/env python3
"""Exercise the real panel against tests/Support/run-stack.py's disposable stack.

Requires Python Playwright. Set E2E_CHROMIUM_PATH to use a system browser; when
absent, Playwright's installed Chromium is used. This test
never connects to a public or production instance and does not simulate VPS
installation or claim a successful proxy connection.
"""
import json
import os
from pathlib import Path
import re
import sys
import time
import traceback
from urllib.parse import urlsplit

from playwright.sync_api import sync_playwright, expect


def main():
    base = os.environ['E2E_BASE_URL'].rstrip('/')
    admin_path = os.environ['E2E_ADMIN_PATH'].strip('/')
    email = os.environ['E2E_ADMIN_EMAIL']
    password = os.environ['E2E_ADMIN_PASSWORD']
    if os.environ.get('SIMPLEXBOARD_E2E') != '1' or urlsplit(base).hostname not in ('127.0.0.1', 'localhost', '::1'):
        raise RuntimeError('Run through tests/Support/run-stack.py with a disposable local database.')
    artifacts = Path(os.environ.get('E2E_ARTIFACT_DIR', '/tmp/simplexboard-panel-e2e'))
    artifacts.mkdir(parents=True, exist_ok=True)
    prefix = 'UI验收' + str(int(time.time()))
    result = {'checks': [], 'screenshots': [], 'console_errors': [], 'page_errors': [], 'unexpected_http_errors': []}
    request_paths = set()
    expected_network_error = False

    def passed(name):
        result['checks'].append(name)
        print('PASS ' + name, flush=True)

    with sync_playwright() as playwright:
        executable = os.environ.get('E2E_CHROMIUM_PATH') or None
        browser = playwright.chromium.launch(headless=True, executable_path=executable, args=['--no-sandbox'])
        context = browser.new_context(viewport={'width': 1440, 'height': 1000}, locale='zh-CN', accept_downloads=True)
        context.grant_permissions(['clipboard-read', 'clipboard-write'], origin=base)
        page = context.new_page()
        page.set_default_timeout(15000)
        page.on('pageerror', lambda error: result['page_errors'].append(str(error)))

        def console(message):
            if message.type == 'error' and not expected_network_error and not str(message.location.get('url', '')).endswith('/favicon.ico'):
                result['console_errors'].append(message.text)

        def response_received(response):
            path = urlsplit(response.url).path
            if path.startswith('/api/'):
                request_paths.add(path)
                if response.status >= 400 and not expected_network_error:
                    result['unexpected_http_errors'].append({'path': path, 'status': response.status})

        page.on('console', console)
        page.on('response', response_received)

        def screenshot(name, mask=None):
            target = artifacts / (name + '.png')
            if name != 'failure':
                expect(page.locator('#page-content .loading-state')).to_have_count(0)
                expect(page.locator('.toast')).to_have_count(0, timeout=10000)
            page.screenshot(path=str(target), full_page=True, mask=mask or [])
            result['screenshots'].append(str(target))

        def login(current_password=password):
            page.get_by_label('管理员邮箱', exact=True).fill(email)
            page.get_by_label('密码', exact=True).fill(current_password)
            page.get_by_role('button', name='登录管理台', exact=True).click()
            expect(page.get_by_role('navigation', name='主导航')).to_be_visible()

        def nav(label):
            page.get_by_role('navigation', name='主导航').get_by_role('link', name=label, exact=True).click()
            expect(page.get_by_role('heading', name=label, exact=True)).to_be_visible()
            expect(page.locator('#page-content .loading-state')).to_have_count(0)

        def dialog(title=None):
            item = page.get_by_role('dialog', name=title, exact=True) if title else page.locator('dialog[open]').last
            expect(item).to_be_visible()
            return item

        def close_dialog():
            page.locator('dialog[open]').last.locator('.dialog-footer').get_by_role('button', name='关闭', exact=True).click()

        def action(endpoint, click, read=True):
            with page.expect_response(lambda response: urlsplit(response.url).path.endswith('/' + endpoint) and response.request.method == 'POST') as waiting:
                click()
            response = waiting.value
            assert response.ok, f'{endpoint}: HTTP {response.status}'
            if not read:
                return None
            payload = response.json()
            assert response.ok and payload.get('status') != 'fail', f'{endpoint}: HTTP {response.status}: {payload}'
            return payload.get('data', payload)

        def api(endpoint):
            token = page.evaluate("sessionStorage.getItem('simplexboard:admin-session')")
            assert token, 'Administrator session missing'
            token = token if token.startswith('Bearer ') else 'Bearer ' + token
            path = urlsplit(page.url).path.strip('/')
            response = context.request.get(f'{base}/api/v2/{path}/{endpoint}', headers={'Authorization': token, 'Accept': 'application/json'})
            assert response.ok, f'{endpoint}: HTTP {response.status}'
            payload = response.json()
            return payload.get('data', payload)

        def subscription(url, expected=200):
            response = context.request.get(url + ('&' if '?' in url else '?') + 'flag=mihomo')
            assert response.status == expected, f'Subscription expected {expected}, received {response.status}'
            return response.text() if expected == 200 else None

        def table_row(name):
            return page.locator('tbody tr').filter(has=page.get_by_text(name, exact=True))

        def certificate_form_checks():
            nonlocal expected_network_error
            name = prefix + ' · 证书校验'
            saves = []

            def track_save(request):
                if request.method == 'POST' and urlsplit(request.url).path.endswith('/server/manage/save'):
                    saves.append(request.url)

            def edit():
                expect(page.locator('#page-content .loading-state')).to_have_count(0)
                page.get_by_role('button', name='编辑节点 ' + name, exact=True).click()
                return dialog('编辑节点')

            def stored():
                return next(node for node in api('server/manage/getNodes') if node['name'] == name)

            def field(form, name):
                return form.locator('[name="' + name + '"]')

            page.on('request', track_save)
            page.get_by_role('button', name='添加节点', exact=True).first.click()
            form = dialog('添加节点')
            field(form, 'type').select_option('hysteria')
            field(form, 'name').fill(name)
            field(form, 'host').fill('hy.example.test')
            field(form, 'port').fill('24445')
            field(form, 'server_port').fill('24445')
            field(form, 'tls_server_name').fill('hy.example.test')
            field(form, 'tls_insecure').check()
            form.get_by_role('button', name='创建节点', exact=True).click()
            expect(field(form, 'cert_mode')).to_be_focused()
            expect(field(form, 'cert_mode')).to_have_attribute('aria-invalid', 'true')
            assert not saves, 'SNI and insecure mode bypassed the missing server certificate source'
            passed('Hysteria 开启跳过验证仍必须选择服务端证书，未发保存请求')

            field(form, 'cert_mode').select_option('file')
            field(form, 'cert_file').fill('/etc/xboard-node/certs/e2e-cert.pem')
            form.get_by_role('button', name='创建节点', exact=True).click()
            expect(field(form, 'cert_key_file')).to_be_focused()
            expect(field(form, 'cert_key_file')).to_have_attribute('aria-invalid', 'true')
            expect(form.locator('#error-cert_key_file')).to_contain_text('私钥文件路径')
            assert not saves, 'A certificate file without a private key reached the API'
            field(form, 'cert_key_file').fill('/etc/xboard-node/certs/e2e-key.pem')
            action('server/manage/save', lambda: form.get_by_role('button', name='创建节点', exact=True).click())
            expect(page.locator('dialog[open]')).to_have_count(0)
            assert stored()['cert_config'] == {'cert_mode': 'file', 'cert_file': '/etc/xboard-node/certs/e2e-cert.pem', 'key_file': '/etc/xboard-node/certs/e2e-key.pem'}
            form = edit()
            expect(field(form, 'cert_mode')).to_have_value('file')
            expect(field(form, 'cert_file')).to_have_value('/etc/xboard-node/certs/e2e-cert.pem')
            expect(field(form, 'cert_key_file')).to_have_value('/etc/xboard-node/certs/e2e-key.pem')
            assert 'cert_config' not in json.loads(field(form, 'advanced_json').input_value()), 'Certificate appeared in two editable sources'
            field(form, 'cert_mode').scroll_into_view_if_needed()
            screenshot('03-certificates-file-desktop')
            passed('文件证书缺私钥时定位字段，补全后持久化并正确回填编辑器')

            field(form, 'cert_mode').select_option('content')
            # Complete PEM boundaries pass the browser check; the real API must reject invalid DER.
            field(form, 'cert_content').fill('-----BEGIN CERTIFICATE-----\nY2VydA==\n-----END CERTIFICATE-----')
            field(form, 'cert_key_content').fill('-----BEGIN PRIVATE KEY-----\na2V5\n-----END PRIVATE KEY-----')
            expected_network_error = True
            try:
                with page.expect_response(lambda response: response.request.method == 'POST' and urlsplit(response.url).path.endswith('/server/manage/save')) as waiting:
                    form.get_by_role('button', name='保存节点', exact=True).click()
                assert waiting.value.status == 422, 'Malformed PEM was not rejected by the real certificate validator'
                expect(field(form, 'cert_content')).to_have_attribute('aria-invalid', 'true')
                expect(field(form, 'cert_content')).to_be_focused()
                expect(form.locator('#error-cert_content')).to_contain_text('证书')
                assert stored()['cert_config']['cert_mode'] == 'file', 'Rejected PEM changed the saved certificate'
            finally:
                expected_network_error = False
            passed('PEM 内容由真实后端解析，失败保留旧配置并映射到证书字段')

            field(form, 'cert_mode').select_option('dns')
            field(form, 'cert_domain').fill('hy.example.test')
            field(form, 'cert_dns_provider').select_option('cloudflare')
            field(form, 'cert_dns_env').fill('{}')
            before = len(saves)
            form.get_by_role('button', name='保存节点', exact=True).click()
            expect(field(form, 'cert_dns_env')).to_be_focused()
            expect(form.locator('#error-cert_dns_env')).to_contain_text('CLOUDFLARE_DNS_API_TOKEN')
            assert len(saves) == before, 'Missing DNS API token was submitted'
            field(form, 'cert_dns_env').fill('{"CF_API_TOKEN":"e2e-placeholder-token"}')
            action('server/manage/save', lambda: form.get_by_role('button', name='保存节点', exact=True).click())
            expect(page.locator('dialog[open]')).to_have_count(0)
            assert stored()['cert_config']['dns_env']['CF_API_TOKEN'] == 'e2e-placeholder-token'
            form = edit()
            expect(field(form, 'cert_dns_provider')).to_have_value('cloudflare')
            assert json.loads(field(form, 'cert_dns_env').input_value()) == {'CF_API_TOKEN': 'e2e-placeholder-token'}
            field(form, 'cert_dns_provider').select_option('route53')
            field(form, 'cert_dns_env').fill('{}')
            action('server/manage/save', lambda: form.get_by_role('button', name='保存节点', exact=True).click())
            expect(page.locator('dialog[open]')).to_have_count(0)
            assert stored()['cert_config']['dns_provider'] == 'route53'
            assert not stored()['cert_config'].get('dns_env'), 'Route 53 retained the previous provider token'
            passed('DNS 缺 token 时阻止保存、凭据别名回填、Route 53 空凭据环境身份配置')

            form = edit()
            page.set_viewport_size({'width': 390, 'height': 844})
            for mode in ['file', 'content', 'http', 'self', 'dns']:
                field(form, 'cert_mode').select_option(mode)
                field(form, 'cert_mode').scroll_into_view_if_needed()
                assert page.evaluate('document.documentElement.scrollWidth <= innerWidth + 1'), 'Certificate page overflow: ' + mode
                assert form.evaluate('(el) => el.scrollWidth <= el.clientWidth + 1'), 'Certificate dialog overflow: ' + mode
                expect(form.get_by_role('button', name='保存节点', exact=True)).to_be_in_viewport()
                if mode == 'http':
                    expect(field(form, 'cert_domain')).to_have_attribute('required', '')
                    expect(field(form, 'cert_http_port')).to_be_visible()
                if mode == 'self':
                    assert not field(form, 'cert_domain').evaluate('(input) => input.required')
            expect(field(form, 'cert_dns_provider')).to_have_value('route53')
            screenshot('03-certificates-dns-mobile', mask=[field(form, 'cert_dns_env'), field(form, 'cert_key_content')])
            page.keyboard.press('Escape')
            expect(page.locator('dialog[open]')).to_have_count(0)
            page.set_viewport_size({'width': 1440, 'height': 1000})
            page.remove_listener('request', track_save)
            page.get_by_role('button', name='删除节点 ' + name, exact=True).click()
            action('server/manage/drop', lambda: dialog('删除节点').get_by_role('button', name='删除节点', exact=True).click())
            expect(page.get_by_text(name, exact=True)).to_have_count(0)
            passed('390px 五种证书来源无横向溢出、字段按模式切换且保存按钮可达')

        try:
            page.goto(base + '/' + admin_path)
            expect(page.get_by_role('heading', name='登录管理台', exact=True)).to_be_visible()
            screenshot('01-login-desktop')
            login()
            links = page.get_by_role('navigation', name='主导航').get_by_role('link').all_text_contents()
            assert links == ['概览', 'VPS 与节点', '成员与订阅', '日志与备份', '设置'], links
            expect(page.locator('.metrics-grid .metric')).to_have_count(4)
            screenshot('02-overview-desktop')
            passed('管理员登录、五个一级菜单、真实概览')

            nav('VPS 与节点')
            certificate_form_checks()
            page.get_by_role('button', name='添加 VPS', exact=True).first.click()
            form = dialog('添加 VPS')
            machine_name = prefix + ' · 东京 VPS'
            form.locator('[name="name"]').fill(machine_name)
            form.locator('[name="notes"]').fill('隔离验收机器；未执行远程安装')
            machine = action('server/machine/save', lambda: form.get_by_role('button', name='创建 VPS', exact=True).click())
            form = dialog('接入 VPS · ' + machine_name)
            assert form.get_by_label('接入命令', exact=True).input_value()
            first_machine_token = form.get_by_label('机器令牌', exact=True).input_value()
            assert first_machine_token == machine['token']
            form.get_by_role('button', name='复制接入命令', exact=True).click()
            clipboard = page.evaluate('navigator.clipboard.readText()')
            assert machine['token'] in clipboard
            close_dialog()
            page.get_by_role('tab', name=re.compile(r'^VPS ')).click()
            expect(page.get_by_role('heading', name=machine_name, exact=True)).to_be_visible()
            page.get_by_role('button', name='编辑 VPS ' + machine_name, exact=True).click()
            form = dialog('编辑 VPS')
            form.get_by_label('备注', exact=True).fill('已验证可编辑的机器备注')
            action('server/machine/save', lambda: form.get_by_role('button', name='保存更改', exact=True).click())
            expect(page.get_by_text('已验证可编辑的机器备注', exact=True)).to_be_visible()
            page.get_by_role('button', name='轮换 ' + machine_name + ' 的机器令牌', exact=True).click()
            action('server/machine/resetToken', lambda: dialog('轮换机器令牌').get_by_role('button', name='确认轮换', exact=True).click())
            form = dialog('接入 VPS · ' + machine_name)
            assert form.get_by_label('机器令牌', exact=True).input_value() != first_machine_token
            close_dialog()
            passed('VPS 创建、编辑、真实接入命令复制和令牌轮换')

            node_names = []
            for protocol in ['vless', 'hysteria']:
                page.get_by_role('button', name='添加节点', exact=True).first.click()
                form = dialog('添加节点')
                name = prefix + (' · REALITY' if protocol == 'vless' else ' · Hysteria2')
                node_names.append(name)
                form.get_by_label('协议', exact=True).select_option(protocol)
                form.locator('[name="name"]').fill(name)
                form.locator('[name="host"]').fill('node.example.test')
                form.locator('[name="port"]').fill('24443' if protocol == 'vless' else '24444')
                form.locator('[name="server_port"]').fill('24443' if protocol == 'vless' else '24444')
                form.get_by_label('绑定 VPS', exact=True).select_option(str(machine['id']))
                expect(form.locator('[name="rate"]')).to_have_value('1')
                if protocol == 'vless':
                    form.locator('[name="reality_server_name"]').fill('www.example.com')
                    generated = action('server/manage/generateRealityKeyPair', lambda: form.get_by_role('button', name='一键生成密钥和 Short ID', exact=True).click())
                    expect(form.locator('[name="reality_public_key"]')).to_have_value(re.compile(r'^[A-Za-z0-9_-]{43}$'))
                    assert len(form.locator('[name="reality_private_key"]').input_value()) == 43
                    expect(form.locator('[name="reality_short_id"]')).to_have_value(re.compile(r'^[0-9a-f]{16}$'))
                    assert form.locator('[name="reality_public_key"]').input_value() == generated['public_key']
                    assert form.locator('[name="reality_private_key"]').input_value() == generated['private_key']
                    assert form.locator('[name="reality_short_id"]').input_value() == generated['short_id']
                else:
                    form.locator('[name="rate"]').fill('2')
                    form.locator('[name="tls_server_name"]').fill('hy.example.test')
                    form.locator('[name="cert_mode"]').select_option('self')
                    form.locator('[name="cert_domain"]').fill('hy.example.test')
                    form.get_by_label('启用 Salamander 混淆', exact=True).check()
                    form.get_by_label('混淆密码', exact=True).fill('test-obfs-private-key')
                action('server/manage/save', lambda: form.get_by_role('button', name='创建节点', exact=True).click())
                expect(page.locator('dialog[open]')).to_have_count(0)
            page.get_by_role('tab', name=re.compile(r'^节点 ')).click()
            expect(page.get_by_text(node_names[0], exact=True)).to_be_visible()
            expect(page.get_by_text(node_names[1], exact=True)).to_be_visible()
            page.get_by_label('搜索节点', exact=True).press_sequentially('REALITY')
            expect(page.get_by_label('搜索节点', exact=True)).to_be_focused()
            expect(page.get_by_label('搜索节点', exact=True)).to_have_value('REALITY')
            page.get_by_label('搜索节点', exact=True).fill('')
            saved_nodes = api('server/manage/getNodes')
            owned_nodes = [node for node in saved_nodes if node['name'] in node_names]
            assert len(owned_nodes) == 2
            reality = next(node for node in owned_nodes if node['type'] == 'vless')
            hy2 = next(node for node in owned_nodes if node['type'] == 'hysteria')
            assert reality['machine_id'] == machine['id']
            assert float(reality['rate']) == 1
            assert float(hy2['rate']) == 2
            assert reality['protocol_settings']['tls'] == 2
            for key in ['public_key', 'private_key', 'short_id']:
                assert reality['protocol_settings']['reality_settings'][key] == generated[key], 'Generated REALITY credentials did not persist'
            assert hy2['protocol_settings']['version'] == 2
            assert hy2['protocol_settings']['obfs']['open'] is True
            expect(table_row(node_names[0])).to_contain_text('额度倍率 1×')
            expect(table_row(node_names[1])).to_contain_text('额度倍率 2×')
            page.get_by_role('button', name='编辑节点 ' + node_names[0], exact=True).click()
            form = dialog('编辑节点')
            expect(form.locator('[name="reality_server_name"]')).to_have_value('www.example.com')
            rate = form.locator('[name="rate"]')
            assert float(rate.input_value()) == 1
            invalid_submissions = []

            def track_invalid_submission(request):
                if request.method == 'POST' and urlsplit(request.url).path.endswith('/server/manage/save'):
                    invalid_submissions.append(request.url)

            page.on('request', track_invalid_submission)
            for invalid in ['-0.01', '100.01', '0.001', '']:
                rate.fill(invalid)
                assert not rate.evaluate('(input) => input.checkValidity()'), f'Invalid rate accepted: {invalid}'
                form.get_by_role('button', name='保存节点', exact=True).click()
                expect(form).to_be_visible()
            page.remove_listener('request', track_invalid_submission)
            assert not invalid_submissions, 'An invalid rate was sent to the node API'
            rate.fill('.5')
            form.get_by_label('标签', exact=True).fill('private, tokyo')
            action('server/manage/save', lambda: form.get_by_role('button', name='保存节点', exact=True).click())
            expect(table_row(node_names[0])).to_contain_text('额度倍率 0.5×')
            for previous, value, label in [(0.5, '0', '额度倍率 0× · 不扣额度'), (0, '0.5', '额度倍率 0.5×')]:
                page.get_by_role('button', name='编辑节点 ' + node_names[0], exact=True).click()
                form = dialog('编辑节点')
                assert float(form.locator('[name="rate"]').input_value()) == previous
                form.locator('[name="rate"]').fill(value)
                action('server/manage/save', lambda: form.get_by_role('button', name='保存节点', exact=True).click())
                expect(table_row(node_names[0])).to_contain_text(label)
            page.get_by_role('button', name='编辑节点 ' + node_names[1], exact=True).click()
            assert float(dialog('编辑节点').locator('[name="rate"]').input_value()) == 2
            page.keyboard.press('Escape')
            expect(page.locator('dialog[open]')).to_have_count(0)
            screenshot('03-nodes-desktop')
            passed('VLESS/REALITY 与 Hysteria2 创建、密钥生成、持久化与编辑')
            passed('节点倍率默认 1×、0.5×/2×/0×保存回读和列表展示，拒绝非法范围与精度')

            nav('成员与订阅')
            member_name = prefix + ' · 家人'
            page.get_by_role('button', name='添加成员', exact=True).click()
            form = dialog('添加成员')
            assert form.locator('input[type="password"]').count() == 0
            form.get_by_label('成员名称', exact=True).fill(member_name)
            created = action('user/create', lambda: form.get_by_role('button', name='创建成员', exact=True).click())
            assert created['transfer_enable'] is None and created['expired_at'] is None and created['access_mode'] == 'all'
            form = dialog('成员订阅')
            subscribe_url = form.get_by_label('订阅地址', exact=True).input_value()
            assert subscribe_url == created['subscribe_url'] + ('&' if '?' in created['subscribe_url'] else '?') + 'flag=mihomo'
            form.get_by_label('客户端格式', exact=True).select_option('mihomo')
            form.get_by_role('button', name='复制订阅链接', exact=True).click()
            assert 'flag=mihomo' in page.evaluate('navigator.clipboard.readText()')
            close_dialog()
            expect(table_row(member_name)).to_contain_text('实际 0 B')
            expect(table_row(member_name)).to_contain_text('额度已用 0 B · 不限额度')
            document = json.dumps(subscription(subscribe_url), ensure_ascii=False)
            assert all(name in document for name in node_names)
            passed('成员无密码创建、默认不限/永久/共享、专属订阅复制')

            nav('VPS 与节点')
            action('server/manage/update', lambda: page.get_by_role('switch', name='在订阅中展示 ' + node_names[0], exact=True).click())
            expect(page.get_by_role('switch', name='在订阅中展示 ' + node_names[0], exact=True)).to_have_attribute('aria-checked', 'false')
            assert node_names[0] not in json.dumps(subscription(subscribe_url), ensure_ascii=False)
            action('server/manage/update', lambda: page.get_by_role('switch', name='在订阅中展示 ' + node_names[0], exact=True).click())
            action('server/manage/update', lambda: page.get_by_role('switch', name='启用节点 ' + node_names[1], exact=True).click())
            expect(page.get_by_role('switch', name='启用节点 ' + node_names[1], exact=True)).to_have_attribute('aria-checked', 'false')
            assert node_names[1] not in json.dumps(subscription(subscribe_url), ensure_ascii=False)
            action('server/manage/update', lambda: page.get_by_role('switch', name='启用节点 ' + node_names[1], exact=True).click())
            passed('节点隐藏和停用分别影响真实订阅内容')

            nav('成员与订阅')
            page.get_by_role('button', name='编辑 ' + member_name, exact=True).click()
            form = dialog('编辑成员')
            form.get_by_label('节点访问范围', exact=True).select_option('restricted')
            form.locator(f'#member-node-{reality["id"]}').check()
            form.get_by_label('流量硬上限（GiB）', exact=True).fill('0')
            changed = action('user/update', lambda: form.get_by_role('button', name='保存更改', exact=True).click())
            assert changed['server_ids'] == [reality['id']] and changed['transfer_enable'] == 0
            expect(table_row(member_name)).to_contain_text('额度用尽')
            subscription(subscribe_url, 403)
            page.get_by_role('button', name='编辑 ' + member_name, exact=True).click()
            form = dialog('编辑成员')
            form.get_by_label('流量硬上限（GiB）', exact=True).fill('')
            action('user/update', lambda: form.get_by_role('button', name='保存更改', exact=True).click())
            document = json.dumps(subscription(subscribe_url), ensure_ascii=False)
            assert node_names[0] in document and node_names[1] not in document
            action('user/ban', lambda: page.get_by_role('switch', name='启用成员 ' + member_name, exact=True).click())
            expect(page.get_by_role('switch', name='启用成员 ' + member_name, exact=True)).to_have_attribute('aria-checked', 'false')
            subscription(subscribe_url, 403)
            action('user/ban', lambda: page.get_by_role('switch', name='启用成员 ' + member_name, exact=True).click())
            subscription(subscribe_url)
            page.get_by_role('button', name='查看 ' + member_name + ' 的订阅', exact=True).click()
            dialog('成员订阅').get_by_role('button', name='轮换凭据', exact=True).click()
            rotated = action('user/resetSecret', lambda: dialog('轮换成员凭据').get_by_role('button', name='确认轮换', exact=True).click())
            expect(dialog('成员订阅').get_by_label('订阅地址', exact=True)).to_have_value(rotated['subscribe_url'] + ('&' if '?' in rotated['subscribe_url'] else '?') + 'flag=mihomo')
            assert rotated['token'] != created['token'] and rotated['uuid'] != created['uuid']
            close_dialog()
            subscription(subscribe_url, 403)
            subscription(rotated['subscribe_url'])
            with page.expect_download() as waiting:
                page.get_by_role('button', name='导出全部', exact=True).click()
            waiting.value.save_as(str(artifacts / 'members.csv'))
            assert member_name in (artifacts / 'members.csv').read_text()
            screenshot('04-members-desktop')
            passed('独立节点授权、零额度、成员停启、凭据轮换、旧链接失效与 CSV 导出')

            nav('日志与备份')
            expect(page.locator('tbody tr').first).to_be_visible()
            page.get_by_role('tab', name='失败任务', exact=True).click()
            expect(page.get_by_role('heading', name='暂无失败任务', exact=True)).to_be_visible()
            page.get_by_role('tab', name='数据库备份', exact=True).click()
            backup = action('backup/create', lambda: page.get_by_role('button', name='立即备份', exact=True).click())
            expect(page.get_by_text(backup['file']['name'], exact=True)).to_be_visible()
            with page.expect_download() as waiting:
                page.get_by_role('button', name='下载', exact=True).first.click()
            backup_path = artifacts / waiting.value.suggested_filename
            waiting.value.save_as(str(backup_path))
            assert backup_path.stat().st_size > 0
            assert backup_path.read_bytes()[:2] == b'\x1f\x8b'
            expect(page.get_by_role('heading', name='恢复说明', exact=True)).to_be_visible()
            screenshot('05-backups-desktop')
            passed('审计记录、失败任务空状态、真实备份创建与授权下载')

            nav('设置')
            original_name = page.locator('[name="app_name"]').input_value()
            # Verify safe bootstrap with HTML-sensitive content; title is text, never markup.
            special_name = '私有网络 </script><svg onload=alert(1)>'
            page.locator('[name="app_name"]').fill(special_name)
            action('config/save', lambda: page.get_by_role('button', name='保存设置', exact=True).click())
            page.reload()
            expect(page.locator('[name="app_name"]')).to_have_value(special_name)
            assert page.locator('svg[onload]').count() == 0
            assert page.evaluate("JSON.parse(document.getElementById('panel-bootstrap').textContent).title") == special_name
            page.locator('[name="app_name"]').fill(original_name)
            action('config/save', lambda: page.get_by_role('button', name='保存设置', exact=True).click())
            page.get_by_role('tab', name='订阅模板', exact=True).click()
            expect(page.get_by_label('分流预设', exact=True)).to_be_visible()
            expect(page.get_by_label('客户端格式', exact=True)).to_have_value('mihomo')
            action('subscription-template/save', lambda: page.get_by_role('button', name='保存当前模板', exact=True).click())
            page.get_by_role('tab', name='管理员安全', exact=True).click()
            expect(page.get_by_text('当前会话', exact=True)).to_be_visible()
            new_path = 'private-panel-e2e'
            page.get_by_label('后台访问路径', exact=True).fill(new_path)
            action('config/save', lambda: page.get_by_role('button', name='保存安全设置', exact=True).click(), read=False)
            page.wait_for_url(base + '/' + new_path + '#/settings?tab=security')
            expect(page.get_by_label('后台访问路径', exact=True)).to_have_value(new_path)
            page.get_by_label('后台访问路径', exact=True).fill(admin_path)
            action('config/save', lambda: page.get_by_role('button', name='保存安全设置', exact=True).click(), read=False)
            page.wait_for_url(base + '/' + admin_path + '#/settings?tab=security')
            expect(page.get_by_label('后台访问路径', exact=True)).to_have_value(admin_path)
            screenshot('06-security-desktop')
            passed('设置保存、模板保存、安全 JSON bootstrap 与后台路径迁移')

            new_password = password + '-updated'
            page.locator('[name="old_password"]').fill(password)
            page.locator('[name="new_password"]').fill(new_password)
            page.locator('[name="new_password_confirmation"]').fill(new_password)
            action('auth/password', lambda: page.get_by_role('button', name='更新密码并退出登录', exact=True).click())
            expect(page.get_by_role('heading', name='登录管理台', exact=True)).to_be_visible()
            login(new_password)
            nav('设置')
            page.get_by_role('tab', name='管理员安全', exact=True).click()
            page.locator('[name="old_password"]').fill(new_password)
            page.locator('[name="new_password"]').fill(password)
            page.locator('[name="new_password_confirmation"]').fill(password)
            action('auth/password', lambda: page.get_by_role('button', name='更新密码并退出登录', exact=True).click())
            login()
            nav('设置')
            page.get_by_role('tab', name='管理员安全', exact=True).click()
            page.get_by_role('button', name='撤销全部会话', exact=True).click()
            action('auth/revokeSessions', lambda: dialog('撤销全部管理员会话').get_by_role('button', name='撤销并退出', exact=True).click())
            expect(page.get_by_role('heading', name='登录管理台', exact=True)).to_be_visible()
            login()
            passed('管理员密码更新撤销会话、重新登录与全部会话撤销')

            page.set_viewport_size({'width': 390, 'height': 844})
            for label in links:
                nav(label)
                overflow = page.evaluate('document.documentElement.scrollWidth > window.innerWidth + 1')
                assert not overflow, f'Mobile horizontal overflow: {label}'
                clipped = page.evaluate('''() => [...document.querySelectorAll('td.actions-cell .icon-button')].some(button => {
                    const bounds = button.getBoundingClientRect();
                    const cell = button.closest('td').getBoundingClientRect();
                    return bounds.width > 0 && (bounds.left < cell.left - 1 || bounds.right > cell.right + 1);
                })''')
                assert not clipped, f'Mobile row action is clipped: {label}'
                if label in ('概览', '成员与订阅', 'VPS 与节点'):
                    screenshot('07-mobile-' + {'概览': 'overview', '成员与订阅': 'members', 'VPS 与节点': 'nodes'}[label])
            nav('成员与订阅')
            page.get_by_role('button', name='查看 ' + member_name + ' 的订阅', exact=True).click()
            expect(dialog('成员订阅').get_by_label('订阅地址', exact=True)).to_have_value(rotated['subscribe_url'] + ('&' if '?' in rotated['subscribe_url'] else '?') + 'flag=mihomo')
            close_dialog()
            page.get_by_role('button', name='添加成员', exact=True).click()
            form = dialog('添加成员')
            expect(form.get_by_label('成员名称', exact=True)).to_be_focused()
            screenshot('08-mobile-member-form')
            page.keyboard.press('Escape')
            expect(page.locator('dialog[open]')).to_have_count(0)
            passed('390px 移动端五页布局、键盘焦点与模态关闭')

            page.set_viewport_size({'width': 1440, 'height': 1000})
            expected_network_error = True
            page.route('**/user/fetch?*', lambda route: route.abort())
            page.get_by_role('button', name='刷新', exact=True).click()
            expect(page.get_by_text('无法连接到服务器，请检查网络后重试。', exact=True)).to_be_visible()
            screenshot('09-network-error-state')
            page.unroute('**/user/fetch?*')
            page.get_by_role('button', name='重新加载', exact=True).click()
            expect(page.get_by_text(member_name, exact=True)).to_be_visible()
            expected_network_error = False
            passed('请求失败显示真实错误并可重试恢复（故障注入）')

            page.get_by_role('button', name='删除 ' + member_name, exact=True).click()
            action('user/destroy', lambda: dialog('删除成员').get_by_role('button', name='删除成员', exact=True).click())
            expect(page.get_by_text(member_name, exact=True)).to_have_count(0)
            subscription(rotated['subscribe_url'], 403)
            nav('VPS 与节点')
            for name in node_names:
                page.get_by_role('button', name='删除节点 ' + name, exact=True).click()
                action('server/manage/drop', lambda: dialog('删除节点').get_by_role('button', name='删除节点', exact=True).click())
                expect(page.get_by_text(name, exact=True)).to_have_count(0)
            page.get_by_role('tab', name=re.compile(r'^VPS ')).click()
            page.get_by_role('button', name='删除 VPS ' + machine_name, exact=True).click()
            action('server/machine/drop', lambda: dialog('删除 VPS 记录').get_by_role('button', name='删除记录', exact=True).click())
            expect(page.get_by_role('heading', name=machine_name, exact=True)).to_have_count(0)
            expect(page.get_by_role('heading', name='接入第一台 VPS', exact=True)).to_be_visible()
            screenshot('10-machines-empty-state')
            action('auth/logout', lambda: page.get_by_role('button', name='退出', exact=True).click())
            expect(page.get_by_role('heading', name='登录管理台', exact=True)).to_be_visible()
            passed('删除成员使订阅失效、节点/VPS 删除与管理员退出')

            forbidden = re.compile(r'/(order|payment|coupon|giftcard|invite|ticket|plan|theme|plugin)(/|\?|$)', re.I)
            assert not [path for path in request_paths if forbidden.search(path)]
            assert not result['page_errors'], result['page_errors']
            assert not result['console_errors'], result['console_errors']
            assert not result['unexpected_http_errors'], result['unexpected_http_errors']
            passed('无商业端点请求、无 JavaScript 异常、无意外 HTTP 错误')
            result['status'] = 'passed'
        except Exception as error:
            result['status'] = 'failed'
            result['error'] = str(error)
            result['traceback'] = traceback.format_exc()
            try:
                screenshot('failure')
                (artifacts / 'failure-dom.txt').write_text(page.locator('body').inner_text())
            except Exception:
                pass
            raise
        finally:
            result['requested_api_paths'] = sorted(request_paths)
            (artifacts / 'panel-e2e-report.json').write_text(json.dumps(result, indent=2, ensure_ascii=False))
            browser.close()
    print(json.dumps({'status': result['status'], 'checks': len(result['checks']), 'screenshots': len(result['screenshots']), 'report': str(artifacts / 'panel-e2e-report.json')}, ensure_ascii=False), flush=True)
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
