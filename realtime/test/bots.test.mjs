import test from 'node:test';
import assert from 'node:assert/strict';
import { WebSocket } from 'ws';
import { createRealtime } from '../server.js';
import { edKeyPair, signLogin } from '../lib/tokens.js';
import { encodePos } from '../lib/snapshot.js';
import { runBots } from '../tools/bots.js';

const php = edKeyPair();
const rtk = edKeyPair();
const mk = (extra = {}) => createRealtime({
  port: 0, host: '127.0.0.1', origins: ['http://localhost'], maxConn: 300, maxPerIp: 1000, ...extra,
  auth: { alg: 'ed25519', verifyKeys: new Map([['p1', php.public]]), signer: { alg: 'ed25519', kid: 'r1', key: rtk.secret } },
});
const signer = { alg: 'ed25519', kid: 'p1', key: php.secret };

test('50 ботов рядом: каждый получает соседей ~5 раз в секунду, память в бюджете, читеры откатываются', async () => {
  const rt = mk();
  const port = await rt.listen();
  try {
    const r = await runBots({ url: `ws://127.0.0.1:${port}`, n: 50, secs: 5, signer, area: 100, cheaters: 2 });
    const honest = r.bots.filter((b) => !b.cheater);
    for (const b of honest) {
      assert.ok(b.fps >= 3.5, `бот ${b.i}: ${b.fps.toFixed(1)} кадров/с`);
      assert.equal(b.neighbors, 20);
      assert.equal(b.corrections, 0);
      assert.equal(b.closed === null || b.closed === 1005 || b.closed === 1000, true);
    }
    const cheaters = r.bots.filter((b) => b.cheater);
    for (const b of cheaters) assert.ok(b.corrections >= 1, 'телепорт-читер откачен');
    const h = await (await fetch(`http://127.0.0.1:${port}/health`)).json();
    assert.ok(h.rss_mb <= 120, 'RSS ' + h.rss_mb);
    assert.ok(h.presence.corrections >= 2);
    assert.ok(h.presence.framesOut > 50 * 5 * 3);
    const m = await (await fetch(`http://127.0.0.1:${port}/metrics`)).text();
    assert.match(m, /wf_connections \d+/);
    assert.match(m, /wf_msgs_per_s [\d.]+/);
  } finally {
    await rt.close();
  }
});

test('сервер: чекпоинты cp подписаны, эмодзи доходит соседу, лимит позиций, идл-таймаут', async () => {
  let now = 1_800_000_000;
  const rt = mk({ now: () => now, idleMs: 700, beatMs: 100, hub: undefined });
  rt.hub.cpIntervalMs = 300;
  const port = await rt.listen();
  const open = async (pid) => {
    const ws = new WebSocket(`ws://127.0.0.1:${port}`, { origin: 'http://localhost' });
    const msgs = []; const waiters = [];
    ws.on('message', (d, bin) => { if (bin) return; const m = JSON.parse(d.toString()); const w = waiters.shift(); if (w) w(m); else msgs.push(m); });
    const next = (pred = () => true) => new Promise((res) => {
      const check = () => { const i = msgs.findIndex(pred); if (i >= 0) return res(msgs.splice(i, 1)[0]); waiters.push((m) => { if (pred(m)) res(m); else { msgs.push(m); setTimeout(check, 5); } }); };
      check();
    });
    const closed = new Promise((r) => ws.once('close', (c) => r(c)));
    await new Promise((r) => ws.once('open', r));
    await next((m) => m.t === 'hello');
    ws.send(JSON.stringify({ t: 'auth', tok: signLogin(signer, pid, { nm: 'P' + pid, vmax: 4.6 }, { now }) }));
    const ok = await next((m) => m.t === 'auth_ok');
    return { ws, next, ok, closed };
  };
  try {
    const a = await open(1);
    const b = await open(2);
    assert.equal(a.ok.tick_ms, 200);
    assert.ok(a.ok.id >= 1 && a.ok.id !== b.ok.id);
    a.ws.send(encodePos({ x: 0, z: 0, ry: 0, anim: 0 }));
    b.ws.send(encodePos({ x: 5, z: 0, ry: 0, anim: 0 }));
    const en = await a.next((m) => m.t === 'enter');
    assert.equal(en.nm, 'P2');
    b.ws.send(JSON.stringify({ t: 'emo', e: 2 }));
    a.ws.send(JSON.stringify({ t: 'emo', e: 4 }));
    const emo = await b.next((m) => m.t === 'emo');
    assert.deepEqual([emo.id, emo.e], [a.ok.id, 4]);
    // чекпоинт: подписан ключом РТ, PHP-сторона проверит так же
    const rcp = await a.next((m) => m.t === 'rcp');
    const { verifyToken } = await import('../lib/tokens.js');
    const v = verifyToken(rcp.tok, { alg: 'ed25519', keys: new Map([['r1', rtk.public]]), typ: 'rcp', now });
    assert.equal(v.ok, true);
    assert.equal(v.payload.pid, 1);
    assert.equal(v.payload.k, 'cp');
    assert.equal(v.payload.d.x, 0);
    // шторм позиций: лишнее отбрасывается, соединение живёт, потом закрывается за шторм
    for (let i = 0; i < 400; i++) a.ws.send(encodePos({ x: 0, z: 0, ry: 0, anim: 0 }));
    assert.equal(await a.closed, 4008);
    assert.ok(rt.hub.stats().msgDropped > 100);
    // b молчит дольше idleMs → закрыт
    assert.equal(await b.closed, 4009);
    for (let i = 0; i < 40 && rt.hub.players.size; i++) await new Promise((r) => setTimeout(r, 25));
    assert.equal(rt.hub.players.size, 0, 'хаб очищается при закрытии');
  } finally {
    await rt.close();
  }
});

test('лимит соединений с одного IP', async () => {
  const rt = mk({ maxPerIp: 2 });
  const port = await rt.listen();
  const res = [];
  const socks = [];
  for (let i = 0; i < 3; i++) {
    const ws = new WebSocket(`ws://127.0.0.1:${port}`, { origin: 'http://localhost' });
    socks.push(ws);
    res.push(await new Promise((r) => { ws.once('open', () => r('open')); ws.once('error', () => r('err')); }));
  }
  assert.deepEqual(res, ['open', 'open', 'err']);
  socks.forEach((w) => w.close());
  await new Promise((r) => setTimeout(r, 100));
  const again = new WebSocket(`ws://127.0.0.1:${port}`, { origin: 'http://localhost' });
  assert.equal(await new Promise((r) => { again.once('open', () => r('open')); again.once('error', () => r('err')); }), 'open', 'слот освободился');
  again.close();
  await rt.close();
});
