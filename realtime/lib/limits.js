// Лимиты РТ-03: ведро токенов на тип сообщения, счётчик соединений с одного IP.

/** Ведро: rate токенов в секунду, вместимость burst. */
export class Bucket {
  constructor(rate, burst, nowMs) {
    this.rate = rate;
    this.burst = burst;
    this.tokens = burst;
    this.at = nowMs;
  }

  take(nowMs, n = 1) {
    const dt = Math.max(0, nowMs - this.at) / 1000;
    this.at = nowMs;
    this.tokens = Math.min(this.burst, this.tokens + dt * this.rate);
    if (this.tokens < n) return false;
    this.tokens -= n;
    return true;
  }
}

/** Классы сообщений: настройки (rate/с, burst). Клиент шлёт позицию 5/с, так что запас двойной. */
export const CLASS_LIMITS = Object.freeze({
  pos: [8, 12],
  ping: [1, 4],
  auth: [0.3, 3],
  emo: [1, 3],
  other: [2, 5],
});

/** Набор вёдер одного соединения. */
export function makeConnLimits(nowMs) {
  const out = {};
  for (const [k, [rate, burst]] of Object.entries(CLASS_LIMITS)) out[k] = new Bucket(rate, burst, nowMs);
  return out;
}

export function classOf(type) {
  return type === 'pos' || type === 'ping' || type === 'auth' || type === 'emo' ? type : 'other';
}

/** Число одновременных соединений с одного IP. */
export class IpCounter {
  constructor(max = 20) {
    this.max = max;
    this.map = new Map();
  }

  acquire(ip) {
    const n = this.map.get(ip) || 0;
    if (n >= this.max) return false;
    this.map.set(ip, n + 1);
    return true;
  }

  release(ip) {
    const n = (this.map.get(ip) || 0) - 1;
    if (n <= 0) this.map.delete(ip);
    else this.map.set(ip, n);
  }
}

/** IP клиента: за доверенным прокси (peer из частной сети: Caddy в Docker, localhost) берём последнюю запись X-Forwarded-For. */
export function clientIp(req) {
  let peer = (req.socket && req.socket.remoteAddress) || '';
  peer = peer.replace(/^::ffff:/, '');
  const xff = req.headers && req.headers['x-forwarded-for'];
  if (xff && isPrivate(peer)) {
    const last = String(xff).split(',').pop().trim();
    if (last) return last.replace(/^::ffff:/, '');
  }
  return peer || 'unknown';
}

export function isPrivate(ip) {
  if (ip === '::1' || ip === 'localhost') return true;
  const m = /^(\d+)\.(\d+)\./.exec(ip);
  if (!m) return /^f[cd][0-9a-f]{2}:/i.test(ip);
  const a = Number(m[1]);
  const b = Number(m[2]);
  return a === 10 || a === 127 || (a === 172 && b >= 16 && b <= 31) || (a === 192 && b === 168);
}
