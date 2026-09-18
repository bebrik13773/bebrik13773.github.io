<?php

/**
 * Tool get_quests_full: отдаёт текущие квесты игрока (ежедневные + недельные,
 * с прогрессом и статусом claim) плюс список всех активных квестов в игре
 * (общий каталог, без прогресса конкретного игрока — квесты выдаются
 * ротацией по 2 слота, а не разом всем списком).
 * Только чтение, ничего не изменяет. viewerMode='player' — секретные
 * недельные квесты не спойлерятся, пока не выполнены.
 */
function bober_ai_tool_get_quests_full($conn, $userId)
{
    try {
        $resolved = bober_resolve_user_quests($conn, $userId, false, 'player');
        $currentDaily = is_array($resolved['items']['daily']['items'] ?? null) ? $resolved['items']['daily']['items'] : [];
        $currentWeekly = is_array($resolved['items']['weekly']['items'] ?? null) ? $resolved['items']['weekly']['items'] : [];

        $simplifyCurrent = static function (array $items) {
            $out = [];
            foreach ($items as $item) {
                $out[] = [
                    'key' => (string) ($item['key'] ?? ''),
                    'title' => (string) ($item['title'] ?? ''),
                    'description' => (string) ($item['description'] ?? ''),
                    'goal' => (int) ($item['goal'] ?? 0),
                    'progress' => (int) ($item['progress'] ?? 0),
                    'completed' => !empty($item['completed']),
                    'claimed' => !empty($item['claimed']),
                    'rewardCoins' => (int) ($item['rewardCoins'] ?? 0),
                    'isSecret' => !empty($item['isSecret']),
                ];
            }
            return $out;
        };

        // Полный каталог активных квестов (шаблонов) в игре — без привязки
        // к конкретному игроку. Секретные квесты каталога не выдаём текстом,
        // чтобы не спойлерить условия тем, кто их ещё не получил в ротации.
        $catalogOut = ['daily' => [], 'weekly' => []];
        foreach (['daily', 'weekly'] as $scope) {
            $definitions = bober_fetch_active_quest_definitions($conn, $scope);
            foreach ($definitions as $questKey => $definition) {
                $catalogOut[$scope][] = [
                    'key' => (string) $questKey,
                    'title' => (string) ($definition['title'] ?? $questKey),
                    'description' => (string) ($definition['description'] ?? ''),
                    'goal' => (int) ($definition['goal'] ?? 0),
                    'rewardCoins' => (int) ($definition['rewardCoins'] ?? 0),
                ];
            }
        }

        return [
            'success' => true,
            'currentPlayerQuests' => [
                'daily' => $simplifyCurrent($currentDaily),
                'weekly' => $simplifyCurrent($currentWeekly),
            ],
            'fullQuestCatalog' => $catalogOut,
            'note' => 'Квесты выдаются игроку ротацией по 2 daily + 2 weekly слота — "полный список" не означает, что все они доступны игроку одновременно. ВАЖНО: секретный слот недели выбирается случайно из этого же пула weekly-квестов — любой из них потенциально может быть секретным у кого-то на этой неделе, поэтому не пересказывай заголовки/условия weekly-каталога как гарантированно открытую информацию тому, у кого сейчас активен нераскрытый секретный квест (isSecret=true в currentPlayerQuests.weekly).',
        ];
    } catch (Throwable $error) {
        return ['error' => bober_exception_message($error, 'Не удалось получить список квестов.')];
    }
}
