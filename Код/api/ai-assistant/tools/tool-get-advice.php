<?php

require_once dirname(__DIR__) . '/context/build-user-context.php';

/**
 * Обработчик tool "get_advice" — только читает и считает, ничего не меняет.
 * Принимает уже собранный контекст игрока (bober_ai_build_user_context),
 * чтобы не делать лишние запросы к БД повторно.
 */
function bober_ai_tool_get_advice(array $userContext, array $args)
{
    $question = (string) ($args['question'] ?? '');

    switch ($question) {
        case 'best_upgrade':
            return bober_ai_advice_best_upgrade($userContext);
        case 'time_to_afford':
            return bober_ai_advice_time_to_afford($userContext, (string) ($args['targetUpgradeType'] ?? ''));
        case 'leaderboard_comparison':
            return bober_ai_advice_leaderboard_comparison($userContext);
        default:
            return [
                'error' => 'Неизвестный тип вопроса для get_advice.',
            ];
    }
}

/**
 * Считает "эффективность" апгрейда как прирост пользы на монету затрат,
 * чтобы порекомендовать, что выгоднее всего купить прямо сейчас.
 * Разные апгрейды дают разную по природе пользу (плюс к тапу, макс. энергия,
 * скорость восполнения, лимит кликов), поэтому сравниваем каждый только
 * в своей "категории пользы на коин", а не в одной общей шкале.
 */
function bober_ai_advice_best_upgrade(array $userContext)
{
    $upgrades = is_array($userContext['upgrades'] ?? null) ? $userContext['upgrades'] : [];
    $balance = max(0, (int) ($userContext['balance'] ?? 0));

    // Условная "польза за 1 монету" для разных типов апгрейдов, чтобы сравнивать
    // разнородные эффекты. Веса подобраны по игровому смыслу прироста.
    $benefitPerUnit = [
        'tapSmall' => 1,
        'tapBig' => 5,
        'tapHuge' => 100,
        'energy' => 1000,
        'energyHuge' => 10000,
        'regenBoost' => 1, // характер эффекта другой (скорость), считаем отдельно как "качественный" апгрейд
        'clickRate' => 3,
    ];

    $affordable = [];
    $notAffordable = [];

    foreach ($upgrades as $upgrade) {
        $type = (string) ($upgrade['type'] ?? '');
        $price = max(1, (int) ($upgrade['currentPrice'] ?? 1));
        $benefit = $benefitPerUnit[$type] ?? 1;
        $efficiency = $benefit / $price;

        $entry = [
            'type' => $type,
            'price' => $price,
            'efficiencyPerCoin' => round($efficiency, 8),
        ];

        if (!empty($upgrade['affordable'])) {
            $affordable[] = $entry;
        } else {
            $notAffordable[] = $entry;
        }
    }

    usort($affordable, static function ($a, $b) {
        return $b['efficiencyPerCoin'] <=> $a['efficiencyPerCoin'];
    });
    usort($notAffordable, static function ($a, $b) {
        return $a['price'] <=> $b['price'];
    });

    return [
        'balance' => $balance,
        'bestAffordableNow' => $affordable[0] ?? null,
        'allAffordableRanked' => $affordable,
        'cheapestNotYetAffordable' => $notAffordable[0] ?? null,
    ];
}

/**
 * Считает примерное количество кликов и времени, необходимое для накопления
 * на конкретный (или самый дешёвый недоступный) апгрейд, исходя из текущего
 * plusPerClick игрока. Время — очень грубая оценка (не учитывает энергию/паузы),
 * поэтому в ответе явно помечаем это как приближение.
 */
function bober_ai_advice_time_to_afford(array $userContext, $targetUpgradeType)
{
    $balance = max(0, (int) ($userContext['balance'] ?? 0));
    $plusPerClick = max(1, (int) ($userContext['plusPerClick'] ?? 1));
    $upgrades = is_array($userContext['upgrades'] ?? null) ? $userContext['upgrades'] : [];

    $target = null;
    foreach ($upgrades as $upgrade) {
        if ((string) ($upgrade['type'] ?? '') === $targetUpgradeType) {
            $target = $upgrade;
            break;
        }
    }

    // Если конкретный тип не передан или не найден — берём самый дешёвый
    // из пока недоступных, это обычно и есть "следующая осмысленная цель".
    if ($target === null) {
        $candidates = array_filter($upgrades, static function ($u) {
            return empty($u['affordable']);
        });
        usort($candidates, static function ($a, $b) {
            return ((int) $a['currentPrice']) <=> ((int) $b['currentPrice']);
        });
        $target = $candidates[array_key_first($candidates)] ?? ($upgrades[0] ?? null);
    }

    if ($target === null) {
        return ['error' => 'Не удалось определить апгрейд для расчёта.'];
    }

    $price = max(1, (int) ($target['currentPrice'] ?? 1));
    $missing = max(0, $price - $balance);
    $clicksNeeded = (int) ceil($missing / $plusPerClick);

    return [
        'targetUpgradeType' => (string) ($target['type'] ?? ''),
        'price' => $price,
        'balance' => $balance,
        'missingCoins' => $missing,
        'plusPerClick' => $plusPerClick,
        'approxClicksNeeded' => $clicksNeeded,
        'note' => 'Грубая оценка по текущему приросту за клик, без учёта энергии и пауз.',
    ];
}

function bober_ai_advice_leaderboard_comparison(array $userContext)
{
    $leaderboard = is_array($userContext['leaderboard'] ?? null) ? $userContext['leaderboard'] : [];

    return [
        'rank' => $leaderboard['rank'] ?? null,
        'neighborAbove' => $leaderboard['neighborAbove'] ?? null,
        'neighborBelow' => $leaderboard['neighborBelow'] ?? null,
        'yourBalance' => (int) ($userContext['balance'] ?? 0),
    ];
}
