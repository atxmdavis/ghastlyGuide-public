<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/http.php';
require_once __DIR__ . '/watch-providers.php';

const GHASTLY_MEDIA_TYPES = ['movie', 'show', 'video_game', 'tabletop_game', 'comic', 'book'];
const GHASTLY_CONFIDENCE = ['official', 'partner', 'database', 'editorial'];

final class GhastlySourceUnavailable extends RuntimeException
{
}

function ghastly_text(mixed $value, int $limit = 255): string
{
    $decoded = html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = trim(preg_replace('/\s+/u', ' ', $decoded) ?? '');
    return mb_substr($text, 0, $limit);
}

function ghastly_valid_date(mixed $value): bool
{
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return false;
    }
    return DateTimeImmutable::createFromFormat('!Y-m-d', $value)?->format('Y-m-d') === $value;
}

function ghastly_partial_date(mixed $value): ?array
{
    if (!is_string($value)) {
        return null;
    }
    $value = trim($value);
    if (ghastly_valid_date($value)) {
        return ['date' => $value, 'precision' => 'day'];
    }
    if (preg_match('/^(\d{4})-(\d{2})$/', $value, $match)) {
        $date = $match[1] . '-' . $match[2] . '-01';
        return ghastly_valid_date($date) ? ['date' => $date, 'precision' => 'month'] : null;
    }
    if (preg_match('/^(\d{4})$/', $value, $match)) {
        return ['date' => $match[1] . '-01-01', 'precision' => 'year'];
    }
    if (preg_match('/^(\d{4}-\d{2}-\d{2})T/', $value, $match)) {
        return ghastly_valid_date($match[1]) ? ['date' => $match[1], 'precision' => 'day'] : null;
    }
    if (preg_match('/[A-Za-z]/', $value)) {
        $timestamp = strtotime($value . ' UTC');
        if ($timestamp !== false) {
            $date = gmdate('Y-m-d', $timestamp);
            $precision = preg_match('/^[A-Za-z]+\s+\d{4}$/', trim($value)) ? 'month' : 'day';
            return ghastly_valid_date($date) ? ['date' => $date, 'precision' => $precision] : null;
        }
    }
    return null;
}

function ghastly_date_overlaps_window(array $parsed, string $start, string $end): bool
{
    $date = (string) ($parsed['date'] ?? '');
    $precision = (string) ($parsed['precision'] ?? 'day');
    if (!ghastly_valid_date($date) || !ghastly_valid_date($start) || !ghastly_valid_date($end)) {
        return false;
    }
    if ($precision === 'year') {
        $year = substr($date, 0, 4);
        return $year . '-12-31' >= $start && $year . '-01-01' <= $end;
    }
    if ($precision === 'month') {
        $monthStart = substr($date, 0, 7) . '-01';
        $monthEnd = (new DateTimeImmutable($monthStart, new DateTimeZone('UTC')))->modify('last day of this month')->format('Y-m-d');
        return $monthEnd >= $start && $monthStart <= $end;
    }
    return $date >= $start && $date <= $end;
}

function ghastly_config_strings(mixed $value): array
{
    $values = is_array($value) ? $value : preg_split('/[,|]/', (string) $value);
    return array_values(array_unique(array_filter(array_map(
        static fn (mixed $item): string => trim((string) $item),
        is_array($values) ? $values : [],
    ))));
}

function ghastly_normalize_release(array $release): array
{
    $mediaType = (string) ($release['media_type'] ?? '');
    $confidence = (string) ($release['confidence'] ?? '');
    $date = $release['release_date'] ?? null;
    if (!in_array($mediaType, GHASTLY_MEDIA_TYPES, true)) {
        throw new RuntimeException('Release has an invalid media type.');
    }
    if (!in_array($confidence, GHASTLY_CONFIDENCE, true)) {
        throw new RuntimeException('Release has an invalid confidence value.');
    }
    if ($date !== null && !ghastly_valid_date($date)) {
        throw new RuntimeException('Release has an invalid date.');
    }
    $title = ghastly_text($release['title'] ?? '');
    $sourceUrl = filter_var($release['source_url'] ?? null, FILTER_VALIDATE_URL);
    if ($title === '' || $sourceUrl === false) {
        throw new RuntimeException('Release requires a title and valid source URL.');
    }
    $externalId = ghastly_text($release['external_id'] ?? '', 160);
    $identity = implode('|', [
        $mediaType,
        $externalId ?: mb_strtolower($title),
        mb_strtolower((string) ($release['platform'] ?? '')),
        mb_strtolower((string) ($release['region'] ?? '')),
    ]);
    $tags = array_values(array_unique(array_filter(array_map(
        static fn (mixed $tag): string => ghastly_text($tag, 80),
        is_array($release['horror_tags'] ?? null) ? $release['horror_tags'] : [],
    ))));
    return [
        'canonical_key' => hash('sha256', $identity),
        'external_id' => $externalId ?: null,
        'title' => $title,
        'creator' => ghastly_text($release['creator'] ?? ''),
        'media_type' => $mediaType,
        'release_date' => $date,
        'date_precision' => in_array($release['date_precision'] ?? null, ['day', 'month', 'year', 'tbd'], true) ? $release['date_precision'] : ($date ? 'day' : 'tbd'),
        'publisher' => ghastly_text($release['publisher'] ?? ''),
        'platform' => ($value = ghastly_text($release['platform'] ?? '')) !== '' ? $value : null,
        'region' => ($value = ghastly_text($release['region'] ?? '', 80)) !== '' ? $value : null,
        'horror_tags' => $tags === [] ? null : json_encode($tags, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        'synopsis' => ($value = ghastly_text($release['synopsis'] ?? '', 4000)) !== '' ? $value : null,
        'source_url' => (string) $sourceUrl,
        'source_name' => ghastly_text($release['source_name'] ?? 'Source'),
        'confidence' => $confidence,
        'source_rank' => max(1, min(100, (int) ($release['source_rank'] ?? 1))),
        'artwork_url' => filter_var($release['artwork_url'] ?? null, FILTER_VALIDATE_URL) ?: null,
        'trailer_url' => ghastly_youtube_watch_url($release['trailer_url'] ?? $release['trailer_id'] ?? null),
    ];
}

function ghastly_with_reception(array $release, array $externalIds, array $ratings = []): array
{
    $release['_external_ids'] = $externalIds;
    $release['_ratings'] = $ratings;
    return $release;
}

function ghastly_tmdb_reception(string $provider, string $id, array $item, string $sourceUrl): array
{
    $externalIds = [[
        'provider' => $provider,
        'external_id' => $id,
        'source_url' => $sourceUrl,
    ]];
    $score = $item['vote_average'] ?? null;
    $count = (int) ($item['vote_count'] ?? 0);
    $ratings = is_numeric($score) && (float) $score >= 0 && $count > 0 ? [[
        'provider' => $provider,
        'external_id' => $id,
        'rating_kind' => 'user',
        'score' => round((float) $score, 2),
        'scale' => 10,
        'rating_count' => $count,
        'label' => null,
        'source_url' => $sourceUrl,
    ]] : [];
    return [$externalIds, $ratings];
}

function ghastly_igdb_external_ids(array $game, string $slug): array
{
    $ids = [[
        'provider' => 'igdb',
        'external_id' => (string) ($game['id'] ?? ''),
        'source_url' => 'https://www.igdb.com/games/' . rawurlencode($slug),
    ]];
    foreach (is_array($game['external_games'] ?? null) ? $game['external_games'] : [] as $external) {
        if (!is_array($external)) {
            continue;
        }
        $source = (int) ($external['external_game_source'] ?? $external['category'] ?? 0);
        $steamId = trim((string) ($external['uid'] ?? ''));
        if ($source !== 1 || !preg_match('/^\d+$/', $steamId)) {
            continue;
        }
        $ids[] = [
            'provider' => 'steam',
            'external_id' => $steamId,
            'source_url' => 'https://store.steampowered.com/app/' . rawurlencode($steamId) . '/',
        ];
    }
    return array_values(array_filter($ids, static fn (array $id): bool => $id['external_id'] !== ''));
}

function ghastly_refresh_steam_ratings(PDO $database, int $limit = 150): int
{
    $statement = $database->prepare(
        "SELECT identity_record.external_id,
                MAX(identity_record.source_url) AS source_url,
                MAX(release_record.release_date) AS latest_release_date,
                MIN(rating.retrieved_at) AS rating_retrieved_at
         FROM release_external_ids identity_record
         INNER JOIN releases release_record ON release_record.id = identity_record.release_id
         LEFT JOIN reception_ratings rating
           ON rating.provider = identity_record.provider
          AND rating.external_id = identity_record.external_id
          AND rating.rating_kind = 'user'
         WHERE identity_record.provider = 'steam'
           AND release_record.active = 1
           AND release_record.release_date BETWEEN DATE_SUB(UTC_DATE(), INTERVAL 1 YEAR) AND UTC_DATE()
           AND (rating.retrieved_at IS NULL OR rating.retrieved_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY))
         GROUP BY identity_record.external_id
         ORDER BY rating_retrieved_at IS NULL DESC, latest_release_date DESC, rating_retrieved_at ASC
         LIMIT " . max(1, min(500, $limit))
    );
    $statement->execute();
    $upsert = $database->prepare(
        "INSERT INTO reception_ratings
           (provider, external_id, rating_kind, score, scale, rating_count, label, source_url, retrieved_at)
         VALUES
           ('steam', :external_id, 'user', :score, 100, :rating_count, :label, :source_url, UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE
           score = VALUES(score), rating_count = VALUES(rating_count), label = VALUES(label),
           source_url = VALUES(source_url), retrieved_at = UTC_TIMESTAMP()"
    );
    $updated = 0;
    $failed = 0;
    foreach ($statement->fetchAll() as $row) {
        $steamId = (string) $row['external_id'];
        try {
            $payload = ghastly_http_json('https://store.steampowered.com/appreviews/' . rawurlencode($steamId) . '?' . http_build_query([
                'json' => '1',
                'language' => 'all',
                'filter' => 'all',
                'review_type' => 'all',
                'purchase_type' => 'steam',
                'filter_offtopic_activity' => '1',
                'num_per_page' => '1',
            ]));
        } catch (RuntimeException $error) {
            error_log("Steam reception lookup failed for app {$steamId}: " . $error->getMessage());
            $failed++;
            continue;
        }
        if ((int) ($payload['success'] ?? 0) !== 1) {
            $failed++;
            continue;
        }
        $summary = is_array($payload['query_summary'] ?? null) ? $payload['query_summary'] : [];
        $positive = (int) ($summary['total_positive'] ?? 0);
        $negative = (int) ($summary['total_negative'] ?? 0);
        $count = $positive + $negative;
        $upsert->execute([
            'external_id' => $steamId,
            'score' => $count > 0 ? round($positive * 100 / $count, 2) : 0,
            'rating_count' => $count,
            'label' => ghastly_text($summary['review_score_desc'] ?? '', 120) ?: null,
            'source_url' => (string) $row['source_url'],
        ]);
        $updated++;
        usleep(100_000);
    }
    if ($updated === 0 && $failed > 0) {
        throw new RuntimeException('Steam reception refresh failed for every attempted app.');
    }
    return $updated;
}

function ghastly_youtube_video_id(mixed $value): ?string
{
    if (!is_string($value) || $value === '') {
        return null;
    }
    $value = trim($value);
    if (preg_match('/^[A-Za-z0-9_-]{11}$/', $value)) {
        return $value;
    }
    $url = filter_var($value, FILTER_VALIDATE_URL);
    if ($url === false) {
        return null;
    }
    $parts = parse_url($url);
    $host = strtolower((string) ($parts['host'] ?? ''));
    $path = (string) ($parts['path'] ?? '');
    $query = [];
    parse_str((string) ($parts['query'] ?? ''), $query);
    if (in_array($host, ['youtu.be', 'www.youtu.be'], true)) {
        $candidate = trim($path, '/');
        return preg_match('/^[A-Za-z0-9_-]{11}$/', $candidate) ? $candidate : null;
    }
    if (!preg_match('/(?:^|\.)youtube(?:-nocookie)?\.com$/', $host)) {
        return null;
    }
    if (is_string($query['v'] ?? null) && preg_match('/^[A-Za-z0-9_-]{11}$/', $query['v'])) {
        return $query['v'];
    }
    if (preg_match('#/(?:embed|shorts)/([A-Za-z0-9_-]{11})#', $path, $match)) {
        return $match[1];
    }
    return null;
}

function ghastly_youtube_watch_url(mixed $value): ?string
{
    $videoId = ghastly_youtube_video_id($value);
    return $videoId === null ? null : 'https://www.youtube.com/watch?v=' . $videoId;
}

function ghastly_pick_official_youtube_key(array $videos): ?string
{
    $eligible = [];
    foreach ($videos as $video) {
        if (!is_array($video) || ($video['site'] ?? '') !== 'YouTube') {
            continue;
        }
        $type = (string) ($video['type'] ?? '');
        if (!in_array($type, ['Trailer', 'Teaser'], true)) {
            continue;
        }
        $key = (string) ($video['key'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_-]{11}$/', $key)) {
            continue;
        }
        $eligible[] = $video;
    }
    if ($eligible === []) {
        return null;
    }
    usort($eligible, static function (array $left, array $right): int {
        $score = static function (array $video): int {
            $score = ($video['type'] ?? '') === 'Trailer' ? 8 : 4;
            if (!empty($video['official'])) {
                $score += 4;
            }
            if (preg_match('/official\s+trailer/i', (string) ($video['name'] ?? ''))) {
                $score += 3;
            }
            if (($video['iso_639_1'] ?? '') === 'en') {
                $score += 1;
            }
            return $score;
        };
        return $score($right) <=> $score($left);
    });
    return (string) $eligible[0]['key'];
}

function ghastly_tmdb_youtube_trailer(string $media, string $tmdbId, string $token, array &$cache): ?string
{
    $cacheKey = $media . ':' . $tmdbId;
    if (!array_key_exists($cacheKey, $cache)) {
        $path = $media === 'tv' ? 'tv' : 'movie';
        try {
            $payload = ghastly_http_json(
                'https://api.themoviedb.org/3/' . $path . '/' . rawurlencode($tmdbId) . '/videos',
                ['Authorization: Bearer ' . $token],
            );
            $cache[$cacheKey] = ghastly_youtube_watch_url(ghastly_pick_official_youtube_key(
                is_array($payload['results'] ?? null) ? $payload['results'] : [],
            ));
        } catch (Throwable) {
            $cache[$cacheKey] = null;
        }
        usleep(80_000);
    }
    return $cache[$cacheKey];
}

function ghastly_youtube_search_trailer(string $title, string $year, array &$cache, int &$remaining): ?string
{
    $config = ghastly_config();
    $apiKey = trim((string) ($config['youtube_api_key'] ?? ''));
    if ($apiKey === '' || $title === '' || $remaining <= 0) {
        return null;
    }
    $cacheKey = mb_strtolower($title) . '|' . $year;
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }
    $remaining--;
    try {
        $payload = ghastly_http_json('https://www.googleapis.com/youtube/v3/search?' . http_build_query([
            'part' => 'snippet',
            'type' => 'video',
            'videoEmbeddable' => 'true',
            'maxResults' => '5',
            'q' => trim($title . ' official trailer ' . $year),
            'key' => $apiKey,
        ]));
    } catch (Throwable) {
        $cache[$cacheKey] = null;
        return null;
    }
    usleep(80_000);
    $chosen = null;
    foreach (is_array($payload['items'] ?? null) ? $payload['items'] : [] as $item) {
        if (!is_array($item)) {
            continue;
        }
        $videoId = (string) ($item['id']['videoId'] ?? '');
        $snippetTitle = (string) ($item['snippet']['title'] ?? '');
        $channel = (string) ($item['snippet']['channelTitle'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_-]{11}$/', $videoId)) {
            continue;
        }
        if (preg_match('/reaction|fan\s*made|gameplay|review|leak|unofficial/i', $snippetTitle . ' ' . $channel)) {
            continue;
        }
        if (preg_match('/official\s+trailer|\btrailer\b/i', $snippetTitle)) {
            $chosen = ghastly_youtube_watch_url($videoId);
            if (preg_match('/official\s+trailer/i', $snippetTitle)) {
                break;
            }
        }
    }
    $cache[$cacheKey] = $chosen;
    return $chosen;
}

