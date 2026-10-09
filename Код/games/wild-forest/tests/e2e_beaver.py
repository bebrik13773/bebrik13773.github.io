"""Сквозной тест 3D-бобра ДЛ-07 (headless Chrome + SwiftShader, без GPU, 1 ядро).
Проверяет корректность, а не скорость: страница обзора tests/beaver.html (все состояния, снаряжение, скины, подробность),
бюджеты треугольников и вызовов отрисовки, видимость и контур на снимках, встраивание бобра в сам лес (ходьба, поворот, качество).
Запуск: python3 e2e_beaver.py [путь_к_chrome]. Снимки: WF_SHOTS (по умолчанию /tmp/wf-shots)."""
import http.server, io, os, socketserver, sys, threading
from playwright.sync_api import sync_playwright
from PIL import Image

CHROME = sys.argv[1] if len(sys.argv) > 1 else '/home/claude/.cache/puppeteer/chrome/linux-131.0.6778.204/chrome-linux64/chrome'
ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..', '..'))
OUT = os.environ.get('WF_SHOTS', '/tmp/wf-shots')
os.makedirs(OUT, exist_ok=True)

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

def pixels(png, box=None):
    im = Image.open(io.BytesIO(png)).convert('RGB')
    if box: im = im.crop(box)
    return im

def count(im, pred):
    px = im.load(); w, h = im.size
    return sum(1 for y in range(0, h, 2) for x in range(0, w, 2) if pred(px[x, y]))

