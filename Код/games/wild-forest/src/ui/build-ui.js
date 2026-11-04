import { S } from './strings.ru.js';

// Интерфейс стройки (ДЛ-16): каталог построек (лист), панель выбора места (повернуть, поставить, отмена) и плавающая панель
// выбранной постройки (прогресс, отменить или снести). Только показывает и сообщает о нажатиях; решает build-flow, а сервер проверяет.
export function createBuildUi({ onPick = () => {}, onRotate = () => {}, onConfirm = () => {}, onCancelPlace = () => {}, onCancelBuild = () => {}, onDemolish = () => {}, onCatalogClose = () => {} } = {}) {
    const B = S.build;
    const sheet = document.createElement('section');
    sheet.className = 'wf-sheet';
    sheet.id = 'wfBuild';
    sheet.setAttribute('aria-hidden', 'true');
    sheet.innerHTML = [
        '<div class="wf-sheet-box" role="dialog" aria-modal="true" aria-label="' + B.title + '">',
        '<div class="wf-sheet-head"><h2>🔨 ' + B.title + '</h2><button type="button" class="wf-iconbtn" id="wfBuildClose" aria-label="' + B.close + '">✕</button></div>',
        '<p class="wf-build-intro">' + B.intro + '</p>',
        '<div class="wf-build-list" id="wfBuildList"></div>',
        '</div>',
    ].join('');
    document.body.appendChild(sheet);
    const listEl = sheet.querySelector('#wfBuildList');

    const bar = document.createElement('div');
    bar.className = 'wf-placebar';
    bar.hidden = true;
    bar.innerHTML = [
        '<div class="wf-placebar-text" id="wfPlaceText"></div>',
        '<div class="wf-placebar-btns">',
        '<button type="button" class="wf-iconbtn" data-act="left" aria-label="' + B.rotateLeft + '">↺</button>',
        '<button type="button" class="wf-iconbtn" data-act="right" aria-label="' + B.rotateRight + '">↻</button>',
        '<button type="button" class="wf-btn wf-placebar-ok" data-act="ok">✓ ' + B.place + '</button>',
        '<button type="button" class="wf-btn is-ghost" data-act="cancel">✕ ' + B.cancelPlace + '</button>',
        '</div>',
    ].join('');
    document.body.appendChild(bar);
    const placeText = bar.querySelector('#wfPlaceText');
    const okBtn = bar.querySelector('[data-act="ok"]');

    const panel = document.createElement('div');
    panel.className = 'wf-buildpanel';
    panel.hidden = true;
    panel.innerHTML = [
        '<div class="wf-buildpanel-head"><span id="wfBpName"></span><span id="wfBpPct"></span></div>',
        '<div class="wf-buildpanel-track"><div class="wf-buildpanel-fill" id="wfBpFill"></div></div>',
        '<div class="wf-buildpanel-hint" id="wfBpHint"></div>',
        '<button type="button" class="wf-btn is-ghost" id="wfBpAct"></button>',
    ].join('');
    document.body.appendChild(panel);
    const bpName = panel.querySelector('#wfBpName');
    const bpPct = panel.querySelector('#wfBpPct');
    const bpFill = panel.querySelector('#wfBpFill');
    const bpHint = panel.querySelector('#wfBpHint');
    const bpAct = panel.querySelector('#wfBpAct');
    let panelId = null;
    let panelReady = false;
    let open = false;

    function setOpen(next) {
        if (open === next) return;
        open = next;
        sheet.classList.toggle('is-open', open);
        sheet.setAttribute('aria-hidden', open ? 'false' : 'true');
        if (open) document.documentElement.dataset.wfModal = 'build';
        else {
            if (document.documentElement.dataset.wfModal === 'build') delete document.documentElement.dataset.wfModal;
            onCatalogClose();
        }
    }

    sheet.addEventListener('click', (event) => {
        if (event.target === sheet) { setOpen(false); return; }
        const b = event.target.closest && event.target.closest('.wf-build-pick');
        if (b && !b.disabled) { const type = b.dataset.type; setOpen(false); onPick(type); }
    });
    sheet.querySelector('#wfBuildClose').addEventListener('click', () => setOpen(false));
    window.addEventListener('keydown', (event) => { if (open && event.key === 'Escape') setOpen(false); });
    bar.addEventListener('click', (event) => {
        const b = event.target.closest && event.target.closest('[data-act]');
        if (!b) return;
        const act = b.dataset.act;
        if (act === 'left') onRotate(-1); else if (act === 'right') onRotate(1);
        else if (act === 'ok') { if (!b.disabled) onConfirm(); } else if (act === 'cancel') onCancelPlace();
    });
    bpAct.addEventListener('click', () => { if (panelId === null) return; if (panelReady) onDemolish(panelId); else onCancelBuild(panelId); });

    function renderList(model) {
        listEl.textContent = '';
        for (const it of model.items) {
            const card = document.createElement('div');
            card.className = 'wf-build-card' + (it.status === 'ok' ? '' : ' is-off');
            const head = document.createElement('div');
            head.className = 'wf-build-head';
            head.textContent = `${it.icon} ${it.name}`;
            const about = document.createElement('div');
            about.className = 'wf-build-about';
            about.textContent = it.about;
            const cost = document.createElement('div');
            cost.className = 'wf-build-cost';
            for (const c of it.cost) {
                const chip = document.createElement('span');
                chip.className = 'wf-chip-cost' + (c.lack ? ' is-lack' : '');
                chip.textContent = `${c.icon} ${c.qty}`;
                chip.title = c.lack ? `${B.lack}: ${c.lack}` : B.have(c.have);
                cost.appendChild(chip);
            }
            const taps = document.createElement('span');
            taps.className = 'wf-chip-cost';
            taps.textContent = '🔨 ' + B.taps(it.taps);
            cost.appendChild(taps);
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'wf-btn wf-build-pick';
            btn.dataset.type = it.type;
            btn.disabled = it.status === 'locked' || it.status === 'maxed' || it.status === 'limit';
            btn.textContent = it.status === 'locked' ? B.locked(it.unlock) : it.status === 'maxed' ? B.maxed : it.status === 'limit' ? B.limit : it.status === 'lack' ? B.lack + ': ' + it.lackText : B.place;
            card.append(head, about, cost, btn);
            listEl.appendChild(card);
        }
    }

    return {
        /** model: { items: [{ type, name, icon, about, cost: [{icon, qty, have, lack}], taps, unlock, status: ok|lack|locked|maxed|limit, lackText }] } */
        openCatalog(model) { renderList(model); setOpen(true); },
        refreshCatalog(model) { if (open) renderList(model); },
        closeCatalog() { setOpen(false); },
        get catalogOpen() { return open; },
        /** Панель выбора места: text — подсказка или причина, valid — можно ли ставить. */
        showPlacing({ text, valid }) { bar.hidden = false; placeText.textContent = text; placeText.classList.toggle('is-bad', !valid); okBtn.disabled = !valid; },
        hidePlacing() { bar.hidden = true; },
        get placing() { return !bar.hidden; },
        /** Плавающая панель постройки: { id, name, icon, pct, ready, hint }. */
        showPanel({ id, name, icon, pct, ready, hint }) {
            panelId = id; panelReady = Boolean(ready);
            panel.hidden = false;
            bpName.textContent = `${icon} ${name}`;
            bpPct.textContent = ready ? B.ready : B.progress(pct);
            bpFill.style.transform = `scaleX(${Math.max(0, Math.min(1, pct / 100))})`;
            bpHint.textContent = hint || '';
            bpAct.textContent = ready ? B.demolish : B.cancel;
        },
        hidePanel() { panel.hidden = true; panelId = null; },
        get panelId() { return panelId; },
        dispose() { sheet.remove(); bar.remove(); panel.remove(); },
    };
}
