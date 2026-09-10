-- Aesthetic Intel: Google Login + GA4 + Google Business Profile
-- MySQL / MariaDB migration

CREATE TABLE IF NOT EXISTS google_user_identities (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    provider VARCHAR(32) NOT NULL DEFAULT 'google',
    provider_subject VARCHAR(255) NOT NULL,
    provider_email VARCHAR(255) NOT NULL,
    display_name VARCHAR(255) NULL,
    picture_url TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_google_identity_subject (provider, provider_subject),
    UNIQUE KEY uq_google_identity_user (user_id, provider),
    KEY idx_google_identity_email (provider_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS google_business_memberships (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    business_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    membership_role VARCHAR(24) NOT NULL DEFAULT 'member',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_google_membership (business_id, user_id),
    KEY idx_google_membership_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS google_connections (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    business_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    service VARCHAR(16) NOT NULL,
    google_subject VARCHAR(255) NULL,
    google_email VARCHAR(255) NULL,
    access_token_encrypted LONGTEXT NULL,
    refresh_token_encrypted LONGTEXT NULL,
    token_expires_at DATETIME NULL,
    scopes_json LONGTEXT NULL,
    selected_resource_id VARCHAR(255) NULL,
    selected_resource_name VARCHAR(255) NULL,
    resource_meta_json LONGTEXT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'connected',
    last_error VARCHAR(500) NULL,
    connected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_synced_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_google_business_service (business_id, service),
    KEY idx_google_connections_user (user_id),
    KEY idx_google_connections_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS google_ga4_daily_metrics (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    business_id BIGINT UNSIGNED NOT NULL,
    property_id VARCHAR(64) NOT NULL,
    metric_date DATE NOT NULL,
    active_users INT NOT NULL DEFAULT 0,
    new_users INT NOT NULL DEFAULT 0,
    sessions INT NOT NULL DEFAULT 0,
    engaged_sessions INT NOT NULL DEFAULT 0,
    engagement_rate DECIMAL(12,8) NOT NULL DEFAULT 0,
    event_count BIGINT NOT NULL DEFAULT 0,
    total_revenue DECIMAL(18,2) NOT NULL DEFAULT 0,
    synced_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_google_ga4_day (business_id, property_id, metric_date),
    KEY idx_google_ga4_business_date (business_id, metric_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS google_gbp_daily_metrics (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    business_id BIGINT UNSIGNED NOT NULL,
    location_resource VARCHAR(255) NOT NULL,
    metric_date DATE NOT NULL,
    desktop_maps_impressions BIGINT NOT NULL DEFAULT 0,
    mobile_maps_impressions BIGINT NOT NULL DEFAULT 0,
    desktop_search_impressions BIGINT NOT NULL DEFAULT 0,
    mobile_search_impressions BIGINT NOT NULL DEFAULT 0,
    website_clicks BIGINT NOT NULL DEFAULT 0,
    call_clicks BIGINT NOT NULL DEFAULT 0,
    direction_requests BIGINT NOT NULL DEFAULT 0,
    synced_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_google_gbp_day (business_id, location_resource, metric_date),
    KEY idx_google_gbp_business_date (business_id, metric_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
