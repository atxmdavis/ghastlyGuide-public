<?php

declare(strict_types=1);

require dirname(__DIR__) . '/dreamhost/backend/src/watch-providers.php';
function ghastly_text(mixed $value, int $limit = 255): string { return substr(trim(strip_tags((string) $value)), 0, $limit); }
function ghastly_config(): array { return ['tmdb_bearer_token' => 'fixture']; }
function ghastly_http_json(string $url, array $headers): array {
    if (str_contains($url, '/2/')) throw new RuntimeException('Fixture network failure');
    return ['results' => []];
}
function check(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }

$offers = ghastly_movie_watch_offers(['results' => [
    'CA' => ['flatrate' => [['provider_id' => 9, 'provider_name' => 'Canada only']]],
    'US' => [
        'flatrate' => [['provider_id' => 1, 'provider_name' => 'Shudder'], ['provider_id' => 1, 'provider_name' => 'Shudder']],
        'rent' => [['provider_id' => 2, 'provider_name' => 'Amazon Video']],
        'ads' => [['provider_id' => 3, 'provider_name' => 'Tubi']],
    ],
]]);
check($offers['flatrate'] === ['Shudder'], 'Subscription providers must be U.S.-only and deduplicated.');
check($offers['rent'] === ['Amazon Video'] && $offers['ads'] === ['Tubi'], 'Rental and ads must stay separate from subscriptions.');
check($offers['buy'] === [], 'Missing offer types must remain empty.');
check(ghastly_movie_watch_offers(['results' => []])['flatrate'] === [], 'An empty valid response must clear removed offers.');
foreach ([[], ['results' => null], ['results' => ['US' => 'broken']], ['results' => ['US' => ['rent' => 'broken']]]] as $invalid) {
    $failed = false;
    try { ghastly_movie_watch_offers($invalid); } catch (RuntimeException) { $failed = true; }
    check($failed, 'Malformed responses must never overwrite cached offers.');
}

class WatchStatement extends PDOStatement {
    public array $writes = [];
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return [['external_id' => '1'], ['external_id' => '2']]; }
    public function execute(?array $params = null): bool { $this->writes[] = $params; return true; }
}
class WatchDatabase extends PDO {
    public WatchStatement $writer;
    public function __construct() { $this->writer = new WatchStatement(); }
    public function query(string $query, ?int $fetchMode = null, mixed ...$args): PDOStatement|false { return new WatchStatement(); }
    public function prepare(string $query, array $options = []): PDOStatement|false { return $this->writer; }
}
$database = new WatchDatabase();
$failed = false;
try { ghastly_refresh_movie_watch($database); } catch (RuntimeException) { $failed = true; }
check($failed, 'Partial lookup failure must be reported.');
check(count($database->writer->writes) === 1 && $database->writer->writes[0]['tmdb_id'] === '1', 'Failed lookups must retain the old snapshot; successful empty lookups must replace it.');
echo "Movie watch-provider normalization and cache failure checks passed.\n";
