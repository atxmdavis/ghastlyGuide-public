<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/src/collectors.php';

$lock = fopen(sys_get_temp_dir() . '/ghastly-guide-ingest.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Another Ghastly Guide ingestion job is already running.\n");
    exit(2);
}

$requestedSources = null;
foreach ($argv as $argument) {
    if (str_starts_with((string) $argument, '--source=')) {
        $requestedSources = array_values(array_filter(array_map('trim', explode(',', substr((string) $argument, 9)))));
    }
}
$allowedSources = ['curated', 'tmdb', 'igdb', 'rawg', 'asmodee', 'lunar', 'prh', 'tvmaze', 'ratings', 'availability'];
$unknownSources = $requestedSources === null ? [] : array_diff($requestedSources, $allowedSources);
if ($requestedSources === [] || $unknownSources !== []) {
    fwrite(STDERR, 'Unknown source: ' . implode(', ', $unknownSources) . "\n");
    exit(2);
}

$today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
$start = $today->modify('-1 year')->format('Y-m-d');
$end = $today->modify('+18 months')->format('Y-m-d');
$collectors = [
    'curated' => static fn (): array => ghastly_collect_curated(),
    'tmdb' => static fn (): array => ghastly_collect_tmdb($start, $end),
    'igdb' => static fn (): array => ghastly_collect_igdb($start, $end),
    'rawg' => static fn (): array => ghastly_collect_rawg($start, $end),
    'asmodee' => static fn (): array => ghastly_collect_asmodee($start, $end),
    'lunar' => static fn (): array => ghastly_collect_lunar($start, $end),
    'prh' => static fn (): array => ghastly_collect_prh($start, $end),
    'tvmaze' => static fn (): array => ghastly_collect_tvmaze($start, $end),
    'ratings' => static fn (): array => [],
    'availability' => static fn (): array => [],
];
if ($requestedSources !== null) {
    $collectors = array_intersect_key($collectors, array_flip($requestedSources));
}

$database = ghastly_db();
$upsert = $database->prepare(<<<'SQL'
INSERT INTO releases
  (canonical_key, external_id, title, creator, media_type, release_date, date_precision, publisher, platform, region, horror_tags, synopsis, source_url, source_name, confidence, source_rank, artwork_url, trailer_url, first_seen_at, last_seen_at, active)
VALUES
  (:canonical_key, :external_id, :title, :creator, :media_type, :release_date, :date_precision, :publisher, :platform, :region, :horror_tags, :synopsis, :source_url, :source_name, :confidence, :source_rank, :artwork_url, :trailer_url, UTC_TIMESTAMP(), UTC_TIMESTAMP(), 1)
ON DUPLICATE KEY UPDATE
  external_id = IF(VALUES(source_rank) >= source_rank, VALUES(external_id), external_id),
  title = IF(VALUES(source_rank) >= source_rank, VALUES(title), title),
  creator = IF(VALUES(source_rank) >= source_rank, VALUES(creator), creator),
  release_date = IF(VALUES(source_rank) >= source_rank, VALUES(release_date), release_date),
  date_precision = IF(VALUES(source_rank) >= source_rank, VALUES(date_precision), date_precision),
  publisher = IF(VALUES(source_rank) >= source_rank, VALUES(publisher), publisher),
  platform = IF(VALUES(source_rank) >= source_rank, VALUES(platform), platform),
  region = IF(VALUES(source_rank) >= source_rank, VALUES(region), region),
  horror_tags = IF(VALUES(source_rank) >= source_rank, VALUES(horror_tags), horror_tags),
  synopsis = IF(VALUES(source_rank) >= source_rank, VALUES(synopsis), synopsis),
  source_url = IF(VALUES(source_rank) >= source_rank, VALUES(source_url), source_url),
  source_name = IF(VALUES(source_rank) >= source_rank, VALUES(source_name), source_name),
  confidence = IF(VALUES(source_rank) >= source_rank, VALUES(confidence), confidence),
  artwork_url = IF(VALUES(source_rank) >= source_rank, VALUES(artwork_url), artwork_url),
  trailer_url = COALESCE(NULLIF(VALUES(trailer_url), ''), trailer_url),
  source_rank = GREATEST(source_rank, VALUES(source_rank)),
  last_seen_at = UTC_TIMESTAMP(), active = 1
SQL);
$deleteSupersededTmdbRecord = $database->prepare(
    "DELETE FROM releases
     WHERE source_name = 'TMDB'
       AND external_id = :external_id
       AND canonical_key <> :canonical_key"
);
$deleteSupersededPrhRecord = $database->prepare(
    "DELETE FROM releases
     WHERE source_name = 'Penguin Random House'
       AND external_id = :external_id
       AND canonical_key <> :canonical_key"
);
$findRelease = $database->prepare('SELECT id FROM releases WHERE canonical_key = :canonical_key');
$upsertExternalId = $database->prepare(
    'INSERT INTO release_external_ids (release_id, provider, external_id, source_url, first_seen_at, last_seen_at)
     VALUES (:release_id, :provider, :external_id, :source_url, UTC_TIMESTAMP(), UTC_TIMESTAMP())
     ON DUPLICATE KEY UPDATE source_url = VALUES(source_url), last_seen_at = UTC_TIMESTAMP()'
);
$upsertRating = $database->prepare(
    'INSERT INTO reception_ratings
       (provider, external_id, rating_kind, score, scale, rating_count, label, source_url, retrieved_at)
     VALUES
       (:provider, :external_id, :rating_kind, :score, :scale, :rating_count, :label, :source_url, UTC_TIMESTAMP())
     ON DUPLICATE KEY UPDATE
       score = VALUES(score), scale = VALUES(scale), rating_count = VALUES(rating_count),
       label = VALUES(label), source_url = VALUES(source_url), retrieved_at = UTC_TIMESTAMP()'
);
$deactivateMissingReleases = $database->prepare(
    "UPDATE releases
     SET active = 0
     WHERE source_name = :source_name
       AND release_date BETWEEN :start AND :end
       AND last_seen_at < :run_started"
);
$completeWindowSources = [
    'tmdb' => 'TMDB',
    'igdb' => 'IGDB',
];

