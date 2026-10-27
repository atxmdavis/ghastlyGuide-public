<?php

declare(strict_types=1);

function ghastly_seo_escape(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function ghastly_seo_slug(string $value): string
{
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    $slug = strtolower((string) ($ascii === false ? $value : $ascii));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
    return trim(substr($slug, 0, 90), '-') ?: 'release';
}

function ghastly_release_url(array $release): string
{
    return sprintf(
        'https://ghastlyguide.com/releases/%d/%s',
        (int) ($release['id'] ?? 0),
        ghastly_seo_slug((string) ($release['title'] ?? 'release')),
    );
}

function ghastly_seo_work_key(array $release): string
{
    $title = mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '', (string) ($release['title'] ?? '')) ?? '');
    $source = mb_strtolower(rtrim(trim((string) ($release['source_url'] ?? '')), '/'));
    if ($title === '' || $source === '') {
        return 'release|' . (int) ($release['id'] ?? 0);
    }
    return (string) ($release['media_type'] ?? '') . '|' . $title . '|' . $source;
}

function ghastly_seo_work_dedupe(array $rows): array
{
    $deduped = [];
    foreach ($rows as $row) {
        $key = ghastly_seo_work_key($row);
        if (!isset($deduped[$key]) || (int) ($row['id'] ?? 0) < (int) ($deduped[$key]['id'] ?? 0)) {
            $deduped[$key] = $row;
        }
    }
    return array_values($deduped);
}

function ghastly_seo_media_label(string $mediaType): string
{
    return match ($mediaType) {
        'movie' => 'Movie',
        'show' => 'Show',
        'video_game' => 'Video game',
        'tabletop_game' => 'Tabletop game',
        'comic' => 'Comic',
        'book' => 'Book',
        default => 'Release',
    };
}

function ghastly_seo_schema_type(string $mediaType): string
{
    return match ($mediaType) {
        'movie' => 'Movie',
        'show' => 'TVSeries',
        'video_game' => 'VideoGame',
        'book' => 'Book',
        'comic' => 'ComicStory',
        default => 'CreativeWork',
    };
}

function ghastly_seo_date(array $release): string
{
    $date = (string) ($release['release_date'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return 'Date to be announced';
    }
    $timestamp = strtotime($date . ' 12:00:00 UTC');
    if ($timestamp === false) {
        return $date;
    }
    return match ((string) ($release['date_precision'] ?? 'day')) {
        'year' => gmdate('Y', $timestamp),
        'month' => gmdate('F Y', $timestamp),
        default => gmdate('F j, Y', $timestamp),
    };
}

function ghastly_seo_select(): string
{
    return 'SELECT id, title, creator, media_type, release_date, date_precision, publisher, platform, region, horror_tags, synopsis, source_url, source_name, confidence, artwork_url, trailer_url, first_seen_at, last_seen_at FROM releases';
}

function ghastly_seo_dedupe(array $rows): array
{
    $deduped = [];
    foreach ($rows as $row) {
        $title = mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '', (string) ($row['title'] ?? '')) ?? '');
        $date = (string) ($row['release_date'] ?? '');
        $source = (string) ($row['source_url'] ?? '');
        $key = match ((string) ($row['media_type'] ?? '')) {
            'movie' => 'movie|' . $source . '|' . $date,
            'video_game', 'show', 'book' => (string) $row['media_type'] . '|' . $title . '|' . $date,
            default => (string) $row['media_type'] . '|' . ($source ?: $title) . '|' . $date,
        };
        if (!isset($deduped[$key])) {
            $deduped[$key] = $row;
        }
    }
    return array_values($deduped);
}