function ghastly_resolve_trailer_url(
    string $title,
    string $date,
    string $tmdbId,
    string $tmdbMedia,
    string $token,
    array &$tmdbVideoCache,
    array &$youtubeCache,
    int &$youtubeRemaining,
): ?string
{
    $fromTmdb = ghastly_tmdb_youtube_trailer($tmdbMedia, $tmdbId, $token, $tmdbVideoCache);
    if ($fromTmdb !== null) {
        return $fromTmdb;
    }
    return ghastly_youtube_search_trailer($title, substr($date, 0, 4), $youtubeCache, $youtubeRemaining);
}

function ghastly_pick_game_youtube_trailer(array $videos): ?string
{
    $eligible = [];
    foreach ($videos as $video) {
        if (!is_array($video)) {
            continue;
        }
        $videoId = (string) ($video['video_id'] ?? '');
        $name = (string) ($video['name'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_-]{11}$/', $videoId)) {
            continue;
        }
        if (preg_match('/gameplay|review|walkthrough|let.?s play|reaction|unboxing|stream/i', $name)) {
            continue;
        }
        if ($name === '' || !preg_match('/trailer|teaser|announce|cinematic/i', $name)) {
            continue;
        }
        $eligible[] = ['video_id' => $videoId, 'name' => $name];
    }
    if ($eligible === []) {
        return null;
    }
    usort($eligible, static function (array $left, array $right): int {
        $score = static function (array $video): int {
            $name = $video['name'];
            $score = 0;
            if (preg_match('/official\s+trailer/i', $name)) {
                $score += 8;
            } elseif (preg_match('/\btrailer\b/i', $name)) {
                $score += 6;
            } elseif (preg_match('/\bteaser\b/i', $name)) {
                $score += 3;
            }
            if (preg_match('/announce|cinematic/i', $name)) {
                $score += 1;
            }
            return $score;
        };
        return $score($right) <=> $score($left);
    });
    return ghastly_youtube_watch_url($eligible[0]['video_id']);
}

function ghastly_rawg_clip_trailer(array $game): ?string
{
    $clip = is_array($game['clip'] ?? null) ? $game['clip'] : [];
    foreach (['video', 'clip', 'preview'] as $key) {
        $url = ghastly_youtube_watch_url($clip[$key] ?? null);
        if ($url !== null) {
            return $url;
        }
    }
    return null;
}

function ghastly_collect_curated(): array
{
    $path = dirname(__DIR__) . '/data/curated-releases.json';
    $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new RuntimeException('Curated release file must contain a JSON array.');
    }
    return array_map('ghastly_normalize_release', $decoded);
}

function ghastly_tmdb_pages(string $path, array $query, string $token, int $maxPages = 5): array
{
    $results = [];
    $maxPages = max(1, min(20, $maxPages));
    for ($page = 1; $page <= $maxPages; $page++) {
        $query['page'] = (string) $page;
        $payload = ghastly_http_json('https://api.themoviedb.org/3/' . $path . '?' . http_build_query($query), [
            'Authorization: Bearer ' . $token,
        ]);
        $items = is_array($payload['results'] ?? null) ? $payload['results'] : [];
        $results = array_merge($results, $items);
        $totalPages = max(1, (int) ($payload['total_pages'] ?? 1));
        if ($page === 1 && $totalPages > $maxPages) {
            throw new RuntimeException('TMDB query exceeded the safe page limit; narrow its date range.');
        }
        if ($page >= $totalPages) {
            break;
        }
        usleep(250_000);
    }
    return $results;
}

function ghastly_tmdb_month_windows(string $start, string $end): array
{
    if (!ghastly_valid_date($start) || !ghastly_valid_date($end) || $start > $end) {
        throw new RuntimeException('TMDB requires a valid release-date range.');
    }
    $cursor = new DateTimeImmutable($start, new DateTimeZone('UTC'));
    $final = new DateTimeImmutable($end, new DateTimeZone('UTC'));
    $windows = [];
    while ($cursor <= $final) {
        $windowEnd = $cursor->modify('last day of this month');
        if ($windowEnd > $final) {
            $windowEnd = $final;
        }
        $windows[] = ['start' => $cursor->format('Y-m-d'), 'end' => $windowEnd->format('Y-m-d')];
        $cursor = $windowEnd->modify('+1 day');
    }
    return $windows;
}

function ghastly_tmdb_tv_horror_keyword_ids(array $config): array
{
    $configured = ghastly_config_strings($config['tmdb_tv_horror_keyword_ids'] ?? ($config['tmdb_tv_horror_keyword_id'] ?? ''));
    if ($configured !== []) {
        return $configured;
    }
    return ['315058', '250593', '256183', '15032', '12339', '287274'];
}

function ghastly_tmdb_discover_tv_by_keywords(array $query, array $keywords, string $token): array
{
    if ($keywords === []) {
        return [];
    }
    try {
        return ghastly_tmdb_pages('discover/tv', $query + ['with_keywords' => implode('|', $keywords)], $token, 5);
    } catch (RuntimeException $error) {
        if (!str_contains($error->getMessage(), 'safe page limit') || count($keywords) === 1) {
            throw $error;
        }
        $results = [];
        foreach ($keywords as $keyword) {
            $results = array_merge($results, ghastly_tmdb_discover_tv_by_keywords($query, [$keyword], $token));
        }
        return $results;
    }
}

function ghastly_tmdb_tv_details(string $id, string $token, array &$cache): ?array
{
    if (array_key_exists($id, $cache)) {
        return $cache[$id];
    }
    $payload = ghastly_http_json(
        'https://api.themoviedb.org/3/tv/' . rawurlencode($id),
        ['Authorization: Bearer ' . $token],
    );
    $cache[$id] = $payload;
    usleep(80_000);
    return $cache[$id];
}

function ghastly_tmdb_tv_season_date(array $details, array $season): ?string
{
    $date = $season['air_date'] ?? null;
    if (ghastly_valid_date($date)) {
        return $date;
    }
    if ((int) ($season['season_number'] ?? 0) === 1) {
        $first = $details['first_air_date'] ?? null;
        return ghastly_valid_date($first) ? $first : null;
    }
    return null;
}

function ghastly_tmdb_is_international_movie(array $movie): bool
{
    $countries = array_map(
        static fn (string $country): string => strtoupper($country),
        ghastly_config_strings($movie['origin_country'] ?? []),
    );
    if ($countries !== []) {
        return !in_array('US', $countries, true);
    }
    $originalLanguage = strtolower(trim((string) ($movie['original_language'] ?? '')));
    return $originalLanguage !== '' && $originalLanguage !== 'en';
}

function ghastly_tmdb_physical_metadata(
    string $movieId,
    string $region,
    string $date,
    string $token,
    array &$cache,
): array {
    if (!array_key_exists($movieId, $cache)) {
        $cache[$movieId] = ghastly_http_json(
            'https://api.themoviedb.org/3/movie/' . rawurlencode($movieId) . '/release_dates',
            ['Authorization: Bearer ' . $token],
        );
        usleep(80_000);
    }
    $notes = [];
    $regionalReleases = [];
    foreach (is_array($cache[$movieId]['results'] ?? null) ? $cache[$movieId]['results'] : [] as $country) {
        if (!is_array($country) || strtoupper((string) ($country['iso_3166_1'] ?? '')) !== strtoupper($region)) {
            continue;
        }
        $regionalReleases = is_array($country['release_dates'] ?? null) ? $country['release_dates'] : [];
        foreach ($regionalReleases as $release) {
            $releaseDate = substr((string) ($release['release_date'] ?? ''), 0, 10);
            if ((int) ($release['type'] ?? 0) === 5 && $releaseDate === $date) {
                $releaseNote = ghastly_text($release['note'] ?? '', 160);
                if ($releaseNote !== '') {
                    $notes[] = $releaseNote;
                }
            }
        }
        break;
    }
    $note = implode(' · ', array_values(array_unique($notes)));
    $tagPatterns = [
        '4K UHD' => '/(?:\b4k\b|\bultra\s*hd\b)/i',
        'Blu-ray' => '/\bblu[ -]?ray\b/i',
        'DVD' => '/\bdvd\b/i',
        'VHS' => '/\bvhs\b/i',
        'Steelbook' => '/\bsteelbook\b/i',
        'Criterion' => '/\bcriterion\b/i',
        'Collector edition' => '/\bcollector(?:\'s)? edition\b/i',
        'Remastered' => '/\bremaster(?:ed)?\b/i',
        'Re-release' => '/\bre[ -]?release\b/i',
    ];
    $tags = [];
    foreach ($tagPatterns as $tag => $pattern) {
        if ($note !== '' && preg_match($pattern, $note)) {
            $tags[] = $tag;
        }
    }
    $hasEarlierPhysical = false;
    $earliestRegionalDate = null;
    $streamingServices = [
        'Netflix' => '/\bnetflix\b/i',
        'Shudder' => '/\bshudder\b/i',
        'Hulu' => '/\bhulu\b/i',
        'Max' => '/\b(?:hbo\s+)?max\b/i',
        'Peacock' => '/\bpeacock\b/i',
        'Paramount+' => '/\bparamount\s*\+/i',
        'Prime Video' => '/\b(?:amazon\s+)?prime video\b/i',
        'AMC+' => '/\bamc\s*\+/i',
        'Disney+' => '/\bdisney\s*\+/i',
        'Apple TV+' => '/\bapple tv\s*\+/i',
    ];
    $previousStreamingServices = [];
    foreach ($regionalReleases as $release) {
        $releaseDate = substr((string) ($release['release_date'] ?? ''), 0, 10);
        if (!ghastly_valid_date($releaseDate)) {
            continue;
        }
        if ($earliestRegionalDate === null || $releaseDate < $earliestRegionalDate) {
            $earliestRegionalDate = $releaseDate;
        }
        if ($releaseDate >= $date) {
            continue;
        }
        $releaseType = (int) ($release['type'] ?? 0);
        if ($releaseType === 5) {
            $hasEarlierPhysical = true;
        }
        if ($releaseType !== 4) {
            continue;
        }
        $digitalNote = ghastly_text($release['note'] ?? '', 160);
        foreach ($streamingServices as $service => $pattern) {
            if ($digitalNote !== '' && preg_match($pattern, $digitalNote)) {
                $previousStreamingServices[] = $service;
            }
        }
    }
    $recentReleaseCutoff = (new DateTimeImmutable($date, new DateTimeZone('UTC')))->modify('-3 years')->format('Y-m-d');
    if (!$hasEarlierPhysical
        && $previousStreamingServices !== []
        && $earliestRegionalDate !== null
        && $earliestRegionalDate >= $recentReleaseCutoff
    ) {
        $tags[] = 'Physical debut';
    }
    $previousStreamingServices = array_values(array_unique($previousStreamingServices));
    if ($previousStreamingServices !== []) {
        foreach ($previousStreamingServices as $service) {
            $tags[] = 'Previously on ' . $service;
        }
    }
    return ['note' => $note, 'tags' => $tags];
}

