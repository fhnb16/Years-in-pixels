/**
 * Инбаунд-реле для Years in pixels.
 *
 * Зачем: Telegram не достаёт до хостинга напрямую. Cloudflare достаёт, поэтому воркер
 * стоит посередине и пробрасывает внутрь ровно два вида запросов:
 *
 *   POST /tg-hook              → обновления от Telegram
 *   GET  /user_screens/<файл>  → картинки календарей, которые Telegram скачивает сам
 *
 * Всё остальное — 404. Это принципиально: реле, которое ходит по любому адресу,
 * через неделю найдут и будут гонять через него чужой трафик.
 *
 * Развёртывание:
 *   npx wrangler deploy inbound-worker.js --name yip-inbound --compatibility-date 2026-01-01
 *
 * После развёртывания в панели (Настройки → Входящие):
 *   Адрес для setWebhook       https://yip-inbound.<аккаунт>.workers.dev/tg-hook
 *   Наш эндпоинт за реле       https://bot.example.ru/pixels/api/webhook.php
 *   Публичный адрес файлов     https://yip-inbound.<аккаунт>.workers.dev
 */

// Каталог api на вашем сервере, без завершающего слэша.
const ORIGIN = 'https://bot.example.ru/pixels/api';

// Файл, который принимает обновления. Тот же адрес впишите в «Наш эндпоинт за реле».
const HOOK = `${ORIGIN}/webhook.php`;

// Имя файла картинки: только то, что генерирует saveUserImage(). Без слэшей и точек подряд —
// иначе через ../ можно вытащить config.php.
const FILE = /^\/user_screens\/[A-Za-z0-9_-]+\.(png|jpe?g|gif)$/;

export default {
  async fetch(request) {
    const { pathname } = new URL(request.url);

    if (pathname === '/tg-hook' && request.method === 'POST') {
      // Секрет не проверяем здесь, его проверяет сервер: реле не должно знать секретов.
      // Наша задача — донести заголовок без изменений, именно на этом обычно всё и ломается.
      const upstream = await fetch(HOOK, {
        method: 'POST',
        headers: {
          'content-type': request.headers.get('content-type') || 'application/json',
          'x-telegram-bot-api-secret-token': request.headers.get('x-telegram-bot-api-secret-token') || '',
          'x-forwarded-via': 'cf-inbound',
        },
        body: request.body,
      });
      // Telegram смотрит на код ответа: всё, кроме 2xx, он считает сбоем и повторит доставку.
      return new Response(upstream.body, { status: upstream.status });
    }

    if (FILE.test(pathname) && (request.method === 'GET' || request.method === 'HEAD')) {
      const upstream = await fetch(ORIGIN + pathname, {
        cf: { cacheTtl: 600, cacheEverything: true },
      });
      return new Response(upstream.body, {
        status: upstream.status,
        headers: {
          'content-type': upstream.headers.get('content-type') || 'image/png',
          'cache-control': 'public, max-age=600',
        },
      });
    }

    return new Response('not found', { status: 404 });
  },
};
