import { S } from './strings.ru.js';

const LEVEL_ICON = { info: 'ℹ️', warn: '⚠️', danger: '🛑' };
const MAX_ITEMS = 30;
const MAX_TOASTS = 3;

// Уведомления: короткие всплывающие подсказки (тосты) и центр уведомлений со списком (для предупреждений плотины и т.п.).
// Уровень показан значком, а не только цветом. Уведомление с key заменяет прежнее с тем же key; sticky держится, пока не вызван resolve(key).
export function createNotifyCenter({ toastsEl, panelEl, listEl, badgeEl, bellEl, clearEl, now = () => Date.now() }) {
    let items = [];
    let seq = 0;
    let open = false;

    function unread() { return items.filter((i) => !i.read).length; }

    function paintBadge() {
        const n = unread();
        badgeEl.textContent = n > 9 ? '9+' : String(n);
        badgeEl.hidden = n === 0;
        bellEl.setAttribute('aria-label', n ? `${S.hud.bell}: ${n}` : S.hud.bell);
    }

    function paintList() {
        listEl.textContent = '';
        if (!items.length) {
            const li = document.createElement('li');
            li.className = 'wf-empty';
            li.textContent = S.notify.empty;
            listEl.appendChild(li);
            return;
        }
        items.slice().sort((a, b) => b.ts - a.ts).forEach((item) => {
            const li = document.createElement('li');
            li.className = `wf-note is-${item.level}`;
            const icon = document.createElement('span');
            icon.className = 'wf-note-icon';
            icon.setAttribute('aria-hidden', 'true');
            icon.textContent = LEVEL_ICON[item.level] || LEVEL_ICON.info;
            const text = document.createElement('span');
            text.textContent = item.text;
            li.append(icon, text);
            listEl.appendChild(li);
        });
    }

    function toast(item, ttlMs) {
        const el = document.createElement('div');
        el.className = `wf-toast is-${item.level}`;
        el.setAttribute('role', 'status');
        const icon = document.createElement('span');
        icon.setAttribute('aria-hidden', 'true');
        icon.textContent = LEVEL_ICON[item.level] || LEVEL_ICON.info;
        const text = document.createElement('span');
        text.textContent = item.text;
        el.append(icon, text);
        while (toastsEl.children.length >= MAX_TOASTS) toastsEl.firstChild.remove();
        toastsEl.appendChild(el);
        setTimeout(() => el.remove(), ttlMs);
    }

    function setOpen(next) {
        open = next;
        panelEl.classList.toggle('is-open', open);
        panelEl.setAttribute('aria-hidden', open ? 'false' : 'true');
        bellEl.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open) {
            items.forEach((i) => { i.read = true; });
            paintList();
            paintBadge();
        }
    }

    bellEl.addEventListener('click', () => setOpen(!open));
    clearEl.addEventListener('click', () => {
        items = items.filter((i) => i.sticky);
        paintList();
        paintBadge();
    });

    paintBadge();
    paintList();

    return {
        /** Показать уведомление. Возвращает его номер. */
        push({ text, level = 'info', key = null, sticky = false, ttlMs = 4200, silent = false }) {
            const clean = String(text || '').trim();
            if (!clean) return 0;
            const lv = LEVEL_ICON[level] ? level : 'info';
            let item = key ? items.find((i) => i.key === key) : null;
            const changed = !item || item.text !== clean || item.level !== lv;
            if (item) {
                item.text = clean; item.level = lv; item.sticky = sticky;
                if (changed) { item.ts = now(); item.read = open; }
            } else {
                seq += 1;
                item = { id: seq, key, text: clean, level: lv, sticky, ts: now(), read: open };
                items.push(item);
                if (items.length > MAX_ITEMS) {
                    const idx = items.findIndex((i) => !i.sticky);
                    items.splice(idx >= 0 ? idx : 0, 1);
                }
            }
            if (changed && !silent) toast(item, ttlMs);
            if (open) paintList();
            paintBadge();
            return item.id;
        },
        /** Снять липкое предупреждение (например, плотину починили). */
        resolve(key) {
            const before = items.length;
            items = items.filter((i) => i.key !== key);
            if (items.length !== before) { paintList(); paintBadge(); }
        },
        open: () => setOpen(true),
        close: () => setOpen(false),
        get isOpen() { return open; },
        get count() { return items.length; },
        get unread() { return unread(); },
    };
}