function ghastly_collect_tmdb(string $start, string $end): array
{
    $config = ghastly_config();
    $token = trim((string) ($config['tmdb_bearer_token'] ?? ''));
    if ($token === '') {
        throw new GhastlySourceUnavailable('TMDB bearer token is not configured.');
    }
    $region = trim((string) ($config['tmdb_region'] ?? 'US')) ?: 'US';
    $base = [
        'include_adult' => 'false',
        'language' => 'en-US',
        'sort_by' => 'primary_release_date.asc',
        'with_genres' => '27',
        'region' => $region,
    ];
    $releases = [];
    $seen = [];
    $releaseDateCache = [];
    $tmdbVideoCache = [];
    $youtubeCache = [];
    $youtubeRemaining = max(0, (int) ($config['youtube_trailer_lookups_per_run'] ?? 40));
    $releaseTypes = [
        '3' => 'Theatrical',
        '2' => 'Limited theatrical',
        '4' => 'Digital',
        '5' => 'Physical',
        '6' => 'Television',
    ];
    $configuredTypes = ghastly_config_strings($config['tmdb_movie_release_types'] ?? array_keys($releaseTypes));
    foreach ($configuredTypes as $releaseType) {
        if (!isset($releaseTypes[$releaseType])) {
            continue;
        }
        foreach (ghastly_tmdb_month_windows($start, $end) as $window) {
            $query = $base + [
                'with_release_type' => $releaseType,
                'release_date.gte' => $window['start'],
                'release_date.lte' => $window['end'],
            ];
            foreach (ghastly_tmdb_pages('discover/movie', $query, $token, 20) as $movie) {
                $date = $movie['release_date'] ?? null;
                $id = (string) ($movie['id'] ?? '');
                $externalId = 'tmdb-movie-' . $id . '-' . $releaseType;
                if (!ghastly_valid_date($date) || $id === '' || isset($seen[$externalId])) {
                    continue;
                }
                $tags = ['Horror'];
                if (ghastly_tmdb_is_international_movie($movie)) {
                    $tags[] = 'International cinema';
                }
                $platform = $releaseTypes[$releaseType];
                if ($releaseType === '5') {
                    $physical = ghastly_tmdb_physical_metadata($id, $region, $date, $token, $releaseDateCache);
                    $tags = array_merge($tags, $physical['tags']);
                }
                $sourceUrl = 'https://www.themoviedb.org/movie/' . rawurlencode($id);
                [$externalIds, $ratings] = ghastly_tmdb_reception('tmdb_movie', $id, $movie, $sourceUrl);
                $releases[] = ghastly_with_reception(ghastly_normalize_release([
                    'external_id' => $externalId,
                    'title' => $movie['title'] ?? ($movie['original_title'] ?? ''),
                    'creator' => '',
                    'media_type' => 'movie',
                    'release_date' => $date,
                    'publisher' => '',
                    'platform' => $platform,
                    'region' => $region,
                    'horror_tags' => $tags,
                    'synopsis' => $movie['overview'] ?? '',
                    'source_url' => $sourceUrl,
                    'source_name' => 'TMDB',
                    'confidence' => 'partner',
                    'source_rank' => 50,
                    'artwork_url' => !empty($movie['poster_path']) ? 'https://image.tmdb.org/t/p/w500' . $movie['poster_path'] : null,
                    'trailer_url' => ghastly_resolve_trailer_url(
                        (string) ($movie['title'] ?? ($movie['original_title'] ?? '')),
                        $date,
                        $id,
                        'movie',
                        $token,
                        $tmdbVideoCache,
                        $youtubeCache,
                        $youtubeRemaining,
                    ),
                ]), $externalIds, $ratings);
                $seen[$externalId] = true;
            }
        }
    }
    $tvKeywords = ghastly_tmdb_tv_horror_keyword_ids($config);
    $tvShowIds = [];
    $tvDiscoverBase = [
        'include_adult' => 'false',
        'include_null_first_air_dates' => 'false',
        'language' => 'en-US',
    ];
    foreach (ghastly_tmdb_month_windows($start, $end) as $window) {
        foreach (['first_air_date', 'air_date'] as $dateField) {
            $query = $tvDiscoverBase + [
                'sort_by' => $dateField . '.asc',
                $dateField . '.gte' => $window['start'],
                $dateField . '.lte' => $window['end'],
            ];
            foreach (ghastly_tmdb_discover_tv_by_keywords($query, $tvKeywords, $token) as $show) {
                $id = (string) ($show['id'] ?? '');
                if ($id !== '') {
                    $tvShowIds[$id] = true;
                }
            }
        }
    }
    $tvDetailsCache = [];
    foreach (array_keys($tvShowIds) as $showId) {
        $id = (string) $showId;
        $details = ghastly_tmdb_tv_details($id, $token, $tvDetailsCache);
        if (!is_array($details)) {
            continue;
        }
        $title = (string) ($details['name'] ?? '');
        $publisher = '';
        foreach (is_array($details['networks'] ?? null) ? $details['networks'] : [] as $network) {
            if (is_array($network) && trim((string) ($network['name'] ?? '')) !== '') {
                $publisher = (string) $network['name'];
                break;
            }
        }
        $tags = ['Horror'];
        if (ghastly_tmdb_is_international_movie($details)) {
            $tags[] = 'International cinema';
        }
        $seasons = is_array($details['seasons'] ?? null) ? $details['seasons'] : [];
        if ($seasons === []) {
            $seasons = [['season_number' => 1, 'air_date' => $details['first_air_date'] ?? null]];
        }
        foreach ($seasons as $season) {
            if (!is_array($season)) {
                continue;
            }
            $seasonNumber = (int) ($season['season_number'] ?? -1);
            if ($seasonNumber < 1) {
                continue;
            }
            $date = ghastly_tmdb_tv_season_date($details, $season);
            if ($date === null || $date < $start || $date > $end) {
                continue;
            }
            $externalId = $seasonNumber === 1 ? 'tmdb-show-' . $id : 'tmdb-show-' . $id . '-s' . $seasonNumber;
            if ($title === '' || isset($seen[$externalId])) {
                continue;
            }
            $sourceUrl = 'https://www.themoviedb.org/tv/' . rawurlencode($id);
            [$externalIds, $ratings] = ghastly_tmdb_reception('tmdb_tv', $id, $details, $sourceUrl);
            $releases[] = ghastly_with_reception(ghastly_normalize_release([
                'external_id' => $externalId,
                'title' => $title,
                'creator' => '',
                'media_type' => 'show',
                'release_date' => $date,
                'publisher' => $publisher,
                'platform' => $seasonNumber === 1 ? 'Series premiere' : 'Season ' . $seasonNumber . ' premiere',
                'region' => $region,
                'horror_tags' => $tags,
                'synopsis' => $details['overview'] ?? '',
                'source_url' => $sourceUrl,
                'source_name' => 'TMDB',
                'confidence' => 'partner',
                'source_rank' => 50,
                'artwork_url' => !empty($details['poster_path']) ? 'https://image.tmdb.org/t/p/w500' . $details['poster_path'] : null,
                'trailer_url' => ghastly_resolve_trailer_url(
                    $title,
                    $date,
                    $id,
                    'tv',
                    $token,
                    $tmdbVideoCache,
                    $youtubeCache,
                    $youtubeRemaining,
                ),
            ]), $externalIds, $ratings);
            $seen[$externalId] = true;
        }
    }
    return $releases;
}

function ghastly_igdb_access_token(array $config): string
{
    $clientId = trim((string) ($config['igdb_client_id'] ?? ''));
    $clientSecret = trim((string) ($config['igdb_client_secret'] ?? ''));
    if ($clientId === '' || $clientSecret === '') {
        throw new GhastlySourceUnavailable('IGDB client credentials are not configured.');
    }
    try {
        $tokenQuery = http_build_query([
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'grant_type' => 'client_credentials',
        ]);
        $payload = ghastly_http_json('https://id.twitch.tv/oauth2/token?' . $tokenQuery, [], 'POST');
    } catch (RuntimeException $error) {
        throw new RuntimeException('IGDB authentication failed: ' . $error->getMessage(), 0, $error);
    }
    $token = trim((string) ($payload['access_token'] ?? ''));
    if ($token === '') {
        throw new RuntimeException('IGDB authentication returned no access token.');
    }
    return $token;
}

function ghastly_igdb_region(mixed $releaseRegion, mixed $legacyRegion): string
{
    if (is_array($releaseRegion) && is_string($releaseRegion['region'] ?? null)) {
        return ghastly_text($releaseRegion['region'], 80);
    }
    return [
        1 => 'Europe',
        2 => 'North America',
        3 => 'Australia',
        4 => 'New Zealand',
        5 => 'Japan',
        6 => 'China',
        7 => 'Asia',
        8 => 'Worldwide',
        9 => 'Korea',
        10 => 'Brazil',
    ][(int) $legacyRegion] ?? 'Worldwide';
}

function ghastly_igdb_date(array $record): ?array
{
    $year = (int) ($record['y'] ?? 0);
    $month = (int) ($record['m'] ?? 0);
    $day = (int) ($record['d'] ?? 0);
    $timestamp = (int) ($record['date'] ?? 0);
    $format = is_array($record['date_format'] ?? null)
        ? strtoupper(preg_replace('/[^A-Z]/', '', (string) ($record['date_format']['format'] ?? '')) ?? '')
        : '';
    $legacyCategory = isset($record['category']) ? (int) $record['category'] : null;
    if ($format === 'YYYYMMMMDD' || $format === 'YYYYMMDD' || $legacyCategory === 0) {
        $candidate = sprintf('%04d-%02d-%02d', $year, $month, $day);
        if ($year > 0 && $month > 0 && $day > 0 && ghastly_valid_date($candidate)) {
            return ['date' => $candidate, 'precision' => 'day'];
        }
        if ($timestamp > 0) {
            return ['date' => gmdate('Y-m-d', $timestamp), 'precision' => 'day'];
        }
    }
    if ($year > 0) {
        $precision = ($format === 'YYYYMMMM' || $format === 'YYYYMM' || $legacyCategory === 1)
            ? 'month'
            : (($format === 'YYYY' || $legacyCategory === 2) ? 'year' : ($day > 0 ? 'day' : ($month > 0 ? 'month' : 'year')));
        $candidate = sprintf('%04d-%02d-%02d', $year, max(1, $month), max(1, $day));
        return ghastly_valid_date($candidate) ? ['date' => $candidate, 'precision' => $precision] : null;
    }
    if ($timestamp <= 0) {
        return null;
    }
    return ['date' => gmdate('Y-m-d', $timestamp), 'precision' => 'day'];
}

function ghastly_igdb_filter_string(string $value): string
{
    return str_replace(['\\', '"'], ['\\\\', '\\"'], trim($value));
}

function ghastly_igdb_strip_nickname_text(string $text, string $term = ''): string
{
    $text = preg_replace('/"[^"]*"/u', ' ', $text) ?? $text;
    $text = preg_replace("/'[^']*'/u", ' ', $text) ?? $text;
    $text = preg_replace('/\b(?:a\.?k\.?a\.?|aka|nicknamed|known as)\b[^.)]{0,80}/iu', ' ', $text) ?? $text;
    $term = trim($term);
    if ($term !== '') {
        $text = preg_replace(
            '/\bthe\s+\p{Lu}[\p{L}\'-]*\s+' . preg_quote($term, '/') . '\b/iu',
            ' ',
            $text,
        ) ?? $text;
    }
    return $text;
}

function ghastly_igdb_ignore_phrases(): array
{
    $config = ghastly_config();
    return ghastly_config_strings($config['igdb_horror_ignore_phrases'] ?? [
        'zombie costume', 'zombie outfit', 'zombie skin', 'halloween costume',
        'horror costume', 'ghost costume', 'vampire costume', 'werewolf costume',
    ]);
}

function ghastly_igdb_strip_ignore_phrases(string $text, array $phrases): string
{
    foreach ($phrases as $phrase) {
        $phrase = trim((string) $phrase);
        if ($phrase === '') {
            continue;
        }
        $text = preg_replace(
            '/(?<![\p{L}\p{N}])' . preg_quote($phrase, '/') . '(?![\p{L}\p{N}])/iu',
            ' ',
            $text,
        ) ?? $text;
    }
    return $text;
}

function ghastly_igdb_strip_cosmetic_term_text(string $text, string $term): string
{
    $term = trim($term);
    if ($term === '') {
        return $text;
    }
    $quoted = preg_quote($term, '/');
    $cosmetic = '(?:costume|costumes|outfit|outfits|skin|skins|cosplay|suit|mask|hat)';
    $text = preg_replace(
        '/(?<![\p{L}\p{N}])' . $quoted . '\s+' . $cosmetic . '(?![\p{L}\p{N}])/iu',
        ' ',
        $text,
    ) ?? $text;
    $text = preg_replace(
        '/(?<![\p{L}\p{N}])' . $cosmetic . '\s+(?:of\s+)?' . $quoted . '(?![\p{L}\p{N}])/iu',
        ' ',
        $text,
    ) ?? $text;
    return $text;
}

function ghastly_igdb_has_horror_theme(array $game, int $themeId): bool
{
    foreach (is_array($game['themes'] ?? null) ? $game['themes'] : [] as $theme) {
        if (is_array($theme) && (int) ($theme['id'] ?? 0) === $themeId) {
            return true;
        }
    }
    return false;
}

function ghastly_igdb_is_sport_game(array $game): bool
{
    foreach (is_array($game['genres'] ?? null) ? $game['genres'] : [] as $genre) {
        if (!is_array($genre)) {
            continue;
        }
        $id = (int) ($genre['id'] ?? 0);
        $name = mb_strtolower(trim((string) ($genre['name'] ?? '')));
        if ($id === 14 || $name === 'sport' || $name === 'sports') {
            return true;
        }
    }
    return false;
}

function ghastly_igdb_has_sufficient_horror_evidence(array $game, array $signals, int $themeId): bool
{
    if (ghastly_igdb_has_horror_theme($game, $themeId)) {
        return true;
    }
    if (ghastly_igdb_is_sport_game($game)) {
        return false;
    }
    $normalizedSignals = [];
    foreach ($signals as $signal) {
        $signal = mb_strtolower(trim((string) $signal));
        if ($signal !== '') {
            $normalizedSignals[$signal] = true;
        }
    }
    $config = ghastly_config();
    $standaloneTerms = ghastly_config_strings($config['igdb_horror_standalone_terms'] ?? [
        'body horror', 'cosmic horror', 'psychological horror',
        'serial killer', 'survival horror',
    ]);
    foreach ($standaloneTerms as $term) {
        if (isset($normalizedSignals[mb_strtolower($term)])) {
            return true;
        }
    }
    return count($normalizedSignals) >= 2;
}

