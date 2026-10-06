// Запуск: node --test Код/games/wild-forest/tests/
import test from 'node:test';
import assert from 'node:assert/strict';
import { QUALITY_PRESETS, QUALITY_ORDER, DEFAULT_QUALITY } from '../src/config.js';
import { loadQuality, saveQuality, getPreset, lowerQuality, shouldSuggestLower, createQualityController, isQuality } from '../src/core/quality.js';

function memoryStorage(initial = {}) {
    const data = { ...initial };
    return {
        getItem: (k) => (k in data ? data[k] : null),
        setItem: (k, v) => { data[k] = String(v); },
    };
}

test('пресеты совпадают с таблицей 8.10 плана', () => {
    assert.deepEqual(QUALITY_ORDER, ['low', 'medium', 'high']);
    assert.equal(QUALITY_PRESETS.low.renderScale, 0.6);
    assert.equal(QUALITY_PRESETS.medium.renderScale, 0.8);
    assert.equal(QUALITY_PRESETS.high.renderScale, 1.0);
    assert.deepEqual([3, 5, 7], QUALITY_ORDER.map((q) => QUALITY_PRESETS[q].drawChunks));
    assert.deepEqual([96, 160, 224], QUALITY_ORDER.map((q) => QUALITY_PRESETS[q].drawDistance));
    assert.deepEqual([0.6, 0.85, 1], QUALITY_ORDER.map((q) => QUALITY_PRESETS[q].treeDensity));
    assert.deepEqual(['none', 'blob', 'real'], QUALITY_ORDER.map((q) => QUALITY_PRESETS[q].shadows));
    assert.equal(DEFAULT_QUALITY, 'medium');
});

test('загрузка и сохранение выбора', () => {
    const store = memoryStorage();
    assert.equal(loadQuality(store), 'medium');
    saveQuality('high', store);
    assert.equal(loadQuality(store), 'high');
    assert.equal(loadQuality(memoryStorage({ 'wf.quality': 'ультра' })), 'medium');
});

test('getPreset с неверным ключом даёт среднее', () => {
    assert.equal(getPreset('xxx'), QUALITY_PRESETS.medium);
    assert.equal(isQuality('low'), true);
    assert.equal(isQuality('toString'), false);
});

test('lowerQuality идёт вниз и упирается в low', () => {
    assert.equal(lowerQuality('high'), 'medium');
    assert.equal(lowerQuality('medium'), 'low');
    assert.equal(lowerQuality('low'), null);
});

test('подсказка снизить качество: порог 24 FPS и есть куда снижать', () => {
    assert.equal(shouldSuggestLower(20, 'high'), true);
    assert.equal(shouldSuggestLower(23.9, 'medium'), true);
    assert.equal(shouldSuggestLower(24, 'medium'), false);
    assert.equal(shouldSuggestLower(10, 'low'), false);
    assert.equal(shouldSuggestLower(NaN, 'high'), false);
    assert.equal(shouldSuggestLower(0, 'high'), false);
});

test('контроллер уведомляет подписчиков только при смене', () => {
    globalThis.localStorage = memoryStorage();
    const ctl = createQualityController('medium');
    const seen = [];
    ctl.subscribe((next, prev) => seen.push([prev, next]));
    assert.equal(ctl.set('medium'), false);
    assert.equal(ctl.set('bad'), false);
    assert.equal(ctl.set('high'), true);
    assert.deepEqual(seen, [['medium', 'high']]);
    assert.equal(globalThis.localStorage.getItem('wf.quality'), 'high');
    delete globalThis.localStorage;
});
