<?php

declare(strict_types=1);
require dirname(__DIR__) . '/backend/src/http.php';

$expected = [
    ['name' => 'Braces }{, brackets ][, quote " and slash \\', 'nested' => [['id' => 1], ['id' => 2]], 'unicode' => 'Hanté'],
    ['name' => 'Second', 'empty' => [], 'value' => null],
];
$actual = iterator_to_array(ghastly_json_object_items(" \n" . json_encode($expected, JSON_THROW_ON_ERROR) . "\t"));
if ($actual !== $expected || iterator_to_array(ghastly_json_object_items('[]')) !== []) {
    throw new RuntimeException('Incremental decoding must preserve nested objects and escaped strings.');
}
foreach (['', '{}', '[1]', '[{}', '[{},]', '[{} {}]', '[{"name":"unfinished}]', '[{"bad":}]', '[{}] trailing'] as $invalid) {
    $failed = false;
    try {
        iterator_to_array(ghastly_json_object_items($invalid));
    } catch (Throwable) {
        $failed = true;
    }
    if (!$failed) throw new RuntimeException('Malformed source responses must fail rather than silently return partial results.');
}

// Expanding this whole fixture produces tens of thousands of nested PHP arrays.
// The iterator must retain only the source text and the current object.
$before = memory_get_usage(true);
$item = json_encode(['id' => 1, '_embedded' => ['show' => ['genres' => ['Drama', 'Horror'], 'summary' => str_repeat('x', 160)]]], JSON_THROW_ON_ERROR);
$json = '[' . str_repeat($item . ',', 49999) . $item . ']';
$count = 0;
foreach (ghastly_json_object_items($json) as $episode) {
    if ($episode['id'] !== 1) throw new RuntimeException('Large response item corrupted.');
    $count++;
}
if ($count !== 50000 || memory_get_peak_usage(true) - $before > 40 * 1024 * 1024) {
    throw new RuntimeException('Large schedule decoding exceeded its memory budget.');
}
echo "TVmaze incremental decoding and memory checks passed.\n";
