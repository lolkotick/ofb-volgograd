#!/usr/bin/env node
/* =============================================================================
   Синхронизация новостей с ВКонтакте → data/news.json

   Что делает:
     1. вызывает метод VK API wall.get для группы федерации;
     2. отбирает обычные записи (без рекламы и служебных постов);
     3. вытаскивает текст, дату, обложку и фотографии;
     4. полностью перезаписывает файл data/news.json.

   Запуск вручную:
     VK_API_TOKEN=токен node scripts/sync-vk-news.mjs
   Запуск по расписанию: .github/workflows/sync-news.yml

   ВАЖНО про ключ: методу wall.get нужен СЕРВИСНЫЙ ключ доступа приложения VK.
   Ключ доступа сообщества не подходит — ВКонтакте отдаёт ошибку 27
   «method is unavailable with group auth».
   Ключ НИКОГДА не хранится в коде — только в GitHub Secrets
   (см. README.md, раздел «Настройка VK API»).
   ========================================================================== */

import { writeFile, readFile } from 'node:fs/promises';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { dirname, resolve } from 'node:path';

const __dirname = dirname(fileURLToPath(import.meta.url));
const OUTPUT = resolve(__dirname, '../data/news.json');

/* --- настройки ----------------------------------------------------------- */
const GROUP_DOMAIN = process.env.VK_GROUP_DOMAIN || 'vrooofb'; // короткий адрес группы
const POST_COUNT   = Number(process.env.VK_POST_COUNT || 30);  // сколько записей хранить
const API_VERSION  = '5.199';
const TOKEN        = process.env.VK_API_TOKEN;

/** Проверяем ключ не при импорте, а при запуске: так функции разбора текста
 *  можно импортировать в тестах без настоящего ключа. */
function requireToken() {
  if (TOKEN) return;
  console.error('✖ Не задана переменная окружения VK_API_TOKEN.');
  console.error('  Нужен СЕРВИСНЫЙ ключ доступа приложения VK (не ключ сообщества!).');
  console.error('  Локально:  VK_API_TOKEN=ваш_ключ node scripts/sync-vk-news.mjs');
  console.error('  В GitHub:  Settings → Secrets and variables → Actions → New repository secret');
  process.exit(1);
}

/* --- вспомогательные функции --------------------------------------------- */

/** Достаёт ссылки вида [https://vk.ru/album-1_2|Награждение] ДО очистки текста,
 *  чтобы показать их на странице новости отдельным списком. */
export function collectLinks(raw = '') {
  const links = [];
  const seen = new Set();
  const re = /\[((?:https?:\/\/|www\.)[^\]|]+)\|([^\]]+)\]/g;
  let m;
  while ((m = re.exec(raw)) !== null) {
    const url = m[1].startsWith('http') ? m[1] : `https://${m[1]}`;
    const label = m[2].trim();
    if (!seen.has(url)) {
      seen.add(url);
      links.push({ url, label: label || url });
    }
  }
  return links;
}

/** Убирает служебную разметку ВКонтакте.
 *  [club1|Название] и [https://…|Подпись] превращаются в подпись:
 *  иначе на сайте видны сырые скобки со ссылками. */
