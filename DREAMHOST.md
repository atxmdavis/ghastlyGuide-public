# DreamHost production setup

This sharing snapshot includes inert workflow examples in `docs/workflow-examples/`. They describe the original deployment and do not run in this repository. Configure your own automation and credentials for a separate installation.

Ghastly Guide deploys a static React frontend plus PHP API/importer to DreamHost Shared. MySQL and upstream API credentials remain in a server-only file.

## Target layout

```text
/home/your-user/ghastlyguide/
├── public/   # ghastlyguide.com web directory
└── private/  # PHP source, importer, schema, config.php
```

## One-time setup

1. Add `ghastlyguide.com` in DreamHost **Websites → Manage Websites** and set its web directory to `/home/your-user/ghastlyguide/public` with a current PHP version and HTTPS.
2. In **Websites → MySQL**, create a database, database user, and hostname. DreamHost database hostnames are not normally `localhost`.
3. Build with `npm test` and upload `dreamhost-dist/public/` plus `dreamhost-dist/private/`.
4. Import `private/schema.sql` through phpMyAdmin or the MySQL CLI.
5. Copy `private/config.example.php` to `private/config.php`, add the database credentials and API tokens, and set permissions to `600`.
6. Run `php /home/your-user/ghastlyguide/private/cron/ingest.php --source=curated`.
7. Add the scheduled importer only after at least one automated source is configured.

## Source configuration

Keep these values in `private/config.php`; never add that file to Git:

- TMDB: set `tmdb_bearer_token`, keep `tmdb_region` as `US`, choose allowed `tmdb_movie_release_types`, and provide a reviewed `tmdb_tv_horror_keyword_ids` array. Movie discovery accepts every origin country and original language, but only records a title when TMDB has a matching US regional theatrical, digital, physical, or television date.
- IGDB: set `igdb_client_id` and `igdb_client_secret` from a confidential Twitch application. The default horror theme is `19`.
- Reception: TMDB vote averages are stored with their vote counts during TMDB ingestion. IGDB supplies exact Steam app IDs; the `ratings` source refreshes eligible Steam user-review aggregates for releases from the past year. Scores are current and do not represent a historical release-day snapshot.
- RAWG: set `rawg_api_key` and keep `rawg_horror_tags` as `['horror']`. RAWG is supplemental discovery behind IGDB: its dates are not region-specific, and every published RAWG record links back to RAWG as required by the free personal-use plan.
- Asmodee USA: `asmodee_enabled` defaults to true and reads the official U.S. release calendar. Keep the horror and accessory term lists narrow; the collector publishes only exact calendar dates and rejects obvious accessories such as token packs, miniatures, and playmats.
- Google Books: set `google_books_api_key` to enable horror and imprint discovery. Records stay at database confidence and never override an official or PRH title.
- Penguin Random House: set `prh_api_key` from the [PRH developer portal](https://developer.penguinrandomhouse.com/). Filter horror by `prh_cat_uris`, then keep product-page URLs as the evidence link.
- TVmaze: `tvmaze_enabled` defaults to true. No API key. The collector reads the public full schedule, keeps Horror-tagged season premieres with current episode dates, and links each record back to TVmaze.

Run one source manually before scheduling all sources:

```sh
php /home/your-user/ghastlyguide/private/cron/ingest.php --source=tmdb
php /home/your-user/ghastlyguide/private/cron/ingest.php --source=igdb
php /home/your-user/ghastlyguide/private/cron/ingest.php --source=rawg
php /home/your-user/ghastlyguide/private/cron/ingest.php --source=asmodee
php /home/your-user/ghastlyguide/private/cron/ingest.php --source=google-books
php /home/your-user/ghastlyguide/private/cron/ingest.php --source=prh
php /home/your-user/ghastlyguide/private/cron/ingest.php --source=tvmaze
php /home/your-user/ghastlyguide/private/cron/ingest.php --source=ratings
```

Production TMDB, IGDB, RAWG, Asmodee USA, PRH, Google Books, and TVmaze ingestion is scheduled daily at `09:17 UTC` by `docs/workflow-examples/ingest-production.yml`. After a complete, non-empty TMDB or IGDB collection, records inside the managed date window that were not seen in that run are deactivated; failed or empty collections never trigger cleanup. IGDB remains the primary video-game provider; matching RAWG records are suppressed from the public API. Google Books records are suppressed when a publisher or PRH title already covers the same book. TVmaze season premieres are suppressed when TMDB or an official record already covers the same show. The same workflow can be started manually for any configured source. Asmodee’s calendar is downloaded on the GitHub runner and transferred into protected storage for the existing CLI parser, because the storefront rate-limits DreamHost requests. The temporary file is removed after the import; failed downloads leave existing records intact. Do not also install the example DreamHost cron below unless the GitHub schedule is disabled, or every provider will be imported twice.

Alternative DreamHost schedule:

```cron
17 4 * * * php /home/your-user/ghastlyguide/private/cron/ingest.php >> /home/your-user/ghastlyguide/private/ingest.log 2>&1
```

## GitHub environment secrets

Create a `Production` environment with:

- `DREAMHOST_SSH_KEY_B64`
- `DREAMHOST_HOST`
- `DREAMHOST_USER`
- `DREAMHOST_PUBLIC_PATH` = `ghastlyguide/public/`
- `DREAMHOST_PRIVATE_PATH` = `ghastlyguide/private/`

The workflow preserves `private/config.php`. Schema changes are always applied separately before dependent PHP code is deployed.

The curated importer uses the first day of a month or year as a storage placeholder when `date_precision` is `month` or `year`. The frontend displays only the known precision.

## Verification

```sh
curl -fsSI https://ghastlyguide.com/
curl -fsS 'https://ghastlyguide.com/api/releases' >/dev/null
curl -fsS 'https://ghastlyguide.com/api/ingest-status'
```
