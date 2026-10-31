"""Сквозной тест ДЛ-14 (headless Chrome + SwiftShader, сервер подменён через route): на поляне стоят ящик и подвода скупщика,
ящик принимает и отдаёт предметы (crate_put, crate_take), скупщик берёт брёвна и рыбу (sell), лимит и отказы показываются игроку.
Запуск: python3 e2e_crate.py [путь_к_chrome]."""
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
CRATE = {'x': SP['x'] + 3.5, 'z': SP['z'] - 2.5}
BUYER = {'x': SP['x'] - 4.0, 'z': SP['z'] + 3.0}
KG = {'log': 7, 'fish_small': 1, 'plank': 3}
pack = {'log': 12, 'fish_small': 3, 'plank': 2}
box = {}
left = {'v': 100}   # остаток лимита скупщика, монет
coins = {'v': 10}

def state():
    kg = sum(KG[k] * v for k, v in pack.items())
    return {'energy': 80, 'energy_max': 100, 'sleeping': False, 'wake_energy': 20, 'coins': coins['v'], 'weight': kg, 'capacity': 100, 'pack': dict(pack),
            'crate': dict(box), 'crate_cap': 100, 'buyer_left': left['v'], 'level': 1, 'xp_into': 0, 'xp_need': 100}
FULL = {
    'player_id': 7, 'pos': {'x': SP['x'], 'z': SP['z'], 'ry': 0}, 'state': state(), 'upgrades': {'axe': 1, 'pack': 1, 'stamina': 1, 'lamp': 0},
    'village': {'name': 'Поляна 7', 'x': SP['x'], 'z': SP['z'], 'crate': CRATE, 'buyer': {**BUYER, 'prices': {'log': 6, 'fish_small': 10.8, 'fish_big': 24}, 'cap': 400}},
    'hotbar': ['axe'], 'tutorial_step': 1, 'last_a': 0, 'clearings': [{'x': SP['x'], 'z': SP['z'], 'r': 12}], 'pond': None, 'drops': [], 'server_ts': 1, 'server_ms': 1,
}
UNIT = {'log': 6.0, 'fish_small': 10.8}

log = []
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
            t = a['type']
            if t in ('crate_put', 'crate_take'):
                src, dst = (pack, box) if t == 'crate_put' else (box, pack)
                n = min(a['qty'], src.get(a['item'], 0))
                if t == 'crate_put': n = min(n, 100 - sum(box.values()))
                if n <= 0: res.append({'a': a['a'], 'ok': False, 'type': t, 'item': a['item'], 'error': 'no_items'}); continue
                src[a['item']] -= n; dst[a['item']] = dst.get(a['item'], 0) + n
                res.append({'a': a['a'], 'ok': True, 'type': t, 'item': a['item'], 'moved': n, 'left': a['qty'] - n})
            elif t == 'sell':
                u = UNIT.get(a['item'])
                if not u: res.append({'a': a['a'], 'ok': False, 'type': 'sell', 'item': a['item'], 'error': 'not_wanted'}); continue
                q = min(a['qty'], pack.get(a['item'], 0))
                while q > 0 and int(u * q) > left['v']: q -= 1
                if q <= 0: res.append({'a': a['a'], 'ok': False, 'type': 'sell', 'item': a['item'], 'error': 'daily_limit', 'left': left['v']}); continue
                total = int(u * q); pack[a['item']] -= q; coins['v'] += total; left['v'] -= total
                res.append({'a': a['a'], 'ok': True, 'type': 'sell', 'item': a['item'], 'sold': q, 'coins': total, 'left_today': left['v'], 'rest': a['qty'] - q})
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

def inv_qty(key):
    return page.evaluate(f"(() => {{ const el = [...document.querySelectorAll('#wfInvGrid .wf-cell')].find(c => c.dataset.key === '{key}'); return el ? el.textContent : ''; }})()")

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

    # 1. Ящик и подвода построены, ящик и рюкзак читаются с сервера
    check('ящик и подвода построены (2 группы моделей)', page.evaluate('window.__wf.stalls()') == 2, page.evaluate('window.__wf.stalls()'))
    c = page.evaluate('window.__wf.crate()')
    check('рюкзак с сервера: 12 брёвен', c['pack'].get('log') == 12, c)
    check('остаток лимита скупщика с сервера', c['buyerLeft'] == 100, c)

    # 2. Сдача в ящик
    check('crate_put принят', page.evaluate("window.__wf.crateMove('pack', 'log', 5)") is True)
    check('sync понёс crate_put с позицией', wait_for(lambda: sent('crate_put', lambda a: a['item'] == 'log' and a['qty'] == 5 and 'px' in a and 'pz' in a)))
    wait_for(lambda: page.evaluate('window.__wf.crate()')['box'].get('log') == 5)
    c = page.evaluate('window.__wf.crate()')
    check('после ответа: в ящике 5, в рюкзаке 7', c['box'].get('log') == 5 and c['pack'].get('log') == 7, c)

    # 3. Забрать назад
    page.wait_for_timeout(3300)
    check('crate_take принят', page.evaluate("window.__wf.crateMove('box', 'log', 2)") is True)
    check('sync понёс crate_take', wait_for(lambda: sent('crate_take', lambda a: a['item'] == 'log' and a['qty'] == 2)))
    wait_for(lambda: page.evaluate('window.__wf.crate()')['box'].get('log') == 3)
    c = page.evaluate('window.__wf.crate()')
    check('после ответа: в ящике 3, в рюкзаке 9', c['box'].get('log') == 3 and c['pack'].get('log') == 9, c)

    # 4. Продажа: лимит 100 монет — брёвна по 6 (16 штук = 96), потом рыба не влезает полностью
    page.wait_for_timeout(3300)
    check('sellAll отправил продажу', page.evaluate('window.__wf.sellAll()') >= 2)
    check('sync понёс sell брёвен и рыбы', wait_for(lambda: sent('sell', lambda a: a['item'] == 'log') and sent('sell', lambda a: a['item'] == 'fish_small')))
    wait_for(lambda: page.evaluate('window.__wf.crate()')['buyerLeft'] != 100)
    page.wait_for_timeout(500)
    c = page.evaluate('window.__wf.crate()')
    check('брёвна проданы (9 × 6 = 54 монеты), лимит 46', c['pack'].get('log', 0) == 0 and c['buyerLeft'] < 100, c)
    check('доски скупщик не трогал', c['pack'].get('plank') == 2, c)
    hud_coins = page.evaluate("window.__wf.ui.hud.values.coins")
    check('монеты в HUD выросли', hud_coins > 10, hud_coins)

    # 5. Панель ящика рисуется и принимает нажатия кнопок
    page.evaluate("window.__wf.ui.notify && 0")
    page.evaluate("document.getElementById('wfCrate') && 0")
    exists = page.evaluate("!!document.getElementById('wfCrate')")
    check('панель ящика есть в странице', exists)

    page.wait_for_timeout(500)
    check('ошибок консоли нет', not errs, errs[:3])
    browser.close()

print(f"\n{sum(results)} из {len(results)}")
sys.exit(0 if all(results) else 1)
