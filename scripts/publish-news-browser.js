const { chromium } = require('playwright');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');

/**
 * Автопост новостей "Бобёр Кликер 2".
 *
 * Раз в день (по расписанию) или вручную (workflow_dispatch):
 *  1) собирает коммиты за последние N часов — тему И тело коммита, а также
 *     реальный diff/stat по каждому (не только заголовок сообщения — чтобы
 *     видеть, что фактически изменилось в коде, даже если коммит-сообщение
 *     скупое);
 *  2) передаёт всё это + game-knowledge.md (знания об игре для ИИ Бобра)
 *     в RouterAI/DeepSeek с промптом, который просит:
 *       - писать только о РЕАЛЬНЫХ улучшениях для игроков (не техдолг/рефактор/
 *         CI/чистка репо) — если таких нет, публикация пропускается целиком;
 *       - дружеский тон, эмодзи, кратко, без воды, структурировано списком;
 *       - только ОДИН текст в обычном Markdown (title + body_game);
 *     Telegram-версия (MarkdownV2) генерируется программно — конвертером
 *     convertMarkdownToTelegram() ниже, а не самой моделью: попытка заставить
 *     модель одновременно писать валидный JSON И правильно экранированный
 *     MarkdownV2 оказалась ненадёжной (модель либо забывала экранировать,
 *     либо ломала валидность самого JSON-ответа лишними escape-слэшами);
 *  3) публикует в игровую ленту новостей через api/announcements/update.php
 *     (через настоящий браузер Playwright — сайт использует anti-bot защиту,
 *     обычный curl/fetch с раннера возвращает challenge/блок);
 *  4) постит тот же повод в Telegram-канал.
 *
 * Если за период нет коммитов, ИЛИ AI решил, что ничего достойного новости
 * нет — публикация не происходит (ни в игру, ни в Telegram).
 */

const required = ['NEWS_SITE_BASE_URL', 'NEWS_ADMIN_PASSWORD', 'BOBER_AI_API_KEY'];
for (const name of required) {
  if (!process.env[name]) throw new Error(`Не задана переменная ${name}`);
}

const baseUrl = process.env.NEWS_SITE_BASE_URL.replace(/\/+$/, '');
const lookbackHours = Number.parseInt(process.env.NEWS_LOOKBACK_HOURS || '24', 10);
const model = process.env.BOBER_AI_MODEL || 'deepseek/deepseek-v4-flash-0731';
const aiBaseUrl = (process.env.BOBER_AI_BASE_URL || 'https://routerai.ru/api/v1').replace(/\/+$/, '');
const maxTokens = 6000;
// Ограничиваем суммарный объём diff'ов, который уходит в промпт — иначе на
// "жирный" день (много коммитов/большие файлы) можно улететь за лимит токенов.
const MAX_DIFF_CHARS_PER_COMMIT = 4000;
const MAX_TOTAL_DIFF_CHARS = 24000;

function log(message) { console.error(`[publish-news-browser] ${message}`); }

function git(args) {
  const repo = process.env.NEWS_REPO_PATH || process.cwd();
  return execFileSync('git', ['-C', repo, ...args], { encoding: 'utf8', maxBuffer: 1024 * 1024 * 64 });
}

/**
 * Собирает коммиты за период: хэш, тема, полное тело сообщения.
 */
function getCommits() {
  // \x1e (RS) — разделитель полей, \x1d (GS) — разделитель коммитов; так тело
  // коммита (может содержать переносы строк) не ломает парсинг.
  const output = git([
    'log', `--since=-${lookbackHours} hours`,
    '--pretty=format:%h%x1e%s%x1e%b%x1d',
    '--no-merges',
  ]);

  return output.split('\x1d').map(chunk => chunk.trim()).filter(Boolean).map(chunk => {
    const [hash, subject, body] = chunk.split('\x1e');
    return { hash, subject: (subject || '').trim(), body: (body || '').trim() };
  });
}

/**
 * Достаёт короткий diffstat + сокращённый unified diff по каждому коммиту,
 * чтобы AI видел РЕАЛЬНЫЕ изменения в коде, а не только текст сообщения
 * (сообщения коммитов часто скупые вида "fix: правки").
 */
function getCommitChanges(hash) {
  let stat = '';
  let diff = '';
  try {
    stat = git(['show', '--stat', '--format=', hash]).trim();
  } catch (error) {
    log(`Не удалось получить stat для ${hash}: ${error.message}`);
  }
  try {
    diff = git(['show', '--format=', '--unified=1', hash]).trim();
  } catch (error) {
    log(`Не удалось получить diff для ${hash}: ${error.message}`);
  }
  if (diff.length > MAX_DIFF_CHARS_PER_COMMIT) {
    diff = diff.slice(0, MAX_DIFF_CHARS_PER_COMMIT) + '\n... (diff обрезан для краткости) ...';
  }
  return { stat, diff };
}

