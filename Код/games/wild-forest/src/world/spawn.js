import { BIOME } from '../shared/worldgen.js';

// Временная точка появления героя до серверного спавна (ДЛ-10): первый подходящий лес на удалении 700..1500 м от города.
// Подходящий = хвойный, лиственный или берёзовый лес без воды и скал в радиусе 24 м.
const FOREST = new Set([BIOME.CONIFER, BIOME.BROADLEAF, BIOME.BIRCH]);

export function findDemoSpawn(wg) {
    for (let k = 0; k < 600; k += 1) {
        const angle = k * 2.399963; // золотой угол: точки равномерно расходятся по кругу
        const r = 700 + (k % 40) * 20;
        const x = Math.round(Math.cos(angle) * r);
        const z = Math.round(Math.sin(angle) * r);
        if (!FOREST.has(wg.biomeAt(x, z))) continue;
        let ok = true;
        for (let i = 0; i < 8 && ok; i += 1) {
            const a = (i / 8) * Math.PI * 2;
            if (!FOREST.has(wg.biomeAt(x + Math.cos(a) * 24, z + Math.sin(a) * 24))) ok = false;
        }
        if (ok) return { x, z };
    }
    return { x: 800, z: 0 };
}
