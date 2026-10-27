<?php

declare(strict_types=1);

require dirname(__DIR__) . '/dreamhost/backend/src/seo.php';

$rows = [
    [
        'id' => 33281,
        'title' => 'Keeping Company',
        'media_type' => 'movie',
        'release_date' => '2025-10-01',
        'source_url' => 'https://www.themoviedb.org/movie/1525909',
        'creator' => '',
        'publisher' => '',
    ],
    [
        'id' => 605,
        'title' => 'Keeping Company',
        'media_type' => 'movie',
        'release_date' => '2026-07-15',
        'source_url' => 'https://www.themoviedb.org/movie/1525909/',
        'creator' => '',
        'publisher' => '',
    ],
    [
        'id' => 700,
        'title' => 'Keeping Company',
        'media_type' => 'book',
        'release_date' => '2026-07-15',
        'source_url' => 'https://www.themoviedb.org/movie/1525909',
        'creator' => '',
        'publisher' => '',
    ],
];

$deduped = ghastly_seo_work_dedupe($rows);
if (count($deduped) !== 2 || (int) $deduped[0]['id'] !== 605 || (int) $deduped[1]['id'] !== 700) {
    fwrite(STDERR, "SEO work deduplication did not retain the oldest stable release URL.\n");
    exit(1);
}

$schema = ghastly_seo_release_schema($rows[0], 'https://ghastlyguide.com/releases/605/keeping-company');
$json = json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
if (!str_contains($json, 'https://ghastlyguide.com/releases/605/keeping-company')) {
    fwrite(STDERR, "Release schema did not use the canonical work URL.\n");
    exit(1);
}

$database = new PDO('sqlite::memory:');
$database->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$database->exec('CREATE TABLE releases (
    id INTEGER PRIMARY KEY,
    title TEXT NOT NULL,
    creator TEXT NOT NULL DEFAULT "",
    media_type TEXT NOT NULL,
    release_date TEXT,
    date_precision TEXT NOT NULL DEFAULT "day",
    publisher TEXT NOT NULL DEFAULT "",
    platform TEXT,
    region TEXT,
    horror_tags TEXT,
    synopsis TEXT,
    source_url TEXT NOT NULL,
    source_name TEXT NOT NULL DEFAULT "Source",
    confidence TEXT NOT NULL DEFAULT "partner",
    artwork_url TEXT,
    trailer_url TEXT,
    first_seen_at TEXT,
    last_seen_at TEXT,
    active INTEGER NOT NULL DEFAULT 1
)');
$insert = $database->prepare(
    'INSERT INTO releases (id, title, media_type, release_date, source_url) VALUES (:id, :title, :media_type, :release_date, :source_url)'
);
foreach ([$rows[1], $rows[0], $rows[2]] as $row) {
    $insert->execute([
        'id' => $row['id'],
        'title' => $row['title'],
        'media_type' => $row['media_type'],
        'release_date' => $row['release_date'],
        'source_url' => $row['source_url'],
    ]);
}
$canonical = ghastly_seo_canonical_release($database, $rows[0]);
if ((int) $canonical['id'] !== 605) {
    fwrite(STDERR, "Release detail canonicalization did not select the oldest matching work URL.\n");
    exit(1);
}

echo "SEO canonical tests passed.\n";
