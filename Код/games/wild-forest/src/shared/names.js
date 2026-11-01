// Генератор имён из двух слов (ДЛ-15): «Тихая Заводь», «Рыжий Хвост». Прилагательное согласуется с родом существительного.
// Зеркало на PHP: api/forest/lib/names.php. Словари: names.json (общие). Выбор слов целочисленным хешем из rng.js,
// поэтому имя для (вид, seed, попытка) одинаково в JS и PHP (проверяют векторы tests/vectors/names.json).
import { hash } from './rng.js';

export const NAME_KINDS = Object.freeze({ village: 11, character: 22, mentor: 33 });
export const NAME_MAX_LEN = 24;
const GENDER = { m: 0, f: 1, n: 2 };
const TRIES = 16; // сколько раз пересобираем пару, если она попала в чёрный список

/** Собирает генератор по данным names.json. */
export function createNameGen(data) {
    const bad = (data.blacklist || []).map((s) => s.toLowerCase());
    const badPairs = new Set(data.bad_pairs || []);

    function pair(kind, seed, attempt, t) {
        const dict = data.kinds[kind];
        const salt = NAME_KINDS[kind];
        const a = attempt * TRIES + t;
        const adj = dict.adj[hash(seed >>> 0, a, 1, salt) % dict.adj.length];
        const noun = dict.noun[hash(seed >>> 0, a, 2, salt) % dict.noun.length];
        return { adj, noun, text: `${adj[GENDER[noun[1]]]} ${noun[0]}` };
    }

    function allowed(text) {
        const low = text.toLowerCase();
        return text.length <= NAME_MAX_LEN && !badPairs.has(text) && !bad.some((s) => low.includes(s));
    }

    return {
        /** Имя по виду ('village' | 'character' | 'mentor'), seed (целое >= 0) и номеру попытки (для уникальности). */
        make(kind, seed, attempt = 0) {
            if (!data.kinds[kind]) throw new Error(`нет словаря ${kind}`);
            let p = null;
            for (let t = 0; t < TRIES; t += 1) {
                p = pair(kind, seed, attempt, t);
                if (allowed(p.text)) return p.text;
            }
            return p.text;
        },
        /** Первое имя, которого нет в наборе занятых (Set строк). Если все попытки заняты, добавляет номер. */
        unique(kind, seed, taken, maxAttempts = 50) {
            let last = '';
            for (let i = 0; i < maxAttempts; i += 1) {
                last = this.make(kind, seed, i);
                if (!taken.has(last)) return last;
            }
            return `${last} ${maxAttempts + 1}`;
        },
        size(kind) {
            const d = data.kinds[kind];
            return d.adj.length * d.noun.length;
        },
    };
}

/** Загрузка names.json для браузера (рядом с species.json). */
export async function loadNameGen(fetchImpl = fetch) {
    const res = await fetchImpl(new URL('./names.json', import.meta.url).href, { cache: 'no-cache' });
    if (!res.ok) throw new Error(`не загрузился names.json: HTTP ${res.status}`);
    return createNameGen(await res.json());
}
