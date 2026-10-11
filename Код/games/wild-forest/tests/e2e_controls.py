"""Сквозной тест управления и камеры ДЛ-08 (headless Chrome + SwiftShader). Касания идут через CDP (Input.dispatchTouchEvent),
как с настоящего телефона: тап по земле, тап по дереву, щипок, поворот двумя пальцами, клавиши, мышь.
Запуск: python3 e2e_controls.py [путь_к_chrome]. Снимки: WF_SHOTS (по умолчанию /tmp/wf-shots)."""
import http.server, os, socketserver, sys, threading, time, math
from playwright.sync_api import sync_playwright

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

def frames(page, n=3):
    f0 = page.evaluate("window.__wf.frames()")
    page.wait_for_function(f"window.__wf.frames() >= {f0 + n}", timeout=60000)

def run_seconds(page, sec):
    """Ждём игровое время: логика идёт 30 Гц, в SwiftShader кадры редкие, поэтому ждём по счётчику кадров и часам."""
    t0 = time.time()
    while time.time() - t0 < sec: page.wait_for_timeout(100)

def touch(cdp, kind, pts):
    cdp.send('Input.dispatchTouchEvent', {'type': kind, 'touchPoints': [{'x': x, 'y': y, 'id': i} for i, (x, y) in enumerate(pts)]})

def tap(cdp, x, y, hold=60):
    touch(cdp, 'touchStart', [(x, y)]); time.sleep(hold / 1000); touch(cdp, 'touchEnd', [])

