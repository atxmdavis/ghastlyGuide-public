# Ghastly Guide release-source plan

Reviewed 2026-09-02. API access, attribution rules, and commercial-use terms can change; re-check before enabling monetization.

## Source policy

1. **Official** studio, network, publisher, distributor, platform, or creator records set the authoritative date.
2. **Partner** APIs discover candidates and can publish dates when their terms permit it.
3. **Database** records fill gaps but never silently override a higher-confidence source.
4. **Editorial** imports require a human-reviewed evidence URL.

Collectors cache results in MySQL, identify the application, isolate failures, and retain the last successful record. No browser request calls upstream providers directly.

## Recommended integrations

| Medium | Recommended launch source | Use | Requirement / caution |
|---|---|---|---|
| Movies | [TMDB Discover Movie](https://developer.themoviedb.org/reference/discover-movie) | Horror genre, US theatrical/digital/physical/TV dates, artwork, international discovery | Collector uses monthly date windows, does not restrict origin country or original language, and labels records identifiable as non-US-origin or non-English-original as international cinema. Physical releases inspect TMDB's regional history for evidence-backed format tags such as 4K UHD, Blu-ray, DVD, or Steelbook. Recent titles moving from a recognized streaming service to their first listed physical edition receive `Physical debut` and a tag such as `Previously on Netflix`; generic digital entries and older catalogs do not imply a physical debut. API bearer token and mandatory TMDB attribution. Free developer access is non-commercial; commercial projects need a license. |
| Shows | [TMDB Discover TV](https://developer.themoviedb.org/reference/discover-tv) and [On the Air](https://developer.themoviedb.org/reference/tv-series-on-the-air-list) | Series premieres and near-term air dates | TMDB has no simple TV horror genre; use a reviewed horror keyword/list and verify network/platform dates. |
| Shows discovery | [TVmaze schedule API](https://www.tvmaze.com/api) | Currently scheduled horror season premieres with US or global streaming availability | Official public API under CC BY-SA. Collector uses `/schedule/full`, keeps only season-premiere episode 1 rows for Horror-tagged shows, and never stores weekly episodes. Matching official or TMDB titles on the same date stay in front; TVmaze can still fill missing artwork. Attribution is the TVmaze show URL. No API key; identify the app with the configured User-Agent and stay under 20 calls / 10 seconds. |
| Video games | [IGDB v4](https://api-docs.igdb.com/) | Primary release-date records by platform and region; horror theme and controlled textual-signal discovery | Collector implemented. It includes IGDB's Horror theme plus a reviewed set of strong signals in names, summaries, storylines, keywords, and themes so obvious vampire, occult, supernatural, and horror-subgenre releases are not lost to incomplete taxonomy. Broad terms such as `dark fantasy` and `RPG` are deliberately insufficient by themselves. Date precision follows IGDB's `date_format` and full timestamp. Same-title, same-date platform and region records consolidate into one public card while preserving unique platform and region lists. A matching official record in the same date or partial month wins the public date, source, and confidence while IGDB supplements platforms, regions, publishers, and missing artwork. Register a confidential Twitch application. Store client credentials only in server config and cache results. Free access is non-commercial; commercial use needs a partnership. |
| Video games discovery | [RAWG](https://rawg.io/apidocs) | Supplemental horror-tag discovery, aggregate release dates, platforms, and artwork | Collector implemented for the personal-use plan. RAWG dates are not region-specific, records stay at database confidence, and every published record links back to RAWG. Matching IGDB records take precedence. The key stays only in server config. |
| Tabletop | Official publisher and distributor calendars | Exact U.S. retail dates | Asmodee USA collector implemented with a narrow horror allowlist and obvious-accessory exclusions. Expand one reviewed source at a time; crowdfunding, fulfillment, and retail remain distinct dates. |
| Tabletop reference | [BoardGameGeek XML API2](https://boardgamegeek.com/wiki/page/bgg_xml_api2) | Metadata and gap discovery | Application approval and bearer token required. Standard license is non-commercial, mandates credit/logo, and discourages backend dependence. Do not use the private JSON API. |
| Comics | [Lunar Distribution](https://www.lunardistribution.com/) public product workbooks plus [PRH Comics](https://prhcomics.com/) catalogs | Direct-market on-sale dates for covered publishers | Lunar collector implemented without account automation. It discovers the current public FOC and release workbooks, collapses cover variants, requires comic metadata plus explicit horror language, and excludes prose, games, merchandise, bundles, and accessories. Lunar supplies no genre field, so uncertain titles stay out. Dates move frequently. |
| Comics reference | [Comic Vine API](https://comicvine.gamespot.com/api/) | Metadata discovery | Non-commercial only, 200 requests/resource/hour, attribution required, and not a dependable release-calendar backbone. |
| Books | [Penguin Random House API](https://developer.penguinrandomhouse.com/) | On-sale dates and metadata for PRH titles | Registration/API key. Filter horror by imprint/category, then verify publisher product pages. |
| Books discovery | [Google Books](https://developers.google.com/books/docs/v1/reference/volumes) | ISBN and published-date gap checks, plus reviewed imprint publisher queries | Retired from ingestion because of low useful yield and repeated upstream failures. Collector retained for reference; reviewed publisher pages and PRH provide book dates. |
| Books discovery | [Open Library APIs](https://openlibrary.org/developers/api) | Open metadata cross-checking | Not intended to be a third-party service's primary backend; prefer dumps for bulk use. |

## Implementation order

1. Load the reviewed launch catalog across all six media types.
2. Enable TMDB movies after a token and required brand attribution are ready. Keep the region set to `US` so domestic and international productions are included only when they have US availability; enable TV only with reviewed horror keywords.
3. Enable IGDB as the primary video-game provider and RAWG for supplemental horror discovery. Review additions caught only by textual fallback signals, and keep storefronts and publishers as the final confirmation for disputed dates.
4. Add PRH books and qualifying horror imprints through an approved API or reviewed publisher imports; use Google Books only for gaps.
5. Expand the official tabletop calendar roster one source at a time. Use the implemented Asmodee USA collector as the source-specific pattern. Apply for BGG access before adding BGG enrichment; its data cannot become a required launch dependency.

## Curated import contract

Use `dreamhost/backend/data/curated-releases.example.json` as the field contract. Copy reviewed entries into `curated-releases.json`, retain the evidence URL, and run:

```sh
php private/cron/ingest.php --source=curated
```

The importer validates media type, date, source URL, and confidence before any write.

For month- or year-only announcements, store the first day as `release_date` and set `date_precision` to `month` or `year`. The public card will not imply a day that the source did not provide.

## Implemented collectors

The CLI currently accepts `curated`, `tmdb`, `igdb`, `rawg`, `asmodee`, `lunar`, `prh`, and `tvmaze`. Missing credentials and disabled discovery sources are marked `skipped`; transient upstream errors use bounded retries and do not remove the last successful database records. Asmodee uses its official U.S. release calendar as the evidence URL and retains exact day precision. Lunar discovers its public FOC and release workbooks on each run, so no account login or manual monthly download is needed. BoardGameGeek, Comic Vine, and Open Library are deliberately not automated launch dependencies because their current licensing or service guidance does not fit a production calendar backbone.
