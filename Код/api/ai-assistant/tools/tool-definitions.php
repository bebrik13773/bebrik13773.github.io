<?php

/**
 * Возвращает массив определений tools в OpenAI-совместимом формате
 * function calling — то, что кладётся в поле "tools" запроса к RouterAI.
 */
function bober_ai_tool_definitions()
{
    return [
        [
            'type' => 'function',
            'function' => [
                'name' => 'buy_upgrade',
                'description' => 'Покупает апгрейд в кликере от имени игрока (переиспользует ту же серверную логику покупки, что и кнопка в интерфейсе). Используй только если игрок явно попросил купить/приобрести конкретное улучшение.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'upgradeType' => [
                            'type' => 'string',
                            'enum' => ['tapSmall', 'tapBig', 'energy', 'tapHuge', 'regenBoost', 'energyHuge', 'clickRate'],
                            'description' => 'Тип апгрейда для покупки.',
                        ],
                        'quantity' => [
                            'type' => 'integer',
                            'description' => 'Сколько штук купить за раз (по умолчанию 1).',
                        ],
                    ],
                    'required' => ['upgradeType'],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'create_support_ticket',
                'description' => 'Создаёт тикет в поддержку от имени игрока: баг, вопрос по балансу, техническая проблема. Используй, если игрок описывает реальную проблему и её нельзя решить прямо в чате.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'category' => [
                            'type' => 'string',
                            'enum' => ['account', 'bugs', 'skins', 'fly_beaver', 'anti_cheat', 'other'],
                            'description' => 'Категория проблемы: account — аккаунт/вход, bugs — баги/технические проблемы (включая баланс), skins — вопросы по скинам/магазину, fly_beaver — вопросы по мини-игре, anti_cheat — подозрение на накрутку, other — всё остальное.',
                        ],
                        'subject' => [
                            'type' => 'string',
                            'description' => 'Короткая тема тикета (до 180 символов).',
                        ],
                        'message' => [
                            'type' => 'string',
                            'description' => 'Полное описание проблемы своими словами, на основе того, что рассказал игрок.',
                        ],
                    ],
                    'required' => ['category', 'subject', 'message'],
                ],
            ],
        ],
/*
        [
            'type' => 'function',
            'function' => [
                'name' => 'report_player',
                'description' => 'Принимает жалобу игрока на другого игрока (читерство, оскорбления и т.п.) и сохраняет её для ручного разбора владельцем игры. Не выносит вердиктов и не банит сам. Используй только если игрок явно жалуется на конкретного другого игрока.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'reportedLogin' => [
                            'type' => 'string',
                            'description' => 'Логин/ник игрока, на которого жалуются (как его назвал автор жалобы).',
                        ],
                        'description' => [
                            'type' => 'string',
                            'description' => 'Суть жалобы: что произошло, почему игрок считает это нарушением.',
                        ],
                    ],
                    'required' => ['reportedLogin', 'description'],
                ],
            ],
        ],
*/
        [
            'type' => 'function',
            'function' => [
                'name' => 'get_advice',
                'description' => 'Считает игровые советы: какой апгрейд сейчас выгоднее всего купить, сколько кликов/времени нужно для накопления на что-то, сравнение с соседями по лидерборду. Только читает и считает, ничего не изменяет. Используй для любых вопросов вида "что купить", "сколько нужно на X", "как я по сравнению с другими".',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'question' => [
                            'type' => 'string',
                            'enum' => ['best_upgrade', 'time_to_afford', 'leaderboard_comparison'],
                            'description' => 'Тип вопроса: best_upgrade — какой апгрейд выгоднее; time_to_afford — сколько нужно кликов/времени на конкретную цель; leaderboard_comparison — сравнение с соседями по рейтингу.',
                        ],
                        'targetUpgradeType' => [
                            'type' => 'string',
                            'enum' => ['tapSmall', 'tapBig', 'energy', 'tapHuge', 'regenBoost', 'energyHuge', 'clickRate'],
                            'description' => 'Для time_to_afford: на какой конкретно апгрейд считать время накопления (необязательно, если не указан явно).',
                        ],
                    ],
                    'required' => ['question'],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'get_quests_full',
                'description' => 'Возвращает текущие квесты игрока (ежедневные и недельные, с прогрессом и наградой) плюс полный список всех квестов, которые вообще существуют в игре. Используй, если игрок спрашивает про квесты подробно: "какие у меня квесты", "какие вообще бывают квесты", "что за задание такое". Только читает, ничего не меняет.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => (object) [],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'get_achievements_full',
                'description' => 'Возвращает полный список всех достижений игры (с наградами) и отмечает, какие из них уже разблокировал текущий игрок. Используй, если игрок спрашивает про достижения: "какие у меня достижения", "какие вообще есть достижения", "что нужно, чтобы получить X". Только читает, ничего не меняет.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => (object) [],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'get_news_full',
                'description' => 'Возвращает все опубликованные новости/объявления игры (заголовок, текст, дата публикации), включая уже прочитанные игроком, с отметкой прочитано/не прочитано у каждой. Используй, если игрок спрашивает про новости, обновления, что нового в игре, историю изменений. Только читает, ничего не меняет и не помечает новости прочитанными.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => (object) [],
                ],
            ],
        ],
    ];
}
