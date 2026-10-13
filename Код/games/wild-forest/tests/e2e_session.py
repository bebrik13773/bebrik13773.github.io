"""Сквозной тест ДЛ-10 (headless Chrome + SwiftShader, сервер подменён через route): вход, join, поляна и пруд с сервера,
HUD из состояния, sync с позицией и correct_pos, оффлайн-режимы. Запуск: python3 e2e_session.py [путь_к_chrome]."""
import http.server, json, os, socketserver, sys, threading
from playwright.sync_api import sync_playwright

CHROME = sys.argv[1] if len(sys.argv) > 1 else '/home/claude/.cache/puppeteer/chrome/linux-131.0.6778.204/chrome-linux64/chrome'
ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..', '..'))

class Quiet(http.server.SimpleHTTPRequestHandler):
    def __init__(self, *a, **k): super().__init__(*a, directory=ROOT, **k)
    def log_message(self, *a): pass

socketserver.TCPServer.allow_reuse_address = True
httpd = socketserver.TCPServer(('127.0.0.1', 0), Quiet)
BASE = f'http://127.0.0.1:{httpd.server_address[1]}/games/wild-forest/'
threading.Thread(target=httpd.serve_forever, daemon=True).start()

results = []
def check(name, cond, extra=''):
    results.append(bool(cond))
    print(('OK   ' if cond else 'FAIL ') + name + (f'  [{extra}]' if extra else ''))

SP = {'x': 900, 'z': 400}
FULL = {
    'player_id': 7, 'village': {'name': 'Поляна 7', 'x': SP['x'], 'z': SP['z']}, 'pos': {'x': SP['x'], 'z': SP['z'], 'ry': 0},
    'state': {'energy': 71.5, 'energy_max': 100, 'coins': 340, 'weight': 14, 'capacity': 40, 'pack': {'log': 2}, 'level': 1, 'xp_into': 0, 'xp_need': 100},
    'upgrades': {'axe': 1, 'pack': 1, 'stamina': 1, 'lamp': 0}, 'hotbar': ['axe'], 'tutorial_step': 0, 'last_a': 4,
    'clearings': [{'x': SP['x'], 'z': SP['z'], 'r': 12}, {'x': SP['x'] + 20, 'z': SP['z'], 'r': 8}],
    'pond': {'x': SP['x'] + 20, 'z': SP['z'], 'r': 5, 'deg': 0, 'dam': {'x': SP['x'] + 25, 'z': SP['z'], 'level': 1}},
    'server_ts': 1, 'server_ms': 1,
}

def install(page, mode, log):
    def handler(route):
        url = route.request.url
        name = url.split('/api/forest/')[1].split('.php')[0]
        body = route.request.post_data
        log.append((name, json.loads(body) if body else None))
        def reply(payload, status=200):
            route.fulfill(status=status, content_type='application/json', body=json.dumps(payload))
        if name == 'health':
            return reply({'ok': True, 'data': {'schema_ok': True, 'server_ms': 1}})
        if mode == 'unauth' and name == 'state':
            return reply({'ok': False, 'error': 'unauthorized', 'message': 'x'}, 401)
        if name == 'state':
            if mode == 'newplayer' and not any(n == 'join' for n, _ in log):
                return reply({'ok': False, 'error': 'bad_state', 'message': 'x', 'extra': {'need_join': True}}, 409)
            return reply({'ok': True, 'data': FULL})
        if name == 'join':
            return reply({'ok': True, 'data': {**FULL, 'created': True}})
        if name == 'sync':
            syncs = [1 for n, _ in log if n == 'sync']
            data = {'ack': body and json.loads(body)['seq'], 'server_ts': 1, 'server_ms': 1, 'results': [],
                    'state': {**FULL['state'], 'energy': 60, 'coins': 345}, 'next_sync_ms': 8000}
            if mode == 'correct' and len(syncs) == 1:
                data['correct_pos'] = {'x': SP['x'] + 3, 'z': SP['z'] + 3}
            return reply({'ok': True, 'data': data})
        return reply({'ok': False, 'error': 'bad_request'}, 400)
    page.route('**/api/forest/*.php*', handler)

def boot(ctx, mode):
    page = ctx.new_page()
    log, errs = [], []
    page.on('console', lambda m: errs.append(m.text) if m.type == 'error' and 'Failed to load resource' not in m.text else None)
    page.on('pageerror', lambda e: errs.append('PAGEERROR ' + str(e)))
    if mode != 'static':
        install(page, mode, log)
    page.goto(BASE + '?debug=1')
    page.wait_for_function("window.__wf && window.__wf.state === 'playing' && window.__wf.frames() > 2", timeout=60000)
    return page, log, errs