function ghastly_igdb_horror_signals(array $game, array $terms): array
{
    $parts = [
        (string) ($game['name'] ?? ''),
        (string) ($game['summary'] ?? ''),
        (string) ($game['storyline'] ?? ''),
    ];
    foreach (['keywords', 'themes'] as $field) {
        foreach (is_array($game[$field] ?? null) ? $game[$field] : [] as $item) {
            if (is_array($item)) {
                $parts[] = (string) ($item['name'] ?? '');
            }
        }
    }
    $haystack = ghastly_igdb_strip_ignore_phrases(implode(' ', $parts), ghastly_igdb_ignore_phrases());
    $matches = [];
    foreach ($terms as $term) {
        $term = trim((string) $term);
        if ($term === '') {
            continue;
        }
        $searchable = ghastly_igdb_strip_cosmetic_term_text(ghastly_igdb_strip_nickname_text($haystack, $term), $term);
        $pattern = '/(?<![\\p{L}\\p{N}])' . preg_quote($term, '/') . '(?![\\p{L}\\p{N}])/iu';
        if (preg_match($pattern, $searchable)) {
            $matches[mb_strtolower($term)] = $term;
        }
    }
    return array_values($matches);
}

function ghastly_igdb_fallback_records(
    array $headers,
    int $minimum,
    int $maximum,
    string $start,
    string $end,
    array $terms,
): array {
    $records = [];
    foreach ($terms as $term) {
        $term = trim((string) $term);
        if ($term === '') {
            continue;
        }
        $escaped = ghastly_igdb_filter_string($term);
        for ($offset = 0; $offset < 1500; $offset += 500) {
            $query = sprintf(
                'fields id,name,slug,summary,storyline,cover.image_id,involved_companies.publisher,involved_companies.company.name,videos.name,videos.video_id,genres.id,genres.name,keywords.name,themes.id,themes.name,external_games.external_game_source,external_games.uid,release_dates.id,release_dates.date,release_dates.date_format.format,release_dates.human,release_dates.y,release_dates.m,release_dates.d,release_dates.platform.name,release_dates.release_region.region,release_dates.region; where (name ~ *"%1$s"* | summary ~ *"%1$s"* | storyline ~ *"%1$s"*) & release_dates.date >= %2$d & release_dates.date <= %3$d; limit 500; offset %4$d;',
                $escaped,
                $minimum,
                $maximum,
                $offset,
            );
            $page = ghastly_http_json('https://api.igdb.com/v4/games', $headers, 'POST', $query);
            foreach ($page as $game) {
                if (!is_array($game)) {
                    continue;
                }
                $signals = ghastly_igdb_horror_signals($game, $terms);
                if ($signals === []) {
                    continue;
                }
                $releaseDates = is_array($game['release_dates'] ?? null) ? $game['release_dates'] : [];
                unset($game['release_dates']);
                $game['_ghastly_horror_signals'] = $signals;
                foreach ($releaseDates as $releaseDate) {
                    if (!is_array($releaseDate)) {
                        continue;
                    }
                    $record = $releaseDate;
                    $record['game'] = $game;
                    $parsed = ghastly_igdb_date($record);
                    $releaseId = (string) ($record['id'] ?? '');
                    if ($parsed === null || $releaseId === '' || !ghastly_date_overlaps_window($parsed, $start, $end)) {
                        continue;
                    }
                    $records[$releaseId] = $record;
                }
            }
            if (count($page) < 500) {
                break;
            }
            usleep(300_000);
        }
        usleep(300_000);
    }
    return array_values($records);
}

function ghastly_collect_igdb(string $start, string $end): array
{
    $config = ghastly_config();
    $clientId = trim((string) ($config['igdb_client_id'] ?? ''));
    $token = ghastly_igdb_access_token($config);
    $themeId = max(1, (int) ($config['igdb_horror_theme_id'] ?? 19));
    $headers = [
        'Client-ID: ' . $clientId,
        'Authorization: Bearer ' . $token,
        'Content-Type: text/plain',
    ];
    $minimum = (new DateTimeImmutable($start . ' 00:00:00', new DateTimeZone('UTC')))->getTimestamp();
    $maximum = (new DateTimeImmutable($end . ' 23:59:59', new DateTimeZone('UTC')))->getTimestamp();
    $records = [];
    for ($offset = 0; $offset < 5000; $offset += 500) {
        $query = sprintf(
            'fields id,date,date_format.format,human,y,m,d,game.id,game.name,game.slug,game.summary,game.storyline,game.cover.image_id,game.involved_companies.publisher,game.involved_companies.company.name,game.videos.name,game.videos.video_id,game.genres.id,game.genres.name,game.keywords.name,game.themes.id,game.themes.name,game.external_games.external_game_source,game.external_games.uid,platform.name,release_region.region,region; where game.themes = (%d) & date >= %d & date <= %d; sort date asc; limit 500; offset %d;',
            $themeId,
            $minimum,
            $maximum,
            $offset,
        );
        try {
            $page = ghastly_http_json('https://api.igdb.com/v4/release_dates', $headers, 'POST', $query);
        } catch (RuntimeException $error) {
            throw new RuntimeException('IGDB release query failed: ' . $error->getMessage(), 0, $error);
        }
        foreach ($page as $record) {
            if (is_array($record) && (string) ($record['id'] ?? '') !== '') {
                $records[(string) $record['id']] = $record;
            }
        }
        if (count($page) < 500) {
            break;
        }
        usleep(300_000);
    }
    $fallbackTerms = ghastly_config_strings($config['igdb_horror_fallback_terms'] ?? [
        'body horror', 'cosmic horror', 'demon', 'demonic', 'eldritch',
        'exorcism', 'ghost', 'haunted', 'haunting', 'lycanthrope', 'occult',
        'possession', 'psychological horror', 'serial killer', 'survival horror',
        'undead', 'vampire', 'vampirism', 'werewolf', 'witchcraft', 'zombie',
    ]);
    if ($fallbackTerms !== []) {
        try {
            foreach (ghastly_igdb_fallback_records($headers, $minimum, $maximum, $start, $end, $fallbackTerms) as $record) {
                $releaseId = (string) ($record['id'] ?? '');
                if ($releaseId !== '' && !isset($records[$releaseId])) {
                    $records[$releaseId] = $record;
                }
            }
        } catch (RuntimeException $error) {
            error_log('IGDB horror-signal fallback failed: ' . $error->getMessage());
        }
    }
    $releases = [];
    $youtubeCache = [];
    $youtubeRemaining = max(0, (int) ($config['youtube_trailer_lookups_per_run'] ?? 40));
    foreach ($records as $record) {
        if (!is_array($record) || !is_array($record['game'] ?? null)) {
            continue;
        }
        $game = $record['game'];
        $date = ghastly_igdb_date($record);
        $releaseId = (string) ($record['id'] ?? '');
        $slug = ghastly_text($game['slug'] ?? '', 160);
        if ($date === null || $releaseId === '' || $slug === '') {
            continue;
        }
        $publishers = [];
        foreach (is_array($game['involved_companies'] ?? null) ? $game['involved_companies'] : [] as $company) {
            if (($company['publisher'] ?? false) && is_array($company['company'] ?? null)) {
                $name = ghastly_text($company['company']['name'] ?? '');
                if ($name !== '') {
                    $publishers[] = $name;
                }
            }
        }
        $imageId = is_array($game['cover'] ?? null) ? ghastly_text($game['cover']['image_id'] ?? '', 160) : '';
        $signals = ghastly_igdb_horror_signals($game, $fallbackTerms);
        if (!ghastly_igdb_has_sufficient_horror_evidence($game, $signals, $themeId)) {
            continue;
        }
        $horrorTags = ['Horror'];
        foreach ($signals as $signal) {
            $horrorTags[] = mb_convert_case((string) $signal, MB_CASE_TITLE, 'UTF-8');
        }
        $platformName = is_array($record['platform'] ?? null)
            ? ghastly_text($record['platform']['name'] ?? '', 255)
            : '';
        if (ghastly_platform_is_vr($platformName)) {
            $horrorTags[] = 'VR';
        }
        $releases[] = ghastly_with_reception(ghastly_normalize_release([
            'external_id' => 'igdb-release-' . $releaseId,
            'title' => $game['name'] ?? '',
            'creator' => implode(', ', array_values(array_unique($publishers))),
            'media_type' => 'video_game',
            'release_date' => $date['date'],
            'date_precision' => $date['precision'],
            'publisher' => implode(', ', array_values(array_unique($publishers))),
            'platform' => $platformName !== '' ? $platformName : 'Video game',
            'region' => ghastly_igdb_region($record['release_region'] ?? null, $record['region'] ?? null),
            'horror_tags' => array_values(array_unique($horrorTags)),
            'synopsis' => $game['summary'] ?? '',
            'source_url' => 'https://www.igdb.com/games/' . rawurlencode($slug),
            'source_name' => 'IGDB',
            'confidence' => 'partner',
            'source_rank' => 50,
            'artwork_url' => $imageId !== '' ? 'https://images.igdb.com/igdb/image/upload/t_cover_big/' . rawurlencode($imageId) . '.jpg' : null,
            'trailer_url' => ghastly_pick_game_youtube_trailer(is_array($game['videos'] ?? null) ? $game['videos'] : [])
                ?? ghastly_youtube_search_trailer((string) ($game['name'] ?? ''), substr($date['date'], 0, 4), $youtubeCache, $youtubeRemaining),
        ]), ghastly_igdb_external_ids($game, $slug));
    }
    return $releases;
}

function ghastly_rawg_pages(array $query, string $apiKey, int $maxPages = 20): array
{
    $results = [];
    $maxPages = max(1, min(20, $maxPages));
    $pageSize = 40;
    for ($page = 1; $page <= $maxPages; $page++) {
        $payload = ghastly_http_json('https://api.rawg.io/api/games?' . http_build_query($query + [
            'key' => $apiKey,
            'page' => (string) $page,
            'page_size' => (string) $pageSize,
        ]));
        $items = is_array($payload['results'] ?? null) ? $payload['results'] : [];
        $results = array_merge($results, $items);
        if ($page === 1 && (int) ($payload['count'] ?? 0) > $maxPages * $pageSize) {
            throw new RuntimeException('RAWG query exceeded the safe page limit; narrow its date range.');
        }
        if (empty($payload['next'])) {
            break;
        }
        usleep(250_000);
    }
    return $results;
}

function ghastly_rawg_has_horror_tag(array $game, array $configuredTags): bool
{
    $expected = array_map(static fn (string $tag): string => mb_strtolower($tag), $configuredTags);
    foreach (is_array($game['tags'] ?? null) ? $game['tags'] : [] as $tag) {
        if (!is_array($tag)) {
            continue;
        }
        $values = [
            mb_strtolower(trim((string) ($tag['id'] ?? ''))),
            mb_strtolower(trim((string) ($tag['slug'] ?? ''))),
            mb_strtolower(trim((string) ($tag['name'] ?? ''))),
        ];
        if (array_intersect($expected, $values) !== []) {
            return true;
        }
    }
    return false;
}

function ghastly_collect_rawg(string $start, string $end): array
{
    if (!ghastly_valid_date($start) || !ghastly_valid_date($end) || $start > $end) {
        throw new RuntimeException('RAWG requires a valid release-date range.');
    }
    $config = ghastly_config();
    $apiKey = trim((string) ($config['rawg_api_key'] ?? ''));
    if ($apiKey === '') {
        throw new GhastlySourceUnavailable('RAWG API key is not configured.');
    }
    $horrorTags = ghastly_config_strings($config['rawg_horror_tags'] ?? ['horror']);
    if ($horrorTags === []) {
        throw new GhastlySourceUnavailable('RAWG horror tags are not configured.');
    }
    $releases = [];
    $seen = [];
    $youtubeCache = [];
    $youtubeRemaining = max(0, (int) ($config['youtube_trailer_lookups_per_run'] ?? 40));
    foreach (ghastly_tmdb_month_windows($start, $end) as $window) {
        $query = [
            'dates' => $window['start'] . ',' . $window['end'],
            'tags' => implode(',', $horrorTags),
            'ordering' => 'released',
            'exclude_additions' => 'true',
        ];
        foreach (ghastly_rawg_pages($query, $apiKey) as $game) {
            if (!is_array($game) || !ghastly_rawg_has_horror_tag($game, $horrorTags)) {
                continue;
            }
            $id = trim((string) ($game['id'] ?? ''));
            $slug = ghastly_text($game['slug'] ?? '', 160);
            $date = $game['released'] ?? null;
            if ($id === '' || $slug === '' || !ghastly_valid_date($date) || $date < $start || $date > $end || isset($seen[$id])) {
                continue;
            }
            $platforms = [];
            foreach (is_array($game['platforms'] ?? null) ? $game['platforms'] : [] as $platform) {
                $name = is_array($platform['platform'] ?? null) ? ghastly_text($platform['platform']['name'] ?? '') : '';
                if ($name !== '') {
                    $platforms[] = $name;
                }
            }
            $releases[] = ghastly_normalize_release([
                'external_id' => 'rawg-game-' . $id,
                'title' => $game['name'] ?? '',
                'media_type' => 'video_game',
                'release_date' => $date,
                'date_precision' => 'day',
                'platform' => implode(', ', array_values(array_unique($platforms))),
                'horror_tags' => ['Horror'],
                'source_url' => 'https://rawg.io/games/' . rawurlencode($slug),
                'source_name' => 'RAWG',
                'confidence' => 'database',
                'source_rank' => 35,
                'artwork_url' => $game['background_image'] ?? null,
                'trailer_url' => ghastly_rawg_clip_trailer($game)
                    ?? ghastly_youtube_search_trailer((string) ($game['name'] ?? ''), substr($date, 0, 4), $youtubeCache, $youtubeRemaining),
            ]);
            $seen[$id] = true;
        }
    }
    return $releases;
}

function ghastly_xpath_first(DOMXPath $xpath, string $query, ?DOMNode $context = null): ?DOMNode
{
    $nodes = $xpath->query($query, $context);
    return $nodes !== false && $nodes->length > 0 ? $nodes->item(0) : null;
}

