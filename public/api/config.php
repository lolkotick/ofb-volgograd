<?php
/* =============================================================================
   Настройки серверной части сайта ВРОО «ОФБ».
   Пароли и ключи сюда не пишутся — они живут в secret.php рядом,
   который создаётся вручную на хостинге и в репозиторий не попадает.
   ========================================================================== */

declare(strict_types=1);

$SECRET_ERROR = '';
if (is_file(__DIR__ . '/secret.php')) {
    try {
        require_once __DIR__ . '/secret.php';
    } catch (Throwable $e) {
        $SECRET_ERROR = $e->getMessage();
    }
}

if (!defined('DB_HOST'))   { define('DB_HOST', 'localhost'); }
if (!defined('DB_NAME'))   { define('DB_NAME', ''); }
if (!defined('DB_USER'))   { define('DB_USER', ''); }
if (!defined('DB_PASS'))   { define('DB_PASS', ''); }
if (!defined('DB_PORT'))   { define('DB_PORT', 3306); }
if (!defined('DB_SOCKET')) { define('DB_SOCKET', ''); }

/* Куда слать уведомление о новом обращении. Пусто — письма не отправляются,
   заявки просто копятся в базе. Включим, когда у федерации появится почта. */
if (!defined('NOTIFY_EMAIL')) { define('NOTIFY_EMAIL', ''); }
if (!defined('MAIL_FROM'))    { define('MAIL_FROM', ''); }

/* Сколько обращений с одного адреса принимаем за час. */
if (!defined('RATE_LIMIT_PER_HOUR')) { define('RATE_LIMIT_PER_HOUR', 5); }

/* Версия текста согласия на обработку персональных данных.
   Меняется вместе с текстом на странице политики: по ней потом видно,
   на какую именно редакцию человек соглашался. */
if (!defined('CONSENT_VERSION')) { define('CONSENT_VERSION', '2026-09-05'); }

/* Ключ для запуска установки таблиц через браузер. Пусто — установка по URL
   запрещена (тогда запускать через CLI). */
if (!defined('INSTALL_KEY')) { define('INSTALL_KEY', ''); }

/** Подключение к базе. Одно на весь запрос. */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    if (DB_NAME === '' || DB_USER === '') {
        throw new RuntimeException(
            'База данных не настроена: в api/secret.php не заданы DB_NAME и DB_USER.'
        );
    }
    $dsn = DB_SOCKET !== ''
        ? 'mysql:unix_socket=' . DB_SOCKET . ';dbname=' . DB_NAME . ';charset=utf8mb4'
        : 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';

    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    return $pdo;
}

/** IP посетителя в двоичном виде — для ограничения частоты обращений.
 *  Храним именно так: компактно и не читается глазами при случайном взгляде в базу. */
function clientIpBinary(): ?string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if ($ip === '') {
        return null;
    }
    $packed = @inet_pton($ip);
    return $packed === false ? null : $packed;
}
