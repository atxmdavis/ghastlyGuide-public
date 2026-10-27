<?php

declare(strict_types=1);

function ghastly_saved_transfer_normalize(mixed $value): ?array
{
    if (!is_array($value) || $value === [] || count($value) > 400) {
        return null;
    }
    $mediaTypes = ['movie', 'show', 'video_game', 'tabletop_game', 'comic', 'book'];
    $precisions = ['day', 'month', 'year', 'tbd'];
    $confidences = ['official', 'partner', 'database', 'editorial'];
    $saves = [];
    foreach ($value as $key => $release) {
        if (!is_string($key) || $key === '' || mb_strlen($key) > 160 || !is_array($release)) {
            return null;
        }
        $id = trim((string) ($release['id'] ?? ''));
        $title = trim((string) ($release['title'] ?? ''));
        $date = trim((string) ($release['date'] ?? ''));
        $mediaType = (string) ($release['mediaType'] ?? '');
        $precision = (string) ($release['datePrecision'] ?? 'day');
        $confidence = (string) ($release['confidence'] ?? 'editorial');
        if ($id === '' || $id !== $key || $title === '' || (!($precision === 'tbd' && $date === '') && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || DateTimeImmutable::createFromFormat('!Y-m-d', $date)?->format('Y-m-d') !== $date))) {
            return null;
        }
        if (!in_array($mediaType, $mediaTypes, true) || !in_array($precision, $precisions, true) || !in_array($confidence, $confidences, true)) {
            return null;
        }
        $source = trim((string) ($release['source'] ?? ''));
        if (!filter_var($source, FILTER_VALIDATE_URL) || !in_array(strtolower((string) parse_url($source, PHP_URL_SCHEME)), ['http', 'https'], true)) return null;
        $tags = is_array($release['tags'] ?? null) ? array_values(array_filter(array_map(
            static fn (mixed $tag): string => mb_substr(trim((string) $tag), 0, 80),
            $release['tags'],
        ))) : [];
        $saves[$id] = [
            'id' => $id,
            'title' => mb_substr($title, 0, 255),
            'creator' => mb_substr(trim((string) ($release['creator'] ?? '')), 0, 255),
            'mediaType' => $mediaType,
            'date' => $date,
            'datePrecision' => $precision,
            'publisher' => mb_substr(trim((string) ($release['publisher'] ?? '')), 0, 255),
            'platform' => mb_substr(trim((string) ($release['platform'] ?? '')), 0, 255),
            'region' => mb_substr(trim((string) ($release['region'] ?? '')), 0, 80),
            'tags' => array_slice($tags, 0, 24),
            'source' => mb_substr(trim((string) ($release['source'] ?? '')), 0, 1000),
            'sourceName' => mb_substr(trim((string) ($release['sourceName'] ?? '')), 0, 255),
            'confidence' => $confidence,
            'artwork' => mb_substr(trim((string) ($release['artwork'] ?? '')), 0, 1000),
            'synopsis' => mb_substr(trim((string) ($release['synopsis'] ?? '')), 0, 4000),
            'verifiedAt' => is_string($release['verifiedAt'] ?? null) ? $release['verifiedAt'] : null,
            'reception' => is_array($release['reception'] ?? null) ? $release['reception'] : [],
            'trailer' => mb_substr(trim((string) ($release['trailer'] ?? '')), 0, 1000),
        ];
    }
    return $saves;
}


function ghastly_claim_saved_transfer(PDO $database, string $hash): ?array
{
    // The conditional update is atomic: concurrent claims cannot both affect a row.
    $claim = $database->prepare('UPDATE saved_transfers SET claimed_at = UTC_TIMESTAMP() WHERE token_hash = :hash AND claimed_at IS NULL AND expires_at > UTC_TIMESTAMP()');
    $claim->execute(['hash' => $hash]);
    if ($claim->rowCount() !== 1) return null;
    $select = $database->prepare('SELECT payload_json FROM saved_transfers WHERE token_hash = :hash LIMIT 1');
    $select->execute(['hash' => $hash]);
    $row = $select->fetch();
    return $row ? ghastly_saved_transfer_normalize(json_decode((string) $row['payload_json'], true)) : null;
}