function ghastly_seo_rows(PDO $database, array $route, int $limit = 40): array
{
    $conditions = ['active = 1', 'release_date IS NOT NULL'];
    $parameters = [];
    if (($route['kind'] ?? '') === 'month') {
        $conditions[] = 'release_date BETWEEN :from_date AND :to_date';
        $parameters = ['from_date' => $route['from'], 'to_date' => $route['to']];
    } else {
        $today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
        $conditions[] = 'release_date BETWEEN :from_date AND :to_date';
        $parameters = [
            'from_date' => $today->modify('first day of this month')->format('Y-m-d'),
            'to_date' => $today->modify('+12 months')->modify('last day of this month')->format('Y-m-d'),
        ];
    }
    if (isset($route['mediaTypes'])) {
        $placeholders = [];
        foreach ($route['mediaTypes'] as $index => $mediaType) {
            $name = 'media_' . $index;
            $placeholders[] = ':' . $name;
            $parameters[$name] = $mediaType;
        }
        $conditions[] = 'media_type IN (' . implode(', ', $placeholders) . ')';
    }
    $statement = $database->prepare(
        ghastly_seo_select() . ' WHERE ' . implode(' AND ', $conditions)
        . ' ORDER BY release_date, title, id LIMIT ' . max(1, min(200, $limit * 3)),
    );
    $statement->execute($parameters);
    return array_slice(ghastly_seo_dedupe($statement->fetchAll()), 0, $limit);
}

function ghastly_seo_route(string $requestPath): array
{
    $path = '/' . trim((string) parse_url($requestPath, PHP_URL_PATH), '/');
    $path = $path === '/' ? '/' : rtrim($path, '/');
    $categories = [
        '/movies' => ['title' => 'Upcoming Horror Movies', 'heading' => 'Upcoming horror movies', 'description' => 'Verified upcoming horror movie release dates for theaters, streaming, digital, and physical formats.', 'mediaTypes' => ['movie']],
        '/shows' => ['title' => 'Upcoming Horror Shows', 'heading' => 'Upcoming horror shows', 'description' => 'Verified upcoming horror television and streaming series release dates.', 'mediaTypes' => ['show']],
        '/games' => ['title' => 'Upcoming Horror Video Games', 'heading' => 'Upcoming horror video games', 'description' => 'Verified upcoming horror video game release dates across PC, console, and VR.', 'mediaTypes' => ['video_game']],
        '/tabletop' => ['title' => 'Upcoming Horror Tabletop Games', 'heading' => 'Upcoming horror tabletop games', 'description' => 'Verified upcoming horror tabletop game release dates.', 'mediaTypes' => ['tabletop_game']],
        '/comics' => ['title' => 'Upcoming Horror Comics', 'heading' => 'Upcoming horror comics', 'description' => 'Verified upcoming horror comic release dates from publishers and distributors.', 'mediaTypes' => ['comic']],
        '/books' => ['title' => 'Upcoming Horror Books', 'heading' => 'Upcoming horror books', 'description' => 'Verified upcoming horror book release dates from publishers and licensed catalogs.', 'mediaTypes' => ['book']],
    ];
    if (isset($categories[$path])) {
        return array_merge(['kind' => 'category', 'path' => $path, 'canonical' => 'https://ghastlyguide.com' . $path], $categories[$path]);
    }
    if (preg_match('#^/calendar/(20\d{2})/(0[1-9]|1[0-2])$#', $path, $match)) {
        $from = sprintf('%s-%s-01', $match[1], $match[2]);
        $date = new DateTimeImmutable($from, new DateTimeZone('UTC'));
        $label = $date->format('F Y');
        return [
            'kind' => 'month', 'path' => $path, 'canonical' => 'https://ghastlyguide.com' . $path,
            'title' => $label . ' Horror Releases', 'heading' => $label . ' horror releases',
            'description' => 'Verified horror movie, show, game, comic, book, and tabletop release dates for ' . $label . '.',
            'from' => $from, 'to' => $date->modify('last day of this month')->format('Y-m-d'),
        ];
    }
    if (preg_match('#^/releases/(\d+)(?:/[^/]*)?$#', $path, $match)) {
        return ['kind' => 'release', 'path' => $path, 'id' => (int) $match[1]];
    }
    $static = [
        '/sources' => ['title' => 'Sources and Editorial Standards', 'description' => 'How Ghastly Guide verifies horror release dates and ranks official, licensed, database, and editorial sources.'],
        '/about' => ['title' => 'About Ghastly Guide', 'description' => 'Learn why Ghastly Guide brings verified horror releases across movies, shows, games, comics, books, and tabletop into one calendar.'],
        '/contact' => ['title' => 'Contact Ghastly Guide', 'description' => 'Suggest a horror release, report a correction, or contact Ghastly Guide.'],
    ];
    if (isset($static[$path])) {
        return array_merge(['kind' => 'static', 'path' => $path, 'canonical' => 'https://ghastlyguide.com' . $path], $static[$path]);
    }
    if ($path !== '/') {
        return ['kind' => 'not_found', 'path' => $path, 'title' => 'Page Not Found', 'description' => 'That Ghastly Guide page could not be found.'];
    }
    return [
        'kind' => 'home', 'path' => '/', 'canonical' => 'https://ghastlyguide.com/',
        'title' => 'Ghastly Guide — Horror Media Release Calendar', 'heading' => 'New and upcoming horror releases',
        'description' => 'A sourced calendar of new horror movies, shows, video games, tabletop games, comics, and books.',
    ];
}

