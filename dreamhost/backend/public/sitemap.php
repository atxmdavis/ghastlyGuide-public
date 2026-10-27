<?php

declare(strict_types=1);

header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=3600');

function ghastly_sitemap_xml(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

$urls = [];
$addUrl = static function (string $location, ?string $lastModified = null) use (&$urls): void {
    $urls[$location] = $lastModified;
};

$addUrl('https://ghastlyguide.com/');
foreach (['movies', 'shows', 'games', 'tabletop', 'comics', 'books', 'sources', 'about', 'contact'] as $path) {
    $addUrl('https://ghastlyguide.com/' . $path);
}

try {
    require dirname(__DIR__) . '/private/src/seo.php';
    require dirname(__DIR__) . '/private/src/bootstrap.php';
    $statement = ghastly_db()->query(
        ghastly_seo_select() . ' WHERE active = 1 AND release_date IS NOT NULL ORDER BY release_date, title, id'
    );
    $rows = ghastly_seo_work_dedupe(ghastly_seo_dedupe($statement->fetchAll()));
    foreach ($rows as $row) {
        if (preg_match('/^(20\d{2})-(0[1-9]|1[0-2])-/', (string) $row['release_date'], $match)) {
            $addUrl('https://ghastlyguide.com/calendar/' . $match[1] . '/' . $match[2]);
        }
        $lastModified = preg_match('/^\d{4}-\d{2}-\d{2}/', (string) ($row['last_seen_at'] ?? ''), $match)
            ? $match[0]
            : null;
        $addUrl(ghastly_release_url($row), $lastModified);
    }
} catch (Throwable $error) {
    error_log('Ghastly Guide sitemap: ' . $error->getMessage());
}

echo '<?xml version="1.0" encoding="UTF-8"?>';
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
foreach ($urls as $location => $lastModified) {
    echo '<url><loc>' . ghastly_sitemap_xml($location) . '</loc>';
    if ($lastModified !== null) {
        echo '<lastmod>' . ghastly_sitemap_xml($lastModified) . '</lastmod>';
    }
    echo '</url>';
}
echo '</urlset>';
