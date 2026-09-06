<?php
/* =============================================================================
   Синхронизация новостей с ВКонтакте → data/news.json
   ВРОО «Областная федерация баскетбола»

   Порт скрипта scripts/sync-vk-news.mjs на PHP, чтобы синхронизация работала
   по расписанию (cron) на самом хостинге, а не на серверах GitHub.

   Запуск по расписанию (предпочтительно, через CLI):
       /usr/bin/php /home/ПОЛЬЗОВАТЕЛЬ/basket34.ru/public_html/sync/vk-sync.php

   Запуск по расписанию через URL (если CLI недоступен):
       https://basket34.ru/sync/vk-sync.php?key=КЛЮЧ_ЗАПУСКА
       Ключ задаётся в файле secret.php рядом. Без ключа по HTTP скрипт
       не выполняется — иначе любой смог бы дёргать синхронизацию.

   Проверка окружения (безопасно, ключи не показывает):
       https://basket34.ru/sync/vk-sync.php?check=1

   ВАЖНО про ключ ВКонтакте: методу wall.get нужен СЕРВИСНЫЙ ключ доступа
   приложения VK. Ключ доступа сообщества не подходит — ВКонтакте отдаёт
   ошибку 27 «method is unavailable with group auth».
   Ключ НИКОГДА не хранится в этом файле — только в secret.php,
   который создаётся вручную на хостинге и не лежит в репозитории.
   ========================================================================== */

declare(strict_types=1);

const API_VERSION = '5.199';
const MIN_PHP     = '7.1.0';

$IS_CLI = (PHP_SAPI === 'cli');

/* --- настройки ------------------------------------------------------------
   Значения по умолчанию можно переопределить в secret.php. */
/* Подключаем осторожно: если в secret.php опечатка (лишняя кавычка,
   потерянная точка с запятой), без этой обёртки страница отдавала бы
   пустую 500-ю, и понять причину было бы невозможно. */
$SECRET_ERROR = '';
if (is_file(__DIR__ . '/secret.php')) {
    try {
        require_once __DIR__ . '/secret.php';
    } catch (Throwable $e) {
        $SECRET_ERROR = $e->getMessage();
    }
}

if (!defined('VK_API_TOKEN'))     { define('VK_API_TOKEN', (string)(getenv('VK_API_TOKEN') ?: '')); }
if (!defined('VK_GROUP_DOMAIN'))  { define('VK_GROUP_DOMAIN', 'vrooofb'); }
if (!defined('VK_POST_COUNT'))    { define('VK_POST_COUNT', 30); }
if (!defined('SYNC_RUN_KEY'))     { define('SYNC_RUN_KEY', ''); }
/* Куда класть готовый файл. По умолчанию — папка data рядом с корнем сайта:
   public_html/data/news.json, доступен по адресу /data/news.json */
if (!defined('SYNC_OUTPUT'))      { define('SYNC_OUTPUT', dirname(__DIR__) . '/data/news.json'); }

/* --- вывод ---------------------------------------------------------------- */
$LOG = [];

function say(string $line): void
{
    global $LOG, $IS_CLI;
    $LOG[] = $line;
    if ($IS_CLI) {
        fwrite(STDOUT, $line . PHP_EOL);
    }
}

/** Пишет журнал последнего запуска рядом со скриптом (закрыт .htaccess). */
function writeLog(bool $ok): void
{
    global $LOG;
    $head = sprintf('[%s] %s', gmdate('Y-m-d H:i:s') . ' UTC', $ok ? 'УСПЕХ' : 'ОШИБКА');
    @file_put_contents(__DIR__ . '/last-run.log', $head . PHP_EOL . implode(PHP_EOL, $LOG) . PHP_EOL);
}

function finish(bool $ok): void
{
    global $LOG, $IS_CLI;
    writeLog($ok);
    if (!$IS_CLI) {
        header('Content-Type: text/plain; charset=utf-8');
        if (!$ok) {
            http_response_code(500);
        }
        echo implode(PHP_EOL, $LOG) . PHP_EOL;
    }
    exit($ok ? 0 : 1);
}