function ghastly_seo_release(PDO $database, int $id): ?array
{
    $statement = $database->prepare(ghastly_seo_select() . ' WHERE id = :id AND active = 1 LIMIT 1');
    $statement->execute(['id' => $id]);
    $release = $statement->fetch();
    if (!is_array($release)) {
        return null;
    }
    if ($release['media_type'] !== 'video_game' || $release['confidence'] !== 'official') {
        return $release;
    }
    $releaseDate = new DateTimeImmutable((string) $release['release_date'], new DateTimeZone('UTC'));
    $supplementStatement = $database->prepare(
        ghastly_seo_select() . " WHERE active = 1 AND media_type = 'video_game' AND source_name = 'IGDB'
            AND title = :title AND release_date BETWEEN :from_date AND :to_date ORDER BY release_date, id"
    );
    $supplementStatement->execute([
        'title' => $release['title'],
        'from_date' => $releaseDate->modify('-7 days')->format('Y-m-d'),
        'to_date' => $releaseDate->modify('+7 days')->format('Y-m-d'),
    ]);
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
    foreach ($supplementStatement->fetchAll() as $supplement) {
        foreach (['platform', 'region', 'publisher'] as $field) {
            $release[$field] = $mergeCommaValues($release[$field] ?? '', $supplement[$field] ?? '');
        }
        if (!filter_var($release['artwork_url'] ?? null, FILTER_VALIDATE_URL)
            && filter_var($supplement['artwork_url'] ?? null, FILTER_VALIDATE_URL)
        ) {
            $release['artwork_url'] = $supplement['artwork_url'];
        }
        if (trim((string) ($release['trailer_url'] ?? '')) === '' && trim((string) ($supplement['trailer_url'] ?? '')) !== '') {
            $release['trailer_url'] = $supplement['trailer_url'];
        }
    }
    return $release;
}

function ghastly_seo_canonical_release(PDO $database, array $release): array
{
    $title = trim((string) ($release['title'] ?? ''));
    $mediaType = trim((string) ($release['media_type'] ?? ''));
    if ($title === '' || trim((string) ($release['source_url'] ?? '')) === '' || $mediaType === '') {
        return $release;
    }
    $statement = $database->prepare(
        ghastly_seo_select()
        . ' WHERE active = 1 AND media_type = :media_type AND title = :title ORDER BY id'
    );
    $statement->execute([
        'media_type' => $mediaType,
        'title' => $title,
    ]);
    $workKey = ghastly_seo_work_key($release);
    foreach ($statement->fetchAll() as $candidate) {
        if (is_array($candidate) && ghastly_seo_work_key($candidate) === $workKey) {
            return $candidate;
        }
    }
    return $release;
}

