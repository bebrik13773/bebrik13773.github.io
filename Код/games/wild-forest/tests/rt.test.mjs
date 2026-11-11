// РТ-02: копия протокола для клиента, лимиты PHP-конфига и золотые векторы не должны расходиться с realtime/.
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { LIMITS, TYP, ERR, tokenInfo } from '../src/shared/rt-proto.js';

const read = (rel) => fs.readFileSync(new URL(rel, import.meta.url), 'utf-8');

test('клиентский rt-proto.js — побайтная копия realtime/lib/proto.js', () => {
  const a = read('../src/shared/rt-proto.js');
  const b = read('../../../../realtime/lib/proto.js');
  assert.equal(a, b, 'скопируй: cp realtime/lib/proto.js Код/games/wild-forest/src/shared/rt-proto.js');
});

test('группа rt в config.php совпадает с LIMITS протокола', () => {
  const cfg = read('../../../api/forest/config.php');
  const block = cfg.match(/'rt' => \[([\s\S]*?)\n    \],/);
  assert.ok(block, 'нет группы rt в config.php');
  const num = (k) => Number(block[1].match(new RegExp(`'${k}'\\s*=>\\s*(\\d+)`))?.[1]);
  const want = {
    token_max_len: LIMITS.tokenMaxLen, token_ttl_s: LIMITS.ttlS, token_refresh_s: LIMITS.refreshS, max_age_s: LIMITS.maxAgeS,
    skew_s: LIMITS.skewS, evt_ttl_s: LIMITS.evtTtlS, receipts_per_sync: LIMITS.receiptsPerSync,
    receipt_data_bytes: LIMITS.receiptDataBytes, cp_interval_s: LIMITS.cpIntervalS, keep_receipts_s: LIMITS.keepReceiptsS,
  };
  for (const [k, v] of Object.entries(want)) assert.equal(num(k), v, k);
});

test('схема v6: миграция и карта таблиц на месте', () => {
  const db = read('../../../api/bootstrap/forest_db.php');
  assert.match(db, /FOREST_SCHEMA_VERSION = 6;/);
  assert.match(db, /6 => 'forest_migration_6'/);
  assert.match(db, /'forest_rt_receipts' => 6/);
  assert.match(db, /ascii_bin/); // rid чувствителен к регистру
});

test('клиент читает срок токена без проверки подписи (rt_exp для sync)', () => {
  const vec = JSON.parse(read('./vectors/rt.json'));
  const c = vec.verify.find((x) => x.name === 'ed25519: квитанция cp');
  const info = tokenInfo(c.token);
  assert.equal(info.typ, TYP.receipt);
  assert.equal(info.pid, 42);
  assert.ok(info.exp > info.iat);
  assert.equal(ERR.expired, 'expired');
});
