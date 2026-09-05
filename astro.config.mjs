// @ts-check
import { defineConfig } from 'astro/config';
import sitemap from '@astrojs/sitemap';
import { readFileSync, existsSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * НАСТРОЙКА АДРЕСА САЙТА ДЛЯ РОССИЙСКОГО ХОСТИНГА / СОБСТВЕННОГО ДОМЕНА
 * --------------------------------------------------------------------
 * На российском хостинге сайт располагается в корне домена (base: '/').
 * Для GitHub Pages при необходимости можно передать SITE_BASE=/ofb-volgograd.
 */
const SITE = process.env.SITE_URL || 'https://basket34.ru';
const BASE = process.env.SITE_BASE || '/';

/** Dev-сервер Astro (Vite) не отдаёт index.html из подпапок public/.
 *  Этот плагин перехватывает запрос к /admin/ и отдаёт статический файл. */
function servePublicSubdirs() {
  return {
    name: 'serve-public-subdirs',
    configureServer(server) {
      server.middlewares.use((req, res, next) => {
        if (req.url === '/admin/' || req.url === '/admin') {
          const file = resolve('public/admin/index.html');
          if (existsSync(file)) {
            res.setHeader('Content-Type', 'text/html; charset=utf-8');
            res.end(readFileSync(file, 'utf-8'));
            return;
          }
        }
        next();
      });
    },
  };
}

export default defineConfig({
  site: SITE,
  base: BASE,

  /* Локальный сервер разработки (на собранный сайт не влияет).
     Слушаем 127.0.0.1 явно: на Windows браузер часто пытается открыть
     localhost по IPv6 (::1), а Node слушает IPv4 — из-за этого
     появляется ERR_CONNECTION_REFUSED при работающем сервере. */
  server: { host: '127.0.0.1', port: 4321 },
  trailingSlash: 'always',
  build: { format: 'directory' },
  integrations: [sitemap()],
  vite: { plugins: [servePublicSubdirs()] },
});
