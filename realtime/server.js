// Бобёр Кликер: Дикий Лес — realtime-сервер (РТ-01, каркас).
// Сервер ничего не решает и не ходит в PHP: принимает WebSocket, проверяет подписанные токены входа (РТ-02)
// и отвечает /health. Присутствие, эмодзи и чекпоинты приходят в РТ-03.
import http from 'node:http';
import https from 'node:https';
import fs from 'node:fs';
import { WebSocketServer } from 'ws';
import { PROTO, TYP, ERR, LIMITS, encodeCtl, decodeCtl } from './lib/proto.js';
import { parseKeyring, verifyToken, signReceipt } from './lib/tokens.js';

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

/** Настройки подписей из переменных окружения: WF_RT_ALG, WF_RT_VERIFY_KEYS (kid:ключ,...), WF_RT_SIGN_KID, WF_RT_SIGN_KEY. */
export function authFromEnv(env = process.env) {
  const alg = env.WF_RT_ALG || 'ed25519';
  const verifyKeys = parseKeyring(env.WF_RT_VERIFY_KEYS);
  let signer = null;
  if (env.WF_RT_SIGN_KID && env.WF_RT_SIGN_KEY) {
    const ring = parseKeyring(`${env.WF_RT_SIGN_KID}:${env.WF_RT_SIGN_KEY}`);
    if (ring.has(env.WF_RT_SIGN_KID)) signer = { alg, kid: env.WF_RT_SIGN_KID, key: ring.get(env.WF_RT_SIGN_KID) };
  }
  return { alg, verifyKeys, signer };
}

export function createRealtime(opts = {}) {
  const port = opts.port ?? Number(process.env.PORT || 8080);
  const host = opts.host ?? process.env.HOST ?? '0.0.0.0';
  const allowed = opts.origins ?? parseOrigins(process.env.WF_ORIGINS);
  const maxConn = opts.maxConn ?? Number(process.env.WF_MAX_CONN || 150);
  const certPath = opts.cert ?? process.env.WF_TLS_CERT;
  const keyPath = opts.key ?? process.env.WF_TLS_KEY;
  const auth = opts.auth ?? authFromEnv();
  const authReady = auth.verifyKeys.size > 0;
  const authTimeoutMs = opts.authTimeoutMs ?? Number(process.env.WF_AUTH_TIMEOUT_MS || 10000);
  const nowS = opts.now ?? (() => Math.floor(Date.now() / 1000));
  const byPid = new Map(); // pid → ws (последнее соединение побеждает)
  const startedAt = Date.now();
  let accepted = 0;
  let rejected = 0;
  let authOk = 0;
  let authFail = 0;

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
        proto: PROTO,
        auth: {
          alg: auth.alg,
          configured: authReady,
          verify_kids: [...auth.verifyKeys.keys()],
          sign_kid: auth.signer ? auth.signer.kid : null,
          authed: byPid.size,
          ok: authOk,
          failed: authFail,
        },
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

  const send = (ws, msg) => {
    try { ws.send(encodeCtl(msg)); } catch { /* ignore */ }
  };

  function handleAuth(ws, m) {
    if (!authReady) {
      authFail++;
      send(ws, { t: 'err', code: ERR.bad_token, why: 'no_keys' });
      ws.close(4001, 'no_keys');
      return;
    }
    const r = verifyToken(m.tok, { alg: auth.alg, keys: auth.verifyKeys, typ: TYP.login, now: nowS() });
    if (!r.ok) {
      authFail++;
      ws.authFails = (ws.authFails || 0) + 1;
      send(ws, { t: 'err', code: r.error });
      if (ws.authFails >= 3 || !ws.pid) ws.close(4001, r.error);
      return;
    }
    const p = r.payload;
    if (ws.pid && ws.pid !== p.pid) { // продлевать можно только свой токен
      authFail++;
      send(ws, { t: 'err', code: ERR.bad_token });
      return;
    }
    const old = byPid.get(p.pid);
    if (old && old !== ws) {
      send(old, { t: 'err', code: ERR.rate, why: 'replaced' });
      old.close(4002, 'replaced');
    }
    ws.pid = p.pid;
    ws.authExp = p.exp;
    ws.profile = { nm: p.nm, vn: p.vn, lv: p.lv, sk: p.sk, vmax: p.vmax, mu: p.mu, fl: p.fl };
    byPid.set(p.pid, ws);
    authOk++;
    send(ws, { t: 'auth_ok', pid: p.pid, exp: p.exp, kid: p.kid });
  }

  wss.on('connection', (ws) => {
    accepted++;
    ws.isAlive = true;
    ws.pid = 0;
    ws.on('pong', () => { ws.isAlive = true; });
    ws.on('error', () => {});
    ws.on('close', () => { if (ws.pid && byPid.get(ws.pid) === ws) byPid.delete(ws.pid); });
    send(ws, { t: 'hello', build: BUILD, server_ts: Date.now(), proto: PROTO, auth: authReady });
    if (authReady && authTimeoutMs > 0) {
      const t = setTimeout(() => { if (!ws.pid) ws.close(4001, 'auth_timeout'); }, authTimeoutMs);
      t.unref();
      ws.on('close', () => clearTimeout(t));
    }
    ws.on('message', (data, isBinary) => {
      if (isBinary) return; // бинарные кадры добавит РТ-03
      const m = decodeCtl(data.toString());
      if (!m) return;
      if (m.t === 'ping') send(ws, { t: 'pong', c: m.c ?? null, server_ts: Date.now() });
      else if (m.t === 'auth') handleAuth(ws, m);
    });
  });

  // Обрыв «тихих» соединений (мобильные сети).
  const beat = setInterval(() => {
    const now = nowS();
    for (const ws of wss.clients) {
      if (!ws.isAlive) { ws.terminate(); continue; }
      // Токен без продления живёт не дольше срока + допуск: так мут и блокировка из PHP доходят до РТ не позже чем через 10–11 минут.
      if (ws.pid && ws.authExp && now > ws.authExp + LIMITS.skewS) {
        send(ws, { t: 'err', code: ERR.expired });
        ws.close(4003, 'expired');
        continue;
      }
      ws.isAlive = false;
      try { ws.ping(); } catch { /* ignore */ }
    }
  }, 25000);
  beat.unref();

  return {
    server,
    wss,
    auth,
    byPid,
    /** Подписать квитанцию ключом сервера (для РТ-03: чекпоинты и итоги обмена). */
    receipt: (fields, o) => {
      if (!auth.signer) throw new Error('ключ подписи РТ не задан');
      return signReceipt(auth.signer, fields, { now: nowS(), ...o });
    },
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
