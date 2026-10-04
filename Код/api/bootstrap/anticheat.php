<?php

// ---------------------------------------------------------------------------
// Серверный античит автокликеров: скоринг признаков тапов, журнал нарушений,
// растущий штраф (с учётом эконом-индекса), бан и откат наказания из админки.
// Признаки считает клиент (окна по ~15 тапов), сервер оценивает их по порогам,
// выведенным из замеров реального автокликера и ручных тапов.
// ---------------------------------------------------------------------------

// Мастер-флаг античита: false = никаких проверок, штрафов и банов.
if (!defined('ANTICHEAT_ENABLED')) {
    define('ANTICHEAT_ENABLED', false);
}

function bober_ensure_cheat_schema($conn)
{
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $sql = <<<SQL
CREATE TABLE IF NOT EXISTS `cheat_events` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `level` VARCHAR(16) NOT NULL DEFAULT 'suspicious',
    `points` INT NOT NULL DEFAULT 0,
    `strike_no` INT NOT NULL DEFAULT 0,
    `removed_score` BIGINT NOT NULL DEFAULT 0,
    `penalty_score` BIGINT NOT NULL DEFAULT 0,
    `penalty_percent` DECIMAL(6,2) NOT NULL DEFAULT 0,
    `economy_multiplier` DECIMAL(6,3) NOT NULL DEFAULT 1,
    `reasons` VARCHAR(500) NOT NULL DEFAULT '',
    `features_json` TEXT NULL,
    `reverted_at` DATETIME NULL DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_cheat_events_user` (`user_id`, `level`, `reverted_at`, `created_at`)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL;

    if (!$conn->query($sql)) {
        throw new RuntimeException('Не удалось создать таблицу нарушений античита.');
    }

    $ensured = true;
}

function bober_cheat_clean_windows($rawWindows)
{
    $clean = [];
    foreach (array_slice((array) $rawWindows, 0, 5) as $window) {
        if (!is_array($window)) {
            continue;
        }

        $n = (int) ($window['n'] ?? 0);
        if ($n < 12 || $n > 200) {
            continue;
        }

        $num = static function ($key) use ($window) {
            return isset($window[$key]) && is_numeric($window[$key]) ? (float) $window[$key] : null;
        };

        $clean[] = [
            'n' => $n,
            'cv' => $num('cv'),
            'meanMs' => $num('meanMs'),
            'holdMean' => $num('holdMean'),
            'holdSd' => $num('holdSd'),
            'emptyPt' => $num('emptyPt'),
            'zeroContact' => $num('zeroContact'),
            'untrusted' => (int) ($window['untrusted'] ?? 0),
            'touchRatio' => $num('touchRatio'),
            'multi' => $num('multi'),
            'posSd' => $num('posSd'),
            'pressureSd' => $num('pressureSd'),
            'sizeSd' => $num('sizeSd'),
        ];
    }

    return $clean;
}

// Возвращает ['points' => int, 'level' => none|suspicious|certain, 'reasons' => []].
function bober_cheat_score_windows(array $windows)
{
    $best = ['points' => 0, 'level' => 'none', 'reasons' => []];

    foreach ($windows as $w) {
        $points = 0;
        $reasons = [];

        // Несколько пальцев одновременно — признак человека: ритм и скорость не считаем,
        // оцениваем только признаки устройства (точка, длительность касания, синтетика).
        $multi = $w['multi'] !== null && $w['multi'] >= 0.2;

        if (!$multi && $w['cv'] !== null) {
            if ($w['cv'] < 0.05) {
                $points += 4;
                $reasons[] = 'слишком ровный ритм';
            } elseif ($w['cv'] < 0.10) {
                $points += 3;
                $reasons[] = 'очень ровный ритм';
            } elseif ($w['cv'] < 0.18) {
                $points += 2;
                $reasons[] = 'ритм подозрительно ровный';
            }
        }

        // Палец не попадает в одну и ту же точку с точностью до пикселя (у мыши это норма — для неё posSd не передаётся).
        if (!$multi && $w['posSd'] !== null && $w['posSd'] < 1.2) {
            $points += 3;
            $reasons[] = 'касания в одну и ту же точку';
        }

        if ($w['holdMean'] !== null && $w['holdMean'] > 0 && $w['holdMean'] < 20) {
            $points += 2;
            $reasons[] = 'нечеловечески короткое касание';
        }
        if ($w['holdSd'] !== null && $w['holdMean'] !== null && $w['holdMean'] > 0 && $w['holdSd'] < 8) {
            $points += 1;
            $reasons[] = 'одинаковая длительность касаний';
        }
        if ($w['pressureSd'] !== null && $w['sizeSd'] !== null && $w['pressureSd'] < 0.01 && $w['sizeSd'] < 0.5) {
            $points += 1;
            $reasons[] = 'одинаковая сила и площадь касания';
        }
        if ($w['emptyPt'] !== null && $w['emptyPt'] >= 0.9) {
            $points += 1;
            $reasons[] = 'нет типа указателя';
        }
        if ($w['zeroContact'] !== null && $w['zeroContact'] >= 0.9) {
            $points += 1;
            $reasons[] = 'нулевая площадь касания';
        }
        if ($w['untrusted'] > 0) {
            $points += 7;
            $reasons[] = 'синтетические события';
        }
        // Одна высокая скорость баллов не даёт: быстрые пальцы — не признак робота.

        if ($points > $best['points']) {
            $best['points'] = $points;
            $best['reasons'] = $reasons;
        }
    }

    // Первое срабатывание — всегда предупреждение; бан только после него (см. bober_evaluate_tap_features)
    // или сразу при синтетических событиях (скрипт, а не палец).
    $best['level'] = $best['points'] >= 4 ? 'suspicious' : 'none';
    foreach ($windows as $w) {
        if ($w['untrusted'] > 0) {
            $best['level'] = 'certain';
            break;
        }
    }

    return $best;
}

function bober_cheat_count_events($conn, $userId, $level, $sinceSeconds = 0)
{
    $sql = 'SELECT COUNT(*) AS total FROM cheat_events WHERE user_id = ? AND level = ? AND reverted_at IS NULL';
    if ($sinceSeconds > 0) {
        $sql .= ' AND created_at > (NOW() - INTERVAL ' . (int) $sinceSeconds . ' SECOND)';
    }

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return 0;
    }
    $stmt->bind_param('is', $userId, $level);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    if ($result) {
        $result->free();
    }
    $stmt->close();

    return max(0, (int) ($row['total'] ?? 0));
}

function bober_cheat_insert_event($conn, $userId, array $event)
{
    $stmt = $conn->prepare('INSERT INTO cheat_events (user_id, level, points, strike_no, removed_score, penalty_score, penalty_percent, economy_multiplier, reasons, features_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    if (!$stmt) {
        return;
    }

    $level = (string) $event['level'];
    $points = (int) $event['points'];
    $strike = (int) ($event['strike'] ?? 0);
    $removed = (int) ($event['removed'] ?? 0);
    $penalty = (int) ($event['penalty'] ?? 0);
    $percent = (float) ($event['percent'] ?? 0);
    $mult = (float) ($event['multiplier'] ?? 1);
    $reasons = mb_substr(implode(', ', (array) ($event['reasons'] ?? [])), 0, 500);
    $features = json_encode($event['windows'] ?? [], JSON_UNESCAPED_UNICODE);

    $stmt->bind_param('isiiiiddss', $userId, $level, $points, $strike, $removed, $penalty, $percent, $mult, $reasons, $features);
    $stmt->execute();
    $stmt->close();
}

// Оценивает признаки тапов из синхронизации.
// Возвращает null (всё чисто) или ['level', 'newScore', 'message', ...].
function bober_evaluate_tap_features($conn, $userId, $rawWindows, $requestedScore, $previousScore)
{
    // ====================================================================
    // АНТИЧИТ ОТКЛЮЧЁН: ПОЛНАЯ БЛОКИРОВКА БЕЗ УДАЛЕНИЯ КОДА
    // ====================================================================
    // Функция вернёт null и не будет проверять, штрафовать или банить.
    // Весь остальной код оставлен ниже (закомментирован логически).
    // ====================================================================
    if (!ANTICHEAT_ENABLED) {
        return null;
    }
    return null;

    // // Всё нижеприведённое тело функции не выполняется:
    // $windows = bober_cheat_clean_windows($rawWindows);
    // if (!$windows) {
    //     return null;
    // }
    //
    // bober_ensure_cheat_schema($conn);
    //
    // $verdict = bober_cheat_score_windows($windows);
    // if ($verdict['level'] === 'none') {
    //     return null;
    // }
    //
    // $level = $verdict['level'];
    // $reasons = $verdict['reasons'];
    //
    // // Одно предупреждение, потом бан. После предупреждения даём 45 секунд, чтобы остановиться;
    // // если и дальше есть признаки робота (за сутки) — это уже бан.
    // if ($level === 'suspicious') {
    //     if (bober_cheat_count_events($conn, $userId, 'suspicious', 45) > 0) {
    //         return null;
    //     }
    //     if (bober_cheat_count_events($conn, $userId, 'suspicious', 86400) >= 1) {
    //         $level = 'certain';
    //         $reasons[] = 'признаки автокликера после предупреждения';
    //     }
    // }
    //
    // if ($level === 'suspicious') {
    //     bober_cheat_insert_event($conn, $userId, [
    //         'level' => 'suspicious',
    //         'points' => $verdict['points'],
    //         'reasons' => $reasons,
    //         'windows' => $windows,
    //     ]);
    //
    //     return [
    //         'level' => 'suspicious',
    //         'newScore' => null,
    //         'message' => 'Похоже на автокликер: ' . implode(', ', array_slice($reasons, 0, 2)) . '. Следующее нарушение приведёт к штрафу и блокировке.',
    //     ];
    // }
    //
    // // certain: отнимаем накрутку этой синхронизации + растущий штраф с учётом эконом-индекса.
    // $strike = bober_cheat_count_events($conn, $userId, 'certain') + 1;
    // $basePercent = min(50.0, 5.0 * pow(2, $strike - 1));
    //
    // $multiplier = 1.0;
    // try {
    //     $profile = bober_build_user_economy_profile(bober_fetch_user_purchase_runtime_state($conn, $userId));
    //     $multiplier = max(1.0, (float) ($profile['multiplier'] ?? 1.0));
    // } catch (Throwable $e) {
    //     $multiplier = 1.0;
    // }
    //
    // $percent = min(60.0, $basePercent * $multiplier);
    // $previousScore = max(0, (int) $previousScore);
    // $removed = max(0, (int) $requestedScore - $previousScore);
    // $penalty = (int) round($previousScore * $percent / 100);
    // $newScore = max(0, $previousScore - $penalty);
    //
    // bober_cheat_insert_event($conn, $userId, [
    //     'level' => 'certain',
    //     'points' => $verdict['points'],
    //     'strike' => $strike,
    //     'removed' => $removed,
    //     'penalty' => min($penalty, $previousScore),
    //     'percent' => $percent,
    //     'multiplier' => $multiplier,
    //     'reasons' => $reasons,
    //     'windows' => $windows,
    // ]);
    //
    // $reasonText = 'Автокликер (серверная проверка): ' . implode(', ', array_slice($reasons, 0, 3));
    // bober_issue_user_ban($conn, $userId, $reasonText, [
    //     'source' => 'autoclicker',
    //     'detected_by' => 'server',
    //     'meta' => ['points' => $verdict['points'], 'strike' => $strike, 'penalty_percent' => $percent],
    // ]);
    //
    // return [
    //     'level' => 'certain',
    //     'newScore' => $newScore,
    //     'message' => $reasonText,
    //     'strike' => $strike,
    //     'percent' => $percent,
    // ];
}

// Откат: возвращает отнятые баллы, помечает нарушения отменёнными, снимает баны.
function bober_revert_cheat_penalties($conn, $userId)
{
    bober_ensure_cheat_schema($conn);
    $userId = max(0, (int) $userId);

    $stmt = $conn->prepare("SELECT COALESCE(SUM(removed_score + penalty_score), 0) AS restore, COUNT(*) AS total FROM cheat_events WHERE user_id = ? AND reverted_at IS NULL");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : ['restore' => 0, 'total' => 0];
    if ($result) {
        $result->free();
    }
    $stmt->close();

    $restore = max(0, (int) ($row['restore'] ?? 0));

    if ($restore > 0) {
        $up = $conn->prepare('UPDATE users SET score = score + ? WHERE id = ?');
        $up->bind_param('ii', $restore, $userId);
        $up->execute();
        $up->close();
    }

    $mark = $conn->prepare('UPDATE cheat_events SET reverted_at = CURRENT_TIMESTAMP WHERE user_id = ? AND reverted_at IS NULL');
    $mark->bind_param('i', $userId);
    $mark->execute();
    $mark->close();

    $lifted = bober_lift_user_bans($conn, $userId);

    return [
        'restoredScore' => $restore,
        'revertedEvents' => max(0, (int) ($row['total'] ?? 0)),
        'lifted' => $lifted,
    ];
}
