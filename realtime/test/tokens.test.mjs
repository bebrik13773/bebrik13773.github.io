import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { WebSocket } from 'ws';
import { createRealtime } from '../server.js';
import * as proto from '../lib/proto.js';
import {
  edKeyPair, signLogin, signReceipt, signPayloadJson, verifyToken, parseKeyring, signRaw, verifyRaw, newRid, ridValid,
} from '../lib/tokens.js';

const VECTORS = new URL('../../Код/games/wild-forest/tests/vectors/rt.json', import.meta.url);
const vec = JSON.parse(fs.readFileSync(VECTORS, 'utf-8'));
const keyring = (obj) => new Map(Object.entries(obj).map(([k, v]) => [k, Buffer.from(proto.b64uDec(v))]));

test('золотые векторы: Node проверяет все токены как ожидалось', () => {
  assert.ok(vec.verify.length >= 50);
  for (const c of vec.verify) {
    const r = verifyToken(c.token, { alg: c.alg, keys: keyring(c.keys), typ: c.typ, now: c.now });
    assert.equal(r.ok, c.expect.ok, c.name);
    if (c.expect.ok) assert.equal(r.payload.pid, c.expect.pid, c.name);
    else assert.equal(r.error, c.expect.error, c.name);
  }
});

test('золотые векторы: подпись воспроизводится побайтно (подпись детерминирована)', () => {
  for (const c of vec.sign) {
    assert.equal(signPayloadJson({ alg: c.alg, key: Buffer.from(proto.b64uDec(c.key)) }, c.payload_json), c.token, c.alg);
  }
});

test('лимиты протокола совпадают с векторами (их же сверяет PHP-конфиг)', () => {
  const L = proto.LIMITS;
  const want = {
    token_max_len: L.tokenMaxLen, token_ttl_s: L.ttlS, token_refresh_s: L.refreshS, max_age_s: L.maxAgeS, skew_s: L.skewS,
    evt_ttl_s: L.evtTtlS, receipts_per_sync: L.receiptsPerSync, receipt_data_bytes: L.receiptDataBytes,
    cp_interval_s: L.cpIntervalS, keep_receipts_s: L.keepReceiptsS,
  };
  assert.deepEqual(vec.limits, want);
});

test('подпись и проверка: круг для ed25519 и hmac, срок, повтор не ломает проверку', () => {
  const ed = edKeyPair();
  const hm = Buffer.alloc(32, 7);
  for (const [alg, sk, vk] of [['ed25519', ed.secret, ed.public], ['hmac', hm, hm]]) {
    const now = 1_800_000_000;
    const t = signReceipt({ alg, kid: 'r1', key: sk }, { pid: 5, k: 'cp', d: { x: 1, z: 2 } }, { now });
    const keys = new Map([['r1', vk]]);
    assert.equal(verifyToken(t, { alg, keys, typ: 'rcp', now }).ok, true);
    assert.equal(verifyToken(t, { alg, keys, typ: 'rcp', now: now + 599 }).ok, true);
    assert.equal(verifyToken(t, { alg, keys, typ: 'rcp', now: now + 601 }).error, 'expired');
    assert.equal(verifyToken(t, { alg, keys, typ: 'rcp', now: now - 61 }).error, 'bad_token');
    assert.equal(verifyToken(t, { alg, keys, typ: 'tok', now }).error, 'bad_token');
  }
});

test('основные поля токена нельзя подменить через extra', () => {
  const ed = edKeyPair();
  const t = signLogin({ alg: 'ed25519', kid: 'p1', key: ed.secret }, 9, { pid: 1, exp: 1, typ: 'rcp', v: 5, nm: 'x' }, { now: 1_800_000_000 });
  const r = verifyToken(t, { alg: 'ed25519', keys: new Map([['p1', ed.public]]), typ: 'tok', now: 1_800_000_000 });
  assert.equal(r.ok, true);
  assert.equal(r.payload.pid, 9);
  assert.equal(r.payload.typ, 'tok');
});

