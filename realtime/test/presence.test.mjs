import test from 'node:test';
import assert from 'node:assert/strict';
import { Grid } from '../lib/grid.js';
import { encodeFrame, decodeFrame, encodePos, decodePos, angleToByte, byteToAngle, FRAME_MAX_BYTES } from '../lib/snapshot.js';
import { Bucket, IpCounter, clientIp, isPrivate, makeConnLimits } from '../lib/limits.js';
import { Hub, moveAllowance, MOVE } from '../lib/hub.js';
import { HERO, moveAllowance as clientAllowance } from '../../Код/games/wild-forest/src/shared/formulas.js';
import { STATE_NAMES } from '../../Код/games/wild-forest/src/entities/beaver-anim.js';
import { ANIM } from '../lib/proto.js';

test('Grid: ячейки, соседи, ближайшие, отрицательные координаты', () => {
  const g = new Grid(128);
  g.set(1, 0, 0);
  g.set(2, 500, 0); // 50 м
  g.set(3, 1279, 0); // следующая ячейка по x (127.9 м)
  g.set(4, 5000, 5000); // далеко
  g.set(5, -10, -10); // ячейка (-1,-1), рядом с 1
  assert.deepEqual(g.near(1, 20), [5, 2, 3]);
  assert.deepEqual(g.near(1, 2), [5, 2]);
  assert.deepEqual(g.near(4, 20), []);
  assert.equal(g.set(2, 510, 0), false, 'в той же ячейке');
  assert.equal(g.set(2, 3000, 0), true, 'ячейка сменилась');
  g.remove(2);
  assert.ok(!g.near(1).includes(2));
  assert.equal(g.size, 4);
  assert.deepEqual(g.near(99), []);
});

test('Grid: 3×3 ячейки, дальше 2 ячеек не видно', () => {
  const g = new Grid(128);
  g.set(1, 0, 0);
  g.set(2, 2560, 0); // 2 ячейки правее: вне 3×3
  g.set(3, 1280, 1280); // диагональ: в 3×3
  assert.deepEqual(g.near(1), [3]);
});

test('кодек позиции клиента: круг и мусор', () => {
  const b = encodePos({ x: 1204.55, z: -88.3, ry: 1.57, anim: 3 });
  assert.equal(b.length, 11);
  assert.deepEqual(decodePos(b), { X: 12046, Z: -883, A: angleToByte(1.57), N: 3 });
  assert.equal(decodePos(b.subarray(0, 10)), null);
  assert.equal(decodePos(new Uint8Array(11)), null);
  assert.ok(Math.abs(byteToAngle(angleToByte(5)) - 5) < 0.03);
  assert.equal(angleToByte(-0.0001), 0 | angleToByte(2 * Math.PI - 0.0001));
});

test('кодек кадра: дельты, полный кадр, отсутствие изменений, неизвестный id', () => {
  const server = new Map([[1, { X: 100, Z: 100, A: 5, N: 0 }]]);
  const client = new Map([[1, { X: 100, Z: 100, A: 5, N: 0 }]]);
  // ничего не изменилось → null
  assert.equal(encodeFrame({ tick: 1, full: false }, [{ id: 1, X: 100, Z: 100, A: 5, N: 0 }], server), null);
  // сдвиг: дельта 3 + 3 + 5 байт = 11 вместо 15
  const f = encodeFrame({ tick: 2, full: false }, [{ id: 1, X: 110, Z: 95, A: 5, N: 0 }], server);
  assert.equal(f.length, 5 + 3 + 4);
  assert.deepEqual(decodeFrame(f, client).items, [{ id: 1, X: 110, Z: 95, A: 5, N: 0 }]);
  // сменился только угол и анимация
  const f2 = encodeFrame({ tick: 3, full: false }, [{ id: 1, X: 110, Z: 95, A: 9, N: 2 }], server);
  assert.equal(f2.length, 5 + 3 + 2);
  assert.deepEqual(decodeFrame(f2, client).items[0], { id: 1, X: 110, Z: 95, A: 9, N: 2 });
  // большой прыжок: абсолютная позиция
  const f3 = encodeFrame({ tick: 4, full: false }, [{ id: 1, X: 90000, Z: -90000, A: 9, N: 2 }], server);
  assert.equal(f3.length, 5 + 3 + 8);
  assert.equal(decodeFrame(f3, client).items[0].X, 90000);
  // полный кадр: всё абсолютно, даже без изменений
  const f4 = encodeFrame({ tick: 5, full: true }, [{ id: 1, X: 90000, Z: -90000, A: 9, N: 2 }], server);
  assert.equal(f4.length, 5 + 3 + 10);
  const back = decodeFrame(f4, client);
  assert.equal(back.full, true);
  assert.equal(back.tick, 5);
  // дельта для неизвестного клиенту id пропускается, полный кадр чинит
  const lonely = new Map();
  assert.deepEqual(decodeFrame(f, lonely).items, []);
  assert.deepEqual(decodeFrame(f4, lonely).items.length, 1);
  // битые данные
  assert.equal(decodeFrame(f.subarray(0, 7), new Map(client)), null);
  assert.equal(decodeFrame(new Uint8Array(3), client), null);
  assert.equal(decodeFrame(new Uint8Array([9, 0, 0, 0, 0]), client), null);
});

