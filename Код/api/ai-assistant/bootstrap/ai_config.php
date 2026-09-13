<?php

if (defined('BOBER_AI_CONFIG_BOOTSTRAP_LOADED')) {
    return;
}

define('BOBER_AI_CONFIG_BOOTSTRAP_LOADED', true);

/**
 * Загружает конфиг ИИ-помощника: ключ RouterAI, модель, Telegram-бот.
 * Источник — Код/config/ai_config.php (генерируется на деплое из GitHub Secrets),
 * с фолбэком на переменные окружения (для локального теста).
 */
function bober_ai_load_config()
{
    static $config = null;

    if ($config !== null) {
        return $config;
    }

    $config = [
        'ai_api_key' => null,
        'ai_base_url' => 'https://routerai.ru/api/v1',
        'ai_model' => '~deepseek/deepseek-v4-flash-latest',
        'tg_bot_token' => null,
        'tg_chat_id' => null,
    ];

    $configFile = dirname(__DIR__, 3) . '/config/ai_config.php';
    if (is_file($configFile)) {
        $loaded = require $configFile;
        if (is_array($loaded)) {
            $config = array_merge($config, array_intersect_key($loaded, $config));
        }
    }

    $envMap = [
        'ai_api_key' => 'BOBER_AI_API_KEY',
        'ai_base_url' => 'BOBER_AI_BASE_URL',
        'ai_model' => 'BOBER_AI_MODEL',
        'tg_bot_token' => 'BOBER_TG_BOT_TOKEN',
        'tg_chat_id' => 'BOBER_TG_CHAT_ID',
    ];

    foreach ($envMap as $key => $envName) {
        $value = getenv($envName);
        if ($value !== false && $value !== '') {
            $config[$key] = $value;
        }
    }

    return $config;
}
