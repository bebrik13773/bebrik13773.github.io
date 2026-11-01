"""Сквозной тест ДЛ-15 (headless Chrome + SwiftShader, сервер подменён через route): наставник Седой Дуб ведёт по шагам,
подсказка со стрелкой, сохранение шагов действием tutorial, пропуск, повтор из настроек.
Запуск: python3 e2e_tutorial.py [путь_к_chrome]."""
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
STATE = {'energy': 80, 'energy_max': 100, 'sleeping': False, 'wake_energy': 20, 'coins': 10, 'weight': 0, 'capacity': 40, 'pack': {}, 'crate': {}, 'crate_cap': 100, 'buyer_left': 400, 'level': 1, 'xp_into': 0, 'xp_need': 100}
FULL = {
    'player_id': 7, 'pos': {'x': SP['x'], 'z': SP['z'], 'ry': 0}, 'state': STATE, 'upgrades': {'axe': 1, 'pack': 1, 'stamina': 1, 'lamp': 0},
    'village': {'name': 'Тихая Заводь', 'x': SP['x'], 'z': SP['z'], 'crate': {'x': SP['x'] + 3.5, 'z': SP['z'] - 2.5}, 'buyer': {'x': SP['x'] - 4.0, 'z': SP['z'] + 3.0, 'prices': {'log': 6}, 'cap': 400}},
    'hotbar': ['axe'], 'tutorial_step': 0, 'last_a': 0, 'clearings': [{'x': SP['x'], 'z': SP['z'], 'r': 12}], 'pond': None, 'drops': [], 'server_ts': 1, 'server_ms': 1,
}
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
        res = [{'a': a['a'], 'ok': True, 'type': a['type']} for a in (req or {}).get('actions', [])]
        return reply({'ok': True, 'data': {'ack': req['seq'], 'server_ts': 1, 'server_ms': 1, 'results': res, 'state': STATE, 'next_sync_ms': 2000}})
    return reply({'ok': False, 'error': 'bad_request'}, 400)

def tutorial_steps():
    out = []
    for n, r in log:
        if n == 'sync' and r:
            for a in r.get('actions', []):
                if a['type'] == 'tutorial': out.append(a.get('step', 'reset'))
    return out

def wait_for(cond, seconds=25):
    end = time.time() + seconds
    while time.time() < end:
        if cond(): return True
        page.wait_for_timeout(250)
    return False

def click_choice(cid):
    ok = wait_for(lambda: page.evaluate(f"!!document.querySelector('#wfDlgChoices button[data-id=\"{cid}\"]')"), 15)
    if ok: page.evaluate(f"document.querySelector('#wfDlgChoices button[data-id=\"{cid}\"]').click()")
    return ok

def dialog_text():
    return page.evaluate("document.getElementById('wfDlgText').textContent")

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

    # 1. Наставник приходит сам и представляется
    check('наставник открыл диалог', wait_for(lambda: page.evaluate("document.getElementById('wfDialog').classList.contains('is-open')"), 20))
    check('говорит Седой Дуб', page.evaluate("document.getElementById('wfDlgSpeaker').textContent") == 'Седой Дуб')
    check('предложено «Давай» и «Я сам»', wait_for(lambda: page.evaluate("document.querySelectorAll('#wfDlgChoices button').length") == 2))
    check('приветствие в тексте', 'Седой Дуб' in dialog_text() or wait_for(lambda: 'Седой Дуб' in dialog_text(), 5))

    # 2. «Давай» → шаг «ходьба»: диалог, затем подсказка и ожидание
    check('нажато «Давай»', click_choice('go'))
    check('сохранён шаг 1', wait_for(lambda: 1 in tutorial_steps(), 20), tutorial_steps())
    check('диалог шага «ходьба»', wait_for(lambda: 'Тапни по земле' in dialog_text(), 10))
    check('нажато «Дальше»', click_choice('next'))
    check('подсказка видна', wait_for(lambda: page.evaluate("!document.getElementById('wfTutor').hidden"), 10))
    check('в подсказке текст про тап', 'Тапни' in page.evaluate("document.getElementById('wfTutorText').textContent"))
    check('диалог закрыт на время ожидания', not page.evaluate("document.getElementById('wfDialog').classList.contains('is-open')"))
    t = page.evaluate('window.__wf.tutorial()')
    check('обучение ждёт ходьбу', t['waiting'] and t['step'] == 1, t)

    # 3. Прошёл 6 м → шаг засчитан
    hp = page.evaluate('window.__wf.heroPos()')
    page.evaluate(f"window.__wf.teleport({hp['x'] + 6}, {hp['z']})")
    check('после ходьбы шаг 2 сохранён и пошёл диалог про рубку', wait_for(lambda: 2 in tutorial_steps() and 'Тапай по дереву' in dialog_text(), 20), tutorial_steps())
    click_choice('next')
    check('ждёт срубленное дерево', wait_for(lambda: page.evaluate('window.__wf.tutorial()')['waiting'], 10))
    check('цель подсказки — дерево рядом', page.evaluate('window.__wf.tutorial()')['target'] is not None)
    check('стрелка включена', wait_for(lambda: page.evaluate("!document.getElementById('wfTutorArrow').hidden"), 8))

    # 4. Событие рубки (ответ sync с деревом) продвигает дальше
    page.evaluate("window.__wf.tutorialEvent('felled')")
    check('шаг 3 сохранён', wait_for(lambda: 3 in tutorial_steps(), 20), tutorial_steps())
    check('диалог про брёвна', wait_for(lambda: 'брёвнам' in dialog_text(), 10))
    click_choice('next')

    # 5. Пропуск кнопкой в подсказке
    check('ожидание подбора', wait_for(lambda: page.evaluate('window.__wf.tutorial()')['waiting'], 10))
    page.evaluate("document.getElementById('wfTutorSkip').click()")
    check('сохранён шаг 12 (пропуск)', wait_for(lambda: 12 in tutorial_steps(), 20), tutorial_steps())
    t = page.evaluate('window.__wf.tutorial()')
    check('обучение завершено, подсказка скрыта', t['done'] and page.evaluate("document.getElementById('wfTutor').hidden"), t)
    check('диалог закрыт', not page.evaluate("document.getElementById('wfDialog').classList.contains('is-open')"))

    # 6. Повтор из настроек
    page.evaluate("document.getElementById('wfTutorialRepeat') && 0")
    page.evaluate("window.__wf.ui.settings.show && 0")
    page.evaluate("document.getElementById('wfTutorialRepeat').click()")
    check('повтор: на сервер ушёл reset', wait_for(lambda: 'reset' in tutorial_steps(), 20), tutorial_steps())
    check('повтор: снова приветствие', wait_for(lambda: 'Седой Дуб' in dialog_text(), 15))
    check('повтор: шаг сброшен', page.evaluate('window.__wf.tutorial()')['step'] == 0)
    # «Я сам» → пропуск
    click_choice('skip')
    check('«Я сам» завершает обучение', wait_for(lambda: page.evaluate('window.__wf.tutorial()')['done'], 10))

    page.wait_for_timeout(500)
    check('нет ошибок консоли', not errs, errs[:3])
    browser.close()

print(f'\n{sum(results)} из {len(results)}')
sys.exit(0 if all(results) else 1)
