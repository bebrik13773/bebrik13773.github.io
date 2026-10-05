import { REPO, UPDATE, PLACEHOLDER_SHA } from './config.js';

// Версия билда вшивается при деплое (GitHub Actions подменяет плейсхолдер в index.html).
export function readBuildSha(marker) {
    const sha = marker && marker.dataset ? (marker.dataset.buildCommit || '') : '';
    return sha && sha !== PLACEHOLDER_SHA ? sha : '';
}

export function renderBuildId(marker, statusEl) {
    const sha = readBuildSha(marker);
    marker.textContent = sha ? `build ${sha.slice(0, 7)}` : 'build: локальная сборка';
    if (statusEl && !sha) {
        statusEl.textContent = '';
    }
    return sha;
}

function safeSession(fn, fallback) {
    try {
        return fn(sessionStorage);
    } catch (e) {
        return fallback;
    }
}

// Нужно ли перезагружаться из-за новой версии (с защитой от зацикливания).
export function shouldReload(latestSha, now = Date.now()) {
    const key = `wf_stale_reload_${latestSha}`;
    const raw = safeSession((s) => s.getItem(key), null);
    let state = { count: 0, at: 0 };
    try {
        if (raw) state = JSON.parse(raw);
    } catch (e) { /* битое значение считаем пустым */ }
    if (state.count >= UPDATE.maxReloadsPerSha) return false;
    if (state.at && now - state.at < UPDATE.minReloadGapMs) return false;
    safeSession((s) => s.setItem(key, JSON.stringify({ count: state.count + 1, at: now })), null);
    return true;
}

export async function fetchLatestSha() {
    const url = `https://api.github.com/repos/${REPO.owner}/${REPO.name}/commits/${REPO.branch}`;
    const response = await fetch(url, { headers: { Accept: 'application/vnd.github+json' } });
    if (!response.ok) throw new Error(`GitHub API: HTTP ${response.status}`);
    const data = await response.json();
    return data && typeof data.sha === 'string' ? data.sha : '';
}

export function startAutoUpdate(marker, statusEl) {
    const builtSha = readBuildSha(marker);
    if (!builtSha) return; // локальный запуск: сверять не с чем

    const check = async () => {
        if (document.hidden) return;
        try {
            const latest = await fetchLatestSha();
            if (!latest) return;
            if (latest === builtSha) {
                if (statusEl) statusEl.textContent = 'Версия актуальна';
                return;
            }
            if (statusEl) statusEl.textContent = 'Доступна новая версия, обновляем…';
            if (shouldReload(latest)) {
                const url = new URL(window.location.href);
                url.searchParams.set('v', latest.slice(0, 7));
                window.location.replace(url.toString());
            }
        } catch (e) {
            if (statusEl) statusEl.textContent = '';
            console.warn('Проверка версии не удалась:', e);
        }
    };

    setTimeout(check, UPDATE.firstCheckDelayMs);
    setInterval(check, UPDATE.intervalMs);
}
