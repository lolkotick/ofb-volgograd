// @ts-check
import { defineConfig } from 'astro/config';
import sitemap from '@astrojs/sitemap';

/**
 * НАСТРОЙКА АДРЕСА САЙТА
 * ----------------------
 * Пока сайт живёт на GitHub Pages по адресу вида
 *   https://ИМЯ-ПОЛЬЗОВАТЕЛЯ.github.io/ofb-volgograd
 * поэтому нужны И site, И base.
 *
 * Когда подключите собственный домен (например https://ofb34.ru):
 *   1. site: 'https://ofb34.ru'
 *   2. base: '/'
 *   3. положите файл public/CNAME с одной строкой: ofb34.ru
 * Больше нигде в коде адреса менять не нужно — все ссылки строятся от base.
 */
const SITE = process.env.SITE_URL || 'https://vrooofb.github.io';
const BASE = process.env.SITE_BASE || '/ofb-volgograd';

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
});
