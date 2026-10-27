<?php

declare(strict_types=1);

function ghastly_rate_limit(string $scope, string $address, int $limit, int $window, ?string $directory = null, ?int $now = null): bool
{
    $directory ??= dirname(__DIR__) . '/data';
    $now ??= time();
    $handle = fopen($directory . '/' . $scope . '-rate-limits.json', 'c+');
    if ($handle === false) throw new RuntimeException('Rate limit storage unavailable.');
    try {
        if (!flock($handle, LOCK_EX)) throw new RuntimeException('Rate limit lock unavailable.');
        $contents = stream_get_contents($handle);
        $records = json_decode(is_string($contents) ? $contents : '', true);
        $records = is_array($records) ? $records : [];
        foreach ($records as $key => $timestamps) {
            $records[$key] = is_array($timestamps) ? array_values(array_filter($timestamps, static fn ($stamp): bool => is_int($stamp) && $stamp > $now - $window)) : [];
            if ($records[$key] === []) unset($records[$key]);
        }
        $key = hash('sha256', $scope . '|' . $address);
        $allowed = count($records[$key] ?? []) < $limit;
        if ($allowed) $records[$key][] = $now;
        $json = json_encode($records, JSON_THROW_ON_ERROR);
        rewind($handle);
        if (!ftruncate($handle, 0) || fwrite($handle, $json) !== strlen($json) || !fflush($handle)) throw new RuntimeException('Rate limit write failed.');
        return $allowed;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}