test('ключи и подписи: слишком короткие ключи и неверные длины отклоняются', () => {
  assert.throws(() => signRaw('hmac', Buffer.alloc(8), 'm'));
  assert.equal(verifyRaw('hmac', Buffer.alloc(8), 'm', Buffer.alloc(32)), false);
  assert.equal(verifyRaw('ed25519', Buffer.alloc(31), 'm', Buffer.alloc(64)), false);
  assert.equal(verifyRaw('nope', Buffer.alloc(32), 'm', Buffer.alloc(32)), false);
  const ring = parseKeyring('p1:' + proto.b64uEnc(Buffer.alloc(32, 1)) + ', r1:не-ключ!, ,:x,плохой:AAAA,p2:' + proto.b64uEnc(Buffer.alloc(32, 2)));
  assert.deepEqual([...ring.keys()], ['p1', 'p2']);
});

test('rid: формат и уникальность', () => {
  const a = newRid();
  assert.ok(ridValid(a));
  assert.notEqual(a, newRid());
  assert.equal(ridValid('короткий'), false);
  assert.equal(ridValid('a'.repeat(65)), false);
});

test('proto: base64url, tokenInfo, управляющие сообщения, бинарный кадр', () => {
  const s = 'Тихая Заводь ✓/+?';
  assert.equal(new TextDecoder().decode(proto.b64uDec(proto.b64uEnc(s))), s);
  assert.equal(proto.b64uDec('a=b'), null);
  assert.equal(proto.b64uDec('a'), null);
  assert.equal(proto.b64uDec(''), null);
  const ed = edKeyPair();
  const t = signLogin({ alg: 'ed25519', kid: 'p1', key: ed.secret }, 3, {}, { now: 1_800_000_000 });
  assert.deepEqual(proto.tokenInfo(t), { typ: 'tok', pid: 3, iat: 1_800_000_000, exp: 1_800_000_600, kid: 'p1' });
  assert.equal(proto.tokenInfo('x'), null);
  assert.equal(proto.tokenInfo('a.b.c'), null);
  assert.deepEqual(proto.decodeCtl(proto.encodeCtl({ t: 'ping', c: 1 })), { t: 'ping', c: 1 });
  assert.equal(proto.decodeCtl('[1]'), null);
  assert.equal(proto.decodeCtl('{"x":1}'), null);
  assert.equal(proto.decodeCtl('x'.repeat(5000)), null);
});

// ── сервер: вход по токену ───────────────────────────────────────────────
function open(port) {
  const ws = new WebSocket(`ws://127.0.0.1:${port}`, { origin: 'http://localhost' });
  const msgs = [];
  const waiters = [];
  ws.on('message', (d) => {
    const m = JSON.parse(d.toString());
    const w = waiters.shift();
    if (w) w(m); else msgs.push(m);
  });
  const next = () => new Promise((res) => { const m = msgs.shift(); if (m) res(m); else waiters.push(res); });
  const closed = new Promise((res) => ws.once('close', (code) => res(code)));
  return { ws, next, closed, opened: new Promise((res, rej) => { ws.once('open', res); ws.once('error', rej); }) };
}

