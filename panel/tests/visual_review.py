#!/usr/bin/env python3
"""Check responsive interaction on run-stack.py's disposable, real application.

Use the existing Python Playwright installation and E2E_CHROMIUM_PATH. No image
baselines or external services are required. Screenshots mask credentials; the
report contains geometry and check names, never response bodies or DOM dumps.
"""
import json
import os
from pathlib import Path
import re
from urllib.parse import urlsplit

from playwright.sync_api import expect, sync_playwright


VIEWPORTS = ((1440, 1000), (1024, 900), (768, 1024), (375, 812), (390, 844), (844, 390))
PAGES = (('overview', '概览'), ('nodes', 'VPS 与节点'), ('members', '成员与订阅'),
         ('logs', '日志与备份'), ('settings', '设置'))
LONG_CN = '视觉检查 · 跨地区共享与多设备长期备用连接的较长中文名称'
LONG_LATIN = 'RegionalFallbackConnectionForPrivateNetworkAndSharedDevicesLongName'
LONG_HOST = 'private-network-' + 'regional-' * 4 + 'node.example.test'
TAGS = ['家庭共享', '备用连接', '较长的中文标签也应保留完整内容', 'tokyo', 'singapore', 'PrivateNetworkFallback']
MASK = ('input[type="password"],input[type="email"],.secret-input input,'
        '[name*="token" i],[name*="password" i],[name*="private_key" i],'
        '[name*="public_key" i],[name*="short_id" i],[name*="uuid" i],'
        'textarea,pre,code,.admin-profile h3,.account-label')
FOCUSABLE = 'a[href],button,input:not([type="hidden"]),select,textarea,summary,[tabindex]'


class CheckFailed(Exception):
    pass


def require(condition, message):
    if not condition:
        raise CheckFailed(message)


