<?php
/* Прогоняет фикстуры через PHP-функции разбора постов ВК. */
require_once __DIR__ . '/../../public/sync/vk-lib.php';

$fixtures = json_decode((string)file_get_contents(__DIR__ . '/fixtures.json'), true);
$out = [];
foreach ($fixtures as $raw) {
    $links = collectLinks($raw);
    $text  = cleanText($raw);
    ['title' => $title, 'truncated' => $truncated] = makeTitle($text);
    $out[] = [
        'links'     => $links,
        'text'      => $text,
        'title'     => $title,
        'truncated' => $truncated,
        'excerpt'   => makeExcerpt($text, $title, $truncated),
    ];
}
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
