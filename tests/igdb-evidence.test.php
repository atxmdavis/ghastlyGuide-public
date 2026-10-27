<?php

declare(strict_types=1);

putenv('GHASTLY_GUIDE_CONFIG=' . __DIR__ . '/fixtures/test-config.php');
require dirname(__DIR__) . '/dreamhost/backend/src/collectors.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

$sportGame = [
    'genres' => [['id' => 14, 'name' => 'Sport']],
    'themes' => [['id' => 17, 'name' => 'Fantasy']],
];
$assert(
    !ghastly_igdb_has_sufficient_horror_evidence($sportGame, ['witchcraft'], 19),
    'A non-horror sport game must not qualify from a single witchcraft mention.',
);

$sportHorror = $sportGame;
$sportHorror['themes'][] = ['id' => 19, 'name' => 'Horror'];
$assert(
    ghastly_igdb_has_sufficient_horror_evidence($sportHorror, [], 19),
    'An explicit IGDB Horror theme must remain sufficient, including for sport games.',
);

$assert(
    ghastly_igdb_has_sufficient_horror_evidence([], ['psychological horror'], 19),
    'A strong standalone horror phrase must remain sufficient.',
);
$assert(
    !ghastly_igdb_has_sufficient_horror_evidence([], ['witchcraft'], 19),
    'A single broad supernatural term must not qualify by itself.',
);
$assert(
    ghastly_igdb_has_sufficient_horror_evidence([], ['witchcraft', 'occult'], 19),
    'Multiple independent supernatural signals must remain sufficient.',
);

fwrite(STDOUT, "IGDB horror evidence checks passed.\n");
