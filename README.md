# Ghastly Guide

Ghastly Guide is a source-aware release calendar for horror movies, shows, video games, tabletop games, comics, and books.

## Sharing snapshot

This repository is a source snapshot for review. It starts with a single commit and does not include production deployment or scheduled ingestion workflows, server configuration, or credentials. Workflow examples live under `docs/workflow-examples/` and cannot run from that directory. It does not automatically update from the production repository. Deployment instructions describe the application architecture; deployment automation must be configured separately.

## Stack

- React + TypeScript + Vite frontend
- PHP 8.3 API and scheduled collectors
- MySQL on DreamHost Shared
- GitHub Actions deployment to DreamHost

## Local development

```sh
npm install
npm run dev
```

`npm run dev` proxies `/api` to the live DreamHost catalog at `https://ghastlyguide.com`. To use a local PHP API instead:

```sh
GHASTLY_API_ORIGIN=http://127.0.0.1:8080 npm run dev
```

## Validation and package

```sh
npm run lint
npm test
```

`npm test` builds and verifies `dreamhost-dist/public` and `dreamhost-dist/private`. Never commit or upload `dreamhost/backend/config.php`; use the server-only template described in [DREAMHOST.md](DREAMHOST.md).

The source and licensing plan is documented in [docs/SOURCE_RESEARCH.md](docs/SOURCE_RESEARCH.md).

Production analytics uses the optional `VITE_GA_MEASUREMENT_ID` build variable. The production HTML includes the Google tag with analytics storage denied until a visitor accepts; local development does not inject the tag. The deployment workflow reads the public measurement ID from the `GA_MEASUREMENT_ID` GitHub repository variable.

## Release ingestion

The importer supports six isolated sources:

- `curated` — reviewed official records committed in `dreamhost/backend/data/curated-releases.json`
- `tmdb` — US-dated horror movies from any origin or original language, plus an explicitly configured TV horror keyword list
- `igdb` — video-game dates by platform and region
- `rawg` — horror-tagged video-game discovery with global catalog dates and required source backlinks
- `asmodee` — official Asmodee USA calendar discovery for clearly horror-related tabletop retail releases; obvious accessories are excluded
Google Books discovery is retired from ingestion; its collector is retained for reference.
- `tvmaze` — currently scheduled horror season premieres from the public TVmaze API; weekly episodes are not stored

Provider credentials belong only in DreamHost's protected `private/config.php`. A missing or intentionally disabled provider is recorded as `skipped`, while another source can still complete successfully.
