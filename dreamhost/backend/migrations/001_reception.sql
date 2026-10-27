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
