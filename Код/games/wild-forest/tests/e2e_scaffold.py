"""Сквозной тест каркаса ДЛ-04 (headless Chrome + SwiftShader, без GPU).
Запуск: python3 e2e_scaffold.py [путь_к_chrome]
Проверяет корректность, а не производительность (в контейнере 1 ядро и нет GPU)."""
import http.server, os, socketserver, sys, threading, time
from playwright.sync_api import sync_playwright

CHROME = sys.argv[1] if len(sys.argv) > 1 else '/home/claude/.cache/puppeteer/chrome/linux-131.0.6778.204/chrome-linux64/chrome'
ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..', '..'))  # папка Код/
OUT = os.environ.get('WF_SHOTS', '/tmp/wf-shots')
os.makedirs(OUT, exist_ok=True)

class Quiet(http.server.SimpleHTTPRequestHandler):
    def __init__(self, *a, **k): super().__init__(*a, directory=ROOT, **k)
    def log_message(self, *a): pass

socketserver.TCPServer.allow_reuse_address = True
httpd = socketserver.TCPServer(('127.0.0.1', 0), Quiet)
PORT = httpd.server_address[1]
threading.Thread(target=httpd.serve_forever, daemon=True).start()
BASE = f'http://127.0.0.1:{PORT}/games/wild-forest/'

results = []
def check(name, cond, extra=''):
    results.append((name, bool(cond)))
    print(('OK   ' if cond else 'FAIL ') + name + (f'  [{extra}]' if extra else ''))

def wait_playing(page, timeout=30000):
    page.wait_for_function("window.__wf && window.__wf.state === 'playing' && window.__wf.frames() > 2", timeout=timeout)

def new_page(browser, w=390, h=800, init=None, mobile=True):
    ctx = browser.new_context(viewport={'width': w, 'height': h}, device_scale_factor=3, has_touch=mobile, is_mobile=mobile)
    if init: ctx.add_init_script(init)
    page = ctx.new_page()
    errs = []
    page.on('console', lambda m: errs.append(m.text) if m.type == 'error' else None)
    page.on('pageerror', lambda e: errs.append('PAGEERROR ' + str(e)))
    return ctx, page, errs

