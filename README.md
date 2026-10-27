# Ghastly Guide

A source-aware release calendar for horror movies, shows, video games, tabletop games, comics, and books. Built with React, TypeScript, Vite, PHP, and MySQL.

## About this repository

This is a public source snapshot for review and sharing. It has no production credentials or active deployment workflows and does not automatically update from the production repository. The original application runs at [ghastlyguide.com](https://ghastlyguide.com).

## Project structure

```text
frontend/
  index.html          HTML entry point
  main.tsx            React entry point and route selection
  src/                Pages, components, styles, and browser data helpers
backend/
  public/             PHP web entry points and API endpoints
  src/                Collectors, database access, and application services
  cron/               Import and migration commands
  data/               Reviewed release records and an example data contract
  migrations/         Incremental SQL migrations
  config.example.php  Configuration template; real config stays untracked
  schema.sql          Base database schema
public/               Static frontend assets copied by Vite
scripts/              Build packaging
tests/               Node and PHP tests, plus isolated fixtures
docs/                Deployment guide, source notes, and inactive workflow examples
```

Root configuration files control Vite, TypeScript, ESLint, and npm. The frontend asset directory `public/` is separate from PHP web entry points in `backend/public/`; packaging combines both into the deployable public directory.

## Local development

Requires Node.js 22.13 or newer. PHP 8.3 is used for backend tests; running a local backend also requires MySQL and provider configuration.

```sh
npm ci
npm run dev
```

By default, `/api` requests proxy to the live catalog at `https://ghastlyguide.com`. The saved-transfer development endpoint uses a temporary in-memory store. Contact submissions use the configured API, so use a local backend when testing that form.

To use your own API:

```sh
GHASTLY_API_ORIGIN=http://127.0.0.1:8080 npm run dev
```

See the [deployment guide](docs/DEPLOYMENT.md) for PHP, database, configuration, and hosting setup. A frontend preview does not require provider credentials.

## Validation and packaging

```sh
npm run lint
npm test
```

`npm test` runs typechecking, builds the frontend, packages the PHP application, and runs Node/PHP checks. It generates ignored output in `dist/` and `dreamhost-dist/`. Packaging keeps browser-accessible files in `dreamhost-dist/public/` and server-only files in the sibling `dreamhost-dist/private/`.

Never commit real configuration, API tokens, or SSH keys. `backend/config.example.php` is a template; `backend/config.php` and deployed `private/config.php` must remain private.

## Data sources and ingestion

The importer supports `curated`, `tmdb`, `igdb`, `rawg`, `asmodee`, `lunar`, `prh`, and `tvmaze`, plus `ratings` and `availability` refresh jobs. Google Books discovery is retired. Reviewed records live in `backend/data/curated-releases.json`; collectors preserve source evidence, region, confidence, and date precision.

Provider credentials belong in protected server configuration. A missing or disabled provider is recorded as skipped so other sources can complete. Building or testing does not run ingestion.

See [source research and attribution notes](docs/SOURCE_RESEARCH.md). Workflow examples in [docs/workflow-examples/](docs/workflow-examples/) are reference files and cannot run from that directory.

Production analytics is optional through `VITE_GA_MEASUREMENT_ID` and requires visitor consent. The example deployment reads the public measurement ID from the `GA_MEASUREMENT_ID` repository variable.
