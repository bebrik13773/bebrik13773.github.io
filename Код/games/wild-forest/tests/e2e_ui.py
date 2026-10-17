"""Сквозной тест интерфейса ДЛ-09 (headless Chrome + SwiftShader): HUD в портрете и ландшафте без наложений, диалог, рюкзак,
уведомления, настройки. Запуск: python3 e2e_ui.py [путь_к_chrome]. Снимки: WF_SHOTS (по умолчанию /tmp/wf-shots)."""
import http.server, os, socketserver, sys, threading, time
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

HUD_SELECTORS = ['#wfEnergy', '#wfChipPack', '#wfChipPack ~ .wf-chip', '#wfGear', '#wfBell', '[data-action=backpack]', '[data-action=build]', '[data-action=helpers]', '#wfHotbar']

def rects(page):
    return page.evaluate("""(sels) => sels.flatMap(sel => [...document.querySelectorAll(sel)].filter(e => e.offsetParent !== null || getComputedStyle(e).position === 'fixed')
        .map((e, i) => { const r = e.getBoundingClientRect(); return { sel: sel + '#' + i, l: r.left, t: r.top, r: r.right, b: r.bottom, w: r.width, h: r.height }; }))""", HUD_SELECTORS)

def overlaps(a, b):
    return a['l'] < b['r'] - 1 and b['l'] < a['r'] - 1 and a['t'] < b['b'] - 1 and b['t'] < a['b'] - 1

def boot(ctx, w, h, query='?debug=1'):
    page = ctx.new_page()
    errs = []
    page.on('console', lambda m: errs.append(m.text) if m.type == 'error' and 'Failed to load resource' not in m.text else None)
    page.on('pageerror', lambda e: errs.append('PAGEERROR ' + str(e)))
    page.set_viewport_size({'width': w, 'height': h})
    page.goto(BASE + query)
    page.wait_for_function("window.__wf && window.__wf.state === 'playing' && window.__wf.frames() > 2", timeout=60000)
    page.wait_for_function("!document.getElementById('wfHud').hidden", timeout=20000)
    return page, errs

