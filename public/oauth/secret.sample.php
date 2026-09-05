<?php
/**
 * Шаблон файла с секретными ключами GitHub OAuth для хостинга.
 *
 * Инструкция:
 * 1. Скопируйте этот файл на хостинге в папку oauth/ под именем secret.php
 * 2. Заполните CLIENT_ID и CLIENT_SECRET значениями из настроек GitHub OAuth App
 * 3. Файл secret.php никогда не коммитится в репозиторий и защищён .htaccess
 */

declare(strict_types=1);

define('GITHUB_CLIENT_ID', 'ВАШ_GITHUB_CLIENT_ID');
define('GITHUB_CLIENT_SECRET', 'ВАШ_GITHUB_CLIENT_SECRET');
define('OAUTH_STATE_SECRET', 'произвольная_случайная_строка_для_подписи_токена');
