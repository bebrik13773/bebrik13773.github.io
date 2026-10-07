"""Сквозной тест генерации мира ДЛ-05 в браузере (headless Chrome).
Загружает те же ES-модули и JSON, что и игра, сверяет с золотыми тест-векторами (проверка, что путь «fetch + модуль» работает).
Запуск: python3 e2e_worldgen.py [путь_к_chrome]"""
import http.server, os, socketserver, sys, threading
from playwright.sync_api import sync_playwright

CHROME = sys.argv[1] if len(sys.argv) > 1 else '/home/claude/.cache/puppeteer/chrome/linux-131.0.6778.204/chrome-linux64/chrome'
ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..', '..'))  # папка Код/

class Quiet(http.server.SimpleHTTPRequestHandler):
    def __init__(self, *a, **k): super().__init__(*a, directory=ROOT, **k)
    def log_message(self, *a): pass

socketserver.TCPServer.allow_reuse_address = True
httpd = socketserver.TCPServer(('127.0.0.1', 0), Quiet)
PORT = httpd.server_address[1]
threading.Thread(target=httpd.serve_forever, daemon=True).start()

results = []
def check(name, cond, extra=''):
    results.append(bool(cond))
    print(('OK   ' if cond else 'FAIL ') + name + (f'  [{extra}]' if extra else ''))

SCRIPT = """
async () => {
  const { loadWorldGen } = await import('/games/wild-forest/src/world/gen.js');
  const rng = await import('/games/wild-forest/src/shared/rng.js');
  const v = await (await fetch('/games/wild-forest/tests/vectors/worldgen.json')).json();
  const out = { hashBad: 0, noiseBad: 0, pointsBad: 0, cellsBad: 0, points: 0, cells: 0, worlds: 0 };
  for (const [s, x, z, sa, h] of v.hashes) if (rng.hash(s, x, z, sa) !== h) out.hashBad++;
  for (const [s, X, Z, P, sa, n, f] of v.noises) if (rng.noise2(s, X, Z, P, sa) !== n || rng.fbm(s, X, Z, P * 8 > 6000 ? 6000 : P * 8, sa) !== f) out.noiseBad++;
  const wg = await loadWorldGen();
  const w = v.worlds[0];
  out.seedOk = wg.seed === w.seed;
  for (const r of w.points) {
    const [X, Z] = r; out.points++;
    const got = [X, Z, wg.fieldE(X, Z), wg.fieldM(X, Z), wg.fieldC(X, Z), wg.fieldR(X, Z), wg.fieldD(X, Z), wg.heightAtDm(X, Z), wg.biomeAtDm(X, Z), wg.tierAtDm(X, Z), wg.zoneAtDm(X, Z), wg.isCityZone(X, Z) ? 1 : 0, wg.isInsideWorld(X, Z) ? 1 : 0];
    if (got.join() !== r.join()) out.pointsBad++;
  }
  for (const r of w.cells) {
    out.cells++;
    const c = wg.cellAt(r[0], r[1], r[2]);
    if ([r[0], r[1], r[2], c.kind, c.ox, c.oz, c.species, c.size, c.rare, c.treasure, c.biome].join() !== r.join()) out.cellsBad++;
  }
  const t0 = performance.now();
  let trees = 0;
  for (let i = 0; i < 300; i++) trees += wg.chunkCells(200 + i, -40 + (i % 9)).length;
  out.msPerChunk = (performance.now() - t0) / 300;
  out.trees = trees;
  out.cityEmpty = wg.chunkCells(0, 0).length < 64 && wg.chunkCells(0, 0).every(c => Math.hypot(c.x, c.z) >= 118);
  return out;
}
"""

with sync_playwright() as p:
    browser = p.chromium.launch(executable_path=CHROME, headless=True, args=['--no-sandbox'])
    page = browser.new_page()
    errs = []
    bad_resp = []
    page.on('response', lambda r: bad_resp.append(f'{r.status} {r.url}') if r.status >= 400 and not r.url.endswith('favicon.ico') else None)
    page.on('console', lambda m: errs.append(m.text) if m.type == 'error' and 'Failed to load resource' not in m.text else None)
    page.on('pageerror', lambda e: errs.append('PAGEERROR ' + str(e)))
    page.goto(f'http://127.0.0.1:{PORT}/games/wild-forest/tests/')
    r = page.evaluate(SCRIPT)
    print(r)
    check('сид загруженного генератора равен сиду векторов', r['seedOk'])
    check('хеши совпадают с векторами в браузере', r['hashBad'] == 0, f"{r['hashBad']} расхождений")
    check('шум и fbm совпадают с векторами в браузере', r['noiseBad'] == 0, f"{r['noiseBad']} расхождений")
    check('10 000 точек мира совпадают', r['points'] >= 10000 and r['pointsBad'] == 0, f"{r['points']} точек, {r['pointsBad']} расхождений")
    check('10 000 клеток мира совпадают', r['cells'] >= 10000 and r['cellsBad'] == 0, f"{r['cells']} клеток, {r['cellsBad']} расхождений")
    check('в чанках вокруг есть деревья', r['trees'] > 3000, str(r['trees']))
    check('генерация чанка быстрее 8 мс (контейнер без GPU, 1 ядро)', r['msPerChunk'] < 8, f"{r['msPerChunk']:.3f} мс")
    check('чанк города (0, 0) без деревьев ближе 118 м к центру', r['cityEmpty'])
    check('нет ошибок консоли и неудачных запросов (кроме favicon)', not errs and not bad_resp, '; '.join(errs + bad_resp)[:200])
    browser.close()

httpd.shutdown()
print(f"\nИтого: {sum(results)} из {len(results)}")
sys.exit(0 if all(results) else 1)
