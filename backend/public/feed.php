<?php

declare(strict_types=1);

header('Content-Type: application/rss+xml; charset=utf-8');
header('Cache-Control: public, max-age=900');

function ghastly_xml(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

try {
    require dirname(__DIR__) . '/private/src/seo.php';
    require dirname(__DIR__) . '/private/src/bootstrap.php';
    $statement = ghastly_db()->query(
        ghastly_seo_select() . " WHERE active = 1 AND release_date >= UTC_DATE() ORDER BY release_date, title, id LIMIT 300"
    );
    $rows = array_slice(ghastly_seo_dedupe($statement->fetchAll()), 0, 100);
    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<rss version="2.0"><channel><title>Ghastly Guide releases</title><link>https://ghastlyguide.com/</link><description>Verified upcoming horror releases with their evidence sources.</description><language>en-us</language>';
    foreach ($rows as $row) {
        $url = ghastly_release_url($row);
        $credit = trim((string) ($row['publisher'] ?: $row['creator']));
        $description = implode(' · ', array_filter([
            ghastly_seo_media_label((string) $row['media_type']),
            $credit,
            ucfirst((string) $row['confidence']) . ' source: ' . $row['source_name'],
            ghastly_seo_source_is_linkable($row) ? 'Evidence: ' . $row['source_url'] : '',
        ]));
        echo '<item><title>' . ghastly_xml($row['title']) . '</title><link>' . ghastly_xml($url) . '</link><guid isPermaLink="true">' . ghastly_xml($url) . '</guid><pubDate>' . gmdate(DATE_RSS, strtotime($row['release_date'] . ' 12:00:00 UTC')) . '</pubDate><description>' . ghastly_xml($description) . '</description></item>';
    }
    echo '</channel></rss>';
} catch (Throwable $error) {
    error_log('Ghastly Guide feed: ' . $error->getMessage());
    http_response_code(500);
    echo '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><title>Ghastly Guide releases</title><link>https://ghastlyguide.com/</link><description>Feed temporarily unavailable.</description></channel></rss>';
}
