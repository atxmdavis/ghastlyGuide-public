<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    require dirname(__DIR__, 2) . '/private/src/bootstrap.php';
    $database = ghastly_db();
    $activeCount = (int) $database->query('SELECT COUNT(*) FROM releases WHERE active = 1')->fetchColumn();
    $statement = $database->query(
        'SELECT runs.source_slug, runs.started_at, runs.completed_at, runs.status, runs.found_count
         FROM source_runs runs
         INNER JOIN (SELECT source_slug, MAX(id) AS id FROM source_runs GROUP BY source_slug) latest ON latest.id = runs.id
         ORDER BY runs.source_slug'
    );
    echo json_encode(['activeReleaseCount' => $activeCount, 'sources' => $statement->fetchAll()], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    error_log('Ghastly Guide ingest status: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Ingest status is temporarily unavailable.']);
}