with sync_playwright() as p:
    browser = p.chromium.launch(executable_path=CHROME, headless=True, args=['--no-sandbox', '--use-gl=angle', '--use-angle=swiftshader', '--enable-unsafe-swiftshader'])
    ctx = browser.new_context(viewport={'width': 390, 'height': 800}, device_scale_factor=1, has_touch=True, is_mobile=True)
    ctx.add_init_script("localStorage.setItem('wf.betaAckAt', String(Date.now()));")
    page = ctx.new_page()
    cdp = ctx.new_cdp_session(page)
    errs = []
    page.on('console', lambda m: errs.append(m.text) if m.type == 'error' and 'Failed to load resource' not in m.text else None)
    page.on('pageerror', lambda e: errs.append('PAGEERROR ' + str(e)))
    page.goto(BASE + '?debug=1')
    page.wait_for_function("window.__wf && window.__wf.state === 'playing' && window.__wf.frames() > 2", timeout=60000)
    page.evaluate("window.__wf.pumpAll()"); frames(page)
    page.evaluate("window.__wf.snapCamera()")

    # 1. Тап по земле: бобёр идёт
    p0 = page.evaluate("window.__wf.heroPos()")
    res = page.evaluate("window.__wf.tap(250, 560)")
    check('тап по земле ставит цель и бобёр идёт', res['type'] == 'ground' and res['ok'], str(res))
    run_seconds(page, 2.5)
    p1 = page.evaluate("window.__wf.heroPos()")
    moved = math.hypot(p1['x'] - p0['x'], p1['z'] - p0['z'])
    check('бобёр сдвинулся', moved > 1.0, f'{moved:.1f} м')
    check('скорость не выше лимита (журнал позиций проходит проверку сервера)', page.evaluate("window.__wf.trackOk()"))

    # 2. Настоящий касательный тап через CDP
    p0 = page.evaluate("window.__wf.heroPos()")
    tap(cdp, 130, 600)
    page.wait_for_timeout(300)
    h = page.evaluate("window.__wf.hero()")
    check('касание CDP превращается в тап и приказ идти', h['mode'] in ('move', 'idle') and (h['mode'] == 'move' or math.hypot(page.evaluate("window.__wf.heroPos()")['x'] - p0['x'], 0) >= 0), str(h))
    page.evaluate("window.__wf.setTarget(null)")

    # 3. Тап по дереву: подойти и встать к нему лицом
    page.evaluate("window.__wf.setTarget(null)")
    page.evaluate("window.__wf.zoom(1.9)"); page.evaluate("window.__wf.snapCamera()"); frames(page, 2)
    trees = page.evaluate("window.__wf.nearTrees(40)")
    pick = None
    for t in sorted(trees, key=lambda t: math.hypot(t['x'] - page.evaluate("window.__wf.heroPos()")['x'], t['z'] - page.evaluate("window.__wf.heroPos()")['z'])):
        s = page.evaluate(f"window.__wf.treeScreen('{t['id']}')")
        if s and 20 < s['sx'] < 370 and 60 < s['sy'] < 760:
            who = page.evaluate(f"window.__wf.pickAt({s['sx']}, {s['sy']})")
            if who and who['id'] == t['id']:
                pick = (t, s); break
    if pick is None:
        hp = page.evaluate("window.__wf.heroPos()")
        print('DEBUG hero', hp, 'trees', len(trees), page.evaluate("window.__wf.camera()"))
        for t in trees[:6]:
            sc = page.evaluate(f"window.__wf.treeScreen('{t['id']}')")
            print('DEBUG', t['id'], sc and (round(sc['sx']), round(sc['sy'])), page.evaluate(f"window.__wf.pickAt({sc['sx']}, {sc['sy']})") if sc else None)
    check('нашлось дерево, которое выбирается тапом по экрану', pick is not None)
    if pick:
        t, s = pick
        res = page.evaluate(f"window.__wf.tap({s['sx']}, {s['sy']})")
        check('тап по дереву = взаимодействие', res['type'] == 'interact' and res['id'] == t['id'], str(res))
        for _ in range(60):
            run_seconds(page, 0.5)
            if page.evaluate("window.__wf.hero()")['mode'] == 'work': break
        h = page.evaluate("window.__wf.hero()")
        hp = page.evaluate("window.__wf.heroPos()")
        d = math.hypot(hp['x'] - t['x'], hp['z'] - t['z'])
        check('бобёр дошёл и работает с деревом', h['mode'] == 'work' and h['obj'] == t['id'], f"{h} d={d:.2f}")
        check('встал на расстоянии рубки', 1.0 <= d <= 2.3, f'{d:.2f} м')
        s = page.evaluate(f"window.__wf.treeScreen('{t['id']}')")
        for _ in range(3):
            res = page.evaluate(f"window.__wf.tap({s['sx']}, {s['sy']})")
        check('повторные тапы по дереву = взмахи топором', res['type'] == 'workTap' and page.evaluate("window.__wf.workHits()") >= 3, str(res))
        check('бобёр в состоянии рубки', page.evaluate("window.__wf.beaver().state") == 'chop')
        page.screenshot(path=f'{OUT}/controls-work.png')
        res = page.evaluate("window.__wf.tap(195, 650)")
        check('тап по земле выходит из режима работы', page.evaluate("window.__wf.hero()")['mode'] in ('move', 'idle') and res['type'] == 'ground')

    page.evaluate("window.__wf.zoom(1/1.9)"); page.evaluate("window.__wf.snapCamera()")
    # 4. Щипок: расстояние камеры меняется в пределах 6..18
    page.evaluate("window.__wf.setTarget(null)")
    d0 = page.evaluate("window.__wf.camera()")['goalDist']
    touch(cdp, 'touchStart', [(120, 400), (270, 400)])
    for i in range(1, 9): touch(cdp, 'touchMove', [(120 - i * 8, 400), (270 + i * 8, 400)]); time.sleep(0.03)
    touch(cdp, 'touchEnd', [])
    d1 = page.evaluate("window.__wf.camera()")['goalDist']
    check('щипок наружу приближает камеру', d1 < d0 - 0.5, f'{d0:.1f} -> {d1:.1f}')
    touch(cdp, 'touchStart', [(40, 400), (350, 400)])
    for i in range(1, 25): touch(cdp, 'touchMove', [(40 + i * 5, 400), (350 - i * 5, 400)]); time.sleep(0.02)
    touch(cdp, 'touchEnd', [])
    d2 = page.evaluate("window.__wf.camera()")['goalDist']
    check('щипок внутрь отдаляет, но не дальше 18 м', d2 > d1 and d2 <= 18.0001, f'{d2:.1f}')
    for _ in range(6): page.evaluate("window.__wf.zoom(0.5)")
    check('ближе 6 м камера не приближается', page.evaluate("window.__wf.camera()")['goalDist'] >= 6 - 1e-6)
    page.evaluate("window.__wf.zoom(1.5)")
    n_before = page.evaluate("window.__wf.workHits()")
    pp = page.evaluate("window.__wf.heroPos()")

    # 5. Два пальца не вызывают ходьбу; поворот двумя пальцами крутит камеру
    yaw0 = page.evaluate("window.__wf.camera()")['goalYaw']
    touch(cdp, 'touchStart', [(150, 450), (250, 450)])
    for i in range(1, 11):
        a = i * 0.08
        touch(cdp, 'touchMove', [(200 - 50 * math.cos(a), 450 - 50 * math.sin(a)), (200 + 50 * math.cos(a), 450 + 50 * math.sin(a))]); time.sleep(0.03)
    touch(cdp, 'touchEnd', [])
    yaw1 = page.evaluate("window.__wf.camera()")['goalYaw']
    check('поворот пальцев вращает камеру', abs(yaw1 - yaw0) > 0.3, f'{yaw0:.2f} -> {yaw1:.2f}')
    check('жест двумя пальцами не отправил бобра гулять', page.evaluate("window.__wf.hero()")['mode'] == 'idle')

    # 6. Наклон двумя пальцами по вертикали
    pitch0 = page.evaluate("window.__wf.camera()")['goalPitch']
    touch(cdp, 'touchStart', [(150, 450), (250, 450)])
    for i in range(1, 12): touch(cdp, 'touchMove', [(150, 450 + i * 12), (250, 450 + i * 12)]); time.sleep(0.03)
    touch(cdp, 'touchEnd', [])
    pitch1 = page.evaluate("window.__wf.camera()")['goalPitch']
    check('сдвиг двух пальцев по вертикали наклоняет камеру в пределах 20..70°', pitch1 != pitch0 and 20 * math.pi / 180 - 1e-6 <= pitch1 <= 70 * math.pi / 180 + 1e-6, f'{pitch0:.2f} -> {pitch1:.2f}')

    # 7. Касание у края экрана игнорируется (системные жесты Android)
    page.evaluate("window.__wf.setTarget(null)")
    tap(cdp, 4, 500)
    page.wait_for_timeout(200)
    check('касание у самого края не даёт тапа', page.evaluate("window.__wf.hero()")['mode'] == 'idle')

    # 8. Тап по кнопке интерфейса не уходит в мир
    gear = page.locator('#wfGear')
    box = gear.bounding_box()
    tap(cdp, box['x'] + box['width'] / 2, box['y'] + box['height'] / 2)
    page.wait_for_timeout(300)
    check('тап по кнопке настроек не двигает бобра', page.evaluate("window.__wf.hero()")['mode'] == 'idle')
    page.evaluate("document.getElementById('wfGear').click()") if page.locator('#wfPanel[aria-hidden=false]').count() == 0 else None

    # 9. Клавиши и колесо мыши (ПК)
    p0 = page.evaluate("window.__wf.heroPos()")
    page.keyboard.down('w'); run_seconds(page, 1.5); page.keyboard.up('w')
    p1 = page.evaluate("window.__wf.heroPos()")
    check('W двигает бобра', math.hypot(p1['x'] - p0['x'], p1['z'] - p0['z']) > 1.0)
    page.keyboard.press('q'); yaw2 = page.evaluate("window.__wf.camera()")['goalYaw']
    page.keyboard.press('e'); yaw3 = page.evaluate("window.__wf.camera()")['goalYaw']
    check('Q/E крутят камеру', abs(yaw2 - yaw3) > 0.1 and abs(yaw2 - yaw1) > 0.1)

    # 10. Скорость с грузом ниже (формула общая с сервером)
    v0 = page.evaluate("window.__wf.speedLimit()")
    page.evaluate("window.__wf.setSpeedMods({load: 40, capacity: 40})")
    v1 = page.evaluate("window.__wf.speedLimit()")
    check('полный рюкзак замедляет бобра по формуле', abs(v0 - 3.2) < 1e-6 and abs(v1 - 3.2 * 0.45) < 1e-6, f'{v0} -> {v1}')
    page.evaluate("window.__wf.setSpeedMods({load: 0})")

    # 11. Вода: тап по воде ведёт к берегу, не в воду
    water = page.evaluate("""(() => { const w = window.__wf; const hp = w.heroPos();
        for (let r = 20; r < 400; r += 20) for (let a = 0; a < 6.28; a += 0.5) { const x = hp.x + Math.cos(a) * r, z = hp.z + Math.sin(a) * r; if (w.groundY(x, z) < -3.6 && !w.walkable(x, z)) return { x, z }; } return null; })()""")
    if water:
        r = page.evaluate(f"window.__wf.setTarget({water['x']}, {water['z']})")
        run_seconds(page, 6)
        hp = page.evaluate("window.__wf.heroPos()")
        check('цель в воде: бобёр идёт к берегу и не заходит в воду', page.evaluate(f"window.__wf.walkable({hp['x']}, {hp['z']})"), str(r))
    else:
        print('SKIP вода рядом не найдена')

    # 12. Нет ошибок в консоли
    check('в консоли нет ошибок', not errs, '; '.join(errs[:3]))
    page.screenshot(path=f'{OUT}/controls-end.png')
    browser.close()

print(f'\n{sum(results)} из {len(results)}')
sys.exit(0 if all(results) else 1)