function ghastly_asmodee_date(string $monthHeading, string $dayLabel): ?string
{
    if (!preg_match('/\b(20\d{2})\b/', $monthHeading, $yearMatch)) {
        return null;
    }
    $dayLabel = preg_replace('/(?<=\d)(?:st|nd|rd|th)\b/i', '', trim($dayLabel)) ?? '';
    $candidate = DateTimeImmutable::createFromFormat(
        '!F j Y',
        trim(preg_replace('/\s+/', ' ', $dayLabel) ?? '') . ' ' . $yearMatch[1],
        new DateTimeZone('UTC'),
    );
    $errors = DateTimeImmutable::getLastErrors();
    if ($candidate === false || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
        return null;
    }
    return $candidate->format('Y-m-d');
}

function ghastly_asmodee_url(string $value): ?string
{
    $value = trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if (str_starts_with($value, '//')) {
        $value = 'https:' . $value;
    } elseif (str_starts_with($value, '/')) {
        $value = 'https://store.asmodee.com' . $value;
    }
    return filter_var($value, FILTER_VALIDATE_URL) ? $value : null;
}

function ghastly_asmodee_matches(string $haystack, array $terms): array
{
    $haystack = mb_strtolower($haystack);
    return array_values(array_filter($terms, static function (string $term) use ($haystack): bool {
        $term = mb_strtolower(trim($term));
        return $term !== '' && str_contains($haystack, $term);
    }));
}

function ghastly_asmodee_platform(string $title): string
{
    $title = mb_strtolower($title);
    if (str_contains($title, 'roleplaying') || str_contains($title, 'call of cthulhu')) {
        return 'Roleplaying game';
    }
    if (str_contains($title, 'card game') || str_contains($title, 'deck-building')) {
        return 'Card game';
    }
    return 'Tabletop game';
}

function ghastly_asmodee_calendar_html(string $calendarUrl): string
{
    $path = getenv('GHASTLY_ASMODEE_CALENDAR_FILE');
    if ($path === false || $path === '') {
        return ghastly_http_html($calendarUrl);
    }
    if (PHP_SAPI !== 'cli' || !is_file($path) || !is_readable($path)) {
        throw new RuntimeException('Asmodee calendar file is unavailable to the CLI importer.');
    }
    clearstatcache(true, $path);
    $size = filesize($path);
    $modified = filemtime($path);
    if ($size === false || $size === 0 || $size > 8 * 1024 * 1024 || $modified === false || $modified < time() - 3600) {
        throw new RuntimeException('Asmodee calendar file must be fresh, nonempty, and no larger than 8 MB.');
    }
    $html = file_get_contents($path, false, null, 0, 8 * 1024 * 1024 + 1);
    if (!is_string($html) || strlen($html) > 8 * 1024 * 1024) {
        throw new RuntimeException('Asmodee calendar file could not be read within its size limit.');
    }
    return $html;
}

function ghastly_collect_asmodee(string $start, string $end): array
{
    if (!ghastly_valid_date($start) || !ghastly_valid_date($end) || $start > $end) {
        throw new RuntimeException('Asmodee requires a valid release-date range.');
    }
    $config = ghastly_config();
    if (($config['asmodee_enabled'] ?? true) !== true) {
        throw new GhastlySourceUnavailable('Asmodee USA discovery is not enabled.');
    }
    if (!class_exists(DOMDocument::class)) {
        throw new RuntimeException('Asmodee discovery requires the PHP DOM extension.');
    }
    $calendarUrl = trim((string) ($config['asmodee_release_calendar_url'] ?? 'https://store.asmodee.com/pages/release-calendar'));
    if (!filter_var($calendarUrl, FILTER_VALIDATE_URL) || parse_url($calendarUrl, PHP_URL_HOST) !== 'store.asmodee.com') {
        throw new RuntimeException('Asmodee release calendar URL is invalid.');
    }
    $horrorTerms = ghastly_config_strings($config['asmodee_horror_terms'] ?? [
        'horror', 'zombie', 'zombicide', 'cthulhu', 'lovecraft', 'vampire',
        'werewolf', 'undead', 'haunted', 'ghost', 'nightmare', 'demon',
        'final girl', 'dead of winter', 'nemesis', 'dceased',
    ]);
    $accessoryTerms = ghastly_config_strings($config['asmodee_accessory_terms'] ?? [
        'acrylic token', 'token pack', 'miniature', 'playmat', 'dice tray',
        'terrain pack', 'terrain expansion', 'spacecats', 'card sleeves',
    ]);
    if ($horrorTerms === []) {
        throw new GhastlySourceUnavailable('Asmodee horror terms are not configured.');
    }

    $document = new DOMDocument();
    $previousErrors = libxml_use_internal_errors(true);
    $loaded = $document->loadHTML(
        '<?xml encoding="UTF-8">' . ghastly_asmodee_calendar_html($calendarUrl),
        LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
    );
    libxml_clear_errors();
    libxml_use_internal_errors($previousErrors);
    if (!$loaded) {
        throw new RuntimeException('Asmodee returned unreadable calendar markup.');
    }
    $xpath = new DOMXPath($document);
    $wrappers = $xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' index-tabs-collections-wrapper ')]");
    if ($wrappers === false || $wrappers->length === 0) {
        throw new RuntimeException('Asmodee calendar markup no longer contains release sections.');
    }

    $releases = [];
    foreach ($wrappers as $wrapper) {
        $headingNode = ghastly_xpath_first($xpath, ".//*[contains(concat(' ', normalize-space(@class), ' '), ' section-header ')]//h2[1]", $wrapper);
        $monthHeading = trim((string) ($headingNode?->textContent ?? ''));
        $tabDates = [];
        $tabNodes = $xpath->query(".//a[contains(concat(' ', normalize-space(@class), ' '), ' index-tabs_nav--item ')]", $wrapper);
        if ($tabNodes !== false) {
            foreach ($tabNodes as $tabNode) {
                if (!$tabNode instanceof DOMElement) {
                    continue;
                }
                $tabId = trim($tabNode->getAttribute('data-href'));
                $label = trim($tabNode->getAttribute('aria-label')) ?: trim((string) $tabNode->textContent);
                $date = ghastly_asmodee_date($monthHeading, $label);
                if ($tabId !== '' && $date !== null) {
                    $tabDates[$tabId] = $date;
                }
            }
        }
        $contentBlocks = $xpath->query(".//div[contains(concat(' ', normalize-space(@class), ' '), ' index-tabs-content_block ')]", $wrapper);
        if ($contentBlocks === false) {
            continue;
        }
        foreach ($contentBlocks as $contentBlock) {
            if (!$contentBlock instanceof DOMElement) {
                continue;
            }
            $date = $tabDates[$contentBlock->getAttribute('id')] ?? null;
            if ($date === null || $date < $start || $date > $end) {
                continue;
            }
            $productNodes = $xpath->query(".//div[contains(concat(' ', normalize-space(@class), ' '), ' product-item-block ')]", $contentBlock);
            if ($productNodes === false) {
                continue;
            }
            foreach ($productNodes as $productNode) {
                $linkNode = ghastly_xpath_first($xpath, ".//a[contains(concat(' ', normalize-space(@class), ' '), ' product-card__link-title ')]", $productNode);
                if (!$linkNode instanceof DOMElement) {
                    continue;
                }
                $title = trim($linkNode->getAttribute('aria-label')) ?: trim((string) $linkNode->textContent);
                $descriptionNode = ghastly_xpath_first($xpath, ".//div[contains(concat(' ', normalize-space(@class), ' '), ' product-item__desc ')]", $productNode);
                $description = trim((string) ($descriptionNode?->textContent ?? ''));
                $matchedTerms = ghastly_asmodee_matches($title . ' ' . $description, $horrorTerms);
                if ($title === '' || $matchedTerms === [] || ghastly_asmodee_matches($title, $accessoryTerms) !== []) {
                    continue;
                }
                $productIdNode = ghastly_xpath_first($xpath, ".//input[@name='product-id']", $productNode);
                $productId = $productIdNode instanceof DOMElement ? trim($productIdNode->getAttribute('value')) : '';
                $sourceUrl = ghastly_asmodee_url($linkNode->getAttribute('href'));
                if ($productId === '' || $sourceUrl === null) {
                    continue;
                }
                $imageNode = ghastly_xpath_first($xpath, ".//img[contains(concat(' ', normalize-space(@class), ' '), ' product-card__image ')]", $productNode);
                $artworkUrl = $imageNode instanceof DOMElement ? ghastly_asmodee_url($imageNode->getAttribute('src')) : null;
                $tags = ['Horror', 'US retail release'];
                foreach ($matchedTerms as $term) {
                    $tag = match (mb_strtolower($term)) {
                        'zombie', 'zombicide' => 'Zombies',
                        'cthulhu', 'lovecraft' => 'Cosmic horror',
                        'vampire' => 'Vampires',
                        'werewolf' => 'Werewolves',
                        'ghost', 'haunted' => 'Supernatural',
                        default => null,
                    };
                    if ($tag !== null) {
                        $tags[] = $tag;
                    }
                }
                $releases[$productId] = ghastly_normalize_release([
                    'external_id' => 'asmodee-product-' . $productId,
                    'title' => $title,
                    'media_type' => 'tabletop_game',
                    'release_date' => $date,
                    'date_precision' => 'day',
                    'platform' => ghastly_asmodee_platform($title),
                    'region' => 'US',
                    'horror_tags' => array_values(array_unique($tags)),
                    'synopsis' => $description,
                    'source_url' => $sourceUrl,
                    'source_name' => 'Asmodee USA',
                    'confidence' => 'official',
                    'source_rank' => 90,
                    'artwork_url' => $artworkUrl,
                ]);
            }
        }
    }
    return array_values($releases);
}

function ghastly_lunar_column_index(string $reference): int
{
    if (!preg_match('/^[A-Z]+/', strtoupper($reference), $match)) {
        return -1;
    }
    $index = 0;
    foreach (str_split($match[0]) as $letter) {
        $index = ($index * 26) + (ord($letter) - 64);
    }
    return $index - 1;
}

function ghastly_lunar_xml(ZipArchive $archive, string $path, int $maxBytes): DOMDocument
{
    $stat = $archive->statName($path);
    if (!is_array($stat) || (int) ($stat['size'] ?? 0) <= 0 || (int) $stat['size'] > $maxBytes) {
        throw new RuntimeException('Lunar workbook contains a missing or oversized XML part.');
    }
    $xml = $archive->getFromName($path);
    if (!is_string($xml)) {
        throw new RuntimeException('Lunar workbook XML could not be read.');
    }
    $document = new DOMDocument();
    $previousErrors = libxml_use_internal_errors(true);
    $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($previousErrors);
    if (!$loaded) {
        throw new RuntimeException('Lunar returned an unreadable workbook.');
    }
    return $document;
}

function ghastly_lunar_workbook_rows(string $workbook): array
{
    if (!class_exists(ZipArchive::class) || !class_exists(DOMDocument::class)) {
        throw new RuntimeException('Lunar discovery requires the PHP ZIP and DOM extensions.');
    }
    if ($workbook === '' || strlen($workbook) > 25_000_000) {
        throw new RuntimeException('Lunar returned an empty or oversized workbook.');
    }
    $temporaryPath = tempnam(sys_get_temp_dir(), 'ghastly-lunar-');
    if ($temporaryPath === false || file_put_contents($temporaryPath, $workbook, LOCK_EX) === false) {
        throw new RuntimeException('Unable to stage the Lunar workbook.');
    }
    $archive = new ZipArchive();
    try {
        if ($archive->open($temporaryPath) !== true) {
            throw new RuntimeException('Lunar response is not a valid Excel workbook.');
        }
        $sharedDocument = ghastly_lunar_xml($archive, 'xl/sharedStrings.xml', 15_000_000);
        $sharedXPath = new DOMXPath($sharedDocument);
        $sharedStrings = [];
        foreach ($sharedXPath->query('//*[local-name()="si"]') ?: [] as $item) {
            $parts = [];
            foreach ((new DOMXPath($sharedDocument))->query('.//*[local-name()="t"]', $item) ?: [] as $textNode) {
                $parts[] = (string) $textNode->textContent;
            }
            $sharedStrings[] = implode('', $parts);
        }
        $sheetDocument = ghastly_lunar_xml($archive, 'xl/worksheets/sheet1.xml', 20_000_000);
        $sheetXPath = new DOMXPath($sheetDocument);
        $rawRows = [];
        foreach ($sheetXPath->query('//*[local-name()="sheetData"]/*[local-name()="row"]') ?: [] as $rowNode) {
            $row = [];
            foreach ($sheetXPath->query('./*[local-name()="c"]', $rowNode) ?: [] as $cellNode) {
                if (!$cellNode instanceof DOMElement) {
                    continue;
                }
                $column = ghastly_lunar_column_index($cellNode->getAttribute('r'));
                if ($column < 0 || $column > 100) {
                    continue;
                }
                $valueNode = $sheetXPath->query('./*[local-name()="v"]', $cellNode)?->item(0);
                $value = (string) ($valueNode?->textContent ?? '');
                if ($cellNode->getAttribute('t') === 's' && ctype_digit($value)) {
                    $value = (string) ($sharedStrings[(int) $value] ?? '');
                }
                $row[$column] = $value;
            }
            $rawRows[] = $row;
        }
    } finally {
        if ($archive->status === ZipArchive::ER_OK) {
            $archive->close();
        }
        @unlink($temporaryPath);
    }
    if (count($rawRows) < 2) {
        throw new RuntimeException('Lunar workbook contains no product rows.');
    }
    $headers = array_map(static fn (mixed $value): string => trim((string) $value), $rawRows[0]);
    $required = ['ProductCode', 'Title', 'Publisher', 'Description', 'InstoreDate', 'Writer', 'Artist', 'NumberOfPages', 'CoverType'];
    if (array_diff($required, $headers) !== []) {
        throw new RuntimeException('Lunar workbook columns have changed.');
    }
    $rows = [];
    foreach (array_slice($rawRows, 1) as $rawRow) {
        $row = [];
        foreach ($headers as $index => $header) {
            if ($header !== '') {
                $row[$header] = trim((string) ($rawRow[$index] ?? ''));
            }
        }
        $rows[] = $row;
    }
    return $rows;
}