/* --- проверка окружения --------------------------------------------------- */
if (!$IS_CLI && isset($_GET['check'])) {
    header('Content-Type: text/plain; charset=utf-8');
    $outDir = dirname(SYNC_OUTPUT);
    $checks = [
        'Версия PHP'                  => PHP_VERSION . (version_compare(PHP_VERSION, MIN_PHP, '<') ? '  ← СТАРАЯ, нужна ' . MIN_PHP . '+' : '  (подходит)'),
        'Расширение mbstring'         => extension_loaded('mbstring') ? 'есть' : 'НЕТ (обязательно)',
        'Расширение curl'             => extension_loaded('curl') ? 'есть' : 'нет',
        'allow_url_fopen'             => ini_get('allow_url_fopen') ? 'включён' : 'выключен',
        'Способ запроса к ВК'         => extension_loaded('curl') ? 'curl' : (ini_get('allow_url_fopen') ? 'file_get_contents' : 'НЕДОСТУПЕН'),
        'Файл secret.php'             => !is_file(__DIR__ . '/secret.php')
            ? 'НЕ НАЙДЕН (создайте на хостинге)'
            : ($SECRET_ERROR !== '' ? 'ОШИБКА В ФАЙЛЕ: ' . $SECRET_ERROR : 'найден, читается'),
        'Ключ ВКонтакте'              => VK_API_TOKEN !== '' ? 'задан' : 'НЕ ЗАДАН',
        'Ключ запуска по URL'         => SYNC_RUN_KEY !== '' ? 'задан' : 'не задан (запуск по URL запрещён)',
        'Сообщество'                  => VK_GROUP_DOMAIN,
        'Куда пишем'                  => SYNC_OUTPUT,
        'Папка назначения'            => is_dir($outDir) ? 'есть' : 'НЕТ (будет создана)',
        'Папка доступна на запись'    => is_dir($outDir) ? (is_writable($outDir) ? 'да' : 'НЕТ') : 'проверим при создании',
    ];
    /* Выравниваем по символам, а не по байтам: printf() считает байты,
       а кириллица занимает по два — колонки разъезжались бы.
       mb_strlen тут использовать нельзя: mbstring может как раз отсутствовать,
       ради чего эта страница и открывается. */
    $pad = static function (string $text, int $width): string {
        $chars = preg_match_all('/./u', $text);
        return $text . str_repeat(' ', max(1, $width - (int)$chars));
    };
    foreach ($checks as $name => $value) {
        echo $pad($name . ':', 28) . $value . "\n";
    }
    exit(0);
}

/* --- требования к окружению -----------------------------------------------
   Проверяем ДО подключения библиотеки: иначе скрипт падал бы с пустой
   500-й ошибкой, не успев объяснить, чего ему не хватает. */
if (version_compare(PHP_VERSION, MIN_PHP, '<')) {
    say('✖ Слишком старая версия PHP: ' . PHP_VERSION . ', нужна ' . MIN_PHP . ' или новее.');
    say('  Панель SpaceWeb → Настройки сайта → Версия PHP → выберите 8.x.');
    finish(false);
}
if (!extension_loaded('mbstring')) {
    say('✖ На хостинге не подключено расширение mbstring — без него нельзя');
    say('  правильно резать русский текст. Включите его в панели SpaceWeb');
    say('  (Настройки PHP → расширения) или напишите в поддержку.');
    finish(false);
}

mb_internal_encoding('UTF-8');
require_once __DIR__ . '/vk-lib.php';

/* --- защита запуска по HTTP ----------------------------------------------- */
if (!$IS_CLI) {
    $given = (string)($_GET['key'] ?? '');
    if (SYNC_RUN_KEY === '' || !hash_equals(SYNC_RUN_KEY, $given)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Доступ запрещён. Синхронизация запускается по расписанию.\n";
        exit(1);
    }
    /* Пусть cron по URL не ждёт ответа дольше разумного */
    @set_time_limit(120);
    @ignore_user_abort(true);
}

