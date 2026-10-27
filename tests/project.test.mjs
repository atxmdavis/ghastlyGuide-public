import assert from "node:assert/strict";
import { access, readFile } from "node:fs/promises";
import test from "node:test";

const root = new URL("../", import.meta.url);

test("DreamHost package contains the public and protected application halves", async () => {
  for (const file of [
    "dreamhost-dist/public/index.php",
    "dreamhost-dist/public/api/releases.php",
    "dreamhost-dist/public/api/contact.php",
    "dreamhost-dist/public/api/saved-transfer.php",
    "dreamhost-dist/public/assets/footer-skull.png",
    "dreamhost-dist/public/assets/ghastly-guide-logo.webp",
    "dreamhost-dist/public/assets/ghastly-guide-logo-small.webp",
    "dreamhost-dist/public/assets/release-placeholder.webp",
    "dreamhost-dist/public/assets/theater-curtain-corner.webp",
    "dreamhost-dist/public/assets/skull-divider-ornament.webp",
    "dreamhost-dist/public/assets/footer-skull.webp",
    "dreamhost-dist/public/assets/hero-background.jpg",
    "dreamhost-dist/public/assets/ghastly-guide-logo.png",
    "dreamhost-dist/public/assets/ornate-corner.svg",
    "dreamhost-dist/public/assets/release-placeholder.png",
    "dreamhost-dist/public/assets/skull-divider-ornament.png",
    "dreamhost-dist/public/assets/theater-curtain-corner.png",
    "dreamhost-dist/public/og-ghastly-guide.png",
    "dreamhost-dist/public/favicon.png",
    "dreamhost-dist/public/apple-touch-icon.png",
    "dreamhost-dist/private/schema.sql",
    "dreamhost-dist/private/src/seo.php",
    "dreamhost-dist/private/config.example.php",
    "dreamhost-dist/private/cron/ingest.php",
    "dreamhost-dist/private/cron/migrate.php",
    "dreamhost-dist/private/migrations/001_reception.sql",
    "dreamhost-dist/private/migrations/002_saved_transfers.sql",
  ]) await access(new URL(file, root));
});

test("contact form is routed, validated, and abuse-limited", async () => {
  const main = await readFile(new URL("dreamhost/frontend/main.tsx", root), "utf8");
  const form = await readFile(new URL("app/contact-page.tsx", root), "utf8");
  const endpoint = await readFile(new URL("dreamhost/backend/public/api/contact.php", root), "utf8");
  const apache = await readFile(new URL("dreamhost/backend/public/.htaccess", root), "utf8");
  assert.match(main, /path === "\/contact"/);
  assert.doesNotMatch(form, /info@ghastlyguide\.com/);
  assert.match(form, /contact-honeypot/);
  assert.match(endpoint, /REQUEST_METHOD/);
  assert.match(endpoint, /HTTP_ORIGIN/);
  const validation = await readFile(new URL("dreamhost/backend/src/contact.php", root), "utf8");
  assert.match(validation, /FILTER_VALIDATE_EMAIL/);
  assert.match(endpoint, /ghastly_contact_rate_limit/);
  assert.match(endpoint, /info@ghastlyguide\.com/);
  assert.match(apache, /api\/contact/);
});

