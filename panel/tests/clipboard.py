#!/usr/bin/env python3
"""Check real browser clipboard contents, including ordinary HTTP and dialogs.

Run with Python Playwright; E2E_CHROMIUM_PATH optionally selects a system browser.
All requests are fulfilled from this checkout. API fixtures only supply harmless
display data to the real page modules; no backend, database or external service
is used. Successful copies are verified by a real keyboard paste, not by mocking
execCommand's return value or granting clipboard permissions to HTTP pages.
"""
import html
import json
import os
from pathlib import Path
import re
from urllib.parse import urlsplit

from playwright.sync_api import expect, sync_playwright


ROOT = Path(__file__).resolve().parents[2]
TEXT = '中文订阅与命令 ✓\n  https://panel.example.test/sub?token=a%2Bb&flag=sing-box\n第二行\n'
SUBSCRIPTION = 'https://panel.example.test/sub?token=clipboard-fixture'
TOKEN = 'clipboard-fixture-machine-token'
COMMAND = "printf '%s\\n' 'clipboard fixture'\n# 这里只是复制内容，不执行命令\n"
RESTORE = '恢复步骤：\n1. 核对备份文件\n2. 执行本机恢复\n'

HARNESS = '''<!doctype html><html lang="zh-CN"><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="/src/styles.css"><title>Clipboard regression</title>
<body><main id="host" style="padding:24px"></main><script type="module">
import * as ui from '/src/ui.js';
import { membersPage } from '/src/pages/members.js';
import { nodesPage } from '/src/pages/nodes.js';
import { logsPage } from '/src/pages/logs.js';
const fixture = __FIXTURE__;
const host = document.getElementById('host');
window.setup = ({depth = 0, text = fixture.text} = {}) => {
  window.payload = text;
  window.copyResult = 'pending';
  const copy = async () => { window.copyResult = await ui.copyText(window.payload, '测试内容已复制'); };
  const source = ui.textarea('selection-source', '原有选区 abcdefgh 中文', {rows: 2});
  source.addEventListener('keydown', event => {
    if (event.ctrlKey && event.shiftKey && event.key.toLowerCase() === 'c') {
      event.preventDefault(); copy();
    }
  });
  const content = ui.h('div', {class: 'stack-lg'},
    ui.field('原有输入', source), ui.button('复制测试内容', {id: 'copy-button', onClick: copy}));
  if (!depth) host.append(content);
  for (let i = 0; i < depth; i++) ui.openDialog({
    title: '复制测试 ' + (i + 1), content: i === depth - 1 ? content : ui.h('p', {}, '父窗口'),
  });
};
window.renderPage = async name => {
  host.replaceChildren();
  const responses = {
    'user/fetch': {data: [fixture.member], total: 1},
    'server/manage/getNodes': {data: []},
    'server/machine/fetch': {data: [{id: 1, name: '复制测试 VPS', is_active: true}]},
    'server/machine/getToken': {data: {token: fixture.token}},
    'server/machine/installCommand': {data: {command: fixture.command}},
    'backup/list': {data: {files: [], restore_command: fixture.restore}},
    'system/getAuditLog': {data: [], total: 0},
    'system/getHorizonFailedJobs': {data: [], total: 0},
  };
  const context = {
    signal: new AbortController().signal,
    query: new URLSearchParams(name === 'nodes' ? 'tab=machines' : 'tab=backups'),
    api: {get: async endpoint => {
      if (!(endpoint in responses)) throw new Error('Unexpected fixture API: ' + endpoint);
      return structuredClone(responses[endpoint]);
    }},
  };
  await ({members: membersPage, nodes: nodesPage, logs: logsPage}[name])(host, context);
};
window.ready = true;
</script></body></html>'''.replace('__FIXTURE__', json.dumps({
    'text': TEXT, 'token': TOKEN, 'command': COMMAND, 'restore': RESTORE,
    'member': {'id': 1, 'name': '复制测试成员', 'subscribe_url': SUBSCRIPTION,
               'uuid': 'be06e0fb-10cd-49d2-a864-8a723c66a526', 'banned': False},
}, ensure_ascii=False))