with sync_playwright() as p:
    browser = p.chromium.launch(executable_path=CHROME, headless=True, args=['--no-sandbox', '--use-gl=angle', '--use-angle=swiftshader', '--enable-unsafe-swiftshader'])

    # 1. Запуск и базовая работа
    ctx, page, errs = new_page(browser)
    page.goto(BASE + '?debug=1')
    wait_playing(page)
    page.wait_for_timeout(1200)
    real_errs = [e for e in errs if 'Failed to load resource' not in e]
    check('страница грузится без ошибок консоли (кроме 404 health на статике)', not real_errs, '; '.join(real_errs)[:200])
    check('экран загрузки скрыт', page.evaluate("document.getElementById('wfLoading').classList.contains('is-hidden')"))
    check('состояние playing', page.evaluate("window.__wf.state") == 'playing')
    info = page.evaluate("window.__wf.info()")
    check('рендерер рисует (calls > 0, triangles > 0)', info['calls'] > 0 and info['triangles'] > 0, str(info))
    check('noindex в meta', page.evaluate("document.querySelector('meta[name=robots]').content.includes('noindex')"))
    check('build id отображается', 'build' in (page.text_content('#wfBuild') or ''))
    check('окно беты показано при первом входе', page.evaluate("document.getElementById('wfBetaModal').classList.contains('is-open')"))
    page.screenshot(path=f'{OUT}/00-beta-modal.png')
    page.click('#wfBetaAck'); page.wait_for_timeout(300)
    check('окно беты закрывается', not page.evaluate("document.getElementById('wfBetaModal').classList.contains('is-open')"))
    check('сервер недоступен не фатален (шаг warn)', page.evaluate("document.querySelector('#wfSteps li[data-step=server]').classList.contains('is-warn')"))
    check('нет горизонтальной прокрутки', page.evaluate("document.documentElement.scrollWidth <= window.innerWidth"))
    box = page.eval_on_selector('#wfGear', 'e => { const r = e.getBoundingClientRect(); return [r.width, r.height]; }')
    check('кнопка настроек не меньше 44 px', box[0] >= 44 and box[1] >= 44, str(box))
    page.screenshot(path=f'{OUT}/01-medium-portrait.png')

    # 2. Качество
    page.click('#wfGear')
    sizes = {}
    worlds = {}
    for q, scale, radius in (('low', 0.6, 3), ('high', 1.0, 7), ('medium', 0.8, 5)):
        page.click(f'#wfQuality [data-q={q}]')
        page.wait_for_timeout(500)
        wait_playing(page)
        page.evaluate("window.__wf.pumpAll()")  # достраиваем очередь чанков, чтобы сравнивать готовый мир
        info = page.evaluate("window.__wf.info()")
        dpr = min(3, 2) * scale
        check(f'{q}: pixelRatio = min(dpr,2) x {scale}', abs(info['pixelRatio'] - dpr) < 0.01, f"{info['pixelRatio']}")
        check(f'{q}: pressed в меню', page.get_attribute(f'#wfQuality [data-q={q}]', 'aria-pressed') == 'true')
        sizes[q] = info['width']
        worlds[q] = page.evaluate("window.__wf.world()")
        dbg = page.inner_text('#wfDebug')
        check(f'{q}: радиус кольца чанков {radius} (загружено > 0 и в пределах круга)', 0 < worlds[q]['loaded'] <= (2 * radius + 3) ** 2, str(worlds[q]))
        check(f'{q}: debug показывает чанки и деревья', 'chunks' in dbg and 'trees' in dbg, dbg.split('\n')[-2])
        if q != 'medium': page.screenshot(path=f'{OUT}/02-{q}.png')
    check('чанков и деревьев больше с ростом качества low < medium < high', worlds['low']['loaded'] < worlds['medium']['loaded'] < worlds['high']['loaded'] and worlds['low']['trees'] < worlds['medium']['trees'] < worlds['high']['trees'], str(worlds))
    check('размер буфера растёт low < medium < high', sizes['low'] < sizes['medium'] < sizes['high'], str(sizes))
    page.click('#wfQuality [data-q=high]')
    page.wait_for_timeout(500); wait_playing(page)
    check('после смены сглаживания рендерер жив (канвас пересоздан, кадры идут)', page.evaluate("window.__wf.info().calls") > 0)
    check('канвас один', page.evaluate("document.querySelectorAll('canvas').length") == 1)
    page.reload(); wait_playing(page)
    check('выбор качества сохраняется после перезагрузки', page.evaluate("window.__wf.quality") == 'high')
    page.evaluate("window.__wf.setQuality('medium')")

    # 3. Потеря и восстановление контекста
    page.wait_for_timeout(300)
    check('loseContext доступен', page.evaluate("window.__wf.loseContext()"))
    page.wait_for_timeout(500)
    check('при потере контекста цикл на паузе', page.evaluate("window.__wf.loopRunning()") is False)
    check('показано уведомление о приостановке', page.evaluate("document.getElementById('wfNotice').classList.contains('is-open')"))
    page.evaluate("window.__wf.restoreContext()")
    page.wait_for_function("window.__wf.loopRunning() === true", timeout=8000)
    f0 = page.evaluate("window.__wf.frames()"); page.wait_for_timeout(600)
    check('после восстановления контекста кадры идут', page.evaluate("window.__wf.frames()") > f0)

    # 4. Скрытая вкладка ставит цикл на паузу
    page.evaluate("Object.defineProperty(document, 'hidden', {configurable: true, get: () => true}); document.dispatchEvent(new Event('visibilitychange'))")
    page.wait_for_timeout(200)
    check('скрытая вкладка: цикл стоит', page.evaluate("window.__wf.loopRunning()") is False)
    f0 = page.evaluate("window.__wf.frames()"); page.wait_for_timeout(400)
    check('скрытая вкладка: кадры не растут', page.evaluate("window.__wf.frames()") == f0)
    page.evaluate("Object.defineProperty(document, 'hidden', {configurable: true, get: () => false}); document.dispatchEvent(new Event('visibilitychange'))")
    page.wait_for_function("window.__wf.loopRunning() === true", timeout=5000)
    check('возврат на вкладку: цикл идёт', True)

    # 5. Автоподсказка снизить качество
    page.evaluate("window.__wf.setQuality('high')"); page.wait_for_timeout(300)
    check('suggestLower(10) показывает подсказку', page.evaluate("window.__wf.suggestLower(10)"))
    check('suggestLower(40) не показывает', page.evaluate("window.__wf.suggestLower(40)") is False)
    page.evaluate("window.__wf.suggestLower(10)")
    page.click('#wfNotice button:has-text("Перейти")')
    page.wait_for_timeout(300)
    check('кнопка подсказки понижает качество high -> medium', page.evaluate("window.__wf.quality") == 'medium')

    # 6. Ошибки: одна даёт уведомление, серия даёт экран ошибки
    page.evaluate("window.__wf.throwError('одна')"); page.wait_for_timeout(300)
    check('одиночная ошибка: уведомление, игра идёт', page.evaluate("document.getElementById('wfNotice').classList.contains('is-open')") and page.evaluate("window.__wf.state") == 'playing')
    for i in range(6): page.evaluate("window.__wf.throwError('серия')")
    page.wait_for_timeout(500)
    check('серия ошибок: экран ошибки', not page.evaluate("document.getElementById('wfError').classList.contains('is-hidden')"))
    check('серия ошибок: состояние error', page.evaluate("document.documentElement.dataset.wfState") == 'error')
    check('на экране ошибки есть ссылка на старый кликер', page.locator('#wfErrorActions a').count() == 1)
    page.screenshot(path=f'{OUT}/03-error.png')
    check('журнал ошибок пишется в localStorage', page.evaluate("JSON.parse(localStorage.getItem('wf.errlog')||'[]').length") >= 6)
    ctx.close()

    # 7. Нет WebGL
    nowebgl = "const g = HTMLCanvasElement.prototype.getContext; HTMLCanvasElement.prototype.getContext = function(t, ...a){ if (/webgl/.test(t)) return null; return g.call(this, t, ...a); };"
    ctx, page, errs = new_page(browser, init=nowebgl)
    page.goto(BASE); page.wait_for_timeout(1200)
    check('нет WebGL: показан экран ошибки', not page.evaluate("document.getElementById('wfError').classList.contains('is-hidden')"))
    check('нет WebGL: понятный заголовок', 'Нет поддержки 3D' in page.inner_text('#wfErrorTitle'))
    check('нет WebGL: ссылка на старую игру', 'clicker' in (page.get_attribute('#wfErrorActions a', 'href') or ''))
    check('нет WebGL: экран загрузки скрыт', page.evaluate("document.getElementById('wfLoading').classList.contains('is-hidden')"))
    page.screenshot(path=f'{OUT}/04-no-webgl.png')
    ctx.close()

    # 8. Ландшафт 800x360 и узкий экран 360x640
    for (w, h, tag) in ((800, 360, 'landscape'), (360, 640, 'narrow')):
        ctx, page, errs = new_page(browser, w, h)
        page.goto(BASE + '?debug=1'); wait_playing(page)
        page.wait_for_timeout(900); page.click('#wfBetaAck'); page.wait_for_timeout(300)
        page.click('#wfGear'); page.wait_for_timeout(300)
        check(f'{tag}: нет горизонтальной прокрутки', page.evaluate("document.documentElement.scrollWidth <= window.innerWidth"))
        r = page.eval_on_selector('#wfPanel', 'e => { const r = e.getBoundingClientRect(); return [r.left, r.right, r.bottom]; }')
        check(f'{tag}: панель настроек влезает в экран', r[0] >= 0 and r[1] <= w and r[2] <= h, str(r))
        page.screenshot(path=f'{OUT}/05-{tag}.png')
        ctx.close()

    browser.close()

httpd.shutdown()
failed = [n for n, ok in results if not ok]
print(f'\nИтого: {len(results) - len(failed)} из {len(results)} пройдено')
sys.exit(1 if failed else 0)
