// Бобёр Кликер: Дикий Лес — realtime-сервер (РТ-01, каркас).
// Сервер ничего не решает и не ходит в PHP: только принимает WebSocket и отвечает /health.
// Протокол, токены и присутствие приходят в РТ-02 и РТ-03.
import http from 'node:http';
import https from 'node:https';
import fs from 'node:fs';
import { WebSocketServer } from 'ws';

export const BUILD = process.env.BUILD_SHA || process.env.SOURCE_COMMIT || 'dev';
const DEFAULT_ORIGINS = ['https://bober-api.gt.tc', 'http://bober-api.gt.tc', 'http://localhost', 'http://127.0.0.1'];

export function parseOrigins(raw) {
  const list = (raw ? raw.split(',') : DEFAULT_ORIGINS).map((s) => s.trim()).filter(Boolean);
  return list;
}

// Origin подходит, если совпадает со списком (для localhost любой порт).
export function originAllowed(origin, allowed) {
  if (!origin) return false;
  let u;
  try { u = new URL(origin); } catch { return false; }
  return allowed.some((a) => {
    let b;
    try { b = new URL(a); } catch { return false; }
    if (u.protocol !== b.protocol || u.hostname !== b.hostname) return false;
    const local = b.hostname === 'localhost' || b.hostname === '127.0.0.1';
    return local ? true : u.port === b.port;
  });
}

export function createRealtime(opts = {}) {
  const port = opts.port ?? Number(process.env.PORT || 8080);
  const host = opts.host ?? process.env.HOST ?? '0.0.0.0';
  const allowed = opts.origins ?? parseOrigins(process.env.WF_ORIGINS);
  const maxConn = opts.maxConn ?? Number(process.env.WF_MAX_CONN || 150);
  const certPath = opts.cert ?? process.env.WF_TLS_CERT;
  const keyPath = opts.key ?? process.env.WF_TLS_KEY;
  const startedAt = Date.now();
  let accepted = 0;
  let rejected = 0;

  const handler = (req, res) => {
    const url = (req.url || '').split('?')[0];
    if (url === '/health' || url === '/') {
      const body = JSON.stringify({
        ok: true,
        service: 'wf-realtime',
        build: BUILD,
        node: process.version,
        uptime_s: Math.round((Date.now() - startedAt) / 1000),
        connections: wss.clients.size,
        max_connections: maxConn,
        accepted,
        rejected,
        rss_mb: Math.round(process.memoryUsage().rss / 1048576),
        tls: Boolean(certPath && keyPath),
      });
      res.writeHead(200, {
        'Content-Type': 'application/json; charset=utf-8',
        'Cache-Control': 'no-store',
        'Access-Control-Allow-Origin': '*',
      });
      res.end(body);
      return;
    }
    res.writeHead(404, { 'Content-Type': 'text/plain; charset=utf-8' });
    res.end('not found');
  };

  const useTls = Boolean(certPath && keyPath);
  const server = useTls
    ? https.createServer({ cert: fs.readFileSync(certPath), key: fs.readFileSync(keyPath) }, handler)
    : http.createServer(handler);

  const wss = new WebSocketServer({
    noServer: true,
    maxPayload: 4096,
    perMessageDeflate: false,
  });

  server.on('upgrade', (req, socket, head) => {
    const ok = originAllowed(req.headers.origin, allowed);
    if (!ok) {
      rejected++;
      socket.write('HTTP/1.1 403 Forbidden\r\nConnection: close\r\n\r\n');
      socket.destroy();
      return;
    }
    if (wss.clients.size >= maxConn) {
      rejected++;
      socket.write('HTTP/1.1 503 Service Unavailable\r\nConnection: close\r\n\r\n');
      socket.destroy();
      return;
    }
    wss.handleUpgrade(req, socket, head, (ws) => wss.emit('connection', ws, req));
  });

  wss.on('connection', (ws) => {
    accepted++;
    ws.isAlive = true;
    ws.on('pong', () => { ws.isAlive = true; });
    ws.on('error', () => {});
    // Пока только приветствие и эхо-пинг. Настоящий протокол — РТ-02.
    ws.send(JSON.stringify({ t: 'hello', build: BUILD, server_ts: Date.now(), proto: 0 }));
    ws.on('message', (data) => {
      let m;
      try { m = JSON.parse(data.toString()); } catch { return; }
      if (m && m.t === 'ping') ws.send(JSON.stringify({ t: 'pong', c: m.c ?? null, server_ts: Date.now() }));
    });
  });

  // Обрыв «тихих» соединений (мобильные сети).
  const beat = setInterval(() => {
    for (const ws of wss.clients) {
      if (!ws.isAlive) { ws.terminate(); continue; }
      ws.isAlive = false;
      try { ws.ping(); } catch { /* ignore */ }
    }
  }, 25000);
  beat.unref();

  return {
    server,
    wss,
    listen: () => new Promise((resolve) => server.listen(port, host, () => resolve(server.address().port))),
    close: () => new Promise((resolve) => {
      clearInterval(beat);
      for (const ws of wss.clients) ws.terminate();
      server.close(() => resolve());
    }),
  };
}

const isMain = process.argv[1] && import.meta.url === new URL(`file://${process.argv[1]}`).href;
if (isMain) {
  const rt = createRealtime();
  rt.listen().then((p) => console.log(`[wf-realtime] build=${BUILD} порт ${p}`));
  const stop = () => rt.close().then(() => process.exit(0));
  process.on('SIGTERM', stop);
  process.on('SIGINT', stop);
  process.on('uncaughtException', (e) => { console.error('[wf-realtime] упал:', e); process.exit(1); });
}
