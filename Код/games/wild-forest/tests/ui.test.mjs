import test from 'node:test';
import assert from 'node:assert/strict';
import {
    energyLevel, packLevel, dayPhase, isNight, phaseInfo, seasonOf, weatherInfo,
    formatCoins, formatKg, truncateName, barShare, GAME_DAY_SECONDS,
} from '../src/ui/hud-logic.js';
import { createTypewriter, normalizeLine, createChoiceTimer, createLineQueue } from '../src/ui/dialog-logic.js';
import { sortItems, totalWeight } from '../src/ui/inventory.js';
import { getPref, setPref, clamp01 } from '../src/ui/prefs.js';
import { S } from '../src/ui/strings.ru.js';

test('энергия: пороги 20% и 10%', () => {
    assert.equal(energyLevel(100, 100), 'ok');
    assert.equal(energyLevel(21, 100), 'ok');
    assert.equal(energyLevel(20, 100), 'low');
    assert.equal(energyLevel(10, 100), 'critical');
    assert.equal(energyLevel(0, 100), 'critical');
    assert.equal(energyLevel(5, 0), 'ok');
});

test('рюкзак: тяжело от 80%, полный от 100%', () => {
    assert.equal(packLevel(10, 40), 'ok');
    assert.equal(packLevel(32, 40), 'heavy');
    assert.equal(packLevel(40, 40), 'full');
    assert.equal(packLevel(41, 40), 'full');
});

test('фаза суток: цикл 48 минут, ночь 35%', () => {
    assert.equal(dayPhase(0), 0);
    assert.ok(Math.abs(dayPhase(GAME_DAY_SECONDS * 500) - 0.5) < 1e-9);
    assert.ok(dayPhase(-1000) >= 0);
    let night = 0;
    for (let i = 0; i < 1000; i += 1) if (isNight(i / 1000)) night += 1;
    assert.ok(Math.abs(night / 1000 - 0.35) < 0.01);
    assert.equal(phaseInfo(0.0).id, 'night');
    assert.equal(phaseInfo(0.2).id, 'dawn');
    assert.equal(phaseInfo(0.5).id, 'day');
    assert.equal(phaseInfo(0.8).id, 'dusk');
    assert.equal(phaseInfo(0.9).id, 'night');
});

test('сезон по месяцам в часовом поясе игры', () => {
    assert.equal(seasonOf(new Date('2026-01-15T12:00:00Z')).id, 'winter');
    assert.equal(seasonOf(new Date('2026-12-01T12:00:00Z')).id, 'winter');
    assert.equal(seasonOf(new Date('2026-04-10T12:00:00Z')).id, 'spring');
    assert.equal(seasonOf(new Date('2026-07-10T12:00:00Z')).id, 'summer');
    assert.equal(seasonOf(new Date('2026-10-12T12:00:00Z')).id, 'autumn');
    // граница: 30 ноября 20:00 UTC уже 1 декабря в Омске (UTC+6)
    assert.equal(seasonOf(new Date('2026-11-30T20:00:00Z')).id, 'winter');
});

test('погода: известные id и неизвестные', () => {
    assert.equal(weatherInfo('rain').label, S.weather.rain);
    assert.equal(weatherInfo('nope'), null);
    assert.equal(weatherInfo(null), null);
});

test('формат монет и килограммов', () => {
    assert.equal(formatCoins(0), '0');
    assert.equal(formatCoins(999), '999');
    assert.equal(formatCoins(1500), '1\u202f500');
    assert.equal(formatCoins(25000), '25\u202f000');
    assert.equal(formatCoins(1250000), '1,2 млн');
    assert.equal(formatCoins(-5), '0');
    assert.equal(formatKg(12.5), '12,5');
    assert.equal(formatKg(40), '40');
    assert.equal(formatKg(0.04), '0');
});

test('имена обрезаются аккуратно, не длиннее лимита', () => {
    assert.equal(truncateName('Тихая Заводь'), 'Тихая Заводь');
    const long = truncateName('Необыкновенно Длинноеназвание Деревни', 24);
    assert.ok(Array.from(long).length <= 24, long);
    assert.ok(long.endsWith('…'));
    assert.equal(truncateName(null), '');
});

