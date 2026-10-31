import { S } from './strings.ru.js';

// Панель ящика-тайника (ДЛ-14): два списка («В рюкзаке» и «В ящике»), у каждого предмета кнопки «1», «10», «Всё».
// Панель только показывает и сообщает о нажатиях; перенос предметов делает crate-flow (клиент предлагает, сервер решает).
export function createCrateUi({ onMove = () => {}, onClose = () => {} } = {}) {
    const sheet = document.createElement('section');
    sheet.className = 'wf-sheet';
    sheet.id = 'wfCrate';
    sheet.setAttribute('aria-hidden', 'true');
    sheet.innerHTML = [
        '<div class="wf-sheet-box" role="dialog" aria-modal="true" aria-label="' + S.crate.title + '">',
        '<div class="wf-sheet-head"><h2>📦 ' + S.crate.title + '</h2><button type="button" class="wf-iconbtn" id="wfCrateClose" aria-label="' + S.crate.close + '">✕</button></div>',
        '<div class="wf-inv-bar"><span id="wfCrateUsed"></span></div>',
        '<h3 class="wf-crate-title">' + S.crate.pack + '</h3><div class="wf-crate-list" id="wfCratePack"></div>',
        '<h3 class="wf-crate-title">' + S.crate.box + '</h3><div class="wf-crate-list" id="wfCrateBox"></div>',
        '</div>',
    ].join('');
    document.body.appendChild(sheet);
    const usedEl = sheet.querySelector('#wfCrateUsed');
    const packEl = sheet.querySelector('#wfCratePack');
    const boxEl = sheet.querySelector('#wfCrateBox');
    let open = false;
    let model = { pack: [], box: [], used: 0, cap: 100 };

    function meta(key) { return S.items[key] || { name: key, icon: '📦' }; }

    function fill(root, list, side, emptyText) {
        root.textContent = '';
        if (!list.length) {
            const p = document.createElement('p');
            p.className = 'wf-empty';
            p.textContent = emptyText;
            root.appendChild(p);
            return;
        }
        for (const it of list) {
            const row = document.createElement('div');
            row.className = 'wf-crate-row';
            const label = document.createElement('span');
            label.className = 'wf-crate-name';
            label.textContent = `${meta(it.key).icon} ${meta(it.key).name} × ${it.qty}`;
            row.appendChild(label);
            for (const [txt, qty] of [[S.crate.one, 1], [S.crate.ten, 10], [S.crate.all, it.qty]]) {
                if (qty > it.qty && txt !== S.crate.all) continue;
                const b = document.createElement('button');
                b.type = 'button';
                b.className = 'wf-btn wf-crate-btn';
                b.textContent = txt;
                b.dataset.side = side;
                b.dataset.item = it.key;
                b.dataset.qty = String(qty);
                row.appendChild(b);
            }
            root.appendChild(row);
        }
    }

    function paint() {
        usedEl.textContent = S.crate.used(model.used, model.cap);
        fill(packEl, model.pack, 'pack', S.crate.emptyPack);
        fill(boxEl, model.box, 'box', S.crate.emptyBox);
    }

    function setOpen(next) {
        if (open === next) return;
        open = next;
        sheet.classList.toggle('is-open', open);
        sheet.setAttribute('aria-hidden', open ? 'false' : 'true');
        if (open) { document.documentElement.dataset.wfModal = 'crate'; paint(); }
        else {
            if (document.documentElement.dataset.wfModal === 'crate') delete document.documentElement.dataset.wfModal;
            onClose();
        }
    }

    sheet.addEventListener('click', (event) => {
        if (event.target === sheet) { setOpen(false); return; }
        const b = event.target.closest && event.target.closest('.wf-crate-btn');
        if (!b) return;
        // side=pack: из рюкзака в ящик; side=box: из ящика в рюкзак
        onMove({ from: b.dataset.side, item: b.dataset.item, qty: Number(b.dataset.qty) });
    });
    sheet.querySelector('#wfCrateClose').addEventListener('click', () => setOpen(false));
    window.addEventListener('keydown', (event) => { if (open && event.key === 'Escape') setOpen(false); });

    return {
        open: () => setOpen(true),
        close: () => setOpen(false),
        get isOpen() { return open; },
        /** model: { pack: [{key, qty}], box: [{key, qty}], used, cap } */
        set(next) { model = { pack: next.pack || [], box: next.box || [], used: next.used | 0, cap: next.cap | 0 }; if (open) paint(); },
        dispose() { sheet.remove(); },
    };
}