function ghastly_seo_listing_schema(array $route, array $rows): array
{
    $canonical = (string) $route['canonical'];
    return [
        '@context' => 'https://schema.org',
        '@graph' => [
            ['@type' => 'Organization', '@id' => 'https://ghastlyguide.com/#organization', 'name' => 'Ghastly Guide', 'url' => 'https://ghastlyguide.com/'],
            ['@type' => 'WebSite', '@id' => 'https://ghastlyguide.com/#website', 'name' => 'Ghastly Guide', 'url' => 'https://ghastlyguide.com/', 'publisher' => ['@id' => 'https://ghastlyguide.com/#organization']],
            [
                '@type' => 'CollectionPage', '@id' => $canonical . '#page', 'url' => $canonical,
                'name' => $route['heading'], 'description' => $route['description'],
                'isPartOf' => ['@id' => 'https://ghastlyguide.com/#website'],
                'mainEntity' => [
                    '@type' => 'ItemList',
                    'itemListElement' => array_map(static fn (array $row, int $index): array => [
                        '@type' => 'ListItem', 'position' => $index + 1,
                        'url' => ghastly_release_url($row), 'name' => $row['title'],
                    ], $rows, array_keys($rows)),
                ],
            ],
        ],
    ];
}

function ghastly_seo_static_schema(array $route): array
{
    return [
        '@context' => 'https://schema.org',
        '@graph' => [
            ['@type' => 'Organization', '@id' => 'https://ghastlyguide.com/#organization', 'name' => 'Ghastly Guide', 'url' => 'https://ghastlyguide.com/'],
            ['@type' => 'WebSite', '@id' => 'https://ghastlyguide.com/#website', 'name' => 'Ghastly Guide', 'url' => 'https://ghastlyguide.com/', 'publisher' => ['@id' => 'https://ghastlyguide.com/#organization']],
            [
                '@type' => ($route['path'] ?? '') === '/about' ? 'AboutPage' : 'WebPage',
                '@id' => $route['canonical'] . '#page', 'url' => $route['canonical'],
                'name' => $route['title'], 'description' => $route['description'],
                'isPartOf' => ['@id' => 'https://ghastlyguide.com/#website'],
            ],
        ],
    ];
}

function ghastly_seo_release_schema(array $release, ?string $canonical = null): array
{
    $url = $canonical ?? ghastly_release_url($release);
    $entity = [
        '@type' => ghastly_seo_schema_type((string) $release['media_type']),
        '@id' => $url . '#release', 'url' => $url, 'name' => $release['title'],
    ];
    if (ghastly_seo_source_is_linkable($release)) {
        $entity['sameAs'] = $release['source_url'];
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($release['release_date'] ?? ''))) {
        $entity['datePublished'] = match ((string) ($release['date_precision'] ?? 'day')) {
            'year' => substr((string) $release['release_date'], 0, 4),
            'month' => substr((string) $release['release_date'], 0, 7),
            default => $release['release_date'],
        };
    }
    foreach (['synopsis' => 'description', 'artwork_url' => 'image'] as $source => $target) {
        if (is_string($release[$source] ?? null) && trim($release[$source]) !== '') {
            $entity[$target] = $release[$source];
        }
    }
    $credit = trim((string) ($release['creator'] ?: $release['publisher']));
    if ($credit !== '') {
        $entity['creator'] = ['@type' => 'Organization', 'name' => $credit];
    }
    if ((string) $release['media_type'] === 'video_game' && trim((string) ($release['platform'] ?? '')) !== '') {
        $entity['gamePlatform'] = array_map('trim', explode(',', (string) $release['platform']));
    }
    return [
        '@context' => 'https://schema.org',
        '@graph' => [
            ['@type' => 'Organization', '@id' => 'https://ghastlyguide.com/#organization', 'name' => 'Ghastly Guide', 'url' => 'https://ghastlyguide.com/'],
            $entity,
            ['@type' => 'BreadcrumbList', 'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'Calendar', 'item' => 'https://ghastlyguide.com/'],
                ['@type' => 'ListItem', 'position' => 2, 'name' => $release['title'], 'item' => $url],
            ]],
        ],
    ];
}

function ghastly_seo_nav(): string
{
    return '<nav class="seo-fallback__nav" aria-label="Browse horror releases">'
        . '<a href="/">Calendar</a><a href="/movies">Movies</a><a href="/shows">Shows</a><a href="/games">Games</a>'
        . '<a href="/tabletop">Tabletop</a><a href="/comics">Comics</a><a href="/books">Books</a><a href="/sources">Sources</a></nav>';
}

function ghastly_seo_source_is_linkable(array $release): bool
{
    return strcasecmp(trim((string) ($release['source_name'] ?? '')), 'Lunar Distribution') !== 0;
}

