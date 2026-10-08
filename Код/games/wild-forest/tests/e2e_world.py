"""Сквозной тест рендера мира ДЛ-06 (headless Chrome + SwiftShader, без GPU, 1 ядро).
Проверяет корректность: чанки грузятся и выгружаются, память не растёт при «прогулке на 5 км», пни, бюджет вызовов отрисовки,
ходьба по тапу, биомы видны. Абсолютные цифры скорости здесь не измеряются.
Запуск: python3 e2e_world.py [путь_к_chrome]. Снимки экрана: WF_SHOTS (по умолчанию /tmp/wf-shots)."""
import http.server, os, socketserver, sys, threading
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

def wait_playing(page, timeout=60000):
    page.wait_for_function("window.__wf && window.__wf.state === 'playing' && window.__wf.frames() > 2", timeout=timeout)

def settle(page, frames=3):
    page.evaluate("window.__wf.pumpAll()")
    f0 = page.evaluate("window.__wf.frames()")
    page.wait_for_function(f"window.__wf.frames() >= {f0 + frames}", timeout=60000)

with sync_playwright() as p:
    browser = p.chromium.launch(executable_path=CHROME, headless=True, args=['--no-sandbox', '--use-gl=angle', '--use-angle=swiftshader', '--enable-unsafe-swiftshader', '--js-flags=--expose-gc'])
    ctx = browser.new_context(viewport={'width': 390, 'height': 800}, device_scale_factor=1, has_touch=True, is_mobile=True)
    ctx.add_init_script("localStorage.setItem('wf.betaAckAt', String(Date.now()));")
    page = ctx.new_page()
    errs = []
    page.on('console', lambda m: errs.append(m.text) if m.type == 'error' and 'Failed to load resource' not in m.text else None)
    page.on('pageerror', lambda e: errs.append('PAGEERROR ' + str(e)))
    page.goto(BASE + '?debug=1')
    wait_playing(page)
    settle(page)

    # 1. Старт: лес вокруг героя, бюджет вызовов отрисовки
    w = page.evaluate("window.__wf.world()")
    info = page.evaluate("window.__wf.info()")
    check('вокруг героя загружены чанки и деревья', w['loaded'] > 40 and w['trees'] > 500, str(w))
    check('вызовы отрисовки на среднем качестве в бюджете (<= 120)', 0 < info['calls'] <= 120, f"calls {info['calls']}")
    check('треугольники на среднем качестве в бюджете (<= 150 тыс.)', 0 < info['triangles'] <= 150000, f"tris {info['triangles']}")
    check('геометрий немного: чанки + служебные', info['geometries'] <= w['loaded'] + 12, f"geo {info['geometries']}, чанков {w['loaded']}")
    page.screenshot(path=f"{OUT}/10-forest-medium.png")

    # 2. Ходьба по тапу
    pos0 = page.evaluate("window.__wf.heroPos()")
    page.mouse.click(195, 330)  # точка перед героем
    page.wait_for_timeout(2500)
    pos1 = page.evaluate("window.__wf.heroPos()")
    moved = ((pos1['x'] - pos0['x']) ** 2 + (pos1['z'] - pos0['z']) ** 2) ** 0.5
    check('тап по земле: герой идёт к точке', moved > 1.0, f'сдвиг {moved:.1f} м')
    page.evaluate("window.__wf.moveInput(0, 1)")
    page.wait_for_timeout(1500)
    page.evaluate("window.__wf.moveInput(0, 0)")
    pos2 = page.evaluate("window.__wf.heroPos()")
    check('клавиши: герой идёт вперёд', ((pos2['x'] - pos1['x']) ** 2 + (pos2['z'] - pos1['z']) ** 2) ** 0.5 > 1.0)

    # 3. Пень: срубленное дерево заменяется пнём без пересоздания чанка
    pos = page.evaluate("window.__wf.heroPos()")
    cx, cz = int(pos['x'] // 32), int(pos['z'] // 32)
    ids = page.evaluate(f"window.__wf.chunkTrees({cx}, {cz})")
    check('в чанке героя есть деревья', ids and len(ids) > 3, str(len(ids or [])))
    before = page.evaluate("window.__wf.world()")
    geo_before = page.evaluate("window.__wf.info().geometries")
    check('fell() принял дерево', page.evaluate(f"window.__wf.fell('{ids[0]}')"))
    after = page.evaluate("window.__wf.world()")
    check('срубленное дерево исчезло, появился пень', after['trees'] == before['trees'] - 1 and after['stumps'] == before['stumps'] + 1, f'{before} -> {after}')
    page.wait_for_function(f"window.__wf.frames() > {page.evaluate('window.__wf.frames()') + 2}")
    check('чанк не пересоздавался (число геометрий то же)', page.evaluate("window.__wf.info().geometries") == geo_before)
    page.screenshot(path=f"{OUT}/11-stump.png")

    # 4. Биомы: ищем по сетке и снимаем каждый
    found = page.evaluate("""() => {
        const names = {0: 'water', 1: 'rocks', 2: 'swamp', 3: 'conifer', 4: 'broadleaf', 5: 'birch'};
        const out = {};
        for (let r = 300; r <= 6000 && Object.keys(out).length < 6; r += 100) {
            for (let a = 0; a < 24; a++) {
                const x = Math.round(Math.cos(a / 24 * 6.2832) * r), z = Math.round(Math.sin(a / 24 * 6.2832) * r);
                const b = window.__wf.biomeAt(x, z);
                // ищем место, где биом держится вокруг, чтобы кадр был «чистым»
                let same = 0; for (let k = 0; k < 8; k++) if (window.__wf.biomeAt(x + Math.cos(k) * 30, z + Math.sin(k) * 30) === b) same++;
                if (same >= 6 && !(names[b] in out)) out[names[b]] = [x, z];
            }
        }
        return out;
    }""")
    check('в мире находятся все шесть биомов (вода, скалы, болото, хвойный, лиственный, роща)', len(found) == 6, str(sorted(found)))
    for name, (x, z) in found.items():
        page.evaluate(f"window.__wf.teleport({x}, {z})")
        settle(page, 2)
        w = page.evaluate("window.__wf.world()")
        check(f'биом {name}: чанки построены без ошибок', w['loaded'] > 20, str(w))
        page.screenshot(path=f"{OUT}/20-biome-{name}.png")

    # 5. Прогулка на 5 км: память и число объектов ограничены
    page.evaluate("window.__wf.teleport(800, 0)")
    settle(page, 1)
    for i in range(10):  # разогрев: пул пней, компиляция шейдеров, кеши
        page.evaluate(f"window.__wf.teleport({800 + i * 100}, {i * 30})"); page.evaluate("window.__wf.pumpAll()")
    page.evaluate("window.gc && window.gc()")
    heap0 = page.evaluate("performance.memory.usedJSHeapSize") / 1048576
    geo0 = page.evaluate("window.__wf.info().geometries")
    peak_geo = 0
    peak_loaded = 0
    heap_mid = None
    for i in range(10, 60):
        if i == 35:
            page.evaluate("window.gc && window.gc()")
            heap_mid = page.evaluate("performance.memory.usedJSHeapSize") / 1048576
        page.evaluate(f"window.__wf.teleport({800 + i * 100}, {i * 30})")
        page.evaluate("window.__wf.pumpAll()")
        if i % 5 == 0:
            page.wait_for_function(f"window.__wf.frames() > {page.evaluate('window.__wf.frames()') + 1}")
        peak_geo = max(peak_geo, page.evaluate("window.__wf.info().geometries"))
        peak_loaded = max(peak_loaded, page.evaluate("window.__wf.world().loaded"))
    settle(page, 2)
    page.evaluate("window.gc && window.gc()")
    heap1 = page.evaluate("performance.memory.usedJSHeapSize") / 1048576
    geo1 = page.evaluate("window.__wf.info().geometries")
    w = page.evaluate("window.__wf.world()")
    check('прогулка 5 км: чанки кольца, не накапливаются', peak_loaded <= 14 * 14, f'пик {peak_loaded}')
    check('прогулка 5 км: число геометрий ограничено кольцом (выгруженные освобождаются)', geo1 <= w['loaded'] + 12 and peak_geo <= 14 * 14 + 12, f'в конце {geo1}, чанков {w["loaded"]}, пик {peak_geo}')
    check('прогулка 5 км: JS-память растёт не больше чем на 40 МБ', heap1 - heap0 < 40, f'{heap0:.1f} -> {heap1:.1f} МБ')
    check('прогулка 5 км: во второй половине память не растёт (рост < 15 МБ, нет утечки)', heap1 - heap_mid < 15, f'{heap_mid:.1f} -> {heap1:.1f} МБ')
    check('после прогулки лес на месте', w['loaded'] > 40 and w['trees'] > 300, str(w))
    page.screenshot(path=f"{OUT}/30-after-walk.png")

    # 6. Высокое и низкое качество
    for q, calls in (('low', 80), ('high', 180)):
        page.evaluate(f"window.__wf.setQuality('{q}')")
        page.wait_for_timeout(400)
        settle(page, 3)
        info = page.evaluate("window.__wf.info()")
        check(f'{q}: вызовы отрисовки в бюджете (<= {calls})', 0 < info['calls'] <= calls, f"calls {info['calls']} tris {info['triangles']}")
        page.screenshot(path=f"{OUT}/40-quality-{q}.png")

    check('нет ошибок консоли и исключений', not errs, '; '.join(errs)[:300])
    browser.close()

httpd.shutdown()
print(f"\nИтого: {sum(results)} из {len(results)}")
sys.exit(0 if all(results) else 1)
