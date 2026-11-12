// Сетка интереса (РТ-03): игроки лежат в ячейках 128 м, сосед = игрок из 3×3 ячеек вокруг, берём ближайших.
// Позиции целые, в дециметрах (как в кадрах). Без Node-API.
export class Grid {
  /** @param {number} cellM размер ячейки, м */
  constructor(cellM = 128) {
    this.cell = Math.round(cellM * 10); // в дециметрах
    this.items = new Map(); // id → { X, Z, key }
    this.cells = new Map(); // key → Set(id)
  }

  key(X, Z) {
    return (Math.floor(X / this.cell) + 32768) * 65536 + (Math.floor(Z / this.cell) + 32768);
  }

  /** Поставить или переместить. Возвращает true, если ячейка сменилась. */
  set(id, X, Z) {
    const key = this.key(X, Z);
    const it = this.items.get(id);
    if (it) {
      it.X = X; it.Z = Z;
      if (it.key === key) return false;
      this._cellRemove(it.key, id);
      it.key = key;
      this._cellAdd(key, id);
      return true;
    }
    this.items.set(id, { X, Z, key });
    this._cellAdd(key, id);
    return true;
  }

  remove(id) {
    const it = this.items.get(id);
    if (!it) return;
    this._cellRemove(it.key, id);
    this.items.delete(id);
  }

  _cellAdd(key, id) {
    let s = this.cells.get(key);
    if (!s) this.cells.set(key, (s = new Set()));
    s.add(id);
  }

  _cellRemove(key, id) {
    const s = this.cells.get(key);
    if (!s) return;
    s.delete(id);
    if (s.size === 0) this.cells.delete(key);
  }

  /** id игроков из 3×3 ячеек вокруг точки (без сортировки). */
  around(X, Z, excludeId = -1) {
    const cx = Math.floor(X / this.cell);
    const cz = Math.floor(Z / this.cell);
    const out = [];
    for (let dx = -1; dx <= 1; dx++) {
      for (let dz = -1; dz <= 1; dz++) {
        const s = this.cells.get((cx + dx + 32768) * 65536 + (cz + dz + 32768));
        if (!s) continue;
        for (const id of s) if (id !== excludeId) out.push(id);
      }
    }
    return out;
  }

  /** Ближайшие к игроку id (до max штук, от ближнего к дальнему), сам игрок не входит. */
  near(id, max = 20) {
    const me = this.items.get(id);
    if (!me) return [];
    const ids = this.around(me.X, me.Z, id);
    if (ids.length <= 1) return ids;
    const d2 = (o) => {
      const it = this.items.get(o);
      const dx = it.X - me.X;
      const dz = it.Z - me.Z;
      return dx * dx + dz * dz;
    };
    const scored = ids.map((o) => [d2(o), o]);
    scored.sort((a, b) => a[0] - b[0] || a[1] - b[1]);
    return scored.slice(0, max).map((r) => r[1]);
  }

  get size() { return this.items.size; }
  get cellCount() { return this.cells.size; }
}
