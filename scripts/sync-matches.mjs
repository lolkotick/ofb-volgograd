#!/usr/bin/env node
/* =============================================================================
   Синхронизация расписания → data/matches.auto.json

   Источники (открытые, ключи не нужны):
     • МЛБЛ-Волгоград — volgograd.ilovebasket.ru. Данные отдаёт Infobasket:
       турниры текущего сезона берём со страницы лиги, матчи — методом
       /Comp/GetCalendar/?comps=…
     • АСБ (Ассоциация студенческого баскетбола), региональный дивизион
       «Волгоградская область», мужчины и женщины — asbasket.ru.
       Данные отдаёт sportoteka: /api/abc/comps/calendar?tag=…&season=…

   Ручной календарь data/matches.json скрипт НЕ трогает: сайт склеивает оба
   файла в src/lib/data.js, при совпадении кода матча побеждает ручная запись.

   Правила безопасности данных:
     • если источник недоступен или ответил ерундой — матчи этого источника
       остаются из прошлого файла, сайт не теряет календарь;
     • файл перезаписывается только при реальных изменениях, поэтому
       расписание GitHub Actions не плодит пустые коммиты и пересборки.

   Запуск вручную:            node scripts/sync-matches.mjs
   Запуск по расписанию:      .github/workflows/sync-matches.yml
   ========================================================================== */

