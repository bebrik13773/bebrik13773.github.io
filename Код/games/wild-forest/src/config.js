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
        terrainSeg: 8,      // сегментов рельефа на чанк (шаг 4 м)
        detailChunks: 0,    // деревья подробной модели в этом радиусе чанков (0 = везде простые)
        drawDistance: 96,
        treeDensity: 0.6,
        shadows: 'none',     // нет теней
        outline: 'hero',     // контур только у бобра
        beaverDetail: 'low', // простая модель бобра
        particles: 'min',
        targetFps: 30,
        antialias: false,
    }),
    medium: Object.freeze({
        label: 'Среднее',
        renderScale: 0.8,
        drawChunks: 5,
        terrainSeg: 16,     // шаг 2 м
        detailChunks: 1.5,
        drawDistance: 160,
        treeDensity: 0.85,
        shadows: 'blob',     // простые тени под бобром
        outline: 'near',
        beaverDetail: 'high',
        particles: 'normal',
        targetFps: 45,
        antialias: false,
    }),
    high: Object.freeze({
        label: 'Высокое',
        renderScale: 1.0,
        drawChunks: 7,
        terrainSeg: 32,     // шаг 1 м
        detailChunks: 3.5,
        drawDistance: 224,
        treeDensity: 1.0,
        shadows: 'real',     // реальные тени, 1 каскад, карта 1024
        outline: 'all',
        beaverDetail: 'high',
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
export const API_BASE = '../../api/forest/';
export const HEALTH_URL = '../../api/forest/health.php';
export const HEALTH_TIMEOUT_MS = 4000;

// Сид мира (ДЛ-05). Должен совпадать с world.seed в api/forest/config.php (проверяет tests/worldgen.test.mjs).
// Менять нельзя после старта беты: изменится весь лес.
export const WORLD_SEED = 1337;

// Мир (ДЛ-06): чанки, бюджеты построения и пул пней.
export const WORLD = Object.freeze({
    chunkBuildBudgetMs: 4,     // сколько миллисекунд за кадр тратим на построение чанков (минимум один чанк за кадр)
    unloadMarginChunks: 1.2,   // чанк выгружается, когда уходит дальше радиуса на это число чанков (гистерезис)
    stumpCapacity: 3000,       // размер общего пула пней (один вызов отрисовки на все пни)
    stumpLifeMs: 60000,        // свежий пень исчезает через минуту после рубки
    waterLevelM: -3,           // уровень воды, м (совпадает с water.level = -30 дм из biomes.json)
    heroSpeed: 3.2,            // временная скорость бобра до ДЛ-08 (потом формула из shared/formulas.js)
});

// Управление и камера (ДЛ-08). Все пороги в одном месте, чтобы подбирать «на ощупь» без правки логики.
export const INPUT = Object.freeze({
    tapMs: 200,             // тап: палец коснулся и отпущен быстрее этого
    tapPx: 10,              // и сдвинулся меньше этого (в CSS-пикселях)
    edgeIgnorePx: 14,       // касания у левого/правого края экрана не считаем (системные жесты Android)
    pinchThresholdPx: 8,    // изменение расстояния между пальцами, после которого включается зум
    twistThresholdRad: 0.07,// поворот пальцев (≈4°), после которого включается вращение камеры
    panThresholdPx: 12,     // сдвиг центра двух пальцев, после которого включается наклон/вращение
    orbitYawPerPx: 0.006,   // рад на пиксель (две пальца по горизонтали, правая кнопка мыши)
    orbitPitchPerPx: 0.004, // рад на пиксель по вертикали
    wheelZoomK: 0.0012,     // колесо мыши: экспонента от deltaY
    keyRotateRad: 0.15,     // Q/E: шаг поворота камеры
    keyZoomStep: 1.15,      // +/-: множитель дальности
    pickMinPx: 24,          // минимальный радиус «толстого пальца» при выборе объекта, CSS-пиксели
    pickMaxPx: 64,
    pickTreeWorldR: 1.0,    // радиус попадания по дереву в метрах (крона шире ствола)
});

export const CAMERA = Object.freeze({
    minDist: 6,
    maxDist: 18,
    defaultDist: 9,         // в портрете чуть дальше (см. resize в scene.js)
    minPitchDeg: 20,
    maxPitchDeg: 70,
    defaultPitchDeg: 46,
    smoothPerSec: 12,       // «пружина» сглаживания, больше = резче
    focusHeight: 1.2,       // куда смотрим над землёй, м
    minClearance: 0.8,      // камера не ближе этого к земле, м
});

// Ходьба, препятствия и взаимодействие (ДЛ-08).
export const MOVE = Object.freeze({
    heroRadius: 0.4,
    treeRadius: Object.freeze([0.35, 0.45, 0.6]), // радиус ствола по размеру дерева (малое, среднее, большое)
    rockRadiusK: 0.85,      // радиус камня = радиус модели * k
    interactReach: 1.8,     // подойти к предмету на столько метров (от центра)
    chopReach: 0.62,        // к дереву подходим на радиус ствола плюс столько: головка топора достаёт до коры
    arriveEps: 0.25,        // «дошёл» до точки, м
    waterTolerance: -0.15,  // в воду не заходим: клетка должна быть выше воды хотя бы на столько, м
    maxSlopeDeg: 40,        // круче не ходим
    turnRate: 10,           // рад/с поворот бобра
    accel: 14,              // м/с² разгон
    stuckSeconds: 0.8,      // столько стоим на месте при движении = «застрял»
    maxReplans: 3,          // сколько раз перестраивать путь, если дошли только до части маршрута
    cellM: 1,               // шаг сетки поиска пути, м
    maxNodes: 9000,         // потолок раскрытых клеток A*
    searchRadiusM: 90,      // дальше от старта не ищем
    marginM: 0.25,          // запас на лес и камни при сглаживании пути
});
