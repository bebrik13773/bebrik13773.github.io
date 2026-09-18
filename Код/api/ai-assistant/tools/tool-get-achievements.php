<?php

/**
 * Tool get_achievements_full: отдаёт полный каталог достижений игры
 * (включая ещё не открытые игроком — с "запертыми" описаниями, если заданы)
 * плюс список достижений, которые уже разблокировал текущий игрок.
 * Только чтение, ничего не изменяет.
 */
function bober_ai_tool_get_achievements_full($conn, $userId)
{
    try {
        $catalog = bober_fetch_achievement_catalog($conn);
        $unlocked = bober_fetch_user_achievements($conn, $userId);

        $unlockedMap = [];
        foreach ($unlocked as $item) {
            $key = (string) ($item['key'] ?? '');
            if ($key !== '') {
                $unlockedMap[$key] = $item;
            }
        }

        $catalogOut = [];
        foreach ($catalog as $item) {
            if (empty($item['isActive'])) {
                continue; // отключённые/снятые с ротации достижения не показываем
            }

            $key = (string) ($item['key'] ?? '');
            $isUnlocked = isset($unlockedMap[$key]);
            $isSecret = !empty($item['secret']);

            // Для ещё не открытых секретных достижений скрываем сюжетные детали —
            // отдаём только "запертые" title/description, если они заданы.
            $showTitle = (!$isSecret || $isUnlocked)
                ? (string) ($item['title'] ?? $key)
                : ((string) ($item['lockedTitle'] ?? '') !== '' ? (string) $item['lockedTitle'] : 'Секретное достижение');
            $showDescription = (!$isSecret || $isUnlocked)
                ? (string) ($item['description'] ?? '')
                : ((string) ($item['lockedDescription'] ?? '') !== '' ? (string) $item['lockedDescription'] : 'Условия скрыты до выполнения.');

            $catalogOut[] = [
                'key' => $key,
                'title' => $showTitle,
                'description' => $showDescription,
                'rewardCoins' => (int) ($item['rewardCoins'] ?? 0),
                'isSecret' => $isSecret,
                'isUnlockedByPlayer' => $isUnlocked,
                'unlockedAt' => $isUnlocked ? (string) ($unlockedMap[$key]['unlockedAt'] ?? '') : null,
            ];
        }

        return [
            'success' => true,
            'totalCount' => count($catalogOut),
            'unlockedCount' => count($unlockedMap),
            'achievements' => $catalogOut,
        ];
    } catch (Throwable $error) {
        return ['error' => bober_exception_message($error, 'Не удалось получить список достижений.')];
    }
}
