// Загрузчик генератора мира для клиента (ДЛ-05). Данные пород и биомов лежат в JSON, общих с сервером (PHP читает те же файлы),
// поэтому загружаем их через fetch, а не дублируем в коде. import ... with { type: 'json' } не используем: нет в старых WebView.
import { createWorldGen } from '../shared/worldgen.js';
import { WORLD_SEED } from '../config.js';

const SHARED_URL = new URL('../shared/', import.meta.url);

async function fetchJson(name, fetchImpl) {
    const res = await fetchImpl(new URL(name, SHARED_URL).href, { cache: 'no-cache' });
    if (!res.ok) throw new Error(`не загрузился ${name}: HTTP ${res.status}`);
    return res.json();
}

/** Загружает species.json и biomes.json и создаёт генератор. seed берётся из config.js (совпадает с сервером). */
export async function loadWorldGen(seed = WORLD_SEED, fetchImpl = fetch) {
    const [species, biomes] = await Promise.all([fetchJson('species.json', fetchImpl), fetchJson('biomes.json', fetchImpl)]);
    return createWorldGen({ seed, species, biomes });
}
