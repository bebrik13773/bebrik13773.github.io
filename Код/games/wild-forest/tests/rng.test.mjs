// Запуск: node --test Код/games/wild-forest/tests/
import test from 'node:test';
import assert from 'node:assert/strict';
import { mul32, mix, hash, noise2, fbm, floorDiv, isqrt } from '../src/shared/rng.js';

// Эталон на BigInt: полностью независимая реализация без побитовых трюков JS.
const M32 = 0xFFFFFFFFn;
const bmul = (a, b) => (BigInt(a) * BigInt(b)) & M32;
function bmix(h) {
    h = BigInt(h);
    h ^= h >> 16n; h = bmul(h, 0x85EBCA6B);
    h ^= h >> 13n; h = bmul(h, 0xC2B2AE35);
    h ^= h >> 16n;
    return h & M32;
}
function bhash(seed, x, z, salt) {
    const u = (v) => BigInt.asUintN(32, BigInt(v));
    let h = u(seed);
    h = bmix(h ^ bmul(u(x), 0x27D4EB2D));
    h = bmix(h ^ bmul(u(z), 0x165667B1));
    h = bmix(h ^ bmul(u(salt), 0x9E3779B1));
    return Number(h);
}

// Формула mul32, которую использует PHP (разбиение на 16 бит без выхода за 64 бита).
const phpMul32 = (a, b) => Number((BigInt(a & 0xFFFF) * BigInt(b) + ((((BigInt(a) >> 16n) * BigInt(b)) & 0xFFFFn) << 16n)) & M32);

test('mix: известные значения финализатора murmur3', () => {
    assert.equal(mix(0), 0);
    assert.equal(mix(1), 0x514E28B7);
});

test('mul32 совпадает с BigInt и с формулой для PHP на крайних и случайных значениях', () => {
    const edge = [0, 1, 2, 0xFFFF, 0x10000, 0x7FFFFFFF, 0x80000000, 0xFFFFFFFF, 0x85EBCA6B, 0xC2B2AE35];
    for (const a of edge) for (const b of edge) {
        assert.equal(mul32(a, b), Number(bmul(a, b)), `${a}*${b}`);
        assert.equal(phpMul32(a, b), Number(bmul(a, b)), `php ${a}*${b}`);
    }
    let s = 12345;
    for (let i = 0; i < 20000; i++) {
        s = mix(s + i);
        const a = s, b = mix(s ^ 0xABCDEF);
        assert.equal(mul32(a, b), Number(bmul(a, b)));
        assert.equal(phpMul32(a, b), Number(bmul(a, b)));
    }
});

test('hash совпадает с эталоном BigInt, включая отрицательные координаты и большие сиды', () => {
    const seeds = [0, 1, 1337, 2147483648, 4000000007, 4294967295];
    let n = 0;
    for (const seed of seeds) for (let i = -30; i <= 30; i++) {
        const x = i * 7919, z = -i * 104729 + 3, salt = (i + 30) % 13;
        assert.equal(hash(seed, x, z, salt), bhash(seed, x, z, salt));
        n++;
    }
    for (const [x, z] of [[-1, -1], [1000000, -1000000], [-1100000, 1100000], [0, 0]]) {
        assert.equal(hash(1337, x, z, 5), bhash(1337, x, z, 5));
    }
    assert.ok(n > 300);
});

test('hash: результат всегда беззнаковое 32-битное число и разные входы дают разные значения', () => {
    const seen = new Set();
    for (let i = 0; i < 5000; i++) {
        const h = hash(1337, i, -i, 1);
        assert.ok(Number.isInteger(h) && h >= 0 && h <= 0xFFFFFFFF);
        seen.add(h);
    }
    assert.ok(seen.size > 4990, 'почти нет коллизий');
});

test('floorDiv и isqrt', () => {
    assert.equal(floorDiv(-1, 6000), -1);
    assert.equal(floorDiv(-6000, 6000), -1);
    assert.equal(floorDiv(-6001, 6000), -2);
    assert.equal(floorDiv(5999, 6000), 0);
    assert.equal(isqrt(0), 0);
    assert.equal(isqrt(15), 3);
    assert.equal(isqrt(16), 4);
    assert.equal(isqrt(1440000), 1200);
    assert.equal(isqrt(1439999), 1199);
    assert.equal(isqrt(2 * 1000000 * 1000000), 1414213);
});

test('noise2 и fbm: диапазон 0..65535, детерминизм и непрерывность на швах решётки', () => {
    for (let i = -50; i < 50; i++) {
        const X = i * 997, Z = i * -1103;
        const n = noise2(1337, X, Z, 6000, 1), f = fbm(1337, X, Z, 6000, 1);
        assert.ok(n >= 0 && n <= 65535 && f >= 0 && f <= 65535);
        assert.equal(n, noise2(1337, X, Z, 6000, 1));
    }
    // на узлах решётки значение равно значению угла, слева и справа от шва разница мала
    for (const gx of [-3, -1, 0, 1, 7]) {
        const X = gx * 6000;
        const left = noise2(1337, X - 1, 12345, 6000, 1), right = noise2(1337, X, 12345, 6000, 1);
        assert.ok(Math.abs(left - right) < 100, `шов gx=${gx}: ${left} vs ${right}`);
    }
});

test('fbm: среднее около середины диапазона', () => {
    let sum = 0;
    const N = 4000;
    for (let i = 0; i < N; i++) sum += fbm(1337, i * 1013 - 2000000, i * -7001 + 500000, 6000, 1);
    const mean = sum / N;
    assert.ok(mean > 26000 && mean < 40000, 'среднее ' + mean);
});
