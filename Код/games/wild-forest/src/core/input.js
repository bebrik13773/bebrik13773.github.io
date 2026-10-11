// Единый слой ввода (ДЛ-08): Pointer Events (касания, мышь, перо одинаково) + клавиатура как запасное управление.
// Слушаем ТОЛЬКО холст сцены: тап по кнопке или панели интерфейса (она вне сцены) никогда не попадает «сквозь» в мир.
import { INPUT } from '../config.js';
import { createGestures } from './gestures.js';

export function installInput({ host, isActive, onTap, onOrbit, onZoom, onMoveKeys, onKeyRotate, cfg = INPUT }) {
    const gestures = createGestures({
        cfg,
        onTap: (tap) => { if (isActive() && onTap) onTap(tap); },
        onOrbit: (dYaw, dPitch) => { if (isActive() && onOrbit) onOrbit(dYaw, dPitch); },
        onZoom: (factor) => { if (isActive() && onZoom) onZoom(factor); },
    });
    const keys = new Set();
    const ownsTarget = (t) => t === host || (t && t.tagName === 'CANVAS' && host.contains(t));
    const now = () => performance.now();

    function onDown(e) {
        if (!ownsTarget(e.target)) return;
        if (e.pointerType === 'touch' && (e.clientX < cfg.edgeIgnorePx || e.clientX > window.innerWidth - cfg.edgeIgnorePx)) return;
        try { host.setPointerCapture(e.pointerId); } catch (err) { /* у синтетических событий захвата может не быть */ }
        gestures.down(e.pointerId, e.clientX, e.clientY, now(), e.pointerType === 'mouse' ? 'mouse' : 'touch', e.button);
    }
    const onMove = (e) => gestures.move(e.pointerId, e.clientX, e.clientY);
    function onUp(e) {
        try { host.releasePointerCapture(e.pointerId); } catch (err) { /* не страшно */ }
        gestures.up(e.pointerId, e.clientX, e.clientY, now());
    }
    const onCancel = (e) => gestures.cancel(e.pointerId);
    function onWheel(e) {
        if (!ownsTarget(e.target)) return;
        e.preventDefault();
        gestures.wheel(e.deltaMode === 1 ? e.deltaY * 16 : e.deltaY);
    }
    const onContext = (e) => e.preventDefault();

    function applyKeys() {
        const x = (keys.has('d') || keys.has('arrowright') ? 1 : 0) - (keys.has('a') || keys.has('arrowleft') ? 1 : 0);
        const z = (keys.has('w') || keys.has('arrowup') ? 1 : 0) - (keys.has('s') || keys.has('arrowdown') ? 1 : 0);
        if (onMoveKeys) onMoveKeys(x, z);
    }
    function onKeyDown(e) {
        const t = e.target;
        if (!isActive() || (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.isContentEditable))) return;
        const k = e.key.toLowerCase();
        if (k === 'q' || k === 'e') { if (onKeyRotate) onKeyRotate(k === 'q' ? cfg.keyRotateRad : -cfg.keyRotateRad); }
        else if (k === '=' || k === '+') { if (onZoom) onZoom(1 / cfg.keyZoomStep); }
        else if (k === '-' || k === '_') { if (onZoom) onZoom(cfg.keyZoomStep); }
        else if (['w', 'a', 's', 'd', 'arrowup', 'arrowdown', 'arrowleft', 'arrowright'].includes(k)) {
            keys.add(k);
            applyKeys();
            e.preventDefault();
        }
    }
    function onKeyUp(e) { keys.delete(e.key.toLowerCase()); applyKeys(); }
    function releaseAll() { keys.clear(); applyKeys(); }

    host.addEventListener('pointerdown', onDown);
    host.addEventListener('pointermove', onMove);
    host.addEventListener('pointerup', onUp);
    host.addEventListener('pointercancel', onCancel);
    host.addEventListener('wheel', onWheel, { passive: false });
    host.addEventListener('contextmenu', onContext);
    window.addEventListener('keydown', onKeyDown);
    window.addEventListener('keyup', onKeyUp);
    window.addEventListener('blur', releaseAll);
    document.addEventListener('visibilitychange', releaseAll);
    return {
        gestures,
        dispose() {
            host.removeEventListener('pointerdown', onDown);
            host.removeEventListener('pointermove', onMove);
            host.removeEventListener('pointerup', onUp);
            host.removeEventListener('pointercancel', onCancel);
            host.removeEventListener('wheel', onWheel);
            host.removeEventListener('contextmenu', onContext);
            window.removeEventListener('keydown', onKeyDown);
            window.removeEventListener('keyup', onKeyUp);
            window.removeEventListener('blur', releaseAll);
            document.removeEventListener('visibilitychange', releaseAll);
        },
    };
}
