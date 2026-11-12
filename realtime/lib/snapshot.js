// Кодек присутствия (РТ-03). Без импортов и без Node-API: клиент получит побайтную копию (src/shared/rt-snapshot.js, РТ-04).
//
// Клиент → сервер, позиция (11 байт): u8 вид (2), i32 X, i32 Z (дециметры), u8 угол (0..255 = 0..2π), u8 анимация.
// Сервер → клиент, кадр: заголовок 5 байт: u8 вид (1), u8 флаги (бит 0: полный кадр), u16 тик, u8 число записей.
//   Запись: u16 id, u8 флаги записи, затем поля по порядку:
//     бит 0: есть позиция; бит 1: позиция абсолютная (i32 X, i32 Z), иначе дельта (i16 dX, i16 dZ) к ранее присланной;
//     бит 2: есть угол (u8); бит 3: есть анимация (u8).
//   В полном кадре у каждой записи абсолютная позиция, угол и анимация (чинит любой рассинхрон за ≤ 2 с).
// Уход и появление соседей идут отдельными JSON-сообщениями (enter с начальным состоянием, leave).
export const KIND_FRAME = 1;
export const KIND_POS = 2;
export const POS_BYTES = 11;
const HEAD = 5;
export const FRAME_MAX_BYTES = 1024;
export const FRAME_MAX_ITEMS = 40;

const TAU = Math.PI * 2;

/** Угол в радианах → байт. */
export function angleToByte(ry) {
  const turn = ((ry % TAU) + TAU) % TAU;
  return Math.round((turn / TAU) * 256) & 0xff;
}

/** Байт → радианы. */
export function byteToAngle(a) {
  return (a / 256) * TAU;
}

/** Метры → дециметры (целые). */
export function toDm(m) {
  return Math.round(m * 10);
}

/** Позиция клиента → 11 байт. Аргументы: x, z в метрах. */
export function encodePos({ x, z, ry, anim }) {
  const buf = new Uint8Array(POS_BYTES);
  const dv = new DataView(buf.buffer);
  dv.setUint8(0, KIND_POS);
  dv.setInt32(1, toDm(x));
  dv.setInt32(5, toDm(z));
  dv.setUint8(9, angleToByte(ry));
  dv.setUint8(10, anim & 0xff);
  return buf;
}

/** 11 байт → { X, Z, A, N } (целые) или null. */
export function decodePos(bytes) {
  if (!(bytes instanceof Uint8Array) || bytes.length !== POS_BYTES || bytes[0] !== KIND_POS) return null;
  const dv = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);
  return { X: dv.getInt32(1), Z: dv.getInt32(5), A: dv.getUint8(9), N: dv.getUint8(10) };
}

/**
 * Собрать кадр. items: [{ id, X, Z, A, N }] (целые). known: Map(id → { X, Z, A, N }), что клиент уже знает;
 * кодек обновляет known. Неизменившиеся записи пропускаются (кроме полного кадра).
 * Возвращает Uint8Array или null, если слать нечего.
 */
export function encodeFrame({ tick, full }, items, known) {
  const parts = [];
  for (const it of items) {
    const k = known.get(it.id);
    let flags = 0;
    let body = 0;
    if (full || !k) {
      flags = 1 | 2 | 4 | 8;
      body = 8 + 1 + 1;
    } else {
      const dX = it.X - k.X;
      const dZ = it.Z - k.Z;
      if (dX !== 0 || dZ !== 0) {
        flags |= 1;
        if (Math.abs(dX) > 32767 || Math.abs(dZ) > 32767) { flags |= 2; body += 8; } else body += 4;
      }
      if (it.A !== k.A) { flags |= 4; body += 1; }
      if (it.N !== k.N) { flags |= 8; body += 1; }
      if (flags === 0) continue;
    }
    parts.push({ it, k, flags, size: 3 + body });
  }
  if (parts.length === 0 && !full) return null;
  if (parts.length > FRAME_MAX_ITEMS) throw new RangeError('слишком много записей в кадре');
  let total = HEAD;
  for (const p of parts) total += p.size;
  if (total > FRAME_MAX_BYTES) throw new RangeError('кадр больше ' + FRAME_MAX_BYTES + ' байт');
  const buf = new Uint8Array(total);
  const dv = new DataView(buf.buffer);
  dv.setUint8(0, KIND_FRAME);
  dv.setUint8(1, full ? 1 : 0);
  dv.setUint16(2, tick & 0xffff);
  dv.setUint8(4, parts.length);
  let o = HEAD;
  for (const { it, k, flags } of parts) {
    dv.setUint16(o, it.id); dv.setUint8(o + 2, flags); o += 3;
    if (flags & 1) {
      if (flags & 2) { dv.setInt32(o, it.X); dv.setInt32(o + 4, it.Z); o += 8; } else { dv.setInt16(o, it.X - k.X); dv.setInt16(o + 2, it.Z - k.Z); o += 4; }
    }
    if (flags & 4) { dv.setUint8(o, it.A); o += 1; }
    if (flags & 8) { dv.setUint8(o, it.N); o += 1; }
    known.set(it.id, { X: it.X, Z: it.Z, A: it.A, N: it.N });
  }
  return buf;
}

/**
 * Разобрать кадр. known: Map(id → { X, Z, A, N }) клиента (заполняется сообщениями enter и самим кодеком).
 * Запись с дельтой для неизвестного id пропускается (ждём enter или полный кадр). Возвращает { full, tick, items } или null.
 * items: [{ id, X, Z, A, N }] — полное состояние каждого затронутого игрока.
 */
export function decodeFrame(bytes, known) {
  if (!(bytes instanceof Uint8Array) || bytes.length < HEAD || bytes[0] !== KIND_FRAME) return null;
  const dv = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);
  const n = dv.getUint8(4);
  if (n > FRAME_MAX_ITEMS) return null;
  const items = [];
  let o = HEAD;
  for (let i = 0; i < n; i++) {
    if (o + 3 > bytes.length) return null;
    const id = dv.getUint16(o);
    const flags = dv.getUint8(o + 2);
    o += 3;
    const k = known.get(id);
    let X = k ? k.X : 0;
    let Z = k ? k.Z : 0;
    let A = k ? k.A : 0;
    let N = k ? k.N : 0;
    let usable = true;
    if (flags & 1) {
      if (flags & 2) {
        if (o + 8 > bytes.length) return null;
        X = dv.getInt32(o); Z = dv.getInt32(o + 4); o += 8;
      } else {
        if (o + 4 > bytes.length) return null;
        if (k) { X = k.X + dv.getInt16(o); Z = k.Z + dv.getInt16(o + 2); } else usable = false;
        o += 4;
      }
    } else if (!k) usable = false;
    if (flags & 4) { if (o + 1 > bytes.length) return null; A = dv.getUint8(o); o += 1; }
    if (flags & 8) { if (o + 1 > bytes.length) return null; N = dv.getUint8(o); o += 1; }
    if (!usable) continue;
    known.set(id, { X, Z, A, N });
    items.push({ id, X, Z, A, N });
  }
  if (o !== bytes.length) return null;
  return { full: (dv.getUint8(1) & 1) === 1, tick: dv.getUint16(2), items };
}
