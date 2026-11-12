// Ядро присутствия (РТ-03). Не знает про WebSocket: соединение — объект conn { sendJson, sendBin, buffered, close }.
// Хаб хранит игроков в памяти, проверяет скорость, рассылает соседей тиками, выдаёт эмодзи и чекпоинты.
// Всё время передаётся параметром now (мс), поэтому логика тестируется без таймеров.
import { Grid } from './grid.js';
import { encodeFrame } from './snapshot.js';
import { PRESENCE, EMO, ANIM, ERR, LIMITS } from './proto.js';

/** Зеркало HERO.moveSlack, HERO.moveConstM из Код/games/wild-forest/src/shared/formulas.js (сверяет тест hub.test.mjs). */
export const MOVE = Object.freeze({ slack: 1.35, constM: 3, anchorMs: 5000, defaultVmax: 4.6, vmaxMin: 1, vmaxMax: 12 });

/** Сколько метров можно пройти за dt секунд при максимальной скорости vMax. */
export function moveAllowance(vMax, dtSec) {
  return vMax * Math.max(0, dtSec) * MOVE.slack + MOVE.constM;
}

const BUFFER_SKIP = 65536; // не шлём кадры тому, у кого очередь отправки больше (медленный телефон)

export class Hub {
  constructor(opts = {}) {
    this.tickMs = opts.tickMs ?? PRESENCE.tickMs;
    this.fullEveryMs = opts.fullEveryMs ?? PRESENCE.fullEveryMs;
    this.maxNeighbors = opts.maxNeighbors ?? PRESENCE.maxNeighbors;
    this.emoCooldownMs = opts.emoCooldownMs ?? PRESENCE.emoCooldownMs;
    this.cpIntervalMs = (opts.cpIntervalS ?? LIMITS.cpIntervalS) * 1000;
    this.strikeWindowMs = opts.strikeWindowMs ?? 600000;
    this.strikeLimit = opts.strikeLimit ?? 8;
    this.blockMs = opts.blockMs ?? 600000;
    this.worldDm = (opts.worldRadiusM ?? PRESENCE.worldRadiusM) * 10;
    this.receipt = opts.receipt || null; // (fields) → токен квитанции
    this.grid = new Grid(opts.cellM ?? PRESENCE.cellM);
    this.players = new Map(); // id → игрок
    this.blocked = new Map(); // pid → до какого времени (мс)
    this.nextId = 1;
    this.tickNo = 0;
    this.rate = 0;
    this._rateAt = 0;
    this._rateIn = 0;
    this.tickAvgMs = 0;
    this.tickMaxMs = 0;
    this._tickWin = 0;
    this.m = {
      msgIn: 0, msgDropped: 0, framesOut: 0, bytesOut: 0, corrections: 0, strikes: 0, blocks: 0,
      emoOut: 0, enters: 0, leaves: 0, skipped: 0, cpOut: 0, joined: 0,
    };
  }

  _allocId() {
    for (let i = 0; i < 65535; i++) {
      const id = this.nextId;
      this.nextId = this.nextId >= 65535 ? 1 : this.nextId + 1;
      if (!this.players.has(id)) return id;
    }
    return 0;
  }

  static cleanProfile(p = {}) {
    const str = (v, d) => (typeof v === 'string' ? v.slice(0, 48) : d);
    const num = (v, d) => (Number.isFinite(v) ? v : d);
    return {
      nm: str(p.nm, ''),
      vn: str(p.vn, ''),
      lv: Math.max(1, Math.min(50, Math.trunc(num(p.lv, 1)))),
      sk: str(p.sk, 'default'),
      vmax: Math.max(MOVE.vmaxMin, Math.min(MOVE.vmaxMax, num(p.vmax, MOVE.defaultVmax))),
      mu: Math.trunc(num(p.mu, 0)),
      fl: Math.trunc(num(p.fl, 0)),
    };
  }

  /** Новый игрок. Возвращает объект игрока или null (кончились id). */
  join(conn, pid, profile, now) {
    const id = this._allocId();
    if (!id) return null;
    const p = {
      id, pid, conn, profile: Hub.cleanProfile(profile),
      X: 0, Z: 0, A: 0, N: 0, hasPos: false, anchor: null, lastPosAt: 0,
      known: new Map(), lastFullAt: now - ((id * 37) % this.fullEveryMs),
      emoAt: -Infinity, strikes: [], lastCorrectAt: 0, lastCpAt: now, joinedAt: now,
    };
    this.players.set(id, p);
    this.m.joined++;
    return p;
  }

  /** Продление токена: обновляем профиль (скорость, мут и т.д.). */
  refresh(p, profile) {
    p.profile = Hub.cleanProfile(profile);
  }

  leave(p) {
    if (!p || this.players.get(p.id) !== p) return;
    this.grid.remove(p.id);
    this.players.delete(p.id);
  }

  isBlocked(pid, now) {
    const until = this.blocked.get(pid);
    if (!until) return false;
    if (until <= now) { this.blocked.delete(pid); return false; }
    return true;
  }

  _strike(p, now) {
    p.strikes.push(now);
    const from = now - this.strikeWindowMs;
    while (p.strikes.length && p.strikes[0] < from) p.strikes.shift();
    this.m.strikes++;
    if (p.strikes.length >= this.strikeLimit) {
      this.blocked.set(p.pid, now + this.blockMs);
      this.m.blocks++;
      p.conn.sendJson({ t: 'err', code: ERR.too_far, why: 'blocked' });
      p.conn.close(4005, 'cheat');
      return true;
    }
    return false;
  }