import { writeFile, readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const __dirname = dirname(fileURLToPath(import.meta.url));
const OUTPUT = resolve(__dirname, '../data/matches.auto.json');

/* --- настройки ----------------------------------------------------------- */
const MLBL_SITE = 'https://volgograd.ilovebasket.ru';
const MLBL_API_DEFAULT = 'https://reg.infobasket.su';
/* Запасной список турниров МЛБЛ, если со страницы лиги их достать не удалось.
   Можно задать переменной окружения: MLBL_COMPS=153462,153463 */
const MLBL_COMPS_FALLBACK = (process.env.MLBL_COMPS || '')
  .split(',').map((s) => s.trim()).filter(Boolean);

const ASB_API = 'https://asb.sportoteka.org/api/abc/comps/calendar';
const ASB_TAGS = ['asb-ufo-34-m', 'asb-ufo-34-w']; // мужчины, женщины
const ASB_PAGE = 100;

/* Если источник раньше давал столько матчей, а теперь вдруг ноль —
   скорее сбой, чем отмена всего сезона: оставляем прежние данные. */
const SUSPICIOUS_DROP = 5;

/** Предупреждение в консоль; в GitHub Actions — ещё и жёлтая пометка в сводке
 *  запуска, чтобы сбой источника было видно без чтения всего лога. */
function warn(message) {
  console.warn(message);
  if (process.env.GITHUB_ACTIONS) console.log(`::warning::${message}`);
}

/* --- сеть ---------------------------------------------------------------- */
async function get(url, { json = true } = {}) {
  const res = await fetch(url, {
    headers: { 'User-Agent': 'basket34.ru calendar sync (+https://basket34.ru)' },
    signal: AbortSignal.timeout(30_000),
  });
  if (!res.ok) throw new Error(`HTTP ${res.status} — ${url}`);
  const text = await res.text();
  if (!json) return text;
  try {
    return JSON.parse(text);
  } catch {
    throw new Error(`ответ не JSON — ${url}`);
  }
}

/* --- общее --------------------------------------------------------------- */

/** «19.10.2026» → «2026-10-19». */
function isoDate(ddmmyyyy) {
  const m = String(ddmmyyyy ?? '').match(/^(\d{2})\.(\d{2})\.(\d{4})$/);
  return m ? `${m[3]}-${m[2]}-${m[1]}` : null;
}

function clean(value) {
  return String(value ?? '').replace(/\s+/g, ' ').trim();
}

/** Сезон АСБ называется по году окончания: 2026/27 → 2027. */
export function asbSeason(now = new Date()) {
  return now.getMonth() >= 6 ? now.getFullYear() + 1 : now.getFullYear();
}

/* --- МЛБЛ ---------------------------------------------------------------- */

/** Турниры текущего сезона — ссылки вида /competitions/153462?apiUrl=… */
async function mlblComps() {
  try {
    const html = await get(MLBL_SITE, { json: false });
    const found = new Map();
    const re = /\/competitions\/(\d+)\?apiUrl=([^"'&\s]+)/g;
    let m;
    while ((m = re.exec(html)) !== null) {
      found.set(m[1], decodeURIComponent(m[2]).replace(/\/+$/, ''));
    }
    if (found.size > 0) return [...found].map(([id, api]) => ({ id, api }));
    warn('  ! на странице МЛБЛ не нашлось ссылок на турниры');
  } catch (e) {
    warn(`  ! страница МЛБЛ недоступна: ${e.message}`);
  }
  return MLBL_COMPS_FALLBACK.map((id) => ({ id, api: MLBL_API_DEFAULT }));
}

export function normalizeMlbl(g) {
  const date = isoDate(g.GameDate);
  if (!g.GameID || !date) return null;
  /* 0 — предстоит, 1 — сыгран. Других статусов (онлайн, перенос)
     не угадываем: такой матч просто не считается сыгранным. */
  const finished = g.GameStatus === 1;
  const league = [clean(g.LeagueNameRu), clean(g.CompNameRu)].filter(Boolean).join(', ');
  return {
    id: `mlbl-${g.GameID}`,
    date,
    time: g.HasTime && /^\d{1,2}:\d{2}$/.test(g.GameTime ?? '') ? g.GameTime : '',
    league: `МЛБЛ — ${league || 'Волгоград'}`,
    team1: clean(g.ShortTeamNameAru || g.CompTeamNameAru),
    team2: clean(g.ShortTeamNameBru || g.CompTeamNameBru),
    venue: clean(g.ArenaRu),
    city: clean(g.RegionRu) || 'Волгоград',
    home: true,
    status: finished ? 'finished' : 'upcoming',
    score: finished ? `${g.ScoreA}:${g.ScoreB}` : '',
    source: 'mlbl',
    source_url: `${MLBL_SITE}/games/${g.GameID}?apiUrl=${MLBL_API_DEFAULT}`,
  };
}

async function fetchMlbl() {
  const comps = await mlblComps();
  if (comps.length === 0) throw new Error('не удалось определить турниры МЛБЛ');
  const out = [];
  for (const { id, api } of comps) {
    const games = await get(`${api}/Comp/GetCalendar/?comps=${id}&format=json`);
    if (!Array.isArray(games)) throw new Error(`турнир ${id}: неожиданный ответ`);
    out.push(...games.map(normalizeMlbl).filter(Boolean));
  }
  return out;
}

/* --- АСБ ----------------------------------------------------------------- */

export function normalizeAsb(item) {
  const g = item.game ?? {};
  const date = isoDate(g.localDate);
  if (!g.id || !date) return null;
  const finished = /^Result/.test(g.gameStatus ?? '');
  const leagueName = clean(item.league?.name).replace(/\s*\((м|ж)\)\s*$/, '');
  const gender = item.gender === 2 || / \(ж\)$/.test(item.league?.name ?? '') ? 'женщины' : 'мужчины';
  return {
    id: `asb-${g.id}`,
    date,
    time: g.hasTime && /^\d{1,2}:\d{2}$/.test(g.localTime ?? '') ? g.localTime : '',
    league: `АСБ — ${leagueName || 'Волгоградская область'}, ${gender}`,
    team1: clean(item.team1?.shortName || item.team1?.name),
    team2: clean(item.team2?.shortName || item.team2?.name),
    venue: clean(item.arena?.name),
    city: clean(item.region?.name) || 'Волгоград',
    home: true,
    status: finished ? 'finished' : 'upcoming',
    score: finished && g.showScore && g.score ? g.score : '',
    source: 'asb',
    source_url: `https://asbasket.ru/game/${g.id}`,
  };
}

async function fetchAsb() {
  /* ASB_SEASON=2026 — посмотреть прошлый сезон при отладке. */
  const season = Number(process.env.ASB_SEASON) || asbSeason();
  const out = [];
  for (const tag of ASB_TAGS) {
    for (let skip = 0; ; skip += ASB_PAGE) {
      const q = new URLSearchParams({
        tag, season: String(season), lang: 'ru',
        skipCount: String(skip), maxResultCount: String(ASB_PAGE),
      });
      const data = await get(`${ASB_API}?${q}`);
      if (data?.status !== 'Ok' || !Array.isArray(data.items)) {
        throw new Error(`${tag}: неожиданный ответ`);
      }
      out.push(...data.items.map(normalizeAsb).filter(Boolean));
      if (data.items.length < ASB_PAGE || skip + ASB_PAGE >= (data.totalCount ?? 0)) break;
    }
  }
  return out;
}

/* --- сборка файла -------------------------------------------------------- */
const SOURCES = [
  { key: 'mlbl', title: 'МЛБЛ-Волгоград', run: fetchMlbl },
  { key: 'asb', title: 'АСБ', run: fetchAsb },
];

async function readPrevious() {
  try {
    const data = JSON.parse(await readFile(OUTPUT, 'utf8'));
    return Array.isArray(data.matches) ? data.matches : [];
  } catch {
    return [];
  }
}

async function main() {
  const previous = await readPrevious();
  const result = [];

  for (const src of SOURCES) {
    const before = previous.filter((m) => m.source === src.key);
    try {
      const fresh = await src.run();
      if (fresh.length === 0 && before.length >= SUSPICIOUS_DROP) {
        warn(`! ${src.title}: пришло 0 матчей вместо ${before.length} — оставляю прежние`);
        result.push(...before);
      } else {
        console.log(`✔ ${src.title}: ${fresh.length} матч(ей)`);
        result.push(...fresh);
      }
    } catch (e) {
      warn(`! ${src.title}: ${e.message} — оставляю прежние (${before.length})`);
      result.push(...before);
    }
  }

  /* Стабильный порядок — чтобы одинаковые данные давали одинаковый файл. */
  const unique = [...new Map(result.map((m) => [m.id, m])).values()];
  unique.sort((a, b) =>
    `${a.date} ${a.time} ${a.id}`.localeCompare(`${b.date} ${b.time} ${b.id}`));

  const next = `${JSON.stringify({ matches: unique }, null, 2)}\n`;
  let current = '';
  try { current = await readFile(OUTPUT, 'utf8'); } catch { /* файла ещё нет */ }

  if (current === next) {
    console.log('Расписание без изменений — файл не перезаписан.');
    return;
  }
  await writeFile(OUTPUT, next, 'utf8');
  console.log(`Записано: data/matches.auto.json (${unique.length} матч(ей)).`);
}

main().catch((e) => {
  /* Даже при непредвиденной ошибке не роняем расписание GitHub Actions:
     прежний файл остаётся на месте. */
  warn(`✖ ${e?.stack || e}`);
});
