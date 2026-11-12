// Протокол Дикого Леса: общие константы, коды ошибок и кодеки (РТ-02).
// Файл без импортов и без Node-API: он одинаково работает в браузере и в Node.
// Каноническая копия лежит здесь (realtime/lib/proto.js), копия для клиента — Код/games/wild-forest/src/shared/rt-proto.js.
// Копии обязаны быть побайтно одинаковыми (проверяет тест tests/rt.test.mjs: при расхождении он пишет команду cp).

export const PROTO = 1;

/** Типы токенов: tok (PHP → РТ, вход), evt (PHP → РТ, события), rcp (РТ → PHP, квитанции). */
export const TYP = Object.freeze({ login: 'tok', events: 'evt', receipt: 'rcp' });

/** Коды ошибок протокола. */
export const ERR = Object.freeze({
  bad_token: 'bad_token', // подпись, формат, ключ, тип или поля не прошли проверку
  expired: 'expired',     // срок вышел или токен слишком старый
  rate: 'rate',           // слишком часто
  too_far: 'too_far',     // слишком далеко (скорость или дальность)
  full: 'full',           // сервер переполнен
  outdated: 'outdated',   // версия протокола или билда устарела
});

/** Виды событий в токене evt и виды квитанций в токене rcp. */
export const EVT_KINDS = Object.freeze(['chg', 'chat', 'ann', 'res', 'kick']);
export const RCP_KINDS = Object.freeze(['cp', 'trade']);

/** Управляющие JSON-сообщения по WebSocket. */
export const CTL = Object.freeze({
  toServer: Object.freeze(['auth', 'ping', 'evt']),
  toClient: Object.freeze(['hello', 'auth_ok', 'pong', 'err', 'rcp']),
});

/** Лимиты; зеркало api/forest/config.php, группа rt (сверяется векторами). */
export const LIMITS = Object.freeze({
  tokenMaxLen: 4096,      // длина токена в символах
  ttlS: 600,              // срок жизни токена входа
  refreshS: 180,          // PHP выдаёт новый токен, когда осталось меньше
  maxAgeS: 600,           // токен или квитанция старше этого отклоняются
  skewS: 60,              // допуск часов: из будущего больше чем на это отклоняются
  evtTtlS: 120,           // срок жизни токена событий
  receiptsPerSync: 5,     // квитанций в одном sync
  receiptDataBytes: 1024, // размер данных квитанции (JSON)
  cpIntervalS: 10,        // чекпоинт позиции раз в столько секунд
  keepReceiptsS: 172800,  // сколько PHP хранит rid (2 суток)
  ctlMaxBytes: 4096,      // размер управляющего сообщения
});

export const RID_RE = /^[A-Za-z0-9_-]{8,64}$/;
export const KID_RE = /^[A-Za-z0-9_.-]{1,16}$/;

const enc = new TextEncoder();
const dec = new TextDecoder('utf-8', { fatal: true });

/** base64url без заполнения: Uint8Array | string → string. */
export function b64uEnc(data) {
  const bytes = typeof data === 'string' ? enc.encode(data) : data;
  let bin = '';
  for (let i = 0; i < bytes.length; i++) bin += String.fromCharCode(bytes[i]);
  return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

/** base64url → Uint8Array или null (строго: только допустимые символы, без заполнения). */
export function b64uDec(s) {
  if (typeof s !== 'string' || s === '' || !/^[A-Za-z0-9_-]+$/.test(s) || s.length % 4 === 1) return null;
  try {
    const bin = atob(s.replace(/-/g, '+').replace(/_/g, '/') + '==='.slice((s.length + 3) % 4));
    const out = new Uint8Array(bin.length);
    for (let i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i);
    return out;
  } catch {
    return null;
  }
}

/** Разбор токена БЕЗ проверки подписи (клиенту нужен только срок: rt_exp). Возвращает поля или null. */
export function tokenInfo(token) {
  if (typeof token !== 'string' || token.length > LIMITS.tokenMaxLen) return null;
  const parts = token.split('.');
  if (parts.length !== 2) return null;
  const raw = b64uDec(parts[0]);
  if (!raw) return null;
  try {
    const p = JSON.parse(dec.decode(raw));
    if (!p || typeof p !== 'object' || Array.isArray(p)) return null;
    return { typ: p.typ, pid: p.pid, iat: p.iat, exp: p.exp, kid: p.kid };
  } catch {
    return null;
  }
}

/** Управляющие сообщения: JSON-строка ↔ объект с полем t. */
export function encodeCtl(msg) {
  return JSON.stringify(msg);
}

export function decodeCtl(text) {
  if (typeof text !== 'string' || text.length > LIMITS.ctlMaxBytes) return null;
  try {
    const m = JSON.parse(text);
    return m && typeof m === 'object' && !Array.isArray(m) && typeof m.t === 'string' ? m : null;
  } catch {
    return null;
  }
}

// ── Присутствие (РТ-03) ──
/** Анимации бобра в кадре (1 байт): порядок совпадает с STATE_NAMES в entities/beaver-anim.js. */
export const ANIM = Object.freeze(['idle', 'walk', 'run', 'chop', 'carry', 'sleep', 'scared', 'eat']);
/** Эмодзи-жесты: id 1..6 (0 — нет). Текста нет, модерация не нужна. */
export const EMO = Object.freeze(['wave', 'axe', 'heart', 'fire', 'shake', 'laugh']);
/** Параметры присутствия (сервер сообщает их клиенту в auth_ok). */
export const PRESENCE = Object.freeze({
  tickMs: 200,          // рассылка кадров
  fullEveryMs: 2000,    // полный кадр раз в столько
  cellM: 128,           // сетка интереса, м
  maxNeighbors: 20,     // ближайших соседей
  emoCooldownMs: 5000,  // эмодзи не чаще
  worldRadiusM: 100000, // граница мира
});