function ghastly_seo_render_listing(array $route, array $rows): string
{
    $cards = '';
    foreach ($rows as $release) {
        $url = ghastly_release_url($release);
        $image = filter_var($release['artwork_url'] ?? null, FILTER_VALIDATE_URL)
            ? '<img src="' . ghastly_seo_escape($release['artwork_url']) . '" alt="" width="180" height="260" loading="lazy">'
            : '';
        $summary = trim((string) ($release['synopsis'] ?? ''));
        if (mb_strlen($summary) > 240) {
            $summary = mb_substr($summary, 0, 237) . '…';
        }
        $sourceLabel = ghastly_seo_escape(ucfirst((string) $release['confidence']) . ' source · ' . $release['source_name']);
        $source = ghastly_seo_source_is_linkable($release)
            ? '<a href="' . ghastly_seo_escape($release['source_url']) . '" rel="noreferrer">' . $sourceLabel . '</a>'
            : '<span>' . $sourceLabel . '</span>';
        $cards .= '<article class="seo-release-card">' . $image . '<div><p>'
            . ghastly_seo_escape(ghastly_seo_date($release)) . ' · '
            . ghastly_seo_escape(ghastly_seo_media_label((string) $release['media_type']))
            . '</p><h2><a href="' . ghastly_seo_escape($url) . '">' . ghastly_seo_escape($release['title']) . '</a></h2>'
            . ($summary !== '' ? '<p>' . ghastly_seo_escape($summary) . '</p>' : '')
            . '<p>' . $source . '</p></div></article>';
    }
    if ($cards === '') {
        $cards = '<p>No verified releases are listed for this view yet.</p>';
    }
    $monthNavigation = '';
    if (($route['kind'] ?? '') === 'month') {
        $date = new DateTimeImmutable((string) $route['from'], new DateTimeZone('UTC'));
        $previous = $date->modify('-1 month');
        $next = $date->modify('+1 month');
        $monthNavigation = '<nav class="seo-fallback__months" aria-label="Calendar months"><a href="/calendar/'
            . $previous->format('Y/m') . '">← ' . $previous->format('F Y') . '</a><a href="/calendar/'
            . $next->format('Y/m') . '">' . $next->format('F Y') . ' →</a></nav>';
    }
    return '<main id="main-content" class="seo-fallback"><header><a href="/" aria-label="Ghastly Guide home"><img src="/assets/ghastly-guide-logo-small.webp" width="1169" height="1345" alt="Ghastly Guide"></a>'
        . ghastly_seo_nav() . '</header><section><p class="eyebrow">Verified horror release dates</p><h1>'
        . ghastly_seo_escape($route['heading']) . '</h1><p class="seo-fallback__intro">'
        . ghastly_seo_escape($route['description']) . '</p>' . $monthNavigation
        . '<div class="seo-release-list">' . $cards . '</div>' . $monthNavigation . '</section></main>';
}

