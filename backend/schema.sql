CREATE TABLE IF NOT EXISTS releases (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    canonical_key CHAR(64) NOT NULL,
    external_id VARCHAR(160) NULL,
    title VARCHAR(255) NOT NULL,
    creator VARCHAR(255) NOT NULL DEFAULT '',
    media_type ENUM('movie', 'show', 'video_game', 'tabletop_game', 'comic', 'book') NOT NULL,
    release_date DATE NULL,
    date_precision ENUM('day', 'month', 'year', 'tbd') NOT NULL DEFAULT 'day',
    publisher VARCHAR(255) NOT NULL DEFAULT '',
    platform VARCHAR(255) NULL,
    region VARCHAR(80) NULL,
    horror_tags JSON NULL,
    synopsis TEXT NULL,
    source_url VARCHAR(1000) NOT NULL,
    source_name VARCHAR(255) NOT NULL,
    confidence ENUM('official', 'partner', 'database', 'editorial') NOT NULL,
    source_rank TINYINT UNSIGNED NOT NULL DEFAULT 1,
    artwork_url VARCHAR(1000) NULL,
    trailer_url VARCHAR(1000) NULL,
    first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    active TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY releases_canonical_key_unique (canonical_key),
    KEY releases_date_active_idx (release_date, active),
    KEY releases_media_date_idx (media_type, release_date),
    KEY releases_external_idx (external_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS source_runs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    source_slug VARCHAR(120) NOT NULL,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    status ENUM('running', 'ok', 'failed', 'skipped') NOT NULL DEFAULT 'running',
    found_count INT UNSIGNED NOT NULL DEFAULT 0,
    error_message VARCHAR(1000) NULL,
    PRIMARY KEY (id),
    KEY source_runs_source_started_idx (source_slug, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS release_external_ids (
    release_id BIGINT UNSIGNED NOT NULL,
    provider VARCHAR(40) NOT NULL,
    external_id VARCHAR(160) NOT NULL,
    source_url VARCHAR(1000) NOT NULL,
    first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (release_id, provider, external_id),
    KEY release_external_lookup_idx (provider, external_id),
    CONSTRAINT release_external_release_fk FOREIGN KEY (release_id) REFERENCES releases (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reception_ratings (
    provider VARCHAR(40) NOT NULL,
    external_id VARCHAR(160) NOT NULL,
    rating_kind ENUM('user', 'critic') NOT NULL,
    score DECIMAL(6,2) NOT NULL,
    scale DECIMAL(6,2) NOT NULL,
    rating_count INT UNSIGNED NOT NULL DEFAULT 0,
    label VARCHAR(120) NULL,
    source_url VARCHAR(1000) NOT NULL,
    retrieved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (provider, external_id, rating_kind),
    KEY reception_retrieved_idx (retrieved_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS saved_transfers (
    token_hash CHAR(64) NOT NULL,
    payload_json LONGTEXT NOT NULL,
    expires_at DATETIME NOT NULL,
    claimed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (token_hash),
    KEY saved_transfers_expires_idx (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS movie_watch_availability (
    tmdb_id VARCHAR(160) NOT NULL,
    region CHAR(2) NOT NULL,
    offers_json JSON NOT NULL,
    source_url VARCHAR(1000) NOT NULL,
    checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (tmdb_id, region),
    KEY movie_watch_checked_idx (checked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
