<?php

declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');
header("Content-Security-Policy: default-src 'self'; img-src 'self' https: data: https://*.google-analytics.com https://*.googletagmanager.com https://*.google.com https://*.g.doubleclick.net; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; connect-src 'self' https://*.google-analytics.com https://www.google-analytics.com https://*.analytics.google.com https://analytics.google.com https://*.googletagmanager.com https://www.googletagmanager.com https://*.google.com https://www.google.com https://*.g.doubleclick.net; script-src 'self' 'unsafe-inline' https://*.googletagmanager.com https://www.googletagmanager.com; frame-src https://www.youtube-nocookie.com; base-uri 'self'; frame-ancestors 'none'; form-action 'self'");
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Content-Type-Options: nosniff');

$shellPath = __DIR__ . '/index.html';
if (!is_file($shellPath)) {
    http_response_code(503);
    echo '<!doctype html><title>Ghastly Guide</title><p>Site build unavailable.</p>';
    exit;
}

$shell = file_get_contents($shellPath);
if (!is_string($shell)) {
    http_response_code(503);
    echo '<!doctype html><title>Ghastly Guide</title><p>Site build unavailable.</p>';
    exit;
}

$route = ['kind' => 'home'];
$fallback = '';
$schema = null;
$title = 'Ghastly Guide — Horror Media Release Calendar';
$description = 'A sourced calendar of new horror movies, shows, video games, tabletop games, comics, and books.';
$canonical = 'https://ghastlyguide.com/';
$socialImage = 'https://ghastlyguide.com/og-ghastly-guide.png';
$socialImageAlt = 'Ghastly Guide — Find What Haunts You Next.';
$socialType = 'website';
$robots = 'index,follow,max-image-preview:large,max-snippet:-1';

require dirname(__DIR__) . '/private/src/seo.php';

try {
    require dirname(__DIR__) . '/private/src/bootstrap.php';
    $route = ghastly_seo_route((string) ($_SERVER['REQUEST_URI'] ?? '/'));
    if ($route['kind'] === 'not_found') {
        http_response_code(404);
        $title = 'Page Not Found — Ghastly Guide';
        $description = $route['description'];
        $canonical = 'https://ghastlyguide.com' . $route['path'];
        $robots = 'noindex,follow';
        $fallback = '<main id="main-content" class="seo-fallback"><section><h1>That page has vanished.</h1><p><a href="/">Return to the Ghastly Calendar</a></p></section></main>';
    } elseif ($route['kind'] === 'release') {
        $database = ghastly_db();
        $release = ghastly_seo_release($database, (int) $route['id']);
        if ($release === null) {
            http_response_code(404);
            $title = 'Release Not Found — Ghastly Guide';
            $description = 'That horror release could not be found.';
            $canonical = 'https://ghastlyguide.com' . $route['path'];
            $robots = 'noindex,follow';
            $fallback = '<main id="main-content" class="seo-fallback"><section><h1>That release has vanished.</h1><p><a href="/">Return to the Ghastly Calendar</a></p></section></main>';
        } else {
            $requestedUrl = ghastly_release_url($release);
            if ($route['path'] !== (string) parse_url($requestedUrl, PHP_URL_PATH)) {
                header('Location: ' . $requestedUrl, true, 301);
                exit;
            }
            $canonicalRelease = ghastly_seo_canonical_release($database, $release);
            $canonical = ghastly_release_url($canonicalRelease);
            $title = $release['title'] . ' Release Date — Ghastly Guide';
            $description = trim((string) ($release['synopsis'] ?? ''));
            if ($description === '') {
                $description = sprintf('%s release date, source, format, and region on Ghastly Guide.', $release['title']);
            }
            if (mb_strlen($description) > 160) {
                $description = mb_substr($description, 0, 157) . '…';
            }
            if (filter_var($release['artwork_url'] ?? null, FILTER_VALIDATE_URL)) {
                $socialImage = $release['artwork_url'];
                $socialImageAlt = $release['title'] . ' cover art';
            }
            $socialType = 'article';
            $schema = ghastly_seo_release_schema($canonicalRelease, $canonical);
            $fallback = ghastly_seo_render_release($release);
        }
    } else {
        $title = $route['title'] . (str_contains($route['title'], 'Ghastly Guide') ? '' : ' — Ghastly Guide');
        $description = $route['description'];
        $canonical = $route['canonical'];
        if (in_array($route['kind'], ['home', 'category', 'month'], true)) {
            $rows = ghastly_seo_rows(ghastly_db(), $route);
            $schema = ghastly_seo_listing_schema($route, $rows);
            $fallback = ghastly_seo_render_listing($route, $rows);
        } elseif ($route['kind'] === 'static') {
            $schema = ghastly_seo_static_schema($route);
            $fallback = ghastly_seo_render_static($route);
        }
    }
} catch (Throwable $error) {
    error_log('Ghastly Guide SEO render: ' . $error->getMessage());
}