function ghastly_lunar_matches(string $haystack, array $terms): array
{
    $matches = [];
    foreach ($terms as $term) {
        $term = trim($term);
        if ($term !== '' && preg_match('/(?<![\\pL\\pN])' . preg_quote($term, '/') . '(?![\\pL\\pN])/iu', $haystack)) {
            $matches[] = mb_strtolower($term);
        }
    }
    return array_values(array_unique($matches));
}

function ghastly_lunar_base_title(string $title): string
{
    $title = preg_replace('/\s+CVR\s+[A-Z0-9]+\b.*$/iu', '', $title) ?? $title;
    $title = preg_replace('/\s+\d+\s+COPY\b.*$/iu', '', $title) ?? $title;
    if (preg_match('/\b(?:CVR|VAR|DIRECT MARKET|COPY)\b/iu', $title)) {
        $title = preg_replace('/\b(TP(?:\s+VOL\s+\d+)?|HC(?:\s+VOL\s+\d+)?)\b.*$/iu', '$1', $title) ?? $title;
    }
    $title = preg_replace('/\s+\((?:MR|MATURE)\)\s*$/iu', '', $title) ?? $title;
    return ghastly_text($title);
}

function ghastly_lunar_date(mixed $value): ?array
{
    $value = trim((string) $value);
    $date = DateTimeImmutable::createFromFormat('!m/d/Y', $value, new DateTimeZone('UTC'));
    $errors = DateTimeImmutable::getLastErrors();
    if ($date === false || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
        return null;
    }
    return ['date' => $date->format('Y-m-d'), 'precision' => 'day'];
}

function ghastly_lunar_synopsis_identity(string $synopsis): string
{
    $withoutVariantLead = preg_replace(
        '/^(?=[^.?!]*(?:variant|cover|virgin|foil|gloss))[^.?!]*[.!?]\s*/iu',
        '',
        $synopsis,
    ) ?? $synopsis;
    return mb_strtolower(trim($withoutVariantLead));
}

function ghastly_lunar_is_comic(array $row, array $excludedTerms): bool
{
    $title = (string) ($row['Title'] ?? '');
    if ($title === '' || ghastly_lunar_matches($title, $excludedTerms) !== []) {
        return false;
    }
    $pages = (int) ($row['NumberOfPages'] ?? 0);
    $coverType = mb_strtolower((string) ($row['CoverType'] ?? ''));
    $hasCreator = trim((string) ($row['Writer'] ?? '')) !== '' || trim((string) ($row['Artist'] ?? '')) !== '';
    return $hasCreator && $pages > 0 && ($coverType === 'softcover' || $coverType === 'hardcover');
}