$successful = 0;
$failed = 0;
foreach ($collectors as $slug => $collector) {
    $database->prepare("INSERT INTO source_runs (source_slug, status) VALUES (:slug, 'running')")->execute(['slug' => $slug]);
    $runId = (int) $database->lastInsertId();
    try {
        if ($slug === 'ratings' || $slug === 'availability') {
            $count = $slug === 'ratings' ? ghastly_refresh_steam_ratings($database) : ghastly_refresh_movie_watch($database);
            $database->prepare("UPDATE source_runs SET completed_at = UTC_TIMESTAMP(), status = 'ok', found_count = :count WHERE id = :id")
                ->execute(['count' => $count, 'id' => $runId]);
            printf("%-12s ok %d snapshots\n", $slug, $count);
            $successful++;
            continue;
        }
        $runStarted = gmdate('Y-m-d H:i:s');
        $releases = $collector();
        foreach ($releases as $release) {
            $externalIds = is_array($release['_external_ids'] ?? null) ? $release['_external_ids'] : [];
            $ratings = is_array($release['_ratings'] ?? null) ? $release['_ratings'] : [];
            unset($release['_external_ids'], $release['_ratings']);
            $upsert->execute($release);
            if ($externalIds !== []) {
                $findRelease->execute(['canonical_key' => $release['canonical_key']]);
                $releaseId = (int) $findRelease->fetchColumn();
                foreach ($externalIds as $external) {
                    if ($releaseId <= 0 || !is_array($external)) {
                        continue;
                    }
                    $upsertExternalId->execute([
                        'release_id' => $releaseId,
                        'provider' => $external['provider'],
                        'external_id' => $external['external_id'],
                        'source_url' => $external['source_url'],
                    ]);
                }
            }
            foreach ($ratings as $rating) {
                if (is_array($rating)) {
                    $upsertRating->execute($rating);
                }
            }
            if ($slug === 'tmdb' && is_string($release['external_id'] ?? null) && $release['external_id'] !== '') {
                $deleteSupersededTmdbRecord->execute([
                    'external_id' => $release['external_id'],
                    'canonical_key' => $release['canonical_key'],
                ]);
            }
            if ($slug === 'prh' && is_string($release['external_id'] ?? null) && $release['external_id'] !== '') {
                $deleteSupersededPrhRecord->execute([
                    'external_id' => $release['external_id'],
                    'canonical_key' => $release['canonical_key'],
                ]);
            }
        }
        $deactivated = 0;
        if (isset($completeWindowSources[$slug]) && $releases !== []) {
            $deactivateMissingReleases->execute([
                'source_name' => $completeWindowSources[$slug],
                'start' => $start,
                'end' => $end,
                'run_started' => $runStarted,
            ]);
            $deactivated = $deactivateMissingReleases->rowCount();
        }
        $database->prepare("UPDATE source_runs SET completed_at = UTC_TIMESTAMP(), status = 'ok', found_count = :count WHERE id = :id")->execute(['count' => count($releases), 'id' => $runId]);
        printf("%-12s ok %d releases, %d deactivated\n", $slug, count($releases), $deactivated);
        $successful++;
    } catch (GhastlySourceUnavailable $error) {
        $database->prepare("UPDATE source_runs SET completed_at = UTC_TIMESTAMP(), status = 'skipped', error_message = :message WHERE id = :id")->execute(['message' => mb_substr($error->getMessage(), 0, 1000), 'id' => $runId]);
        printf("%-12s skipped: %s\n", $slug, $error->getMessage());
    } catch (Throwable $error) {
        $database->prepare("UPDATE source_runs SET completed_at = UTC_TIMESTAMP(), status = 'failed', error_message = :message WHERE id = :id")->execute(['message' => mb_substr($error->getMessage(), 0, 1000), 'id' => $runId]);
        fprintf(STDERR, "%-12s failed: %s\n", $slug, $error->getMessage());
        $failed++;
    }
}
exit($successful > 0 && $failed === 0 ? 0 : 1);
