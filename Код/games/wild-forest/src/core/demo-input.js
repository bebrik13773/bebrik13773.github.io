// ВРЕМЕННОЕ управление до ДЛ-08: тап по земле (ходить), WASD / стрелки (ходить), Q/E (повернуть камеру),
// два пальца по горизонтали (повернуть камеру). Полноценный слой ввода, щипок и зум приходят в ДЛ-08.
const TAP_MS = 250;
const TAP_PX = 10;

export function installDemoInput({ canvasHost, world, isActive }) {
    const keys = new Set();
    const pointers = new Map(); // id -> { x, y, t, startX, startY, moved }
    let twoFingerX = null;

    function applyKeys() {
        const x = (keys.has('d') || keys.has('arrowright') ? 1 : 0) - (keys.has('a') || keys.has('arrowleft') ? 1 : 0);
        const z = (keys.has('w') || keys.has('arrowup') ? 1 : 0) - (keys.has('s') || keys.has('arrowdown') ? 1 : 0);
        world.setMoveInput(x, z);
    }

    function onKeyDown(e) {
        if (!isActive() || e.target instanceof HTMLInputElement) return;
        const k = e.key.toLowerCase();
        if (k === 'q') world.rotateCamera(0.15);
        else if (k === 'e') world.rotateCamera(-0.15);
        else if (['w', 'a', 's', 'd', 'arrowup', 'arrowdown', 'arrowleft', 'arrowright'].includes(k)) {
            keys.add(k);
            applyKeys();
            e.preventDefault();
        }
    }
    function onKeyUp(e) {
        keys.delete(e.key.toLowerCase());
        applyKeys();
    }

    function onDown(e) {
        pointers.set(e.pointerId, { x: e.clientX, y: e.clientY, t: performance.now(), startX: e.clientX, startY: e.clientY, moved: false });
        if (pointers.size === 2) twoFingerX = [...pointers.values()].reduce((s, p) => s + p.x, 0) / 2;
    }
    function onMove(e) {
        const p = pointers.get(e.pointerId);
        if (!p) return;
        p.x = e.clientX;
        p.y = e.clientY;
        if (Math.hypot(p.x - p.startX, p.y - p.startY) > TAP_PX) p.moved = true;
        if (pointers.size === 2 && twoFingerX !== null) {
            const mid = [...pointers.values()].reduce((s, q) => s + q.x, 0) / 2;
            world.rotateCamera(-(mid - twoFingerX) * 0.006);
            twoFingerX = mid;
        }
    }
    function onUp(e) {
        const p = pointers.get(e.pointerId);
        pointers.delete(e.pointerId);
        twoFingerX = null;
        if (!p || !isActive()) return;
        const wasSingle = pointers.size === 0;
        if (wasSingle && !p.moved && performance.now() - p.t < TAP_MS) {
            const rect = canvasHost.getBoundingClientRect();
            const ndcX = ((e.clientX - rect.left) / rect.width) * 2 - 1;
            const ndcY = -(((e.clientY - rect.top) / rect.height) * 2 - 1);
            const hit = world.pickGround(ndcX, ndcY);
            if (hit && !world.isWater(hit.x, hit.z)) world.setTarget(hit.x, hit.z);
        }
    }

    window.addEventListener('keydown', onKeyDown);
    window.addEventListener('keyup', onKeyUp);
    canvasHost.addEventListener('pointerdown', onDown);
    canvasHost.addEventListener('pointermove', onMove);
    canvasHost.addEventListener('pointerup', onUp);
    canvasHost.addEventListener('pointercancel', onUp);
    return () => {
        window.removeEventListener('keydown', onKeyDown);
        window.removeEventListener('keyup', onKeyUp);
        canvasHost.removeEventListener('pointerdown', onDown);
        canvasHost.removeEventListener('pointermove', onMove);
        canvasHost.removeEventListener('pointerup', onUp);
        canvasHost.removeEventListener('pointercancel', onUp);
    };
}