function ghastly_lunar_tags(array $matches, array $row): array
{
    $tags = ['Horror', 'US comic-shop release'];
    foreach ($matches as $term) {
        $tag = match ($term) {
            'body horror' => 'Body horror',
            'cosmic horror', 'cthulhu', 'lovecraft' => 'Cosmic horror',
            'folk horror' => 'Folk horror',
            'gothic horror' => 'Gothic horror',
            'slasher', 'serial killer' => 'Slasher',
            'haunted', 'haunting', 'ghost story' => 'Supernatural',
            'vampire', 'vampires' => 'Vampires',
            'zombie', 'zombies', 'undead' => 'Undead',
            'werewolf', 'werewolves' => 'Werewolves',
            'occult', 'demonic', 'demon possession' => 'Occult',
            default => null,
        };
        if ($tag !== null) {
            $tags[] = $tag;
        }
    }
    if (filter_var($row['Mature'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
        $tags[] = 'Mature';
    }
    return array_values(array_unique($tags));
}

function ghastly_lunar_downloads(array $urls): array
{
    $config = ghastly_config();
    $multi = curl_multi_init();
    $handles = [];
    foreach ($urls as $kind => $url) {
        $handle = curl_init($url);
        if ($handle === false) {
            continue;
        }
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_USERAGENT => $config['user_agent'],
            CURLOPT_HTTPHEADER => ['Accept: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        ]);
        $handles[(string) $kind] = $handle;
        curl_multi_add_handle($multi, $handle);
    }
    do {
        $status = curl_multi_exec($multi, $running);
        if ($status !== CURLM_OK) {
            break;
        }
        if ($running > 0) {
            curl_multi_select($multi, 1.0);
        }
    } while ($running > 0);
    $workbooks = [];
    foreach ($handles as $kind => $handle) {
        $body = curl_multi_getcontent($handle);
        $httpStatus = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        if (is_string($body) && $body !== '' && $httpStatus >= 200 && $httpStatus < 300) {
            $workbooks[$kind] = $body;
        } else {
            error_log(sprintf('Lunar %s workbook request failed with HTTP %d.', $kind, $httpStatus));
        }
        curl_multi_remove_handle($multi, $handle);
        curl_close($handle);
    }
    curl_multi_close($multi);
    if ($workbooks === []) {
        throw new RuntimeException('Lunar product workbook requests failed.');
    }
    return $workbooks;
}

function ghastly_lunar_artwork_url(mixed $value): ?string
{
    $url = trim(html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    return filter_var($url, FILTER_VALIDATE_URL)
        && parse_url($url, PHP_URL_SCHEME) === 'https'
        && parse_url($url, PHP_URL_HOST) === 'media.lunardistribution.com'
        ? $url
        : null;
}

function ghastly_collect_lunar(string $start, string $end): array
{
    if (!ghastly_valid_date($start) || !ghastly_valid_date($end) || $start > $end) {
        throw new RuntimeException('Lunar requires a valid release-date range.');
    }
    $config = ghastly_config();
    if (($config['lunar_enabled'] ?? true) !== true) {
        throw new GhastlySourceUnavailable('Lunar discovery is not enabled.');
    }
    $homeUrl = trim((string) ($config['lunar_home_url'] ?? 'https://www.lunardistribution.com/'));
    if (!filter_var($homeUrl, FILTER_VALIDATE_URL) || parse_url($homeUrl, PHP_URL_HOST) !== 'www.lunardistribution.com') {
        throw new RuntimeException('Lunar homepage URL is invalid.');
    }
    $horrorTerms = ghastly_config_strings($config['lunar_horror_terms'] ?? [
        'horror', 'body horror', 'cosmic horror', 'folk horror', 'gothic horror',
        'slasher', 'haunted', 'ghost story', 'supernatural horror',
        'vampire', 'vampires', 'zombie', 'zombies', 'undead', 'occult',
        'cthulhu', 'lovecraft', 'demonic', 'demon possession', 'werewolf',
        'werewolves', 'creature feature', 'serial killer',
    ]);
    $excludedTerms = ghastly_config_strings($config['lunar_excluded_terms'] ?? [
        'light novel', 'prose novel', 'ttrpg', 'roleplaying game', 'role-playing game',
        'art book', 'coloring book', 'picture book', 'poster', 'statue', 'figure', 'plush',
        'bundle of', 'bundles of', 'reader pack', 'unlock bundle',
    ]);
    if ($horrorTerms === []) {
        throw new GhastlySourceUnavailable('Lunar horror terms are not configured.');
    }
    $document = new DOMDocument();
    $previousErrors = libxml_use_internal_errors(true);
    $loaded = $document->loadHTML('<?xml encoding="UTF-8">' . ghastly_http_html($homeUrl), LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($previousErrors);
    if (!$loaded) {
        throw new RuntimeException('Lunar returned unreadable homepage markup.');
    }
    $xpath = new DOMXPath($document);
    $artworkByCode = [];
    $productNodes = $xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' productdetail ')][@data-code]");
    foreach ($productNodes ?: [] as $productNode) {
        if (!$productNode instanceof DOMElement) {
            continue;
        }
        $productCode = trim($productNode->getAttribute('data-code'));
        $imageNodes = $xpath->query('.//img[@data-lazy]', $productNode);
        $catalogImage = $imageNodes !== false && $imageNodes->length > 0 && $imageNodes->item(0) instanceof DOMElement
            ? $imageNodes->item(0)->getAttribute('data-lazy')
            : '';
        $artworkUrl = ghastly_lunar_artwork_url($catalogImage)
            ?? ghastly_lunar_artwork_url($productNode->getAttribute('data-img'));
        if ($productCode !== '' && $artworkUrl !== null) {
            $artworkByCode[$productCode] = $artworkUrl;
        }
    }
    $buttons = $xpath->query("//button[contains(concat(' ', normalize-space(@class), ' '), ' datafile ')]");
    $downloads = [];
    foreach ($buttons ?: [] as $button) {
        if (!$button instanceof DOMElement) {
            continue;
        }
        $class = ' ' . preg_replace('/\s+/', ' ', trim($button->getAttribute('class'))) . ' ';
        $kind = str_contains($class, ' foc ') ? 'foc' : (str_contains($class, ' release ') ? 'release' : '');
        $dateValue = trim($button->getAttribute('data-val'));
        if ($kind !== '' && $dateValue !== '' && ghastly_partial_date($dateValue) !== null) {
            $downloads[$kind] = rtrim($homeUrl, '/') . '/home/productdatafile?' . $kind . '=' . rawurlencode($dateValue);
        }
    }
    if ($downloads === []) {
        throw new RuntimeException('Lunar homepage no longer exposes product workbook dates.');
    }
    $releases = [];
    $artworkScores = [];
    foreach (ghastly_lunar_downloads($downloads) as $kind => $workbook) {
        $downloadUrl = $downloads[$kind];
        foreach (ghastly_lunar_workbook_rows($workbook) as $row) {
            if (!ghastly_lunar_is_comic($row, $excludedTerms)) {
                continue;
            }
            $date = ghastly_lunar_date($row['InstoreDate'] ?? null);
            if ($date === null || $date['precision'] !== 'day' || $date['date'] < $start || $date['date'] > $end) {
                continue;
            }
            $matches = ghastly_lunar_matches(($row['Title'] ?? '') . ' ' . ($row['Description'] ?? ''), $horrorTerms);
            if ($matches === []) {
                continue;
            }
            $title = ghastly_lunar_base_title((string) ($row['Title'] ?? ''));
            $publisher = ghastly_text($row['Publisher'] ?? '');
            if ($title === '' || $publisher === '') {
                continue;
            }
            $synopsis = ghastly_text($row['Description'] ?? '', 4000);
            $identityText = ghastly_lunar_synopsis_identity($synopsis) ?: mb_strtolower($title);
            $identity = hash('sha256', mb_strtolower($publisher) . '|' . $identityText);
            $existingTitle = (string) ($releases[$identity]['title'] ?? '');
            if ($existingTitle !== '' && mb_strlen($existingTitle) < mb_strlen($title)) {
                $title = $existingTitle;
            }
            $rawTitle = (string) ($row['Title'] ?? '');
            $artworkScore = preg_match('/\bCVR\s+A\b/iu', $rawTitle)
                ? 3
                : (preg_match('/\b(?:VAR|CVR|EXCLUSIVE|COPY)\b/iu', $rawTitle) ? 1 : 2);
            $artworkUrl = $artworkByCode[trim((string) ($row['ProductCode'] ?? ''))] ?? null;
            if (isset($artworkScores[$identity]) && $artworkScores[$identity] >= $artworkScore) {
                $artworkUrl = $releases[$identity]['artwork_url'] ?? $artworkUrl;
            } elseif ($artworkUrl !== null) {
                $artworkScores[$identity] = $artworkScore;
            }
            $releases[$identity] = ghastly_normalize_release([
                'external_id' => 'lunar-comic-' . $identity,
                'title' => $title,
                'creator' => $row['Writer'] ?? '',
                'media_type' => 'comic',
                'release_date' => $date['date'],
                'date_precision' => 'day',
                'publisher' => $publisher,
                'platform' => 'Comic shop',
                'region' => 'US',
                'horror_tags' => ghastly_lunar_tags($matches, $row),
                'synopsis' => $synopsis,
                'source_url' => $downloadUrl,
                'source_name' => 'Lunar Distribution',
                'confidence' => 'official',
                'source_rank' => 90,
                'artwork_url' => $artworkUrl,
            ]);
        }
    }
    return array_values($releases);
}

function ghastly_collect_google_books(string $start, string $end): array
{
    $config = ghastly_config();
    $apiKey = trim((string) ($config['google_books_api_key'] ?? ''));
    if ($apiKey === '') {
        throw new GhastlySourceUnavailable('Google Books API key is not configured.');
    }
    $queries = ghastly_config_strings($config['google_books_queries'] ?? ['subject:horror']);
    foreach (range((int) substr($start, 0, 4), (int) substr($end, 0, 4)) as $year) {
        $queries[] = 'subject:horror ' . $year;
    }
    $queries = array_values(array_unique($queries));
    $region = trim((string) ($config['google_books_region'] ?? 'US')) ?: 'US';
    $releases = [];
    $seen = [];
    foreach ($queries as $query) {
        $imprintQuery = (bool) preg_match('/inpublisher:/i', $query);
        $pageCap = $imprintQuery ? 40 : 80;
        for ($startIndex = 0; $startIndex < $pageCap; $startIndex += 40) {
            $payload = ghastly_http_json('https://www.googleapis.com/books/v1/volumes?' . http_build_query([
                'q' => $query,
                'orderBy' => 'newest',
                'printType' => 'books',
                'showPreorders' => 'true',
                'maxResults' => '40',
                'startIndex' => (string) $startIndex,
                'key' => $apiKey,
            ]));
            if (isset($payload['error'])) {
                throw new RuntimeException('Google Books: ' . ghastly_text($payload['error']['message'] ?? 'request failed', 300));
            }
            $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
            $keptBefore = count($releases);
            $skippedDate = 0;
            foreach ($items as $item) {
                $volume = is_array($item['volumeInfo'] ?? null) ? $item['volumeInfo'] : [];
                $id = ghastly_text($item['id'] ?? '', 160);
                $date = ghastly_partial_date($volume['publishedDate'] ?? null);
                $categories = is_array($volume['categories'] ?? null) ? $volume['categories'] : [];
                $horrorEvidence = implode(' ', array_merge($categories, [(string) ($volume['description'] ?? '')]));
                if ($id === '' || isset($seen[$id]) || $date === null || !ghastly_date_overlaps_window($date, $start, $end)) {
                    if ($id !== '' && !isset($seen[$id])) {
                        $skippedDate++;
                    }
                    continue;
                }
                if (!$imprintQuery && !preg_match('/subject:horror/i', $query) && stripos($horrorEvidence, 'horror') === false) {
                    continue;
                }
                $sourceUrl = (string) ($volume['infoLink'] ?? ('https://books.google.com/books?id=' . rawurlencode($id)));
                $sourceUrl = preg_replace('/^http:/i', 'https:', $sourceUrl) ?? $sourceUrl;
                $thumbnail = is_array($volume['imageLinks'] ?? null) ? (string) ($volume['imageLinks']['thumbnail'] ?? '') : '';
                $thumbnail = preg_replace('/^http:/i', 'https:', $thumbnail) ?? $thumbnail;
                $tags = array_values(array_unique(array_merge(['Horror'], array_slice(array_map(
                    static fn (mixed $category): string => ghastly_text($category, 80),
                    $categories,
                ), 0, 4))));
                $releases[] = ghastly_normalize_release([
                    'external_id' => 'google-books-' . $id,
                    'title' => $volume['title'] ?? '',
                    'creator' => implode(', ', is_array($volume['authors'] ?? null) ? $volume['authors'] : []),
                    'media_type' => 'book',
                    'release_date' => $date['date'],
                    'date_precision' => $date['precision'],
                    'publisher' => $volume['publisher'] ?? '',
                    'platform' => ($item['saleInfo']['isEbook'] ?? false) ? 'Ebook' : 'Book',
                    'region' => $region,
                    'horror_tags' => $tags,
                    'synopsis' => $volume['description'] ?? '',
                    'source_url' => $sourceUrl,
                    'source_name' => 'Google Books',
                    'confidence' => 'database',
                    'source_rank' => 30,
                    'artwork_url' => $thumbnail !== '' ? $thumbnail : null,
                ]);
                $seen[$id] = true;
            }
            printf(
                "google-books q=%s total=%s items=%d kept=%d skipped_date=%d\n",
                $query,
                (string) ($payload['totalItems'] ?? 'n/a'),
                count($items),
                count($releases) - $keptBefore,
                $skippedDate,
            );
            if (count($items) < 40) {
                break;
            }
            usleep(350_000);
        }
    }
    return $releases;
}

function ghastly_prh_isbn(mixed $value): string
{
    return preg_replace('/\D+/', '', (string) $value) ?? '';
}

function ghastly_prh_named(mixed $value, int $limit = 160): string
{
    if (is_array($value)) {
        return ghastly_text($value['description'] ?? ($value['name'] ?? ($value['display'] ?? ($value['authorDisplay'] ?? ''))), $limit);
    }
    return ghastly_text($value, $limit);
}

function ghastly_prh_authors(array $title): string
{
    $author = ghastly_prh_named($title['author'] ?? '');
    if ($author !== '') {
        return $author;
    }
    $names = [];
    foreach (is_array($title['authors'] ?? null) ? $title['authors'] : [] as $authorRow) {
        $name = ghastly_prh_named($authorRow);
        if ($name !== '') {
            $names[] = $name;
        }
    }
    return implode(', ', $names);
}

function ghastly_prh_format_family(array $title): string
{
    $family = ghastly_prh_named($title['formatFamily'] ?? '', 80);
    if ($family !== '') {
        return $family;
    }
    return ghastly_prh_named($title['format'] ?? '', 80) ?: 'Book';
}

function ghastly_prh_format_code(array $title): string
{
    $format = $title['format'] ?? null;
    if (is_array($format)) {
        return strtoupper(trim((string) ($format['code'] ?? '')));
    }
    return strtoupper(trim((string) $format));
}

function ghastly_prh_is_audiobook(array $title): bool
{
    $code = ghastly_prh_format_code($title);
    if (in_array($code, ['AA', 'CD', 'DA', 'DN'], true)) {
        return true;
    }
    if ((int) ($title['projectedMinutes'] ?? 0) > 0) {
        return true;
    }
    $haystack = mb_strtolower(implode(' ', array_filter([
        ghastly_prh_format_family($title),
        ghastly_prh_named($title['format'] ?? ''),
        ghastly_prh_named($title['consumerFormat'] ?? ''),
        ghastly_prh_named($title['imprint'] ?? ''),
        ghastly_prh_named($title['consumerImprint'] ?? ''),
        ghastly_prh_named($title['division'] ?? ''),
        (string) ($title['audioPackage'] ?? ''),
        ghastly_prh_named($title['contribRoleDesc'] ?? ''),
    ])));
    return (bool) preg_match('/\b(audio|audiobook|narrat)/', $haystack);
}

function ghastly_prh_merge_title(array $base, array $detail): array
{
    foreach (['format', 'formatFamily', 'consumerFormat', 'imprint', 'division', 'audioPackage', 'projectedMinutes', 'pages', 'author', 'authors'] as $field) {
        if (!isset($detail[$field])) {
            continue;
        }
        $current = $base[$field] ?? null;
        $preferDetail = $field === 'format' && is_array($detail[$field]);
        if ($preferDetail || $current === null || $current === '' || ($field === 'formatFamily' && ghastly_prh_named($current, 80) === 'Book')) {
            $base[$field] = $detail[$field];
        }
    }
    return $base;
}

function ghastly_prh_fetch_titles_by_isbn(string $domain, string $apiKey, array $isbns): array
{
    $details = [];
    $isbns = array_values(array_unique(array_filter($isbns)));
    foreach (array_chunk($isbns, 25) as $chunk) {
        $query = [
            'api_key' => $apiKey,
            'rows' => (string) count($chunk),
            'returnEmptyLists' => 'true',
        ];
        $url = 'https://api.penguinrandomhouse.com/resources/v2/title/domains/' . rawurlencode($domain) . '/titles?' . http_build_query($query);
        foreach ($chunk as $isbn) {
            $url .= '&isbn=' . rawurlencode((string) $isbn);
        }
        $payload = ghastly_http_json($url);
        $title = ghastly_prh_unwrap_title($payload);
        if (is_array($title)) {
            $isbn = preg_replace('/\D+/', '', (string) ($title['isbn'] ?? ($title['isbn13'] ?? ''))) ?? '';
            if ($isbn !== '') {
                $details[$isbn] = $title;
            }
        }
        foreach (ghastly_prh_title_rows($payload) as $row) {
            $isbn = preg_replace('/\D+/', '', (string) ($row['isbn'] ?? ($row['isbn13'] ?? ''))) ?? '';
            if ($isbn !== '') {
                $details[$isbn] = $row;
            }
        }
        usleep(250_000);
    }
    return $details;
}

function ghastly_prh_platform(array $title): string
{
    if (ghastly_prh_is_audiobook($title)) {
        return 'Audiobook';
    }
    $family = ghastly_prh_format_family($title);
    $code = ghastly_prh_format_code($title);
    if ($family === 'Ebook' || $code === 'EL') {
        return 'Ebook';
    }
    return $family !== '' ? $family : 'Book';
}

function ghastly_prh_title_url(array $title, string $isbn): string
{
    foreach (['seoFriendlyUrl', 'uri', 'url'] as $field) {
        $value = trim((string) ($title[$field] ?? ''));
        if ($value !== '' && str_starts_with($value, 'http')) {
            return $value;
        }
        if ($value !== '' && str_starts_with($value, '/')) {
            return 'https://www.penguinrandomhouse.com' . $value;
        }
    }
    $workId = trim((string) ($title['workId'] ?? ''));
    if ($workId !== '' && ctype_digit($workId)) {
        return 'https://www.penguinrandomhouse.com/books/' . rawurlencode($workId) . '/';
    }
    return 'https://www.penguinrandomhouse.com/search/site/' . rawurlencode($isbn);
}

function ghastly_prh_cover_url(array $title, string $isbn): ?string
{
    foreach (['coverUrl', 'coverLink', 'imageUrl', 'cover'] as $field) {
        $url = trim((string) ($title[$field] ?? ''));
        if (filter_var($url, FILTER_VALIDATE_URL)) {
            return $url;
        }
    }
    foreach (is_array($title['_links'] ?? null) ? $title['_links'] : [] as $link) {
        if (!is_array($link)) {
            continue;
        }
        $rel = strtolower((string) ($link['rel'] ?? ''));
        $href = trim((string) ($link['href'] ?? ''));
        if (($rel === 'icon' || str_contains($rel, 'cover')) && filter_var($href, FILTER_VALIDATE_URL)) {
            return $href;
        }
    }
    if ($isbn !== '') {
        return 'https://images.randomhouse.com/cover/' . rawurlencode($isbn);
    }
    return null;
}

function ghastly_prh_title_rows(array $payload): array
{
    foreach ([
        $payload['data']['titles'] ?? null,
        $payload['titles'] ?? null,
        $payload['data']['works'] ?? null,
        $payload['works'] ?? null,
    ] as $rows) {
        if (!is_array($rows) || $rows === []) {
            continue;
        }
        $titles = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (isset($row['isbn']) || isset($row['isbn13']) || isset($row['title'])) {
                $titles[] = $row;
            }
            foreach (is_array($row['titles'] ?? null) ? $row['titles'] : [] as $nested) {
                if (is_array($nested)) {
                    $titles[] = $nested;
                }
            }
        }
        if ($titles !== []) {
            return $titles;
        }
    }
    return [];
}

function ghastly_prh_unwrap_title(array $payload): ?array
{
    $rows = ghastly_prh_title_rows($payload);
    if ($rows !== []) {
        return $rows[0];
    }
    $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
    if (isset($data['title']) && is_array($data['title']) && !isset($data['isbn']) && !isset($data['isbn13'])) {
        $data = $data['title'];
    }
    if (is_array($data) && (isset($data['isbn']) || isset($data['isbn13']) || isset($data['title']))) {
        return $data;
    }
    return null;
}

function ghastly_prh_content_item_text(array $item): string
{
    $label = mb_strtolower(implode(' ', array_filter([
        ghastly_prh_named($item['contentTypeDesc'] ?? ''),
        (string) ($item['contentTypeCode'] ?? ''),
        (string) ($item['type'] ?? ''),
        (string) ($item['key'] ?? ''),
        (string) ($item['code'] ?? ''),
        (string) ($item['name'] ?? ''),
    ])));
    $text = ghastly_text($item['text'] ?? ($item['value'] ?? ($item['content'] ?? ($item['flapCopy'] ?? ($item['flapcopy'] ?? ($item['jacketCopy'] ?? ($item['description'] ?? '')))))), 4000);
    if ($text === '') {
        return '';
    }
    if (preg_match('/bio|excerpt|author|quote|teachers? guide|reading group/', $label) && !preg_match('/flap|jacket|about the book|description|overview|summary/', $label)) {
        return '';
    }
    if ($label === '' || preg_match('/flap|jacket|about|description|copy|overview|summary/', $label)) {
        return $text;
    }
    return '';
}

function ghastly_prh_embedded_content(array $title): array
{
    $items = [];
    $candidates = [$title['content'] ?? null, $title['_embeds'] ?? null, $title['embeds'] ?? null];
    foreach ($candidates as $candidate) {
        if (!is_array($candidate)) {
            continue;
        }
        $rows = [];
        if (array_is_list($candidate)) {
            $rows = $candidate;
        } else {
            foreach (['content', 'titleContent', 'contents'] as $key) {
                if (is_array($candidate[$key] ?? null)) {
                    $rows = array_merge($rows, $candidate[$key]);
                }
            }
            $rows[] = $candidate;
        }
        foreach ($rows as $row) {
            if (is_array($row)) {
                $items[] = $row;
                foreach (['content', 'titleContent', 'contents'] as $key) {
                    foreach (is_array($row[$key] ?? null) ? $row[$key] : [] as $nested) {
                        if (is_array($nested)) {
                            $items[] = $nested;
                        }
                    }
                }
            }
        }
    }
    return $items;
}

function ghastly_prh_synopsis(array $title): string
{
    foreach (['flapCopy', 'flapcopy', 'jacketCopy', 'aboutTheBook', 'description', 'titleDescription', 'copy'] as $field) {
        $text = ghastly_text($title[$field] ?? '', 4000);
        if ($text !== '') {
            return $text;
        }
    }
    foreach (ghastly_prh_embedded_content($title) as $item) {
        $text = ghastly_prh_content_item_text($item);
        if ($text !== '') {
            return $text;
        }
        foreach (['flapCopy', 'flapcopy', 'jacketCopy', 'aboutTheBook', 'description'] as $field) {
            $nested = ghastly_text($item[$field] ?? '', 4000);
            if ($nested !== '') {
                return $nested;
            }
        }
    }
    return '';
}

function ghastly_prh_fetch_synopsis(string $domain, string $isbn, string $apiKey): string
{
    try {
        $payload = ghastly_http_json('https://api.penguinrandomhouse.com/resources/v2/title/domains/' . rawurlencode($domain) . '/titles/' . rawurlencode($isbn) . '/content?' . http_build_query([
            'api_key' => $apiKey,
            'returnEmptyLists' => 'true',
        ]));
    } catch (Throwable $error) {
        return '';
    }
    usleep(150_000);
    $rows = $payload['data']['content'] ?? ($payload['content'] ?? ($payload['data'] ?? []));
    if (!is_array($rows)) {
        return '';
    }
    $synopsis = ghastly_prh_synopsis(array_is_list($rows) ? ['content' => $rows] : $rows);
    if ($synopsis !== '') {
        return $synopsis;
    }
    foreach ($rows as $item) {
        if (is_array($item)) {
            $text = ghastly_prh_content_item_text($item);
            if ($text !== '') {
                return $text;
            }
        }
    }
    return '';
}

function ghastly_prh_subjects(array $title): array
{
    $subjects = [];
    foreach (['subjects', 'categories', 'bisacs'] as $field) {
        foreach (is_array($title[$field] ?? null) ? $title[$field] : [] as $subject) {
            if (is_array($subject)) {
                $subjects[] = $subject;
            }
        }
    }
    foreach (ghastly_prh_embedded_content($title) as $item) {
        foreach (['subjects', 'categories'] as $field) {
            foreach (is_array($item[$field] ?? null) ? $item[$field] : [] as $subject) {
                if (is_array($subject)) {
                    $subjects[] = $subject;
                }
            }
        }
    }
    return $subjects;
}

function ghastly_prh_is_comic(array $title, string $catUri = ''): bool
{
    $titleHay = mb_strtolower(trim((string) ($title['title'] ?? '') . ' ' . (string) ($title['subtitle'] ?? '')));
    if (preg_match('/\b(ttrpg|rulebook|roleplaying|role-playing)\b/', $titleHay)) {
        return false;
    }
    foreach (ghastly_prh_subjects($title) as $subject) {
        $code = strtoupper(preg_replace('/\s+/', '', (string) ($subject['code'] ?? ($subject['bisac'] ?? ''))) ?? '');
        if (str_starts_with($code, 'CGN')) {
            return true;
        }
    }
    $parts = [
        $catUri,
        ghastly_prh_named($title['imprint'] ?? ''),
        ghastly_prh_named($title['publisher'] ?? ''),
        ghastly_prh_named($title['division'] ?? ''),
        ghastly_prh_named($title['consumerImprint'] ?? ''),
        ghastly_prh_format_family($title),
        ghastly_prh_named($title['format'] ?? ''),
        ghastly_prh_named($title['subformat'] ?? ($title['subFormat'] ?? '')),
        (string) ($title['subtitle'] ?? ''),
    ];
    foreach (ghastly_prh_subjects($title) as $subject) {
        $parts[] = (string) ($subject['description'] ?? ($subject['name'] ?? ''));
        $parts[] = (string) ($subject['catUri'] ?? '');
    }
    $haystack = mb_strtolower(implode(' ', $parts));
    if (preg_match('/graphic[- ]novels?|\bcomics?\b|\bmanga\b|comic book/', $haystack)) {
        return true;
    }
    foreach ([
        'marvel', 'dark horse', 'idw', 'boom! studios', 'boom studios',
        'dc comics', 'image comics', 'dynamite', 'dstlry', 'kodansha comics',
        'seven seas', 'titan comics', 'oni press', 'aftershock', 'ignition press',
        'penguin random house comics', 'prh comics', 'viz media',
    ] as $imprint) {
        if (str_contains($haystack, $imprint)) {
            return true;
        }
    }
    return false;
}

function ghastly_collect_prh(string $start, string $end): array
{
    $config = ghastly_config();
    $apiKey = trim((string) ($config['prh_api_key'] ?? ''));
    if ($apiKey === '') {
        throw new GhastlySourceUnavailable('Penguin Random House API key is not configured.');
    }
    $domain = trim((string) ($config['prh_domain'] ?? 'PRH.US')) ?: 'PRH.US';
    $categories = ghastly_config_strings($config['prh_cat_uris'] ?? ['/gothic-horror']);
    $onSaleFrom = DateTimeImmutable::createFromFormat('!Y-m-d', $start, new DateTimeZone('UTC'))?->format('m/d/Y');
    $onSaleTo = DateTimeImmutable::createFromFormat('!Y-m-d', $end, new DateTimeZone('UTC'))?->format('m/d/Y');
    if ($onSaleFrom === null || $onSaleTo === null) {
        throw new RuntimeException('PRH requires a valid on-sale date range.');
    }
    $collected = [];
    foreach ($categories as $catUri) {
        for ($startIndex = 0; $startIndex < 400; $startIndex += 50) {
            $payload = ghastly_http_json('https://api.penguinrandomhouse.com/resources/v2/title/domains/' . rawurlencode($domain) . '/titles/views/list-display?' . http_build_query([
                'api_key' => $apiKey,
                'catUri' => $catUri,
                'onSaleFrom' => $onSaleFrom,
                'onSaleTo' => $onSaleTo,
                'start' => (string) $startIndex,
                'rows' => '50',
                'sort' => 'onsale',
                'dir' => 'asc',
                'returnEmptyLists' => 'true',
                'zoom' => 'https://api.penguinrandomhouse.com/title/titles/content/definition',
            ]));
            $titles = ghastly_prh_title_rows($payload);
            foreach ($titles as $title) {
                $isbn = preg_replace('/\D+/', '', (string) ($title['isbn'] ?? ($title['isbn13'] ?? ''))) ?? '';
                $date = ghastly_partial_date((string) ($title['onsale'] ?? ($title['onSaleDate'] ?? ($title['earliestOnSaleDate'] ?? ''))));
                if ($isbn === '' || $date === null || $date['date'] < $start || $date['date'] > $end) {
                    continue;
                }
                $title['_catUri'] = $catUri;
                $collected[$isbn] = $title;
            }
            if (count($titles) < 50) {
                break;
            }
            usleep(250_000);
        }
    }
    $needsFormat = [];
    foreach ($collected as $isbn => $title) {
        $isbn = (string) $isbn;
        if (ghastly_prh_format_code($title) === '' || ghastly_prh_format_family($title) === 'Book') {
            $needsFormat[] = $isbn;
        }
    }
    foreach (ghastly_prh_fetch_titles_by_isbn($domain, $apiKey, $needsFormat) as $isbn => $detail) {
        $isbn = (string) $isbn;
        if (isset($collected[$isbn])) {
            $collected[$isbn] = ghastly_prh_merge_title($collected[$isbn], $detail);
        }
    }
    foreach ($collected as $isbn => $title) {
        $isbn = (string) $isbn;
        if (ghastly_prh_format_code($title) !== '') {
            continue;
        }
        try {
            $detail = ghastly_http_json('https://api.penguinrandomhouse.com/resources/v2/title/domains/' . rawurlencode($domain) . '/titles/' . rawurlencode((string) $isbn) . '?' . http_build_query([
                'api_key' => $apiKey,
                'returnEmptyLists' => 'true',
            ]));
            $row = ghastly_prh_unwrap_title($detail);
            if (is_array($row)) {
                $collected[$isbn] = ghastly_prh_merge_title($title, $row);
            }
        } catch (Throwable) {
            // Keep the list-display record if a single-ISBN lookup fails.
        }
        usleep(250_000);
    }
    $releases = [];
    $seen = [];
    $preferredFormat = ['Hardcover' => 3, 'Paperback' => 2, 'Ebook' => 1, 'Audiobook' => 1];
    foreach ($collected as $isbn => $title) {
        $isbn = (string) $isbn;
        $formatFamily = ghastly_prh_format_family($title);
        $platform = ghastly_prh_platform($title);
        if (in_array($formatFamily, ['App', 'Merchandise', 'Boxed Set', 'Board Book'], true)) {
            continue;
        }
        $date = ghastly_partial_date((string) ($title['onsale'] ?? ($title['onSaleDate'] ?? ($title['earliestOnSaleDate'] ?? ''))));
        if ($date === null) {
            continue;
        }
        $workKey = trim((string) ($title['workId'] ?? $isbn)) . '|' . ($platform === 'Audiobook' ? 'audio' : 'print');
        $score = $preferredFormat[$platform] ?? ($preferredFormat[$formatFamily] ?? 0);
        if (isset($seen[$workKey]) && $seen[$workKey] >= $score) {
            continue;
        }
        $synopsis = ghastly_prh_synopsis($title);
        if ($synopsis === '') {
            $synopsis = ghastly_prh_fetch_synopsis($domain, (string) $isbn, $apiKey);
        }
        $catUri = (string) ($title['_catUri'] ?? '');
        unset($title['_catUri']);
        $releases[$workKey] = ghastly_normalize_release([
            'external_id' => 'prh-' . $isbn,
            'title' => $title['title'] ?? '',
            'creator' => ghastly_prh_authors($title),
            'media_type' => ghastly_prh_is_comic($title, $catUri) ? 'comic' : 'book',
            'release_date' => $date['date'],
            'date_precision' => $date['precision'],
            'publisher' => ghastly_prh_named($title['imprint'] ?? '') ?: (ghastly_prh_named($title['publisher'] ?? '') ?: 'Penguin Random House'),
            'platform' => $platform,
            'region' => 'US',
            'horror_tags' => ['Horror'],
            'synopsis' => $synopsis,
            'source_url' => ghastly_prh_title_url($title, $isbn),
            'source_name' => 'Penguin Random House',
            'confidence' => 'partner',
            'source_rank' => 70,
            'artwork_url' => ghastly_prh_cover_url($title, $isbn),
        ]);
        $seen[$workKey] = $score;
    }
    return array_values($releases);
}

function ghastly_tvmaze_show(array $episode): array
{
    if (is_array($episode['show'] ?? null)) {
        return $episode['show'];
    }
    $embedded = is_array($episode['_embedded'] ?? null) ? $episode['_embedded'] : [];
    return is_array($embedded['show'] ?? null) ? $embedded['show'] : [];
}

function ghastly_tvmaze_is_horror_show(array $show): bool
{
    foreach (is_array($show['genres'] ?? null) ? $show['genres'] : [] as $genre) {
        if (mb_strtolower(trim((string) $genre)) === 'horror') {
            return true;
        }
    }
    return false;
}

function ghastly_tvmaze_is_season_premiere(array $episode): bool
{
    return (int) ($episode['season'] ?? 0) >= 1 && (int) ($episode['number'] ?? 0) === 1;
}

function ghastly_tvmaze_has_current_episodes(array $show, array $episode): bool
{
    return ghastly_tvmaze_is_season_premiere($episode)
        && ghastly_valid_date($episode['airdate'] ?? null)
        && trim((string) ($show['name'] ?? '')) !== '';
}

function ghastly_tvmaze_country_code(mixed $channel): string
{
    if (!is_array($channel)) {
        return '';
    }
    $country = is_array($channel['country'] ?? null) ? $channel['country'] : [];
    return strtoupper(trim((string) ($country['code'] ?? '')));
}

function ghastly_tvmaze_is_us_available(array $show): bool
{
    $networkCode = ghastly_tvmaze_country_code($show['network'] ?? null);
    if ($networkCode === 'US') {
        return true;
    }
    $webChannel = is_array($show['webChannel'] ?? null) ? $show['webChannel'] : null;
    if ($webChannel === null) {
        return false;
    }
    $webCode = ghastly_tvmaze_country_code($webChannel);
    return $webCode === '' || $webCode === 'US';
}

function ghastly_tvmaze_release_from_premiere(array $episode, string $start, string $end): ?array
{
    $show = ghastly_tvmaze_show($episode);
    $id = trim((string) ($show['id'] ?? ''));
    $date = $episode['airdate'] ?? null;
    $sourceUrl = filter_var($show['url'] ?? null, FILTER_VALIDATE_URL);
    if (
        $id === ''
        || $sourceUrl === false
        || !ghastly_tvmaze_is_horror_show($show)
        || !ghastly_tvmaze_has_current_episodes($show, $episode)
        || !ghastly_tvmaze_is_us_available($show)
        || !ghastly_valid_date($date)
        || $date < $start
        || $date > $end
    ) {
        return null;
    }
    foreach (is_array($show['genres'] ?? null) ? $show['genres'] : [] as $genre) {
        if (mb_strtolower(trim((string) $genre)) === 'adult') {
            return null;
        }
    }
    $seasonNumber = (int) ($episode['season'] ?? 0);
    $network = is_array($show['network'] ?? null) ? $show['network'] : [];
    $webChannel = is_array($show['webChannel'] ?? null) ? $show['webChannel'] : [];
    $publisher = ghastly_text($network['name'] ?? ($webChannel['name'] ?? ''));
    $image = is_array($show['image'] ?? null) ? $show['image'] : [];
    $tags = ['Horror'];
    if (strcasecmp(trim((string) ($show['language'] ?? 'English')), 'English') !== 0) {
        $tags[] = 'International cinema';
    }
    return ghastly_normalize_release([
        'external_id' => $seasonNumber === 1 ? 'tvmaze-show-' . $id : 'tvmaze-show-' . $id . '-s' . $seasonNumber,
        'title' => $show['name'] ?? '',
        'media_type' => 'show',
        'release_date' => $date,
        'date_precision' => 'day',
        'publisher' => $publisher,
        'platform' => $seasonNumber === 1 ? 'Series premiere' : 'Season ' . $seasonNumber . ' premiere',
        'region' => 'US',
        'horror_tags' => $tags,
        'synopsis' => $show['summary'] ?? '',
        'source_url' => (string) $sourceUrl,
        'source_name' => 'TVmaze',
        'confidence' => 'database',
        'source_rank' => 35,
        'artwork_url' => $image['original'] ?? ($image['medium'] ?? null),
    ]);
}

function ghastly_collect_tvmaze(string $start, string $end): array
{
    if (!ghastly_valid_date($start) || !ghastly_valid_date($end) || $start > $end) {
        throw new RuntimeException('TVmaze requires a valid release-date range.');
    }
    $config = ghastly_config();
    if (($config['tvmaze_enabled'] ?? true) !== true) {
        throw new GhastlySourceUnavailable('TVmaze discovery is not enabled.');
    }

    $releases = [];
    $seen = [];
    // Keep the response text, but expand only the episode currently being filtered.
    $schedule = ghastly_http_request('https://api.tvmaze.com/schedule/full', ['Accept: application/json'], 'GET', null, 120);
    foreach (ghastly_json_object_items($schedule) as $episode) {
        if (!is_array($episode)) {
            continue;
        }
        $release = ghastly_tvmaze_release_from_premiere($episode, $start, $end);
        $externalId = is_array($release) ? (string) ($release['external_id'] ?? '') : '';
        if ($release === null || $externalId === '' || isset($seen[$externalId])) {
            continue;
        }
        $releases[] = $release;
        $seen[$externalId] = true;
    }
    return $releases;
}