test('кадр с 20 соседями в полном виде ≤ 1 КБ, лишнее отклоняется', () => {
  const items = Array.from({ length: 20 }, (_, i) => ({ id: i + 1, X: i * 1000, Z: -i * 1000, A: i, N: i % 8 }));
  const f = encodeFrame({ tick: 1, full: true }, items, new Map());
  assert.ok(f.length <= FRAME_MAX_BYTES);
  assert.equal(f.length, 5 + 20 * 13);
  assert.throws(() => encodeFrame({ tick: 1, full: true }, Array.from({ length: 41 }, (_, i) => ({ id: i + 1, X: 0, Z: 0, A: 0, N: 0 })), new Map()));
});

test('Bucket, IpCounter, IP за прокси', () => {
  const b = new Bucket(2, 3, 0);
  assert.ok(b.take(0) && b.take(0) && b.take(0));
  assert.equal(b.take(0), false);
  assert.equal(b.take(500), true, 'за 0.5 с набежал 1 токен');
  assert.equal(b.take(500), false);
  const c = new IpCounter(2);
  assert.ok(c.acquire('a') && c.acquire('a'));
  assert.equal(c.acquire('a'), false);
  c.release('a');
  assert.ok(c.acquire('a'));
  assert.ok(c.acquire('b'));
  assert.equal(isPrivate('172.18.0.3'), true);
  assert.equal(isPrivate('8.8.8.8'), false);
  assert.equal(clientIp({ socket: { remoteAddress: '::ffff:172.18.0.3' }, headers: { 'x-forwarded-for': '1.1.1.1, 5.5.5.5' } }), '5.5.5.5');
  assert.equal(clientIp({ socket: { remoteAddress: '8.8.8.8' }, headers: { 'x-forwarded-for': '5.5.5.5' } }), '8.8.8.8', 'чужому заголовку не верим');
  assert.ok(makeConnLimits(0).pos);
});

test('формулы РТ совпадают с клиентскими; анимации совпадают с бобром', () => {
  assert.equal(MOVE.slack, HERO.moveSlack);
  assert.equal(MOVE.constM, HERO.moveConstM);
  for (const [v, dt] of [[3.2, 0], [4.6, 0.2], [4.6, 5], [1, 100]]) assert.equal(moveAllowance(v, dt), clientAllowance(v, dt));
  assert.deepEqual([...ANIM], [...STATE_NAMES]);
});

function fakeConn() {
  const c = { json: [], bin: [], closed: null, buf: 0 };
  c.sendJson = (m) => c.json.push(m);
  c.sendBin = (b) => c.bin.push(b);
  c.buffered = () => c.buf;
  c.close = (code, why) => { c.closed = [code, why]; };
  return c;
}
const pos = (x, z, A = 0, N = 0) => ({ X: Math.round(x * 10), Z: Math.round(z * 10), A, N });