with sync_playwright() as p:
    browser = p.chromium.launch(executable_path=CHROME, headless=True, args=['--no-sandbox', '--use-gl=angle', '--use-angle=swiftshader', '--enable-unsafe-swiftshader'])
    ctx = browser.new_context(viewport={'width': 520, 'height': 700}, device_scale_factor=1)
    ctx.add_init_script("localStorage.setItem('wf.betaAckAt', String(Date.now()));")
    page = ctx.new_page()
    errs = []
    page.on('console', lambda m: errs.append(m.text) if m.type == 'error' and 'favicon' not in m.text and '404' not in m.text else None)
    page.on('pageerror', lambda e: errs.append(str(e)))

    # ---------- страница обзора модели ----------
    page.goto(BASE + 'tests/beaver.html')
    page.wait_for_function('window.__bt')
    page.evaluate("document.getElementById('ui').style.display='none'")
    check('страница обзора запускается без ошибок', not errs, '; '.join(errs[:3]))

    shots = {}
    for state, opts, yaw in [('idle', {}, 0.6), ('walk', {'speed': 3.2}, 1.4), ('run', {'speed': 5.5, 'load': 0.5}, 1.2), ('chop', {}, 1.57),
                             ('carry', {'speed': 2}, 0.5), ('sleep', {}, 0.4), ('scared', {}, 0.5), ('eat', {}, 0.5)]:
        info = page.evaluate("([s,o,y])=>{window.__bt.view({yaw:y,pitch:0.25});return window.__bt.set(s,s=='chop'?0.62:0.7,o);}", [state, opts, yaw])
        png = page.screenshot(path=f'{OUT}/beaver_{state}.png')
        shots[state] = png
        st = page.evaluate('window.__bt.stats()')
        im = pixels(png, (60, 60, 460, 640))
        dark = count(im, lambda c: c[0] < 40 and c[1] < 40 and c[2] < 40)      # чёрный контур
        warm = count(im, lambda c: c[0] > c[2] + 25 and c[0] > 70 and c[1] < c[0])  # шерсть: коричневый
        check(f'{state}: состояние включено, бобёр виден, контур есть', info['state'] == state and warm > 600 and dark > 150, f'шерсть={warm} контур={dark}')
        check(f'{state}: вызовов отрисовки не больше 12 на бобра', st['calls'] <= 1 + 12 + 1, f"calls={st['calls']} (земля + бобёр + значок)")
    sizes = {s: pixels(shots[s], (0, 0, 520, 700)).getbbox() for s in shots}
    check('снимки разных состояний различаются (анимации работают)', len({shots[s] for s in shots}) == len(shots))
    page.evaluate("window.__bt.view({yaw:0.4,pitch:0.25}); window.__bt.set('sleep',0.7)")
    sleep_png = page.screenshot()
    standing = page.evaluate("window.__bt.set('idle',0.5)")
    # занятая область в кадре: лежащий бобёр шире, чем стоящий
    def bbox_of_fur(png):
        im = pixels(png, (0, 0, 520, 640)); px = im.load(); xs = []; ys = []
        for y in range(0, 640, 2):
            for x in range(0, 520, 2):
                c = px[x, y]
                if c[0] > c[2] + 25 and c[0] > 70 and c[1] < c[0] and not (c[1] > c[0] - 10):
                    xs.append(x); ys.append(y)
        return (max(xs) - min(xs), max(ys) - min(ys)) if xs else (0, 0)
    page.evaluate("window.__bt.view({yaw:0.4,pitch:0.25}); window.__bt.set('idle',0.5)")
    stand_w, stand_h = bbox_of_fur(page.screenshot())
    sleep_w, sleep_h = bbox_of_fur(sleep_png)
    check('сон: бобёр лежит (ниже и шире, чем стоя)', sleep_h < stand_h and sleep_w > stand_w, f'стоя {stand_w}x{stand_h}, спит {sleep_w}x{sleep_h}')

    # снаряжение, скины, подробность
    page.evaluate("window.__bt.view({yaw:2.6,pitch:0.2}); window.__bt.set('idle',0.3)")
    base = page.evaluate("window.__bt.beaver.info()")
    full = page.evaluate("(()=>{const b=window.__bt.beaver;b.setGear({axe:6,pack:8,lamp:4});window.__bt.set('idle',0.2);return b.info();})()")
    page.screenshot(path=f'{OUT}/beaver_gear_max.png')
    check('снаряжение: треугольников больше, фонарь даёт третий вызов', full['triangles'] > base['triangles'] and full['drawCalls'] == 3, f"{base['triangles']} -> {full['triangles']}, calls={full['drawCalls']}")
    for i in (1, 2, 3):
        page.evaluate("(i)=>{document.getElementById('skin').value=i;document.getElementById('skin').onchange();window.__bt.set('idle',0.2);}", i)
        info = page.evaluate("window.__bt.beaver.info()")
        page.screenshot(path=f'{OUT}/beaver_skin_{info["skin"]}.png')
        check(f'скин {info["skin"]}: применён, бюджет треугольников соблюдён', info['triangles'] <= 3500, f"{info['triangles']}")
    page.evaluate("window.__bt.beaver.setSkin(null)")
    low = page.evaluate("(()=>{const b=window.__bt.beaver;b.setGear({axe:6,pack:8,lamp:4});b.setDetail('low');window.__bt.set('walk',0.3,{speed:3});return b.info();})()")
    page.screenshot(path=f'{OUT}/beaver_low.png')
    check('низкая подробность: до 1500 треугольников', low['detail'] == 'low' and low['triangles'] <= 1500, f"{low['triangles']}")
    check('в консоли нет ошибок после всех проверок', not errs, '; '.join(errs[:3]))

    # ---------- бобёр в самом лесу ----------
    errs.clear()
    game = ctx.new_page()
    game.on('console', lambda m: errs.append(m.text) if m.type == 'error' and 'favicon' not in m.text and '404' not in m.text else None)
    game.on('pageerror', lambda e: errs.append(str(e)))
    game.set_viewport_size({'width': 390, 'height': 800})
    game.goto(BASE + '?debug=1')
    game.wait_for_function("window.__wf && window.__wf.state === 'playing' && window.__wf.frames() > 2", timeout=90000)
    game.evaluate('window.__wf.pumpAll()')
    f0 = game.evaluate('window.__wf.frames()')
    game.wait_for_function(f'window.__wf.frames() >= {f0 + 5}', timeout=60000)
    b = game.evaluate('window.__wf.beaver()')
    check('в лесу: герой это бобёр, стоит', b['state'] == 'idle' and b['bones'] == 13 and b['triangles'] > 800, str(b))
    game.screenshot(path=f'{OUT}/forest_beaver_idle.png')
    pos0 = game.evaluate('window.__wf.heroPos()')
    game.evaluate(f"window.__wf.setTarget({pos0['x'] + 14}, {pos0['z'] + 6})")
    try:
        game.wait_for_function("window.__wf.beaver().state === 'walk'", timeout=30000); walked = True
    except Exception: walked = False
    check('в лесу: при ходьбе включается анимация ходьбы', walked)
    game.screenshot(path=f'{OUT}/forest_beaver_walk.png')
    try:
        game.wait_for_function("window.__wf.beaver().state === 'idle'", timeout=60000); stopped = True
    except Exception: stopped = False
    check('в лесу: дойдя до цели, бобёр снова стоит', stopped)
    game.evaluate("window.__wf.beaverState('sleep')")
    f1 = game.evaluate('window.__wf.frames()')
    game.wait_for_function(f'window.__wf.frames() >= {f1 + 10}', timeout=60000)
    check('в лесу: ручное состояние держится, движение его не перебивает', game.evaluate('window.__wf.beaver().state') == 'sleep')
    game.screenshot(path=f'{OUT}/forest_beaver_sleep.png')
    game.evaluate("window.__wf.beaverState(null)")
    # рубка по тапу: событие удара доходит
    game.evaluate("window.__wf.beaverState('idle')")
    game.evaluate("window.__wf.beaverSwing()")
    try:
        game.wait_for_function("window.__wf.beaver().state === 'chop'", timeout=10000); chopped = True
    except Exception: chopped = False
    check('в лесу: один тап запускает взмах топором', chopped)
    game.evaluate("window.__wf.beaverState(null); window.__wf.beaverState('idle')")
    # качество: на низком модель упрощается
    game.evaluate("window.__wf.setQuality && window.__wf.setQuality('low')")
    game.wait_for_function("window.__wf.beaver().detail === 'low'", timeout=30000)
    low_info = game.evaluate('window.__wf.beaver()')
    check('в лесу: на низком качестве бобёр упрощается до 1500 треугольников', low_info['triangles'] <= 1500, str(low_info['triangles']))
    game.evaluate("window.__wf.setQuality('high')")
    game.wait_for_function("window.__wf.beaver().detail === 'high'", timeout=30000)
    check('в лесу: на высоком качестве подробная модель', game.evaluate('window.__wf.beaver().triangles') > 2000)
    check('в лесу: ошибок в консоли нет', not errs, '; '.join(errs[:3]))
    browser.close()

print(f"\nИтого: {sum(results)} из {len(results)}")
sys.exit(0 if all(results) else 1)