$jsonFlags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$schemaJson = json_encode($schema ?? [
    '@context' => 'https://schema.org',
    '@type' => 'WebSite',
    'name' => 'Ghastly Guide',
    'url' => 'https://ghastlyguide.com/',
    'description' => $description,
], $jsonFlags | JSON_THROW_ON_ERROR);

$replace = static function (string $pattern, string $replacement, string $html): string {
    $result = preg_replace_callback($pattern, static fn (): string => $replacement, $html, 1);
    return is_string($result) ? $result : $html;
};
$shell = $replace('#<title>.*?</title>#s', '<title>' . ghastly_seo_escape($title) . '</title>', $shell);
$shell = $replace('#<meta name="description" content="[^"]*"\s*/?>#', '<meta name="description" content="' . ghastly_seo_escape($description) . '" />', $shell);
$shell = $replace('#<meta name="robots" content="[^"]*"\s*/?>#', '<meta name="robots" content="' . $robots . '" />', $shell);
$shell = $replace('#<link rel="canonical" href="[^"]*"\s*/?>#', '<link rel="canonical" href="' . ghastly_seo_escape($canonical) . '" />', $shell);
$shell = $replace('#<meta property="og:type" content="[^"]*"\s*/?>#', '<meta property="og:type" content="' . $socialType . '" />', $shell);
$shell = $replace('#<meta property="og:title" content="[^"]*"\s*/?>#', '<meta property="og:title" content="' . ghastly_seo_escape($title) . '" />', $shell);
$shell = $replace('#<meta property="og:description" content="[^"]*"\s*/?>#', '<meta property="og:description" content="' . ghastly_seo_escape($description) . '" />', $shell);
$shell = $replace('#<meta property="og:url" content="[^"]*"\s*/?>#', '<meta property="og:url" content="' . ghastly_seo_escape($canonical) . '" />', $shell);
$shell = $replace('#<meta property="og:image" content="[^"]*"\s*/?>#', '<meta property="og:image" content="' . ghastly_seo_escape($socialImage) . '" />', $shell);
$shell = $replace('#<meta property="og:image:secure_url" content="[^"]*"\s*/?>#', '<meta property="og:image:secure_url" content="' . ghastly_seo_escape($socialImage) . '" />', $shell);
$shell = $replace('#<meta property="og:image:alt" content="[^"]*"\s*/?>#', '<meta property="og:image:alt" content="' . ghastly_seo_escape($socialImageAlt) . '" />', $shell);
$shell = $replace('#<meta name="twitter:title" content="[^"]*"\s*/?>#', '<meta name="twitter:title" content="' . ghastly_seo_escape($title) . '" />', $shell);
$shell = $replace('#<meta name="twitter:description" content="[^"]*"\s*/?>#', '<meta name="twitter:description" content="' . ghastly_seo_escape($description) . '" />', $shell);
$shell = $replace('#<meta name="twitter:image" content="[^"]*"\s*/?>#', '<meta name="twitter:image" content="' . ghastly_seo_escape($socialImage) . '" />', $shell);
$shell = $replace('#<meta name="twitter:image:alt" content="[^"]*"\s*/?>#', '<meta name="twitter:image:alt" content="' . ghastly_seo_escape($socialImageAlt) . '" />', $shell);
if ($socialType === 'article') {
    $shell = preg_replace('#\s*<meta property="og:image:(?:type|width|height)" content="[^"]*"\s*/?>#', '', $shell) ?? $shell;
}
$shell = $replace('#<script type="application/ld\+json">.*?</script>#s', '<script type="application/ld+json">' . $schemaJson . '</script>', $shell);
$shell = str_replace('<div id="root"></div>', '<div id="root">' . $fallback . '</div>', $shell);

echo $shell;
