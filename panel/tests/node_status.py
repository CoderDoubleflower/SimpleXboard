#!/usr/bin/env python3
"""Verify readiness UI using authenticated node reports on a disposable panel.

These reports exercise status rendering, not a real proxy kernel. The actual
node and REALITY data plane are covered by node_core_lifecycle.py.
"""
import json
import os
from pathlib import Path
from urllib.parse import urlencode, urlsplit

from playwright.sync_api import sync_playwright, expect
from websockets.sync.client import connect


def main():
    base = os.environ['E2E_BASE_URL'].rstrip('/')
    if os.environ.get('SIMPLEXBOARD_E2E') != '1' or urlsplit(base).hostname != '127.0.0.1':
        raise RuntimeError('Use the disposable run-stack.py environment.')
    fixture = json.loads(Path(os.environ['E2E_FIXTURE_FILE']).read_text())
    node = fixture['nodes'][0]
    artifacts = Path(os.environ['E2E_ARTIFACT_DIR'])
    result = {'status': 'failed', 'scope': 'authenticated reports and browser UI', 'checks': []}

    def passed(label):
        result['checks'].append(label)
        print('PASS ' + label, flush=True)

    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True, executable_path=os.environ.get('E2E_CHROMIUM_PATH') or None,
                                    args=['--no-sandbox'])
        context = browser.new_context(viewport={'width': 1440, 'height': 1000}, locale='zh-CN')
        page = context.new_page()
        page.set_default_timeout(15000)
        errors = []
        page.on('pageerror', lambda error: errors.append(str(error)))
        ws = None
        try:
            page.goto(base + '/' + os.environ['E2E_ADMIN_PATH'])
            page.get_by_label('管理员邮箱', exact=True).fill(os.environ['E2E_ADMIN_EMAIL'])
            page.get_by_label('密码', exact=True).fill(os.environ['E2E_ADMIN_PASSWORD'])
            page.get_by_role('button', name='登录管理台', exact=True).click()
            expect(page.get_by_role('navigation', name='主导航')).to_be_visible()
            token = page.evaluate("sessionStorage.getItem('simplexboard:admin-session')")
            headers = {'Authorization': token if token.startswith('Bearer ') else 'Bearer ' + token}
            admin = base + '/api/v2/' + os.environ['E2E_ADMIN_PATH'] + '/'

            def post(url, payload, authenticated=False):
                response = context.request.post(url, data=payload, headers=headers if authenticated else {})
                assert response.ok, 'Status test HTTP operation failed: ' + str(response.status)
                return response.json().get('data')

            def refresh():
                previous = page.locator('#page-content > [data-page]').element_handle()
                page.get_by_role('button', name='刷新', exact=True).click()
                if previous:
                    page.wait_for_function('(old) => !old.isConnected', arg=previous)
                expect(page.locator('#page-content .loading-state')).to_have_count(0)

            def nav(label):
                page.get_by_role('navigation', name='主导航').get_by_role('link', name=label, exact=True).click()
                expect(page.get_by_role('heading', name=label, exact=True)).to_be_visible()
                expect(page.locator('#page-content .loading-state')).to_have_count(0)

            def row():
                return page.get_by_role('row').filter(has=page.get_by_text(node['name'], exact=True))

            machine = post(admin + 'server/machine/save', {'name': '状态验收 VPS'}, True)
            post(admin + 'server/manage/update', {'id': node['id'], 'machine_id': machine['id']}, True)
            auth = {'machine_id': machine['id'], 'token': machine['token']}
            post(base + '/api/v2/server/machine/status', {**auth, 'cpu': 1, 'mem': {'total': 1024, 'used': 100}})
            ws = connect(os.environ['E2E_WS_URL'] + '?' + urlencode(auth), open_timeout=10)
            assert json.loads(ws.recv(timeout=10))['event'] == 'auth.success'
            report_url = base + '/api/v2/server/report?' + urlencode({**auth, 'node_id': node['id']})

            nav('VPS 与节点')
            expect(row().get_by_text('核心状态未知', exact=True)).to_be_visible()
            expect(row().get_by_text('机器在线 · WebSocket 在线', exact=True)).to_be_visible()
            assert row().locator('.badge-success').count() == 0
            passed('Machine and WebSocket heartbeats leave the core unknown')

            def report(state, ready=False, users=0, applied=0, error=None):
                post(report_url, {'metrics': {'core_status': {'state': state, 'ready': ready,
                    'users': users, 'applied_users': applied, 'error': error}}})
                refresh()

            report('waiting_users')
            expect(row().get_by_text('等待用户', exact=True)).to_be_visible()
            passed('Empty users are shown as waiting without a success badge')

            message = '监听失败：address already in use；<img src=x onerror=alert(1)> ' + '节点启动错误说明 ' * 12
            report('error', users=1, error=message)
            expect(row().get_by_text('核心异常', exact=True)).to_be_visible()
            row().get_by_text('查看核心错误', exact=True).click()
            expect(row().get_by_text(message, exact=True)).to_be_visible()
            assert row().locator('img').count() == 0
            passed('Core failures remain visible and error text cannot inject HTML')
            for width, height in [(1440, 1000), (375, 812)]:
                page.set_viewport_size({'width': width, 'height': height})
                row().scroll_into_view_if_needed()
                assert page.evaluate('document.documentElement.scrollWidth <= innerWidth + 1'), 'Status text overflowed the page'
                page.screenshot(path=str(artifacts / f'node-status-{width}.png'), full_page=True)
            passed('Long core errors remain readable at desktop and 375px widths')
            page.set_viewport_size({'width': 1440, 'height': 1000})

            report('running', ready=True, users=1, applied=0)
            expect(row().get_by_text('核心未就绪', exact=True)).to_be_visible()
            passed('A contradictory ready claim with unapplied users remains unready')
            report('running', ready=True, users=1, applied=1)
            expect(row().get_by_text('核心就绪', exact=True)).to_be_visible()
            expect(row().get_by_text('已应用 1 / 1 位用户', exact=True)).to_be_visible()
            passed('A successful applied report makes the core ready')

            ws.close()
            ws = None
            refresh()
            expect(row().get_by_text('机器在线 · WebSocket 断开', exact=True)).to_be_visible()
            expect(row().get_by_text('核心就绪', exact=True)).to_be_visible()
            passed('WebSocket disconnection does not overwrite a fresh core report')
            nav('概览')
            metric = page.locator('.metric').filter(has=page.get_by_text('就绪节点', exact=True))
            expect(metric.locator('.metric-value')).to_contain_text('1')
            expect(metric).to_contain_text('核心就绪 / 已启用')
            passed('Overview counts reported ready cores')
            assert not errors, 'Browser runtime errors were reported'
            result['status'] = 'passed'
        finally:
            if ws:
                ws.close()
            (artifacts / 'node-status-results.json').write_text(json.dumps(result, ensure_ascii=False, indent=2) + '\n')
            browser.close()


if __name__ == '__main__':
    main()
