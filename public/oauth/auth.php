<?php
/**
 * Инициализация OAuth-авторизации GitHub для Decap CMS
 * ВРОО «Областная федерация баскетбола»
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

if (GITHUB_CLIENT_ID === 'YOUR_GITHUB_CLIENT_ID' || empty(GITHUB_CLIENT_ID)) {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Ошибка настройки OAuth</title></head>';
    echo '<body style="font-family:sans-serif;max-width:600px;margin:50px auto;padding:20px;background:#181818;color:#eee;border-radius:10px;">';
    echo '<h2 style="color:#F5761A;">Не настроен GitHub OAuth</h2>';
    echo '<p>Не задан <code>GITHUB_CLIENT_ID</code>. Пожалуйста, внесите реквизиты OAuth-приложения в файл <code>oauth/config.php</code> (или <code>oauth/secret.php</code>) на хостинге.</p>';
    echo '<p>Инструкция по настройке находится в файле <code>ВХОД-В-ПАНЕЛЬ-НАСТРОЙКА.md</code>.</p>';
    echo '</body></html>';
    exit;
}

// Генерация защищённого CSRF-токена (state) с HMAC-подписью
$time = time();
$rand = bin2hex(random_bytes(16));
$payload = $rand . '.' . $time;
$sig = hash_hmac('sha256', $payload, OAUTH_STATE_SECRET);
$state = rawurlencode($payload . '.' . $sig);

// Репозиторий федерации публичный: запрашиваем только public_repo,
// чтобы не запрашивать доступ к личным приватным репозиториям сотрудников.
$scope = 'public_repo';
$baseUrl = getBaseUrl();
$redirectUri = $baseUrl . '/oauth/callback.php';

$params = [
    'client_id'    => GITHUB_CLIENT_ID,
    'redirect_uri' => $redirectUri,
    'scope'        => $scope,
    'state'        => $state,
];

$githubAuthUrl = 'https://github.com/login/oauth/authorize?' . http_build_query($params);

header('Location: ' . $githubAuthUrl);
exit;