with sync_playwright() as p:
    browser = p.chromium.launch(executable_path=CHROME, headless=True, args=['--no-sandbox', '--use-gl=angle', '--use-angle=swiftshader', '--enable-unsafe-swiftshader'])
    ctx = browser.new_context(viewport={'width': 800, 'height': 480}, device_scale_factor=1)
    ctx.add_init_script("if (!sessionStorage.getItem('wf_seeded')) { sessionStorage.setItem('wf_seeded', '1'); localStorage.setItem('wf.betaAckAt', String(Date.now())); }")

    # 1. Игрок уже есть: герой на месте с сервера, поляна и пруд, HUD из состояния
    page, log, errs = boot(ctx, 'existing')
    hp = page.evaluate('window.__wf.heroPos()')
    check('герой появился в точке сервера', abs(hp['x'] - SP['x']) < 1 and abs(hp['z'] - SP['z']) < 1, hp)
    check('join не вызывался, state вызван', not any(n == 'join' for n, _ in log) and any(n == 'state' for n, _ in log))
    page.wait_for_function("!document.getElementById('wfHud').hidden", timeout=20000)
    check('энергия из состояния сервера', '72' in page.inner_text('#wfEnergyText') or '71' in page.inner_text('#wfEnergyText'), page.inner_text('#wfEnergyText'))
    check('монеты из состояния сервера', '340' in page.inner_text('#wfCoinsText'), page.inner_text('#wfCoinsText'))
    check('рюкзак: бревно из состояния', page.evaluate("window.__wf.ui.inventory.items.length") == 1 and page.evaluate("window.__wf.ui.inventory.items[0].qty") == 2)
    check('пруд и плотина в сцене', page.evaluate("window.__wf.pondInfo && window.__wf.pondInfo()"))
    page.evaluate('window.__wf.pumpAll()')
    cl = page.evaluate(f"window.__wf.chunkTrees({SP['x'] // 32}, {SP['z'] // 32}).length")
    near = page.evaluate(f"window.__wf.nearTrees(10).length")
    check('на поляне нет деревьев в радиусе 10 м', near == 0, near)
    check('очередь продолжает номера сервера', page.evaluate('window.__wf.net.queue.lastA') == 4)

    # 2. sync уходит с позицией (ждём первый, 3 с)
    page.wait_for_function("window.__wf.net.sync.seq >= 1", timeout=15000)
    sy = [b for n, b in log if n == 'sync'][0]
    check('sync несёт позицию и seq', sy['seq'] == 1 and abs(sy['pos']['x'] - SP['x']) < 1 and 'actions' in sy, sy['pos'])
    page.wait_for_function("document.getElementById('wfCoinsText').textContent.includes('345')", timeout=10000)
    check('ответ sync обновил монеты', True)
    check('ошибок консоли нет', not errs, errs[:3])
    page.close()

    # 3. Новый игрок: state просит join, join создаёт
    page, log, errs = boot(ctx, 'newplayer')
    names = [n for n, _ in log]
    check('новый игрок: state → join', 'join' in names and names.index('join') > names.index('state'), names)
    hp = page.evaluate('window.__wf.heroPos()')
    check('новый игрок появился на поляне', abs(hp['x'] - SP['x']) < 1)
    page.close()

    # 4. correct_pos возвращает героя
    page, log, errs = boot(ctx, 'correct')
    page.wait_for_function("window.__wf.net.sync.seq >= 1", timeout=15000)
    page.wait_for_function(f"Math.abs(window.__wf.heroPos().x - {SP['x'] + 3}) < 1.5", timeout=10000)
    check('correct_pos телепортирует героя', True)
    page.close()

    # 5. Без входа в аккаунт: играем без сохранения, sync не идёт
    page, log, errs = boot(ctx, 'unauth')
    page.wait_for_timeout(4500)
    check('unauthorized: sync не запускался', not any(n == 'sync' for n, _ in log))
    check('unauthorized: лес всё равно работает', page.evaluate('window.__wf.frames()') > 2)
    check('unauthorized: есть сообщение', page.evaluate("document.body.innerText.includes('аккаунт')"))
    page.close()

    # 6. Совсем без сервера (статический хостинг без PHP)
    page, log, errs = boot(ctx, 'static')
    check('без сервера игра запускается', page.evaluate("window.__wf.state") == 'playing')
    check('без сервера нет net.sync', page.evaluate("window.__wf.net.sync") is None)
    page.close()
    browser.close()

print(f"\n{sum(results)} из {len(results)}")
sys.exit(0 if all(results) else 1)
