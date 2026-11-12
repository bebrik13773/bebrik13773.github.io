// Боты для нагрузки и тестов (РТ-03): N игроков ходят рядом, каждый шлёт позицию 5 раз в секунду и считает полученные кадры.
// Запуск: node tools/bots.js --self --n 50 --secs 15        (сервер поднимается тут же, ключи временные)
//         node tools/bots.js --url wss://ДОМЕН --n 50 --secs 30 --alg hmac --kid p1 --key БАЗА64URL --origin https://bober-api.gt.tc
//         ключ — секретный ключ PHP (подпись токенов входа), сервер должен знать к нему открытый (WF_RT_VERIFY_KEYS).
import { WebSocket } from 'ws';
import { signLogin, edKeyPair } from '../lib/tokens.js';
import { encodePos, decodeFrame, angleToByte } from '../lib/snapshot.js';
import { b64uDec, b64uEnc } from '../lib/proto.js';

/**
 * @param {{url:string, origin?:string, n:number, secs:number, signer:{alg:string,kid:string,key:Uint8Array}, area?:number, speed?:number, cheaters?:number, pidBase?:number, nowS?:()=>number}} o
 * Возвращает сводку по каждому боту. Читеры (последние cheaters ботов) один раз прыгают на 500 м и должны получить correct.
 */
export async function runBots(o) {
  const area = o.area ?? 100;
  const speed = o.speed ?? 3;
  const pidBase = o.pidBase ?? 1000;
  const nowS = o.nowS ?? (() => Math.floor(Date.now() / 1000));
  const bots = [];
  for (let i = 0; i < o.n; i++) {
    const b = { i, pid: pidBase + i, x: (Math.random() - 0.5) * area, z: (Math.random() - 0.5) * area, tx: 0, tz: 0, frames: 0, bytes: 0, enters: 0, leaves: 0, corrections: 0, rcp: 0, errors: [], closed: null, known: new Map(), ready: false, cheater: i >= o.n - (o.cheaters || 0), cheated: false };
    b.tx = b.x; b.tz = b.z;
    bots.push(b);
  }
  const start = Date.now();
  await Promise.all(bots.map((b) => new Promise((resolve) => {
    const ws = new WebSocket(o.url, { origin: o.origin || 'http://localhost' });
    b.ws = ws;
    ws.on('error', (e) => { b.errors.push(String(e.message || e)); resolve(); });
    ws.on('close', (c) => { b.closed = c; resolve(); });
    ws.on('open', () => resolve());
    ws.on('message', (data, isBin) => {
      if (isBin) {
        b.frames++; b.bytes += data.length;
        decodeFrame(new Uint8Array(data), b.known);
        return;
      }
      b.bytes += data.length;
      let m; try { m = JSON.parse(data.toString()); } catch { return; }
      if (m.t === 'hello') ws.send(JSON.stringify({ t: 'auth', tok: signLogin(o.signer, b.pid, { nm: 'Бот ' + b.i, vn: 'Лагерь', lv: 1, vmax: 4.6 }, { now: nowS() }) }));
      else if (m.t === 'auth_ok') b.ready = true;
      else if (m.t === 'enter') { b.enters++; b.known.set(m.id, { X: m.x, Z: m.z, A: m.a, N: m.an }); }
      else if (m.t === 'leave') { b.leaves++; b.known.delete(m.id); }
      else if (m.t === 'correct') { b.corrections++; b.x = m.x; b.z = m.z; b.tx = m.x; b.tz = m.z; }
      else if (m.t === 'rcp') b.rcp++;
      else if (m.t === 'err') b.errors.push(m.code + (m.why ? ':' + m.why : ''));
    });
  })));
  const dt = 0.2;
  const timer = setInterval(() => {
    for (const b of bots) {
      if (!b.ready || !b.ws || b.ws.readyState !== 1) continue;
      const dx = b.tx - b.x; const dz = b.tz - b.z; const d = Math.hypot(dx, dz);
      if (d < 1) { b.tx = (Math.random() - 0.5) * area; b.tz = (Math.random() - 0.5) * area; } else { b.x += (dx / d) * speed * dt; b.z += (dz / d) * speed * dt; }
      if (b.cheater && !b.cheated && Date.now() - start > 1500) { b.cheated = true; b.ws.send(encodePos({ x: b.x + 500, z: b.z, ry: 0, anim: 1 })); continue; }
      const ry = Math.atan2(dx, dz);
      b.ws.send(encodePos({ x: b.x, z: b.z, ry, anim: d < 1 ? 0 : 1 }));
      b.lastA = angleToByte(ry);
    }
  }, dt * 1000);
  await new Promise((r) => setTimeout(r, o.secs * 1000));
  clearInterval(timer);
  const secs = (Date.now() - start) / 1000;
  const out = bots.map((b) => ({ i: b.i, frames: b.frames, fps: b.frames / secs, bytes: b.bytes, neighbors: b.known.size, enters: b.enters, leaves: b.leaves, corrections: b.corrections, rcp: b.rcp, errors: b.errors, closed: b.closed, cheater: b.cheater }));
  for (const b of bots) { try { b.ws.close(); } catch { /* ignore */ } }
  return { secs, bots: out };
}