export function cleanText(raw = '') {
  return raw
    .replace(/\[[^\]|]+\|([^\]]+)\]/g, '$1')
    .replace(/(#[^\s#@]+)@[\w.]+/g, '$1')
    .replace(/&quot;/g, '"')
    .replace(/&amp;/g, '&')
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    .replace(/\r\n/g, '\n')
    .replace(/[ \t]+\n/g, '\n')
    .replace(/\n{3,}/g, '\n\n')
    .trim();
}

const TITLE_MAX = 95;
const EXCERPT_MAX = 180;

/** Обрезает по границе слова, а не по символу */
function clip(value, max) {
  if (value.length <= max) return value;
  const cut = value.slice(0, max);
  const space = cut.lastIndexOf(' ');
  return `${(space > max * 0.5 ? cut.slice(0, space) : cut).trimEnd()}…`;
}

/** Заголовок берём из первой строки поста.
 *  Возвращаем и признак обрезки: от него зависит, как строить превью. */
export function makeTitle(text) {
  if (!text) return { title: 'Публикация федерации', truncated: false };
  const firstLine = text.split('\n').map((s) => s.trim()).find((s) => s.length > 0) || text;
  const clean = firstLine.replace(/^[#•\-–—\s]+/, '').replace(/\s+/g, ' ').trim();
  if (!clean) return { title: 'Публикация федерации', truncated: false };
  if (clean.length <= TITLE_MAX) return { title: clean, truncated: false };

  // если в начале есть законченное предложение подходящей длины — берём его целиком
  const sentence = clean.match(/^.{30,95}?[.!?…](?:\s|$)/);
  if (sentence) return { title: sentence[0].trim(), truncated: false };

  return { title: clip(clean, TITLE_MAX), truncated: true };
}

/** Превью под заголовком.
 *  Если заголовок — обрезанное начало текста, превью продолжает фразу и
 *  начинается с многоточия. Иначе показываем текст после строки-заголовка. */
export function makeExcerpt(text, title, truncated) {
  const flat = (text || '').replace(/\s+/g, ' ').trim();
  if (!flat) return '';
  // сравниваем по тексту с одинарными пробелами: в постах встречаются двойные,
  // из-за них заголовок иначе не «находится» в начале и превью дублирует его
  const base = (truncated ? title.replace(/…$/, '') : title).replace(/\s+/g, ' ').trim();
  let rest = flat.startsWith(base) ? flat.slice(base.length).trim() : '';
  if (!rest) rest = flat;
  return truncated ? `…${clip(rest, EXCERPT_MAX)}` : clip(rest, EXCERPT_MAX);
}

/** Из вложения-фотографии выбирает самый крупный размер */
function bestPhoto(photo) {
  const sizes = photo?.sizes ?? [];
  if (!sizes.length) return null;
  const best = sizes.reduce((a, b) => (b.width > a.width ? b : a));
  return { url: best.url, width: best.width, height: best.height };
}

/** Достаёт фото из вложений записи (в том числе из репоста) */
function collectPhotos(post) {
  const attachments = [
    ...(post.attachments ?? []),
    ...((post.copy_history?.[0]?.attachments) ?? []),
  ];
  return attachments
    .filter((a) => a.type === 'photo')
    .map((a) => bestPhoto(a.photo))
    .filter(Boolean);
}

function hasVideo(post) {
  const attachments = [
    ...(post.attachments ?? []),
    ...((post.copy_history?.[0]?.attachments) ?? []),
  ];
  return attachments.some((a) => a.type === 'video');
}

/* --- запрос к VK API ------------------------------------------------------ */
async function fetchWall() {
  const params = new URLSearchParams({
    domain: GROUP_DOMAIN,
    count: String(Math.min(POST_COUNT, 100)),
    filter: 'owner',            // только записи самого сообщества, без предложенных
    extended: '0',
    access_token: TOKEN,
    v: API_VERSION,
  });

  const res = await fetch(`https://api.vk.com/method/wall.get?${params}`, {
    headers: { 'User-Agent': 'ofb-volgograd-site/1.0' },
  });

  if (!res.ok) throw new Error(`HTTP ${res.status} ${res.statusText}`);
  const json = await res.json();

  if (json.error) {
    const { error_code: code, error_msg: msg } = json.error;
    const hints = {
      5:  'Ключ недействителен или отозван — создайте сервисный ключ заново и обновите секрет VK_API_TOKEN.',
      15: 'Нет доступа к стене. Сервисный ключ читает только открытые сообщества — проверьте, что группа не закрыта.',
      27: 'Похоже, в секрет положен КЛЮЧ СООБЩЕСТВА. Метод wall.get с ним не работает.\n'
        + '     Нужен СЕРВИСНЫЙ КЛЮЧ ДОСТУПА приложения: dev.vk.com → Мои приложения →\n'
        + '     создать Standalone-приложение → Настройки → «Сервисный ключ доступа».\n'
        + '     Подробнее — в README, раздел «Настройка VK API».',
      28: 'Ключ приложения не подходит для этого метода — нужен именно сервисный ключ.',
      29: 'Превышен лимит запросов — уменьшите частоту синхронизации в расписании.',
      100:'Проверьте короткий адрес группы в переменной VK_GROUP_DOMAIN (сейчас: ' + GROUP_DOMAIN + ').',
    };
    throw new Error(`VK API ошибка ${code}: ${msg}${hints[code] ? `\n  → ${hints[code]}` : ''}`);
  }
  return json.response;
}

/* --- основной сценарий ---------------------------------------------------- */
async function main() {
  requireToken();
  console.log(`→ Читаю стену сообщества vk.com/${GROUP_DOMAIN} …`);
  const response = await fetchWall();
  const posts = response.items ?? [];
  console.log(`  получено записей: ${posts.length} (всего в сообществе: ${response.count ?? '?'})`);

  const items = posts
    .filter((p) => !p.marked_as_ads)
    .filter((p) => p.post_type === 'post')
    .map((post) => {
      const sourceText = post.text?.trim() ? post.text : (post.copy_history?.[0]?.text ?? '');
      const links = collectLinks(sourceText);
      const text = cleanText(sourceText);
      const { title, truncated } = makeTitle(text);
      const photos = collectPhotos(post);
      return {
        id: post.id,
        date: new Date(post.date * 1000).toISOString(),
        url: `https://vk.com/wall${post.owner_id}_${post.id}`,
        title,
        text,
        links,
        excerpt: makeExcerpt(text, title, truncated),
        cover: photos[0] ?? null,
        photos,
        hasVideo: hasVideo(post),
        isPinned: Boolean(post.is_pinned),
      };
    })
    // записи совсем без текста и без картинок на сайте бесполезны
    .filter((item) => item.text || item.cover)
    .sort((a, b) => new Date(b.date) - new Date(a.date));

  const payload = {
    _комментарий: 'Файл создаётся автоматически скриптом scripts/sync-vk-news.mjs. Руками не редактировать.',
    generatedAt: new Date().toISOString(),
    source: `https://vk.com/${GROUP_DOMAIN}`,
    items,
  };

  // Не переписываем файл, если ничего не изменилось — чтобы не плодить пустые коммиты
  let previous = null;
  try { previous = JSON.parse(await readFile(OUTPUT, 'utf8')); } catch { /* файла ещё нет */ }
  const same = previous && JSON.stringify(previous.items) === JSON.stringify(items);
  if (same) {
    console.log('✓ Новых изменений нет — файл не тронут.');
    return;
  }

  await writeFile(OUTPUT, `${JSON.stringify(payload, null, 2)}\n`, 'utf8');
  console.log(`✓ Сохранено новостей: ${items.length} → data/news.json`);
}

const runDirectly = process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href;
if (runDirectly) {
  main().catch((err) => {
    console.error('✖ Синхронизация не удалась.');
    console.error(String(err.message || err));
    process.exit(1);
  });
}
