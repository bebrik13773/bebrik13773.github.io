// Золотые векторы подписей РТ-02: Node подписывает, PHP (tools/forest-selftest.php, раздел «Realtime») проверяет и воспроизводит.
// Запуск: node Код/games/wild-forest/tests/gen-rt-vectors.mjs
// Ключи в векторах ТЕСТОВЫЕ (выведены из строк), к настоящим ключам игры отношения не имеют.
import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { edKeyPair, signPayloadJson } from '../../../../realtime/lib/tokens.js';
import { LIMITS, b64uEnc } from '../../../../realtime/lib/proto.js';

const here = path.dirname(fileURLToPath(import.meta.url));
const sha = (s) => crypto.createHash('sha256').update(s).digest();
const NOW = 1760000000;

const php = edKeyPair(sha('wf-rt-test-php'));   // подписывает PHP, проверяет РТ
const rt = edKeyPair(sha('wf-rt-test-rt'));     // подписывает РТ, проверяет PHP
const rt2 = edKeyPair(sha('wf-rt-test-rt-2'));  // второй ключ РТ (ротация)
const hmacA = sha('wf-rt-test-hmac-a');         // токены PHP → РТ
const hmacB = sha('wf-rt-test-hmac-b');         // квитанции РТ → PHP
const hmacB2 = sha('wf-rt-test-hmac-b2');

const sets = {
  ed25519: { sign: rt.secret, verify: rt.public, signPhp: php.secret, verifyPhp: php.public, sign2: rt2.secret, verify2: rt2.public },
  hmac: { sign: hmacB, verify: hmacB, signPhp: hmacA, verifyPhp: hmacA, sign2: hmacB2, verify2: hmacB2 },
};

const sign = (alg, key, payload) => signPayloadJson({ alg, key }, typeof payload === 'string' ? payload : JSON.stringify(payload));
const base = (over = {}) => ({ v: 1, kid: 'r1', typ: 'rcp', pid: 42, iat: NOW - 5, exp: NOW + 595, rid: 'AbCdEfGh12345678', k: 'cp', d: { x: 1204.5, z: -88.3, ry: 1.57, t: NOW * 1000 }, ...over });

const verify = [];
const signCases = [];
const add = (alg, name, token, keys, typ, expect, now = NOW) => verify.push({ name: `${alg}: ${name}`, alg, keys, typ, token, now, expect });
const flip = (tok) => {
  const [a, b] = tok.split('.');
  return a + '.' + (b[0] === 'A' ? 'B' : 'A') + b.slice(1);
};

