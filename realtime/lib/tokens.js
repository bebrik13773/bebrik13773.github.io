// Подписанные токены и квитанции (РТ-02). Только Node (модуль crypto).
// Формат: base64url(JSON).base64url(подпись), подпись берётся от первой части как от строки.
// Алгоритмы: ed25519 (у каждой стороны только свой секретный ключ и чужой открытый) или hmac (SHA-256, два разных ключа).
// Алгоритм задаётся настройкой сервера, а не токеном (иначе можно подменить алгоритм), ключ выбирается по kid.
import crypto from 'node:crypto';
import { PROTO, TYP, ERR, LIMITS, RID_RE, KID_RE, b64uEnc, b64uDec } from './proto.js';

const PKCS8_ED25519 = Buffer.from('302e020100300506032b657004220420', 'hex');
const SPKI_ED25519 = Buffer.from('302a300506032b6570032100', 'hex');
export const HMAC_MIN_KEY = 32;

/** Секретный ключ ed25519: 64 байта (формат sodium: зерно + открытый ключ) или 32 байта (зерно) → KeyObject. */
export function edPrivate(raw) {
  const b = Buffer.from(raw);
  if (b.length !== 64 && b.length !== 32) throw new Error('секретный ключ ed25519 должен быть 32 или 64 байта');
  return crypto.createPrivateKey({ key: Buffer.concat([PKCS8_ED25519, b.subarray(0, 32)]), format: 'der', type: 'pkcs8' });
}

/** Открытый ключ ed25519: 32 байта → KeyObject. */
export function edPublic(raw) {
  const b = Buffer.from(raw);
  if (b.length !== 32) throw new Error('открытый ключ ed25519 должен быть 32 байта');
  return crypto.createPublicKey({ key: Buffer.concat([SPKI_ED25519, b]), format: 'der', type: 'spki' });
}

/** Новая пара ed25519 из зерна (или случайная): { secret: 64 байта, public: 32 байта } в формате sodium. */
export function edKeyPair(seed = crypto.randomBytes(32)) {
  const priv = edPrivate(seed);
  const pub = crypto.createPublicKey(priv).export({ format: 'der', type: 'spki' }).subarray(-32);
  return { secret: Buffer.concat([Buffer.from(seed).subarray(0, 32), pub]), public: Buffer.from(pub) };
}

export function signRaw(alg, key, msg) {
  if (alg === 'ed25519') return crypto.sign(null, Buffer.from(msg), edPrivate(key));
  if (alg === 'hmac') {
    if (Buffer.from(key).length < HMAC_MIN_KEY) throw new Error('ключ hmac короче 32 байт');
    return crypto.createHmac('sha256', key).update(msg).digest();
  }
  throw new Error('неизвестный алгоритм: ' + alg);
}

export function verifyRaw(alg, key, msg, sig) {
  try {
    if (alg === 'ed25519') {
      const k = Buffer.from(key);
      if (k.length !== 32 || sig.length !== 64) return false;
      return crypto.verify(null, Buffer.from(msg), edPublic(k), sig);
    }
    if (alg === 'hmac') {
      const k = Buffer.from(key);
      if (k.length < HMAC_MIN_KEY || sig.length !== 32) return false;
      const want = crypto.createHmac('sha256', k).update(msg).digest();
      return crypto.timingSafeEqual(want, Buffer.from(sig));
    }
  } catch {
    return false;
  }
  return false;
}

/** Строка вида «kid:ключ,kid2:ключ2» (ключи в base64url) → Map(kid → Buffer). Мусор отбрасывается. */
export function parseKeyring(text) {
  const ring = new Map();
  for (const item of String(text || '').split(',')) {
    const i = item.indexOf(':');
    if (i < 0) continue;
    const kid = item.slice(0, i).trim();
    const key = b64uDec(item.slice(i + 1).trim());
    if (KID_RE.test(kid) && key) ring.set(kid, Buffer.from(key));
  }
  return ring;
}