function ghastly_seo_render_release(array $release): string
{
    $image = filter_var($release['artwork_url'] ?? null, FILTER_VALIDATE_URL)
        ? '<img class="seo-release-detail__art" src="' . ghastly_seo_escape($release['artwork_url']) . '" alt="' . ghastly_seo_escape($release['title']) . ' cover art" width="360" height="520">'
        : '';
    $trailer = filter_var($release['trailer_url'] ?? null, FILTER_VALIDATE_URL)
        ? '<a class="button button--primary" href="' . ghastly_seo_escape($release['trailer_url']) . '" rel="noreferrer">Play trailer</a>'
        : '';
    $back = '<a class="release-detail__back" href="/#full-calendar" aria-label="Back to guide"><svg viewBox="0 0 24 24" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="1.75" d="M15 5.5 8 12l7 6.5" /></svg></a>';
    $media = $image !== '' || $trailer !== ''
        ? '<div class="seo-release-detail__media">' . $back . $image . $trailer . '</div>'
        : $back;
    $detailClass = $media === '' ? 'seo-release-detail seo-release-detail--no-media' : 'seo-release-detail';
    $facts = [
        'Release date' => ghastly_seo_date($release),
        'Medium' => ghastly_seo_media_label((string) $release['media_type']),
        'Creator' => (string) ($release['creator'] ?? ''),
        'Publisher' => (string) ($release['publisher'] ?? ''),
        'Platform or format' => (string) ($release['platform'] ?? ''),
        'Region' => (string) ($release['region'] ?? ''),
        'Date source' => (string) $release['source_name'],
        'Last verified' => isset($release['last_seen_at']) ? gmdate('F j, Y', strtotime((string) $release['last_seen_at'] . ' UTC')) : '',
    ];
    $definition = '';
    foreach ($facts as $label => $value) {
        if (trim($value) !== '') {
            $definition .= '<div><dt>' . ghastly_seo_escape($label) . '</dt><dd>' . ghastly_seo_escape($value) . '</dd></div>';
        }
    }
    $sourceAction = ghastly_seo_source_is_linkable($release)
        ? '<p><a class="button button--primary" href="' . ghastly_seo_escape($release['source_url']) . '" rel="noreferrer">Check '
            . ghastly_seo_escape($release['source_name']) . ' source</a></p>'
        : '';
    return '<main id="main-content" class="seo-fallback"><header><a href="/" aria-label="Ghastly Guide home"><img src="/assets/ghastly-guide-logo-small.webp" width="1169" height="1345" alt="Ghastly Guide"></a>'
        . ghastly_seo_nav() . '</header><article class="' . $detailClass . '">' . $media . '<div><p class="eyebrow">'
        . ghastly_seo_escape(ghastly_seo_media_label((string) $release['media_type'])) . '</p><h1>' . ghastly_seo_escape($release['title']) . '</h1>'
        . (trim((string) ($release['synopsis'] ?? '')) !== '' ? '<p>' . ghastly_seo_escape($release['synopsis']) . '</p>' : '')
        . '<dl>' . $definition . '</dl>'
        . $sourceAction . '<p><a href="/">← Back to the calendar</a></p></div></article></main>';
}

function ghastly_seo_render_static(array $route): string
{
    $content = match ((string) ($route['path'] ?? '')) {
        '/about' => '<p>Ghastly Guide brings verified horror releases across movies, shows, games, comics, books, and tabletop into one calendar.</p>'
            . '<h2>Evidence before rumor</h2><p>Every listed date keeps its public source, confidence level, region, and date precision. A month-only announcement remains a month instead of becoming a guessed day.</p>'
            . '<h2>Corrections</h2><p>If a date changes or a release is missing, <a href="/contact">send the title and a public evidence link</a>. Stronger official evidence replaces weaker records.</p>',
        '/sources' => '<p>Something appears on Ghastly Guide only when its date can be traced to public evidence.</p>'
            . '<h2>Source confidence</h2><ol><li><strong>Official:</strong> the studio, network, publisher, creator, platform, or distributor that sets the date.</li><li><strong>Partner:</strong> a licensed catalog such as TMDB or IGDB.</li><li><strong>Database:</strong> reference metadata used to discover and fill gaps.</li><li><strong>Editorial:</strong> a human-reviewed date with a public evidence URL.</li></ol>'
            . '<p>When sources disagree, stronger evidence wins. Release pages identify the source, confidence, region, date precision, and last verification date.</p>',
        '/contact' => '<p>Suggest a missing horror release or report a changed date. Include the title, medium, and a public studio, publisher, platform, distributor, or licensed-catalog page that can be checked.</p><p>The interactive contact form is available when JavaScript loads.</p><p><a href="/sources">Read the sourcing standards</a>.</p>',
        default => '<p>Browse verified horror release dates and the public evidence behind them.</p>',
    };
    return '<main id="main-content" class="seo-fallback"><header><a href="/" aria-label="Ghastly Guide home"><img src="/assets/ghastly-guide-logo-small.webp" width="1169" height="1345" alt="Ghastly Guide"></a>'
        . ghastly_seo_nav() . '</header><section class="seo-fallback__static"><p class="eyebrow">Ghastly Guide</p><h1>'
        . ghastly_seo_escape($route['title']) . '</h1>' . $content . '</section></main>';
}
