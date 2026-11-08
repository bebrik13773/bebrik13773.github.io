// Окно сада (ДЛ-19): места посадки по кругу, выбор семени, полив, уборка засохшего и рубка выросшего дерева (серия тапов).
// Только показывает и сообщает о нажатиях; решает garden-flow, а сервер проверяет.
export function createGardenUi({ onPlant = () => {}, onWater = () => {}, onClear = () => {}, onChopStart = () => {}, onChopTap = () => {}, onChopStop = () => {}, onClose = () => {} } = {}) {
    const sheet = document.createElement('section');
    sheet.className = 'wf-sheet';
    sheet.id = 'wfGarden';
    sheet.setAttribute('aria-hidden', 'true');
    sheet.innerHTML = [
        '<div class="wf-sheet-box" role="dialog" aria-modal="true">',
        '<div class="wf-sheet-head"><h2 data-role="title"></h2><button type="button" class="wf-iconbtn" data-role="close" aria-label="Закрыть">✕</button></div>',
        '<div class="wf-build-about" data-role="about"></div>',
        '<div class="wf-build-list" data-role="rows"></div>',
        '<div class="wf-build-card" data-role="seeds" hidden></div>',
        '<div class="wf-build-card" data-role="chop" hidden></div>',
        '</div>',
    ].join('');
    document.body.appendChild(sheet);
    const q = (r) => sheet.querySelector(`[data-role="${r}"]`);
    const titleEl = q('title'); const aboutEl = q('about'); const rowsEl = q('rows'); const seedsEl = q('seeds'); const chopEl = q('chop');
    let open = false;
    let pickSlot = null;   // место, для которого выбираем семя

    function setOpen(next) {
        if (open === next) return;
        open = next;
        sheet.classList.toggle('is-open', open);
        sheet.setAttribute('aria-hidden', open ? 'false' : 'true');
        if (open) document.documentElement.dataset.wfModal = 'garden';
        else {
            if (document.documentElement.dataset.wfModal === 'garden') delete document.documentElement.dataset.wfModal;
            pickSlot = null;
            onChopStop();
            onClose();
        }
    }
    const btn = (label, data, cls = '') => {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = `wf-btn ${cls}`.trim();
        for (const [k, v] of Object.entries(data)) b.dataset[k] = String(v);
        b.textContent = label;
        return b;
    };

    /**
     * m: { title, about, rows: [{ slot, icon, text, sub, bar (0..1), actions: [{ act, label, disabled }] }],
     *      seeds: null | { title, empty, items: [{ item, icon, name, sub, qty }], cancel },
     *      chop: null | { title, hits, need, tap, stop } }
     */
    function paint(m) {
        titleEl.textContent = m.title;
        aboutEl.textContent = m.about || '';
        rowsEl.textContent = '';
        for (const r of m.rows) {
            const row = document.createElement('div');
            row.className = 'wf-build-card';
            const head = document.createElement('div');
            head.className = 'wf-build-head';
            head.textContent = `${r.icon} ${r.text}`;
            row.appendChild(head);
            if (r.sub) { const p = document.createElement('div'); p.className = 'wf-build-about'; p.textContent = r.sub; row.appendChild(p); }
            if (r.bar !== undefined && r.bar !== null) {
                const bar = document.createElement('div');
                bar.className = 'wf-garden-bar';
                bar.style.cssText = 'height:6px;border-radius:3px;background:rgba(0,0,0,.25);overflow:hidden;margin:4px 0';
                const fill = document.createElement('div');
                fill.style.cssText = `height:100%;width:${Math.round(Math.max(0, Math.min(1, r.bar)) * 100)}%;background:#6fbf4a`;
                bar.appendChild(fill);
                row.appendChild(bar);
            }
            for (const a of r.actions || []) {
                const b = btn(a.label, { act: a.act, slot: r.slot, id: a.id !== undefined ? a.id : '' });
                b.disabled = Boolean(a.disabled);
                row.appendChild(b);
            }
            rowsEl.appendChild(row);
        }
        seedsEl.hidden = !m.seeds;
        seedsEl.textContent = '';
        if (m.seeds) {
            const head = document.createElement('div');
            head.className = 'wf-build-head';
            head.textContent = m.seeds.title;
            seedsEl.appendChild(head);
            if (!m.seeds.items.length) { const p = document.createElement('div'); p.className = 'wf-build-about'; p.textContent = m.seeds.empty; seedsEl.appendChild(p); }
            for (const it of m.seeds.items) seedsEl.appendChild(btn(`${it.icon} ${it.name} ×${it.qty}${it.sub ? ` · ${it.sub}` : ''}`, { act: '__seed', item: it.item }));
            seedsEl.appendChild(btn(m.seeds.cancel, { act: '__seedcancel' }, 'is-ghost'));
        }
        chopEl.hidden = !m.chop;
        chopEl.textContent = '';
        if (m.chop) {
            const head = document.createElement('div');
            head.className = 'wf-build-head';
            head.textContent = `${m.chop.title}: ${m.chop.hits} / ${m.chop.need}`;
            chopEl.appendChild(head);
            const tap = btn(m.chop.tap, { act: '__chop' });
            tap.style.cssText = 'font-size:1.4em;padding:18px;width:100%';
            chopEl.appendChild(tap);
            chopEl.appendChild(btn(m.chop.stop, { act: '__chopstop' }, 'is-ghost'));
        }
    }

    sheet.addEventListener('click', (event) => {
        if (event.target === sheet) { setOpen(false); return; }
        const b = event.target.closest && event.target.closest('button[data-act]');
        if (!b || b.disabled) return;
        const act = b.dataset.act;
        if (act === '__seed') { const slot = pickSlot; pickSlot = null; if (slot !== null) onPlant(slot, b.dataset.item); return; }
        if (act === '__seedcancel') { pickSlot = null; api.requestRefresh(); return; }
        if (act === '__chop') { onChopTap(); return; }
        if (act === '__chopstop') { onChopStop(); api.requestRefresh(); return; }
        const slot = Number(b.dataset.slot);
        if (act === 'plant') { pickSlot = slot; api.requestRefresh(); } else if (act === 'water') onWater(Number(b.dataset.id));
        else if (act === 'clear') onClear(Number(b.dataset.id)); else if (act === 'chop') onChopStart(Number(b.dataset.id));
    });
    q('close').addEventListener('click', () => setOpen(false));
    window.addEventListener('keydown', (event) => { if (open && event.key === 'Escape') setOpen(false); });

    const api = {
        requestRefresh() { /* заменяется flow */ },
        open(m) { paint(m); setOpen(true); },
        refresh(m) { if (open) paint(m); },
        close() { setOpen(false); },
        get isOpen() { return open; },
        get pickSlot() { return pickSlot; },
        dispose() { sheet.remove(); },
    };

    return api;
}
