/* =============================================================================
   Единая точка доступа к данным сайта.
   Все данные лежат в обычных JSON-файлах в папке /data и правятся вручную
   (кроме news.json — его автоматически перезаписывает синхронизация с ВКонтакте).
   ========================================================================== */

import site from '../../data/site.json';
import leadership from '../../data/leadership.json';
import documentsData from '../../data/documents.json';
import matchesData from '../../data/matches.json';
import newsData from '../../data/news.json';
import newsSample from '../../data/news.sample.json';

export { site };
export const leaders = leadership.people ?? [];
export const documents = documentsData.documents ?? [];

/* --- НОВОСТИ -------------------------------------------------------------
   Источник: группа ВКонтакте федерации, метод wall.get.
   Файл data/news.json перезаписывает скрипт scripts/sync-vk-news.mjs,
   который запускается по расписанию через GitHub Actions.
   Демо-новости подставляются только при локальном запуске с USE_SAMPLE_NEWS=1,
   чтобы можно было посмотреть вёрстку до подключения VK API. */
const useSample =
  (import.meta.env.USE_SAMPLE_NEWS === '1' || process.env.USE_SAMPLE_NEWS === '1') &&
  (newsData.items ?? []).length === 0;

const rawNews = useSample ? newsSample : newsData;

export const news = [...(rawNews.items ?? [])].sort(
  (a, b) => new Date(b.date) - new Date(a.date)
);
/** Односложная запись с трансляцией матча: есть видео, нет ни обложки,
 *  ни фотографий, а весь текст поста совпадает с заголовком — то есть
 *  «Липецк - Волгоград» и больше ничего.
 *  Проверяем совокупность признаков, а не один hasVideo: у настоящей
 *  новости тоже бывает прикреплённое видео. */
export function isBroadcast(item) {
  if (!item?.hasVideo) return false;
  if (item.cover) return false;
  if ((item.photos ?? []).length > 0) return false;
  const text = String(item.text ?? '').trim();
  const title = String(item.title ?? '').trim();
  return text === '' || text === title;
}

/** Настоящие новости — то, что показываем на главной. */
export const articles = news.filter((item) => !isBroadcast(item));

/** Записи матчей — по сути архив трансляций, а не новостная лента. */
export const broadcasts = news.filter(isBroadcast);

export const newsUpdatedAt = rawNews.generatedAt ?? null;
export const newsSourceUrl = rawNews.source ?? site.social.vk;

/* --- МАТЧИ ---------------------------------------------------------------
   TODO (второй этап): здесь появится точка подключения автоматического
   источника расписания. Когда у РФБ (или у оператора соревнований) появится
   открытый фид, достаточно будет:
     1) написать скрипт по образцу scripts/sync-vk-news.mjs, который приводит
        данные фида к структуре ниже и пишет их в data/matches.json;
     2) добавить его в расписание GitHub Actions.
   Остальной код страниц календаря менять не придётся — он читает только
   эту функцию. */
function normalizeMatch(m) {
  const dt = m.time ? `${m.date}T${m.time}:00` : `${m.date}T00:00:00`;
  return {
    ...m,
    datetime: dt,
    timestamp: new Date(dt).getTime(),
    isHome: m.home === true,
    isFinished: m.status === 'finished',
    hasBroadcast: Boolean(m.broadcast_url && String(m.broadcast_url).trim()),
  };
}

export const matches = (matchesData.matches ?? [])
  .map(normalizeMatch)
  .sort((a, b) => a.timestamp - b.timestamp);

export const upcomingMatches = matches.filter((m) => !m.isFinished);
export const finishedMatches = [...matches].filter((m) => m.isFinished).reverse();

/* --- ГОТОВНОСТЬ ДАННЫХ ---------------------------------------------------
   В данных сайта незаполненные места помечены словом «указать»:
   «указать адрес», «указать@почту.ru», «Указать спортзал».
   Сайт не показывает незаполненное — это правило проекта, и вот его проверка. */
export function isFilled(value) {
  const s = String(value ?? '').trim();
  return s !== '' && !/указать/i.test(s);
}

/** Реквизиты, без которых нельзя опубликовать политику обработки
 *  персональных данных, а значит и принимать обращения через сайт:
 *  в политике по 152-ФЗ указываются наименование, ОГРН, ИНН, адрес
 *  и почта для обращений субъектов персональных данных. */
export const privacyReady =
  isFilled(site.requisites?.ogrn) &&
  isFilled(site.requisites?.inn) &&
  isFilled(site.requisites?.legal_address) &&
  isFilled(site.contacts?.email);

/** Сборка для GitHub Pages: там нет PHP, поэтому серверная форма не работает.
 *  На хостинге base равен '/', на Pages — '/ofb-volgograd/'. */
export const hasServer = import.meta.env.BASE_URL === '/';

/* --- ФОРМАТИРОВАНИЕ ------------------------------------------------------ */
const MONTHS = ['января','февраля','марта','апреля','мая','июня','июля','августа','сентября','октября','ноября','декабря'];
const WEEKDAYS = ['воскресенье','понедельник','вторник','среда','четверг','пятница','суббота'];

export function formatDate(value, { withYear = true } = {}) {
  const d = new Date(value);
  if (Number.isNaN(d.getTime())) return '';
  return `${d.getDate()} ${MONTHS[d.getMonth()]}${withYear ? ` ${d.getFullYear()}` : ''}`;
}

export function formatDateTime(value) {
  const d = new Date(value);
  if (Number.isNaN(d.getTime())) return '';
  const time = `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
  return `${formatDate(d)}, ${time}`;
}

export function weekday(value) {
  const d = new Date(value);
  return Number.isNaN(d.getTime()) ? '' : WEEKDAYS[d.getDay()];
}

/** Превращает адрес трансляции VK Видео в адрес для <iframe>.
 *  Принимает и готовую ссылку video_ext.php, и обычную ссылку вида
 *  https://vk.com/video-123456_456239017 — во втором случае собирает embed сама. */
export function vkEmbedUrl(raw) {
  if (!raw) return null;
  const value = String(raw).trim();
  if (value.includes('video_ext.php')) return value;
  const m = value.match(/video(-?\d+)_(\d+)/);
  if (m) return `https://vk.com/video_ext.php?oid=${m[1]}&id=${m[2]}&hd=2`;
  return null;
}
