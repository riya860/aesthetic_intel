-- Aesthetic Intel - GA4 Google Connections Control Center
-- Additive MariaDB/MySQL migration. Existing tables are not removed or rewritten.

CREATE TABLE IF NOT EXISTS google_ga4_preferences (
    business_id BIGINT UNSIGNED NOT NULL,
    key_events_json LONGTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (business_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS google_ga4_saved_views (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    business_id BIGINT UNSIGNED NOT NULL,
    view_name VARCHAR(80) NOT NULL,
    period_days INT NOT NULL DEFAULT 30,
    section_anchor VARCHAR(32) NOT NULL DEFAULT 'trend',
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_google_ga4_saved_view_name (business_id, view_name),
    KEY idx_google_ga4_saved_views_business (business_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS google_sync_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    business_id BIGINT UNSIGNED NOT NULL,
    service VARCHAR(16) NOT NULL,
    action_name VARCHAR(40) NOT NULL,
    status VARCHAR(16) NOT NULL,
    rows_synced INT NOT NULL DEFAULT 0,
    message VARCHAR(1000) NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_google_sync_history_business_service (business_id, service, created_at),
    KEY idx_google_sync_history_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS google_ga4_pdf_comparison_runs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    business_id BIGINT UNSIGNED NOT NULL,
    extraction_id BIGINT UNSIGNED NULL,
    property_id VARCHAR(64) NOT NULL,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    match_percent DECIMAL(7,2) NOT NULL DEFAULT 0,
    comparable_metrics INT NOT NULL DEFAULT 0,
    matched_metrics INT NOT NULL DEFAULT 0,
    review_metrics INT NOT NULL DEFAULT 0,
    comparison_json LONGTEXT NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_google_ga4_pdf_compare_business (business_id, created_at),
    KEY idx_google_ga4_pdf_compare_extraction (extraction_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
