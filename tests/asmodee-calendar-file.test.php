<?php

declare(strict_types=1);
putenv('GHASTLY_GUIDE_CONFIG=' . __DIR__ . '/fixtures/test-config.php');
require dirname(__DIR__) . '/backend/src/collectors.php';

$path = tempnam(sys_get_temp_dir(), 'ghastly-asmodee-test-');
if ($path === false) throw new RuntimeException('Unable to create calendar fixture.');
putenv('GHASTLY_ASMODEE_CALENDAR_FILE=' . $path);
try {
    $html = '<html><body>Calendar fixture</body></html>';
    file_put_contents($path, $html);
    if (ghastly_asmodee_calendar_html('https://store.asmodee.com/pages/release-calendar') !== $html) {
        throw new RuntimeException('Transferred calendar must be read without a network request.');
    }
    foreach (['empty', 'stale', 'oversized', 'error-page', 'missing'] as $case) {
        file_put_contents($path, $case === 'oversized' ? str_repeat('x', 8 * 1024 * 1024 + 1) : ($case === 'empty' ? '' : $html));
        touch($path, $case === 'stale' ? time() - 3601 : time());
        if ($case === 'missing') unlink($path);
        $failed = false;
        try {
            ghastly_collect_asmodee('2026-01-01', '2027-12-31');
        } catch (RuntimeException) {
            $failed = true;
        }
        if (!$failed) throw new RuntimeException("Invalid calendar must fail rather than update releases: {$case}");
    }
} finally {
    if (is_file($path)) unlink($path);
    putenv('GHASTLY_ASMODEE_CALENDAR_FILE');
}
echo "Asmodee transferred calendar validation checks passed.\n";
