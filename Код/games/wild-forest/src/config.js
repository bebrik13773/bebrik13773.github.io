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