/* --- защита от параллельных запусков -------------------------------------- */
$lockFile = __DIR__ . '/.sync.lock';
$lock = @fopen($lockFile, 'c');
if ($lock === false) {
    say('✖ Не удалось создать файл блокировки: ' . $lockFile);
    finish(false);
}
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    say('• Предыдущая синхронизация ещё выполняется — этот запуск пропущен.');
    finish(true);
}

/* --- запрос к VK API ------------------------------------------------------ */

function httpGet(string $url): string
{
    if (extension_loaded('curl')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'ofb-volgograd-site/1.0',
        ]);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false) {
            throw new RuntimeException('Сеть недоступна: ' . $err);
        }
        if ($code !== 200) {
            throw new RuntimeException("HTTP $code от api.vk.com");
        }
        return (string)$body;
    }

    if (!ini_get('allow_url_fopen')) {
        throw new RuntimeException(
            'На хостинге нет ни расширения curl, ни allow_url_fopen — '
            . 'запросы наружу невозможны. Напишите в поддержку SpaceWeb.'
        );
    }
    $ctx = stream_context_create(['http' => [
        'timeout' => 30,
        'header'  => "User-Agent: ofb-volgograd-site/1.0\r\n",
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) {
        throw new RuntimeException('Не удалось получить ответ от api.vk.com');
    }
    return $body;
}

function fetchWall(): array
{
    $params = http_build_query([
        'domain'       => VK_GROUP_DOMAIN,
        'count'        => (string)min((int)VK_POST_COUNT, 100),
        'filter'       => 'owner',   // только записи самого сообщества, без предложенных
        'extended'     => '0',
        'access_token' => VK_API_TOKEN,
        'v'            => API_VERSION,
    ]);

    $json = json_decode(httpGet('https://api.vk.com/method/wall.get?' . $params), true);
    if (!is_array($json)) {
        throw new RuntimeException('ВКонтакте вернул неразбираемый ответ.');
    }

    if (isset($json['error'])) {
        $code = (int)($json['error']['error_code'] ?? 0);
        $msg  = (string)($json['error']['error_msg'] ?? 'неизвестная ошибка');
        $hints = [
            5   => 'Ключ недействителен или отозван — создайте сервисный ключ заново и обновите secret.php.',
            15  => 'Нет доступа к стене. Сервисный ключ читает только открытые сообщества — проверьте, что группа не закрыта.',
            27  => "Похоже, в secret.php положен КЛЮЧ СООБЩЕСТВА. Метод wall.get с ним не работает.\n"
                 . "     Нужен СЕРВИСНЫЙ КЛЮЧ ДОСТУПА приложения: dev.vk.com → Мои приложения →\n"
                 . "     создать Standalone-приложение → Настройки → «Сервисный ключ доступа».",
            28  => 'Ключ приложения не подходит для этого метода — нужен именно сервисный ключ.',
            29  => 'Превышен лимит запросов — уменьшите частоту синхронизации в расписании.',
            100 => 'Проверьте короткий адрес группы в VK_GROUP_DOMAIN (сейчас: ' . VK_GROUP_DOMAIN . ').',
        ];
        $hint = isset($hints[$code]) ? "\n  → " . $hints[$code] : '';
        throw new RuntimeException("VK API ошибка $code: $msg$hint");
    }

    return $json['response'] ?? [];
}

/* --- основной сценарий ---------------------------------------------------- */

try {
    if ($SECRET_ERROR !== '') {
        throw new RuntimeException(
            "В файле sync/secret.php ошибка: " . $SECRET_ERROR . "\n"
            . "  Чаще всего это лишняя или потерянная кавычка вокруг ключа.\n"
            . "  Строка должна выглядеть ровно так:\n"
            . "  define('VK_API_TOKEN', 'ключ');"
        );
    }
    if (VK_API_TOKEN === '') {
        throw new RuntimeException(
            "Не задан ключ ВКонтакте.\n"
            . "  Создайте на хостинге файл sync/secret.php по образцу secret.sample.php\n"
            . "  и впишите туда СЕРВИСНЫЙ ключ доступа приложения VK."
        );
    }

    say('→ Читаю стену сообщества vk.com/' . VK_GROUP_DOMAIN . ' …');
    $response = fetchWall();
    $posts = $response['items'] ?? [];
    say('  получено записей: ' . count($posts) . ' (всего в сообществе: ' . ($response['count'] ?? '?') . ')');

    $items = [];
    foreach ($posts as $post) {
        if (!empty($post['marked_as_ads'])) { continue; }
        if (($post['post_type'] ?? '') !== 'post') { continue; }

        $ownText = jsTrim((string)($post['text'] ?? ''));
        $sourceText = $ownText !== '' ? (string)$post['text'] : (string)($post['copy_history'][0]['text'] ?? '');

        $links = collectLinks($sourceText);
        $text  = cleanText($sourceText);
        $parsed    = makeTitle($text);
        $title     = $parsed['title'];
        $truncated = $parsed['truncated'];
        $photos = collectPhotos($post);

        $item = [
            'id'       => $post['id'],
            'date'     => gmdate('Y-m-d\TH:i:s', (int)$post['date']) . '.000Z',
            'url'      => 'https://vk.com/wall' . $post['owner_id'] . '_' . $post['id'],
            'title'    => $title,
            'text'     => $text,
            'links'    => $links,
            'excerpt'  => makeExcerpt($text, $title, $truncated),
            'cover'    => $photos[0] ?? null,
            'photos'   => $photos,
            'hasVideo' => hasVideo($post),
            'isPinned' => (bool)($post['is_pinned'] ?? false),
        ];

        // записи совсем без текста и без картинок на сайте бесполезны
        if ($item['text'] === '' && $item['cover'] === null) { continue; }

        $items[] = $item;
    }

    usort($items, function (array $a, array $b) {
        return strcmp($b['date'], $a['date']);
    });

    $jsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT;

    // Не переписываем файл, если ничего не изменилось — чтобы не трогать диск зря
    if (is_file(SYNC_OUTPUT)) {
        $previous = json_decode((string)@file_get_contents(SYNC_OUTPUT), true);
        if (is_array($previous)
            && json_encode($previous['items'] ?? null, $jsonFlags) === json_encode($items, $jsonFlags)) {
            say('✓ Новых изменений нет — файл не тронут.');
            finish(true);
        }
    }

    $payload = [
        '_комментарий' => 'Файл создаётся автоматически скриптом sync/vk-sync.php. Руками не редактировать.',
        'generatedAt'  => gmdate('Y-m-d\TH:i:s') . '.000Z',
        'source'       => 'https://vk.com/' . VK_GROUP_DOMAIN,
        'items'        => $items,
    ];

    $dir = dirname(SYNC_OUTPUT);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Не удалось создать папку ' . $dir);
    }

    // Пишем во временный файл и подменяем одним движением:
    // так посетитель никогда не получит наполовину записанный JSON.
    $tmp = SYNC_OUTPUT . '.tmp';
    $encoded = json_encode($payload, $jsonFlags);
    if ($encoded === false) {
        throw new RuntimeException('Не удалось собрать JSON: ' . json_last_error_msg());
    }
    if (@file_put_contents($tmp, $encoded . "\n") === false) {
        throw new RuntimeException('Нет прав на запись в ' . $dir);
    }
    if (!@rename($tmp, SYNC_OUTPUT)) {
        @unlink($tmp);
        throw new RuntimeException('Не удалось заменить файл ' . SYNC_OUTPUT);
    }
    @chmod(SYNC_OUTPUT, 0644);

    say('✓ Сохранено новостей: ' . count($items) . ' → ' . SYNC_OUTPUT);
    finish(true);

} catch (Throwable $e) {
    say('✖ Синхронизация не удалась.');
    say($e->getMessage());
    finish(false);
}
