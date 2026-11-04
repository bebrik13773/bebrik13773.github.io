// Проверка места для призрака постройки на клиенте (ДЛ-16): те же правила, что у сервера (shared/build.js), но по тому, что видно на экране.
// Решает всё равно сервер; здесь только подсветка зелёным/красным. Зависимости передаются снаружи, поэтому проверяется в node.
import { BUILD_RULES, circlesOverlap, slopeDeg, samplePoints, placeError } from '../shared/build.js';

/**
 * deps: { rules, village: {x,z}, level, buildings: [{x,z,r}], fixed: [[x,z,r]], heightAt(x,z), isWater(x,z), nearObstacles(x,z,range,cb), insideWorld?(x,z) }
 * nearObstacles отдаёт препятствия { x, z, r, kind } рядом (деревья, пни, камни, пруд): пни на клиенте не мешают (как на сервере).
 * Возвращает { ok, reason|null, slope }.
 */
export function checkSpot(spec, x, z, counts, deps) {
    const rules = deps.rules || BUILD_RULES;
    const r = spec.r;
    const heights = samplePoints(x, z, r).map(([px, pz]) => deps.heightAt(px, pz));
    const water = samplePoints(x, z, r).some(([px, pz]) => deps.isWater(px, pz));
    let trees = 0;
    deps.nearObstacles(x, z, r + 2, (o) => {
        if (o.kind === 'stump' || o.kind === 'blocker') return;
        if (Math.hypot(o.x - x, o.z - z) < r + (o.kind === 'tree' ? rules.trunk : 0.5)) trees += 1;
    });
    let overlap = false;
    for (const f of deps.fixed || []) if (circlesOverlap(x, z, r, f[0], f[1], f[2], rules.gap)) overlap = true;
    for (const b of deps.buildings || []) if (circlesOverlap(x, z, r, b.x, b.z, b.r, rules.gap)) overlap = true;
    const slope = slopeDeg(heights, r);
    const reason = placeError({
        level: deps.level, unlock: spec.unlock, total: counts.total, max_total: rules.max_total, same: counts.same, max_same: spec.max,
        inside_world: deps.insideWorld ? deps.insideWorld(x, z) : true, city: false,
        dist_village: Math.hypot(x - deps.village.x, z - deps.village.z), foreign_dist: Infinity,
        water, slope_deg: slope, trees, overlap,
    }, rules);
    return { ok: reason === null, reason, slope };
}
