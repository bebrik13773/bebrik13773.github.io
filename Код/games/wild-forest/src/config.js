// Клиентские константы «Дикого Леса». Баланс игры живёт на сервере (api/forest/config.php).
export const BETA = true; // в ДЛ-40 станет false и плашка беты исчезнет

export const REPO = {
    owner: 'bebrik13773',
    name: 'bebrik13773.github.io',
    branch: 'main',
};

export const UPDATE = {
    firstCheckDelayMs: 2000,
    intervalMs: 5 * 60 * 1000, // не чаще раза в 5 минут (лимит GitHub API без токена — 60 запросов в час)
    maxReloadsPerSha: 3,       // защита от бесконечной перезагрузки
    minReloadGapMs: 60 * 1000, // деплой по FTP занимает время: не перезагружаем чаще раза в минуту
};

export const BETA_NOTICE_TTL_MS = 24 * 60 * 60 * 1000; // как часто повторно показывать окно беты
export const PLACEHOLDER_SHA = '__BUILD_COMMIT_SHA__';

// Состояния игры (раздел «ДЛ-04» плана).
export const STATES = Object.freeze({
    LOADING: 'loading',
    PLAYING: 'playing',
    MINIGAME: 'minigame',
    ERROR: 'error',
});

// Пресеты качества — таблица 8.10 плана. Нужны и клиенту, и тестам.
export const QUALITY_ORDER = Object.freeze(['low', 'medium', 'high']);
export const DEFAULT_QUALITY = 'medium';
export const QUALITY_STORAGE_KEY = 'wf.quality';
export const MAX_DPR = 2;

export const QUALITY_PRESETS = Object.freeze({
    low: Object.freeze({
        label: 'Низкое',
        renderScale: 0.6,
        drawChunks: 3,
        drawDistance: 96,
        treeDensity: 0.6,
        shadows: 'none',     // нет теней
        outline: 'hero',     // контур только у бобра
        particles: 'min',
        targetFps: 30,
        antialias: false,
    }),
    medium: Object.freeze({
        label: 'Среднее',
        renderScale: 0.8,
        drawChunks: 5,
        drawDistance: 160,
        treeDensity: 0.85,
        shadows: 'blob',     // простые тени под бобром
        outline: 'near',
        particles: 'normal',
        targetFps: 45,
        antialias: false,
    }),
    high: Object.freeze({
        label: 'Высокое',
        renderScale: 1.0,
        drawChunks: 7,
        drawDistance: 224,
        treeDensity: 1.0,
        shadows: 'real',     // реальные тени, 1 каскад, карта 1024
        outline: 'all',
        particles: 'full',
        targetFps: 60,
        antialias: true,
    }),
});

// Главный цикл: логика с фиксированным шагом, отрисовка с интерполяцией.
export const LOOP = Object.freeze({
    logicHz: 30,
    maxFrameMs: 250, // длинный кадр (вкладка «заснула») не вызывает лавину шагов
    maxSteps: 5,
});

// Автоподсказка снизить качество: первые 5 секунд кадров меньше 24 в секунду.
export const LOW_FPS = Object.freeze({
    warmupMs: 1000,   // первая секунда: компиляция шейдеров, не считаем
    windowMs: 5000,
    threshold: 24,
});

// Ошибки: если за окно набралось слишком много, показываем экран ошибки и останавливаем игру.
export const ERRORS = Object.freeze({
    windowMs: 10000,
    fatalCount: 6,
    ringSize: 30,
    storageKey: 'wf.errlog',
});

export const OLD_GAME_URL = '../../pages/clicker/index.html';
export const HEALTH_URL = '../../api/forest/health.php';
export const HEALTH_TIMEOUT_MS = 4000;
