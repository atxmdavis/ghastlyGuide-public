<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300, stale-while-revalidate=600');

try {
    require dirname(__DIR__, 2) . '/private/src/bootstrap.php';
    $today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
    $minimum = $today->modify('-1 year')->format('Y-m-d');
    $maximum = $today->modify('+2 years')->format('Y-m-d');
    $search = preg_replace('/\s+/u', ' ', trim((string) ($_GET['q'] ?? ''))) ?? '';
    $releaseId = isset($_GET['id']) && preg_match('/^\d+$/', (string) $_GET['id'])
        ? (int) $_GET['id']
        : null;
    $ids = null;
    if (isset($_GET['ids'])) {
        $rawIds = (string) $_GET['ids'];
        if (!preg_match('/^\d+(?:,\d+){0,99}$/', $rawIds)) {
            header('Cache-Control: no-store');
            http_response_code(400);
            echo json_encode(['error' => 'Choose up to 100 valid releases.'], JSON_THROW_ON_ERROR);
            exit;
        }
        $ids = array_values(array_unique(array_map('intval', explode(',', $rawIds))));
    }
    $from = $_GET['from'] ?? $today->modify('first day of this month')->format('Y-m-d');
    $to = $_GET['to'] ?? $today->modify('+6 months')->format('Y-m-d');
    $valid = static fn (mixed $value): bool => is_string($value)
        && (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)
        && DateTimeImmutable::createFromFormat('!Y-m-d', $value)?->format('Y-m-d') === $value;
    if ($search !== '' && (mb_strlen($search) < 2 || mb_strlen($search) > 100)) {
        header('Cache-Control: no-store');
        http_response_code(400);
        echo json_encode(['error' => 'Search using 2 to 100 characters.'], JSON_THROW_ON_ERROR);
        exit;
    }
    if (isset($_GET['id']) && $releaseId === null) {
        header('Cache-Control: no-store');
        http_response_code(400);
        echo json_encode(['error' => 'Choose a valid release.'], JSON_THROW_ON_ERROR);
        exit;
    }
    if ($ids === null && $releaseId === null && $search === '' && (!$valid($from) || !$valid($to) || $from > $to || $from < $minimum || $to > $maximum)) {
        header('Cache-Control: no-store');
        http_response_code(400);
        echo json_encode(['error' => 'Choose a valid range from one year ago through two years ahead.'], JSON_THROW_ON_ERROR);
        exit;
    }
    $select = 'SELECT id, title, creator, media_type, release_date, date_precision, publisher, platform, region, horror_tags, synopsis, source_url, source_name, confidence, artwork_url, trailer_url, last_seen_at FROM releases';
    if ($ids !== null) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $statement = ghastly_db()->prepare($select . " WHERE active = 1 AND id IN ({$placeholders}) ORDER BY release_date, id");
        $statement->execute($ids);
    } elseif ($releaseId !== null) {
        $statement = ghastly_db()->prepare($select . ' WHERE active = 1 AND id = :release_id LIMIT 1');
        $statement->execute(['release_id' => $releaseId]);
    } elseif ($search !== '') {
        $escapeLike = static fn (string $value): string => str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
        $searchTerms = array_slice(preg_split('/\s+/u', $search) ?: [], 0, 8);
        $conditions = [];
        $parameters = [
            'exact_title' => $search,
            'prefix_title' => $escapeLike($search) . '%',
        ];
        foreach ($searchTerms as $index => $term) {
            $parameter = '%' . $escapeLike($term) . '%';
            $conditions[] = "(title LIKE :title_{$index} OR creator LIKE :creator_{$index} OR publisher LIKE :publisher_{$index} OR platform LIKE :platform_{$index} OR CAST(horror_tags AS CHAR) LIKE :tags_{$index})";
            $parameters["title_{$index}"] = $parameter;
            $parameters["creator_{$index}"] = $parameter;
            $parameters["publisher_{$index}"] = $parameter;
            $parameters["platform_{$index}"] = $parameter;
            $parameters["tags_{$index}"] = $parameter;
        }
        $statement = ghastly_db()->prepare(
            $select . ' WHERE active = 1 AND release_date IS NOT NULL AND ' . implode(' AND ', $conditions)
            . ' ORDER BY CASE WHEN title = :exact_title THEN 0 WHEN title LIKE :prefix_title THEN 1 ELSE 2 END, release_date, media_type, title, id LIMIT 501'
        );
        $statement->execute($parameters);
    } else {
        $statement = ghastly_db()->prepare(
            $select . ' WHERE active = 1 AND release_date BETWEEN :from_date AND :to_date ORDER BY release_date, media_type, title, id'
        );
        $statement->execute(['from_date' => $from, 'to_date' => $to]);
    }
    $rows = $statement->fetchAll();
    $searchWasCapped = $search !== '' && count($rows) > 500;
    if ($searchWasCapped) {
        array_pop($rows);
    }
    if ($releaseId !== null && count($rows) === 1) {
        $detailRow = $rows[0];
        if ($detailRow['media_type'] === 'video_game' && $detailRow['confidence'] === 'official') {
            $detailDate = new DateTimeImmutable((string) $detailRow['release_date'], new DateTimeZone('UTC'));
            $supplementStatement = ghastly_db()->prepare(
                $select . " WHERE active = 1 AND media_type = 'video_game' AND source_name = 'IGDB'
                    AND title = :title AND release_date BETWEEN :from_date AND :to_date ORDER BY release_date, id"
            );
            $supplementStatement->execute([
                'title' => $detailRow['title'],
                'from_date' => $detailDate->modify('-7 days')->format('Y-m-d'),
                'to_date' => $detailDate->modify('+7 days')->format('Y-m-d'),
            ]);
            foreach ($supplementStatement->fetchAll() as $supplement) {
                $rows[] = $supplement;
            }
        }
    }
    $ratingsByRelease = [];
    $steamIdsByRelease = [];
    $watchByRelease = [];
    $releaseIds = array_values(array_unique(array_map(static fn (array $row): int => (int) $row['id'], $rows)));
    if ($releaseIds !== []) {
        $placeholders = implode(',', array_fill(0, count($releaseIds), '?'));
        $watchStatement = ghastly_db()->prepare(
            "SELECT identity_record.release_id, watch.offers_json, watch.source_url, watch.checked_at
             FROM release_external_ids identity_record
             INNER JOIN movie_watch_availability watch ON watch.tmdb_id = identity_record.external_id
             WHERE identity_record.provider = 'tmdb_movie' AND watch.region = 'US'
               AND watch.checked_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 3 DAY)
               AND identity_record.release_id IN ({$placeholders})
             ORDER BY watch.checked_at DESC"
        );
        $watchStatement->execute($releaseIds);
        foreach ($watchStatement->fetchAll() as $watch) {
            $offers = json_decode((string) $watch['offers_json'], true);
            if (!is_array($offers)) continue;
            $watchByRelease[(int) $watch['release_id']] ??= [
                'region' => 'US', 'offers' => $offers,
                'source' => $watch['source_url'],
                'checkedAt' => gmdate(DATE_ATOM, strtotime($watch['checked_at'] . ' UTC')),
            ];
        }
        $steamStatement = ghastly_db()->prepare(
            "SELECT release_id, external_id FROM release_external_ids
             WHERE provider = 'steam' AND release_id IN ({$placeholders})
             ORDER BY external_id"
        );
        $steamStatement->execute($releaseIds);
        foreach ($steamStatement->fetchAll() as $identity) {
            if (preg_match('/^[0-9]+$/', (string) $identity['external_id'])) {
                $steamIdsByRelease[(int) $identity['release_id']] ??= (string) $identity['external_id'];
            }
        }
        $ratingStatement = ghastly_db()->prepare(
            "SELECT identity_record.release_id, rating.provider, rating.rating_kind, rating.score, rating.scale,
                    rating.rating_count, rating.label, rating.source_url, rating.retrieved_at
             FROM release_external_ids identity_record
             INNER JOIN reception_ratings rating
               ON rating.provider = identity_record.provider AND rating.external_id = identity_record.external_id
             WHERE identity_record.release_id IN ({$placeholders}) AND rating.rating_count >= 10
             ORDER BY rating.provider, rating.rating_kind"
        );
        $ratingStatement->execute($releaseIds);
        foreach ($ratingStatement->fetchAll() as $rating) {
            $provider = match ((string) $rating['provider']) {
                'steam' => 'Steam',
                'tmdb_movie', 'tmdb_tv' => 'TMDB',
                default => null,
            };
            if ($provider === null || !filter_var($rating['source_url'] ?? null, FILTER_VALIDATE_URL)) {
                continue;
            }
            $ratingsByRelease[(int) $rating['release_id']][] = [
                'provider' => $provider,
                'kind' => $rating['rating_kind'],
                'score' => (float) $rating['score'],
                'scale' => (float) $rating['scale'],
                'count' => (int) $rating['rating_count'],
                'label' => is_string($rating['label'] ?? null) && $rating['label'] !== '' ? $rating['label'] : null,
                'source' => $rating['source_url'],
                'updatedAt' => gmdate(DATE_ATOM, strtotime((string) $rating['retrieved_at'] . ' UTC')),
            ];
        }
    }
    foreach ($rows as &$row) {
        $row['reception'] = (string) $row['release_date'] <= $today->format('Y-m-d')
            ? ($ratingsByRelease[(int) $row['id']] ?? [])
            : [];
    }
    unset($row);
    $mergeReception = static function (array $primary, array $supplemental): array {
        $merged = [];
        foreach (array_merge($primary, $supplemental) as $rating) {
            if (!is_array($rating)) {
                continue;
            }
            $merged[(string) ($rating['provider'] ?? '') . '|' . (string) ($rating['kind'] ?? '')] = $rating;
        }
        return array_values($merged);
    };
    $igdbGames = [];
    foreach ($rows as $row) {
        if ($row['media_type'] !== 'video_game' || $row['source_name'] !== 'IGDB') {
            continue;
        }
        $normalizedTitle = mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '', (string) $row['title']) ?? '');
        $igdbGames[$normalizedTitle . '|' . substr((string) $row['release_date'], 0, 4)] = true;
    }
    $displayRows = [];
    $igdbGroupIndexes = [];
    $igdbPlatforms = [];
    $igdbRegions = [];
    $movieGroupIndexes = [];
    $moviePlatforms = [];
    $bookGroupIndexes = [];
    $bookPlatforms = [];
    $bookPublishers = [];
    $bookFormatRank = [
        'Hardcover' => 4,
        'Paperback' => 3,
        'Ebook' => 2,
        'Book' => 1,
        'Audiobook' => 0,
    ];
    $fillBookGaps = static function (array $keeper, array $row): array {
        foreach (['synopsis', 'creator'] as $field) {
            if (trim((string) ($keeper[$field] ?? '')) === '' && trim((string) ($row[$field] ?? '')) !== '') {
                $keeper[$field] = $row[$field];
            }
        }
        if (!filter_var($keeper['artwork_url'] ?? null, FILTER_VALIDATE_URL)
            && filter_var($row['artwork_url'] ?? null, FILTER_VALIDATE_URL)
        ) {
            $keeper['artwork_url'] = $row['artwork_url'];
        }
        $keptTags = json_decode((string) ($keeper['horror_tags'] ?? '[]'), true);
        $rowTags = json_decode((string) ($row['horror_tags'] ?? '[]'), true);
        $keeper['horror_tags'] = json_encode(array_values(array_unique(array_merge(
            is_array($keptTags) ? $keptTags : [],
            is_array($rowTags) ? $rowTags : [],
        ))), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return $keeper;
    };
    foreach ($rows as $row) {
        if ($row['media_type'] === 'video_game' && $row['source_name'] === 'RAWG') {
            $normalizedTitle = mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '', (string) $row['title']) ?? '');
            if (isset($igdbGames[$normalizedTitle . '|' . substr((string) $row['release_date'], 0, 4)])) {
                continue;
            }
        }
        if ($row['media_type'] === 'video_game' && $row['source_name'] === 'IGDB') {
            $normalizedTitle = mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '', (string) $row['title']) ?? '');
            $groupKey = implode('|', [
                $normalizedTitle,
                (string) $row['release_date'],
            ]);
            if (!isset($igdbGroupIndexes[$groupKey])) {
                $igdbGroupIndexes[$groupKey] = count($displayRows);
                $igdbPlatforms[$groupKey] = [];
                $igdbRegions[$groupKey] = [];
                $displayRows[] = $row;
            } else {
                $index = $igdbGroupIndexes[$groupKey];
                $displayRows[$index]['reception'] = $mergeReception($displayRows[$index]['reception'], $row['reception']);
            }
            $platform = trim((string) ($row['platform'] ?? ''));
            if ($platform !== '') {
                $igdbPlatforms[$groupKey][] = $platform;
            }
            $region = trim((string) ($row['region'] ?? ''));
            if ($region !== '') {
                $igdbRegions[$groupKey][] = $region;
            }
            continue;
        }
        if ($row['media_type'] === 'movie') {
            $groupKey = implode('|', [
                (string) $row['source_url'],
                (string) $row['release_date'],
            ]);
            if (!isset($movieGroupIndexes[$groupKey])) {
                $movieGroupIndexes[$groupKey] = count($displayRows);
                $moviePlatforms[$groupKey] = [];
                $displayRows[] = $row;
            } else {
                $index = $movieGroupIndexes[$groupKey];
                $kept = $displayRows[$index];
                $displayRows[$index]['reception'] = $mergeReception($kept['reception'], $row['reception']);
                $keptTags = json_decode((string) ($kept['horror_tags'] ?? '[]'), true);
                $rowTags = json_decode((string) ($row['horror_tags'] ?? '[]'), true);
                $displayRows[$index]['horror_tags'] = json_encode(array_values(array_unique(array_merge(
                    is_array($keptTags) ? $keptTags : [],
                    is_array($rowTags) ? $rowTags : [],
                ))), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                if (!filter_var($kept['artwork_url'] ?? null, FILTER_VALIDATE_URL)
                    && filter_var($row['artwork_url'] ?? null, FILTER_VALIDATE_URL)
                ) {
                    $displayRows[$index]['artwork_url'] = $row['artwork_url'];
                }
                if (
                    (!is_string($kept['trailer_url'] ?? null) || $kept['trailer_url'] === '')
                    && is_string($row['trailer_url'] ?? null)
                    && preg_match('#^https://www\.youtube\.com/watch\?v=[A-Za-z0-9_-]{11}$#', $row['trailer_url'])
                ) {
                    $displayRows[$index]['trailer_url'] = $row['trailer_url'];
                }
            }
            $platform = trim((string) ($row['platform'] ?? ''));
            if ($platform !== '') {
                $moviePlatforms[$groupKey][] = $platform;
            }
            continue;
        }
        if ($row['media_type'] === 'book') {
            $normalizedTitle = mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '', (string) $row['title']) ?? '');
            $groupKey = implode('|', [
                $normalizedTitle,
                (string) $row['release_date'],
            ]);
            $rank = $bookFormatRank[trim((string) ($row['platform'] ?? ''))] ?? 1;
            if (!isset($bookGroupIndexes[$groupKey])) {
                $bookGroupIndexes[$groupKey] = count($displayRows);
                $bookPlatforms[$groupKey] = [];
                $bookPublishers[$groupKey] = [];
                $row['_book_rank'] = $rank;
                $displayRows[] = $row;
            } else {
                $index = $bookGroupIndexes[$groupKey];
                $kept = $displayRows[$index];
                $keptRank = (int) ($kept['_book_rank'] ?? 0);
                if ($rank > $keptRank) {
                    $incoming = $fillBookGaps($row, $kept);
                    $incoming['_book_rank'] = $rank;
                    $incoming['reception'] = $mergeReception(
                        is_array($row['reception'] ?? null) ? $row['reception'] : [],
                        is_array($kept['reception'] ?? null) ? $kept['reception'] : [],
                    );
                    $displayRows[$index] = $incoming;
                } else {
                    $displayRows[$index] = $fillBookGaps($kept, $row);
                    $displayRows[$index]['reception'] = $mergeReception(
                        is_array($kept['reception'] ?? null) ? $kept['reception'] : [],
                        is_array($row['reception'] ?? null) ? $row['reception'] : [],
                    );
                }
            }
            $platform = trim((string) ($row['platform'] ?? ''));
            if ($platform !== '') {
                $bookPlatforms[$groupKey][] = $platform;
            }
            $publisher = trim((string) ($row['publisher'] ?? ''));
            if ($publisher !== '') {
                $bookPublishers[$groupKey][] = $publisher;
            }
            continue;
        }
        $displayRows[] = $row;
    }
    foreach ($igdbGroupIndexes as $groupKey => $index) {
        $displayRows[$index]['platform'] = implode(', ', array_values(array_unique($igdbPlatforms[$groupKey])));
        $displayRows[$index]['region'] = implode(', ', array_values(array_unique($igdbRegions[$groupKey])));
    }
    $movieFormatOrder = [
        'Theatrical' => 0,
        'Limited theatrical' => 1,
        'Digital' => 2,
        'Physical' => 3,
        'Streaming' => 4,
        'Television' => 5,
    ];
    foreach ($movieGroupIndexes as $groupKey => $index) {
        $unique = [];
        foreach ($moviePlatforms[$groupKey] as $platform) {
            $unique[$platform] = $platform;
        }
        uksort($unique, static function (string $left, string $right) use ($movieFormatOrder): int {
            return ($movieFormatOrder[$left] ?? 99) <=> ($movieFormatOrder[$right] ?? 99) ?: strcmp($left, $right);
        });
        $displayRows[$index]['platform'] = implode(', ', array_values($unique));
    }
    $bookFormatOrder = [
        'Hardcover' => 0,
        'Paperback' => 1,
        'Ebook' => 2,
        'Audiobook' => 3,
        'Book' => 4,
    ];
    foreach ($bookGroupIndexes as $groupKey => $index) {
        $unique = [];
        foreach ($bookPlatforms[$groupKey] as $platform) {
            $unique[$platform] = $platform;
        }
        uksort($unique, static function (string $left, string $right) use ($bookFormatOrder): int {
            return ($bookFormatOrder[$left] ?? 99) <=> ($bookFormatOrder[$right] ?? 99) ?: strcmp($left, $right);
        });
        $displayRows[$index]['platform'] = implode(', ', array_values($unique));
        $publishers = [];
        $keeperPublisher = trim((string) ($displayRows[$index]['publisher'] ?? ''));
        if ($keeperPublisher !== '') {
            $publishers[mb_strtolower($keeperPublisher)] = $keeperPublisher;
        }
        foreach ($bookPublishers[$groupKey] as $publisher) {
            $publishers[mb_strtolower($publisher)] = $publisher;
        }
        $displayRows[$index]['publisher'] = implode(', ', array_values($publishers));
        unset($displayRows[$index]['_book_rank']);
    }
    $mergeCommaValues = static function (mixed $primary, mixed $supplemental): string {
        $merged = [];
        foreach (array_merge(explode(',', (string) $primary), explode(',', (string) $supplemental)) as $value) {
            $value = trim($value);
            if ($value !== '') {
                $merged[mb_strtolower($value)] = $value;
            }
        }
        return implode(', ', array_values($merged));
    };
    $officialGameIndexes = [];
    foreach ($displayRows as $index => $row) {
        if ($row['media_type'] !== 'video_game' || $row['confidence'] !== 'official') {
            continue;
        }
        $normalizedTitle = mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '', (string) $row['title']) ?? '');
        $officialGameIndexes[$normalizedTitle . '|' . substr((string) $row['release_date'], 0, 4)][] = $index;
    }
    $suppressedIgdbIndexes = [];
    foreach ($displayRows as $index => $row) {
        if ($row['media_type'] !== 'video_game' || $row['source_name'] !== 'IGDB') {
            continue;
        }
        $normalizedTitle = mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '', (string) $row['title']) ?? '');
        $matchKey = $normalizedTitle . '|' . substr((string) $row['release_date'], 0, 4);
        foreach ($officialGameIndexes[$matchKey] ?? [] as $officialIndex) {
            $official = $displayRows[$officialIndex];
            $sameDate = $official['release_date'] === $row['release_date'];
            $samePartialMonth = substr((string) $official['release_date'], 0, 7) === substr((string) $row['release_date'], 0, 7)
                && ($official['date_precision'] !== 'day' || $row['date_precision'] !== 'day');
            $officialTimestamp = strtotime((string) $official['release_date'] . ' UTC');
            $igdbTimestamp = strtotime((string) $row['release_date'] . ' UTC');
            $sameReleaseWindow = $officialTimestamp !== false && $igdbTimestamp !== false
                && abs($officialTimestamp - $igdbTimestamp) <= 7 * 86400;
            if (!$sameDate && !$samePartialMonth && !$sameReleaseWindow) {
                continue;
            }
            foreach (['platform', 'region', 'publisher'] as $field) {
                $displayRows[$officialIndex][$field] = $mergeCommaValues($official[$field] ?? '', $row[$field] ?? '');
            }
            $officialTags = json_decode((string) ($official['horror_tags'] ?? '[]'), true);
            $igdbTags = json_decode((string) ($row['horror_tags'] ?? '[]'), true);
            $displayRows[$officialIndex]['horror_tags'] = json_encode(array_values(array_unique(array_merge(
                is_array($officialTags) ? $officialTags : [],
                is_array($igdbTags) ? $igdbTags : [],
            ))), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if (!filter_var($official['artwork_url'] ?? null, FILTER_VALIDATE_URL)
                && filter_var($row['artwork_url'] ?? null, FILTER_VALIDATE_URL)
            ) {
                $displayRows[$officialIndex]['artwork_url'] = $row['artwork_url'];
            }
            if (
                (!is_string($official['trailer_url'] ?? null) || $official['trailer_url'] === '')
                && is_string($row['trailer_url'] ?? null)
                && preg_match('#^https://www\.youtube\.com/watch\?v=[A-Za-z0-9_-]{11}$#', $row['trailer_url'])
            ) {
                $displayRows[$officialIndex]['trailer_url'] = $row['trailer_url'];
            }
            foreach (['creator', 'synopsis'] as $field) {
                if (trim((string) ($official[$field] ?? '')) === '' && trim((string) ($row[$field] ?? '')) !== '') {
                    $displayRows[$officialIndex][$field] = $row[$field];
                }
            }
            $displayRows[$officialIndex]['reception'] = $mergeReception(
                $displayRows[$officialIndex]['reception'],
                $row['reception'],
            );
            $suppressedIgdbIndexes[$index] = true;
            break;
        }
    }
    if ($suppressedIgdbIndexes !== []) {
        $displayRows = array_values(array_filter(
            $displayRows,
            static fn (int $index): bool => !isset($suppressedIgdbIndexes[$index]),
            ARRAY_FILTER_USE_KEY,
        ));
    }
    $showConfidenceRank = static function (array $row): int {
        return match ((string) ($row['confidence'] ?? '')) {
            'official' => 4,
            'editorial' => 3,
            'partner' => 2,
            'database' => 1,
            default => 0,
        };
    };
    $showGroups = [];
    foreach ($displayRows as $index => $row) {
        if ($row['media_type'] !== 'show') {
            continue;
        }
        $normalizedTitle = mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '', (string) $row['title']) ?? '');
        if ($normalizedTitle === '') {
            continue;
        }
        $showGroups[$normalizedTitle][] = $index;
    }
    $suppressedShowIndexes = [];
    foreach ($showGroups as $indexes) {
        usort($indexes, static function (int $left, int $right) use ($displayRows, $showConfidenceRank): int {
            $rank = $showConfidenceRank($displayRows[$right]) <=> $showConfidenceRank($displayRows[$left]);
            if ($rank !== 0) {
                return $rank;
            }
            return ((int) $displayRows[$left]['id']) <=> ((int) $displayRows[$right]['id']);
        });
        $keepers = [];
        foreach ($indexes as $index) {
            $row = $displayRows[$index];
            $rowTimestamp = strtotime((string) $row['release_date'] . ' UTC');
            $matchedKeeper = null;
            foreach ($keepers as $keeperIndex) {
                $keeperTimestamp = strtotime((string) $displayRows[$keeperIndex]['release_date'] . ' UTC');
                if ($rowTimestamp === false || $keeperTimestamp === false) {
                    continue;
                }
                if (abs($rowTimestamp - $keeperTimestamp) <= 7 * 86400) {
                    $matchedKeeper = $keeperIndex;
                    break;
                }
            }
            if ($matchedKeeper === null) {
                $keepers[] = $index;
                continue;
            }
            $keeper = $displayRows[$matchedKeeper];
            $keeperTags = json_decode((string) ($keeper['horror_tags'] ?? '[]'), true);
            $rowTags = json_decode((string) ($row['horror_tags'] ?? '[]'), true);
            $displayRows[$matchedKeeper]['horror_tags'] = json_encode(array_values(array_unique(array_merge(
                is_array($keeperTags) ? $keeperTags : [],
                is_array($rowTags) ? $rowTags : [],
            ))), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if (!filter_var($keeper['artwork_url'] ?? null, FILTER_VALIDATE_URL)
                && filter_var($row['artwork_url'] ?? null, FILTER_VALIDATE_URL)
            ) {
                $displayRows[$matchedKeeper]['artwork_url'] = $row['artwork_url'];
            }
            if (
                (!is_string($keeper['trailer_url'] ?? null) || $keeper['trailer_url'] === '')
                && is_string($row['trailer_url'] ?? null)
                && preg_match('#^https://www\.youtube\.com/watch\?v=[A-Za-z0-9_-]{11}$#', $row['trailer_url'])
            ) {
                $displayRows[$matchedKeeper]['trailer_url'] = $row['trailer_url'];
            }
            foreach (['creator', 'synopsis'] as $field) {
                if (trim((string) ($keeper[$field] ?? '')) === '' && trim((string) ($row[$field] ?? '')) !== '') {
                    $displayRows[$matchedKeeper][$field] = $row[$field];
                }
            }
            $displayRows[$matchedKeeper]['reception'] = $mergeReception(
                $displayRows[$matchedKeeper]['reception'],
                $row['reception'],
            );
            $suppressedShowIndexes[$index] = true;
        }
    }
    if ($suppressedShowIndexes !== []) {
        $displayRows = array_values(array_filter(
            $displayRows,
            static fn (int $index): bool => !isset($suppressedShowIndexes[$index]),
            ARRAY_FILTER_USE_KEY,
        ));
    }
    $preferredBookKeys = [];
    foreach ($displayRows as $row) {
        if (!in_array($row['media_type'], ['book', 'comic'], true) || $row['source_name'] === 'Google Books') {
            continue;
        }
        $normalizedTitle = mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '', (string) $row['title']) ?? '');
        $preferredBookKeys[$normalizedTitle . '|' . substr((string) $row['release_date'], 0, 4)] = true;
    }
    $displayRows = array_values(array_filter(
        $displayRows,
        static function (array $row) use ($preferredBookKeys): bool {
            if ($row['media_type'] !== 'book' || $row['source_name'] !== 'Google Books') {
                return true;
            }
            $normalizedTitle = mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '', (string) $row['title']) ?? '');
            return !isset($preferredBookKeys[$normalizedTitle . '|' . substr((string) $row['release_date'], 0, 4)]);
        },
    ));
    if ($ids !== null) $displayRows = $rows;
    $releases = [];
    foreach ($displayRows as $row) {
        $tags = json_decode((string) ($row['horror_tags'] ?? '[]'), true);
        $tags = is_array($tags) ? array_values($tags) : [];
        if ($row['media_type'] === 'video_game' && ghastly_platform_is_vr($row['platform'] ?? null)) {
            $tags[] = 'VR';
        }
        $release = [
            'id' => 'release-' . $row['id'],
            'title' => $row['title'],
            'creator' => $row['creator'],
            'mediaType' => $row['media_type'],
            'date' => (string) ($row['release_date'] ?? ''),
            'datePrecision' => $row['date_precision'],
            'publisher' => $row['publisher'],
            'tags' => array_values(array_unique($tags)),
            'source' => $row['source_url'],
            'sourceName' => $row['source_name'],
            'confidence' => $row['confidence'],
        ];
        foreach (['platform', 'region', 'synopsis'] as $optional) {
            if (is_string($row[$optional] ?? null) && $row[$optional] !== '') {
                $release[$optional] = $row[$optional];
            }
        }
        if ($row['media_type'] === 'video_game' && isset($steamIdsByRelease[(int) $row['id']])) {
            $release['steamUrl'] = 'https://store.steampowered.com/app/' . $steamIdsByRelease[(int) $row['id']] . '/';
        }
        if ($row['media_type'] === 'movie' && isset($watchByRelease[(int) $row['id']])) {
            $release['watchAvailability'] = $watchByRelease[(int) $row['id']];
        }
        if (filter_var($row['artwork_url'] ?? null, FILTER_VALIDATE_URL)) {
            $release['artwork'] = $row['artwork_url'];
        }
        if (is_string($row['trailer_url'] ?? null) && preg_match('#^https://www\.youtube\.com/watch\?v=[A-Za-z0-9_-]{11}$#', $row['trailer_url'])) {
            $release['trailer'] = $row['trailer_url'];
        }
        if (is_array($row['reception'] ?? null) && $row['reception'] !== []) {
            $release['reception'] = $row['reception'];
        }
        if (is_string($row['last_seen_at'] ?? null) && strtotime($row['last_seen_at'] . ' UTC') !== false) {
            $release['verifiedAt'] = gmdate(DATE_ATOM, strtotime($row['last_seen_at'] . ' UTC'));
        }
        $releases[] = $release;
    }
    $searchLimit = 200;
    $truncated = $search !== '' && ($searchWasCapped || count($releases) > $searchLimit);
    if ($truncated) {
        $releases = array_slice($releases, 0, $searchLimit);
    }
    $updatedAt = ghastly_db()->query('SELECT MAX(last_seen_at) FROM releases')->fetchColumn();
    echo json_encode([
        'releases' => $releases,
        'truncated' => $truncated,
        'updatedAt' => $updatedAt ? gmdate(DATE_ATOM, strtotime((string) $updatedAt . ' UTC')) : gmdate(DATE_ATOM),
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    error_log('Ghastly Guide releases API: ' . $error->getMessage());
    header('Cache-Control: no-store');
    http_response_code(500);
    echo json_encode(['error' => 'Release data is temporarily unavailable.']);
}
