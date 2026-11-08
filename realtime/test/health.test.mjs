import test from 'node:test';
import assert from 'node:assert/strict';
import { WebSocket } from 'ws';
import { createRealtime, originAllowed } from '../server.js';

test('originAllowed: игра, localhost с любым портом, чужие отклоняются', () => {
  const a = ['https://bober-api.gt.tc', 'http://localhost'];
  assert.equal(originAllowed('https://bober-api.gt.tc', a), true);
  assert.equal(originAllowed('http://bober-api.gt.tc', a), false);
  assert.equal(originAllowed('http://localhost:5173', a), true);
  assert.equal(originAllowed('https://evil.example', a), false);
  assert.equal(originAllowed('', a), false);
  assert.equal(originAllowed('мусор', a), false);
});

test('/health и wss hello', async () => {
  const rt = createRealtime({ port: 0, host: '127.0.0.1', origins: ['http://localhost'], maxConn: 1 });
  const port = await rt.listen();
  try {
    const h = await (await fetch(`http://127.0.0.1:${port}/health`)).json();
    assert.equal(h.ok, true);
    assert.equal(h.service, 'wf-realtime');
    assert.equal(typeof h.build, 'string');
    assert.equal(h.connections, 0);

    const ws = new WebSocket(`ws://127.0.0.1:${port}`, { origin: 'http://localhost:3000' });
    const hello = await new Promise((res, rej) => { ws.once('message', (d) => res(JSON.parse(d.toString()))); ws.once('error', rej); });
    assert.equal(hello.t, 'hello');
    ws.send(JSON.stringify({ t: 'ping', c: 7 }));
    const pong = await new Promise((res) => ws.once('message', (d) => res(JSON.parse(d.toString()))));
    assert.equal(pong.t, 'pong');
    assert.equal(pong.c, 7);

    // лимит соединений
    const ws2 = new WebSocket(`ws://127.0.0.1:${port}`, { origin: 'http://localhost' });
    const err2 = await new Promise((res) => { ws2.once('error', res); ws2.once('open', () => res(null)); });
    assert.ok(err2, 'второе соединение при maxConn=1 должно быть отклонено');

    // чужой Origin
    ws.close();
    await new Promise((r) => setTimeout(r, 50));
    const ws3 = new WebSocket(`ws://127.0.0.1:${port}`, { origin: 'https://evil.example' });
    const err3 = await new Promise((res) => { ws3.once('error', res); ws3.once('open', () => res(null)); });
    assert.ok(err3, 'чужой Origin отклоняется');
  } finally {
    await rt.close();
  }
});