test('полоса: доля от 0 до 1', () => {
    assert.equal(barShare(50, 100), 0.5);
    assert.equal(barShare(-5, 100), 0);
    assert.equal(barShare(500, 100), 1);
    assert.equal(barShare(5, 0), 0);
});

test('печать текста: по буквам, скип, эмодзи не рвутся', () => {
    const t = createTypewriter('Привет 🦫!', 10);
    assert.equal(t.total, 9);
    assert.equal(t.reveal(0.3), true);
    assert.equal(t.text, 'При');
    assert.equal(t.done, false);
    t.reveal(0.4);
    assert.equal(t.text, 'Привет ');
    t.reveal(0.1);
    assert.equal(t.text, 'Привет 🦫');
    t.skip();
    assert.equal(t.text, 'Привет 🦫!');
    assert.equal(t.done, true);
    assert.equal(t.reveal(1), false);
});

test('реплика: не больше 4 вариантов, пустые отбрасываются, таймаут только с вариантами', () => {
    const line = normalizeLine({ text: 'Да?', choices: [{ label: 'a' }, { label: 'b' }, { label: '' }, { label: 'c' }, { label: 'd' }, { label: 'e' }], timeoutMs: 5000 });
    assert.equal(line.choices.length, 4);
    assert.equal(line.timeoutMs, 5000);
    assert.equal(line.defaultId, 3);
    const mono = normalizeLine({ text: 'Просто', timeoutMs: 5000 });
    assert.equal(mono.choices.length, 0);
    assert.equal(mono.timeoutMs, 0);
    assert.equal(normalizeLine(null).text, '');
});

test('таймер выбора: считает только после старта и один раз срабатывает', () => {
    const timer = createChoiceTimer(1000);
    assert.equal(timer.tick(2), false);
    timer.start();
    assert.equal(timer.tick(0.4), false);
    assert.ok(Math.abs(timer.share - 0.6) < 1e-9);
    assert.equal(timer.tick(0.7), true);
    assert.equal(timer.tick(1), false);
    assert.equal(createChoiceTimer(0).tick(5), false);
});

test('очередь реплик: порядок сохраняется', () => {
    const q = createLineQueue();
    q.push(1); q.push(2); q.push(3);
    assert.equal(q.size, 3);
    assert.equal(q.shift(), 1);
    assert.equal(q.clear(), 2);
    assert.equal(q.shift(), undefined);
});

test('рюкзак: вес и сортировка', () => {
    const items = [
        { key: 'plank', qty: 3, kg: 3 },
        { key: 'log', qty: 5, kg: 4 },
        { key: 'fish_small', qty: 9, kg: 1 },
    ];
    assert.equal(totalWeight(items), 9 + 20 + 9);
    assert.deepEqual(sortItems(items, 'weight').map((i) => i.key), ['log', 'plank', 'fish_small']);
    assert.deepEqual(sortItems(items, 'qty').map((i) => i.key), ['fish_small', 'log', 'plank']);
    assert.deepEqual(sortItems(items, 'name').map((i) => i.key), ['Бревно', 'Доска', 'Мелкая рыба'].map((n) => items.find((i) => S.items[i.key].name === n).key));
    assert.equal(items[0].key, 'plank', 'исходный массив не меняется');
});

test('настройки громкости: границы, значения по умолчанию, сбой хранилища', () => {
    const mem = new Map();
    const storage = { getItem: (k) => (mem.has(k) ? mem.get(k) : null), setItem: (k, v) => mem.set(k, v) };
    assert.equal(getPref('wf.vol.sfx', storage), 0.8);
    assert.equal(setPref('wf.vol.sfx', 2, storage), 1);
    assert.equal(getPref('wf.vol.sfx', storage), 1);
    assert.equal(setPref('wf.vol.music', -1, storage), 0);
    assert.equal(clamp01('abc', 0.3), 0.3);
    const broken = { getItem() { throw new Error('нет'); }, setItem() { throw new Error('нет'); } };
    assert.equal(getPref('wf.vol.music', broken), 0.5);
    assert.equal(setPref('wf.vol.music', 0.4, broken), 0.4);
});

test('строки интерфейса: у каждого предмета есть имя и значок', () => {
    Object.entries(S.items).forEach(([key, v]) => {
        assert.ok(v.name && v.icon, key);
    });
});
