<?php
/* =============================================================================
   Разбор постов ВКонтакте — чистые функции без побочных эффектов.
   Точный перенос логики из scripts/sync-vk-news.mjs.

   Вынесено отдельным файлом, чтобы функции можно было прогнать тестами,
   не запуская саму синхронизацию (как в оригинале, где они экспортируются).
   ========================================================================== */

declare(strict_types=1);

mb_internal_encoding('UTF-8');

if (!defined('TITLE_MAX'))   { define('TITLE_MAX', 95); }
if (!defined('EXCERPT_MAX')) { define('EXCERPT_MAX', 180); }

/** Класс пробельных символов, совпадающий с \s в JavaScript.
 *  В PHP \s — только ASCII, а в постах ВКонтакте регулярно попадаются
 *  неразрывные пробелы (копипаста из Word). Без этого класса заголовок
 *  и превью на PHP и на Node расходились бы. */
const JS_WS = '\x{0009}-\x{000d}\x{0020}\x{00a0}\x{1680}\x{2000}-\x{200a}\x{2028}\x{2029}\x{202f}\x{205f}\x{3000}\x{feff}';

/** Аналог String.prototype.trim() из JavaScript (режет и юникодные пробелы). */
function jsTrim(string $s): string
{
    return (string)preg_replace('/^[' . JS_WS . ']+|[' . JS_WS . ']+$/u', '', $s);
}

/** Аналог trimEnd() из JavaScript. */
function jsTrimEnd(string $s): string
{
    return (string)preg_replace('/[' . JS_WS . ']+$/u', '', $s);
}

/* =============================================================================
   Функции разбора текста — точный перенос логики из sync-vk-news.mjs
   ========================================================================== */

/** Достаёт ссылки вида [https://vk.ru/album-1_2|Награждение] ДО очистки текста,
 *  чтобы показать их на странице новости отдельным списком. */
function collectLinks(string $raw = ''): array
{
    $links = [];
    $seen = [];
    $re = '/\[((?:https?:\/\/|www\.)[^\]|]+)\|([^\]]+)\]/u';
    if (preg_match_all($re, $raw, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            $url = str_starts_with($m[1], 'http') ? $m[1] : 'https://' . $m[1];
            $label = jsTrim($m[2]);
            if (!isset($seen[$url])) {
                $seen[$url] = true;
                $links[] = ['url' => $url, 'label' => $label !== '' ? $label : $url];
            }
        }
    }
    return $links;
}

/** Убирает служебную разметку ВКонтакте.
 *  [club1|Название] и [https://…|Подпись] превращаются в подпись:
 *  иначе на сайте видны сырые скобки со ссылками. */
function cleanText(string $raw = ''): string
{
    $out = preg_replace('/\[[^\]|]+\|([^\]]+)\]/u', '$1', $raw);
    $out = preg_replace('/(#[^' . JS_WS . '#@]+)@[\w.]+/u', '$1', $out);
    $out = str_replace(['&quot;', '&amp;', '&lt;', '&gt;'], ['"', '&', '<', '>'], $out);
    $out = str_replace("\r\n", "\n", $out);
    $out = preg_replace('/[ \t]+\n/u', "\n", $out);
    $out = preg_replace('/\n{3,}/u', "\n\n", $out);
    return jsTrim($out);
}

/** Обрезает по границе слова, а не по символу */
function clip(string $value, int $max): string
{
    if (mb_strlen($value) <= $max) {
        return $value;
    }
    $cut = mb_substr($value, 0, $max);
    $space = mb_strrpos($cut, ' ');
    $base = ($space !== false && $space > $max * 0.5) ? mb_substr($cut, 0, $space) : $cut;
    return jsTrimEnd($base) . '…';
}

/** Заголовок берём из первой строки поста.
 *  Возвращаем и признак обрезки: от него зависит, как строить превью. */
function makeTitle(string $text): array
{
    if ($text === '') {
        return ['title' => 'Публикация федерации', 'truncated' => false];
    }
    $firstLine = $text;
    foreach (explode("\n", $text) as $line) {
        $line = jsTrim($line);
        if ($line !== '') { $firstLine = $line; break; }
    }
    $clean = preg_replace('/^[#•\-–—' . JS_WS . ']+/u', '', $firstLine);
    $clean = jsTrim((string)preg_replace('/[' . JS_WS . ']+/u', ' ', $clean));

    if ($clean === '') {
        return ['title' => 'Публикация федерации', 'truncated' => false];
    }
    if (mb_strlen($clean) <= TITLE_MAX) {
        return ['title' => $clean, 'truncated' => false];
    }
    // если в начале есть законченное предложение подходящей длины — берём его целиком
    if (preg_match('/^.{30,95}?[.!?…](?:[' . JS_WS . ']|$)/u', $clean, $m)) {
        return ['title' => jsTrim($m[0]), 'truncated' => false];
    }
    return ['title' => clip($clean, TITLE_MAX), 'truncated' => true];
}

/** Превью под заголовком.
 *  Если заголовок — обрезанное начало текста, превью продолжает фразу и
 *  начинается с многоточия. Иначе показываем текст после строки-заголовка. */
function makeExcerpt(string $text, string $title, bool $truncated): string
{
    $flat = jsTrim((string)preg_replace('/[' . JS_WS . ']+/u', ' ', $text));
    if ($flat === '') {
        return '';
    }
    // сравниваем по тексту с одинарными пробелами: в постах встречаются двойные,
    // из-за них заголовок иначе не «находится» в начале и превью дублирует его
    $base = $truncated ? preg_replace('/…$/u', '', $title) : $title;
    $base = jsTrim((string)preg_replace('/[' . JS_WS . ']+/u', ' ', $base));

    $rest = '';
    if ($base !== '' && str_starts_with($flat, $base)) {
        $rest = jsTrim(mb_substr($flat, mb_strlen($base)));
    }
    if ($rest === '') {
        $rest = $flat;
    }
    return $truncated ? '…' . clip($rest, EXCERPT_MAX) : clip($rest, EXCERPT_MAX);
}

/** Из вложения-фотографии выбирает самый крупный размер */
function bestPhoto(?array $photo): ?array
{
    $sizes = $photo['sizes'] ?? [];
    if (!$sizes) {
        return null;
    }
    $best = $sizes[0];
    foreach ($sizes as $size) {
        if (($size['width'] ?? 0) > ($best['width'] ?? 0)) {
            $best = $size;
        }
    }
    return [
        'url'    => $best['url'] ?? '',
        'width'  => $best['width'] ?? 0,
        'height' => $best['height'] ?? 0,
    ];
}

function allAttachments(array $post): array
{
    return array_merge(
        $post['attachments'] ?? [],
        $post['copy_history'][0]['attachments'] ?? []
    );
}

/** Достаёт фото из вложений записи (в том числе из репоста) */
function collectPhotos(array $post): array
{
    $photos = [];
    foreach (allAttachments($post) as $a) {
        if (($a['type'] ?? '') !== 'photo') { continue; }
        $best = bestPhoto($a['photo'] ?? null);
        if ($best !== null) { $photos[] = $best; }
    }
    return $photos;
}

function hasVideo(array $post): bool
{
    foreach (allAttachments($post) as $a) {
        if (($a['type'] ?? '') === 'video') { return true; }
    }
    return false;
}