function loadGameKnowledge() {
  const repo = process.env.NEWS_REPO_PATH || process.cwd();
  const candidatePath = path.join(repo, 'Код/api/ai-assistant/prompts/game-knowledge.md');
  try {
    return fs.readFileSync(candidatePath, 'utf8');
  } catch (error) {
    log(`Не удалось прочитать game-knowledge.md (${candidatePath}): ${error.message} — продолжаю без него.`);
    return '';
  }
}

function buildCommitsBlock(commits) {
  let totalDiffChars = 0;
  const parts = commits.map(commit => {
    const { stat, diff } = getCommitChanges(commit.hash);
    let diffSection = '';
    if (totalDiffChars < MAX_TOTAL_DIFF_CHARS) {
      const remaining = MAX_TOTAL_DIFF_CHARS - totalDiffChars;
      const trimmedDiff = diff.length > remaining ? diff.slice(0, remaining) + '\n... (обрезано) ...' : diff;
      totalDiffChars += trimmedDiff.length;
      diffSection = trimmedDiff ? `\nDiff (сокращённый):\n${trimmedDiff}` : '';
    }
    return [
      `### Коммит ${commit.hash}: ${commit.subject}`,
      commit.body ? `Полное сообщение:\n${commit.body}` : '',
      stat ? `Изменённые файлы:\n${stat}` : '',
      diffSection,
    ].filter(Boolean).join('\n');
  });
  return parts.join('\n\n');
}

async function generateNews(commits) {
  const gameKnowledge = loadGameKnowledge();
  const commitsBlock = buildCommitsBlock(commits);

  const system = `Ты — редактор новостей для игры «Бобёр Кликер 2» (маленький браузерный кликер, игроки лично знакомы с разработчиком по имени Бобёр).

Тебе дают: (1) справочник знаний об игре — термины, механики, интерфейс; (2) список коммитов за последние ${lookbackHours} ч. — тема, полное сообщение коммита, список изменённых файлов и сокращённый diff. Коммит-сообщения написаны разработчиком для себя, в техническом жаргоне (fix:/feat:/ui:/perf:/tune:, английские термины) — они НЕ предназначены для игроков напрямую. Diff и список файлов нужны тебе, чтобы понять, что РЕАЛЬНО изменилось в коде, даже если сообщение коммита скупое или неточное — используй знания об игре, чтобы понять, к какой видимой игроку фиче это относится.

## Твоя задача
Оцени: есть ли среди этих изменений хотя бы ОДНО реальное улучшение, которое игрок заметит и оценит (новая фича, видимый фикс бага, баланс, новый контент, новый скин/квест/апгрейд и т.п.)?

Пропусти (verdict "skip") всё, что является:
- чисто техническим (рефакторинг, чистка репозитория, правки CI/workflow, комментарии, переименования переменных, внутренние утилиты типа этого же новостного бота);
- невидимым игроку (изменения в промптах ИИ-ассистента, логи, админ-панель, если это не new-facing фича);
- незавершённым/экспериментальным без явной пользы для игрока прямо сейчас.

Если НИ ОДНОГО коммита не тянет на новость — верни {"verdict":"skip"} и больше ничего.
Если есть хотя бы одно достойное изменение — опиши в новости ТОЛЬКО достойные пункты, остальное молча пропусти (не пиши "а ещё было техническое обслуживание" и подобное — просто не упоминай).

## Стиль (важно, соблюдай строго)
- Дружеский, тёплый тон, как будто Бобёр сам пишет игрокам, которых знает лично.
- Кратко и по делу, БЕЗ ВОДЫ: никаких вступлений вида "Приветствуем, бобрята! Сегодня хотим рассказать о...". Сразу к сути.
- Уместные эмодзи (1 на пункт/абзац максимум, не спамить).
- Структурировано: если изменений больше одного — список пунктов, каждый с эмодзи в начале. Если изменение одно — 1-2 коротких абзаца, тоже можно с эмодзи.
- Используй ТОЛЬКО игровые термины на русском (см. справочник знаний) — никогда не пиши "рефакторинг", "API", "баг в коде", "коммит" и подобный технический жаргон. Говори по-геймерски по-человечески: "почистили", "починили", "стало быстрее", "добавили".
- НЕ упоминай хэши коммитов, имена файлов, технические детали реализации.

## Формат ответа
Верни СТРОГО валидный JSON без markdown-обёртки (без тройных кавычек), один из двух видов:

Если пропускаешь:
{"verdict":"skip"}

Если публикуешь:
{
  "verdict": "publish",
  "title": "Короткий заголовок с эмодзи, до 60 символов",
  "body_game": "Текст в обычном Markdown — см. правила ниже"
}

### Правила для body_game (обычный Markdown)
- Разрешено: **жирный**, *курсив*, зачёркнутый через ~~, списки через "- ", цитаты через "> ", разделитель "---". Не используй заголовки # — заголовок новости отдельно в поле title.
- 2-5 коротких пунктов/предложений максимум.
- Этот же текст пойдёт и в Telegram (автоматически сконвертируется в его формат) — не используй ничего, кроме перечисленной разметки.

ВАЖНО про формат JSON-ответа: заголовок и текст — это строки JSON. Не используй внутри них обратный слэш \\ ни в каком виде (никаких self-made экранирований) — если нужен обратный слэш как символ, лучше вообще его не используй. Двойные кавычки внутри текста тоже не используй — если нужна цитата, используй «ёлочки» или одинарные кавычки.

## Справочник знаний об игре
${gameKnowledge || '(файл game-knowledge.md недоступен в этом запуске)'}`;

  const response = await fetch(`${aiBaseUrl}/chat/completions`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      Authorization: `Bearer ${process.env.BOBER_AI_API_KEY}`,
    },
    body: JSON.stringify({
      model,
      messages: [
        { role: 'system', content: system },
        { role: 'user', content: `Коммиты за последние ${lookbackHours} часов:\n\n${commitsBlock}` },
      ],
      max_tokens: maxTokens,
      temperature: 0.6,
    }),
  });
  const envelope = await response.json();
  if (!response.ok) throw new Error(`AI HTTP ${response.status}: ${JSON.stringify(envelope)}`);
  let content = envelope.choices?.[0]?.message?.content?.trim();
  if (!content) throw new Error('AI вернул пустой ответ');
  content = content.replace(/^```(?:json)?\s*/i, '').replace(/\s*```$/, '');
  return parseNewsJson(content);
}

