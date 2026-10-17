"""Сквозной тест ДЛ-13 (headless Chrome + SwiftShader, сервер подменён через route): выпавшие предметы видны, брёвна рядом
подбираются сами (действие pickup в sync), груз замедляет бобра, бросок уходит действием drop и появляется на земле без автоподбора.
Запуск: python3 e2e_pack.py [путь_к_chrome]."""
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
pack = {'log': 2}
DROPS = [
    {'id': 11, 'x': SP['x'] + 0.8, 'z': SP['z'], 'item': 'log', 'qty': 1, 'ttl': 900},   # рядом: подберётся само
    {'id': 12, 'x': SP['x'] + 6.0, 'z': SP['z'], 'item': 'log', 'qty': 1, 'ttl': 900},   # далеко
    {'id': 13, 'x': SP['x'] + 0.5, 'z': SP['z'] + 0.5, 'item': 'ore', 'qty': 1, 'ttl': 900},  # руда само не берётся
]
def state():
    kg = pack.get('log', 0) * 7
    return {'energy': 80, 'energy_max': 100, 'sleeping': False, 'wake_energy': 20, 'coins': 10, 'weight': kg, 'capacity': 40, 'pack': dict(pack), 'level': 1, 'xp_into': 0, 'xp_need': 100}
FULL = {
    'player_id': 7, 'village': {'name': 'Поляна 7', 'x': SP['x'], 'z': SP['z']}, 'pos': {'x': SP['x'], 'z': SP['z'], 'ry': 0},
    'state': state(), 'upgrades': {'axe': 1, 'pack': 1, 'stamina': 1, 'lamp': 0}, 'hotbar': ['axe'], 'tutorial_step': 1, 'last_a': 0,
    'clearings': [{'x': SP['x'], 'z': SP['z'], 'r': 12}], 'pond': None, 'drops': DROPS, 'server_ts': 1, 'server_ms': 1,
}

log = []
next_id = {'v': 100}
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
        res = []
        for a in (req or {}).get('actions', []):
            if a['type'] == 'pickup':
                pack['log'] = pack.get('log', 0) + 1
                res.append({'a': a['a'], 'ok': True, 'type': 'pickup', 'drop': a['drop'], 'item': 'log', 'taken': 1, 'left': 0})
            elif a['type'] == 'drop':
                pack[a['item']] = max(0, pack.get(a['item'], 0) - a['qty'])
                next_id['v'] += 1
                res.append({'a': a['a'], 'ok': True, 'type': 'drop', 'item': a['item'], 'drop': {'id': next_id['v'], 'x': SP['x'] - 1.2, 'z': SP['z'], 'item': a['item'], 'qty': a['qty'], 'ttl': 900}})
        return reply({'ok': True, 'data': {'ack': req['seq'], 'server_ts': 1, 'server_ms': 1, 'results': res, 'state': state(), 'next_sync_ms': 2000}})
    return reply({'ok': False, 'error': 'bad_request'}, 400)

def sent(kind, pred=lambda a: True):
    return any(n == 'sync' and r and any(a['type'] == kind and pred(a) for a in r.get('actions', [])) for n, r in log)

def wait_for(cond, seconds=25):
    end = time.time() + seconds
    while time.time() < end:
        if cond(): return True
        page.wait_for_timeout(250)
    return False

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

    # 1. Предметы с сервера лежат на земле (бревно 11 может уже подбираться само, поэтому смотрим на остальные)
    ids0 = [d['id'] for d in page.evaluate('window.__wf.drops()')]
    check('выпавшие предметы показаны', 12 in ids0 and 13 in ids0, ids0)

    # 2. Вес замедляет бобра по общей формуле: v = 3.2 × (1 − 0.55 × (вес/40)^1.2), проверяем на текущем весе
    def speed_ok():
        kg = page.evaluate('window.__wf.ui.inventory.weight')
        want = 3.2 * (1 - 0.55 * (kg / 40) ** 1.2)
        got = page.evaluate('window.__wf.speedLimit()')
        return abs(got - want) < 0.01, f'{kg} кг: {got:.3f} против {want:.3f}'
    ok_, info = speed_ok()
    check('скорость с грузом совпадает с формулой', ok_ and page.evaluate('window.__wf.speedLimit()') < 3.2, info)

    # 3. Бревно рядом подбирается само, руда и далёкое бревно лежат
    ok = wait_for(lambda: sent('pickup', lambda a: a['drop'] == 11))
    check('автоподбор: sync понёс pickup бревна 11', ok)
    check('автоподбор: бревно 11 ушло с земли', 11 not in [d['id'] for d in page.evaluate('window.__wf.drops()')])
    ids = [d['id'] for d in page.evaluate('window.__wf.drops()')]
    check('руда и далёкое бревно остались', 12 in ids and 13 in ids, ids)
    check('руду автоподбор не брал', not sent('pickup', lambda a: a['drop'] == 13))
    wait_for(lambda: page.evaluate('window.__wf.pack().pending.length') == 0)
    w = page.evaluate('window.__wf.ui.inventory.weight')
    check('после ответа вес рюкзака 21 кг (3 бревна)', abs(w - 21) < 0.01, w)
    ok_, info = speed_ok()
    check('после подбора скорость снова по формуле и ниже скорости пустого', ok_ and page.evaluate('window.__wf.speedLimit()') < 2.5, info)

    # 4. Подбор далёкого бревна по запросу (как тап по нему)
    check('pickup(12) принят', page.evaluate('window.__wf.pickup(12)') is True)
    check('бревно 12 сразу ушло с земли', 12 not in [d['id'] for d in page.evaluate('window.__wf.drops()')])
    check('sync понёс pickup бревна 12', wait_for(lambda: sent('pickup', lambda a: a['drop'] == 12)))

    # 5. Бросок: вес падает сразу, действие drop уходит, предмет появляется и сам не подбирается
    wait_for(lambda: page.evaluate('window.__wf.pack().pending.length') == 0)
    before = page.evaluate('window.__wf.ui.inventory.weight')
    check('dropItem принят', page.evaluate("window.__wf.dropItem('log', 1)") is True)
    after = page.evaluate('window.__wf.ui.inventory.weight')
    check('вес упал на 7 кг сразу', abs((before - after) - 7) < 0.01, f'{before} → {after}')
    check('sync понёс drop', wait_for(lambda: sent('drop', lambda a: a['item'] == 'log' and a['qty'] == 1)))
    wait_for(lambda: any(d['id'] > 100 for d in page.evaluate('window.__wf.drops()')))
    thrown = [d for d in page.evaluate('window.__wf.drops()') if d['id'] > 100]
    check('брошенное лежит на земле', len(thrown) == 1, thrown)
    page.wait_for_timeout(1500)
    check('брошенное рядом не подобралось само', not sent('pickup', lambda a: a['drop'] > 100))

    page.wait_for_timeout(500)
    check('ошибок консоли нет', not errs, errs[:3])
    browser.close()

print(f"\n{sum(results)} из {len(results)}")
sys.exit(0 if all(results) else 1)
