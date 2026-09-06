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

    /* Флаги для значений, которые попадают внутрь <script>.
       JSON_HEX_TAG обязателен: текст ошибки приходит из параметров запроса,
       и без него подделанная ссылка могла бы закрыть тег и подставить свой код. */
    $jsFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
    $jsonPayload  = json_encode($payload, $jsFlags);
    $escapedError = json_encode($errorMessage, $jsFlags);
    ?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Служебная страница</title>
</head>
<body>
<?php if (!$success): ?>
    <p id="oauth-error" style="font-family:sans-serif;font-size:.95rem;padding:16px"></p>
<?php endif; ?>
<script>
(function () {
    /* Страница намеренно ничего не показывает при успехе и сразу закрывается.
       Раньше здесь была карточка «Вход выполнен» — на свежем домене, принимающем
       параметры авторизации GitHub, она попадала под эвристику Safe Browsing
       и Chrome помечал страницу как фишинговую. Показывать тут человеку нечего:
       токен уходит в панель через postMessage. */
    var provider = "<?php echo $provider; ?>";
    var isSuccess = <?php echo $success ? 'true' : 'false'; ?>;
    var successData = <?php echo $jsonPayload ?: '{}'; ?>;
    var errorMsg = <?php echo $escapedError ?: '""'; ?>;
    var targetOrigin = window.location.origin;

    if (!isSuccess) {
        var box = document.getElementById("oauth-error");
        if (box) { box.textContent = errorMsg; }
    }

    function closeSoon() {
        if (isSuccess) { setTimeout(function () { window.close(); }, 150); }
    }

    function sendMessage() {
        if (!window.opener) { return; }
        var message = isSuccess
            ? "authorization:" + provider + ":success:" + JSON.stringify(successData)
            : "authorization:" + provider + ":error:" + JSON.stringify({ message: errorMsg });
        window.opener.postMessage(message, targetOrigin);
    }

    function handleHandshake(e) {
        // Принимаем сообщения только от своего домена
        if (e.origin !== targetOrigin) { return; }
        if (e.data === "authorizing:" + provider) {
            window.removeEventListener("message", handleHandshake, false);
            sendMessage();
            closeSoon();
        }
    }

    window.addEventListener("message", handleHandshake, false);

    if (window.opener) {
        // Инициируем рукопожатие с Decap CMS строго на свой origin
        window.opener.postMessage("authorizing:" + provider, targetOrigin);
        // Резервная отправка на случай, если панель уже ждёт ответ
        setTimeout(function () { sendMessage(); closeSoon(); }, 400);
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
