<?php

declare(strict_types=1);

// Only a successful, well-formed response may replace a cached snapshot.
function ghastly_movie_watch_offers(array $payload): array
{
    if (!isset($payload['results']) || !is_array($payload['results'])) {
        throw new RuntimeException('Invalid TMDB watch-provider response.');
    }
    $us = $payload['results']['US'] ?? [];
    if (!is_array($us)) throw new RuntimeException('Invalid U.S. watch-provider response.');
    $offers = [];
    foreach (['flatrate', 'free', 'ads', 'rent', 'buy'] as $kind) {
        if (isset($us[$kind]) && !is_array($us[$kind])) {
            throw new RuntimeException('Invalid watch-provider offers.');
        }
        $providers = [];
        foreach ($us[$kind] ?? [] as $provider) {
            if (!is_array($provider) || !is_string($provider['provider_name'] ?? null)
                || !is_numeric($provider['provider_id'] ?? null)) continue;
            $name = ghastly_text($provider['provider_name'], 120);
            if ($name !== '') $providers[(int) $provider['provider_id']] = $name;
        }
        $offers[$kind] = array_values(array_unique($providers));
    }
    return $offers;
}

function ghastly_refresh_movie_watch(PDO $database, int $limit = 1000): int
{
    $token = trim((string) (ghastly_config()['tmdb_bearer_token'] ?? ''));
    if ($token === '') throw new GhastlySourceUnavailable('TMDB token is not configured.');
    $statement = $database->query(
        "SELECT identity_record.external_id
         FROM release_external_ids identity_record
         INNER JOIN releases r ON r.id = identity_record.release_id
         LEFT JOIN movie_watch_availability watch
           ON watch.tmdb_id = identity_record.external_id AND watch.region = 'US'
         WHERE identity_record.provider = 'tmdb_movie' AND r.active = 1
           AND r.media_type = 'movie' AND r.region = 'US'
           AND r.platform REGEXP '(^|, ?)(Digital|Physical|Streaming|Television)(,|$)'
           AND r.release_date BETWEEN DATE_SUB(UTC_DATE(), INTERVAL 1 YEAR) AND DATE_ADD(UTC_DATE(), INTERVAL 18 MONTH)
           AND (watch.checked_at IS NULL OR watch.checked_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY))
         GROUP BY identity_record.external_id
         ORDER BY MIN(watch.checked_at) IS NULL DESC, MIN(watch.checked_at) ASC, MIN(r.release_date) ASC
         LIMIT " . max(1, min(1000, $limit))
    );
    $upsert = $database->prepare(
        "INSERT INTO movie_watch_availability (tmdb_id, region, offers_json, source_url, checked_at)
         VALUES (:tmdb_id, 'US', :offers_json, :source_url, UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE offers_json = VALUES(offers_json), source_url = VALUES(source_url), checked_at = UTC_TIMESTAMP()"
    );
    $updated = 0;
    $failed = 0;
    foreach ($statement->fetchAll() as $row) {
        $id = (string) $row['external_id'];
        if (!preg_match('/^[0-9]+$/', $id)) continue;
        try {
            $payload = ghastly_http_json('https://api.themoviedb.org/3/movie/' . $id . '/watch/providers', ['Authorization: Bearer ' . $token]);
            $offers = ghastly_movie_watch_offers($payload);
            $upsert->execute([
                'tmdb_id' => $id,
                'offers_json' => json_encode($offers, JSON_THROW_ON_ERROR),
                'source_url' => 'https://www.themoviedb.org/movie/' . $id . '/watch?locale=US',
            ]);
            $updated++;
        } catch (RuntimeException $error) {
            error_log('Movie watch-provider lookup failed for ' . $id . ': ' . $error->getMessage());
            $failed++;
        }
        usleep(100_000);
    }
    if ($failed > 0) throw new RuntimeException("Movie availability refreshed {$updated} snapshots; {$failed} lookups failed. Previous snapshots were retained.");
    return $updated;
}
