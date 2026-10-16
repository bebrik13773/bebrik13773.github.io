"""Сквозной тест ДЛ-12 (headless Chrome + SwiftShader, сервер подменён через route): сон при state.sleeping, тапы не двигают
спящего, пробуждение по ответу sync, еда уходит действием eat, ответ с gain. Запуск: python3 e2e_energy.py [путь_к_chrome]."""
import http.server, json, os, socketserver, sys, threading, time
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
STATE = {'energy': 3, 'energy_max': 100, 'sleeping': True, 'wake_energy': 20, 'coins': 10, 'weight': 2, 'capacity': 40, 'pack': {'fish_small': 2}, 'level': 1, 'xp_into': 0, 'xp_need': 100}
FULL = {
    'player_id': 7, 'village': {'name': 'Поляна 7', 'x': SP['x'], 'z': SP['z']}, 'pos': {'x': SP['x'], 'z': SP['z'], 'ry': 0},
    'state': STATE, 'upgrades': {'axe': 1, 'pack': 1, 'stamina': 1, 'lamp': 0}, 'hotbar': ['axe'], 'tutorial_step': 1, 'last_a': 0,
    'clearings': [{'x': SP['x'], 'z': SP['z'], 'r': 12}], 'pond': None, 'server_ts': 1, 'server_ms': 1,
}

log = []
phase = {'awake': False}
def handler(route):
    name = route.request.url.split('/api/forest/')[1].split('.php')[0]
    body = route.request.post_data
    req = json.loads(body) if body else None
    log.append((name, req))
    def reply(payload, status=200):
        route.fulfill(status=status, content_type='application/json', body=json.dumps(payload))
    if name == 'health': return reply({'ok': True, 'data': {'schema_ok': True, 'server_ms': 1}})
    if name in ('state', 'join'): return reply({'ok': True, 'data': FULL})
    if name == 'sync':
        results_ = []
        for a in (req or {}).get('actions', []):
            if a['type'] == 'eat':
                results_.append({'a': a['a'], 'ok': True, 'type': 'eat', 'gain': 25, 'energy': 48})
        st = {**STATE, 'sleeping': not phase['awake'], 'energy': 48 if phase['awake'] else 12, 'pack': {'fish_small': 1} if phase['awake'] else STATE['pack']}
        return reply({'ok': True, 'data': {'ack': req['seq'], 'server_ts': 1, 'server_ms': 1, 'results': results_, 'state': st, 'next_sync_ms': 2000}})
    return reply({'ok': False, 'error': 'bad_request'}, 400)

with sync_playwright() as p:
    browser = p.chromium.launch(executable_path=CHROME, headless=True, args=['--no-sandbox', '--use-gl=angle', '--use-angle=swiftshader', '--enable-unsafe-swiftshader'])
    ctx = browser.new_context(viewport={'width': 800, 'height': 480}, device_scale_factor=1)
    ctx.add_init_script("if (!sessionStorage.getItem('wf_seeded')) { sessionStorage.setItem('wf_seeded', '1'); localStorage.setItem('wf.betaAckAt', String(Date.now())); }")
    page = ctx.new_page()
    errs = []
    page.on('console', lambda m: errs.append(m.text) if m.type == 'error' and 'Failed to load resource' not in m.text else None)
    page.on('pageerror', lambda e: errs.append('PAGEERROR ' + str(e)))
    page.route('**/api/forest/*.php*', handler)
    page.goto(BASE + '?debug=1')
    page.wait_for_function("window.__wf && window.__wf.state === 'playing' && window.__wf.frames() > 2", timeout=60000)

    # 1. Сервер сказал sleeping: бобёр лёг, тап по земле не двигает, HUD со значком сна
    check('сон включился по состоянию сервера', page.evaluate('window.__wf.energy().sleeping') is True)
    page.wait_for_timeout(600)
    check('бобёр в состоянии sleep', page.evaluate('window.__wf.beaverStateName()') == 'sleep', page.evaluate('window.__wf.beaverStateName()'))
    check('в HUD значок сна', page.evaluate("document.getElementById('wfEnergy').dataset.sleeping") == '1')
    before = page.evaluate('window.__wf.heroPos()')
    r = page.evaluate('window.__wf.tap ? window.__wf.tap(400, 300) : null')
    page.wait_for_timeout(500)
    after = page.evaluate('window.__wf.heroPos()')
    check('спящий не ходит', abs(after['x'] - before['x']) < 0.05 and abs(after['z'] - before['z']) < 0.05)

    # 2. Ответ sync без sleeping: проснулся
    phase['awake'] = True
    page.wait_for_function('window.__wf.energy().sleeping === false', timeout=20000)
    check('проснулся по ответу sync', True)
    page.wait_for_timeout(400)
    check('бобёр больше не спит', page.evaluate('window.__wf.beaverStateName()') != 'sleep', page.evaluate('window.__wf.beaverStateName()'))
    check('в HUD значка сна нет', page.evaluate("document.getElementById('wfEnergy').dataset.sleeping") == '0')

    # 3. Еда: действие eat уходит в sync, повтор до паузы отклоняется на клиенте
    ok1 = page.evaluate("window.__wf.eat('fish_small')")
    ok2 = page.evaluate("window.__wf.eat('fish_small')")
    check('еда принята, повтор до паузы нет', ok1 is True and ok2 is False, [ok1, ok2])
    page.wait_for_function('window.__wf.net.sync && true', timeout=5000)
    deadline = time.time() + 20
    sent = False
    while time.time() < deadline and not sent:
        sent = any(n == 'sync' and r and any(a['type'] == 'eat' and a['item'] == 'fish_small' for a in r.get('actions', [])) for n, r in log)
        page.wait_for_timeout(300)
    check('sync понёс действие eat', sent)
    page.wait_for_timeout(800)
    check('ошибок консоли нет', not errs, errs[:3])
    browser.close()

print(f"\n{sum(results)} из {len(results)}")
sys.exit(0 if all(results) else 1)