def subscription_html():
    """Use the actual Blade copy controls/script without duplicating their logic."""
    template = (ROOT / 'resources/views/client/subscribe.blade.php').read_text()
    controls = template.split('<div class="link-item">', 1)[1].split('<div class="qr-section">', 1)[0]
    controls = '<div class="link-item">' + controls
    translations = json.loads((ROOT / 'resources/lang/zh-CN.json').read_text())
    controls = re.sub(r"\{\{ __\('([^']+)'\) \}\}",
                      lambda match: html.escape(translations.get(match[1], match[1]), quote=True), controls)
    controls = controls.replace('{{ $subscription_url }}', html.escape(SUBSCRIPTION, quote=True))
    script = re.findall(r'<script>(.*?)</script>', template, re.S)[-1]
    style = re.findall(r'<style>(.*?)</style>', template, re.S)[0]
    return f'<!doctype html><meta charset="utf-8"><style>{style}</style>{controls}<script>{script}</script>'


def serve(route):
    path = urlsplit(route.request.url).path
    if path == '/':
        route.fulfill(content_type='text/html', body=HARNESS)
    elif path == '/subscription':
        route.fulfill(content_type='text/html', body=subscription_html())
    elif path.startswith('/src/'):
        source = (ROOT / 'panel' / path.lstrip('/')).resolve()
        if source.is_relative_to(ROOT / 'panel/src') and source.is_file():
            route.fulfill(content_type='text/css' if source.suffix == '.css' else 'text/javascript', body=source.read_text())
        else:
            route.fulfill(status=404, body='Unknown source')
    else:
        route.fulfill(status=404, body='No external requests are allowed')


def clipboard_probe(page, seed=None):
    """Use keyboard copy/paste in the active modal, without Clipboard API access."""
    page.evaluate('''() => {
      const root = [...document.querySelectorAll('dialog[open]')].at(-1) || document.body;
      const probe = document.createElement('textarea');
      probe.id = 'clipboard-test-probe';
      probe.setAttribute('aria-label', 'Clipboard test probe');
      root.append(probe);
    }''')
    probe = page.locator('#clipboard-test-probe')
    if seed is not None:
        probe.fill(seed)
        probe.press('Control+A')
        probe.press('Control+C')
    probe.fill('')
    probe.press('Control+V')
    value = probe.input_value()
    probe.evaluate('(element) => element.remove()')
    return value


def reject_async_clipboard(page):
    page.evaluate('''() => {
      navigator.clipboard.writeText = async () => { throw new DOMException('Test denial', 'NotAllowedError'); };
    }''')


def check_success(page, expected, in_dialog=False):
    notice = page.locator('.toast-success').last
    expect(notice).to_be_visible()
    assert notice.evaluate('''(element) => element.closest('dialog') ===
      ([...document.querySelectorAll('dialog[open]')].at(-1) || null)'''), 'Feedback is outside the active modal'
    assert page.locator('dialog[open]').count() > 0 if in_dialog else True
    expect(page.locator('.clipboard-fallback')).to_have_count(0)
    assert clipboard_probe(page) == expected, 'Clipboard contains different text, despite success feedback'