/** Собрать и подписать токен. Основные поля (v, kid, typ, pid, iat, exp) нельзя перекрыть дополнительными. */
export function signToken({ alg, kid, key }, typ, pid, extra = {}, { now = Math.floor(Date.now() / 1000), ttl = LIMITS.ttlS } = {}) {
  // основные поля идут последними, чтобы дополнительные не могли их подменить
  const payload = { ...extra, v: PROTO, kid, typ, pid, iat: now, exp: now + ttl };
  return signPayloadJson({ alg, key }, JSON.stringify(payload));
}

/** Подписать готовую строку JSON (нужно для векторов: PHP воспроизводит ту же подпись побайтно). */
export function signPayloadJson({ alg, key }, json) {
  const part = b64uEnc(json);
  return part + '.' + b64uEnc(signRaw(alg, key, part));
}

/**
 * Проверка токена. keys: Map(kid → ключ). Возвращает { ok: true, payload } или { ok: false, error }.
 * Порядок: формат → подпись → версия → тип → поля → окно времени.
 */
export function verifyToken(token, { alg, keys, now = Math.floor(Date.now() / 1000), typ = null, maxAge = LIMITS.maxAgeS, skew = LIMITS.skewS }) {
  const bad = (error) => ({ ok: false, error });
  if (typeof token !== 'string' || token.length === 0 || token.length > LIMITS.tokenMaxLen) return bad(ERR.bad_token);
  const parts = token.split('.');
  if (parts.length !== 2) return bad(ERR.bad_token);
  const raw = b64uDec(parts[0]);
  const sig = b64uDec(parts[1]);
  if (!raw || !sig) return bad(ERR.bad_token);
  let p;
  try {
    p = JSON.parse(new TextDecoder('utf-8', { fatal: true }).decode(raw));
  } catch {
    return bad(ERR.bad_token);
  }
  if (!p || typeof p !== 'object' || Array.isArray(p)) return bad(ERR.bad_token);
  if (typeof p.kid !== 'string' || !KID_RE.test(p.kid)) return bad(ERR.bad_token);
  const key = keys && keys.get ? keys.get(p.kid) : undefined;
  if (!key) return bad(ERR.bad_token);
  if (!verifyRaw(alg, key, parts[0], Buffer.from(sig))) return bad(ERR.bad_token);

  if (p.v !== PROTO) return bad(Number.isInteger(p.v) && p.v > PROTO ? ERR.outdated : ERR.bad_token);
  if (typeof p.typ !== 'string' || (typ !== null && p.typ !== typ)) return bad(ERR.bad_token);
  if (!Number.isInteger(p.pid) || p.pid < 0 || !Number.isInteger(p.iat) || !Number.isInteger(p.exp)) return bad(ERR.bad_token);
  if (p.iat > now + skew) return bad(ERR.bad_token);          // из будущего: часы или подделка
  if (p.exp - p.iat > maxAge) return bad(ERR.bad_token);      // срок длиннее допустимого
  if (p.exp < now || now - p.iat > maxAge) return bad(ERR.expired);
  return { ok: true, payload: p };
}

/** Случайный идентификатор квитанции (16 байт, 22 символа base64url). */
export function newRid() {
  return b64uEnc(crypto.randomBytes(16));
}

/** Проверка rid по формату. */
export function ridValid(rid) {
  return typeof rid === 'string' && RID_RE.test(rid);
}

/** Квитанция РТ → PHP. k: 'cp' | 'trade', d: полезные данные (JSON не больше receiptDataBytes). */
export function signReceipt(signer, { pid, k, d }, opts = {}) {
  const dJson = JSON.stringify(d ?? {});
  if (Buffer.byteLength(dJson) > LIMITS.receiptDataBytes) throw new RangeError('данные квитанции слишком большие');
  return signToken(signer, TYP.receipt, pid, { rid: opts.rid || newRid(), k, d: d ?? {} }, opts);
}

/** Токен входа PHP → РТ (в основном для тестов: настоящие выдаёт PHP). */
export function signLogin(signer, pid, fields = {}, opts = {}) {
  return signToken(signer, TYP.login, pid, fields, opts);
}
