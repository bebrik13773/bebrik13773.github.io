"""Сквозной тест ДЛ-11 (headless Chrome + SwiftShader, сервер подменён через route): тап по дереву, подход, удары, шкала,
chop_done в sync, награда и выпавшие брёвна, откат при отказе сервера. Запуск: python3 e2e_chop.py [путь_к_chrome]."""
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
        parsed = json.loads(body) if body else None
        log.append((name, parsed))
        def reply(payload, status=200):
            route.fulfill(status=status, content_type='application/json', body=json.dumps(payload))
        if name == 'health':
            return reply({'ok': True, 'data': {'schema_ok': True, 'server_ms': 1}})
        if name == 'state':
            return reply({'ok': True, 'data': FULL})
        if name == 'sync':
            results = []
            for a in parsed.get('actions', []):
                if a['type'] != 'chop_done':
                    results.append({'a': a['a'], 'ok': False, 'error': 'bad_state'})
                elif mode == 'reject':
                    results.append({'a': a['a'], 'ok': False, 'error': 'too_far', 'tree': a['tree']})
                else:
                    results.append({'a': a['a'], 'ok': True, 'tree': a['tree'], 'reward': {'log': 2, 'coins': 120}, 'xp': 8,
                                    'drops': [{'id': 900 + a['a'], 'x': SP['x'] + 20, 'z': SP['z'] - 20, 'item': 'log', 'qty': 1}, {'id': 1900 + a['a'], 'x': SP['x'] + 21, 'z': SP['z'] - 20, 'item': 'log', 'qty': 1}]})
            data = {'ack': parsed['seq'], 'server_ts': 1, 'server_ms': 1, 'results': results, 'state': FULL['state'], 'next_sync_ms': 8000}
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

def run(ctx, mode):
    page, log, errs = boot(ctx, mode)
    page.evaluate('window.__wf.pumpAll()')
    trees = page.evaluate('window.__wf.nearTrees(40)')
    trees = [t for t in trees if ((t['x'] - SP['x']) ** 2 + (t['z'] - SP['z']) ** 2) ** 0.5 > 15]
    check(f'[{mode}] рядом с поляной есть деревья', len(trees) > 0, len(trees))
    hero = page.evaluate('window.__wf.heroPos()')
    trees.sort(key=lambda t: (t['x'] - hero['x']) ** 2 + (t['z'] - hero['z']) ** 2)
    tree = trees[0]
    page.evaluate(f"window.__wf.teleport({tree['x'] - 4}, {tree['z']})")
    page.evaluate('window.__wf.pumpAll()')
    sp = None
    for k in range(9):
        page.evaluate('window.__wf.snapCamera()')
        page.wait_for_timeout(150)
        sp = page.evaluate(f"window.__wf.treeScreen('{tree['id']}')")
        if sp and 30 < sp['sx'] < 770 and 30 < sp['sy'] < 450:
            break
        sp = None
        page.evaluate('window.__wf.orbit(0.8, 0)')
        page.evaluate('window.__wf.snapCamera()')
    if sp is None:
        tree = None
    check(f'[{mode}] дерево на экране', tree is not None)
    r = page.evaluate(f"window.__wf.tap({sp['sx']}, {sp['sy']})")
    check(f'[{mode}] тап по дереву запускает подход', r['type'] in ('interact', 'workTap'), r)
    page.wait_for_function("window.__wf.hero().mode === 'work'", timeout=40000)
    check(f'[{mode}] бобёр дошёл и работает', True)
    info = page.evaluate('window.__wf.chop()')
    check(f'[{mode}] рубка началась с нуля', info['active'] and info['hits'] == 0 and info['need'] >= 1, info)
    need = info['need']
    page.wait_for_timeout(300)
    for i in range(need + 4):
        s = page.evaluate(f"window.__wf.treeScreen('{tree['id']}')")
        page.evaluate(f"window.__wf.tap({s['sx']}, {s['sy']})")
        page.wait_for_timeout(215)
        st = page.evaluate('window.__wf.chop()')
        if not st['active']:
            break
        if i == 0:
            check(f'[{mode}] первый удар засчитан', st['hits'] >= 1 and 0 < st['frac'] <= 1, st)
    check(f'[{mode}] дерево упало (рубка завершена)', not page.evaluate('window.__wf.chop()')['active'])
    page.wait_for_function("window.__wf.net.sync && window.__wf.net.sync.seq >= 1 && window.__wf.net.queue.size === 0", timeout=20000)
    chops = [a for n, b in log if n == 'sync' for a in b['actions'] if a['type'] == 'chop_done']
    check(f'[{mode}] chop_done ушёл на сервер', len(chops) >= 1 and chops[0]['tree'] == tree['id'] and chops[0]['hits'] == need, chops[:1])
    check(f'[{mode}] в действии есть позиция и метки времени', 'px' in chops[0] and 'pz' in chops[0] and chops[0]['t1'] >= chops[0]['t0'], chops[:1])
    page.wait_for_timeout(500)
    return page, log, errs, tree

with sync_playwright() as p:
    browser = p.chromium.launch(executable_path=CHROME, headless=True, args=['--no-sandbox', '--use-gl=angle', '--use-angle=swiftshader', '--enable-unsafe-swiftshader'])
    ctx = browser.new_context(viewport={'width': 800, 'height': 480}, device_scale_factor=1)
    ctx.add_init_script("if (!sessionStorage.getItem('wf_seeded')) { sessionStorage.setItem('wf_seeded', '1'); localStorage.setItem('wf.betaAckAt', String(Date.now())); }")

    page, log, errs, tree = run(ctx, 'ok')
    stumps = page.evaluate('window.__wf.world().stumps')
    check('[ok] на месте дерева пень', stumps >= 1, stumps)
    check('[ok] выпавшие брёвна показаны на земле', len(page.evaluate('window.__wf.drops()')) == 2)
    check('[ok] ошибок консоли нет', not errs, errs[:3])
    page.close()

    page, log, errs, tree = run(ctx, 'reject')
    page.wait_for_timeout(800)
    page.evaluate('window.__wf.pumpAll()')
    again = page.evaluate(f"window.__wf.nearTrees(60).some(t => t.id === '{tree['id']}')")
    check('[reject] сервер отказал — дерево вернулось', again)
    check('[reject] брёвен на земле нет', len(page.evaluate('window.__wf.drops()')) == 0)
    check('[reject] ошибок консоли нет', not errs, errs[:3])
    page.close()
    browser.close()

total = len(results)
print(f"\n{sum(results)} из {total}")
sys.exit(0 if all(results) else 1)