def main():
    artifacts = Path(os.environ.get('E2E_ARTIFACT_DIR', '/tmp/simplexboard-clipboard-e2e'))
    artifacts.mkdir(parents=True, exist_ok=True)
    result = {'status': 'failed', 'checks': [], 'page_errors': []}
    page = None

    def passed(name):
        result['checks'].append(name)
        print('PASS ' + name, flush=True)

    try:
        with sync_playwright() as playwright:
            browser = playwright.chromium.launch(headless=True, executable_path=os.environ.get('E2E_CHROMIUM_PATH') or None,
                                                 args=['--no-sandbox'])

            def open_page(secure=False, denied=False, path='/', width=1440):
                origin = ('https' if secure else 'http') + '://clipboard.test'
                context = browser.new_context(viewport={'width': width, 'height': 1000}, locale='zh-CN')
                context.route('**/*', serve)
                if secure and not denied:
                    context.grant_permissions(['clipboard-read', 'clipboard-write'], origin=origin)
                item = context.new_page()
                item.set_default_timeout(10000)
                item.on('pageerror', lambda error: result['page_errors'].append(str(error)))
                item.goto(origin + path)
                assert item.evaluate('isSecureContext') is secure
                if not secure:
                    assert item.evaluate('typeof navigator.clipboard') == 'undefined', 'HTTP test exposed the secure Clipboard API'
                if path == '/':
                    item.wait_for_function('window.ready === true')
                if denied:
                    reject_async_clipboard(item)
                return item

            try:
                cases = [(True, False, 0, 1440), (False, False, 0, 1440), (False, False, 1, 1440),
                         (False, False, 2, 1440), (True, True, 0, 1440), (True, True, 1, 1440),
                         (False, False, 1, 390)]
                for secure, denied, depth, width in cases:
                    page = open_page(secure, denied, width=width)
                    page.evaluate('(depth) => setup({depth})', depth)
                    clipboard_probe(page, 'clipboard sentinel')
                    page.locator('#copy-button').click()
                    page.wait_for_function('window.copyResult !== "pending"')
                    assert page.evaluate('copyResult') is True
                    expect(page.locator('#copy-button')).to_be_focused()
                    check_success(page, TEXT, depth > 0)
                    passed(f'{"HTTPS denied" if denied else "HTTPS" if secure else "HTTP"}, modal depth {depth}, {width}px: exact multiline clipboard and visible feedback')
                    page.context.close()

                page = open_page()
                page.evaluate('setup({depth: 1})')
                source = page.locator('#field-selection-source')
                source.focus()
                source.evaluate("element => element.setSelectionRange(3, 8, 'backward')")
                source.press('Control+Shift+C')
                page.wait_for_function('window.copyResult !== "pending"')
                expect(source).to_be_focused()
                assert source.evaluate('element => [element.selectionStart, element.selectionEnd, element.selectionDirection]') == [3, 8, 'backward']
                check_success(page, TEXT, True)
                passed('Fallback restores the original input focus and selection')
                page.context.close()

                for failure in ('false', 'throw'):
                    page = open_page(secure=True, denied=True)
                    page.evaluate('setup({depth: 1})')
                    clipboard_probe(page, 'unchanged on failure')
                    page.evaluate('''(failure) => {
                      document.execCommand = () => {
                        if (failure === 'throw') throw new Error('Test clipboard failure');
                        return false;
                      };
                    }''', failure)
                    page.locator('#copy-button').click()
                    page.wait_for_function('window.copyResult !== "pending"')
                    assert page.evaluate('copyResult') is False
                    expect(page.locator('.toast-success')).to_have_count(0)
                    expect(page.locator('.clipboard-fallback')).to_have_count(0)
                    expect(page.locator('dialog[open]')).to_have_count(2)
                    manual = page.locator('dialog[open]').last.locator('textarea')
                    expect(manual).to_be_focused()
                    assert manual.input_value() == TEXT
                    assert manual.evaluate('element => element.value.slice(element.selectionStart, element.selectionEnd)') == TEXT
                    # The browser's native shortcut remains usable when programmatic copying is blocked.
                    manual.press('Control+C')
                    assert clipboard_probe(page) == TEXT
                    page.locator('dialog[open]').last.locator('.dialog-footer').get_by_role('button', name='关闭', exact=True).click()
                    expect(page.locator('dialog[open]')).to_have_count(1)
                    passed(f'Fallback {failure}: no false success, no leaked control, complete manual copy remains available')
                    page.context.close()

                for expression in ('null', 'undefined', "''"):
                    page = open_page()
                    page.evaluate('setup()')
                    clipboard_probe(page, 'empty input sentinel')
                    page.evaluate('window.payload = ' + expression)
                    page.locator('#copy-button').click()
                    page.wait_for_function('window.copyResult !== "pending"')
                    assert page.evaluate('copyResult') is False
                    expect(page.locator('.toast-success')).to_have_count(0)
                    expect(page.locator('.toast-error')).to_be_visible()
                    expect(page.locator('dialog[open]')).to_have_count(0)
                    assert clipboard_probe(page) == 'empty input sentinel'
                    page.context.close()
                passed('Missing or empty copy values report failure and preserve existing clipboard contents')

                # Exercise all six production buttons using real page modules and ordinary HTTP.
                page = open_page()
                page.evaluate("renderPage('members')")
                page.get_by_role('button', name='复制 复制测试成员 的订阅', exact=True).click()
                check_success(page, SUBSCRIPTION)
                page.get_by_role('button', name='查看 复制测试成员 的订阅', exact=True).click()
                page.get_by_label('客户端格式', exact=True).select_option('sing-box')
                page.get_by_role('button', name='复制订阅链接', exact=True).click()
                check_success(page, SUBSCRIPTION + '&flag=sing-box', True)
                page.get_by_role('button', name='复制节点 UUID', exact=True).click()
                check_success(page, 'be06e0fb-10cd-49d2-a864-8a723c66a526', True)
                page.locator('dialog[open] .dialog-footer').get_by_role('button', name='关闭', exact=True).click()
                page.evaluate("renderPage('nodes')")
                page.get_by_role('button', name='接入命令', exact=True).click()
                for label, value in (('复制接入命令', COMMAND), ('复制机器令牌', TOKEN)):
                    page.get_by_role('button', name=label, exact=True).click()
                    check_success(page, value, True)
                page.locator('dialog[open] .dialog-footer').get_by_role('button', name='关闭', exact=True).click()
                page.evaluate("renderPage('logs')")
                page.get_by_role('button', name='复制恢复说明', exact=True).click()
                check_success(page, RESTORE)
                passed('All six production copy buttons: member list, formatted subscription, UUID, install command, machine token and restore instructions')
                page.context.close()

                for secure, denied in ((True, False), (False, False), (True, True)):
                    page = open_page(secure, denied, '/subscription')
                    page.locator('.copy-btn').click()
                    expect(page.locator('.copy-btn')).to_have_class(re.compile(r'\bcopied\b'))
                    assert clipboard_probe(page) == SUBSCRIPTION
                    expect(page.locator('#copy-status')).to_have_text('')
                    page.context.close()
                passed('Subscription display page: native clipboard, ordinary HTTP and API rejection all copy the exact link')

                page = open_page(path='/subscription')
                page.evaluate('''() => {
                  window.originalExecCommand = document.execCommand;
                  document.execCommand = () => false;
                }''')
                page.locator('.copy-btn').click()
                expect(page.locator('.copy-btn')).not_to_have_class(re.compile(r'\bcopied\b'))
                expect(page.locator('#copy-status')).not_to_be_empty()
                link = page.locator('#sub_url')
                expect(link).to_be_focused()
                assert link.evaluate('element => element.value.slice(element.selectionStart, element.selectionEnd)') == SUBSCRIPTION
                page.evaluate('() => { document.execCommand = window.originalExecCommand; }')
                page.locator('.copy-btn').click()
                expect(page.locator('.copy-btn')).to_have_class(re.compile(r'\bcopied\b'))
                expect(page.locator('#copy-status')).to_have_text('')
                assert clipboard_probe(page) == SUBSCRIPTION
                passed('Subscription copy failure keeps the link selected, reports failure, and recovers on retry')
                assert not result['page_errors'], 'Browser raised a JavaScript error'
                result['status'] = 'passed'
            except Exception:
                if page is not None and not page.is_closed():
                    page.screenshot(path=str(artifacts / 'clipboard-failure.png'), full_page=True)
                raise
            finally:
                browser.close()
    except Exception as error:
        result['error'] = str(error)
        print('FAIL ' + str(error), flush=True)
    finally:
        (artifacts / 'clipboard-results.json').write_text(json.dumps(result, ensure_ascii=False, indent=2) + '\n')
    print(json.dumps({'status': result['status'], 'checks': len(result['checks'])}), flush=True)
    return 0 if result['status'] == 'passed' else 1


if __name__ == '__main__':
    raise SystemExit(main())
