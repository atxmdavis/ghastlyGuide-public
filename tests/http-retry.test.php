<?php

declare(strict_types=1);
require dirname(__DIR__) . '/backend/src/http.php';

$now = 1791223200;
$cases = [
    [429, 1, null, 15], [429, 2, null, 30], [429, 3, null, null],
    [503, 1, null, 2], [503, 2, null, 4], [0, 1, null, 2],
    [400, 1, '5', null], [403, 1, null, null], [200, 1, null, null],
    [429, 1, '45', 45], [503, 1, '60', 60], [429, 1, '0', 1],
    [429, 1, '61', null], [429, 1, '999999999999999999999999', null],
    [429, 1, '-1', 15], [429, 1, 'tomorrow', 15],
    [429, 1, gmdate('D, d M Y H:i:s \G\M\T', $now + 20), 20],
    [503, 1, gmdate('D, d M Y H:i:s \G\M\T', $now - 20), 1],
    [429, 1, gmdate('D, d M Y H:i:s \G\M\T', $now + 120), null],
];
foreach ($cases as [$status, $attempt, $header, $expected]) {
    $actual = ghastly_http_retry_delay($status, $attempt, $header, $now);
    if ($actual !== $expected) throw new RuntimeException('Incorrect retry policy: ' . json_encode([$status, $attempt, $header, $actual, $expected]));
}
echo "HTTP retry timing, server cooldown, and attempt limit checks passed.\n";