function arg(name, def) {
  const i = process.argv.indexOf('--' + name);
  return i < 0 ? def : (process.argv[i + 1] && !process.argv[i + 1].startsWith('--') ? process.argv[i + 1] : true);
}

const isMain = process.argv[1] && import.meta.url === new URL(`file://${process.argv[1]}`).href;
if (isMain) {
  const n = Number(arg('n', 50));
  const secs = Number(arg('secs', 15));
  let url = arg('url', '');
  let signer; let rt; let rss;
  if (arg('self', false)) {
    const { createRealtime } = await import('../server.js');
    const kp = edKeyPair();
    rt = createRealtime({ port: 0, host: '127.0.0.1', origins: ['http://localhost'], maxConn: 500, maxPerIp: 1000, auth: { alg: 'ed25519', verifyKeys: new Map([['p1', kp.public]]), signer: null } });
    url = `ws://127.0.0.1:${await rt.listen()}`;
    signer = { alg: 'ed25519', kid: 'p1', key: kp.secret };
  } else {
    if (!url) { console.error('нужен --url или --self'); process.exit(2); }
    const key = b64uDec(String(arg('key', '')));
    if (!key) { console.error('нужен --key (секретный ключ PHP, base64url)'); process.exit(2); }
    signer = { alg: arg('alg', 'ed25519'), kid: arg('kid', 'p1'), key: Buffer.from(key) };
  }
  const cpu0 = process.cpuUsage();
  const r = await runBots({ url, origin: arg('origin', 'http://localhost'), n, secs, signer, area: Number(arg('area', 100)), cheaters: Number(arg('cheaters', 0)) });
  const cpu = process.cpuUsage(cpu0);
  const fps = r.bots.map((b) => b.fps).sort((a, b) => a - b);
  const ok = r.bots.filter((b) => !b.closed || b.closed === 1005 || b.closed === 1000);
  console.log(`ботов ${n}, ${r.secs.toFixed(1)} с, не оборвано ${ok.length}`);
  console.log(`кадров/с на бота: мин ${fps[0].toFixed(1)}, медиана ${fps[fps.length >> 1].toFixed(1)}, макс ${fps[fps.length - 1].toFixed(1)}`);
  console.log(`соседей на бота: ${Math.min(...r.bots.map((b) => b.neighbors))}…${Math.max(...r.bots.map((b) => b.neighbors))}; исправлений: ${r.bots.reduce((s, b) => s + b.corrections, 0)}; КБ/с на бота: ${(r.bots.reduce((s, b) => s + b.bytes, 0) / n / r.secs / 1024).toFixed(2)}`);
  if (rt) {
    console.log(`CPU процесса (сервер и боты вместе): ${(((cpu.user + cpu.system) / 1e6 / r.secs) * 100).toFixed(1)}% одного ядра; RSS ${(process.memoryUsage().rss / 1048576).toFixed(0)} МБ; тик ${JSON.stringify({ avg: rt.hub.stats().tick_avg_ms, max: rt.hub.stats().tick_max_ms })}`);
    await rt.close();
  } else {
    try { const h = await (await fetch(url.replace(/^ws/, 'http') + '/health')).json(); console.log('сервер:', JSON.stringify({ rss_mb: h.rss_mb, ...h.presence })); } catch { /* ignore */ }
  }
  process.exit(0);
}
