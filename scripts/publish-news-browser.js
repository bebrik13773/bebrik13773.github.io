const { chromium } = require('playwright');
const { execFileSync } = require('node:child_process');

const required = ['NEWS_SITE_BASE_URL', 'NEWS_ADMIN_PASSWORD', 'BOBER_AI_API_KEY'];
for (const name of required) {
  if (!process.env[name]) throw new Error(`Не задана переменная ${name}`);
}

const baseUrl = process.env.NEWS_SITE_BASE_URL.replace(/\/+$/, '');
const lookbackHours = Number.parseInt(process.env.NEWS_LOOKBACK_HOURS || '24', 10);
const model = process.env.BOBER_AI_MODEL || 'deepseek/deepseek-v4-flash-0731';
const aiBaseUrl = (process.env.BOBER_AI_BASE_URL || 'https://routerai.ru/api/v1').replace(/\/+$/, '');
const maxTokens = 6000;

function log(message) { console.error(`[publish-news-browser] ${message}`); }

function getCommits() {
  const repo = process.env.NEWS_REPO_PATH || process.cwd();
  const output = execFileSync('git', [
    '-C', repo, 'log', `--since=-${lookbackHours} hours`,
    '--pretty=format:%h|%s', '--no-merges',
  ], { encoding: 'utf8' });
  return output.split('\n').map(line => line.trim()).filter(Boolean).map(line => {
    const [hash, ...subject] = line.split('|');
    return { hash, subject: subject.join('|') };
  });
}

async function generateNews(commits) {
  const system = `Ты — редактор новостей игры «Бобёр Кликер 2». По списку технических коммитов напиши одну дружелюбную новость для игроков без технического жаргона. Если ничего интересного нет, верни skip=true. Верни только валидный JSON: {"skip":false,"title":"до 60 символов","body_game":"обычный Markdown","body_telegram":"Telegram MarkdownV2"}.`;
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
        { role: 'user', content: `Коммиты за последние ${lookbackHours} часов:\n${commits.map(c => `- ${c.subject} (${c.hash})`).join('\n')}` },
      ],
      max_tokens: maxTokens,
      temperature: 0.7,
    }),
  });
  const envelope = await response.json();
  if (!response.ok) throw new Error(`AI HTTP ${response.status}: ${JSON.stringify(envelope)}`);
  let content = envelope.choices?.[0]?.message?.content?.trim();
  if (!content) throw new Error('AI вернул пустой ответ');
  content = content.replace(/^```(?:json)?\s*/i, '').replace(/\s*```$/, '');
  return JSON.parse(content);
}

async function publishInBrowser(news) {
  const browser = await chromium.launch({ headless: process.env.NEWS_BROWSER_HEADLESS !== 'false' });
  const context = await browser.newContext({
    userAgent: 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/131 Safari/537.36',
  });
  const page = await context.newPage();
  page.on('console', message => log(`browser: ${message.text()}`));

  // Выполняем JavaScript сайта и ждём легитимного challenge.
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
  if (!process.env.BOBER_TG_BOT_TOKEN || !process.env.BOBER_TG_CHAT_ID || !news.body_telegram) return;
  const response = await fetch(`https://api.telegram.org/bot${process.env.BOBER_TG_BOT_TOKEN}/sendMessage`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      chat_id: process.env.BOBER_TG_CHAT_ID,
      text: `*${news.title}*\n\n${news.body_telegram}`,
      parse_mode: 'MarkdownV2',
      disable_web_page_preview: true,
    }),
  });
  if (!response.ok) log(`Telegram HTTP ${response.status}: ${await response.text()}`);
}

(async () => {
  const commits = getCommits();
  if (!commits.length) return log('Коммитов за период нет — завершаюсь.');
  const news = await generateNews(commits);
  if (news.skip) return log('AI решил пропустить новость.');
  if (!news.title || !news.body_game) throw new Error('AI вернул неполный ответ');
  log(`Модель: ${model}; max_tokens: ${maxTokens}`);
  const published = await publishInBrowser(news);
  log(`Новость опубликована в игре, id=${published.announcement?.id || 0}`);
  await postTelegram(news);
})().catch(error => { log(`ОШИБКА: ${error.stack || error.message}`); process.exit(1); });
