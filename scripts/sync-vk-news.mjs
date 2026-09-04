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

   Токен НИКОГДА не хранится в коде — только в GitHub Secrets
   (см. README.md, раздел «Настройка VK API»).
   ========================================================================== */

import { writeFile, readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const __dirname = dirname(fileURLToPath(import.meta.url));
const OUTPUT = resolve(__dirname, '../data/news.json');

/* --- настройки ----------------------------------------------------------- */
const GROUP_DOMAIN = process.env.VK_GROUP_DOMAIN || 'vrooofb'; // короткий адрес группы
const POST_COUNT   = Number(process.env.VK_POST_COUNT || 30);  // сколько записей хранить
const API_VERSION  = '5.199';
const TOKEN        = process.env.VK_API_TOKEN;

if (!TOKEN) {
  console.error('✖ Не задана переменная окружения VK_API_TOKEN.');
  console.error('  Локально:  VK_API_TOKEN=ваш_токен node scripts/sync-vk-news.mjs');
  console.error('  В GitHub:  Settings → Secrets and variables → Actions → New repository secret');
  process.exit(1);
}

/* --- вспомогательные функции --------------------------------------------- */

/** Убирает служебную разметку ВКонтакте: [club1|Название] → Название */
function cleanText(raw = '') {
  return raw
    .replace(/\[(?:id|club|public|event)-?\d+\|([^\]]+)\]/g, '$1')
    .replace(/&quot;/g, '«')
    .replace(/&amp;/g, '&')
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    .replace(/\r\n/g, '\n')
    .replace(/\n{3,}/g, '\n\n')
    .trim();
}

/** Заголовок = первая осмысленная строка или первое предложение, до 110 символов */
function makeTitle(text) {
  if (!text) return 'Публикация федерации';
  const firstLine = text.split('\n').map((s) => s.trim()).find((s) => s.length > 0) || text;
  const base = firstLine.length > 130 ? (firstLine.split(/(?<=[.!?])\s/)[0] || firstLine) : firstLine;
  const clean = base.replace(/^[#•\-–—\s]+/, '').trim();
  return clean.length > 110 ? `${clean.slice(0, 107).trimEnd()}…` : clean || 'Публикация федерации';
}

function makeExcerpt(text, title) {
  if (!text) return '';
  const rest = text.startsWith(title.replace(/…$/, '')) ? text.slice(title.replace(/…$/, '').length) : text;
  const flat = rest.replace(/\s+/g, ' ').trim() || text.replace(/\s+/g, ' ').trim();
  return flat.length > 180 ? `${flat.slice(0, 177).trimEnd()}…` : flat;
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
      5:  'Токен недействителен или отозван — создайте ключ доступа сообщества заново.',
      15: 'Нет доступа к стене. Проверьте, что ключ создан с правом «Стена» (wall) и группа открыта.',
      27: 'Ключ доступа сообщества не подходит для этого запроса.',
      29: 'Превышен лимит запросов — уменьшите частоту синхронизации.',
      100:'Проверьте короткий адрес группы в переменной VK_GROUP_DOMAIN.',
    };
    throw new Error(`VK API ошибка ${code}: ${msg}${hints[code] ? `\n  → ${hints[code]}` : ''}`);
  }
  return json.response;
}

/* --- основной сценарий ---------------------------------------------------- */
async function main() {
  console.log(`→ Читаю стену сообщества vk.com/${GROUP_DOMAIN} …`);
  const response = await fetchWall();
  const posts = response.items ?? [];
  console.log(`  получено записей: ${posts.length} (всего в сообществе: ${response.count ?? '?'})`);

  const items = posts
    .filter((p) => !p.marked_as_ads)
    .filter((p) => p.post_type === 'post')
    .map((post) => {
      const sourceText = post.text?.trim() ? post.text : (post.copy_history?.[0]?.text ?? '');
      const text = cleanText(sourceText);
      const title = makeTitle(text);
      const photos = collectPhotos(post);
      return {
        id: post.id,
        date: new Date(post.date * 1000).toISOString(),
        url: `https://vk.com/wall${post.owner_id}_${post.id}`,
        title,
        text,
        excerpt: makeExcerpt(text, title),
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

main().catch((err) => {
  console.error('✖ Синхронизация не удалась.');
  console.error(String(err.message || err));
  process.exit(1);
});
