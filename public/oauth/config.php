<?php
/**
 * Конфигурация GitHub OAuth-посредника для Decap CMS
 * ВРОО «Областная федерация баскетбола»
 *
 * Инструкция:
 * 1. Создайте GitHub OAuth App: https://github.com/settings/developers
 * 2. Введите Client ID и Client Secret ниже (или создайте файл secret.php рядом)
 */

declare(strict_types=1);

// Подключаем secret.php, если он существует на сервере (рекомендуется)
if (file_exists(__DIR__ . '/secret.php')) {
    require_once __DIR__ . '/secret.php';
}

// Client ID приложения GitHub
if (!defined('GITHUB_CLIENT_ID')) {
    define('GITHUB_CLIENT_ID', getenv('GITHUB_CLIENT_ID') ?: 'YOUR_GITHUB_CLIENT_ID');
}

// Client Secret приложения GitHub (секретный ключ)
if (!defined('GITHUB_CLIENT_SECRET')) {
    define('GITHUB_CLIENT_SECRET', getenv('GITHUB_CLIENT_SECRET') ?: 'YOUR_GITHUB_CLIENT_SECRET');
}

// Секретная соль для подписи CSRF state токенов
if (!defined('OAUTH_STATE_SECRET')) {
    define('OAUTH_STATE_SECRET', getenv('OAUTH_STATE_SECRET') ?: 'ofb-secret-salt-change-in-production');
}

// Функция для автоопределения базового URL сайта
function getBaseUrl(): string
{
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    // Для рабочего домена GitHub OAuth строго требует https в redirect_uri
    if (strpos($host, 'basket34.ru') !== false) {
        return 'https://' . $host;
    }
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    $protocol = $isHttps ? 'https://' : 'http://';
    return $protocol . $host;
}
