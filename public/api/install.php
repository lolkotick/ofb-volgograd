<?php
/* =============================================================================
   Установка и проверка серверной части.

   Проверка окружения (ключей не показывает, открывать безопасно):
       https://basket34.ru/api/install.php?check=1

   Создание таблиц:
       через CLI:  /usr/bin/php .../public_html/api/install.php
       или в браузере: .../api/install.php?key=КЛЮЧ_УСТАНОВКИ
       (ключ задаётся в api/secret.php в INSTALL_KEY)
   ========================================================================== */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

$IS_CLI = (PHP_SAPI === 'cli');

function out(string $line): void
{
    echo $line . (PHP_SAPI === 'cli' ? PHP_EOL : "\n");
}

if (!$IS_CLI) {
    header('Content-Type: text/plain; charset=utf-8');
}

/* --- проверка окружения --------------------------------------------------- */
if (!$IS_CLI && isset($_GET['check'])) {
    $pad = static function (string $text, int $width): string {
        $chars = preg_match_all('/./u', $text);
        return $text . str_repeat(' ', max(1, $width - (int)$chars));
    };

    $dbStatus = 'не проверялась';
    $tableStatus = 'не проверялась';
    if (DB_NAME !== '' && DB_USER !== '') {
        try {
            $pdo = db();
            $dbStatus = 'подключение есть';
            $found = $pdo->query("SHOW TABLES LIKE 'submissions'")->fetchColumn();
            if ($found) {
                $count = (int)$pdo->query('SELECT COUNT(*) FROM submissions')->fetchColumn();
                $tableStatus = 'есть, обращений: ' . $count;
            } else {
                $tableStatus = 'НЕТ (запустите установку)';
            }
        } catch (Throwable $e) {
            $dbStatus = 'ОШИБКА: ' . $e->getMessage();
        }
    }

    $checks = [
        'Версия PHP'            => PHP_VERSION,
        'Расширение pdo_mysql'  => extension_loaded('pdo_mysql') ? 'есть' : 'НЕТ (обязательно)',
        'Расширение mbstring'   => extension_loaded('mbstring') ? 'есть' : 'НЕТ (обязательно)',
        'Файл secret.php'       => !is_file(__DIR__ . '/secret.php')
            ? 'НЕ НАЙДЕН (создайте на хостинге)'
            : ($SECRET_ERROR !== '' ? 'ОШИБКА В ФАЙЛЕ: ' . $SECRET_ERROR : 'найден, читается'),
        'Имя базы'              => DB_NAME !== '' ? 'задано' : 'НЕ ЗАДАНО',
        'Пользователь базы'     => DB_USER !== '' ? 'задан' : 'НЕ ЗАДАН',
        'Подключение к базе'    => $dbStatus,
        'Таблица обращений'     => $tableStatus,
        'Ключ установки'        => INSTALL_KEY !== '' ? 'задан' : 'не задан (установка по URL запрещена)',
        'Почта уведомлений'     => NOTIFY_EMAIL !== '' ? 'задана' : 'не задана (заявки копятся в базе)',
        'Отправка почты'        => function_exists('mail') ? 'функция доступна' : 'недоступна',
    ];
    foreach ($checks as $name => $value) {
        out($pad($name . ':', 26) . $value);
    }
    exit(0);
}

/* --- защита запуска по HTTP ----------------------------------------------- */
if (!$IS_CLI) {
    $given = (string)($_GET['key'] ?? '');
    if (INSTALL_KEY === '' || !hash_equals(INSTALL_KEY, $given)) {
        http_response_code(403);
        out('Доступ запрещён.');
        exit(1);
    }
}

/* --- установка ------------------------------------------------------------ */
try {
    if ($SECRET_ERROR !== '') {
        throw new RuntimeException('В файле api/secret.php ошибка: ' . $SECRET_ERROR);
    }

    $sqlFile = __DIR__ . '/schema.sql';
    if (!is_file($sqlFile)) {
        throw new RuntimeException('Не найден файл schema.sql рядом со скриптом.');
    }

    $pdo = db();
    out('→ Подключение к базе ' . DB_NAME . ' установлено.');

    $sql = (string)file_get_contents($sqlFile);
    /* Убираем комментарии и режем на отдельные запросы. */
    $sql = (string)preg_replace('/^\s*--.*$/m', '', $sql);
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
        $pdo->exec($statement);
    }

    $found = $pdo->query("SHOW TABLES LIKE 'submissions'")->fetchColumn();
    if (!$found) {
        throw new RuntimeException('Таблица submissions не появилась — проверьте права пользователя базы.');
    }

    out('✓ Таблица submissions готова.');
    out('  Теперь можно очистить INSTALL_KEY в api/secret.php — установка больше не нужна.');
    exit(0);

} catch (Throwable $e) {
    if (!$IS_CLI) {
        http_response_code(500);
    }
    out('✖ Установка не удалась.');
    out($e->getMessage());
    exit(1);
}
