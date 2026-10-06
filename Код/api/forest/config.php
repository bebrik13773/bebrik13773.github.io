<?php
if (isset($_SERVER['SCRIPT_FILENAME']) && @realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    // Файл конфигурации не является эндпоинтом: прямой заход по HTTP закрыт.
    http_response_code(404);
    exit;
}
/**
 * Бобёр Кликер: Дикий Лес — единый конфиг баланса (ДЛ-01).
 *
 * Правило: в коде леса нет «магических чисел», только обращение к этому файлу.
 * Клиент получает нужную часть через forest/config (ДЛ-03), поэтому цифры не дублируются.
 * Все значения стартовые (см. WILD_FOREST_PLAN.md, раздел 8), подбираются в бете (ДЛ-39).
 */

return [
    'version' => 1,

    // ── Доступ ──────────────────────────────────────────────────────────
    'admin' => [
        // id владельца в таблице users (админка «Креатив», ДЛ-37). 0 = не задан.
        // Можно переопределить переменной окружения BOBER_FOREST_OWNER_ID.
        'owner_user_id' => 0,
    ],

    // ── Бета ────────────────────────────────────────────────────────────
    'beta' => ['enabled' => true],

    // ── Мир ─────────────────────────────────────────────────────────────
    'world' => [
        'seed'            => 1337,        // менять нельзя после старта беты
        'chunk_size_m'    => 32,
        'cell_size_m'     => 4,
        'world_radius_m'  => 100000,      // край леса (непроходимый туман)
        'city_x'          => 0,
        'city_z'          => 0,
        'city_radius_m'   => 120,
        'spawn_min_m'     => 600,
        'spawn_max_m'     => 4000,
        'spawn_min_gap_m' => 150,         // до чужого спавна
        'spawn_attempts'  => 30,
        'tier_step_m'     => 500,
        'tier_max'        => 10,
        'zone_edge_m'     => 600,         // опушка < 600 <= чаща < 1500 <= глушь
        'zone_deep_m'     => 1500,
        'village_safe_m'  => 30,
        'village_protect_m' => 40,        // зона защиты вокруг деревни
        'clearing_radius_m' => 12,
    ],

    // ── Время, сезоны ───────────────────────────────────────────────────
    'time' => [
        'game_day_seconds'  => 2880,      // 48 минут реального времени
        'tz'                => 'Asia/Omsk',
        'night_share'       => 0.35,
        'weather_bucket_s'  => 1200,      // 20 минут
        'season_growth'     => ['winter' => 0.3, 'spring' => 0.8, 'summer' => 1.0, 'autumn' => 0.8],
    ],

    // ── Бобёр ───────────────────────────────────────────────────────────
    'hero' => [
        'walk_speed'        => 3.2,
        'energy_base'       => 100,
        'energy_per_stamina_lvl' => 10,
        'stamina_max_lvl'   => 6,
        'cost_chop'         => 0.5,
        'cost_build_tap'    => 0.3,
        'cost_walk_per_m'   => 0.015,
        'regen_home_ps'     => 1.0,
        'regen_rest_ps'     => 0.1,
        'wake_energy'       => 20,
        'fish_energy'       => ['small' => 25, 'big' => 45],
        'pack_base_kg'      => 40,
        'pack_per_lvl_kg'   => 10,
        'pack_max_lvl'      => 8,
        'load_slowdown'     => 0.55,
        'load_exponent'     => 1.2,
        'start_coins'       => 50,
        'weights_kg'        => ['log_min' => 4, 'log_max' => 10, 'plank' => 3, 'ore' => 8, 'ingot' => 5, 'stone' => 10, 'fish' => 1],
        'xp_base'           => 100,       // XP(l) = base * l^exp
        'xp_exp'            => 1.6,
        'level_max'         => 50,
    ],

    // ── Деревья и рубка ─────────────────────────────────────────────────
    'trees' => [
        'hp_base'     => 8,
        'hp_tier_k'   => 0.30,
        'size_k'      => ['small' => 0.8, 'medium' => 1.0, 'large' => 1.5],
        'size_share'  => ['small' => 0.60, 'medium' => 0.30, 'large' => 0.10],
        'rare_chance' => 0.012, 'rare_tier_k' => 0.25, 'rare_cap' => 0.06,
        'treasure_chance' => 0.002,
        'seed_drop_chance' => 0.10,
        'density'     => ['conifer' => 0.62, 'broadleaf' => 0.55, 'birch' => 0.50, 'swamp' => 0.12, 'rocks' => 0.08, 'water' => 0.0],
        // порода => [H, брёвна мал/ср/бол, цена бревна, дни роста]
        'species' => [
            'spruce'     => ['h' => 1.0, 'logs' => [2, 3, 5],  'price' => 10,  'grow_days' => 4],
            'pine'       => ['h' => 0.9, 'logs' => [2, 3, 4],  'price' => 11,  'grow_days' => 4],
            'birch'      => ['h' => 0.7, 'logs' => [1, 2, 3],  'price' => 8,   'grow_days' => 3],
            'aspen'      => ['h' => 0.8, 'logs' => [2, 3, 4],  'price' => 8,   'grow_days' => 4],
            'maple'      => ['h' => 1.3, 'logs' => [2, 3, 4],  'price' => 14,  'grow_days' => 5],
            'oak'        => ['h' => 1.8, 'logs' => [3, 4, 6],  'price' => 22,  'grow_days' => 6],
            'black_alder'=> ['h' => 1.2, 'logs' => [2, 3, 3],  'price' => 40,  'grow_days' => 6],
            'gold_birch' => ['h' => 2.5, 'logs' => [2, 3, 4],  'price' => 90,  'grow_days' => 7],
            'ancient_cedar' => ['h' => 3.2, 'logs' => [4, 6, 8], 'price' => 80, 'grow_days' => 7],
            'iron_oak'   => ['h' => 4.5, 'logs' => [5, 7, 10], 'price' => 130, 'grow_days' => 7],
        ],
        'treasure_coins' => [50, 300],
        // уровень => [сила, монеты, слитки]
        'axe' => [
            1 => ['power' => 1.0, 'coins' => 0,     'ingots' => 0],
            2 => ['power' => 1.4, 'coins' => 400,   'ingots' => 0],
            3 => ['power' => 2.0, 'coins' => 1200,  'ingots' => 5],
            4 => ['power' => 2.8, 'coins' => 3500,  'ingots' => 15],
            5 => ['power' => 4.0, 'coins' => 9000,  'ingots' => 40],
            6 => ['power' => 5.6, 'coins' => 25000, 'ingots' => 100],
        ],
        'pickaxe_price' => 250,
        'rock_hp'   => ['boulder' => 6, 'ore' => 12],
    ],

    // ── Экономика ───────────────────────────────────────────────────────
    'economy' => [
        'prices' => [
            'plank' => 28, 'crate' => 110, 'barrel' => 230, 'table' => 520, 'chair' => 190,
            'ore' => 14, 'ingot' => 140, 'stone' => 18, 'asphalt_bag' => 60,
            'fish_small' => 18, 'fish_big' => 40,
        ],
        'recipes' => [
            'plank'  => ['in' => ['log' => 2],                  'sec' => 20, 'building' => 'sawmill'],
            'crate'  => ['in' => ['plank' => 3],                'sec' => 30, 'building' => 'workshop'],
            'barrel' => ['in' => ['plank' => 4, 'ingot' => 1],  'sec' => 40, 'building' => 'workshop'],
            'table'  => ['in' => ['plank' => 6, 'ingot' => 1],  'sec' => 60, 'building' => 'workshop'],
            'chair'  => ['in' => ['plank' => 2],                'sec' => 25, 'building' => 'workshop'],
            'ingot'  => ['in' => ['ore' => 3, 'log' => 2],      'sec' => 30, 'building' => 'smelter'],
            'stone'  => ['in' => ['boulder' => 4],              'sec' => 30, 'building' => 'quarry'],
        ],
        'demand_amp'        => 0.25,
        'saturation_floor'  => 0.30,
        'starter_buyer_ratio' => 0.60,    // скупщик на поляне (ДЛ-14)
        'starter_buyer_daily_cap' => 400,
        'crate_capacity'    => 100,
    ],

    // ── Постройки: бревна/доски/слитки/монеты/тапы ──────────────────────
    'buildings' => [
        'warehouse' => ['logs' => 30, 'planks' => 0,  'ingots' => 0,  'coins' => 0,    'taps' => 150, 'capacity' => 300],
        'house'     => ['logs' => 40, 'planks' => 0,  'ingots' => 0,  'coins' => 0,    'taps' => 250, 'beds' => 2, 'beds_per_lvl' => 1, 'beds_max' => 6],
        'sawmill'   => ['logs' => 40, 'planks' => 0,  'ingots' => 4,  'coins' => 0,    'taps' => 300],
        'market'    => ['logs' => 20, 'planks' => 16, 'ingots' => 0,  'coins' => 0,    'taps' => 250],
        'workshop'  => ['logs' => 20, 'planks' => 20, 'ingots' => 6,  'coins' => 0,    'taps' => 400],
        'garden'    => ['logs' => 15, 'planks' => 6,  'ingots' => 0,  'coins' => 0,    'taps' => 120, 'slots' => 6, 'slots_per_lvl' => 4],
        'smelter'   => ['logs' => 30, 'planks' => 20, 'ingots' => 8,  'coins' => 2500, 'taps' => 600],
        'forge'     => ['logs' => 20, 'planks' => 30, 'ingots' => 10, 'coins' => 3500, 'taps' => 700],
        'quarry'    => ['logs' => 20, 'planks' => 20, 'ingots' => 6,  'coins' => 1500, 'taps' => 500],
        'max_dist_from_village_m' => 60,
        'max_slope_deg'           => 12,
        'cancel_full_refund_until' => 0.20,
        'cancel_partial_until'     => 0.70,
        'demolish_refund'          => 0.50,
        'helper_build_ps'          => 0.4,
        'max_warehouses'           => 3,
    ],

    // ── Улучшения бобра ─────────────────────────────────────────────────
    'upgrades' => [
        'pack'    => [2 => 200, 3 => 500, 4 => 1100, 5 => 2200, 6 => 4000, 7 => 7000, 8 => 12000],
        'stamina' => [2 => 250, 3 => 600, 4 => 1300, 5 => 2800, 6 => 5500],
        'lamp'    => [1 => 150, 2 => 700, 3 => 2500, 4 => 8000],
        'lamp_ingots' => [3 => 2, 4 => 8],
        'lamp_bonus_m' => [1 => 12, 2 => 24, 3 => 40, 4 => 60],
        'boots_speed' => 0.10,
        'shop_axe_markup' => 1.5,
    ],

    // ── Плотина ─────────────────────────────────────────────────────────
    'dam' => [
        'wear_per_min'      => 0.05,
        'level_wear_k'      => 0.12,
        'event_flood_k'     => 3.0,
        'warn_creak'        => 50,
        'warn_crack'        => 25,
        'offline_grace_h'   => 10,
        'loss_min'          => 0.25,
        'loss_max'          => 0.40,
        'flood_radius_m'    => 35,
        'drain_taps'        => 60,
        'drain_planks'      => 8,
        'repair_logs'       => ['gain' => 10, 'cost' => 10],
        'repair_planks'     => ['gain' => 12, 'cost' => 5],
        'repair_taps'       => 20,
        'after_breach_dur'  => 40,
        'patch_logs'        => 30,
        'upgrade' => [2 => ['logs' => 40, 'planks' => 20, 'ingots' => 0], 3 => ['logs' => 80, 'planks' => 40, 'ingots' => 10]],
    ],

    // ── Погода: шансы (на 100) по сезонам и множители ──────────────────
    'weather' => [
        'table' => [
            'summer' => ['clear' => 48, 'cloudy' => 22, 'rain' => 18, 'storm' => 7, 'fog' => 5, 'snow' => 0, 'blizzard' => 0],
            'autumn' => ['clear' => 28, 'cloudy' => 27, 'rain' => 28, 'storm' => 5, 'fog' => 12, 'snow' => 0, 'blizzard' => 0],
            'winter' => ['clear' => 25, 'cloudy' => 25, 'rain' => 0,  'storm' => 0, 'fog' => 8,  'snow' => 38, 'blizzard' => 4],
            'spring' => ['clear' => 35, 'cloudy' => 25, 'rain' => 28, 'storm' => 5, 'fog' => 7,  'snow' => 0, 'blizzard' => 0],
        ],
        // [износ плотины, видимость м, рост, расход энергии, звери]
        'mods' => [
            'clear'    => ['dam' => 1.0, 'vis' => 120, 'grow' => 1.0, 'energy' => 1.0,  'beasts' => 1.0],
            'cloudy'   => ['dam' => 1.0, 'vis' => 110, 'grow' => 1.0, 'energy' => 1.0,  'beasts' => 1.0],
            'rain'     => ['dam' => 2.5, 'vis' => 80,  'grow' => 1.2, 'energy' => 1.1,  'beasts' => 0.8],
            'storm'    => ['dam' => 4.0, 'vis' => 55,  'grow' => 1.2, 'energy' => 1.25, 'beasts' => 0.6],
            'fog'      => ['dam' => 1.0, 'vis' => 40,  'grow' => 1.0, 'energy' => 1.0,  'beasts' => 1.2],
            'snow'     => ['dam' => 1.2, 'vis' => 70,  'grow' => 0.3, 'energy' => 1.15, 'beasts' => 0.8],
            'blizzard' => ['dam' => 2.0, 'vis' => 35,  'grow' => 0.1, 'energy' => 1.3,  'beasts' => 0.5],
        ],
        'night_vis_m' => 18,
    ],

    // ── Торговля ────────────────────────────────────────────────────────
    'trade' => [
        'arrival_base_min'  => 6,
        'arrival_q_k'       => 0.5,
        'queue_max'         => 3,
        'wait_min'          => 5,
        'session_ttl_s'     => 120,
        'mood_k'            => 0.25,
        'buyers' => [
            'greedy'   => ['mult' => [0.90, 1.00], 'patience' => 3],
            'rich'     => ['mult' => 1.30, 'mult_logs' => 1.0, 'patience' => 5],
            'builder'  => ['mult' => 0.95, 'patience' => 4, 'qty' => [20, 40]],
            'reseller' => ['mult' => 1.20, 'patience' => 4, 'wander' => true],
        ],
        'phrases' => [
            'smile' => ['mood' => 0.15, 'mood_greedy' => 0.05],
            'press' => ['price_up' => 0.10, 'chance' => 0.5, 'mood' => -0.2],
            'yield' => ['mood' => 0.10],
        ],
        'fail_patience' => -1, 'fail_mood' => -0.1,
    ],

    // ── Помощники ───────────────────────────────────────────────────────
    'helpers' => [
        'speed_k'           => 0.4,
        'skill_range'       => [0.8, 1.4],
        'carry_kg'          => 30,
        'carry_range_kg'    => [20, 40],
        'wage_base'         => 150, 'wage_skill' => 600, 'wage_greed' => 150,
        'hire_accept_full'  => 1.0, 'hire_accept_min' => 0.8,
        'hire_tries_per_day'=> 3,
        'pay_period_days'   => 30,
        'pay_window_days'   => 3,
        'overdue_leave_days'=> 7,
        'wait_grace_days'   => 3, 'wait_mood' => -20,
        'lower_offer_min'   => 0.7, 'lower_mood' => -15,
        'night_courage'     => 0.6,
        'beast_courage'     => 0.5, 'beast_flee_min' => 10,
        'walk_penalty'      => 0.25,
        'max_helpers'       => 8,
        'pool_spare'        => 5,
        'offline_cap_h'     => 48,
        'auto_radius_m'     => 60, 'area_radius_max_m' => 80,
        'mood_low'          => 30,
    ],

    // ── Звери ───────────────────────────────────────────────────────────
    'beasts' => [
        'check_every_m'   => 120,
        'check_every_s'   => 60,
        'chance'          => ['fox' => 0.08, 'wolf' => 0.12, 'bear' => 0.05],
        'sleep_mult'      => 2.0,
        'fox'  => ['min_r' => 500,  'steal' => 0.25, 'scare_s' => 3],
        'wolf' => ['min_r' => 600,  'drop' => 0.50, 'energy' => -20, 'pickup_ttl_s' => 300],
        'bear' => ['min_r' => 1500, 'warn_m' => 40, 'attack_m' => 12, 'return_energy' => 10],
    ],

    // ── Шахта и портал в старый кликер (ДЛ-41) ─────────────────────────
    'mine' => [
        'total_rocks'      => 5000000,   // общий завал на весь мир
        'pick_durability'  => 500,       // ударов железной кирки
        'pick_ingots'      => 3,         // слитков на одну кирку
        'energy_per_hit'   => 0.2,
        'portal_permanent' => true,
        'rating_bonus_max' => 30,        // потолок бонуса к рейтингу
        'rating_bonus_k'   => 5,         // M = min(max, round(k * log10(1 + камней)))
    ],

    // ── Интерфейс ───────────────────────────────────────────────────────
    'ui' => [
        'hotbar_slots' => 6,
    ],

    // ── Рейтинг ─────────────────────────────────────────────────────────
    'rating' => [
        'weights'   => ['W' => 0.25, 'D' => 0.15, 'S' => 0.25, 'V' => 0.20, 'I' => 0.15],
        'wealth_ref' => 10000000, 'sold_ref' => 10000000, 'distance_ref_m' => 8000,
        'recalc_min_interval_s' => 300,
    ],

    // ── События (даты в приложении 14.2 плана) ──────────────────────────
    'events' => [
        'new_year'   => ['from' => '12-25', 'to' => '01-10', 'snow_share' => 0.60, 'demand_k' => 1.25],
        'maslenitsa' => ['demand_logs_k' => 1.5],
        'flood'      => ['from' => '04-01', 'to' => '04-21', 'dam_k' => 3.0, 'rain_bonus' => 10],
        'forest_day' => ['yield_k' => 1.5, 'upgrade_discount' => 0.30, 'days_around' => 3],
        'dark_night' => ['from' => '10-24', 'to' => '10-31', 'night_share' => 0.55, 'wolf_k' => 2.0],
    ],

    // ── Защита и лимиты API ─────────────────────────────────────────────
    'limits' => [
        'max_taps_per_s'        => 6,
        'tap_bucket'            => 40,
        'sync_min_interval_s'   => 3,
        'sync_active_ms'        => 8000,
        'sync_idle_ms'          => 15000,
        'sync_background_ms'    => 60000,
        'idle_after_s'          => 30,
        'max_actions_per_sync'  => 60,
        'max_body_bytes'        => 16384,
        'move_tolerance_k'      => 1.35,
        'move_tolerance_m'      => 3,
        'chop_reach_m'          => 3.5,
        'pickup_reach_m'        => 3.0,
        'strikes_window_s'      => 600,
        'strikes_soft'          => 3,
        'strikes_cut'           => 7,
        'strikes_block'         => 8,
        'block_seconds'         => 600,
        'presence_ttl_s'        => 120,
        'presence_radius_m'     => 150, 'presence_max' => 20,
        'villages_radius_m'     => 500,
        'changes_per_sync'      => 200,
        'chat_max_len'          => 300,
        'log_retention_days'    => 90,
    ],
];