test('Hub: скорость — честный ход проходит, телепорт откатывается, страйки блокируют', () => {
  const h = new Hub();
  const c = fakeConn();
  const p = h.join(c, 7, { nm: 'Тест', vmax: 4 }, 0);
  assert.equal(h.onPos(p, pos(0, 0), 0), 'ok');
  // 5 Гц, 3 м/с: идём 20 секунд
  let t = 0;
  for (let i = 1; i <= 100; i++) { t = i * 200; assert.equal(h.onPos(p, pos(0.6 * i, 0), t), 'ok', 'шаг ' + i); }
  // «автоход» 7.5 м/с (в 2.3 раза быстрее): упираемся в якорь
  let corrected = 0;
  for (let i = 1; i <= 50; i++) { t += 200; if (h.onPos(p, pos(60 + 1.5 * i, 0), t) === 'corrected') corrected++; }
  assert.ok(corrected > 0, 'ускоренный ход отклонён');
  assert.ok(c.json.some((m) => m.t === 'correct'));
  // телепорт на 500 м
  const before = p.X;
  const r = h.onPos(p, pos(560, 0), t + 200);
  assert.ok(r === 'corrected' || r === 'blocked');
  assert.equal(p.X, before, 'позиция не принята');
  // дальше страйки доводят до блокировки
  let last = r;
  for (let i = 0; i < 10 && last !== 'blocked'; i++) last = h.onPos(p, pos(900, 0), t + 400 + i * 100);
  assert.equal(last, 'blocked');
  assert.deepEqual(c.closed, [4005, 'cheat']);
  assert.equal(h.isBlocked(7, t + 1000), true);
  assert.equal(h.isBlocked(7, t + 700000), false);
  assert.equal(h.onPos(h.join(fakeConn(), 8, {}, 0), { X: 99999999, Z: 0, A: 0, N: 0 }, 0), 'bad', 'за границей мира');
  assert.equal(h.onPos(h.join(fakeConn(), 9, {}, 0), { X: 0, Z: 0, A: 0, N: 99 }, 0), 'bad', 'нет такой анимации');
});

test('Hub: якорь не даёт копить запас +3 м с каждым сообщением', () => {
  const h = new Hub();
  const p = h.join(fakeConn(), 1, { vmax: 3 }, 0);
  h.onPos(p, pos(0, 0), 0);
  let rejected = 0;
  for (let i = 1; i <= 25; i++) if (h.onPos(p, pos(2.5 * i, 0), i * 200) !== 'ok') rejected++; // 12.5 м/с
  assert.ok(rejected >= 20, 'отклонено ' + rejected);
});

test('Hub: тик — enter, кадры, leave, полный кадр, 20 ближайших', () => {
  const h = new Hub({ fullEveryMs: 1000 });
  const ca = fakeConn();
  const cb = fakeConn();
  const a = h.join(ca, 1, { nm: 'A' }, 0);
  const b = h.join(cb, 2, { nm: 'B', lv: 3 }, 0);
  h.onPos(a, pos(0, 0), 0);
  h.onPos(b, pos(10, 0, 64, 1), 0);
  h.tick(200);
  assert.equal(ca.json.filter((m) => m.t === 'enter').length, 1);
  const en = ca.json.find((m) => m.t === 'enter');
  assert.deepEqual([en.id, en.pid, en.nm, en.lv, en.x, en.a, en.an], [b.id, 2, 'B', 3, 100, 64, 1]);
  assert.equal(ca.bin.length, 0, 'в тик появления кадра нет (всё в enter)');
  // клиент A строит свою картину
  const known = new Map([[b.id, { X: en.x, Z: en.z, A: en.a, N: en.an }]]);
  h.onPos(b, pos(10.6, 0, 64, 1), 400);
  h.tick(400);
  assert.equal(ca.bin.length, 1);
  assert.deepEqual(decodeFrame(ca.bin[0], known).items[0], { id: b.id, X: 106, Z: 0, A: 64, N: 1 });
  // без изменений кадров нет, а через fullEveryMs приходит полный
  h.tick(600);
  assert.equal(ca.bin.length, 1);
  h.tick(1500);
  assert.equal(ca.bin.length, 2);
  assert.equal(decodeFrame(ca.bin[1], known).full, true);
  // B ушёл
  h.leave(b);
  h.tick(1700);
  assert.deepEqual(ca.json.filter((m) => m.t === 'leave').map((m) => m.id), [b.id]);

  // толпа: 30 игроков в одной точке, каждый видит 20
  const crowd = [];
  for (let i = 0; i < 30; i++) { const p = h.join(fakeConn(), 100 + i, {}, 0); h.onPos(p, pos(i * 0.5, 0), 2000); crowd.push(p); }
  h.tick(2200);
  for (const p of crowd) assert.equal(p.known.size, 20);
  // медленному клиенту кадры пропускаются
  const slow = crowd[0].conn;
  slow.buf = 1e6;
  const n = slow.json.length;
  h.tick(2400);
  assert.equal(slow.json.length, n);
  assert.ok(h.stats().skipped >= 1);
});

