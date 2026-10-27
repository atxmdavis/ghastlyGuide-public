CREATE TABLE IF NOT EXISTS movie_watch_availability (
    tmdb_id VARCHAR(160) NOT NULL,
    region CHAR(2) NOT NULL,
    offers_json JSON NOT NULL,
    source_url VARCHAR(1000) NOT NULL,
    checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (tmdb_id, region),
    KEY movie_watch_checked_idx (checked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