  /**
   * Позиция от клиента (целые дециметры). Возвращает 'ok', 'corrected', 'blocked' или 'bad'.
   * Скорость сверяется с точкой-якорем, а не с прошлым сообщением: якорь двигается не чаще раза в 5 с,
   * иначе запас +3 м обновлялся бы с каждым сообщением (15 м/с при 5 сообщениях в секунду).
   * Первая позиция после подключения принимается без проверки: её сверяет PHP по чекпоинту (cp).
   */
  onPos(p, pos, now) {
    if (!pos || pos.N >= ANIM.length) return 'bad';
    const farOut = Math.abs(pos.X) > this.worldDm || Math.abs(pos.Z) > this.worldDm;
    if (p.hasPos) {
      let bad = farOut;
      if (!bad) {
        const dt = (now - p.anchor.t) / 1000;
        const dist = Math.hypot(pos.X - p.anchor.X, pos.Z - p.anchor.Z) / 10;
        bad = dist > moveAllowance(p.profile.vmax, dt);
      }
      if (bad) {
        this.m.corrections++;
        if (this._strike(p, now)) return 'blocked';
        if (now - p.lastCorrectAt >= 500) {
          p.lastCorrectAt = now;
          p.conn.sendJson({ t: 'correct', code: ERR.too_far, x: p.X / 10, z: p.Z / 10 });
        }
        return 'corrected';
      }
    } else if (farOut) {
      return 'bad';
    }
    p.X = pos.X; p.Z = pos.Z; p.A = pos.A; p.N = pos.N;
    p.lastPosAt = now;
    if (!p.anchor || now - p.anchor.t >= MOVE.anchorMs) p.anchor = { X: pos.X, Z: pos.Z, t: now };
    p.hasPos = true;
    this.grid.set(p.id, pos.X, pos.Z);
    return 'ok';
  }

  /** Эмодзи: 'ok', 'rate' (чаще раза в 5 с) или 'bad'. Получают те, у кого игрок в списке соседей. */
  onEmoji(p, e, now) {
    if (!Number.isInteger(e) || e < 1 || e > EMO.length || !p.hasPos) return 'bad';
    if (now - p.emoAt < this.emoCooldownMs) return 'rate';
    p.emoAt = now;
    for (const id of this.grid.around(p.X, p.Z, p.id)) {
      const q = this.players.get(id);
      if (q && q.known.has(p.id)) { q.conn.sendJson({ t: 'emo', id: p.id, e }); this.m.emoOut++; }
    }
    return 'ok';
  }

  /** Один тик: рассылка соседей и чекпоинты. */
  tick(now) {
    const t0 = performance.now();
    this.tickNo = (this.tickNo + 1) & 0xffff;
    for (const p of this.players.values()) {
      if (!p.hasPos) continue;
      if (this.receipt && now - p.lastCpAt >= this.cpIntervalMs && now - p.lastPosAt <= 30000) {
        p.lastCpAt = now;
        try {
          const tok = this.receipt({ pid: p.pid, k: 'cp', d: { x: p.X / 10, z: p.Z / 10, ry: Math.round((p.A / 256) * 628.318) / 100, t: now } });
          p.conn.sendJson({ t: 'rcp', tok });
          this.m.cpOut++;
        } catch { /* ключа подписи нет: чекпоинты выключены */ }
      }
      this._presence(p, now);
    }
    if (now - this._rateAt >= 1000) {
      if (this._rateAt) this.rate = ((this.m.msgIn - this._rateIn) * 1000) / (now - this._rateAt);
      this._rateAt = now;
      this._rateIn = this.m.msgIn;
    }
    const dur = performance.now() - t0;
    this.tickAvgMs = this.tickAvgMs * 0.95 + dur * 0.05;
    if (++this._tickWin > 100) { this._tickWin = 0; this.tickMaxMs = 0; }
    if (dur > this.tickMaxMs) this.tickMaxMs = dur;
  }

  _presence(p, now) {
    if (p.conn.buffered() > BUFFER_SKIP) { this.m.skipped++; return; }
    const ids = this.grid.near(p.id, this.maxNeighbors);
    const set = new Set(ids);
    for (const oid of p.known.keys()) {
      if (!set.has(oid)) { p.known.delete(oid); p.conn.sendJson({ t: 'leave', id: oid }); this.m.leaves++; }
    }
    const items = [];
    for (const oid of ids) {
      const o = this.players.get(oid);
      if (!o) continue;
      if (!p.known.has(oid)) {
        p.known.set(oid, { X: o.X, Z: o.Z, A: o.A, N: o.N });
        const f = o.profile;
        p.conn.sendJson({ t: 'enter', id: oid, pid: o.pid, nm: f.nm, vn: f.vn, lv: f.lv, sk: f.sk, x: o.X, z: o.Z, a: o.A, an: o.N });
        this.m.enters++;
      } else {
        items.push({ id: oid, X: o.X, Z: o.Z, A: o.A, N: o.N });
      }
    }
    const full = now - p.lastFullAt >= this.fullEveryMs;
    if (full) p.lastFullAt = now;
    if (items.length === 0) return;
    const frame = encodeFrame({ tick: this.tickNo, full }, items, p.known);
    if (frame) { p.conn.sendBin(frame); this.m.framesOut++; }
  }

  /** Сводка для /health и /metrics. */
  stats() {
    let authed = 0;
    for (const p of this.players.values()) if (p.hasPos) authed++;
    return {
      players: this.players.size,
      positioned: authed,
      cells: this.grid.cellCount,
      msgs_per_s: Math.round(this.rate * 10) / 10,
      tick_avg_ms: Math.round(this.tickAvgMs * 100) / 100,
      tick_max_ms: Math.round(this.tickMaxMs * 100) / 100,
      blocked: this.blocked.size,
      ...this.m,
    };
  }
}
