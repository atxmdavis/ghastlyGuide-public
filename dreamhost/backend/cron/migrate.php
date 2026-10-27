<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/src/bootstrap.php';

$database = ghastly_db();
// Older installations predate the versioned migrations. Repair this once, in the CLI only.
if ($database->query("SHOW COLUMNS FROM releases LIKE 'trailer_url'")->fetch() === false) {
    $database->exec('ALTER TABLE releases ADD COLUMN trailer_url VARCHAR(1000) NULL');
}
$database->exec(
    "CREATE TABLE IF NOT EXISTS schema_migrations (
        migration VARCHAR(160) NOT NULL,
        applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (migration)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$applied = $database->query('SELECT migration FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
$known = array_fill_keys(array_map('strval', $applied), true);
$files = glob(dirname(__DIR__) . '/migrations/*.sql') ?: [];
sort($files, SORT_STRING);

foreach ($files as $file) {
    $migration = basename($file);
    if (isset($known[$migration])) {
        continue;
    }
    $sql = file_get_contents($file);
    if (!is_string($sql) || trim($sql) === '') {
        throw new RuntimeException("Migration {$migration} is empty.");
    }
    $statements = array_values(array_filter(array_map('trim', preg_split('/;\s*(?:\R|$)/', $sql) ?: [])));
    foreach ($statements as $statement) {
        $database->exec($statement);
    }
    $record = $database->prepare('INSERT INTO schema_migrations (migration) VALUES (:migration)');
    $record->execute(['migration' => $migration]);
    printf("applied %s\n", $migration);
}

echo "schema current\n";