test("saved titles can be copied to another device with a one-time link", async () => {
  const home = await readFile(new URL("app/page.tsx", root), "utf8");
  const modal = await readFile(new URL("app/saved-transfer-modal.tsx", root), "utf8");
  const transfer = await readFile(new URL("app/saved-transfer.ts", root), "utf8");
  const endpoint = await readFile(new URL("dreamhost/backend/public/api/saved-transfer.php", root), "utf8");
  const apache = await readFile(new URL("dreamhost/backend/public/.htaccess", root), "utf8");
  const vite = await readFile(new URL("vite.config.ts", root), "utf8");
  const pkg = await readFile(new URL("package.json", root), "utf8");
  const css = await readFile(new URL("app/globals.css", root), "utf8");
  assert.match(home, /no account, login, or profile/);
  assert.match(home, /Lists are not synced/);
  assert.match(home, /Copy to another device/);
  assert.match(home, /SavedTransferModal/);
  assert.match(home, /claimSavedTransferOnce/);
  assert.match(modal, /qrcode/);
  assert.match(modal, /Scan on your other device/);
  assert.match(transfer, /\/api\/saved-transfer/);
  assert.match(endpoint, /saved_transfers/);
  assert.match(endpoint, /expiresIn/);
  assert.match(apache, /api\/saved-transfer/);
  assert.match(vite, /savedTransferDevPlugin/);
  assert.match(vite, /\/api\/saved-transfer/);
  assert.match(pkg, /"qrcode"/);
  assert.match(css, /\.pair-dialog \{/);
});

test("schema supports every launch media type", async () => {
  const schema = await readFile(new URL("dreamhost/backend/schema.sql", root), "utf8");
  for (const media of ["movie", "show", "video_game", "tabletop_game", "comic", "book"]) {
    assert.match(schema, new RegExp(`'${media}'`));
  }
  assert.match(schema, /canonical_key/);
  assert.match(schema, /source_url/);
  assert.match(schema, /confidence/);
  assert.match(schema, /trailer_url/);
  assert.match(schema, /release_external_ids/);
  assert.match(schema, /reception_ratings/);
});

test("production shell has the correct brand and discovery metadata", async () => {
  const html = await readFile(new URL("dreamhost/frontend/index.html", root), "utf8");
  const apache = await readFile(new URL("dreamhost/backend/public/.htaccess", root), "utf8");
  const css = await readFile(new URL("app/globals.css", root), "utf8");
  const divider = await readFile(new URL("app/ornate-divider.tsx", root), "utf8");
  const header = await readFile(new URL("app/site-header.tsx", root), "utf8");
  const home = await readFile(new URL("app/page.tsx", root), "utf8");
  const releaseCard = await readFile(new URL("app/release-card.tsx", root), "utf8");
  const vite = await readFile(new URL("vite.config.ts", root), "utf8");
  assert.match(html, /Ghastly Guide/);
  assert.match(html, /https:\/\/ghastlyguide\.com\//);
  assert.match(html, /application\/ld\+json/);
  assert.match(html, /property="og:image" content="https:\/\/ghastlyguide\.com\/og-ghastly-guide\.png"/);
  assert.match(html, /rel="icon" href="\/favicon\.png\?v=white-g-4"/);
  assert.match(html, /rel="apple-touch-icon" href="\/apple-touch-icon\.png\?v=logo-2"/);
  assert.match(html, /property="og:image:type" content="image\/png"/);
  assert.match(html, /property="og:image:width" content="1730"/);
  assert.match(html, /property="og:image:height" content="909"/);
  assert.match(html, /name="twitter:image" content="https:\/\/ghastlyguide\.com\/og-ghastly-guide\.png"/);
  assert.match(html, /Ghastly Guide — Horror Media Release Calendar/);
  assert.match(html, /name="twitter:description" content="Horror media release calendar\."/);
  assert.doesNotMatch(html, /Horror has a date/i);
  assert.doesNotMatch(html, /Noise Industry/);
  assert.match(apache, /www\\\.ghastlyguide\\\.com/);
  assert.match(apache, /https:\/\/ghastlyguide\.com/);
  assert.match(css, /html \{[^}]*font-size: 14px;/);
  const subBaseRemSizes = [...css.matchAll(/font-size:\s*([0-9]*\.[0-9]+)rem/g)]
    .map(match => Number(match[1]))
    .filter(size => size < 1);
  const subBasePixelSizes = [...css.matchAll(/font-size:\s*([0-9]+)px/g)]
    .map(match => Number(match[1]))
    .filter(size => size < 14);
  assert.deepEqual(subBaseRemSizes, []);
  assert.deepEqual(subBasePixelSizes, []);
  assert.doesNotMatch(css, /font-size:\s*[0-9.]+vw;/);
  assert.match(css, /--editorial: Arial, Helvetica, sans-serif;/);
  assert.doesNotMatch(css, /Newsreader|--serif/);
  for (const token of ["color-blood-curtain", "color-aged-paper", "color-crypt-black", "color-tarnished-gold", "color-blood-orange"]) {
    assert.match(css, new RegExp(`--${token}:`));
  }
  assert.match(css, /prefers-reduced-motion/);
  assert.match(home, /className="hero__logo"[^>]*ghastly-guide-logo\.webp/);
  assert.match(home, /hero__curtain--left/);
  assert.match(css, /theater-curtain-corner\.webp/);
  assert.match(css, /ornate-corner\.svg/);
  assert.match(divider, /skull-divider-ornament\.webp/);
  assert.match(releaseCard, /release-placeholder\.webp/);
  assert.doesNotMatch(divider, /<svg/);
  for (const label of ["Calendar", "This Week", "Saved", "Contact"]) assert.match(header, new RegExp(`label: "${label}"`));
  for (const label of ["Discover", "Movies", "Shows", "Games", "Comics", "Books", "Tabletop"]) assert.doesNotMatch(header, new RegExp(`label: "${label}"`));
  assert.match(header, /range=week/);
  assert.match(header, /site-header__brand/);
  assert.match(header, /ghastly-guide-logo-small\.webp/);
  assert.match(css, /\.site-header \{[^}]*min-height: 102px/);
  assert.match(css, /\.site-header \{[^}]*background: rgba\(12, 11, 9, \.94\)/);
  assert.match(css, /\.site-header--below-hero \{[^}]*background: transparent/);
  assert.match(css, /\.site-header--below-hero \{[^}]*border-bottom: 0/);
  assert.match(home, /belowHero/);
  assert.match(header, /aria-label="Ghastly Guide home"/);
  assert.match(css, /\.site-header > nav > a \{[^}]*font-size: 1\.2rem;/);
  assert.match(html, /class="skip-link" href="#main-content"/);
  assert.match(home, /<main id="main-content" tabIndex=\{-1\}>/);
  assert.doesNotMatch(home, /<section className="hero" id="main-content"/);
  for (const page of ["about-page.tsx", "sources-page.tsx", "contact-page.tsx"]) {
    const content = await readFile(new URL(`app/${page}`, root), "utf8");
    assert.match(content, /<main id="main-content" tabIndex=\{-1\}>/);
    assert.doesNotMatch(content, /page-hero" id="main-content"/);
  }
  assert.match(home, /Copy RSS link/);
  assert.match(home, /clipboard\.writeText/);
  assert.doesNotMatch(home, /across mediums/);
  assert.doesNotMatch(home, /hero__content">\s*<p className="eyebrow"/);
  assert.doesNotMatch(home, /Curated from verified release sources/);
  assert.match(home, /savedOnly \? "Kept on this device" : "Upcoming horror across media"/);
  assert.match(home, /This Week's Haunts/);
  assert.match(home, /Ghastly Calendar/);
  assert.match(home, /Saved Releases/);
  assert.match(home, /no account, login, or profile/);
  assert.match(home, /Copy to another device/);
  assert.match(home, /Download calendar/);
  assert.match(home, /Only titles with a specific day are included/);
  assert.match(home, /button--hint/);
  assert.match(home, /aria-label=\{`Download calendar\. \$\{savedCalendarHint\}`\}/);
  assert.match(home, /Save a title with a specific date to download a calendar file/);
  assert.match(css, /\.button--hint:hover::after/);
  assert.match(home, /downloadSavedCalendar\(Object\.values\(saved\)\)/);
  assert.match(home, /savedCalendarCount === 0/);
  assert.match(home, /showSavedBrowseTools = !savedOnly \|\| savedCount > 50/);
  assert.match(home, /showSavedBrowseTools \? <div className="search-control">/);
  assert.match(home, /showSavedBrowseTools \? <div className="type-filters-wrap">/);
  assert.match(home, /!showSavedBrowseTools \|\| !selectedFilters\.length/);
  assert.match(home, /!showSavedBrowseTools \|\| wholeDatabaseSearch/);
  assert.match(home, /Remove all/);
  assert.match(home, /Confirm remove all/);
  assert.match(home, /commitSaved\(\(\) => \(\{\}\)\)/);
  assert.match(home, /await updateSaved\(update\)/);
  assert.match(home, /toolbar-status__clear/);
  assert.match(css, /\.toolbar-status__clear \{[^}]*min-height: 44px/);
  assert.match(home, /section-heading__actions/);
  assert.match(css, /\.section-heading__actions \{[^}]*flex-wrap: wrap/);
  assert.match(css, /\.calendar-toolbar \{[^}]*position: sticky/);
  assert.match(css, /@media \(max-width: 700px\)[\s\S]*?\.calendar-toolbar \{[^}]*position: static/);
  assert.match(home, /Quick date filter/);
  assert.match(home, /date-range__preset/);
  const dates = await readFile(new URL("app/date.ts", root), "utf8");
  for (const label of ["Today", "This week", "This month", "3 Months", "6 months", "This year"]) {
    assert.match(dates, new RegExp(`label: "${label}"`));
  }
  assert.match(home, /date-range__summary/);
  assert.match(css, /\.date-range__summary \{[^}]*font-family: var\(--display\)/);
  assert.match(css, /\.date-range__summary \{[^}]*margin: 0 0 \.15rem 16px/);
  assert.match(home, /rangeForPreset\("months3", today\)/);
  assert.match(dates, /id === "months3"[\s\S]*?endOfMonth\(addMonths\(start, 2\)\)/);
  assert.match(home, /resultBatchSize = 40/);
  assert.match(home, /filtered\.slice\(0, visibleResultCount\)/);
  assert.match(home, /IntersectionObserver/);
  assert.match(home, /setVisibleResultCount\(current => Math\.min\(current \+ resultBatchSize, filtered\.length\)\)/);
  assert.doesNotMatch(home, /Loading later releases/);
  assert.doesNotMatch(home, /Extended range/);
  assert.doesNotMatch(css, /\.load-next-month \{/);
  assert.match(css, /\.results-sentinel \{[^}]*min-height: 1px/);
  assert.doesNotMatch(home, /Load next month/);
  assert.match(home, /Searching all release dates/);
  assert.match(home, /Limit to selected dates/);
  assert.match(home, /setTimeout\(async \(\) => \{/);
  assert.match(home, /searchReleases\(searchTerm, controller\.signal\)/);
  assert.match(css, /\.search-scope button \{[^}]*min-height: 44px/);
  assert.doesNotMatch(home, /date-range__button-compact/);
  assert.doesNotMatch(css, /\.site-header \{[^}]*position: sticky/);
  assert.match(header, /back-to-top/);
  assert.match(css, /\.back-to-top \{[^}]*position: fixed/);
  assert.match(home, /month-group/);
  assert.doesNotMatch(home, /slice\(0, 6\)/);
  assert.doesNotMatch(home, /Show next month/);
  assert.doesNotMatch(home, /<section className="calendar"/);
  assert.match(css, /family=Special\+Elite/);
  assert.match(css, /--display: "Special Elite", Georgia, serif;/);
  assert.match(vite, /GHASTLY_API_ORIGIN/);
  assert.match(vite, /https:\/\/ghastlyguide\.com/);
  assert.match(css, /\.release-detail dl \{ display: grid;/);
  assert.match(css, /\.release-grid \{[^}]*grid-template-columns: 1fr;/);
  assert.match(css, /\.release-card__art img \{[^}]*object-fit: fill/);
  assert.match(css, /@media \(max-width: 700px\)[\s\S]*?\.release-card__art img\.release-card__art-cover \{[^}]*object-fit: contain/);
  assert.match(css, /@media \(max-width: 700px\)[\s\S]*?\.release-card \{[^}]*7\.75rem minmax\(0, 1fr\)/);
  assert.match(css, /@media \(max-width: 700px\)[\s\S]*?\.release-card__source \{[^}]*display: none/);
  assert.match(css, /@media \(max-width: 700px\)[\s\S]*?#root, main \{[^}]*overflow-x: hidden/);
  assert.match(css, /#root \{[^}]*overflow-x: clip/);
  assert.match(css, /\.discovery__inner \{[^}]*max-width: var\(--content-max\)/);
  assert.match(css, /\.hero__inner \{[^}]*grid-template-columns: minmax\(0, \.92fr\) minmax\(28rem, \.78fr\)/);
  assert.match(css, /\.hero__inner \{[^}]*max-width: var\(--content-max\)[^}]*padding-left: var\(--page-gutter\)/);
  assert.match(css, /\.hero__logo \{[^}]*object-fit: contain/);
  assert.match(css, /@media \(min-width: 1101px\) and \(max-width: 1919px\)[^{]*\{\s*\.hero__content \{[^}]*padding-left: max\(0px, calc\(\(var\(--hero-curtain-width\) \* \.9\)/);
  assert.match(css, /@media \(min-width: 701px\) and \(max-width: 1100px\)[\s\S]*?\.hero__inner \{[^}]*grid-template-columns: 1fr/);
  assert.match(css, /@media \(min-width: 701px\) and \(max-width: 1100px\)[\s\S]*?\.hero__content \{[^}]*justify-self: center;[^}]*width: min\(100%, 48rem\)[^}]*text-align: center/);
  assert.match(css, /@media \(min-width: 701px\) and \(max-width: 1100px\)[\s\S]*?\.hero__content \.hero__lede \{[^}]*margin-right: auto;[^}]*margin-left: auto/);
  assert.match(css, /@media \(max-width: 700px\)[\s\S]*?\.hero__content \{[^}]*justify-self: stretch;[^}]*text-align: center/);
  assert.match(css, /\.site-header--below-hero \.wordmark \{[^}]*display: none/);
  assert.match(css, /\.page-hero \{[^}]*--content-max/);
});

test("public routes are discoverable without relying on client-side rendering", async () => {
  const shell = await readFile(new URL("dreamhost/backend/public/index.php", root), "utf8");
  const seo = await readFile(new URL("dreamhost/backend/src/seo.php", root), "utf8");
  const sitemap = await readFile(new URL("dreamhost/backend/public/sitemap.php", root), "utf8");
  const feed = await readFile(new URL("dreamhost/backend/public/feed.php", root), "utf8");
  const releaseApi = await readFile(new URL("dreamhost/backend/public/api/releases.php", root), "utf8");
  const robots = await readFile(new URL("public/robots.txt", root), "utf8");
  const llms = await readFile(new URL("public/llms.txt", root), "utf8");
  const card = await readFile(new URL("app/release-card.tsx", root), "utf8");
  assert.match(shell, /ghastly_seo_render_listing/);
  assert.match(shell, /ghastly_seo_render_release/);
  assert.match(shell, /noindex,follow/);
  assert.match(seo, /\/calendar\/\(20\\d\{2\}\)/);
  assert.match(seo, /\/releases\/\(\\d\+\)/);
  assert.match(seo, /CollectionPage/);
  assert.match(seo, /BreadcrumbList/);
  assert.match(seo, /AboutPage/);
  assert.match(sitemap, /ghastly_release_url/);
  assert.match(sitemap, /ghastly_seo_work_dedupe/);
  assert.match(sitemap, /lastmod/);
  assert.match(shell, /ghastly_seo_canonical_release/);
  assert.match(feed, /ghastly_seo_dedupe/);
  assert.match(feed, /isPermaLink="true"/);
  assert.match(releaseApi, /id = :release_id/);
  assert.match(releaseApi, /Choose a valid release/);
  assert.match(robots, /User-agent: OAI-SearchBot/);
  assert.match(robots, /Disallow: \/api\//);
  assert.match(llms, /Individual release pages/);
  assert.match(card, /href=\{releasePath\(release\)\}/);
});

test("credentials and generated output remain untracked", async () => {
  const ignore = await readFile(new URL(".gitignore", root), "utf8");
  assert.match(ignore, /dreamhost\/backend\/config\.php/);
  assert.match(ignore, /dreamhost-dist\//);
  assert.match(ignore, /\.env/);
});

test("analytics is production-only and requires visitor consent", async () => {
  const analytics = await readFile(new URL("app/analytics.ts", root), "utf8");
  const consent = await readFile(new URL("app/analytics-consent.tsx", root), "utf8");
  const publicShell = await readFile(new URL("dreamhost/backend/public/index.php", root), "utf8");
  const workflow = await readFile(new URL("docs/workflow-examples/deploy-dreamhost.yml", root), "utf8");
  const vite = await readFile(new URL("vite.config.ts", root), "utf8");
  assert.match(analytics, /import\.meta\.env\.PROD/);
  assert.match(analytics, /ghastlyguide\.com/);
  assert.match(analytics, /allow_google_signals: false/);
  assert.match(analytics, /allow_ad_personalization_signals: false/);
  assert.match(analytics, /dataLayer\?\.push\(arguments\)/);
  assert.match(analytics, /consent", "default"/);
  assert.match(analytics, /analytics_storage: "denied"/);
  assert.match(analytics, /analytics_storage: "granted"/);
  assert.match(analytics, /send_page_view: true/);
  assert.match(vite, /ghastly-guide:analytics-consent:v1/);
  assert.doesNotMatch(vite, /gtag\('config'/);
  assert.match(consent, /installAnalyticsTag/);
  assert.match(consent, /subscribeAnalyticsPreferences/);
  assert.match(consent, /createPortal/);
  assert.match(consent, /guide-notice/);
  assert.match(consent, /Help improve the guide/);
  const footer = await readFile(new URL("app/site-footer.tsx", root), "utf8");
  assert.match(footer, /openAnalyticsPreferences/);
  assert.match(footer, /Privacy choices/);
  assert.match(footer, /footer-skull\.webp/);
  assert.match(footer, /site-footer__statement-copy/);
  assert.match(footer, /Some stories lurk/);
  assert.doesNotMatch(footer, /Every story hides/);
  assert.match(footer, /href="\/contact">Suggest a title/);
  assert.doesNotMatch(footer, /mailto:info@ghastlyguide.com\?subject=Suggest/);
  assert.match(analytics, /preferencesListeners/);
  assert.match(vite, /gtag\/js\?id=/);
  assert.match(vite, /transformIndexHtml/);
  assert.match(publicShell, /script-src[^;]*https:\/\/www\.googletagmanager\.com/);
  assert.match(publicShell, /frame-src https:\/\/www\.youtube-nocookie\.com/);
  assert.match(publicShell, /connect-src[^;]*https:\/\/www\.google-analytics\.com/);
  assert.match(publicShell, /connect-src[^;]*https:\/\/analytics\.google\.com/);
  assert.match(publicShell, /connect-src[^;]*https:\/\/www\.google\.com/);
  assert.match(workflow, /vars\.GA_MEASUREMENT_ID/);
});

test("reviewed launch catalog covers all six media types with provenance", async () => {
  const releases = JSON.parse(await readFile(new URL("dreamhost/backend/data/curated-releases.json", root), "utf8"));
  assert.ok(releases.length >= 6);
  assert.deepEqual(
    [...new Set(releases.map(release => release.media_type))].sort(),
    ["book", "comic", "movie", "show", "tabletop_game", "video_game"],
  );
  assert.equal(new Set(releases.map(release => release.external_id)).size, releases.length);
  for (const release of releases) {
    assert.match(release.release_date, /^\d{4}-\d{2}-\d{2}$/);
    assert.match(release.source_url, /^https:\/\//);
    assert.ok(release.source_name);
    assert.equal(release.confidence, "official");
    assert.ok(["day", "month", "year", "tbd"].includes(release.date_precision));
  }
  const dawnwalker = releases.find(release => release.title === "The Blood of Dawnwalker");
  assert.equal(dawnwalker?.release_date, "2026-09-03");
  assert.equal(dawnwalker?.source_name, "Bandai Namco Entertainment");
  const townfall = releases.find(release => release.title === "Silent Hill: Townfall");
  assert.equal(townfall?.platform, "PC (Microsoft Windows), PlayStation 5");
  assert.equal(townfall?.source_name, "Konami");
});

test("automated collectors are credential-gated and conservative", async () => {
  const config = await readFile(new URL("dreamhost/backend/config.example.php", root), "utf8");
  const importer = await readFile(new URL("dreamhost/backend/cron/ingest.php", root), "utf8");
  const collectors = await readFile(new URL("dreamhost/backend/src/collectors.php", root), "utf8");
  assert.match(config, /'igdb_client_secret' => ''/);
  assert.match(config, /'rawg_api_key' => ''/);
  assert.match(config, /'rawg_horror_tags' => \['horror'\]/);
  assert.match(config, /'google_books_enabled' => false/);
  assert.match(config, /'prh_api_key' => ''/);
  assert.match(config, /inpublisher:"Tor Nightfire"/);
  for (const source of ["curated", "tmdb", "igdb", "rawg", "asmodee", "lunar", "prh"]) {
    assert.match(importer, new RegExp(`'${source}'`));
  }
  assert.match(collectors, /GhastlySourceUnavailable/);
  assert.match(collectors, /date_precision/);
  assert.match(importer, /modify\('-1 year'\)/);
  assert.match(importer, /modify\('\+18 months'\)/);
  assert.doesNotMatch(importer, /modify\('-3 months'\)/);
  assert.match(collectors, /offset < 5000/);
  assert.doesNotMatch(collectors, /api\.boardgamegeek|comicvine\.gamespot\.com\/api/);
});

test("Asmodee USA discovers dated horror tabletop retail releases conservatively", async () => {
  const config = await readFile(new URL("dreamhost/backend/config.example.php", root), "utf8");
  const collectors = await readFile(new URL("dreamhost/backend/src/collectors.php", root), "utf8");
  const workflow = await readFile(new URL("docs/workflow-examples/ingest-production.yml", root), "utf8");
  assert.match(config, /'asmodee_enabled' => true/);
  assert.match(config, /store\.asmodee\.com\/pages\/release-calendar/);
  assert.match(config, /'asmodee_horror_terms'/);
  assert.match(config, /'asmodee_accessory_terms'/);
  assert.match(collectors, /function ghastly_collect_asmodee/);
  assert.match(collectors, /DOMDocument/);
  assert.match(collectors, /'media_type' => 'tabletop_game'/);
  assert.match(collectors, /'region' => 'US'/);
  assert.match(collectors, /'source_name' => 'Asmodee USA'/);
  assert.match(collectors, /'confidence' => 'official'/);
  assert.match(workflow, /- asmodee/);
});

test("Lunar discovers public workbook dates and keeps only explicit horror comics", async () => {
  const config = await readFile(new URL("dreamhost/backend/config.example.php", root), "utf8");
  const collectors = await readFile(new URL("dreamhost/backend/src/collectors.php", root), "utf8");
  const workflow = await readFile(new URL("docs/workflow-examples/ingest-production.yml", root), "utf8");
  const sources = await readFile(new URL("app/sources-page.tsx", root), "utf8");
  assert.match(config, /'lunar_enabled' => true/);
  assert.match(config, /'lunar_horror_terms'/);
  assert.match(config, /'lunar_excluded_terms'/);
  assert.match(collectors, /function ghastly_collect_lunar/);
  assert.match(collectors, /home\/productdatafile\?/);
  assert.match(collectors, /ZipArchive/);
  assert.match(collectors, /createFromFormat\('!m\/d\/Y'/);
  assert.match(collectors, /function ghastly_lunar_synopsis_identity/);
  assert.match(collectors, /function ghastly_lunar_artwork_url/);
  assert.match(collectors, /media\.lunardistribution\.com/);
  assert.match(collectors, /\$artworkByCode/);
  assert.match(collectors, /data-lazy/);
  assert.match(collectors, /'media_type' => 'comic'/);
  assert.match(collectors, /'source_name' => 'Lunar Distribution'/);
  assert.match(collectors, /'confidence' => 'official'/);
  assert.match(workflow, /- lunar/);
  assert.match(sources, /Lunar Distribution/);
  assert.match(sources, /does not supply genres/);
});

test("RAWG discovery is horror-tagged, source-linked, and lower confidence", async () => {
  const collectors = await readFile(new URL("dreamhost/backend/src/collectors.php", root), "utf8");
  assert.match(collectors, /ghastly_collect_rawg/);
  assert.match(collectors, /ghastly_rawg_has_horror_tag/);
  assert.match(collectors, /https:\/\/rawg\.io\/games\//);
  assert.match(collectors, /'source_name' => 'RAWG'/);
  assert.match(collectors, /'confidence' => 'database'/);
});

test("IGDB takes precedence over matching RAWG game records", async () => {
  const releasesApi = await readFile(new URL("dreamhost/backend/public/api/releases.php", root), "utf8");
  const seo = await readFile(new URL("dreamhost/backend/src/seo.php", root), "utf8");
  const releasesClient = await readFile(new URL("app/releases.ts", root), "utf8");
  const collectors = await readFile(new URL("dreamhost/backend/src/collectors.php", root), "utf8");
  const workflow = await readFile(new URL("docs/workflow-examples/ingest-production.yml", root), "utf8");
  assert.match(releasesApi, /\$igdbGames/);
  assert.match(releasesApi, /\$row\['source_name'\] === 'RAWG'/);
  assert.match(releasesApi, /\$igdbGroupIndexes/);
  assert.match(releasesApi, /array_unique\(\$igdbPlatforms\[\$groupKey\]\)/);
  assert.match(releasesApi, /array_unique\(\$igdbRegions\[\$groupKey\]\)/);
  assert.match(releasesApi, /ORDER BY release_date, media_type, title, id/);
  assert.match(releasesApi, /\$groupKey = implode\('\|', \[\s*\$normalizedTitle,\s*\(string\) \$row\['release_date'\],\s*\]\);/);
  assert.match(releasesClient, /view: releaseViewVersion/);
  assert.match(releasesClient, /releaseViewVersion = "14"/);
  assert.match(releasesClient, /export async function searchReleases/);
  assert.match(releasesApi, /mb_strlen\(\$search\) < 2/);
  assert.match(releasesApi, /CAST\(horror_tags AS CHAR\) LIKE/);
  assert.match(releasesApi, /platform LIKE :platform_/);
  assert.match(releasesApi, /release_date IS NOT NULL/);
  assert.match(releasesApi, /LIMIT 501/);
  assert.match(releasesApi, /\$searchLimit = 200/);
  assert.match(collectors, /date_format\.format/);
  assert.match(collectors, /\$format === 'YYYYMMMMDD'/);
  assert.match(collectors, /\$day > 0 && ghastly_valid_date\(\$candidate\)/);
  assert.match(releasesApi, /\$officialGameIndexes/);
  assert.match(releasesApi, /\$samePartialMonth/);
  assert.match(releasesApi, /\$sameReleaseWindow/);
  assert.match(releasesApi, /7 \* 86400/);
  assert.match(releasesApi, /\$suppressedIgdbIndexes/);
  assert.match(releasesApi, /\['platform', 'region', 'publisher'\]/);
  assert.match(releasesApi, /\$releaseId !== null && count\(\$rows\) === 1/);
  assert.match(releasesApi, /\$detailDate->modify\('-7 days'\)/);
  assert.match(seo, /\$releaseDate->modify\('-7 days'\)/);
  assert.match(seo, /source_name = 'IGDB'/);
  assert.match(workflow, /SOURCE:-tmdb,igdb,rawg/);
  const importer = await readFile(new URL("dreamhost/backend/cron/ingest.php", root), "utf8");
  assert.match(importer, /'igdb' => 'IGDB'/);
  assert.match(importer, /last_seen_at < :run_started/);
});

test("post-release reception uses exact provider identities and preserves provenance", async () => {
  const schema = await readFile(new URL("dreamhost/backend/schema.sql", root), "utf8");
  const collectors = await readFile(new URL("dreamhost/backend/src/collectors.php", root), "utf8");
  const importer = await readFile(new URL("dreamhost/backend/cron/ingest.php", root), "utf8");
  const releasesApi = await readFile(new URL("dreamhost/backend/public/api/releases.php", root), "utf8");
  const releaseCard = await readFile(new URL("app/release-card.tsx", root), "utf8");
  const releaseDetail = await readFile(new URL("app/release-detail-page.tsx", root), "utf8");
  const workflow = await readFile(new URL("docs/workflow-examples/deploy-dreamhost.yml", root), "utf8");
  assert.match(schema, /PRIMARY KEY \(release_id, provider, external_id\)/);
  assert.match(schema, /PRIMARY KEY \(provider, external_id, rating_kind\)/);
  assert.match(collectors, /game\.external_games\.external_game_source/);
  assert.match(collectors, /https:\/\/store\.steampowered\.com\/appreviews\//);
  assert.match(collectors, /purchase_type.*steam/s);
  assert.match(collectors, /ORDER BY rating_retrieved_at IS NULL DESC, latest_release_date DESC, rating_retrieved_at ASC/);
  assert.match(importer, /ghastly_refresh_steam_ratings/);
  assert.match(releasesApi, /rating\.rating_count >= 10/);
  assert.match(collectors, /release_record\.release_date BETWEEN DATE_SUB\(UTC_DATE\(\), INTERVAL 1 YEAR\) AND UTC_DATE\(\)/);
  assert.match(releasesApi, /\(string\) \$row\['release_date'\] <= \$today->format\('Y-m-d'\)/);
  assert.match(releaseCard, /release-card__rating/);
  assert.match(releaseDetail, /release-reception-heading/);
  assert.doesNotMatch(releaseCard, /className="tag-list"/);
  assert.match(releaseDetail, /Current rating, updated/);
  assert.match(workflow, /Apply database migrations[\s\S]*Upload public site/);
});

test("IGDB supplements its Horror theme with controlled textual signals", async () => {
  const config = await readFile(new URL("dreamhost/backend/config.example.php", root), "utf8");
  const collectorsUrl = new URL("dreamhost/backend/src/collectors.php", root);
  const collectors = await readFile(collectorsUrl, "utf8");
  assert.match(config, /'igdb_horror_fallback_terms'/);
  for (const signal of ["body horror", "occult", "vampire", "werewolf", "witchcraft"]) {
    assert.match(config, new RegExp(`'${signal}'`));
  }
  assert.match(collectors, /function ghastly_igdb_horror_signals/);
  assert.match(collectors, /function ghastly_igdb_strip_nickname_text/);
  assert.match(collectors, /function ghastly_igdb_strip_cosmetic_term_text/);
  assert.match(collectors, /function ghastly_igdb_ignore_phrases/);
  assert.match(collectors, /function ghastly_igdb_has_horror_theme/);
  assert.match(collectors, /function ghastly_igdb_has_sufficient_horror_evidence/);
  assert.match(config, /'igdb_horror_ignore_phrases'/);
  assert.match(config, /'igdb_horror_standalone_terms'/);
  for (const standalone of ["body horror", "cosmic horror", "psychological horror", "serial killer", "survival horror"]) {
    assert.match(config, new RegExp(`'${standalone}'`));
  }
  assert.match(config, /'zombie costume'/);
  assert.match(collectors, /zombie costume/);
  assert.match(collectors, /function ghastly_igdb_is_sport_game/);
  assert.match(collectors, /\$id === 14/);
  assert.match(collectors, /aka\|nicknamed\|known as/);
  assert.match(collectors, /if \(ghastly_igdb_is_sport_game\(\$game\)\) \{\s*return false;/);
  assert.match(collectors, /return count\(\$normalizedSignals\) >= 2;/);
  assert.match(collectors, /!ghastly_igdb_has_sufficient_horror_evidence\(\$game, \$signals, \$themeId\)/);
  assert.match(collectors, /summary ~ \*"%1\$s"\*/);
  assert.match(collectors, /storyline ~ \*"%1\$s"\*/);
  assert.match(collectors, /_ghastly_horror_signals/);
  assert.match(collectors, /\(\?<!\[\\\\p\{L\}\\\\p\{N\}\]\)/);
  assert.match(collectors, /game\.genres\.id,game\.genres\.name/);
  assert.match(collectors, /genres\.id,genres\.name/);
  assert.doesNotMatch(config, /'igdb_horror_fallback_terms'[\s\S]*?'dark fantasy'/);
  assert.doesNotMatch(config, /'igdb_horror_fallback_terms'[\s\S]*?'rpg'/i);
});

test("IGDB VR platforms produce a searchable VR tag", async () => {
  const bootstrap = await readFile(new URL("dreamhost/backend/src/bootstrap.php", root), "utf8");
  const collectors = await readFile(new URL("dreamhost/backend/src/collectors.php", root), "utf8");
  const releasesApi = await readFile(new URL("dreamhost/backend/public/api/releases.php", root), "utf8");
  assert.match(bootstrap, /function ghastly_platform_is_vr/);
  for (const platform of ["steamvr", "playstation vr2", "meta quest 3", "oculus rift", "windows mixed reality"]) {
    assert.match(bootstrap, new RegExp(`'${platform}'`));
  }
  assert.match(collectors, /ghastly_platform_is_vr\(\$platformName\)/);
  assert.match(collectors, /\$horrorTags\[\] = 'VR'/);
  assert.match(releasesApi, /ghastly_platform_is_vr\(\$row\['platform'\]/);
  assert.match(releasesApi, /\$tags\[\] = 'VR'/);
});

test("movie filters separate theatrical and at-home releases", async () => {
  const page = await readFile(new URL("app/page.tsx", root), "utf8");
  const types = await readFile(new URL("app/types.ts", root), "utf8");
  const card = await readFile(new URL("app/release-card.tsx", root), "utf8");
  const detail = await readFile(new URL("app/release-detail-page.tsx", root), "utf8");
  const css = await readFile(new URL("app/globals.css", root), "utf8");
  const releasesApi = await readFile(new URL("dreamhost/backend/public/api/releases.php", root), "utf8");
  const seo = await readFile(new URL("dreamhost/backend/src/seo.php", root), "utf8");
  const feed = await readFile(new URL("dreamhost/backend/public/feed.php", root), "utf8");
  assert.match(types, /label: "In Theaters"/);
  assert.match(types, /label: "Movies at home"/);
  assert.match(types, /export function releaseTypeLabel/);
  assert.match(card, /releaseTypeLabel\(release\)/);
  for (const platform of ["Theatrical", "Limited theatrical", "Physical", "Digital", "Streaming", "Television"]) {
    assert.match(types, new RegExp(`"${platform}"`));
  }
  assert.match(types, /releaseMatchesFilter/);
  assert.match(types, /releasePlatforms/);
  assert.match(types, /split\(","\)/);
  assert.match(page, /selectedFilters\.some\(filter => releaseMatchesFilter\(release, filter\)\)/);
  assert.match(page, /role="group" aria-label="Filter releases"/);
  assert.doesNotMatch(card, /<dt>Publisher<\/dt>|"Format" : "Platform"|<dt>Region<\/dt>/);
  assert.match(card, /<span className="release-card__source">\{release\.sourceName\}<\/span>/);
  assert.doesNotMatch(card, /Licensed partner|Official source|confidenceLabels/);
  assert.doesNotMatch(detail, /Licensed partner|confidenceLabels/);
  assert.match(card, /media-badge[\s\S]*<time dateTime=\{release\.date\} className="release-card__date">/);
  assert.match(card, /MediaTypeIcon release=\{release\}/);
  assert.match(card, /media-badge__icon/);
  for (const mediaType of ["movie", "show", "video_game", "tabletop_game", "comic", "book"]) {
    assert.match(css, new RegExp(`\\.release-card--${mediaType} \\{ --media-accent:`));
  }
  assert.match(card, /<h3><a[^>]*href=\{releasePath\(release\)\}>\{release\.title\}<\/a><\/h3>/);
  assert.match(card, /release-card__details-link[^>]*href=\{releasePath\(release\)\}[\s\S]*View details/);
  assert.match(css, /\.release-card__details-link \{[^}]*padding: 16px 0;/);
  assert.doesNotMatch(card, /highlightReleaseTags|className="feature-tag"/);
  assert.doesNotMatch(card, /release-card__badges/);
  assert.match(css, /\.release-card__date \{[^}]*font-size: 1rem;[^}]*font-weight: 700/);
  assert.doesNotMatch(card, /Play trailer/);
  assert.match(detail, /Play trailer/);
  assert.match(detail, /TrailerModal/);
  assert.match(detail, /release-detail__media[\s\S]*release-detail__back[\s\S]*release-detail__art[\s\S]*release-detail__trailer/);
  assert.match(detail, /aria-label="Back to guide"/);
  assert.match(detail, /href=\{back\.href\}/);
  assert.match(detail, /release-detail__synopsis[\s\S]*<dl>/);
  assert.doesNotMatch(detail, />Add to calendar</);
  assert.doesNotMatch(detail, />Back to calendar</);
  assert.match(detail, />Back to guide</);
  assert.match(detail, />Download to calendar</);
  assert.match(detail, /<dt>Date source<\/dt>/);
  assert.match(detail, /release\.sourceName/);
  assert.match(detail, /<dt>Last verified<\/dt>/);
  assert.match(detail, /release-detail__tags/);
  assert.match(detail, /release-detail__reception/);
  assert.match(detail, /toggleSaved\(release\)/);
  assert.match(detail, /downloadReleaseCalendar\(release\)/);
  assert.match(card, /Save \$\{release.title\} to your local calendar/);
  assert.doesNotMatch(card, />Add to calendar</);
  assert.match(card, /canAddToCalendar\(release\)/);
  assert.match(card, /downloadReleaseCalendar\(release\)/);
  const calendar = await readFile(new URL("app/calendar-event.ts", root), "utf8");
  const releaseSource = await readFile(new URL("app/release-source.ts", root), "utf8");
  assert.match(calendar, /DTSTART;VALUE=DATE/);
  assert.match(calendar, /datePrecision === "day"/);
  assert.match(calendar, /export function savedCalendarIcs/);
  assert.match(calendar, /export function downloadSavedCalendar/);
  assert.match(calendar, /ghastly-guide-saved\.ics/);
  assert.match(calendar, /X-WR-CALNAME:Ghastly Guide Saved/);
  assert.match(calendar, /events\.flatMap\(release => releaseEventLines/);
  assert.match(releaseSource, /release\.sourceName !== "Lunar Distribution"/);
  assert.match(card, /releaseSourceIsLinkable\(release\)/);
  assert.match(detail, /sourceLinkable[\s\S]*Check source/);
  assert.match(calendar, /sourceLinkable && release\.source/);
  assert.match(seo, /ghastly_seo_source_is_linkable/);
  assert.match(feed, /ghastly_seo_source_is_linkable\(\$row\)/);
  assert.match(card, /release-card__buttons[\s\S]*href=\{release\.source\}/);
  assert.match(card, /release-card__art-bg/);
  assert.match(card, /release-card__art-cover/);
  assert.doesNotMatch(card, /Read more|Read less|synopsisOverflows|release-card__extra|release-card__more/);
  assert.match(card, /release-card__rating/);
  assert.match(css, /\.release-card__rating \{[^}]*margin: 1rem 0;/);
  assert.match(css, /\.release-card \{[^}]*grid-template-columns: 13rem minmax\(0, 1fr\)/);
  assert.match(releasesApi, /\$movieGroupIndexes/);
  assert.match(releasesApi, /\$row\['media_type'\] === 'movie'/);
  assert.match(releasesApi, /\$movieFormatOrder/);
});

test("official YouTube trailers are stored from TMDB videos and optional YouTube search", async () => {
  const schema = await readFile(new URL("dreamhost/backend/schema.sql", root), "utf8");
  const config = await readFile(new URL("dreamhost/backend/config.example.php", root), "utf8");
  const collectors = await readFile(new URL("dreamhost/backend/src/collectors.php", root), "utf8");
  const importer = await readFile(new URL("dreamhost/backend/cron/ingest.php", root), "utf8");
  const bootstrap = await readFile(new URL("dreamhost/backend/src/bootstrap.php", root), "utf8");
  const releasesApi = await readFile(new URL("dreamhost/backend/public/api/releases.php", root), "utf8");
  const modal = await readFile(new URL("app/trailer-modal.tsx", root), "utf8");
  assert.match(schema, /trailer_url VARCHAR\(1000\) NULL/);
  assert.match(config, /'youtube_api_key' => ''/);
  assert.match(collectors, /ghastly_tmdb_youtube_trailer/);
  assert.match(collectors, /ghastly_pick_game_youtube_trailer/);
  assert.match(collectors, /game\.videos\.video_id/);
  assert.match(collectors, /ghastly_rawg_clip_trailer/);
  assert.match(collectors, /googleapis\.com\/youtube\/v3\/search/);
  assert.match(collectors, /official\\s\+trailer/);
  assert.match(importer, /:trailer_url/);
  const migration = await readFile(new URL("dreamhost/backend/cron/migrate.php", root), "utf8");
  assert.match(migration, /SHOW COLUMNS FROM releases LIKE 'trailer_url'/);
  assert.doesNotMatch(bootstrap, /SHOW COLUMNS|ALTER TABLE/);
  assert.match(releasesApi, /\$release\['trailer'\]/);
  assert.match(modal, /youtube-nocookie\.com\/embed/);
  assert.match(modal, /autoplay=0/);
  assert.match(modal, /createPortal/);
});

test("TMDB includes international horror with verified US availability", async () => {
  const config = await readFile(new URL("dreamhost/backend/config.example.php", root), "utf8");
  const importer = await readFile(new URL("dreamhost/backend/cron/ingest.php", root), "utf8");
  const collectors = await readFile(new URL("dreamhost/backend/src/collectors.php", root), "utf8");
  assert.match(config, /'tmdb_region' => 'US'/);
  assert.match(config, /'tmdb_movie_release_types' => \['3', '2', '4', '5', '6'\]/);
  assert.match(collectors, /ghastly_tmdb_month_windows/);
  assert.match(collectors, /ghastly_tmdb_physical_metadata/);
  for (const format of ["4K UHD", "Blu-ray", "DVD", "Steelbook", "Criterion", "Collector edition", "Remastered", "Re-release", "Physical debut", "Previously on "]) {
    assert.match(collectors, new RegExp(format));
  }
  assert.match(collectors, /'Netflix' =>/);
  assert.match(collectors, /\$releaseDate >= \$date/);
  assert.match(collectors, /modify\('-3 years'\)/);
  assert.doesNotMatch(collectors, /Previously digital/);
  assert.match(collectors, /'region' => \$region/);
  assert.match(collectors, /International cinema/);
  assert.match(importer, /deleteSupersededTmdbRecord/);
  assert.match(importer, /external_id = :external_id/);
  assert.match(importer, /'tmdb' => 'TMDB'/);
  assert.match(importer, /last_seen_at < :run_started/);
  assert.match(importer, /\$deactivateMissingReleases->rowCount\(\)/);
  assert.match(importer, /\$failed === 0/);
  assert.doesNotMatch(collectors, /['"]with_origin_country['"]\s*=>/);
  assert.doesNotMatch(collectors, /['"]with_original_language['"]\s*=>/);
  assert.match(config, /'tmdb_tv_horror_keyword_ids' => \['315058', '250593', '256183', '15032', '12339', '287274'\]/);
  assert.match(collectors, /function ghastly_tmdb_tv_horror_keyword_ids/);
  assert.match(collectors, /function ghastly_tmdb_discover_tv_by_keywords/);
  assert.match(collectors, /function ghastly_tmdb_tv_details/);
  const tvDetails = collectors.slice(
    collectors.indexOf("function ghastly_tmdb_tv_details"),
    collectors.indexOf("function ghastly_tmdb_tv_season_date"),
  );
  assert.doesNotMatch(tvDetails, /catch \(Throwable\)/);
  assert.match(collectors, /first_air_date', 'air_date'/);
  assert.match(collectors, /Season ' \. \$seasonNumber \. ' premiere/);
  assert.match(collectors, /tmdb-show-' \. \$id \. '-s' \. \$seasonNumber/);
  assert.match(collectors, /\$id = \(string\) \$showId/);
});

test("PRH discovers horror books while Google Books stays retired", async () => {
  const config = await readFile(new URL("dreamhost/backend/config.example.php", root), "utf8");
  const collectors = await readFile(new URL("dreamhost/backend/src/collectors.php", root), "utf8");
  const releasesApi = await readFile(new URL("dreamhost/backend/public/api/releases.php", root), "utf8");
  const importer = await readFile(new URL("dreamhost/backend/cron/ingest.php", root), "utf8");
  const workflow = await readFile(new URL("docs/workflow-examples/ingest-production.yml", root), "utf8");
  assert.match(config, /'google_books_enabled' => false/);
  assert.match(config, /'prh_domain' => 'PRH.US'/);
  assert.match(collectors, /function ghastly_collect_prh/);
  assert.match(collectors, /function ghastly_prh_is_comic/);
  assert.match(collectors, /function ghastly_prh_is_audiobook/);
  assert.match(collectors, /function ghastly_prh_platform/);
  assert.match(collectors, /return 'Audiobook'/);
  assert.match(collectors, /function ghastly_prh_isbn/);
  assert.match(collectors, /rawurlencode\(\(string\) \$isbn\)/);
  assert.match(collectors, /projectedMinutes/);
  assert.match(collectors, /html_entity_decode/);
  assert.match(collectors, /function ghastly_prh_cover_url/);
  assert.match(collectors, /function ghastly_prh_synopsis/);
  assert.match(collectors, /titles\/views\/list-display/);
  assert.match(collectors, /titles\/content\/definition/);
  assert.match(collectors, /images\.randomhouse\.com\/cover/);
  assert.match(collectors, /str_starts_with\(\$code, 'CGN'\)/);
  assert.match(collectors, /ttrpg\|rulebook\|roleplaying\|role-playing/);
  assert.match(collectors, /'media_type' => ghastly_prh_is_comic\(\$title, \$catUri\) \? 'comic' : 'book'/);
  assert.match(collectors, /Google Books API key is not configured/);
  assert.match(collectors, /Penguin Random House API key is not configured/);
  assert.match(collectors, /function ghastly_date_overlaps_window/);
  assert.match(collectors, /subject:horror ' \. \$year/);
  assert.match(collectors, /Google Books: ' \. ghastly_text/);
  assert.match(collectors, /skipped_date=%d/);
  assert.match(collectors, /api\.penguinrandomhouse\.com\/resources\/v2\/title\/domains/);
  assert.match(collectors, /'confidence' => 'partner'/);
  assert.match(releasesApi, /preferredBookKeys/);
  assert.match(releasesApi, /\$bookGroupIndexes/);
  assert.match(releasesApi, /'Audiobook' => 3/);
  assert.match(releasesApi, /\['book', 'comic'\]/);
  assert.match(releasesApi, /Google Books/);
  assert.match(importer, /'prh'/);
  assert.match(importer, /deleteSupersededPrhRecord/);
  assert.match(importer, /Penguin Random House/);
  assert.match(workflow, /- prh/);
  assert.match(workflow, /SOURCE:-tmdb,igdb,rawg,asmodee,lunar,prh,tvmaze,ratings/);
  assert.doesNotMatch(workflow, /google-books/);
  assert.doesNotMatch(importer, /google-books/);
});

test("TVmaze discovers current horror season premieres without storing episodes", async () => {
  const config = await readFile(new URL("dreamhost/backend/config.example.php", root), "utf8");
  const collectors = await readFile(new URL("dreamhost/backend/src/collectors.php", root), "utf8");
  const importer = await readFile(new URL("dreamhost/backend/cron/ingest.php", root), "utf8");
  const releasesApi = await readFile(new URL("dreamhost/backend/public/api/releases.php", root), "utf8");
  const workflow = await readFile(new URL("docs/workflow-examples/ingest-production.yml", root), "utf8");
  const sources = await readFile(new URL("app/sources-page.tsx", root), "utf8");
  assert.match(config, /'tvmaze_enabled' => true/);
  assert.match(collectors, /function ghastly_collect_tvmaze/);
  assert.match(collectors, /function ghastly_tvmaze_is_season_premiere/);
  assert.match(collectors, /function ghastly_tvmaze_has_current_episodes/);
  assert.match(collectors, /api\.tvmaze\.com\/schedule\/full/);
  assert.match(collectors, /\$episode\['number'\] \?\? 0\) === 1/);
  assert.match(collectors, /mb_strtolower\(trim\(\(string\) \$genre\)\) === 'horror'/);
  assert.match(collectors, /'source_name' => 'TVmaze'/);
  assert.match(collectors, /'confidence' => 'database'/);
  assert.match(collectors, /tvmaze-show-' \. \$id \. '-s' \. \$seasonNumber/);
  assert.doesNotMatch(collectors, /shows\/:id\/episodes/);
  assert.match(importer, /'tvmaze'/);
  assert.match(releasesApi, /\$showGroups/);
  assert.match(releasesApi, /\$suppressedShowIndexes/);
  assert.match(releasesApi, /\$showConfidenceRank/);
  assert.match(releasesApi, /7 \* 86400/);
  assert.match(workflow, /- tvmaze/);
  assert.match(sources, /TVmaze/);
  assert.match(sources, /Discovery is not confirmation/);
});

test("about page matches sources voice and keeps required credits", async () => {
  const about = await readFile(new URL("app/about-page.tsx", root), "utf8");
  assert.doesNotMatch(about, /page-hero">.*without turning rumor into fact/);
  assert.match(about, /without turning rumor into fact/);
  assert.match(about, /One date\. Many forms\./);
  assert.match(about, /not endorsed or certified by TMDB/);
  assert.match(about, /TVmaze/);
  assert.match(about, /href="\/sources"/);
});

test("contact page matches sources voice and keeps the form", async () => {
  const contact = await readFile(new URL("app/contact-page.tsx", root), "utf8");
  assert.doesNotMatch(contact, /page-hero">.*Found a missing release/);
  assert.match(contact, /Found a missing release/);
  assert.match(contact, /What helps most/);
  assert.match(contact, /We will write back/);
  assert.match(contact, /href="\/sources"/);
  assert.match(contact, /contact-form/);
});

test("public ingest health does not expose provider errors", async () => {
  const statusApi = await readFile(new URL("dreamhost/backend/public/api/ingest-status.php", root), "utf8");
  assert.doesNotMatch(statusApi, /runs\.error_message/);
});
