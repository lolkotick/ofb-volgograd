<?php
/* =============================================================================
   Приём обращения с сайта ВРОО «ОФБ».

   Работает и без JavaScript: тогда браузер отправляет обычный POST и получает
   страницу с результатом. С JavaScript страница не перезагружается — скрипт
   на сайте отправляет то же самое и показывает ответ прямо под формой.
   ========================================================================== */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/form-lib.php';

/* Отвечаем JSON, если браузер его просит (так делает скрипт формы),
   иначе рисуем человеку страницу. */
$wantsJson = strpos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false;

function respond(bool $ok, string $message, array $errors = []): void
{
    global $wantsJson;

    if ($wantsJson) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($ok ? 200 : 422);
        echo json_encode(
            ['ok' => $ok, 'message' => $message, 'errors' => $errors],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        exit;
    }

    header('Content-Type: text/html; charset=utf-8');
    http_response_code($ok ? 200 : 422);
    $title = $ok ? 'Обращение отправлено' : 'Обращение не отправлено';
    $list = '';
    foreach ($errors as $field => $text) {
        if ($field === '_bot') { continue; }
        $list .= '<li>' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</li>';
    }
    echo '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<meta name="robots" content="noindex">'
       . '<title>' . $title . '</title><style>'
       . 'body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;'
       . 'background:#111;color:#fff;display:flex;align-items:center;justify-content:center;'
       . 'min-height:100vh;margin:0;padding:20px;box-sizing:border-box}'
       . '.card{background:#1A1A1A;border:1px solid #2F2F2F;border-radius:12px;'
       . 'padding:32px;max-width:520px;width:100%}'
       . 'h1{font-size:1.35rem;margin:0 0 12px;color:' . ($ok ? '#4ADE80' : '#FF7A00') . '}'
       . 'p,li{color:#A6A6A6;line-height:1.6;font-size:.95rem}'
       . 'a{color:#F5761A}</style></head><body><div class="card">'
       . '<h1>' . $title . '</h1>'
       . '<p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>'
       . ($list !== '' ? '<ul>' . $list . '</ul>' : '')
       . '<p><a href="/contacts/">Вернуться к контактам</a></p>'
       . '</div></body></html>';
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(false, 'Эта страница принимает только отправку формы.');
}

if ($SECRET_ERROR !== '') {
    error_log('OFB submit: ошибка в api/secret.php — ' . $SECRET_ERROR);
    respond(false, 'Форма временно недоступна. Пожалуйста, напишите нам во ВКонтакте.');
}

$check = validateSubmission($_POST);

if ($check['errors']) {
    /* Ловушки для роботов не объясняем подробно и не подсвечиваем поля. */
    if (isset($check['errors']['_bot'])) {
        respond(false, $check['errors']['_bot']);
    }
    respond(false, 'Проверьте, пожалуйста, отмеченные поля.', $check['errors']);
}

$values = $check['values'];
$ip = clientIpBinary();

try {
    $pdo = db();

    /* Ограничение частоты: спасает и от спама, и от случайной двойной отправки. */
    if ($ip !== null) {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM submissions
              WHERE ip = ? AND created_at > (NOW() - INTERVAL 1 HOUR)'
        );
        $stmt->execute([$ip]);
        if ((int)$stmt->fetchColumn() >= RATE_LIMIT_PER_HOUR) {
            respond(false, 'С этого адреса за последний час уже отправлено несколько обращений. '
                         . 'Попробуйте позже или напишите нам во ВКонтакте.');
        }
    }

    $stmt = $pdo->prepare(
        'INSERT INTO submissions
            (created_at, topic, topic_label, name, contact, contact_kind, message,
             consent_version, consent_at, ip, user_agent)
         VALUES (NOW(), ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?)'
    );
    $stmt->execute([
        $values['topic'],
        $values['topic_label'],
        $values['name'],
        $values['contact'],
        $values['contact_kind'],
        $values['message'],
        CONSENT_VERSION,
        $ip,
        mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
    ]);
} catch (Throwable $e) {
    /* Подробности — в лог сервера, человеку показываем спокойный текст:
       содержимое ошибки базы посетителю знать незачем. */
    error_log('OFB submit: ' . $e->getMessage());
    respond(false, 'Не удалось сохранить обращение — что-то не так на нашей стороне. '
                 . 'Пожалуйста, напишите нам во ВКонтакте, мы разберёмся.');
}

/* Уведомление на почту — только если ящик задан. */
if (NOTIFY_EMAIL !== '') {
    $subject = 'Обращение с сайта: ' . $values['topic_label'];
    $body = "Тема: {$values['topic_label']}\n"
          . "Имя: {$values['name']}\n"
          . "Контакт: {$values['contact']}\n\n"
          . $values['message'] . "\n";
    $headers = 'Content-Type: text/plain; charset=utf-8';
    if (MAIL_FROM !== '') {
        $headers .= "\r\nFrom: " . MAIL_FROM;
    }
    @mail(NOTIFY_EMAIL, $subject, $body, $headers);
}

respond(true, 'Спасибо! Обращение принято, мы свяжемся с вами по указанным контактам.');
