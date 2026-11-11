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

// ── Бинарный кадр позиций (черновой формат v1, окончательно утверждается в РТ-03) ──
// Заголовок 6 байт: u8 вид (1), u8 флаги (бит 0: полный кадр), u16 тик, u8 число записей, u8 резерв.
// Запись 12 байт: u16 id, i32 x (дециметры), i32 z (дециметры), u8 угол (0..255 = 0..2π), u8 анимация.
export const FRAME_KIND_POS = 1;
const FRAME_HEAD = 6;
const FRAME_ITEM = 12;
const FRAME_MAX_ITEMS = 40;

export function encodeFrame(frame) {
  const items = frame.items || [];
  if (items.length > FRAME_MAX_ITEMS) throw new RangeError('слишком много записей в кадре');
  const buf = new Uint8Array(FRAME_HEAD + items.length * FRAME_ITEM);
  const dv = new DataView(buf.buffer);
  dv.setUint8(0, FRAME_KIND_POS);
  dv.setUint8(1, frame.full ? 1 : 0);
  dv.setUint16(2, frame.tick & 0xffff);
  dv.setUint8(4, items.length);
  items.forEach((it, i) => {
    const o = FRAME_HEAD + i * FRAME_ITEM;
    dv.setUint16(o, it.id & 0xffff);
    dv.setInt32(o + 2, Math.round(it.x * 10));
    dv.setInt32(o + 6, Math.round(it.z * 10));
    const turn = ((it.ry % (2 * Math.PI)) + 2 * Math.PI) % (2 * Math.PI);
    dv.setUint8(o + 10, Math.round((turn / (2 * Math.PI)) * 256) & 0xff);
    dv.setUint8(o + 11, it.anim & 0xff);
  });
  return buf;
}

export function decodeFrame(bytes) {
  if (!(bytes instanceof Uint8Array) || bytes.length < FRAME_HEAD) return null;
  const dv = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);
  if (dv.getUint8(0) !== FRAME_KIND_POS) return null;
  const n = dv.getUint8(4);
  if (n > FRAME_MAX_ITEMS || bytes.length !== FRAME_HEAD + n * FRAME_ITEM) return null;
  const items = [];
  for (let i = 0; i < n; i++) {
    const o = FRAME_HEAD + i * FRAME_ITEM;
    items.push({
      id: dv.getUint16(o),
      x: dv.getInt32(o + 2) / 10,
      z: dv.getInt32(o + 6) / 10,
      ry: (dv.getUint8(o + 10) / 256) * 2 * Math.PI,
      anim: dv.getUint8(o + 11),
    });
  }
  return { full: (dv.getUint8(1) & 1) === 1, tick: dv.getUint16(2), items };
}