test('сервер: вход по токену, замена второго соединения, плохие токены, таймаут', async () => {
  const phpKeys = edKeyPair();
  const rtKeys = edKeyPair();
  let now = 1_800_000_000;
  const rt = createRealtime({
    port: 0, host: '127.0.0.1', origins: ['http://localhost'], authTimeoutMs: 300, now: () => now,
    auth: { alg: 'ed25519', verifyKeys: new Map([['p1', phpKeys.public]]), signer: { alg: 'ed25519', kid: 'r1', key: rtKeys.secret } },
  });
  const port = await rt.listen();
  const php = { alg: 'ed25519', kid: 'p1', key: phpKeys.secret };
  try {
    const a = open(port);
    await a.opened;
    const hello = await a.next();
    assert.equal(hello.t, 'hello');
    assert.equal(hello.auth, true);
    assert.equal(hello.proto, 1);

    a.ws.send(JSON.stringify({ t: 'auth', tok: signLogin(php, 11, { nm: 'Тихая Заводь', vmax: 4.6 }, { now }) }));
    const ok = await a.next();
    assert.equal(ok.t, 'auth_ok');
    assert.equal(ok.pid, 11);
    assert.equal(rt.byPid.get(11).profile.nm, 'Тихая Заводь');

    // ping работает и после входа
    a.ws.send(JSON.stringify({ t: 'ping', c: 5 }));
    assert.equal((await a.next()).c, 5);

    // второе соединение того же игрока вытесняет первое
    const b = open(port);
    await b.opened;
    await b.next();
    b.ws.send(JSON.stringify({ t: 'auth', tok: signLogin(php, 11, {}, { now }) }));
    assert.equal((await b.next()).t, 'auth_ok');
    assert.equal(await a.closed, 4002);
    assert.equal(rt.byPid.get(11), rt.byPid.get(11));

    // продление токена тем же игроком
    b.ws.send(JSON.stringify({ t: 'auth', tok: signLogin(php, 11, {}, { now: now + 30 }) }));
    assert.equal((await b.next()).t, 'auth_ok');
    // продление чужим токеном отклоняется
    b.ws.send(JSON.stringify({ t: 'auth', tok: signLogin(php, 12, {}, { now: now + 30 }) }));
    assert.equal((await b.next()).code, 'bad_token');
    b.ws.close();

    // плохой токен: ошибка и закрытие
    const c = open(port);
    await c.opened;
    await c.next();
    c.ws.send(JSON.stringify({ t: 'auth', tok: signLogin({ ...php, key: edKeyPair().secret }, 13, {}, { now }) }));
    assert.equal((await c.next()).code, 'bad_token');
    assert.equal(await c.closed, 4001);

    // просроченный токен
    const d = open(port);
    await d.opened;
    await d.next();
    d.ws.send(JSON.stringify({ t: 'auth', tok: signLogin(php, 14, {}, { now: now - 1000 }) }));
    assert.equal((await d.next()).code, 'expired');
    assert.equal(await d.closed, 4001);

    // токен квитанции вместо токена входа
    const e = open(port);
    await e.opened;
    await e.next();
    e.ws.send(JSON.stringify({ t: 'auth', tok: signReceipt(php, { pid: 15, k: 'cp', d: {} }, { now }) }));
    assert.equal((await e.next()).code, 'bad_token');
    assert.equal(await e.closed, 4001);

    // молчание: закрывается по таймауту
    const f = open(port);
    await f.opened;
    await f.next();
    assert.equal(await f.closed, 4001);

    // квитанция сервера подписана его ключом и проходит проверку у PHP-стороны
    const rcp = rt.receipt({ pid: 11, k: 'cp', d: { x: 1, z: 2 } });
    assert.equal(verifyToken(rcp, { alg: 'ed25519', keys: new Map([['r1', rtKeys.public]]), typ: 'rcp', now }).ok, true);
    const health = await (await fetch(`http://127.0.0.1:${port}/health`)).json();
    assert.equal(health.auth.configured, true);
    assert.deepEqual(health.auth.verify_kids, ['p1']);
    assert.equal(health.auth.sign_kid, 'r1');
    assert.equal(JSON.stringify(health).includes(proto.b64uEnc(rtKeys.secret)), false);
  } finally {
    await rt.close();
  }
});

test('сервер без ключей: вход невозможен, hello сообщает auth=false', async () => {
  const rt = createRealtime({ port: 0, host: '127.0.0.1', origins: ['http://localhost'], auth: { alg: 'ed25519', verifyKeys: new Map(), signer: null } });
  const port = await rt.listen();
  try {
    const a = open(port);
    await a.opened;
    assert.equal((await a.next()).auth, false);
    a.ws.send(JSON.stringify({ t: 'auth', tok: 'x.y' }));
    assert.equal((await a.next()).code, 'bad_token');
    assert.equal(await a.closed, 4001);
    assert.throws(() => rt.receipt({ pid: 1, k: 'cp', d: {} }));
  } finally {
    await rt.close();
  }
});
