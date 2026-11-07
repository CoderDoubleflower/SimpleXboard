#!/usr/bin/env python3
"""Exercise REALITY generators in a real non-secure browser origin.

All browser requests for the HTTP test origin are forwarded to run-stack.py's
disposable loopback application. This avoids external DNS while preserving the
browser's real isSecureContext=false / unavailable crypto.subtle behavior.
"""
import json
import os
from pathlib import Path
import re
import time
from urllib.parse import urlsplit

from playwright.sync_api import Error, expect, sync_playwright


PAIR = 'generateRealityKeyPair'
SHORT = 'generateShortId'
PAIR_LABEL = '一键生成密钥和 Short ID'
SHORT_LABEL = '生成 Short ID'
FIELDS = ('public_key', 'private_key', 'short_id')


def main():
    base = os.environ['E2E_BASE_URL'].rstrip('/')
    if os.environ.get('SIMPLEXBOARD_E2E') != '1' or urlsplit(base).hostname != '127.0.0.1':
        raise RuntimeError('Use the disposable run-stack.py application.')
    origin = 'http://simplexboard.test:' + str(urlsplit(base).port)
    admin_path = os.environ['E2E_ADMIN_PATH']
    artifacts = Path(os.environ['E2E_ARTIFACT_DIR'])
    artifacts.mkdir(parents=True, exist_ok=True)
    result = {'status': 'failed', 'origin': 'non-secure HTTP browser origin forwarded to loopback', 'checks': [], 'page_errors': []}
    generated_values = set()

    def passed(name):
        result['checks'].append(name)
        print('PASS ' + name, flush=True)

    try:
        with sync_playwright() as playwright:
            browser = playwright.chromium.launch(headless=True, executable_path=os.environ.get('E2E_CHROMIUM_PATH') or None, args=['--no-sandbox'])
            for width in (1440, 390):
                context = browser.new_context(viewport={'width': width, 'height': 1000 if width == 1440 else 844}, locale='zh-CN')
                state = {'fail': None, 'hold': None, 'pending': [], 'saves': 0}

                def forward(route):
                    operation = urlsplit(route.request.url).path.rsplit('/', 1)[-1]
                    if operation == 'save' and '/server/manage/' in route.request.url:
                        state['saves'] += 1
                    if operation == state['fail']:
                        route.fulfill(status=503, content_type='application/json', body=json.dumps({'message': '生成服务暂不可用，请重试。'}))
                        return
                    response = route.fetch(url=route.request.url.replace(origin, base, 1))
                    if operation == state['hold']:
                        state['pending'].append((route, response))
                    else:
                        route.fulfill(response=response)

                context.route(origin + '/**', forward)
                page = context.new_page()
                page.set_default_timeout(15000)
                page.on('pageerror', lambda error: result['page_errors'].append(str(error)))

                def values(form):
                    return {key: form.locator('[name="reality_' + key + '"]').input_value() for key in FIELDS}

                def valid(credentials):
                    assert all(re.fullmatch(r'[A-Za-z0-9_-]{43}', credentials[key]) for key in ('public_key', 'private_key')), 'Invalid generated key format'
                    assert re.fullmatch(r'[0-9a-f]{16}', credentials['short_id']), 'Invalid generated Short ID'

                def action(operation, control):
                    with page.expect_response(lambda response: response.request.method == 'POST' and urlsplit(response.url).path.endswith('/' + operation)) as waiting:
                        control.click()
                    response = waiting.value
                    assert response.ok, 'Generator or save request failed'
                    if operation in (PAIR, SHORT):
                        assert 'no-store' in response.headers.get('cache-control', ''), 'Generated secrets were cacheable'
                    data = response.json()['data']
                    if operation in (PAIR, SHORT):
                        generated_values.update(data.values())
                    return data

                def nodes():
                    token = page.evaluate("sessionStorage.getItem('simplexboard:admin-session')")
                    authorization = token if token.startswith('Bearer ') else 'Bearer ' + token
                    response = context.request.get(base + '/api/v2/' + admin_path + '/server/manage/getNodes', headers={'Authorization': authorization})
                    assert response.ok, 'Could not inspect saved test nodes'
                    return response.json()['data']

                def add_node():
                    page.get_by_role('button', name='添加节点', exact=True).first.click()
                    form = page.get_by_role('dialog', name='添加节点', exact=True)
                    expect(form).to_be_visible()
                    return form

                def generate(form):
                    generated = action(PAIR, form.get_by_role('button', name=PAIR_LABEL, exact=True))
                    expect(form.get_by_role('button', name=PAIR_LABEL, exact=True)).to_be_enabled()
                    assert values(form) == generated, 'Generated credentials were not filled together'
                    valid(generated)
                    return generated

                def hold_generation(form):
                    state['hold'] = PAIR
                    form.get_by_role('button', name=PAIR_LABEL, exact=True).click()
                    deadline = time.monotonic() + 10
                    while not state['pending'] and time.monotonic() < deadline:
                        page.wait_for_timeout(25)
                    assert state['pending'], 'Test did not intercept the pending generation response'
                    expect(form.get_by_role('button', name=PAIR_LABEL, exact=True)).to_be_disabled()
                    expect(form.get_by_role('button', name=SHORT_LABEL, exact=True)).to_be_disabled()
                    expect(form.locator('button[type="submit"]')).to_be_disabled()

                def release_cancelled():
                    state['hold'] = None
                    for route, response in state['pending']:
                        try:
                            route.fulfill(response=response)
                        except Error:
                            # Closing/switching the form deliberately aborts this request.
                            pass
                    state['pending'].clear()

                page.goto(origin + '/' + admin_path)
                assert page.evaluate('window.isSecureContext') is False, 'Test unexpectedly ran in a secure context'
                assert page.evaluate('typeof crypto.subtle') == 'undefined', 'Test did not exercise the original WebCrypto failure'
                page.get_by_label('管理员邮箱', exact=True).fill(os.environ['E2E_ADMIN_EMAIL'])
                page.get_by_label('密码', exact=True).fill(os.environ['E2E_ADMIN_PASSWORD'])
                page.get_by_role('button', name='登录管理台', exact=True).click()
                page.get_by_role('navigation', name='主导航').get_by_role('link', name='VPS 与节点', exact=True).click()
                expect(page.locator('#page-content .loading-state')).to_have_count(0)
                before = len(nodes())
                form = add_node()
                credentials = generate(form)
                assert len(nodes()) == before, 'Generating credentials saved an unfinished node'
                passed(f'{width}px：普通 HTTP、无 crypto.subtle 时一键填入公钥、私钥及 Short ID')

                new_id = action(SHORT, form.get_by_role('button', name=SHORT_LABEL, exact=True))['short_id']
                expect(form.get_by_role('button', name=SHORT_LABEL, exact=True)).to_be_enabled()
                assert new_id != credentials['short_id'], 'Short ID did not change'
                credentials['short_id'] = new_id
                assert values(form) == credentials, 'Independent Short ID generation changed the key pair'
                valid(credentials)
                passed(f'{width}px：单独生成 Short ID 保留密钥对，不提交节点')

                if width == 1440:
                    for operation, label in ((PAIR, PAIR_LABEL), (SHORT, SHORT_LABEL)):
                        state['fail'] = operation
                        form.get_by_role('button', name=label, exact=True).click()
                        expect(form.get_by_role('alert').filter(has_text='生成服务暂不可用，请重试。')).to_be_visible()
                        expect(form.get_by_role('button', name=label, exact=True)).to_be_enabled()
                        assert values(form) == credentials, 'Failed generation replaced existing values'
                        state['fail'] = None
                        if operation == PAIR:
                            credentials = generate(form)
                        else:
                            credentials['short_id'] = action(SHORT, form.get_by_role('button', name=SHORT_LABEL, exact=True))['short_id']
                            expect(form.get_by_role('button', name=SHORT_LABEL, exact=True)).to_be_enabled()
                            assert values(form) == credentials, 'Retry did not fill the Short ID'
                    passed('生成接口失败时保留原值、显示错误，恢复后可重试')

                    # Keep the whole form valid so requestSubmit cannot be stopped
                    # merely by missing required fields while a generation is pending.
                    form.locator('[name="name"]').fill('Pending generation probe')
                    form.locator('[name="host"]').fill('node.example.test')
                    form.locator('[name="reality_server_name"]').fill('www.example.com')
                    hold_generation(form)
                    count = state['saves']
                    form.locator('form').evaluate('(form) => form.requestSubmit()')
                    page.wait_for_timeout(100)
                    assert state['saves'] == count, 'Pending generation submitted an incomplete node'
                    form.get_by_label('安全方式', exact=True).select_option('1')
                    release_cancelled()
                    form.get_by_label('安全方式', exact=True).select_option('2')
                    assert values(form) == credentials, 'Cancelled request overwrote the current form'
                    expect(form.get_by_role('button', name=PAIR_LABEL, exact=True)).to_be_enabled()
                    passed('生成中阻止重复操作与提交，切换安全方式取消旧请求')

                    hold_generation(form)
                    form.get_by_label('协议', exact=True).select_option('hysteria')
                    release_cancelled()
                    form.get_by_label('协议', exact=True).select_option('vless')
                    assert all(value == '' for value in values(form).values()), 'Old response populated a replacement protocol form'
                    hold_generation(form)
                    form.get_by_role('button', name='取消', exact=True).click()
                    release_cancelled()
                    expect(page.locator('dialog[open]')).to_have_count(0)
                    form = add_node()
                    assert all(value == '' for value in values(form).values()), 'Old response populated a reopened dialog'
                    credentials = generate(form)
                    passed('切换协议或关闭窗口后，旧生成响应不会回填新表单')

                name = 'REALITY HTTP ' + str(width)
                form.locator('[name="name"]').fill(name)
                form.locator('[name="host"]').fill('node.example.test')
                form.locator('[name="reality_server_name"]').fill('www.example.com')
                form.get_by_role('button', name=SHORT_LABEL, exact=True).scroll_into_view_if_needed()
                page.screenshot(path=str(artifacts / f'reality-generator-{width}.png'), full_page=True,
                                mask=[form.locator('[name="reality_' + key + '"]') for key in FIELDS])
                action('save', form.get_by_role('button', name='创建节点', exact=True))
                expect(page.locator('dialog[open]')).to_have_count(0)
                record = next(node for node in nodes() if node['name'] == name)
                assert all(record['protocol_settings']['reality_settings'][key] == credentials[key] for key in FIELDS), 'Created credentials were not persisted'
                page.get_by_role('button', name='编辑节点 ' + name, exact=True).click()
                form = page.get_by_role('dialog', name='编辑节点', exact=True)
                assert values(form) == credentials, 'Opening an existing node changed its credentials'
                action(SHORT, form.get_by_role('button', name=SHORT_LABEL, exact=True))
                expect(form.get_by_role('button', name=SHORT_LABEL, exact=True)).to_be_enabled()
                form.get_by_role('button', name='取消', exact=True).click()
                page.get_by_role('button', name='编辑节点 ' + name, exact=True).click()
                form = page.get_by_role('dialog', name='编辑节点', exact=True)
                assert values(form) == credentials, 'Cancelled generation changed the saved node'
                previous = credentials
                credentials = generate(form)
                assert all(credentials[key] != previous[key] for key in FIELDS), 'Regeneration reused an existing credential'
                action('save', form.get_by_role('button', name='保存节点', exact=True))
                expect(page.locator('dialog[open]')).to_have_count(0)
                record = next(node for node in nodes() if node['name'] == name)
                assert all(record['protocol_settings']['reality_settings'][key] == credentials[key] for key in FIELDS), 'Edited credentials were not persisted'
                assert len(nodes()) == before + 1, 'Generator created extra nodes'
                passed(f'{width}px：创建与编辑保存、回读一致，取消不改已有配置')
                context.close()
            browser.close()
        assert not result['page_errors'], 'Browser raised a JavaScript error'
        result['status'] = 'passed'
    except Exception as error:
        result['error'] = str(error)
        for value in generated_values:
            result['error'] = result['error'].replace(value, '[generated value]')
        print(json.dumps({'status': 'failed', 'error': result['error']}), flush=True)
    finally:
        (artifacts / 'reality-generation-results.json').write_text(json.dumps(result, ensure_ascii=False, indent=2) + '\n')
    print(json.dumps({'status': result['status'], 'checks': len(result['checks'])}), flush=True)
    return 0 if result['status'] == 'passed' else 1


if __name__ == '__main__':
    raise SystemExit(main())
