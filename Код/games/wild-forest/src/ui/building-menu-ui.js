import { S } from './strings.ru.js';

// Меню готовой постройки (ДЛ-17): название и уровень, пара строк о том, что здесь можно, кнопки действий (открыть склад, лечь спать, верстак),
// блок «Улучшить» с ценой и кнопка «Снести». Только показывает и сообщает о нажатиях; решает build-flow, а сервер проверяет.
export function createBuildingMenuUi({ onAction = () => {}, onUpgrade = () => {}, onDemolish = () => {}, onClose = () => {} } = {}) {
    const M = S.menu;
    const sheet = document.createElement('section');
    sheet.className = 'wf-sheet';
    sheet.id = 'wfBuildMenu';
    sheet.setAttribute('aria-hidden', 'true');
    sheet.innerHTML = [
        '<div class="wf-sheet-box" role="dialog" aria-modal="true">',
        '<div class="wf-sheet-head"><h2 data-role="title"></h2><button type="button" class="wf-iconbtn" data-role="close" aria-label="' + M.close + '">✕</button></div>',
        '<div class="wf-build-about" data-role="lines"></div>',
        '<div class="wf-build-list" data-role="actions"></div>',
        '<div class="wf-build-card" data-role="upgrade"></div>',
        '<button type="button" class="wf-btn is-ghost" data-role="demolish">' + M.demolish + '</button>',
        '</div>',
    ].join('');
    document.body.appendChild(sheet);
    const q = (r) => sheet.querySelector(`[data-role="${r}"]`);
    const titleEl = q('title');
    const linesEl = q('lines');
    const actionsEl = q('actions');
    const upEl = q('upgrade');
    const demoEl = q('demolish');
    let open = false;
    let curId = null;

    function setOpen(next) {
        if (open === next) return;
        open = next;
        sheet.classList.toggle('is-open', open);
        sheet.setAttribute('aria-hidden', open ? 'false' : 'true');
        if (open) document.documentElement.dataset.wfModal = 'buildmenu';
        else {
            if (document.documentElement.dataset.wfModal === 'buildmenu') delete document.documentElement.dataset.wfModal;
            curId = null;
            onClose();
        }
    }

    function paint(m) {
        curId = m.id;
        titleEl.textContent = `${m.icon} ${m.name} · ${M.level(m.level, m.maxLevel)}`;
        linesEl.textContent = '';
        for (const t of m.lines || []) { const p = document.createElement('p'); p.textContent = t; linesEl.appendChild(p); }
        actionsEl.textContent = '';
        for (const a of m.actions || []) {
            const b = document.createElement('button');
            b.type = 'button';
            b.className = 'wf-btn';
            b.dataset.act = a.id;
            b.textContent = a.label;
            actionsEl.appendChild(b);
        }
        upEl.textContent = '';
        const u = m.upgrade;
        if (u) {
            const head = document.createElement('div');
            head.className = 'wf-build-head';
            head.textContent = u.status === 'max' ? M.maxLevel : `⬆️ ${M.upgradeTo(u.to)}`;
            upEl.appendChild(head);
            if (u.note) { const n = document.createElement('div'); n.className = 'wf-build-about'; n.textContent = u.note; upEl.appendChild(n); }
            if (u.status !== 'max') {
                const cost = document.createElement('div');
                cost.className = 'wf-build-cost';
                for (const c of u.cost) {
                    const chip = document.createElement('span');
                    chip.className = 'wf-chip-cost' + (c.lack ? ' is-lack' : '');
                    chip.textContent = `${c.icon} ${c.qty}`;
                    chip.title = c.lack ? `${M.lack}: ${c.lack}` : M.have(c.have);
                    cost.appendChild(chip);
                }
                const taps = document.createElement('span');
                taps.className = 'wf-chip-cost';
                taps.textContent = '🔨 ' + M.taps(u.taps);
                cost.appendChild(taps);
                upEl.appendChild(cost);
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'wf-btn';
                btn.dataset.act = '__upgrade';
                btn.disabled = u.status === 'lack';
                btn.textContent = u.status === 'lack' ? `${M.lack}: ${u.lackText}` : M.upgrade;
                upEl.appendChild(btn);
            }
        }
        upEl.hidden = !u;
    }

    sheet.addEventListener('click', (event) => {
        if (event.target === sheet) { setOpen(false); return; }
        const b = event.target.closest && event.target.closest('button[data-act]');
        if (!b || b.disabled || curId === null) return;
        const id = curId;
        if (b.dataset.act === '__upgrade') { setOpen(false); onUpgrade(id); } else { onAction(b.dataset.act, id); }
    });
    q('close').addEventListener('click', () => setOpen(false));
    demoEl.addEventListener('click', () => { if (curId === null) return; const id = curId; setOpen(false); onDemolish(id); });
    window.addEventListener('keydown', (event) => { if (open && event.key === 'Escape') setOpen(false); });

    return {
        /** m: { id, name, icon, level, maxLevel, lines: [], actions: [{id, label}], upgrade: null | { to, cost: [{icon, qty, have, lack}], taps, status: ok|lack|max, lackText, note } } */
        open(m) { paint(m); setOpen(true); },
        refresh(m) { if (open && curId === m.id) paint(m); },
        close() { setOpen(false); },
        get isOpen() { return open; },
        get currentId() { return curId; },
        dispose() { sheet.remove(); },
    };
}
