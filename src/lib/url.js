/** Склеивает base сайта с путём. Нужен, потому что на GitHub Pages сайт живёт
 *  в подпапке (/ofb-volgograd/), а на своём домене — в корне (/). */
export function url(path = '/') {
  const base = import.meta.env.BASE_URL || '/';
  const joined = `${base.replace(/\/+$/, '')}/${String(path).replace(/^\/+/, '')}`;
  return joined.replace(/\/{2,}/g, '/');
}
