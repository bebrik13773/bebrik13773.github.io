import { S } from './strings.ru.js';

// Окно верстака (ДЛ-18): список улучшений бобра с подписью «было → станет», ценой и кнопкой «Сделать».
// Только показывает и сообщает о нажатии; решает bench-flow, а сервер проверяет.
export function createBenchUi({ onBuy = () => {}, onClose = () => {} } = {}) {
    const B = S.bench;
    const sheet = document.createElement('section');
    sheet.className = 'wf-sheet';
    sheet.id = 'wfBench';
    sheet.setAttribute('aria-hidden', 'true');
    sheet.innerHTML = '<div class="wf-sheet-box" role="dialog" aria-modal="true"><div class="wf-sheet-head"><h2>🔨 ' + B.title + '</h2><button type="button" class="wf-iconbtn" data-role="close" aria-label="' + B.close + '">✕</button></div><div class="wf-build-about">' + B.about + '</div><div class="wf-build-list" data-role="rows"></div></div>';
    document.body.appendChild(sheet);
    const rowsEl = sheet.querySelector('[data-role="rows"]');
    let open = false;

    function setOpen(next) {
        if (open === next) return;
        open = next;
        sheet.classList.toggle('is-open', open);
        sheet.setAttribute('aria-hidden', open ? 'false' : 'true');
        if (open) document.documentElement.dataset.wfModal = 'bench';
        else { if (document.documentElement.dataset.wfModal === 'bench') delete document.documentElement.dataset.wfModal; onClose(); }
    }

    /** rows: [{ id, kind, status, level, max, from, next, spec, lack, to }], icons: { предмет: значок }. */
    function paint(rows, icons = {}) {
        rowsEl.textContent = '';
        for (const r of rows) {
            const n = B.names[r.kind];
            const card = document.createElement('div');
            card.className = 'wf-build-card' + (r.status === 'max' || r.status === 'owned' ? ' is-off' : '');
            const head = document.createElement('div');
            head.className = 'wf-build-head';
            head.textContent = `${n.icon} ${n.name}` + (r.max ? ` · ${B.level(r.level, r.max)}` : '');
            card.appendChild(head);
            const about = document.createElement('div');
            about.className = 'wf-build-about';
            about.textContent = r.next !== undefined && r.to ? `${n.stat(r.from)} → ${n.stat(r.next)}` : n.stat(r.from);
            card.appendChild(about);
            if (r.status === 'max' || r.status === 'owned' || r.status === 'full') {
                const done = document.createElement('div');
                done.className = 'wf-build-about';
                done.textContent = r.status === 'max' ? B.max : (r.status === 'owned' ? B.owned : B.full);
                card.appendChild(done);
            } else {
                const cost = document.createElement('div');
                cost.className = 'wf-build-cost';
                const chips = [];
                if (r.spec.coins > 0) chips.push({ icon: '🪙', qty: r.spec.coins, lack: r.lack.coins || 0 });
                for (const [item, qty] of Object.entries(r.spec.items || {})) chips.push({ icon: icons[item] || item, qty, lack: r.lack[item] || 0 });
                for (const c of chips) {
                    const chip = document.createElement('span');
                    chip.className = 'wf-chip-cost' + (c.lack ? ' is-lack' : '');
                    chip.textContent = `${c.icon} ${c.qty}`;
                    if (c.lack) chip.title = `${B.lack}: ${c.lack}`;
                    cost.appendChild(chip);
                }
                card.appendChild(cost);
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'wf-btn';
                btn.dataset.id = r.id;
                if (r.to) btn.dataset.to = String(r.to);
                btn.disabled = r.status === 'lack';
                btn.textContent = r.status === 'lack' ? B.lack : B.buy;
                card.appendChild(btn);
            }
            rowsEl.appendChild(card);
        }
    }

    sheet.addEventListener('click', (event) => {
        if (event.target === sheet) { setOpen(false); return; }
        const b = event.target.closest && event.target.closest('button[data-id]');
        if (b && !b.disabled) onBuy(b.dataset.id, b.dataset.to ? Number(b.dataset.to) : null);
    });
    sheet.querySelector('[data-role="close"]').addEventListener('click', () => setOpen(false));
    window.addEventListener('keydown', (event) => { if (open && event.key === 'Escape') setOpen(false); });

    return {
        open(rows, icons) { paint(rows, icons); setOpen(true); },
        refresh(rows, icons) { if (open) paint(rows, icons); },
        close() { setOpen(false); },
        get isOpen() { return open; },
        dispose() { sheet.remove(); },
    };
}
