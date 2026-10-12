import { S } from './strings.ru.js';
import { formatKg } from './hud-logic.js';

const SORTS = ['weight', 'name', 'qty'];

/** Сортировка предметов (чистая функция, тестируется в Node). */
export function sortItems(items, mode) {
    const list = items.slice();
    const name = (i) => (S.items[i.key] ? S.items[i.key].name : i.key);
    if (mode === 'name') list.sort((a, b) => name(a).localeCompare(name(b), 'ru'));
    else if (mode === 'qty') list.sort((a, b) => b.qty - a.qty || name(a).localeCompare(name(b), 'ru'));
    else list.sort((a, b) => b.qty * b.kg - a.qty * a.kg || name(a).localeCompare(name(b), 'ru'));
    return list;
}

export function totalWeight(items) {
    return items.reduce((sum, i) => sum + i.qty * i.kg, 0);
}

// Панель рюкзака (ДЛ-09): сетка предметов, вес, «бросить один/всё», «съесть», сортировка.
// Компонент только показывает и сообщает о выборе игрока (onDrop, onEat). Что реально произойдёт, решит сервер (ДЛ-13).
export function createInventory({ onDrop = () => {}, onEat = () => {}, onChange = () => {} } = {}) {
    const $ = (id) => document.getElementById(id);
    const sheet = $('wfInventory');
    const grid = $('wfInvGrid');
    const weightEl = $('wfInvWeight');
    const detail = $('wfInvDetail');
    const sortBtn = $('wfInvSort');
    const closeBtn = $('wfInvClose');

    let items = [];       // [{ key, qty, kg, edible? }]
    let capacity = 40;
    let sortMode = 'weight';
    let selectedKey = null;
    let open = false;

    function meta(key) {
        return S.items[key] || { name: key, icon: '📦' };
    }

    function paintDetail() {
        detail.textContent = '';
        const item = items.find((i) => i.key === selectedKey);
        if (!item) {
            const hint = document.createElement('p');
            hint.className = 'wf-hint';
            hint.textContent = items.length ? S.inventory.pick : S.inventory.empty;
            detail.appendChild(hint);
            return;
        }
        const m = meta(item.key);
        const title = document.createElement('p');
        title.className = 'wf-inv-title';
        title.textContent = `${m.icon} ${m.name} · ${item.qty} шт. · ${formatKg(item.qty * item.kg)} ${S.hud.kg}`;
        detail.appendChild(title);
        const row = document.createElement('div');
        row.className = 'wf-actions';
        const add = (label, action, ghost) => {
            const b = document.createElement('button');
            b.type = 'button';
            b.className = ghost ? 'wf-btn is-ghost' : 'wf-btn';
            b.textContent = label;
            b.addEventListener('click', action);
            row.appendChild(b);
        };
        if (item.edible) add(S.inventory.eat, () => onEat(item.key));
        add(S.inventory.dropOne, () => onDrop(item.key, 1), true);
        if (item.qty > 1) add(S.inventory.dropAll, () => onDrop(item.key, item.qty), true);
        detail.appendChild(row);
    }

    function paint() {
        const kg = totalWeight(items);
        weightEl.textContent = `${S.inventory.weight}: ${formatKg(kg)} / ${formatKg(capacity)} ${S.hud.kg}`;
        sortBtn.textContent = { weight: S.inventory.sortWeight, name: S.inventory.sortName, qty: S.inventory.sortQty }[sortMode];
        grid.textContent = '';
        if (selectedKey && !items.some((i) => i.key === selectedKey)) selectedKey = null;
        sortItems(items, sortMode).forEach((item) => {
            const m = meta(item.key);
            const cell = document.createElement('button');
            cell.type = 'button';
            cell.className = 'wf-cell';
            cell.dataset.key = item.key;
            cell.setAttribute('aria-pressed', item.key === selectedKey ? 'true' : 'false');
            cell.setAttribute('aria-label', `${m.name}, ${item.qty} шт.`);
            const icon = document.createElement('span');
            icon.textContent = m.icon;
            const qty = document.createElement('b');
            qty.textContent = String(item.qty);
            const w = document.createElement('i');
            w.textContent = `${formatKg(item.qty * item.kg)}`;
            cell.append(icon, qty, w);
            cell.addEventListener('click', () => { selectedKey = item.key; paint(); });
            grid.appendChild(cell);
        });
        paintDetail();
        onChange({ kg, capacity });
    }

    function setOpen(next) {
        if (open === next) return;
        open = next;
        sheet.classList.toggle('is-open', open);
        sheet.setAttribute('aria-hidden', open ? 'false' : 'true');
        if (open) { document.documentElement.dataset.wfModal = 'inventory'; paint(); closeBtn.focus(); }
        else if (document.documentElement.dataset.wfModal === 'inventory') delete document.documentElement.dataset.wfModal;
    }

    closeBtn.addEventListener('click', () => setOpen(false));
    sortBtn.addEventListener('click', () => {
        sortMode = SORTS[(SORTS.indexOf(sortMode) + 1) % SORTS.length];
        paint();
    });
    sheet.addEventListener('click', (event) => { if (event.target === sheet) setOpen(false); });
    window.addEventListener('keydown', (event) => { if (open && event.key === 'Escape') setOpen(false); });

    paint();

    return {
        open: () => setOpen(true),
        close: () => setOpen(false),
        toggle: () => setOpen(!open),
        get isOpen() { return open; },
        setItems(next) {
            items = (next || []).filter((i) => i && i.qty > 0).map((i) => ({ key: i.key, qty: Math.floor(i.qty), kg: Number(i.kg) || 0, edible: Boolean(i.edible) }));
            paint();
        },
        /** Убрать qty штук (временно локально, пока сервер не присылает рюкзак). */
        remove(key, qty) {
            const item = items.find((i) => i.key === key);
            if (!item) return 0;
            const n = Math.min(item.qty, Math.max(0, Math.floor(qty)));
            item.qty -= n;
            items = items.filter((i) => i.qty > 0);
            paint();
            return n;
        },
        setCapacity(kg) { capacity = Number(kg) || 0; paint(); },
        get items() { return items.map((i) => ({ ...i })); },
        get weight() { return totalWeight(items); },
    };
}