def main():
    base = os.environ['E2E_BASE_URL'].rstrip('/')
    require(os.environ.get('SIMPLEXBOARD_E2E') == '1' and urlsplit(base).hostname == '127.0.0.1',
            'Use tests/Support/run-stack.py with its disposable loopback database.')
    admin_path = os.environ['E2E_ADMIN_PATH'].strip('/')
    artifacts = Path(os.environ['E2E_ARTIFACT_DIR'])
    artifacts.mkdir(parents=True, exist_ok=True)
    fixture = json.loads(Path(os.environ['E2E_FIXTURE_FILE']).read_text())
    result = {'status': 'failed', 'scope': 'disposable-loopback', 'checks': [], 'screenshots': [],
              'viewports': [], 'page_errors': [], 'http_errors': []}
    secrets = {os.environ['E2E_ADMIN_EMAIL'], os.environ['E2E_ADMIN_PASSWORD']}
    stage = 'startup'
    page = None

    def remember(value, key=''):
        if isinstance(value, dict):
            for name, item in value.items():
                remember(item, name)
        elif isinstance(value, list):
            for item in value:
                remember(item, key)
        elif isinstance(value, str) and value and re.search(r'token|key|password|uuid|short_id|auth_data|subscribe_url', key, re.I):
            secrets.add(value)

    def redact(value):
        text = str(value)
        for secret in sorted(secrets, key=len, reverse=True):
            text = text.replace(secret, '[redacted]')
        return text

    def passed(name):
        result['checks'].append(name)
        print('PASS ' + name, flush=True)

    def screenshot(name):
        target = artifacts / ('visual-' + name + '.png')
        if name != 'failure':
            expect(page.locator('#page-content .loading-state')).to_have_count(0)
        page.screenshot(path=str(target), full_page=False, animations='disabled',
                        mask=[page.locator(MASK)], mask_color='#94a3b8')
        result['screenshots'].append({'file': target.name, 'viewport': page.viewport_size, 'credentials_masked': True})

    def no_overflow(label):
        geometry = page.evaluate('''() => ({
            viewport: innerWidth, document: document.documentElement.scrollWidth,
            body: document.body.scrollWidth,
            main: document.querySelector('#page-content')?.getBoundingClientRect().toJSON()
        })''')
        require(max(geometry['document'], geometry['body']) <= geometry['viewport'] + 1,
                label + ': page overflows horizontally ' + json.dumps(geometry))
        if geometry['main']:
            require(geometry['main']['left'] >= -1 and geometry['main']['right'] <= geometry['viewport'] + 1,
                    label + ': main content is clipped horizontally')
        dialog = page.locator('dialog[open]')
        if dialog.count():
            require(dialog.evaluate('(el) => el.scrollWidth <= el.clientWidth + 1'),
                    label + ': dialog overflows horizontally')

    def reachable(control, label, touch=False, focus=False):
        expect(control).to_be_visible()
        if not focus:
            control.scroll_into_view_if_needed()
        measured = control.evaluate('''el => {
            const r = el.getBoundingClientRect();
            const x = (Math.max(0, r.left) + Math.min(innerWidth, r.right)) / 2;
            const y = (Math.max(0, r.top) + Math.min(innerHeight, r.bottom)) / 2;
            const hit = document.elementFromPoint(x, y);
            const style = getComputedStyle(el);
            return {width:r.width, height:r.height, left:r.left, right:r.right,
                viewport:innerWidth, hit:!!hit && (hit === el || el.contains(hit)),
                focused:document.activeElement === el, focusVisible:el.matches(':focus-visible'),
                outline:parseFloat(style.outlineWidth) > 0 && style.outlineStyle !== 'none'
                    && style.outlineColor !== 'rgba(0, 0, 0, 0)'};
        }''')
        require(measured['left'] >= -1 and measured['right'] <= measured['viewport'] + 1 and measured['hit'],
                label + ': control is clipped or covered ' + json.dumps(measured))
        if touch:
            require(measured['width'] >= 43.5 and measured['height'] >= 43.5,
                    label + ': touch target is smaller than 44 CSS px ' + json.dumps(measured))
        if focus:
            require(measured['focused'] and measured['focusVisible'] and measured['outline'],
                    label + ': keyboard focus has no visible indicator ' + json.dumps(measured))

    def ready():
        expect(page.locator('#page-content .loading-state')).to_have_count(0)
        expect(page.locator('#page-content [role="alert"]')).to_have_count(0)

    def nav(label):
        link = page.get_by_role('navigation', name='主导航').get_by_role('link', name=label, exact=True)
        previous = page.locator('#page-content > [data-page]').element_handle()
        if urlsplit(page.url).fragment == link.get_attribute('href').lstrip('#'):
            page.get_by_role('button', name='刷新', exact=True).click()
        else:
            link.click()
        if previous:
            page.wait_for_function('(old) => !old.isConnected', arg=previous)
        expect(page.get_by_role('heading', name=label, exact=True)).to_be_visible()
        expect(link).to_have_attribute('aria-current', 'page')
        ready()
        if label == '概览':
            expect(page.locator('.metrics-grid .metric')).to_have_count(4)

    def skip_link():
        skip = page.get_by_role('link', name='跳至主要内容', exact=True)
        first = page.locator(FOCUSABLE).evaluate_all('''elements => elements.find(el =>
            el.tabIndex >= 0 && !el.disabled && el.getClientRects().length)?.textContent.trim()''')
        require(first == '跳至主要内容', 'Skip link is not the first tab stop')
        skip.focus()
        page.keyboard.press('Tab')
        page.keyboard.press('Shift+Tab')
        expect(skip).to_be_focused()
        reachable(skip, 'Skip link', focus=True)
        original_url = page.url
        page.keyboard.press('Enter')
        expect(page.locator('#page-content')).to_be_focused()
        require(page.url == original_url, 'Skip link changed the current SPA route')

    def dialog_keyboard(dialog, opener):
        # Exercise native Tab wrapping in both directions, then restore the opener.
        controls = dialog.locator(FOCUSABLE)
        indices = controls.evaluate_all('''elements => elements.flatMap((el,index) =>
            el.tabIndex >= 0 && !el.disabled && el.getClientRects().length ? [index] : [])''')
        require(len(indices) > 2, 'Dialog has no usable keyboard controls')
        first, last = controls.nth(indices[0]), controls.nth(indices[-1])

        def tab(key):
            page.keyboard.press(key)
            # Native Chromium may traverse browser chrome (reported as BODY)
            # before re-entering a modal. Background app controls must stay inert.
            if page.evaluate('document.activeElement === document.body'):
                page.keyboard.press(key)
            require(dialog.evaluate('(el) => el.contains(document.activeElement)'),
                    'Tab moved focus to a background application control')

        last.focus()
        tab('Tab')
        expect(first).to_be_focused()
        reachable(first, 'Dialog first control', focus=True)
        tab('Shift+Tab')
        expect(last).to_be_focused()
        reachable(last, 'Dialog last control', focus=True)
        page.keyboard.press('Escape')
        expect(page.locator('dialog[open]')).to_have_count(0)
        expect(opener).to_be_focused()

    remember(fixture)
    playwright = sync_playwright().start()
    browser = None
    try:
        browser = playwright.chromium.launch(headless=True, executable_path=os.environ.get('E2E_CHROMIUM_PATH') or None,
                                              args=['--no-sandbox'])
        context = browser.new_context(viewport={'width': 1440, 'height': 1000}, locale='zh-CN')
        page = context.new_page()
        page.set_default_timeout(15000)
        page.on('pageerror', lambda error: result['page_errors'].append(redact(error)))

        def response_received(response):
            if response.status >= 400 and urlsplit(response.url).path.startswith('/api/'):
                result['http_errors'].append({'path': urlsplit(response.url).path, 'status': response.status})

        page.on('response', response_received)
        page.goto(base + '/' + admin_path)
        expect(page.get_by_role('heading', name='登录管理台', exact=True)).to_be_visible()
        for width, height in VIEWPORTS:
            stage = f'login {width}x{height}'
            page.set_viewport_size({'width': width, 'height': height})
            no_overflow(stage)
            for label in ('管理员邮箱', '密码'):
                reachable(page.get_by_label(label, exact=True), stage + ' ' + label, touch=True)
            reachable(page.get_by_role('button', name='登录管理台', exact=True), stage + ' submit', touch=True)
            page.evaluate('scrollTo(0, 0)')
            if width in (1440, 375):
                screenshot(f'login-{width}')
        passed('六个视口的登录布局与触控目标')
        page.set_viewport_size({'width': 1440, 'height': 1000})
        page.get_by_label('管理员邮箱', exact=True).fill(os.environ['E2E_ADMIN_EMAIL'])
        page.get_by_label('密码', exact=True).fill(os.environ['E2E_ADMIN_PASSWORD'])
        page.get_by_label('密码', exact=True).press('Enter')
        expect(page.get_by_role('navigation', name='主导航')).to_be_visible()
        ready()
        token = page.evaluate("sessionStorage.getItem('simplexboard:admin-session')")
        require(bool(token), 'Administrator session was not created')
        secrets.add(token)
        authorization = token if token.startswith('Bearer ') else 'Bearer ' + token

        def api(path, payload=None):
            url = base + '/api/v2/' + admin_path + '/' + path
            response = (context.request.get(url, headers={'Authorization': authorization}) if payload is None
                        else context.request.post(url, headers={'Authorization': authorization}, data=payload))
            require(response.ok, 'Fixture API failed: ' + path + ' HTTP ' + str(response.status))
            value = response.json()
            remember(value)
            require(value.get('status') != 'fail', 'Fixture API rejected: ' + path)
            return value

        stage = 'long-content fixtures'
        records = {node['id']: node for node in api('server/manage/getNodes')['data']}
        for index, original in enumerate(fixture['nodes']):
            node = records[original['id']]
            payload = {key: node[key] for key in ('id', 'type', 'port', 'server_port', 'protocol_settings')}
            payload.update(name=(LONG_CN, LONG_LATIN)[index], host=LONG_HOST, tags=TAGS)
            api('server/manage/save', payload)
        total = api('user/fetch')['total']
        require(total <= 21, 'Expected a small disposable member fixture')
        for index in range(21 - total):
            api('user/create', {'name': LONG_CN if index == 20 - total else f'视觉分页成员 {index + 1:02}',
                                'access_mode': 'all'})
        pair = next(node for node in records.values() if node['type'] == 'vless')['protocol_settings']['reality_settings']
        passed('仅扩展两个长名称/长地址节点及21成员分页数据')

        for width, height in VIEWPORTS:
            stage = f'pages {width}x{height}'
            page.set_viewport_size({'width': width, 'height': height})
            page.emulate_media(reduced_motion='reduce' if width == 390 else 'no-preference')
            for key, label in PAGES:
                stage = f'{key} {width}x{height}'
                nav(label)
                no_overflow(stage)
                links = page.get_by_role('navigation', name='主导航').get_by_role('link')
                require(links.count() == 5, stage + ': primary navigation is incomplete')
                for link in links.all():
                    reachable(link, stage + ' navigation', touch=True)
                primary = {'overview': '添加节点', 'nodes': '添加节点', 'members': '添加成员',
                           'settings': '保存设置'}.get(key)
                if primary:
                    reachable(page.get_by_role('button', name=primary, exact=True).first, stage + ' primary action', touch=True)
                if key in ('nodes', 'members'):
                    first_row = page.locator('tbody tr').first
                    for control in first_row.locator('button').all():
                        reachable(control, stage + ' row action', touch=True)
                    headers = page.get_by_role('columnheader')
                    require(headers.count() > 0, stage + ': responsive table lost column-header semantics')
                if width in (1440, 375) and key != 'logs':
                    page.evaluate('scrollTo(0, 0)')
                    screenshot(f'{key}-{width}')
                elif (width, key) in ((1024, 'nodes'), (768, 'overview')):
                    page.evaluate('scrollTo(0, 0)')
                    screenshot(f'{key}-{width}')
            if width in (1440, 375):
                skip_link()
            if width == 390:
                motion = page.locator('.button,.nav-link').evaluate_all('''elements => elements.every(el => {
                    const s = getComputedStyle(el);
                    const seconds = value => value.split(',').every(v => parseFloat(v) * (v.trim().endsWith('ms') ? .001 : 1) <= .05);
                    return seconds(s.transitionDuration) && seconds(s.animationDuration);
                })''')
                require(motion, 'Reduced-motion preference leaves substantial control animation')
            result['viewports'].append({'width': width, 'height': height, 'pages': 5, 'passed': True})
            passed(f'{width}×{height}：五页无横向溢出，导航/主要操作/行操作可触达')

        stage = '960x400 short-desktop navigation'
        page.set_viewport_size({'width': 960, 'height': 400})
        nav('概览')
        navigation = page.get_by_role('navigation', name='主导航').get_by_role('link')
        navigation.first.focus()
        page.keyboard.press('Tab')
        page.keyboard.press('Shift+Tab')
        for index in range(5):
            expect(navigation.nth(index)).to_be_focused()
            reachable(navigation.nth(index), stage + ' item ' + str(index + 1), touch=True, focus=True)
            if index < 4:
                page.keyboard.press('Tab')
        screenshot('navigation-960x400')
        passed('960×400低高度桌面：五个侧栏导航可通过Tab依次聚焦且不被裁切')

        stage = '375px pagination and keyboard tabs'
        page.set_viewport_size({'width': 375, 'height': 812})
        page.emulate_media(reduced_motion='no-preference')
        nav('成员与订阅')
        expect(page.locator('tbody tr')).to_have_count(20)
        next_page = page.get_by_role('button', name='下一页', exact=True)
        reachable(next_page, 'Member next page', touch=True)
        next_page.click()
        expect(page.locator('.pagination')).to_contain_text('第 2 / 2 页')
        expect(page.locator('tbody tr')).to_have_count(1)
        require(next_page.is_disabled(), 'Last-page control was not disabled')
        previous = page.get_by_role('button', name='上一页', exact=True)
        reachable(previous, 'Member previous page', touch=True)
        previous.click()
        expect(page.locator('tbody tr')).to_have_count(20)
        opener = page.get_by_role('button', name='添加成员', exact=True)
        opener.click()
        dialog = page.get_by_role('dialog', name='添加成员', exact=True)
        expect(dialog.get_by_label('成员名称', exact=True)).to_be_focused()
        dialog.get_by_label('节点访问范围', exact=True).select_option('restricted')
        choice = dialog.locator('.node-choice').first
        reachable(choice, 'Long-name member node choice', touch=True)
        choice.click()
        expect(choice.get_by_role('checkbox')).to_be_checked()
        no_overflow('Member restricted-node form')
        dialog_keyboard(dialog, opener)
        nav('设置')
        page.get_by_role('tab', name='基础设置', exact=True).focus()
        for key, label in (('End', '订阅模板'), ('Home', '基础设置'),
                           ('ArrowRight', '管理员安全'), ('ArrowLeft', '基础设置')):
            page.keyboard.press(key)
            tab = page.get_by_role('tab', name=label, exact=True)
            expect(tab).to_be_focused()
            expect(tab).to_have_attribute('aria-selected', 'true')
            reachable(tab, 'Settings tab ' + key, touch=True, focus=True)
            no_overflow('Settings tab ' + label)
        passed('375px分页、长节点选择、弹窗焦点循环/回归与标签页方向/Home/End键')

        for width, height in ((1440, 1000), (375, 812), (844, 390)):
            stage = f'node form {width}x{height}'
            page.set_viewport_size({'width': width, 'height': height})
            nav('VPS 与节点')
            opener = page.get_by_role('button', name='添加节点', exact=True).first
            opener.click()
            dialog = page.get_by_role('dialog', name='添加节点', exact=True)
            expect(dialog.locator('[name="name"]')).to_be_focused()
            dialog.locator('[name="name"]').fill(LONG_CN)
            dialog.locator('[name="host"]').fill(LONG_HOST)
            dialog.locator('[name="tags"]').fill(', '.join(TAGS))
            dialog.locator('[name="reality_server_name"]').fill('www.example.test')
            submit = dialog.get_by_role('button', name='创建节点', exact=True)
            reachable(submit, stage + ' submit', touch=True)
            submit.click()
            expect(dialog).to_be_visible()
            expect(dialog.locator('[name="reality_public_key"]')).to_be_focused()
            reachable(dialog.locator('[name="reality_public_key"]'), stage + ' invalid field', touch=True)
            for key in ('public_key', 'private_key', 'short_id'):
                dialog.locator('[name="reality_' + key + '"]').fill(pair[key])
            require(dialog.locator('form').evaluate('(form) => form.checkValidity()'), stage + ': filled form is invalid')
            for label in ('一键生成密钥和 Short ID', '生成 Short ID'):
                reachable(dialog.get_by_role('button', name=label, exact=True), stage + ' ' + label, touch=True)
            summary = dialog.locator('summary')
            summary.click()
            editor = dialog.get_by_label('协议参数 JSON', exact=True)
            reachable(editor, stage + ' advanced editor', touch=True)
            no_overflow(stage)
            summary.click()
            dialog.locator('[name="name"]').scroll_into_view_if_needed()
            screenshot(f'node-form-{width}')
            dialog_keyboard(dialog, opener)
        passed('桌面/375px/横屏长节点表单：原生校验可见、完整输入、生成控件、JSON编辑区与取消')
        require(not result['page_errors'], 'Browser raised a JavaScript error')
        require(not result['http_errors'], 'Unexpected API errors during visual review')
        result['status'] = 'passed'
    except Exception as error:
        result['error'] = redact(error)
        result['stage'] = stage
        if page and not page.is_closed():
            try:
                screenshot('failure')
            except Exception:
                pass
    finally:
        if browser and browser.is_connected():
            browser.close()
        playwright.stop()
        result['page_errors'] = [redact(error) for error in result['page_errors']]
        report = artifacts / 'panel-visual-review-results.json'
        report.write_text(json.dumps(result, ensure_ascii=False, indent=2) + '\n')
    print(json.dumps({'status': result['status'], 'checks': len(result['checks']),
                      'screenshots': len(result['screenshots']), 'stage': stage,
                      **({'error': result['error']} if 'error' in result else {})}, ensure_ascii=False), flush=True)
    return 0 if result['status'] == 'passed' else 1


if __name__ == '__main__':
    raise SystemExit(main())
