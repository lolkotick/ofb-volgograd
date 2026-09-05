/* Прогоняет те же фикстуры через Node-функции разбора постов ВК. */
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
import { cleanText, collectLinks, makeTitle, makeExcerpt } from '../sync-vk-news.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const fixtures = JSON.parse(readFileSync(resolve(here, 'fixtures.json'), 'utf8'));

const out = fixtures.map((raw) => {
  const links = collectLinks(raw);
  const text = cleanText(raw);
  const { title, truncated } = makeTitle(text);
  return { links, text, title, truncated, excerpt: makeExcerpt(text, title, truncated) };
});
console.log(JSON.stringify(out, null, 2));
