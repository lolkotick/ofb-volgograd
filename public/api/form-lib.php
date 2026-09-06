<?php
/* =============================================================================
   Проверка и нормализация данных формы обращения.
   Чистые функции без побочных эффектов — их можно прогнать тестами,
   не поднимая базу и не отправляя запросы.
   ========================================================================== */

declare(strict_types=1);

if (!defined('MSG_MIN'))  { define('MSG_MIN', 10); }
if (!defined('MSG_MAX'))  { define('MSG_MAX', 4000); }
if (!defined('NAME_MIN')) { define('NAME_MIN', 2); }
if (!defined('NAME_MAX')) { define('NAME_MAX', 150); }
/* Ниже этого числа секунд между открытием страницы и отправкой считаем,
   что форму заполнил робот: человек столько не успевает. */
if (!defined('MIN_FILL_SECONDS')) { define('MIN_FILL_SECONDS', 3); }
if (!defined('MAX_FORM_AGE'))     { define('MAX_FORM_AGE', 6 * 3600); }

/** Темы обращения. Список должен совпадать с тем, что в форме на сайте:
 *  всё, чего здесь нет, отклоняется. */
function formTopics(): array
{
    return [
        'accreditation' => 'Аккредитация',
        'competitions'  => 'Участие в соревнованиях',
        'kids'          => 'Детский баскетбол и секции',
        'amateur'       => 'Любительский баскетбол',
        'student'       => 'Студенческий баскетбол',
        'referees'      => 'Судейство',
        'partnership'   => 'Сотрудничество и партнёрство',
        'media'         => 'Вопрос для СМИ',
        'site'          => 'Работа сайта',
        'other'         => 'Другое',
    ];
}

/** Схлопывает пробелы и обрезает края. Юникодные пробелы тоже. */
function tidy(string $value): string
{
    $ws = '\x{0009}-\x{000d}\x{0020}\x{00a0}\x{2000}-\x{200a}\x{2028}\x{2029}\x{202f}\x{205f}\x{3000}\x{feff}';
    $value = (string)preg_replace('/[' . $ws . ']+/u', ' ', $value);
    return (string)preg_replace('/^ +| +$/u', '', $value);
}

/** То же, но переводы строк сохраняем — для текста сообщения. */
function tidyMultiline(string $value): string
{
    $value = str_replace("\r\n", "\n", $value);
    $value = (string)preg_replace('/[ \t\x{00a0}]+/u', ' ', $value);
    $value = (string)preg_replace('/ *\n */u', "\n", $value);
    $value = (string)preg_replace('/\n{3,}/u', "\n\n", $value);
    return trim($value);
}

/** Определяет, что человек оставил: почту, телефон или что-то ещё. */
function detectContactKind(string $contact): string
{
    if (filter_var($contact, FILTER_VALIDATE_EMAIL)) {
        return 'email';
    }
    $digits = preg_replace('/\D+/', '', $contact);
    if (strlen((string)$digits) >= 10 && strlen((string)$digits) <= 15) {
        return 'phone';
    }
    return 'other';
}

/**
 * Проверяет присланные поля.
 * Возвращает ['errors' => [поле => текст], 'values' => [подготовленные значения]].
 * Ошибки написаны так, чтобы их можно было показать человеку как есть.
 */
function validateSubmission(array $in, ?int $now = null): array
{
    $now = $now ?? time();
    $errors = [];
    $topics = formTopics();

    $topic   = tidy((string)($in['topic'] ?? ''));
    $name    = tidy((string)($in['name'] ?? ''));
    $contact = tidy((string)($in['contact'] ?? ''));
    $message = tidyMultiline((string)($in['message'] ?? ''));
    $consent = (string)($in['consent'] ?? '');
    $trap    = tidy((string)($in['website'] ?? ''));
    $ts      = (string)($in['ts'] ?? '');

    if ($topic === '' || !isset($topics[$topic])) {
        $errors['topic'] = 'Выберите тему обращения.';
    }

    $nameLen = mb_strlen($name);
    if ($name === '') {
        $errors['name'] = 'Напишите, как к вам обращаться.';
    } elseif ($nameLen < NAME_MIN || $nameLen > NAME_MAX) {
        $errors['name'] = 'Имя должно быть от ' . NAME_MIN . ' до ' . NAME_MAX . ' символов.';
    }

    if ($contact === '') {
        $errors['contact'] = 'Оставьте почту или телефон — иначе мы не сможем ответить.';
    } elseif (mb_strlen($contact) > 190) {
        $errors['contact'] = 'Слишком длинное значение.';
    } elseif (detectContactKind($contact) === 'other') {
        $errors['contact'] = 'Похоже, это не почта и не телефон. Проверьте, пожалуйста.';
    }

    $msgLen = mb_strlen($message);
    if ($message === '') {
        $errors['message'] = 'Опишите вопрос.';
    } elseif ($msgLen < MSG_MIN) {
        $errors['message'] = 'Слишком коротко — напишите хотя бы ' . MSG_MIN . ' символов.';
    } elseif ($msgLen > MSG_MAX) {
        $errors['message'] = 'Слишком длинно: ' . $msgLen . ' символов, а можно до ' . MSG_MAX . '.';
    }

    if ($consent !== '1') {
        $errors['consent'] = 'Без согласия на обработку персональных данных мы не вправе принять обращение.';
    }

    /* Ловушки для роботов. Человеку они не видны и сработать у него не могут,
       поэтому текст ошибки нарочно нейтральный. */
    if ($trap !== '') {
        $errors['_bot'] = 'Форма отклонена.';
    }
    if ($ts === '' || !ctype_digit($ts)) {
        $errors['_bot'] = 'Форма отклонена. Обновите страницу и попробуйте ещё раз.';
    } else {
        $age = $now - (int)floor((int)$ts / 1000);
        if ($age < MIN_FILL_SECONDS || $age > MAX_FORM_AGE) {
            $errors['_bot'] = 'Форма отклонена. Обновите страницу и попробуйте ещё раз.';
        }
    }

    return [
        'errors' => $errors,
        'values' => [
            'topic'        => $topic,
            'topic_label'  => $topics[$topic] ?? '',
            'name'         => $name,
            'contact'      => $contact,
            'contact_kind' => $contact !== '' ? detectContactKind($contact) : 'other',
            'message'      => $message,
        ],
    ];
}
