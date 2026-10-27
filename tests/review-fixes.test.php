<?php

declare(strict_types=1);
require dirname(__DIR__) . '/dreamhost/backend/src/contact.php';
require dirname(__DIR__) . '/dreamhost/backend/src/saved-transfer.php';
require dirname(__DIR__) . '/dreamhost/backend/src/rate-limit.php';

function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$message = ['name' => 'Test visitor', 'email' => 'test@example.com', 'message' => 'A legitimate message about a release.'];
foreach ([0, (int) (microtime(true) * 1000), 1, PHP_INT_MAX] as $startedAt) {
    check(ghastly_contact_validation($message + ['startedAt' => $startedAt]) === null, 'Device clock and form age must not discard valid messages.');
}
check(ghastly_contact_validation(array_replace($message, ['email' => "test@example.com\r\nBcc: victim@example.com"])) !== null, 'Header injection must be rejected.');
check(ghastly_contact_validation(array_replace($message, ['message' => []])) !== null, 'Malformed messages must be rejected.');

$release = ['id' => 'release-1', 'title' => 'Test title', 'date' => '2026-10-02', 'datePrecision' => 'day', 'mediaType' => 'book', 'confidence' => 'official', 'source' => 'https://example.com/release', 'verifiedAt' => '2026-10-02T00:00:00Z'];
check(ghastly_saved_transfer_normalize(['release-1' => $release])['release-1']['verifiedAt'] === $release['verifiedAt'], 'Copying saves must retain verification metadata.');
check(ghastly_saved_transfer_normalize(['release-1' => array_replace($release, ['date' => '', 'datePrecision' => 'tbd'])]) !== null, 'Undated saves must be transferable.');
check(ghastly_saved_transfer_normalize(['release-1' => array_replace($release, ['date' => '2026-02-30'])]) === null, 'Invalid dates must be rejected.');
check(ghastly_saved_transfer_normalize(['release-1' => array_replace($release, ['source' => 'javascript:alert(1)'])]) === null, 'Unsafe source links must be rejected.');

// Execute the same atomic claim statements against a real local database.
// SQLite supplies the MySQL clock function; no production configuration is accessed.
$db = class_exists(Pdo\Sqlite::class) ? new Pdo\Sqlite('sqlite::memory:') : new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
if (method_exists($db, 'createFunction')) $db->createFunction('UTC_TIMESTAMP', static fn (): string => '2026-10-02 00:00:00');
else $db->sqliteCreateFunction('UTC_TIMESTAMP', static fn (): string => '2026-10-02 00:00:00');
$db->exec('CREATE TABLE saved_transfers (token_hash TEXT PRIMARY KEY, payload_json TEXT, expires_at TEXT, claimed_at TEXT)');
$insert = $db->prepare('INSERT INTO saved_transfers VALUES (?, ?, ?, NULL)');
$insert->execute(['valid', json_encode(['release-1' => $release]), '2026-10-02 00:10:00']);
$insert->execute(['expired', json_encode(['release-1' => $release]), '2026-10-01 23:59:00']);
check(ghastly_claim_saved_transfer($db, 'valid') !== null, 'An unclaimed token must work.');
check(ghastly_claim_saved_transfer($db, 'valid') === null, 'A claimed token must never work a second time.');
check(ghastly_claim_saved_transfer($db, 'expired') === null, 'Expired tokens must not work.');
check(ghastly_claim_saved_transfer($db, 'missing') === null, 'Missing tokens must not work.');

$directory = sys_get_temp_dir() . '/ghastly-rate-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
try {
    check(ghastly_rate_limit('test', 'client-a', 2, 60, $directory, 100), 'First request should pass.');
    check(ghastly_rate_limit('test', 'client-a', 2, 60, $directory, 101), 'Second request should pass.');
    check(!ghastly_rate_limit('test', 'client-a', 2, 60, $directory, 102), 'Excess requests must be limited.');
    check(ghastly_rate_limit('test', 'client-b', 2, 60, $directory, 102), 'Different clients need separate limits.');
    check(ghastly_rate_limit('test', 'client-a', 2, 60, $directory, 161), 'Expired limits must clear.');
} finally {
    foreach (glob($directory . '/*') ?: [] as $file) unlink($file);
    rmdir($directory);
}
echo "Contact, transfer, and rate-limit behavior checks passed.\n";