for (const alg of ['ed25519', 'hmac']) {
  const k = sets[alg];
  const ring = { r1: b64uEnc(k.verify) };
  const okTok = sign(alg, k.sign, base());
  add(alg, 'квитанция cp', okTok, ring, 'rcp', { ok: true, pid: 42 });
  add(alg, 'квитанция trade', sign(alg, k.sign, base({ rid: 'trade-0001-xyzxyz', k: 'trade', d: { tid: 't9', st: 'done' } })), ring, 'rcp', { ok: true, pid: 42 });
  add(alg, 'тип не проверяется (null)', okTok, ring, null, { ok: true, pid: 42 });
  add(alg, 'токен входа tok (ключи PHP)', sign(alg, k.signPhp, base({ kid: 'p1', typ: 'tok', rid: undefined, k: undefined, d: undefined, nm: 'Тихая Заводь', vn: 'Мшистый Бор', lv: 3, sk: 'default', vmax: 4.6, mu: 0, fl: 0 })), { p1: b64uEnc(k.verifyPhp) }, 'tok', { ok: true, pid: 42 });

  const [pPart, sPart] = okTok.split('.');
  const forged = Buffer.from(pPart.length ? JSON.stringify(base({ pid: 7 })) : '').toString('base64url') + '.' + sPart;
  add(alg, 'подмена pid без подписи', forged, ring, 'rcp', { ok: false, error: 'bad_token' });
  add(alg, 'испорченная подпись', flip(okTok), ring, 'rcp', { ok: false, error: 'bad_token' });
  add(alg, 'неизвестный kid', sign(alg, k.sign, base({ kid: 'zz' })), ring, 'rcp', { ok: false, error: 'bad_token' });
  add(alg, 'чужой ключ при том же kid', sign(alg, k.sign2, base()), ring, 'rcp', { ok: false, error: 'bad_token' });
  add(alg, 'вышел срок (exp в прошлом)', sign(alg, k.sign, base({ iat: NOW - 700, exp: NOW - 100 })), ring, 'rcp', { ok: false, error: 'expired' });
  add(alg, 'старше 10 минут при живом exp', sign(alg, k.sign, base({ iat: NOW - 601, exp: NOW - 1 })), ring, 'rcp', { ok: false, error: 'expired' });
  add(alg, 'ровно 600 секунд назад: ещё можно', sign(alg, k.sign, base({ iat: NOW - 600, exp: NOW })), ring, 'rcp', { ok: true, pid: 42 });
  add(alg, 'из будущего на 120 с', sign(alg, k.sign, base({ iat: NOW + 120, exp: NOW + 720 })), ring, 'rcp', { ok: false, error: 'bad_token' });
  add(alg, 'из будущего на 30 с (в допуске)', sign(alg, k.sign, base({ iat: NOW + 30, exp: NOW + 630 })), ring, 'rcp', { ok: true, pid: 42 });
  add(alg, 'срок жизни длиннее допустимого', sign(alg, k.sign, base({ iat: NOW, exp: NOW + 601 })), ring, 'rcp', { ok: false, error: 'bad_token' });
  add(alg, 'не тот тип (tok как rcp)', sign(alg, k.sign, base({ typ: 'tok' })), ring, 'rcp', { ok: false, error: 'bad_token' });
  add(alg, 'версия протокола новее', sign(alg, k.sign, base({ v: 2 })), ring, 'rcp', { ok: false, error: 'outdated' });
  add(alg, 'версия 0', sign(alg, k.sign, base({ v: 0 })), ring, 'rcp', { ok: false, error: 'bad_token' });
  add(alg, 'pid не число', sign(alg, k.sign, base({ pid: '42' })), ring, 'rcp', { ok: false, error: 'bad_token' });
  add(alg, 'ротация: старый ключ r1', okTok, { r1: b64uEnc(k.verify), r2: b64uEnc(k.verify2) }, 'rcp', { ok: true, pid: 42 });
  add(alg, 'ротация: новый ключ r2', sign(alg, k.sign2, base({ kid: 'r2' })), { r1: b64uEnc(k.verify), r2: b64uEnc(k.verify2) }, 'rcp', { ok: true, pid: 42 });
  for (const [name, tok] of [['пусто', ''], ['без точки', 'abc'], ['три части', 'a.b.c'], ['только точки', '....'], ['не base64url', 'a+b/c=.d+e/f='], ['слишком длинный', 'a'.repeat(LIMITS.tokenMaxLen + 1)]]) {
    add(alg, 'мусор: ' + name, tok, ring, 'rcp', { ok: false, error: 'bad_token' });
  }
  signCases.push({
    alg,
    key: b64uEnc(k.signPhp),
    payload_json: JSON.stringify({ v: 1, kid: 'p1', typ: 'tok', pid: 7, iat: NOW, exp: NOW + 600, nm: 'Тихая Заводь', vn: 'а/б "в"', lv: 3, vmax: 4.6 }),
    token: sign(alg, k.signPhp, JSON.stringify({ v: 1, kid: 'p1', typ: 'tok', pid: 7, iat: NOW, exp: NOW + 600, nm: 'Тихая Заводь', vn: 'а/б "в"', lv: 3, vmax: 4.6 })),
  });
}

// Подмена алгоритма: токен ed25519 против проверки hmac и наоборот
const edTok = sign('ed25519', sets.ed25519.sign, base());
const hmTok = sign('hmac', sets.hmac.sign, base());
verify.push({ name: 'смена алгоритма: ed25519-токен при hmac-проверке', alg: 'hmac', keys: { r1: b64uEnc(sets.ed25519.verify) }, typ: 'rcp', token: edTok, now: NOW, expect: { ok: false, error: 'bad_token' } });
verify.push({ name: 'смена алгоритма: hmac-токен при ed25519-проверке', alg: 'ed25519', keys: { r1: b64uEnc(sets.hmac.verify) }, typ: 'rcp', token: hmTok, now: NOW, expect: { ok: false, error: 'bad_token' } });

const out = {
  version: 1,
  now: NOW,
  limits: {
    token_max_len: LIMITS.tokenMaxLen, token_ttl_s: LIMITS.ttlS, token_refresh_s: LIMITS.refreshS, max_age_s: LIMITS.maxAgeS,
    skew_s: LIMITS.skewS, evt_ttl_s: LIMITS.evtTtlS, receipts_per_sync: LIMITS.receiptsPerSync,
    receipt_data_bytes: LIMITS.receiptDataBytes, cp_interval_s: LIMITS.cpIntervalS, keep_receipts_s: LIMITS.keepReceiptsS,
  },
  keys: {
    ed25519: { php_secret: b64uEnc(php.secret), php_public: b64uEnc(php.public), rt_secret: b64uEnc(rt.secret), rt_public: b64uEnc(rt.public) },
    hmac: { key_a: b64uEnc(hmacA), key_b: b64uEnc(hmacB) },
  },
  sign: signCases,
  verify,
  encode: [{ array: { v: 1, nm: 'Тихая Заводь', vn: 'а/б', lv: 3, vmax: 4.6 }, json: '{"v":1,"nm":"Тихая Заводь","vn":"а/б","lv":3,"vmax":4.6}' }],
};
fs.writeFileSync(path.join(here, 'vectors', 'rt.json'), JSON.stringify(out, null, 1) + '\n');
console.log(`rt.json: ${verify.length} проверок, ${signCases.length} подписей`);