with sync_playwright() as p:
    browser = p.chromium.launch(executable_path=CHROME, headless=True, args=['--no-sandbox', '--use-gl=angle', '--use-angle=swiftshader', '--enable-unsafe-swiftshader'])
    ctx = browser.new_context(viewport={'width': 360, 'height': 800}, device_scale_factor=1, has_touch=True, is_mobile=True)
    ctx.add_init_script("if (!sessionStorage.getItem('wf_seeded')) { sessionStorage.setItem('wf_seeded', '1'); localStorage.setItem('wf.betaAckAt', String(Date.now())); }")
    page, errs = boot(ctx, 360, 800)

    # 1. HUD виден, содержимое есть
    check('HUD показан после загрузки', page.evaluate("!document.getElementById('wfHud').hidden && !document.getElementById('wfHotbar').hidden"))
    check('энергия 100 / 100', page.inner_text('#wfEnergyText').strip() == '100 / 100', page.inner_text('#wfEnergyText'))
    check('рюкзак 0 / 40 кг', page.inner_text('#wfPackText').strip() == '0 / 40 кг', page.inner_text('#wfPackText'))
    check('часы и сезон заполнены', page.inner_text('#wfClockText').strip() != '' and page.inner_text('#wfSeasonText').strip() != '')
    check('в хотбаре 6 слотов, первый выбран, топор', page.locator('.wf-slot').count() == 6 and page.get_attribute('.wf-slot[data-slot="0"]', 'aria-pressed') == 'true' and '🪓' in page.inner_text('.wf-slot[data-slot="0"]'))
    page.screenshot(path=f'{OUT}/ui_portrait.png')

    # 2. Читаемость: нет наложений, цели касания не меньше 44 px, ничего не выходит за экран
    def audit(tag, w, h):
        rs = rects(page)
        bad = [(a['sel'], b['sel']) for i, a in enumerate(rs) for b in rs[i + 1:] if overlaps(a, b)]
        check(f'{tag}: HUD без наложений', not bad, str(bad[:3]))
        out = [r['sel'] for r in rs if r['l'] < -1 or r['t'] < -1 or r['r'] > w + 1 or r['b'] > h + 1]
        check(f'{tag}: HUD в пределах экрана', not out, str(out[:3]))
        small = page.evaluate("""() => [...document.querySelectorAll('.wf-qbtn, .wf-slot, #wfGear')].filter(e => { const r = e.getBoundingClientRect(); return r.width < 44 || r.height < 44; }).map(e => e.className || e.id)""")
        check(f'{tag}: кнопки не меньше 44 px', not small, str(small))
    audit('360x800', 360, 800)

    # 3. Энергия: предупреждения и значок меняются
    page.evaluate("window.__wf.ui.hud.set({ energy: 18 })")
    check('энергия 18%: уровень low и значок 🪫', page.get_attribute('#wfEnergy', 'data-level') == 'low' and '🪫' in page.inner_text('#wfEnergyIcon'))
    check('предупреждение об энергии в центре уведомлений', page.locator('.wf-toast', has_text='Энергия на исходе').count() == 1)
    page.evaluate("window.__wf.ui.hud.set({ energy: 8 })")
    check('энергия 8%: critical и значок 💤', page.get_attribute('#wfEnergy', 'data-level') == 'critical' and '💤' in page.inner_text('#wfEnergyIcon'))
    page.evaluate("window.__wf.ui.hud.set({ energy: 100 })")
    check('энергия снова ok', page.get_attribute('#wfEnergy', 'data-level') == 'ok')
    page.evaluate("window.__wf.ui.hud.set({ packKg: 33, coins: 25000, weather: 'rain' })")
    check('рюкзак тяжёлый, монеты отформатированы, погода видна', page.get_attribute('#wfChipPack', 'data-level') == 'heavy' and '25' in page.inner_text('#wfCoinsText') and not page.evaluate("document.getElementById('wfChipWeather').hidden"))
    audit('360x800 (все чипы)', 360, 800)
    page.screenshot(path=f'{OUT}/ui_portrait_full.png')

    # 4. Центр уведомлений
    page.click('#wfBell')
    check('колокольчик открывает центр уведомлений', page.evaluate("window.__wf.ui.notifyCenter.isOpen") and page.locator('.wf-note').count() >= 1)
    check('после открытия уведомления прочитаны', page.evaluate("window.__wf.ui.notifyCenter.unread") == 0)
    page.screenshot(path=f'{OUT}/ui_notify.png')
    page.evaluate("window.__wf.ui.notifyCenter.push({ key: 'dam', level: 'danger', text: 'Плотина трещит!', sticky: true })")
    page.click('#wfNotesClear')
    check('«Очистить» оставляет липкое предупреждение плотины', page.locator('.wf-note').count() == 1 and 'Плотина' in page.inner_text('.wf-note'))
    page.evaluate("window.__wf.ui.notifyCenter.resolve('dam')")
    check('resolve снимает предупреждение', page.evaluate("window.__wf.ui.notifyCenter.count") == 0)
    page.click('#wfBell')

    # 5. Рюкзак
    page.evaluate("window.__wf.ui.inventory.setItems([{key:'log',qty:5,kg:4},{key:'plank',qty:3,kg:3},{key:'fish_small',qty:2,kg:1,edible:true}])")
    page.click('[data-action=backpack]')
    check('рюкзак открывается, 3 предмета', page.evaluate("window.__wf.ui.inventory.isOpen") and page.locator('.wf-cell').count() == 3)
    check('вес = 31 кг и виден в HUD', page.evaluate("window.__wf.ui.inventory.weight") == 31 and page.inner_text('#wfPackText').startswith('31'), page.inner_text('#wfPackText'))
    page.click('.wf-cell[data-key=log]')
    check('выбор показывает действия', page.locator('#wfInvDetail .wf-btn').count() >= 2)
    page.click('text=Бросить 1')
    # Без связи с сервером бросить нельзя (ДЛ-13: бросок идёт действием drop), рюкзак не меняется
    check('бросить без сервера: рюкзак не меняется', [i['qty'] for i in page.evaluate("window.__wf.ui.inventory.items") if i['key'] == 'log'] == [5])
    page.evaluate("window.__wf.ui.inventory.remove('log', 5)")
    check('убрать предметы из панели (локально): брёвен нет', not any(i['key'] == 'log' for i in page.evaluate("window.__wf.ui.inventory.items")))
    page.screenshot(path=f'{OUT}/ui_inventory.png')
    page.click('#wfInvSort')
    check('кнопка сортировки меняет режим', 'названию' in page.inner_text('#wfInvSort') or 'количеству' in page.inner_text('#wfInvSort'))
    page.click('#wfInvClose')
    check('рюкзак закрывается', not page.evaluate("window.__wf.ui.inventory.isOpen"))
    page.evaluate("window.__wf.ui.inventory.setItems([])")
    check('пустой рюкзак: подсказка и вес 0', page.evaluate("window.__wf.ui.inventory.weight") == 0)

    # 6. Диалог
    page.evaluate("""window.__dlg = []; window.__wf.ui.dialog.say({ speaker: 'Седой Дуб', portrait: '🧓', text: 'Эй, малыш! Давай покажу, как тут всё устроено?', choices: [{ id: 'yes', label: 'Давай' }, { id: 'no', label: 'Я сам' }] }).then(r => window.__dlg.push(r)); undefined""")
    page.wait_for_function("document.getElementById('wfDialog').classList.contains('is-open')")
    check('диалог открылся с именем', page.text_content('#wfDlgSpeaker') == 'Седой Дуб', str(page.text_content('#wfDlgSpeaker')))
    page.screenshot(path=f'{OUT}/ui_dialog_typing.png')
    page.click('#wfDlgText')
    check('тап по тексту показывает всё сразу и варианты', page.inner_text('#wfDlgText').endswith('устроено?') and page.locator('.wf-choice').count() == 2)
    p0 = page.evaluate("window.__wf.heroPos()")
    page.mouse.click(180, 300)
    page.wait_for_timeout(300)
    check('пока открыт диалог, тап по миру не двигает бобра', page.evaluate("window.__wf.hero().mode") in ('idle',), str(page.evaluate("window.__wf.hero()")))
    page.screenshot(path=f'{OUT}/ui_dialog.png')
    page.click('.wf-choice[data-id=no]')
    page.wait_for_function("window.__dlg.length === 1")
    check('диалог вернул выбранный вариант', page.evaluate("window.__dlg[0].id") == 'no' and page.evaluate("window.__dlg[0].timedOut") is False)
    check('после ответа диалог закрыт', not page.evaluate("window.__wf.ui.dialog.isOpen"))

    # очередь и реплика без вариантов
    page.evaluate("""window.__q = []; const d = window.__wf.ui.dialog;
        d.say({ text: 'Первая', cps: 1000 }).then(r => window.__q.push('a:' + r.id));
        d.say({ text: 'Вторая', cps: 1000, choices: [{ id: 'x', label: 'Ок' }] }).then(r => window.__q.push('b:' + r.id)); undefined""")
    page.wait_for_selector('.wf-choice')
    check('в очереди ждёт вторая реплика', page.evaluate("window.__wf.ui.dialog.queued") == 1 and page.inner_text('.wf-choice') == 'Дальше')
    page.click('.wf-choice')
    page.wait_for_function("document.querySelector('.wf-choice') && document.querySelector('.wf-choice').dataset.id === 'x'")
    page.click('.wf-choice')
    page.wait_for_function("window.__q.length === 2")
    check('очередь прошла по порядку', page.evaluate("window.__q") == ['a:next', 'b:x'])

    # таймаут выбора
    page.evaluate("""window.__t = null; window.__wf.ui.dialog.say({ text: 'Торопись', cps: 1000, choices: [{ id: 'a', label: 'Да' }, { id: 'b', label: 'Нет' }], timeoutMs: 700, defaultId: 'b' }).then(r => window.__t = r); undefined""")
    page.wait_for_function("window.__t !== null", timeout=15000)
    check('по таймеру выбирается вариант по умолчанию', page.evaluate("window.__t.id") == 'b' and page.evaluate("window.__t.timedOut") is True)
    page.evaluate("window.__wf.ui.dialog.say({ text: 'Отмена', choices: [{ id: 'a', label: 'Да' }] }); undefined")
    page.evaluate("window.__wf.ui.dialog.cancelAll()")
    check('cancelAll закрывает диалог', not page.evaluate("window.__wf.ui.dialog.isOpen") and not page.evaluate("document.documentElement.dataset.wfModal"))

    # 7. Хотбар
    page.click('.wf-slot[data-slot="3"]')
    check('тап по слоту выбирает его', page.evaluate("window.__wf.ui.hud.selected") == 3)
    page.keyboard.press('2')
    check('клавиша 2 выбирает второй слот', page.evaluate("window.__wf.ui.hud.selected") == 1)

    # 8. Настройки
    page.click('#wfGear')
    check('настройки открываются', page.evaluate("window.__wf.ui.settings.isOpen"))
    page.screenshot(path=f'{OUT}/ui_settings.png')
    page.fill('#wfVolSfx', '30')
    page.fill('#wfVolMusic', '65')
    check('ползунки пишут проценты', page.inner_text('#wfVolSfxOut') == '30%' and page.inner_text('#wfVolMusicOut') == '65%')
    page.click('#wfTutorialRepeat')
    check('«Повторить обучение» отвечает уведомлением', page.evaluate("window.__wf.ui.notifyCenter.count") >= 1)
    page.click('#wfGear'); page.click('#wfExit')
    check('«Выйти» просит подтверждения', 'Выйти' in page.inner_text('#wfNotice') and page.evaluate("document.getElementById('wfNotice').classList.contains('is-open')"))
    page.click('#wfNotice .is-ghost')
    page.click('#wfBell')
    check('центр уведомлений закрывает настройки', not page.evaluate("window.__wf.ui.settings.isOpen"))

    # 9. Громкость и выбранный слот переживают перезагрузку
    page.close()
    page2, errs2 = boot(ctx, 360, 800)
    vols = page2.evaluate("window.__wf.ui.settings.volumes()")
    check('громкость сохранилась после перезагрузки', abs(vols['sfx'] - 0.3) < 1e-9 and abs(vols['music'] - 0.65) < 1e-9, str(vols))
    check('выбранный слот сохранился', page2.evaluate("window.__wf.ui.hud.selected") == 1)
    check('значение ползунка восстановлено', page2.input_value('#wfVolSfx') == '30')

    # 10. Ландшафт и узкий экран (каждый размер в новой странице: SwiftShader не любит смену размера «на лету»)
    page2.close()
    page3, errs3 = boot(ctx, 800, 360)
    page3.evaluate("window.__wf.ui.hud.set({ packKg: 33, coins: 1250000, weather: 'storm' })")
    page = page3
    audit('800x360', 800, 360)
    page3.screenshot(path=f'{OUT}/ui_landscape.png')
    page3.close()
    page4, errs4 = boot(ctx, 320, 640)
    page4.evaluate("window.__wf.ui.hud.set({ packKg: 33, coins: 1250000, weather: 'storm' })")
    page = page4
    audit('320x640', 320, 640)
    page4.screenshot(path=f'{OUT}/ui_narrow.png')
    errs2 = errs2 + errs3 + errs4
    check('ошибок в консоли нет', not errs and not errs2, str((errs + errs2)[:3]))
    browser.close()

passed = sum(results)
print(f'\nИтого: {passed} из {len(results)}')
sys.exit(0 if passed == len(results) else 1)
