CREATE TABLE IF NOT EXISTS businesses (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(160) NOT NULL,
 slug VARCHAR(180) NOT NULL UNIQUE,
 contact_name VARCHAR(160) NULL,
 contact_email VARCHAR(190) NULL,
 phone VARCHAR(40) NULL,
 logo_path VARCHAR(500) NULL,
 timezone VARCHAR(80) NOT NULL DEFAULT 'America/Denver',
 primary_color VARCHAR(20) NOT NULL DEFAULT '#12336b',
 accent_color VARCHAR(20) NOT NULL DEFAULT '#0f766e',
 status ENUM('active','inactive') NOT NULL DEFAULT 'active',
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX idx_business_status(status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 business_id BIGINT UNSIGNED NULL,
 name VARCHAR(160) NOT NULL,
 email VARCHAR(190) NOT NULL UNIQUE,
 password_hash VARCHAR(255) NOT NULL,
 role ENUM('super_admin','business_user') NOT NULL DEFAULT 'business_user',
 provider_kpi_role ENUM('none','leadership','provider','data_uploader') NOT NULL DEFAULT 'none',
 status ENUM('active','inactive') NOT NULL DEFAULT 'active',
 must_change_password TINYINT(1) NOT NULL DEFAULT 0,
 password_changed_at DATETIME NULL,
 password_reset_at DATETIME NULL,
 password_reset_by BIGINT UNSIGNED NULL,
 failed_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
 locked_until DATETIME NULL,
 last_login_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT fk_users_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
 INDEX idx_users_business(business_id), INDEX idx_users_role_status(role,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS data_sources (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 code VARCHAR(80) NOT NULL UNIQUE,
 name VARCHAR(160) NOT NULL,
 description TEXT NULL,
 status ENUM('active','inactive') NOT NULL DEFAULT 'active',
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS business_data_sources (
 business_id BIGINT UNSIGNED NOT NULL,
 data_source_id BIGINT UNSIGNED NOT NULL,
 enabled TINYINT(1) NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(business_id,data_source_id),
 CONSTRAINT fk_bds_business FOREIGN KEY(business_id) REFERENCES businesses(id) ON DELETE CASCADE,
 CONSTRAINT fk_bds_source FOREIGN KEY(data_source_id) REFERENCES data_sources(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS business_features (
 business_id BIGINT UNSIGNED NOT NULL,
 feature_code VARCHAR(80) NOT NULL,
 enabled TINYINT(1) NOT NULL DEFAULT 1,
 updated_by BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY(business_id,feature_code),
 INDEX idx_business_feature_enabled(business_id,enabled),
 CONSTRAINT fk_business_feature_business FOREIGN KEY(business_id) REFERENCES businesses(id) ON DELETE CASCADE,
 CONSTRAINT fk_business_feature_user FOREIGN KEY(updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS report_types (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 data_source_id BIGINT UNSIGNED NOT NULL,
 code VARCHAR(100) NOT NULL,
 parser_key VARCHAR(100) NULL,
 name VARCHAR(180) NOT NULL,
 description VARCHAR(255) NULL,
 upload_path VARCHAR(500) NULL,
 expected_headers_json LONGTEXT NULL,
 required TINYINT(1) NOT NULL DEFAULT 1,
 api_enabled TINYINT(1) NOT NULL DEFAULT 1,
 sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
 status ENUM('active','inactive') NOT NULL DEFAULT 'active',
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_report_source FOREIGN KEY(data_source_id) REFERENCES data_sources(id) ON DELETE CASCADE,
 UNIQUE KEY uq_source_report_code(data_source_id,code), INDEX idx_report_sort(data_source_id,sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS upload_batches (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 business_id BIGINT UNSIGNED NOT NULL,
 data_source_id BIGINT UNSIGNED NOT NULL,
 uploaded_by BIGINT UNSIGNED NOT NULL,
 period_start DATE NOT NULL,
 period_end DATE NOT NULL,
 frequency ENUM('weekly','monthly','quarterly','yearly','custom') NOT NULL DEFAULT 'weekly',
 status ENUM('processing','completed','failed') NOT NULL DEFAULT 'processing',
 completeness_score DECIMAL(5,2) NOT NULL DEFAULT 0,
 warning_count INT UNSIGNED NOT NULL DEFAULT 0,
 error_message TEXT NULL,
 dashboard_json LONGTEXT NULL,
 insights_json LONGTEXT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 completed_at DATETIME NULL,
 CONSTRAINT fk_batch_business FOREIGN KEY(business_id) REFERENCES businesses(id) ON DELETE CASCADE,
 CONSTRAINT fk_batch_source FOREIGN KEY(data_source_id) REFERENCES data_sources(id) ON DELETE RESTRICT,
 CONSTRAINT fk_batch_user FOREIGN KEY(uploaded_by) REFERENCES users(id) ON DELETE RESTRICT,
 INDEX idx_batch_business_period(business_id,period_start,period_end), INDEX idx_batch_status(status,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS uploaded_files (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 batch_id BIGINT UNSIGNED NOT NULL,
 report_type_id BIGINT UNSIGNED NOT NULL,
 original_name VARCHAR(255) NOT NULL,
 stored_name VARCHAR(255) NOT NULL,
 relative_path VARCHAR(500) NOT NULL,
 file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
 mime_type VARCHAR(120) NULL,
 checksum_sha256 CHAR(64) NOT NULL,
 row_count INT UNSIGNED NOT NULL DEFAULT 0,
 status ENUM('uploaded','validated','failed') NOT NULL DEFAULT 'uploaded',
 warnings_json LONGTEXT NULL,
 error_message TEXT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_file_batch FOREIGN KEY(batch_id) REFERENCES upload_batches(id) ON DELETE CASCADE,
 CONSTRAINT fk_file_report FOREIGN KEY(report_type_id) REFERENCES report_types(id) ON DELETE RESTRICT,
 UNIQUE KEY uq_batch_report(batch_id,report_type_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS metrics (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 batch_id BIGINT UNSIGNED NOT NULL,
 metric_key VARCHAR(140) NOT NULL,
 metric_value DECIMAL(20,4) NULL,
 metric_format ENUM('number','currency','percent','text','json') NOT NULL DEFAULT 'number',
 metric_json LONGTEXT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_metric_batch FOREIGN KEY(batch_id) REFERENCES upload_batches(id) ON DELETE CASCADE,
 UNIQUE KEY uq_batch_metric(batch_id,metric_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS gbp_entries (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 business_id BIGINT UNSIGNED NOT NULL,
 entered_by BIGINT UNSIGNED NOT NULL,
 period_start DATE NOT NULL,
 period_end DATE NOT NULL,
 frequency ENUM('weekly','monthly','quarterly','yearly','custom') NOT NULL DEFAULT 'weekly',
 interactions BIGINT UNSIGNED NULL,
 calls BIGINT UNSIGNED NULL,
 directions BIGINT UNSIGNED NULL,
 website_clicks BIGINT UNSIGNED NULL,
 total_reviews BIGINT UNSIGNED NULL,
 new_reviews_manual INT UNSIGNED NULL,
 average_rating DECIMAL(3,2) NULL,
 unanswered_reviews INT UNSIGNED NULL,
 notes TEXT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_gbp_business_period (business_id,period_start,period_end),
 KEY idx_gbp_business_end (business_id,period_end),
 CONSTRAINT fk_gbp_business FOREIGN KEY(business_id) REFERENCES businesses(id) ON DELETE CASCADE,
 CONSTRAINT fk_gbp_user FOREIGN KEY(entered_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NULL,
 business_id BIGINT UNSIGNED NULL,
 event_type VARCHAR(120) NOT NULL,
 event_details LONGTEXT NULL,
 ip_address VARCHAR(64) NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_audit_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL,
 CONSTRAINT fk_audit_business FOREIGN KEY(business_id) REFERENCES businesses(id) ON DELETE SET NULL,
 INDEX idx_audit_created(created_at), INDEX idx_audit_business(business_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS boulevard_connections (
 business_id BIGINT UNSIGNED PRIMARY KEY,
 api_key_encrypted TEXT NULL,
 api_secret_encrypted TEXT NULL,
 boulevard_business_id VARCHAR(160) NULL,
 connected_business_name VARCHAR(190) NULL,
 connected_timezone VARCHAR(100) NULL,
 status ENUM('not_connected','saved','connected','failed') NOT NULL DEFAULT 'not_connected',
 last_tested_at DATETIME NULL,
 last_test_message VARCHAR(1000) NULL,
 last_reports_fetched_at DATETIME NULL,
 available_reports_json LONGTEXT NULL,
 updated_by BIGINT UNSIGNED NULL,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT fk_blvd_connection_business FOREIGN KEY(business_id) REFERENCES businesses(id) ON DELETE CASCADE,
 CONSTRAINT fk_blvd_connection_user FOREIGN KEY(updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS boulevard_report_mappings (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 business_id BIGINT UNSIGNED NOT NULL,
 report_type_id BIGINT UNSIGNED NOT NULL,
 boulevard_report_id VARCHAR(220) NOT NULL,
 boulevard_report_name VARCHAR(255) NULL,
 available_filters_json LONGTEXT NULL,
 date_filter_attribute VARCHAR(255) NULL,
 enabled TINYINT(1) NOT NULL DEFAULT 1,
 updated_by BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_blvd_mapping_type(business_id,report_type_id),
 KEY idx_blvd_mapping_report(business_id,boulevard_report_id),
 CONSTRAINT fk_blvd_mapping_business FOREIGN KEY(business_id) REFERENCES businesses(id) ON DELETE CASCADE,
 CONSTRAINT fk_blvd_mapping_type FOREIGN KEY(report_type_id) REFERENCES report_types(id) ON DELETE CASCADE,
 CONSTRAINT fk_blvd_mapping_user FOREIGN KEY(updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS boulevard_mapping_suggestions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 business_id BIGINT UNSIGNED NOT NULL,
 report_type_id BIGINT UNSIGNED NOT NULL,
 suggested_report_id VARCHAR(220) NULL,
 suggested_report_name VARCHAR(255) NULL,
 confidence TINYINT UNSIGNED NOT NULL DEFAULT 0,
 status ENUM('strong_match','likely_match','needs_review') NOT NULL DEFAULT 'needs_review',
 reason VARCHAR(500) NULL,
 created_by BIGINT UNSIGNED NULL,
 analyzed_at DATETIME NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_blvd_suggestion_type(business_id,report_type_id),
 KEY idx_blvd_suggestion_business(business_id,confidence),
 CONSTRAINT fk_blvd_suggestion_business FOREIGN KEY(business_id) REFERENCES businesses(id) ON DELETE CASCADE,
 CONSTRAINT fk_blvd_suggestion_type FOREIGN KEY(report_type_id) REFERENCES report_types(id) ON DELETE CASCADE,
 CONSTRAINT fk_blvd_suggestion_user FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS boulevard_mapping_verifications (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 business_id BIGINT UNSIGNED NOT NULL,
 report_type_id BIGINT UNSIGNED NOT NULL,
 report_url VARCHAR(1000) NOT NULL,
 template_slug VARCHAR(255) NULL,
 sample_filename VARCHAR(255) NULL,
 detected_headers_json LONGTEXT NULL,
 matched_headers_json LONGTEXT NULL,
 missing_headers_json LONGTEXT NULL,
 extra_headers_json LONGTEXT NULL,
 compatibility_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
 status ENUM('verified','partial','failed') NOT NULL DEFAULT 'failed',
 candidate_reports_json LONGTEXT NULL,
 selected_report_id VARCHAR(220) NULL,
 selected_report_name VARCHAR(255) NULL,
 verified_by BIGINT UNSIGNED NULL,
 verified_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_blvd_verification_type (business_id,report_type_id),
 KEY idx_blvd_verification_business (business_id,status),
 CONSTRAINT fk_blvd_verification_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
 CONSTRAINT fk_blvd_verification_type FOREIGN KEY (report_type_id) REFERENCES report_types(id) ON DELETE CASCADE,
 CONSTRAINT fk_blvd_verification_user FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS boulevard_sync_runs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 business_id BIGINT UNSIGNED NOT NULL,
 period_start DATE NOT NULL,
 period_end DATE NOT NULL,
 frequency ENUM('weekly','monthly','quarterly','yearly','custom') NOT NULL DEFAULT 'weekly',
 status ENUM('queued','preflight','requesting','waiting','running','processing','completed','partial','needs_attention','failed','cancelled') NOT NULL DEFAULT 'queued',
 requested_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
 completed_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
 failed_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
 upload_batch_id BIGINT UNSIGNED NULL,
 started_by BIGINT UNSIGNED NULL,
 started_at DATETIME NULL,
 completed_at DATETIME NULL,
 last_checked_at DATETIME NULL,
 error_message TEXT NULL,
 status_message VARCHAR(1000) NULL,
 preflight_json LONGTEXT NULL,
 reconciliation_json LONGTEXT NULL,
 worker_lock_token VARCHAR(80) NULL,
 worker_locked_at DATETIME NULL,
 last_heartbeat_at DATETIME NULL,
 next_worker_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_blvd_sync_business_period(business_id,period_end,status),
 CONSTRAINT fk_blvd_sync_business FOREIGN KEY(business_id) REFERENCES businesses(id) ON DELETE CASCADE,
 CONSTRAINT fk_blvd_sync_user FOREIGN KEY(started_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS boulevard_sync_items (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 sync_run_id BIGINT UNSIGNED NOT NULL,
 business_id BIGINT UNSIGNED NOT NULL,
 report_type_id BIGINT UNSIGNED NOT NULL,
 boulevard_report_id VARCHAR(220) NOT NULL,
 boulevard_report_name VARCHAR(255) NULL,
 date_filter_attribute VARCHAR(255) NULL,
 interval_value VARCHAR(40) NULL,
 report_export_id VARCHAR(220) NULL,
 file_url TEXT NULL,
 current_export_at DATETIME NULL,
 status ENUM('queued','requesting','requested','accepted','generating','waiting','retry_scheduled','downloading','validating','validated','downloaded','processing','processed','completed','needs_attention','timed_out','failed') NOT NULL DEFAULT 'queued',
 attempt_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
 provider_check_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
 max_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 5,
 provider_payload_json LONGTEXT NULL,
 validation_json LONGTEXT NULL,
 checksum_sha256 CHAR(64) NULL,
 local_path VARCHAR(700) NULL,
 header_score TINYINT UNSIGNED NULL,
 row_count INT UNSIGNED NOT NULL DEFAULT 0,
 warning_count INT UNSIGNED NOT NULL DEFAULT 0,
 error_message TEXT NULL,
 failure_code VARCHAR(80) NULL,
 last_http_status SMALLINT UNSIGNED NULL,
 last_provider_check_at DATETIME NULL,
 next_attempt_at DATETIME NULL,
 last_error_at DATETIME NULL,
 webhook_received_at DATETIME NULL,
 completion_source ENUM('webhook','poll','download_probe','manual') NULL,
 requested_at DATETIME NULL,
 downloaded_at DATETIME NULL,
 processed_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_blvd_sync_item_type(sync_run_id,report_type_id),
 KEY idx_blvd_sync_item_status(sync_run_id,status),
 CONSTRAINT fk_blvd_sync_item_run FOREIGN KEY(sync_run_id) REFERENCES boulevard_sync_runs(id) ON DELETE CASCADE,
 CONSTRAINT fk_blvd_sync_item_business FOREIGN KEY(business_id) REFERENCES businesses(id) ON DELETE CASCADE,
 CONSTRAINT fk_blvd_sync_item_type FOREIGN KEY(report_type_id) REFERENCES report_types(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS boulevard_webhook_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 business_id BIGINT UNSIGNED NOT NULL,
 idempotency_key VARCHAR(190) NOT NULL,
 event_type VARCHAR(100) NOT NULL,
 payload_json LONGTEXT NOT NULL,
 received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 processed_at DATETIME NULL,
 UNIQUE KEY uq_blvd_webhook_event(business_id,idempotency_key),
 KEY idx_blvd_webhook_received(business_id,received_at),
 CONSTRAINT fk_blvd_webhook_business FOREIGN KEY(business_id) REFERENCES businesses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Provider KPI Dashboard Phase 1
CREATE TABLE IF NOT EXISTS provider_kpi_settings (
 business_id BIGINT UNSIGNED PRIMARY KEY,
 module_name VARCHAR(160) NOT NULL DEFAULT 'Provider KPI Dashboard',
 enabled TINYINT(1) NOT NULL DEFAULT 0,
 updated_by BIGINT UNSIGNED NULL,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT fk_provider_kpi_settings_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
 CONSTRAINT fk_provider_kpi_settings_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS provider_profiles (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 business_id BIGINT UNSIGNED NOT NULL,
 linked_user_id BIGINT UNSIGNED NULL,
 name VARCHAR(160) NOT NULL,
 normalized_name VARCHAR(190) NOT NULL,
 email VARCHAR(190) NULL,
 provider_type VARCHAR(100) NULL,
 department VARCHAR(120) NULL,
 status ENUM('active','inactive') NOT NULL DEFAULT 'active',
 display_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
 created_by BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_provider_business_name (business_id,normalized_name),
 UNIQUE KEY uq_provider_linked_user (linked_user_id),
 KEY idx_provider_business_status (business_id,status,display_order),
 CONSTRAINT fk_provider_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
 CONSTRAINT fk_provider_linked_user FOREIGN KEY (linked_user_id) REFERENCES users(id) ON DELETE SET NULL,
 CONSTRAINT fk_provider_created_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS provider_kpi_definitions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 code VARCHAR(120) NOT NULL UNIQUE,
 name VARCHAR(180) NOT NULL,
 category VARCHAR(60) NOT NULL,
 category_sort SMALLINT UNSIGNED NOT NULL DEFAULT 0,
 format ENUM('number','currency','percent','hours') NOT NULL DEFAULT 'number',
 aggregation ENUM('sum','average','derived') NOT NULL DEFAULT 'sum',
 formula_key VARCHAR(120) NULL,
 higher_is_better TINYINT(1) NOT NULL DEFAULT 1,
 goal_enabled TINYINT(1) NOT NULL DEFAULT 1,
 importable TINYINT(1) NOT NULL DEFAULT 1,
 show_on_scorecard TINYINT(1) NOT NULL DEFAULT 1,
 sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
 status ENUM('active','inactive') NOT NULL DEFAULT 'active',
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 KEY idx_provider_kpi_sort (category_sort,sort_order,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS provider_kpi_imports (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 business_id BIGINT UNSIGNED NOT NULL,
 period_month DATE NOT NULL,
 original_filename VARCHAR(255) NOT NULL,
 checksum_sha256 CHAR(64) NOT NULL,
 status ENUM('preview','completed','failed','cancelled') NOT NULL DEFAULT 'preview',
 row_count INT UNSIGNED NOT NULL DEFAULT 0,
 error_count INT UNSIGNED NOT NULL DEFAULT 0,
 warning_count INT UNSIGNED NOT NULL DEFAULT 0,
 preview_json LONGTEXT NULL,
 summary_json LONGTEXT NULL,
 rollback_json LONGTEXT NULL,
 uploaded_by BIGINT UNSIGNED NOT NULL,
 confirmed_by BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 completed_at DATETIME NULL,
 rolled_back_at DATETIME NULL,
 rolled_back_by BIGINT UNSIGNED NULL,
 rollback_message VARCHAR(500) NULL,
 KEY idx_provider_import_business_month (business_id,period_month,status),
 KEY idx_provider_import_checksum (business_id,checksum_sha256),
 CONSTRAINT fk_provider_import_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
 CONSTRAINT fk_provider_import_uploaded_user FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE RESTRICT,
 CONSTRAINT fk_provider_import_confirmed_user FOREIGN KEY (confirmed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS provider_kpi_goals (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 business_id BIGINT UNSIGNED NOT NULL,
 provider_id BIGINT UNSIGNED NOT NULL,
 kpi_definition_id BIGINT UNSIGNED NOT NULL,
 period_month DATE NOT NULL,
 goal_value DECIMAL(20,4) NOT NULL,
 set_by BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_provider_goal_month (provider_id,kpi_definition_id,period_month),
 KEY idx_provider_goal_business_month (business_id,period_month),
 CONSTRAINT fk_provider_goal_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
 CONSTRAINT fk_provider_goal_provider FOREIGN KEY (provider_id) REFERENCES provider_profiles(id) ON DELETE CASCADE,
 CONSTRAINT fk_provider_goal_definition FOREIGN KEY (kpi_definition_id) REFERENCES provider_kpi_definitions(id) ON DELETE CASCADE,
 CONSTRAINT fk_provider_goal_user FOREIGN KEY (set_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS provider_kpi_values (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 business_id BIGINT UNSIGNED NOT NULL,
 provider_id BIGINT UNSIGNED NOT NULL,
 kpi_definition_id BIGINT UNSIGNED NOT NULL,
 period_month DATE NOT NULL,
 actual_value DECIMAL(20,4) NULL,
 source_type ENUM('csv','manual','api') NOT NULL DEFAULT 'csv',
 import_id BIGINT UNSIGNED NULL,
 entered_by BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_provider_value_month (provider_id,kpi_definition_id,period_month),
 KEY idx_provider_value_business_month (business_id,period_month),
 CONSTRAINT fk_provider_value_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
 CONSTRAINT fk_provider_value_provider FOREIGN KEY (provider_id) REFERENCES provider_profiles(id) ON DELETE CASCADE,
 CONSTRAINT fk_provider_value_definition FOREIGN KEY (kpi_definition_id) REFERENCES provider_kpi_definitions(id) ON DELETE CASCADE,
 CONSTRAINT fk_provider_value_import FOREIGN KEY (import_id) REFERENCES provider_kpi_imports(id) ON DELETE SET NULL,
 CONSTRAINT fk_provider_value_user FOREIGN KEY (entered_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Provider KPI Dashboard Phase 2
CREATE TABLE IF NOT EXISTS provider_kpi_reviews (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 business_id BIGINT UNSIGNED NOT NULL,
 provider_id BIGINT UNSIGNED NOT NULL,
 period_month DATE NOT NULL,
 review_date DATE NULL,
 review_status ENUM('draft','completed') NOT NULL DEFAULT 'draft',
 summary TEXT NULL,
 wins TEXT NULL,
 risks TEXT NULL,
 opportunities TEXT NULL,
 next_review_date DATE NULL,
 created_by BIGINT UNSIGNED NULL,
 updated_by BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_provider_review_month (provider_id,period_month),
 KEY idx_provider_review_business_month (business_id,period_month,review_status),
 CONSTRAINT fk_provider_review_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
 CONSTRAINT fk_provider_review_provider FOREIGN KEY (provider_id) REFERENCES provider_profiles(id) ON DELETE CASCADE,
 CONSTRAINT fk_provider_review_created_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
 CONSTRAINT fk_provider_review_updated_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS provider_kpi_actions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 business_id BIGINT UNSIGNED NOT NULL,
 provider_id BIGINT UNSIGNED NOT NULL,
 review_id BIGINT UNSIGNED NOT NULL,
 title VARCHAR(200) NOT NULL,
 details TEXT NULL,
 priority ENUM('low','medium','high') NOT NULL DEFAULT 'medium',
 status ENUM('open','in_progress','completed','cancelled') NOT NULL DEFAULT 'open',
 assigned_to_user_id BIGINT UNSIGNED NULL,
 due_date DATE NULL,
 completed_at DATETIME NULL,
 created_by BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 KEY idx_provider_action_review (review_id,status,due_date),
 KEY idx_provider_action_business_status (business_id,status,due_date),
 CONSTRAINT fk_provider_action_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
 CONSTRAINT fk_provider_action_provider FOREIGN KEY (provider_id) REFERENCES provider_profiles(id) ON DELETE CASCADE,
 CONSTRAINT fk_provider_action_review FOREIGN KEY (review_id) REFERENCES provider_kpi_reviews(id) ON DELETE CASCADE,
 CONSTRAINT fk_provider_action_assigned_user FOREIGN KEY (assigned_to_user_id) REFERENCES users(id) ON DELETE SET NULL,
 CONSTRAINT fk_provider_action_created_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Aesthetic Intel v1.5.6 — cached on-demand AI reviewed reports.
CREATE TABLE IF NOT EXISTS ai_report_reviews (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    business_id BIGINT UNSIGNED NOT NULL,
    report_type ENUM('unified','boulevard','gbp') NOT NULL,
    report_key VARCHAR(255) NOT NULL,
    source_report_id BIGINT UNSIGNED NULL,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    frequency ENUM('weekly','monthly','quarterly','yearly','custom') NOT NULL DEFAULT 'weekly',
    source_hash CHAR(64) NOT NULL,
    normalized_json LONGTEXT NOT NULL,
    review_json LONGTEXT NULL,
    status ENUM('pending','completed','failed') NOT NULL DEFAULT 'pending',
    model VARCHAR(100) NULL,
    prompt_version VARCHAR(30) NOT NULL DEFAULT '1.0',
    requested_by BIGINT UNSIGNED NULL,
    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    last_error VARCHAR(1000) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ai_report_review_identity (business_id,report_type,report_key),
    KEY idx_ai_report_review_business_status (business_id,status,completed_at),
    CONSTRAINT fk_ai_report_review_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
    CONSTRAINT fk_ai_report_review_user FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Aesthetic Intel v1.5.7 — Smart Search normalized-intent AI fallback cache.
CREATE TABLE IF NOT EXISTS smart_search_cache (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cache_hash CHAR(64) NOT NULL,
    intent_hash CHAR(64) NOT NULL,
    context_key CHAR(64) NOT NULL,
    candidate_signature CHAR(64) NOT NULL,
    result_topic_id VARCHAR(120) NOT NULL,
    model VARCHAR(100) NULL,
    response_json TEXT NULL,
    hit_count INT UNSIGNED NOT NULL DEFAULT 0,
    last_used_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_smart_search_cache_hash (cache_hash),
    KEY idx_smart_search_intent (intent_hash,last_used_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Aesthetic Intel v1.5.8 — Automatic Daily Full-System Backups.
CREATE TABLE IF NOT EXISTS automatic_backup_settings (
    id TINYINT UNSIGNED PRIMARY KEY,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    backup_time TIME NOT NULL DEFAULT '03:00:00',
    timezone VARCHAR(100) NOT NULL DEFAULT 'UTC',
    retention_days SMALLINT UNSIGNED NOT NULL DEFAULT 14,
    password_encrypted TEXT NULL,
    last_run_at DATETIME NULL,
    last_success_at DATETIME NULL,
    last_status VARCHAR(20) NULL,
    last_message VARCHAR(500) NULL,
    updated_by BIGINT UNSIGNED NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_auto_backup_settings_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO automatic_backup_settings(id,enabled,backup_time,timezone,retention_days) VALUES(1,0,'03:00:00','UTC',14);

CREATE TABLE IF NOT EXISTS automatic_backup_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    run_type ENUM('automatic','manual_test') NOT NULL DEFAULT 'automatic',
    scheduled_local_date DATE NULL,
    status ENUM('running','verified','failed','deleted') NOT NULL DEFAULT 'running',
    filename VARCHAR(255) NULL,
    password_encrypted TEXT NULL,
    size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    sha256 CHAR(64) NULL,
    table_count INT UNSIGNED NOT NULL DEFAULT 0,
    file_count INT UNSIGNED NOT NULL DEFAULT 0,
    business_count INT UNSIGNED NOT NULL DEFAULT 0,
    user_count INT UNSIGNED NOT NULL DEFAULT 0,
    app_version VARCHAR(30) NULL,
    backup_timezone VARCHAR(100) NOT NULL DEFAULT 'UTC',
    validation_status ENUM('pending','verified','failed') NOT NULL DEFAULT 'pending',
    validated_at DATETIME NULL,
    validation_message VARCHAR(500) NULL,
    error_message VARCHAR(1000) NULL,
    attempt_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    last_started_at DATETIME NULL,
    completed_at DATETIME NULL,
    deleted_at DATETIME NULL,
    deleted_reason ENUM('manual','retention') NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_auto_backup_schedule (run_type,scheduled_local_date),
    KEY idx_auto_backup_status (status,completed_at),
    KEY idx_auto_backup_validation (validation_status,validated_at),
    CONSTRAINT fk_auto_backup_history_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