/**
 * Пытается распарсить JSON-ответ модели. Модели иногда всё же вставляют
 * "лишний" обратный слэш (невалидную escape-последовательность типа \.),
 * из-за чего строгий JSON.parse падает — прежде чем сдаться, пробуем
 * почистить такие слэши и распарсить ещё раз.
 */
function parseNewsJson(content) {
  try {
    return JSON.parse(content);
  } catch (firstError) {
    // Убираем обратный слэш перед любым символом, который не образует
    // валидную JSON-escape-последовательность (", \, /, b, f, n, r, t, u).
    const sanitized = content.replace(/\\(?!["\\/bfnrtu])/g, '');
    try {
      const parsed = JSON.parse(sanitized);
      log('Ответ AI содержал невалидные escape-последовательности — восстановлен автоматической очисткой.');
      return parsed;
    } catch (secondError) {
      throw new Error(`Не удалось распарсить JSON-ответ AI: ${firstError.message}. Сырой ответ: ${content.slice(0, 500)}`);
    }
  }
}

/**
 * Экранирует спецсимволы Telegram MarkdownV2 в куске обычного текста
 * (не самой разметки, а текста между тегами разметки).
 */
function escapeTelegramText(text) {
  return text.replace(/[_*[\]()~`>#+\-=|{}.!\\]/g, '\\$&');
}

/**
 * Конвертирует наше ограниченное подмножество обычного Markdown
 * (**жирный**, *курсив*, ~~зачёркнутый~~, "- " списки, "> " цитаты, "---")
 * в Telegram MarkdownV2, беря на себя всё экранирование программно —
 * не полагаясь на то, что модель сама правильно расставит escape-слэши
 * (это оказалось ненадёжным: модель то забывала экранировать, то ломала
 * валидность самого JSON-ответа лишними слэшами).
 */
function convertMarkdownToTelegram(markdown) {
  const lines = markdown.split('\n');
  const outputLines = lines.map(line => {
    const trimmed = line.trim();

    if (trimmed === '' || /^-{3,}$/.test(trimmed)) {
      return '';
    }

    // Списковые пункты "- текст" -> буллит "• текст"
    const bulletMatch = trimmed.match(/^-\s+(.*)$/);
    const quoteMatch = trimmed.match(/^>\s?(.*)$/);
    const prefix = bulletMatch ? '• ' : quoteMatch ? '› ' : '';
    let body = bulletMatch ? bulletMatch[1] : quoteMatch ? quoteMatch[1] : trimmed;

    // Сначала выделяем куски внутри **жирный**/*курсив*/~~зачёркнутый~~,
    // экранируем текст МЕЖДУ ними отдельно, саму разметку конвертируем
    // в телеграмный синтаксис без экранирования звёздочек/тильд разметки.
    const tokens = [];
    let rest = body;
    const pattern = /(\*\*([^*]+)\*\*|~~([^~]+)~~|\*([^*]+)\*)/;
    while (rest.length > 0) {
      const match = rest.match(pattern);
      if (!match) {
        tokens.push({ type: 'text', value: rest });
        break;
      }
      if (match.index > 0) {
        tokens.push({ type: 'text', value: rest.slice(0, match.index) });
      }
      if (match[2] !== undefined) {
        tokens.push({ type: 'bold', value: match[2] });
      } else if (match[3] !== undefined) {
        tokens.push({ type: 'strike', value: match[3] });
      } else if (match[4] !== undefined) {
        tokens.push({ type: 'italic', value: match[4] });
      }
      rest = rest.slice(match.index + match[0].length);
    }

    const rendered = tokens.map(token => {
      const escaped = escapeTelegramText(token.value);
      if (token.type === 'bold') return `*${escaped}*`;
      if (token.type === 'italic') return `_${escaped}_`;
      if (token.type === 'strike') return `~${escaped}~`;
      return escaped;
    }).join('');

    return prefix ? escapeTelegramText(prefix).replace(/\\/g, '') + rendered : rendered;
  });

  // Схлопываем повторяющиеся пустые строки, оставляя не более одной подряд.
  const collapsed = [];
  for (const line of outputLines) {
    if (line === '' && collapsed[collapsed.length - 1] === '') continue;
    collapsed.push(line);
  }
  return collapsed.join('\n').trim();
}

async function publishInBrowser(news) {
  const browser = await chromium.launch({ headless: process.env.NEWS_BROWSER_HEADLESS !== 'false' });
  const context = await browser.newContext({
    userAgent: 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/131 Safari/537.36',
  });
  const page = await context.newPage();
  page.on('console', message => log(`browser: ${message.text()}`));

  // Выполняем JavaScript сайта и ждём легитимного challenge (anti-bot).
  await page.goto(baseUrl, { waitUntil: 'domcontentloaded', timeout: 60000 });
  await page.waitForTimeout(Number.parseInt(process.env.NEWS_BROWSER_WAIT_MS || '15000', 10));

  const result = await page.evaluate(async ({ url, payload }) => {
    const response = await fetch(`${url}/api/announcements/update.php`, {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify(payload),
    });
    const text = await response.text();
    let body;
    try { body = JSON.parse(text); } catch { body = { raw: text.slice(0, 1000) }; }
    return { status: response.status, body };
  }, {
    url: baseUrl,
    payload: {
      adminPassword: process.env.NEWS_ADMIN_PASSWORD,
      title: news.title,
      body: news.body_game,
      bodyFormat: 'markdown',
      isPublished: true,
    },
  });

  await browser.close();
  if (result.status >= 400 || !result.body.success) {
    throw new Error(`Сайт отклонил публикацию (HTTP ${result.status}): ${JSON.stringify(result.body)}`);
  }
  return result.body;
}

async function postTelegram(news) {
  if (!process.env.BOBER_TG_BOT_TOKEN || !process.env.BOBER_TG_CHAT_ID) return;
  const titleEscaped = escapeTelegramText(news.title);
  const bodyTelegram = convertMarkdownToTelegram(news.body_game);
  const text = `*${titleEscaped}*\n\n${bodyTelegram}`;
  const response = await fetch(`https://api.telegram.org/bot${process.env.BOBER_TG_BOT_TOKEN}/sendMessage`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      chat_id: process.env.BOBER_TG_CHAT_ID,
      text,
      parse_mode: 'MarkdownV2',
      disable_web_page_preview: true,
    }),
  });
  if (!response.ok) log(`Telegram HTTP ${response.status}: ${await response.text()}`);
}

(async () => {
  const commits = getCommits();
  if (!commits.length) return log('Коммитов за период нет — завершаюсь.');
  log(`Найдено коммитов: ${commits.length}`);

  const news = await generateNews(commits);
  if (news.verdict === 'skip' || news.skip) {
    return log('AI решил: реальных улучшений для игроков нет — публикация пропущена (ни в игру, ни в Telegram).');
  }
  if (!news.title || !news.body_game) throw new Error('AI вернул неполный ответ (нет title/body_game)');

  log(`Модель: ${model}; заголовок: ${news.title}`);
  const published = await publishInBrowser(news);
  log(`Новость опубликована в игре, id=${published.announcement?.id || 0}`);
  await postTelegram(news);
  log('Готово.');
})().catch(error => { log(`ОШИБКА: ${error.stack || error.message}`); process.exit(1); });