test('Hub: эмодзи — набор, кулдаун 5 с, получают только соседи', () => {
  const h = new Hub();
  const ca = fakeConn(); const cb = fakeConn(); const cc = fakeConn();
  const a = h.join(ca, 1, {}, 0); const b = h.join(cb, 2, {}, 0); const c = h.join(cc, 3, {}, 0);
  h.onPos(a, pos(0, 0), 0); h.onPos(b, pos(5, 0), 0); h.onPos(c, pos(5000, 5000), 0);
  h.tick(200);
  assert.equal(h.onEmoji(a, 3, 1000), 'ok');
  assert.deepEqual(cb.json.filter((m) => m.t === 'emo'), [{ t: 'emo', id: a.id, e: 3 }]);
  assert.equal(cc.json.filter((m) => m.t === 'emo').length, 0);
  assert.equal(ca.json.filter((m) => m.t === 'emo').length, 0, 'себе не шлём');
  assert.equal(h.onEmoji(a, 3, 3000), 'rate');
  assert.equal(h.onEmoji(a, 4, 6001), 'ok');
  assert.equal(h.onEmoji(a, 0, 20000), 'bad');
  assert.equal(h.onEmoji(a, 7, 20000), 'bad');
  assert.equal(h.onEmoji(a, '1', 20000), 'bad');
});

test('Hub: чекпоинт раз в 10 с, только при живой позиции и ключе', () => {
  const sent = [];
  const h = new Hub({ receipt: (f) => { sent.push(f); return 'TOK' + sent.length; } });
  const c = fakeConn();
  const p = h.join(c, 5, {}, 0);
  h.tick(1000);
  assert.equal(sent.length, 0, 'позиции ещё нет');
  h.onPos(p, pos(12.34, -5.6), 9000);
  h.tick(10000);
  assert.equal(sent.length, 1);
  assert.deepEqual([sent[0].pid, sent[0].k, sent[0].d.x, sent[0].d.z], [5, 'cp', 12.3, -5.6]);
  assert.deepEqual(c.json.find((m) => m.t === 'rcp'), { t: 'rcp', tok: 'TOK1' });
  h.tick(15000);
  assert.equal(sent.length, 1);
  h.onPos(p, pos(12.5, -5.6), 19000);
  h.tick(20500);
  assert.equal(sent.length, 2);
  h.tick(50000); // позиция протухла (>30 с без сообщений)
  h.tick(70000);
  assert.equal(sent.length, 2);
  const h2 = new Hub();
  const p2 = h2.join(fakeConn(), 1, {}, 0);
  h2.onPos(p2, pos(0, 0), 0);
  h2.tick(20000); // без ключа тик не падает
});

test('Hub: id не повторяются, профиль чистится', () => {
  const h = new Hub();
  const a = h.join(fakeConn(), 1, { nm: 'x'.repeat(100), vmax: 999, lv: 0 }, 0);
  assert.equal(a.profile.nm.length, 48);
  assert.equal(a.profile.vmax, MOVE.vmaxMax);
  assert.equal(a.profile.lv, 1);
  const b = h.join(fakeConn(), 2, { vmax: 'x' }, 0);
  assert.notEqual(a.id, b.id);
  assert.equal(b.profile.vmax, MOVE.defaultVmax);
  h.leave(a);
  h.leave(a); // повтор безопасен
  assert.equal(h.players.size, 1);
});
