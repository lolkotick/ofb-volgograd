<?php
/**
 * Обработка ответа от GitHub и передача токена в Decap CMS
 * ВРОО «Областная федерация баскетбола»
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

header('Content-Type: text/html; charset=utf-8');

function renderAuthResponse(bool $success, array $payload, string $errorMessage = ''): void
{
    $provider = 'github';
    $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $escapedError = json_encode($errorMessage, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    ?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <title><?php echo $success ? 'Авторизация успешна' : 'Ошибка авторизации'; ?></title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #111111;
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100vh;
            margin: 0;
            padding: 20px;
            box-sizing: border-box;
            text-align: center;
        }
        .card {
            background: #1A1A1A;
            border: 1px solid #2F2F2F;
            border-radius: 12px;
            padding: 30px;
            max-width: 420px;
            width: 100%;
        }
        .status-ok { color: #4ADE80; font-size: 1.2rem; font-weight: bold; }
        .status-err { color: #FF4D4D; font-size: 1.2rem; font-weight: bold; }
        p { color: #A6A6A6; font-size: 0.95rem; line-height: 1.5; margin-top: 10px; }
    </style>
</head>
<body>
    <div class="card">
        <?php if ($success): ?>
            <div class="status-ok">✓ Вход выполнен</div>
            <p>Передаем данные в панель управления... Окно закроется автоматически.</p>
        <?php else: ?>
            <div class="status-err">✕ Ошибка входа</div>
            <p><?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>
    </div>

    <script>
    (function () {
        var provider = "<?php echo $provider; ?>";
        var isSuccess = <?php echo $success ? 'true' : 'false'; ?>;
        var successData = <?php echo $jsonPayload ?: '{}'; ?>;
        var errorMsg = <?php echo $escapedError ?: '""'; ?>;
        var targetOrigin = window.location.origin;

        function sendMessage() {
            if (!window.opener) return;
            var message;
            if (isSuccess) {
                message = "authorization:" + provider + ":success:" + JSON.stringify(successData);
            } else {
                message = "authorization:" + provider + ":error:" + JSON.stringify({ message: errorMsg });
            }
            window.opener.postMessage(message, targetOrigin);
        }

        function handleHandshake(e) {
            // Принимаем сообщения только от своего домена
            if (e.origin !== targetOrigin) return;
            if (e.data === "authorizing:" + provider) {
                window.removeEventListener("message", handleHandshake, false);
                sendMessage();
            }
        }

        window.addEventListener("message", handleHandshake, false);

        if (window.opener) {
            // Инициируем рукопожатие с Decap CMS строго на свой origin
            window.opener.postMessage("authorizing:" + provider, targetOrigin);
            // Резервный таймер отправки сообщения
            setTimeout(sendMessage, 400);
        }
    })();
    </script>
</body>
</html>
    <?php
    exit;
}

// 1. Проверка наличия кода авторизации
$code = $_GET['code'] ?? null;
$state = $_GET['state'] ?? null;
$error = $_GET['error_description'] ?? $_GET['error'] ?? null;

if ($error) {
    renderAuthResponse(false, [], 'GitHub вернул ошибку: ' . $error);
}

if (!$code || !$state) {
    renderAuthResponse(false, [], 'Не получены обязательные параметры авторизации от GitHub.');
}

// 2. Валидация CSRF state токена
$stateDecoded = rawurldecode($state);
$parts = explode('.', $stateDecoded);
if (count($parts) !== 3) {
    renderAuthResponse(false, [], 'Некорректная цифровая подпись запроса (state).');
}

[$rand, $timeStr, $sig] = $parts;
$time = (int)$timeStr;

// Проверяем срок действия токена (15 минут)
if (time() - $time > 900 || time() < $time - 60) {
    renderAuthResponse(false, [], 'Время ожидания авторизации истекло. Пожалуйста, попробуйте войти снова.');
}

// Проверяем HMAC
$expectedSig = hash_hmac('sha256', $rand . '.' . $timeStr, OAUTH_STATE_SECRET);
if (!hash_equals($expectedSig, $sig)) {
    renderAuthResponse(false, [], 'Нарушена безопасность запроса (неверная HMAC-подпись state).');
}

// 3. Запрос токена доступа у GitHub API
$baseUrl = getBaseUrl();
$redirectUri = $baseUrl . '/oauth/callback.php';

$postFields = [
    'client_id'     => GITHUB_CLIENT_ID,
    'client_secret' => GITHUB_CLIENT_SECRET,
    'code'          => $code,
    'redirect_uri'  => $redirectUri,
    'state'         => $state,
];

$ch = curl_init('https://github.com/login/oauth/access_token');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query($postFields),
    CURLOPT_HTTPHEADER     => [
        'Accept: application/json',
        'User-Agent: OFB-Volgograd-OAuth-Gateway/1.0',
    ],
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_SSL_VERIFYPEER => true,
]);

$response = curl_exec($ch);
$curlError = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($response === false) {
    renderAuthResponse(false, [], 'Не удалось связаться с сервером GitHub: ' . $curlError);
}

$data = json_decode((string)$response, true);

if (!is_array($data)) {
    renderAuthResponse(false, [], 'Некорректный ответ от GitHub API: ' . substr((string)$response, 0, 150));
}

if (!empty($data['error'])) {
    $desc = $data['error_description'] ?? $data['error'];
    renderAuthResponse(false, [], 'Ошибка авторизации GitHub: ' . $desc);
}

if (empty($data['access_token'])) {
    renderAuthResponse(false, [], 'GitHub не предоставил токен доступа.');
}

// 4. Успешный вход — передаём токен в Decap CMS
renderAuthResponse(true, [
    'token'    => $data['access_token'],
    'provider' => 'github',
]);
