<?php

function run_app_migrations(): void
{
    static $done = false;

    if ($done) {
        return;
    }

    $done = true;

    try {

        /*
         * ============================================================
         * BUSINESS BRANDING
         * ============================================================
         */

        $cols = db()
            ->query(
                "SHOW COLUMNS FROM businesses LIKE 'logo_path'"
            )
            ->fetchAll();

        if (!$cols) {
            db()->exec(
                "ALTER TABLE businesses
                 ADD COLUMN logo_path VARCHAR(500)
                 NULL AFTER phone"
            );
        }


        /*
         * ============================================================
         * GOOGLE BUSINESS PROFILE
         * ============================================================
         */

        db()->exec("
            CREATE TABLE IF NOT EXISTS gbp_entries (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

                business_id BIGINT UNSIGNED NOT NULL,

                entered_by BIGINT UNSIGNED NOT NULL,

                period_start DATE NOT NULL,

                period_end DATE NOT NULL,

                frequency ENUM(
                    'weekly',
                    'monthly',
                    'quarterly',
                    'yearly',
                    'custom'
                ) NOT NULL DEFAULT 'weekly',

                interactions BIGINT UNSIGNED NULL,

                calls BIGINT UNSIGNED NULL,

                directions BIGINT UNSIGNED NULL,

                website_clicks BIGINT UNSIGNED NULL,

                total_reviews BIGINT UNSIGNED NULL,

                new_reviews_manual INT UNSIGNED NULL,

                average_rating DECIMAL(3,2) NULL,

                unanswered_reviews INT UNSIGNED NULL,

                notes TEXT NULL,

                created_at TIMESTAMP
                    NOT NULL DEFAULT CURRENT_TIMESTAMP,

                updated_at TIMESTAMP
                    NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,

                UNIQUE KEY uq_gbp_business_period (
                    business_id,
                    period_start,
                    period_end
                ),

                KEY idx_gbp_business_end (
                    business_id,
                    period_end
                ),

                CONSTRAINT fk_gbp_business
                    FOREIGN KEY (business_id)
                    REFERENCES businesses(id)
                    ON DELETE CASCADE,

                CONSTRAINT fk_gbp_user
                    FOREIGN KEY (entered_by)
                    REFERENCES users(id)
                    ON DELETE RESTRICT

            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
        ");


        $source = (int)(
            db()->query(
                "SELECT id
                 FROM data_sources
                 WHERE code='google-business-profile'
                 LIMIT 1"
            )->fetchColumn()
            ?: 0
        );

        if (!$source) {

            $s = db()->prepare(
                "INSERT INTO data_sources
                 (
                    code,
                    name,
                    status
                 )
                 VALUES
                 (
                    'google-business-profile',
                    'Google Business Profile',
                    'active'
                 )"
            );

            $s->execute();

            $source = (int)db()->lastInsertId();
        }


        if ($source) {

            db()->exec(
                "INSERT IGNORE INTO business_data_sources
                 (
                    business_id,
                    data_source_id,
                    enabled
                 )
                 SELECT
                    id,
                    {$source},
                    1
                 FROM businesses"
            );
        }


        /*
         * ============================================================
         * USER SECURITY
         * ============================================================
         */

        $userSecurityColumns = [

            'must_change_password' =>
                'TINYINT(1) NOT NULL DEFAULT 0 AFTER status',

            'password_changed_at' =>
                'DATETIME NULL AFTER last_login_at',

            'password_reset_at' =>
                'DATETIME NULL AFTER password_changed_at',

            'password_reset_by' =>
                'BIGINT UNSIGNED NULL AFTER password_reset_at',

        ];


        foreach (
            $userSecurityColumns
            as $column => $definition
        ) {

            $exists = db()->query(
                "SHOW COLUMNS FROM users LIKE "
                . db()->quote($column)
            )->fetch();

            if (!$exists) {

                db()->exec(
                    "ALTER TABLE users
                     ADD COLUMN {$column} {$definition}"
                );
            }
        }


        /*
         * ============================================================
         * OPENAI SETTINGS
         * ============================================================
         */

        db()->exec("
            CREATE TABLE IF NOT EXISTS ai_settings (

                id TINYINT UNSIGNED PRIMARY KEY,

                provider VARCHAR(30)
                    NOT NULL DEFAULT 'openai',

                api_key_encrypted TEXT NULL,

                model VARCHAR(100)
                    NOT NULL DEFAULT 'gpt-5-mini',

                is_enabled TINYINT(1)
                    NOT NULL DEFAULT 0,

                last_test_status VARCHAR(20) NULL,

                last_test_message VARCHAR(500) NULL,

                last_tested_at DATETIME NULL,

                updated_by BIGINT UNSIGNED NULL,

                updated_at TIMESTAMP
                    NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,

                CONSTRAINT fk_ai_settings_user
                    FOREIGN KEY (updated_by)
                    REFERENCES users(id)
                    ON DELETE SET NULL

            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
        ");


        db()->exec("
            INSERT IGNORE INTO ai_settings
            (
                id,
                provider,
                model,
                is_enabled
            )
            VALUES
            (
                1,
                'openai',
                'gpt-5-mini',
                0
            )
        ");


        $aiColumns = [

            'admin_api_key_encrypted' =>
                'TEXT NULL AFTER api_key_encrypted',

            'last_usage_status' =>
                'VARCHAR(20) NULL AFTER last_tested_at',

            'last_usage_message' =>
                'VARCHAR(500) NULL AFTER last_usage_status',

            'last_usage_spend' =>
                'DECIMAL(14,4) NULL AFTER last_usage_message',

            'last_usage_currency' =>
                'VARCHAR(10) NULL AFTER last_usage_spend',

            'last_usage_requests' =>
                'BIGINT UNSIGNED NULL AFTER last_usage_currency',

            'last_usage_input_tokens' =>
                'BIGINT UNSIGNED NULL AFTER last_usage_requests',

            'last_usage_output_tokens' =>
                'BIGINT UNSIGNED NULL AFTER last_usage_input_tokens',

            'last_usage_spend_limit' =>
                'DECIMAL(14,4) NULL AFTER last_usage_output_tokens',

            'last_usage_remaining' =>
                'DECIMAL(14,4) NULL AFTER last_usage_spend_limit',

            'last_usage_enforcement' =>
                'VARCHAR(30) NULL AFTER last_usage_remaining',

            'last_usage_period_start' =>
                'DATE NULL AFTER last_usage_enforcement',

            'last_usage_period_end' =>
                'DATE NULL AFTER last_usage_period_start',

            'last_usage_checked_at' =>
                'DATETIME NULL AFTER last_usage_period_end',

        ];


        foreach (
            $aiColumns
            as $column => $definition
        ) {

            $exists = db()->query(
                "SHOW COLUMNS FROM ai_settings LIKE "
                . db()->quote($column)
            )->fetch();

            if (!$exists) {

                db()->exec(
                    "ALTER TABLE ai_settings
                     ADD COLUMN {$column} {$definition}"
                );
            }
        }


        /*
         * ============================================================
         * AI EXTRACTIONS
         * ============================================================
         */

        db()->exec("
            CREATE TABLE IF NOT EXISTS ai_extractions (

                id BIGINT UNSIGNED
                    AUTO_INCREMENT PRIMARY KEY,

                business_id BIGINT UNSIGNED
                    NOT NULL,

                source_code VARCHAR(40)
                    NOT NULL,

                period_start DATE
                    NOT NULL,

                period_end DATE
                    NOT NULL,

                frequency ENUM(
                    'weekly',
                    'monthly',
                    'quarterly',
                    'yearly',
                    'custom'
                ) NOT NULL DEFAULT 'weekly',

                extracted_json LONGTEXT
                    NOT NULL,

                notes TEXT NULL,

                status ENUM(
                    'confirmed',
                    'draft'
                ) NOT NULL DEFAULT 'confirmed',

                created_by BIGINT UNSIGNED
                    NOT NULL,

                created_at TIMESTAMP
                    NOT NULL DEFAULT CURRENT_TIMESTAMP,

                updated_at TIMESTAMP
                    NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,

                KEY idx_ai_extract_business_source_end (
                    business_id,
                    source_code,
                    period_end
                ),

                CONSTRAINT fk_ai_extract_business
                    FOREIGN KEY (business_id)
                    REFERENCES businesses(id)
                    ON DELETE CASCADE,

                CONSTRAINT fk_ai_extract_user
                    FOREIGN KEY (created_by)
                    REFERENCES users(id)
                    ON DELETE RESTRICT

            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
        ");


        /*
         * ============================================================
         * v1.5.5 — AI REPORT INTELLIGENCE
         * ============================================================
         *
         * Existing historical records default to validated so the
         * upgrade does not hide reports that were already in
         * production before this feature existed.
         */

        $validationTables = [

            'upload_batches' => [

                'validation_status' =>
                    "ENUM(
                        'pending',
                        'validated',
                        'warning',
                        'review_required',
                        'approved',
                        'unavailable'
                    ) NOT NULL DEFAULT 'validated'
                    AFTER status",

                'validation_score' =>
                    'TINYINT UNSIGNED NULL
                     AFTER validation_status',

                'validation_json' =>
                    'LONGTEXT NULL
                     AFTER validation_score',

                'validated_at' =>
                    'DATETIME NULL
                     AFTER validation_json',

                'validation_override_by' =>
                    'BIGINT UNSIGNED NULL
                     AFTER validated_at',

                'validation_override_at' =>
                    'DATETIME NULL
                     AFTER validation_override_by',
            ],


            'ai_extractions' => [

                'validation_status' =>
                    "ENUM(
                        'pending',
                        'validated',
                        'warning',
                        'review_required',
                        'approved',
                        'unavailable'
                    ) NOT NULL DEFAULT 'validated'
                    AFTER status",

                'validation_score' =>
                    'TINYINT UNSIGNED NULL
                     AFTER validation_status',

                'validation_json' =>
                    'LONGTEXT NULL
                     AFTER validation_score',

                'validated_at' =>
                    'DATETIME NULL
                     AFTER validation_json',

                'validation_override_by' =>
                    'BIGINT UNSIGNED NULL
                     AFTER validated_at',

                'validation_override_at' =>
                    'DATETIME NULL
                     AFTER validation_override_by',
            ],


            'gbp_entries' => [

                'validation_status' =>
                    "ENUM(
                        'pending',
                        'validated',
                        'warning',
                        'review_required',
                        'approved',
                        'unavailable'
                    ) NOT NULL DEFAULT 'validated'
                    AFTER notes",

                'validation_score' =>
                    'TINYINT UNSIGNED NULL
                     AFTER validation_status',

                'validation_json' =>
                    'LONGTEXT NULL
                     AFTER validation_score',

                'validated_at' =>
                    'DATETIME NULL
                     AFTER validation_json',

                'validation_override_by' =>
                    'BIGINT UNSIGNED NULL
                     AFTER validated_at',

                'validation_override_at' =>
                    'DATETIME NULL
                     AFTER validation_override_by',
            ],

        ];


        foreach (
            $validationTables
            as $table => $columns
        ) {

            foreach (
                $columns
                as $column => $definition
            ) {

                $exists = db()->query(
                    "SHOW COLUMNS FROM {$table} LIKE "
                    . db()->quote($column)
                )->fetch();

                if (!$exists) {

                    db()->exec(
                        "ALTER TABLE {$table}
                         ADD COLUMN {$column} {$definition}"
                    );
                }
            }
        }


        /*
         * ============================================================
         * REPORT TYPE METADATA
         * ============================================================
         */

        $reportTypeColumns = [

            'parser_key' =>
                "VARCHAR(100) NULL AFTER code",

            'upload_path' =>
                "VARCHAR(500) NULL AFTER description",

            'expected_headers_json' =>
                "LONGTEXT NULL AFTER upload_path",

            'api_enabled' =>
                "TINYINT(1) NOT NULL DEFAULT 1 AFTER required",

        ];


        foreach (
            $reportTypeColumns
            as $column => $definition
        ) {

            $exists = db()->query(
                "SHOW COLUMNS FROM report_types LIKE "
                . db()->quote($column)
            )->fetch();

            if (!$exists) {

                db()->exec(
                    "ALTER TABLE report_types
                     ADD COLUMN {$column} {$definition}"
                );
            }
        }


        db()->exec(
            "UPDATE report_types
             SET parser_key=code
             WHERE parser_key IS NULL
                OR parser_key=''"
        );


        $paths = [

            'sales_summary' =>
                'Boulevard → Reports → Summaries → Sales Summary',

            'daily_summary' =>
                'Boulevard → Reports → Summaries → Daily Summary',

            'appointment_metrics' =>
                'Boulevard → Reports → Appointments → Appointment Metrics',

            'staff_schedule' =>
                'Boulevard → Reports → Staff → Staff Schedule',

            'service_commission' =>
                'Boulevard → Reports → Commissions → Service Commission',

            'product_commission' =>
                'Boulevard → Reports → Commissions → Product Commission',

            'membership_commission' =>
                'Boulevard → Reports → Commissions → Membership Commission',

            'membership_sales' =>
                'Boulevard → Reports → Sales → Membership Sales',

            'product_sales' =>
                'Boulevard → Reports → Catalog → Product Sales (all categories)',

            'retail_product_sales' =>
                'Boulevard → Reports → Catalog → Product Sales → filter Category: Retail',

            'subscriptions' =>
                'Boulevard → Reports → Memberships → Subscriptions',

        ];


        $pathStmt = db()->prepare(
            "UPDATE report_types
             SET upload_path=?
             WHERE code=?
               AND (
                    upload_path IS NULL
                    OR upload_path=''
               )"
        );


        foreach (
            $paths
            as $code => $path
        ) {

            $pathStmt->execute([
                $path,
                $code,
            ]);
        }


        $expectedHeaders = [

            'sales_summary' => [
                'Sales Category',
                'Payments',
                'Refunds',
                'Total',
            ],

            'daily_summary' => [
                'Date',
                'Appointments',
                'Requested Appointments',
                'Services',
                'Service Revenue',
                'Product Revenue',
                'Membership Revenue',
                'Tip Revenue',
                'Total Revenue',
            ],

            'appointment_metrics' => [
                'Name',
                'Hours Scheduled',
                'Hours Booked',
                'Utilization',
                'Appt. Count',
                'New Clients',
            ],

            'staff_schedule' => [
                'Date',
                'Time',
                'Service',
                'Client',
                'Other Providers',
            ],

            'service_commission' => [
                'Date',
                'Client',
                'Service',
                'List Price',
                'Subtotal',
                'Rate',
                'Commission',
            ],

            'product_commission' => [
                'Date',
                'Client',
                'Product',
                'Quantity',
                'Total',
                'Rate',
                'Commission',
            ],

            'membership_commission' => [
                'Date',
                'Client',
                'Membership',
                'Quantity',
                'Total',
                'Rate',
                'Commission',
            ],

            'membership_sales' => [
                'Membership',
                'Quantity',
                'Subtotal',
                'Tax',
                'Total',
            ],

            'product_sales' => [
                'Product',
                'Brand',
                'Quantity',
                'Cost Value',
                'Subtotal',
                'Tax',
                'Total',
            ],

            'retail_product_sales' => [
                'Product',
                'Brand',
                'Quantity',
                'Cost Value',
                'Subtotal',
                'Tax',
                'Total',
            ],

            'subscriptions' => [
                'Subscription id',
                'Location Name',
                'Client Name',
                'Product Name',
                'Start On',
                'End On',
                'Subscription Interval',
                'Subscription MRR',
                'Subscription Status',
            ],

        ];


        $headerStmt = db()->prepare(
            "UPDATE report_types
             SET expected_headers_json=?
             WHERE code=?
               AND (
                    expected_headers_json IS NULL
                    OR expected_headers_json=''
               )"
        );


        foreach (
            $expectedHeaders
            as $code => $headers
        ) {

            $headerStmt->execute([
                json_encode(
                    $headers,
                    JSON_UNESCAPED_SLASHES
                ),
                $code,
            ]);
        }


        /*
         * ============================================================
         * BOULEVARD API
         * ============================================================
         */

        db()->exec("
            CREATE TABLE IF NOT EXISTS boulevard_connections (

                business_id BIGINT UNSIGNED PRIMARY KEY,

                api_key_encrypted TEXT NULL,

                api_secret_encrypted TEXT NULL,

                boulevard_business_id VARCHAR(160) NULL,

                connected_business_name VARCHAR(190) NULL,

                connected_timezone VARCHAR(100) NULL,

                status ENUM(
                    'not_connected',
                    'saved',
                    'connected',
                    'failed'
                ) NOT NULL DEFAULT 'not_connected',

                last_tested_at DATETIME NULL,

                last_test_message VARCHAR(1000) NULL,

                last_reports_fetched_at DATETIME NULL,

                available_reports_json LONGTEXT NULL,

                updated_by BIGINT UNSIGNED NULL,

                updated_at TIMESTAMP
                    NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,

                CONSTRAINT fk_blvd_connection_business
                    FOREIGN KEY (business_id)
                    REFERENCES businesses(id)
                    ON DELETE CASCADE,

                CONSTRAINT fk_blvd_connection_user
                    FOREIGN KEY (updated_by)
                    REFERENCES users(id)
                    ON DELETE SET NULL

            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
        ");


        db()->exec("
            CREATE TABLE IF NOT EXISTS boulevard_report_mappings (

                id BIGINT UNSIGNED
                    AUTO_INCREMENT PRIMARY KEY,

                business_id BIGINT UNSIGNED NOT NULL,

                report_type_id BIGINT UNSIGNED NOT NULL,

                boulevard_report_id VARCHAR(220) NOT NULL,

                boulevard_report_name VARCHAR(255) NULL,

                available_filters_json LONGTEXT NULL,

                enabled TINYINT(1)
                    NOT NULL DEFAULT 1,

                updated_by BIGINT UNSIGNED NULL,

                created_at DATETIME
                    NOT NULL DEFAULT CURRENT_TIMESTAMP,

                updated_at TIMESTAMP
                    NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,

                UNIQUE KEY uq_blvd_mapping_type (
                    business_id,
                    report_type_id
                ),

                KEY idx_blvd_mapping_report (
                    business_id,
                    boulevard_report_id
                ),

                CONSTRAINT fk_blvd_mapping_business
                    FOREIGN KEY (business_id)
                    REFERENCES businesses(id)
                    ON DELETE CASCADE,

                CONSTRAINT fk_blvd_mapping_type
                    FOREIGN KEY (report_type_id)
                    REFERENCES report_types(id)
                    ON DELETE CASCADE,

                CONSTRAINT fk_blvd_mapping_user
                    FOREIGN KEY (updated_by)
                    REFERENCES users(id)
                    ON DELETE SET NULL

            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
        ");


        db()->exec("
            CREATE TABLE IF NOT EXISTS boulevard_mapping_suggestions (

                id BIGINT UNSIGNED
                    AUTO_INCREMENT PRIMARY KEY,

                business_id BIGINT UNSIGNED NOT NULL,

                report_type_id BIGINT UNSIGNED NOT NULL,

                suggested_report_id VARCHAR(220) NULL,

                suggested_report_name VARCHAR(255) NULL,

                confidence TINYINT UNSIGNED
                    NOT NULL DEFAULT 0,

                status ENUM(
                    'strong_match',
                    'likely_match',
                    'needs_review'
                ) NOT NULL DEFAULT 'needs_review',

                reason VARCHAR(500) NULL,

                created_by BIGINT UNSIGNED NULL,

                analyzed_at DATETIME NOT NULL,

                created_at TIMESTAMP
                    NOT NULL DEFAULT CURRENT_TIMESTAMP,

                UNIQUE KEY uq_blvd_suggestion_type (
                    business_id,
                    report_type_id
                ),

                KEY idx_blvd_suggestion_business (
                    business_id,
                    confidence
                ),

                CONSTRAINT fk_blvd_suggestion_business
                    FOREIGN KEY (business_id)
                    REFERENCES businesses(id)
                    ON DELETE CASCADE,

                CONSTRAINT fk_blvd_suggestion_type
                    FOREIGN KEY (report_type_id)
                    REFERENCES report_types(id)
                    ON DELETE CASCADE,

                CONSTRAINT fk_blvd_suggestion_user
                    FOREIGN KEY (created_by)
                    REFERENCES users(id)
                    ON DELETE SET NULL

            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
        ");


        db()->exec("
            CREATE TABLE IF NOT EXISTS boulevard_mapping_verifications (

                id BIGINT UNSIGNED
                    AUTO_INCREMENT PRIMARY KEY,

                business_id BIGINT UNSIGNED NOT NULL,

                report_type_id BIGINT UNSIGNED NOT NULL,

                report_url VARCHAR(1000) NOT NULL,

                template_slug VARCHAR(255) NULL,

                sample_filename VARCHAR(255) NULL,

                detected_headers_json LONGTEXT NULL,

                matched_headers_json LONGTEXT NULL,

                missing_headers_json LONGTEXT NULL,

                extra_headers_json LONGTEXT NULL,

                compatibility_score TINYINT UNSIGNED
                    NOT NULL DEFAULT 0,

                status ENUM(
                    'verified',
                    'partial',
                    'failed'
                ) NOT NULL DEFAULT 'failed',

                candidate_reports_json LONGTEXT NULL,

                selected_report_id VARCHAR(220) NULL,

                selected_report_name VARCHAR(255) NULL,

                verified_by BIGINT UNSIGNED NULL,

                verified_at DATETIME NULL,

                created_at DATETIME
                    NOT NULL DEFAULT CURRENT_TIMESTAMP,

                updated_at TIMESTAMP
                    NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,

                UNIQUE KEY uq_blvd_verification_type (
                    business_id,
                    report_type_id
                ),

                KEY idx_blvd_verification_business (
                    business_id,
                    status
                ),

                CONSTRAINT fk_blvd_verification_business
                    FOREIGN KEY (business_id)
                    REFERENCES businesses(id)
                    ON DELETE CASCADE,

                CONSTRAINT fk_blvd_verification_type
                    FOREIGN KEY (report_type_id)
                    REFERENCES report_types(id)
                    ON DELETE CASCADE,

                CONSTRAINT fk_blvd_verification_user
                    FOREIGN KEY (verified_by)
                    REFERENCES users(id)
                    ON DELETE SET NULL

            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
        ");


        $mappingDateFilterColumn = db()->query(
            "SHOW COLUMNS
             FROM boulevard_report_mappings
             LIKE 'date_filter_attribute'"
        )->fetch();


        if (!$mappingDateFilterColumn) {

            db()->exec(
                "ALTER TABLE boulevard_report_mappings
                 ADD COLUMN date_filter_attribute
                 VARCHAR(255) NULL
                 AFTER available_filters_json"
            );
        }


        db()->exec("
            CREATE TABLE IF NOT EXISTS boulevard_sync_runs (

                id BIGINT UNSIGNED
                    AUTO_INCREMENT PRIMARY KEY,

                business_id BIGINT UNSIGNED NOT NULL,

                period_start DATE NOT NULL,

                period_end DATE NOT NULL,

                frequency ENUM(
                    'weekly',
                    'monthly',
                    'quarterly',
                    'yearly',
                    'custom'
                ) NOT NULL DEFAULT 'weekly',

                status ENUM(
                    'queued',
                    'requesting',
                    'waiting',
                    'processing',
                    'completed',
                    'partial',
                    'failed'
                ) NOT NULL DEFAULT 'queued',

                requested_count SMALLINT UNSIGNED
                    NOT NULL DEFAULT 0,

                completed_count SMALLINT UNSIGNED
                    NOT NULL DEFAULT 0,

                failed_count SMALLINT UNSIGNED
                    NOT NULL DEFAULT 0,

                started_by BIGINT UNSIGNED NULL,

                started_at DATETIME NULL,

                completed_at DATETIME NULL,

                error_message TEXT NULL,

                created_at DATETIME
                    NOT NULL DEFAULT CURRENT_TIMESTAMP,

                KEY idx_blvd_sync_business_period (
                    business_id,
                    period_end,
                    status
                ),

                CONSTRAINT fk_blvd_sync_business
                    FOREIGN KEY (business_id)
                    REFERENCES businesses(id)
                    ON DELETE CASCADE,

                CONSTRAINT fk_blvd_sync_user
                    FOREIGN KEY (started_by)
                    REFERENCES users(id)
                    ON DELETE SET NULL

            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
        ");


        $syncRunColumns = [

            'upload_batch_id' =>
                'BIGINT UNSIGNED NULL AFTER failed_count',

            'last_checked_at' =>
                'DATETIME NULL AFTER completed_at',

            'status_message' =>
                'VARCHAR(1000) NULL AFTER error_message',

        ];


        foreach (
            $syncRunColumns
            as $column => $definition
        ) {

            $exists = db()->query(
                "SHOW COLUMNS FROM boulevard_sync_runs LIKE "
                . db()->quote($column)
            )->fetch();

            if (!$exists) {

                db()->exec(
                    "ALTER TABLE boulevard_sync_runs
                     ADD COLUMN {$column} {$definition}"
                );
            }
        }


        db()->exec("
            CREATE TABLE IF NOT EXISTS boulevard_sync_items (

                id BIGINT UNSIGNED
                    AUTO_INCREMENT PRIMARY KEY,

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

                status ENUM(
                    'queued',
                    'requested',
                    'waiting',
                    'downloading',
                    'downloaded',
                    'validated',
                    'processed',
                    'failed'
                ) NOT NULL DEFAULT 'queued',

                attempt_count SMALLINT UNSIGNED
                    NOT NULL DEFAULT 0,

                local_path VARCHAR(700) NULL,

                header_score TINYINT UNSIGNED NULL,

                row_count INT UNSIGNED
                    NOT NULL DEFAULT 0,

                warning_count INT UNSIGNED
                    NOT NULL DEFAULT 0,

                error_message TEXT NULL,

                requested_at DATETIME NULL,

                downloaded_at DATETIME NULL,

                processed_at DATETIME NULL,

                created_at DATETIME
                    NOT NULL DEFAULT CURRENT_TIMESTAMP,

                updated_at TIMESTAMP
                    NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,

                UNIQUE KEY uq_blvd_sync_item_type (
                    sync_run_id,
                    report_type_id
                ),

                KEY idx_blvd_sync_item_status (
                    sync_run_id,
                    status
                ),

                CONSTRAINT fk_blvd_sync_item_run
                    FOREIGN KEY (sync_run_id)
                    REFERENCES boulevard_sync_runs(id)
                    ON DELETE CASCADE,

                CONSTRAINT fk_blvd_sync_item_business
                    FOREIGN KEY (business_id)
                    REFERENCES businesses(id)
                    ON DELETE CASCADE,

                CONSTRAINT fk_blvd_sync_item_type
                    FOREIGN KEY (report_type_id)
                    REFERENCES report_types(id)
                    ON DELETE CASCADE

            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
        ");


        /*
         * ============================================================
         * Boulevard fail-safe sync engine v1.2.0
         * ============================================================
         */

        db()->exec("
            ALTER TABLE boulevard_sync_runs
            MODIFY COLUMN status ENUM(
                'queued',
                'preflight',
                'requesting',
                'waiting',
                'running',
                'processing',
                'completed',
                'partial',
                'needs_attention',
                'failed',
                'cancelled'
            ) NOT NULL DEFAULT 'queued'
        ");


        $syncRunV120Columns = [

            'preflight_json' =>
                'LONGTEXT NULL AFTER status_message',

            'reconciliation_json' =>
                'LONGTEXT NULL AFTER preflight_json',

            'worker_lock_token' =>
                'VARCHAR(80) NULL AFTER reconciliation_json',

            'worker_locked_at' =>
                'DATETIME NULL AFTER worker_lock_token',

            'last_heartbeat_at' =>
                'DATETIME NULL AFTER worker_locked_at',

            'next_worker_at' =>
                'DATETIME NULL AFTER last_heartbeat_at',

        ];


        foreach (
            $syncRunV120Columns
            as $column => $definition
        ) {

            $exists = db()->query(
                "SHOW COLUMNS FROM boulevard_sync_runs LIKE "
                . db()->quote($column)
            )->fetch();

            if (!$exists) {

                db()->exec(
                    "ALTER TABLE boulevard_sync_runs
                     ADD COLUMN {$column} {$definition}"
                );
            }
        }


        db()->exec("
            ALTER TABLE boulevard_sync_items
            MODIFY COLUMN status ENUM(
                'queued',
                'requesting',
                'requested',
                'accepted',
                'generating',
                'waiting',
                'retry_scheduled',
                'downloading',
                'validating',
                'validated',
                'downloaded',
                'processing',
                'processed',
                'completed',
                'needs_attention',
                'timed_out',
                'failed'
            ) NOT NULL DEFAULT 'queued'
        ");


        $syncItemV120Columns = [

            'provider_check_count' =>
                'SMALLINT UNSIGNED NOT NULL DEFAULT 0
                 AFTER attempt_count',

            'max_attempts' =>
                'SMALLINT UNSIGNED NOT NULL DEFAULT 5
                 AFTER provider_check_count',

            'provider_payload_json' =>
                'LONGTEXT NULL AFTER max_attempts',

            'validation_json' =>
                'LONGTEXT NULL AFTER provider_payload_json',

            'checksum_sha256' =>
                'CHAR(64) NULL AFTER validation_json',

            'failure_code' =>
                'VARCHAR(80) NULL AFTER error_message',

            'last_http_status' =>
                'SMALLINT UNSIGNED NULL AFTER failure_code',

            'last_provider_check_at' =>
                'DATETIME NULL AFTER last_http_status',

            'next_attempt_at' =>
                'DATETIME NULL AFTER last_provider_check_at',

            'last_error_at' =>
                'DATETIME NULL AFTER next_attempt_at',

            'webhook_received_at' =>
                'DATETIME NULL AFTER last_error_at',

            'completion_source' =>
                "ENUM(
                    'webhook',
                    'poll',
                    'download_probe',
                    'manual'
                 ) NULL AFTER webhook_received_at",

        ];


        foreach (
            $syncItemV120Columns
            as $column => $definition
        ) {

            $exists = db()->query(
                "SHOW COLUMNS FROM boulevard_sync_items LIKE "
                . db()->quote($column)
            )->fetch();

            if (!$exists) {

                db()->exec(
                    "ALTER TABLE boulevard_sync_items
                     ADD COLUMN {$column} {$definition}"
                );
            }
        }


        db()->exec("
            CREATE TABLE IF NOT EXISTS boulevard_webhook_events (

                id BIGINT UNSIGNED
                    AUTO_INCREMENT PRIMARY KEY,

                business_id BIGINT UNSIGNED NOT NULL,

                idempotency_key VARCHAR(190) NOT NULL,

                event_type VARCHAR(100) NOT NULL,

                payload_json LONGTEXT NOT NULL,

                received_at DATETIME
                    NOT NULL DEFAULT CURRENT_TIMESTAMP,

                processed_at DATETIME NULL,

                UNIQUE KEY uq_blvd_webhook_event (
                    business_id,
                    idempotency_key
                ),

                KEY idx_blvd_webhook_received (
                    business_id,
                    received_at
                ),

                CONSTRAINT fk_blvd_webhook_business
                    FOREIGN KEY (business_id)
                    REFERENCES businesses(id)
                    ON DELETE CASCADE

            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
        ");


        /*
         * ============================================================
         * Boulevard admin approval / simplified business runner v1.2.2
         * ============================================================
         */

        $blvdAccessColumns = [

            'business_user_run_enabled' =>
                'TINYINT(1) NOT NULL DEFAULT 0
                 AFTER available_reports_json',

            'business_user_enabled_by' =>
                'BIGINT UNSIGNED NULL
                 AFTER business_user_run_enabled',

            'business_user_enabled_at' =>
                'DATETIME NULL
                 AFTER business_user_enabled_by',

        ];


        foreach (
            $blvdAccessColumns
            as $column => $definition
        ) {

            $exists = db()->query(
                "SHOW COLUMNS FROM boulevard_connections LIKE "
                . db()->quote($column)
            )->fetch();

            if (!$exists) {

                db()->exec(
                    "ALTER TABLE boulevard_connections
                     ADD COLUMN {$column} {$definition}"
                );
            }
        }


        $syncRunV122Columns = [

            'run_mode' =>
                "ENUM(
                    'full',
                    'diagnostic'
                 ) NOT NULL DEFAULT 'full'
                 AFTER frequency",

            'diagnostic_report_type_id' =>
                'BIGINT UNSIGNED NULL AFTER run_mode',

            'diagnostic_variant' =>
                'VARCHAR(40) NULL
                 AFTER diagnostic_report_type_id',

        ];


        foreach (
            $syncRunV122Columns
            as $column => $definition
        ) {

            $exists = db()->query(
                "SHOW COLUMNS FROM boulevard_sync_runs LIKE "
                . db()->quote($column)
            )->fetch();

            if (!$exists) {

                db()->exec(
                    "ALTER TABLE boulevard_sync_runs
                     ADD COLUMN {$column} {$definition}"
                );
            }
        }


        /*
         * ============================================================
         * v1.5.6 — ON-DEMAND AI REVIEWED REPORTS
         * ============================================================
         *
         * The original report remains immutable.
         * One cached AI review is stored per report identity
         * and source hash.
         */

        db()->exec("
            CREATE TABLE IF NOT EXISTS ai_report_reviews (

                id BIGINT UNSIGNED
                    AUTO_INCREMENT PRIMARY KEY,

                business_id BIGINT UNSIGNED
                    NOT NULL,

                report_type ENUM(
                    'unified',
                    'boulevard',
                    'gbp'
                ) NOT NULL,

                report_key VARCHAR(255)
                    NOT NULL,

                source_report_id BIGINT UNSIGNED
                    NULL,

                period_start DATE
                    NOT NULL,

                period_end DATE
                    NOT NULL,

                frequency ENUM(
                    'weekly',
                    'monthly',
                    'quarterly',
                    'yearly',
                    'custom'
                ) NOT NULL DEFAULT 'weekly',

                source_hash CHAR(64)
                    NOT NULL,

                normalized_json LONGTEXT
                    NOT NULL,

                review_json LONGTEXT
                    NULL,

                status ENUM(
                    'pending',
                    'completed',
                    'failed'
                ) NOT NULL DEFAULT 'pending',

                model VARCHAR(100)
                    NULL,

                prompt_version VARCHAR(30)
                    NOT NULL DEFAULT '1.0',

                requested_by BIGINT UNSIGNED
                    NULL,

                requested_at DATETIME
                    NOT NULL DEFAULT CURRENT_TIMESTAMP,

                completed_at DATETIME
                    NULL,

                last_error VARCHAR(1000)
                    NULL,

                created_at DATETIME
                    NOT NULL DEFAULT CURRENT_TIMESTAMP,

                updated_at TIMESTAMP
                    NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,

                UNIQUE KEY uq_ai_report_review_identity (
                    business_id,
                    report_type,
                    report_key
                ),

                KEY idx_ai_report_review_business_status (
                    business_id,
                    status,
                    completed_at
                ),

                CONSTRAINT fk_ai_report_review_business
                    FOREIGN KEY (business_id)
                    REFERENCES businesses(id)
                    ON DELETE CASCADE,

                CONSTRAINT fk_ai_report_review_user
                    FOREIGN KEY (requested_by)
                    REFERENCES users(id)
                    ON DELETE SET NULL

            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
        ");


        /*
         * ============================================================
         * v1.5.7 — SMART SEARCH AI FALLBACK CACHE
         * ============================================================
         *
         * Cache stores only normalized intent/context metadata.
         * It never stores API keys or report/business metric secrets.
         */

        db()->exec("
            CREATE TABLE IF NOT EXISTS smart_search_cache (

                id BIGINT UNSIGNED
                    AUTO_INCREMENT PRIMARY KEY,

                cache_hash CHAR(64)
                    NOT NULL,

                intent_hash CHAR(64)
                    NOT NULL,

                context_key CHAR(64)
                    NOT NULL,

                candidate_signature CHAR(64)
                    NOT NULL,

                result_topic_id VARCHAR(120)
                    NOT NULL,

                model VARCHAR(100)
                    NULL,

                response_json TEXT
                    NULL,

                hit_count INT UNSIGNED
                    NOT NULL DEFAULT 0,

                last_used_at DATETIME
                    NULL,

                created_at DATETIME
                    NOT NULL DEFAULT CURRENT_TIMESTAMP,

                updated_at TIMESTAMP
                    NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,

                UNIQUE KEY uq_smart_search_cache_hash (
                    cache_hash
                ),

                KEY idx_smart_search_intent (
                    intent_hash,
                    last_used_at
                )

            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
        ");


        /*
         * ============================================================
         * PROVIDER KPI DASHBOARD PHASE 1 v1.3.0
         * ============================================================
         */

        $providerRoleColumn = db()->query(
            "SHOW COLUMNS
             FROM users
             LIKE 'provider_kpi_role'"
        )->fetch();


        if (!$providerRoleColumn) {

            db()->exec(
                "ALTER TABLE users
                 ADD COLUMN provider_kpi_role
                 ENUM(
                    'none',
                    'leadership',
                    'provider',
                    'data_uploader'
                 )
                 NOT NULL DEFAULT 'none'
                 AFTER role"
            );
        }


        db()->exec("
            CREATE TABLE IF NOT EXISTS provider_kpi_settings (

                business_id BIGINT UNSIGNED
                    PRIMARY KEY,

                module_name VARCHAR(160)
                    NOT NULL DEFAULT
                    'Provider KPI Dashboard',

                enabled TINYINT(1)
                    NOT NULL DEFAULT 0,

                updated_by BIGINT UNSIGNED
                    NULL,

                updated_at TIMESTAMP
                    NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,

                CONSTRAINT fk_provider_kpi_settings_business
                    FOREIGN KEY (business_id)
                    REFERENCES businesses(id)
                    ON DELETE CASCADE,

                CONSTRAINT fk_provider_kpi_settings_user
                    FOREIGN KEY (updated_by)
                    REFERENCES users(id)
                    ON DELETE SET NULL

            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
        ");


        db()->exec("
            INSERT IGNORE INTO provider_kpi_settings
            (
                business_id,
                module_name,
                enabled
            )
            SELECT
                id,
                'Provider KPI Dashboard',
                0
            FROM businesses
        ");


        db()->exec("
            CREATE TABLE IF NOT EXISTS provider_profiles (

                id BIGINT UNSIGNED
                    AUTO_INCREMENT PRIMARY KEY,

                business_id BIGINT UNSIGNED
                    NOT NULL,

                linked_user_id BIGINT UNSIGNED
                    NULL,

                name VARCHAR(160)
                    NOT NULL,

                normalized_name VARCHAR(190)
                    NOT NULL,

                email VARCHAR(190)
                    NULL,

                provider_type VARCHAR(100)
                    NULL,

                department VARCHAR(120)
                    NULL,

                status ENUM(
                    'active',
                    'inactive'
                ) NOT NULL DEFAULT 'active',

                display_order SMALLINT UNSIGNED
                    NOT NULL DEFAULT 0,

                created_by BIGINT UNSIGNED
                    NULL,

                created_at DATETIME
                    NOT NULL DEFAULT CURRENT_TIMESTAMP,

                updated_at TIMESTAMP
                    NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,

                UNIQUE KEY uq_provider_business_name (
                    business_id,
                    normalized_name
                ),

                UNIQUE KEY uq_provider_linked_user (
                    linked_user_id
                ),

                KEY idx_provider_business_status (
                    business_id,
                    status,
                    display_order
                ),

                CONSTRAINT fk_provider_business
                    FOREIGN KEY (business_id)
                    REFERENCES businesses(id)
                    ON DELETE CASCADE,

                CONSTRAINT fk_provider_linked_user
                    FOREIGN KEY (linked_user_id)
                    REFERENCES users(id)
                    ON DELETE SET NULL,

                CONSTRAINT fk_provider_created_user
                    FOREIGN KEY (created_by)
                    REFERENCES users(id)
                    ON DELETE SET NULL

            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
        ");


        db()->exec("
            CREATE TABLE IF NOT EXISTS provider_kpi_definitions (

                id BIGINT UNSIGNED
                    AUTO_INCREMENT PRIMARY KEY,

                code VARCHAR(120)
                    NOT NULL UNIQUE,

                name VARCHAR(180)
                    NOT NULL,

                category VARCHAR(60)
                    NOT NULL,

                category_sort SMALLINT UNSIGNED
                    NOT NULL DEFAULT 0,

                format ENUM(
                    'number',
                    'currency',
                    'percent',
                    'hours'
                ) NOT NULL DEFAULT 'number',

                aggregation ENUM(
                    'sum',
                    'average',
                    'derived'
                ) NOT NULL DEFAULT 'sum',

                formula_key VARCHAR(120)
                    NULL,

                higher_is_better TINYINT(1)
                    NOT NULL DEFAULT 1,

                goal_enabled TINYINT(1)
                    NOT NULL DEFAULT 1,

                importable TINYINT(1)
                    NOT NULL DEFAULT 1,

                show_on_scorecard TINYINT(1)
                    NOT NULL DEFAULT 1,

                sort_order SMALLINT UNSIGNED
                    NOT NULL DEFAULT 0,

                status ENUM(
                    'active',
                    'inactive'
                ) NOT NULL DEFAULT 'active',

                created_at DATETIME
                    NOT NULL DEFAULT CURRENT_TIMESTAMP,

                updated_at TIMESTAMP
                    NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,

                KEY idx_provider_kpi_sort (
                    category_sort,
                    sort_order,
                    status
                )

            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
        ");


        $providerKpis = [

            [
                'total_production',
                'Total Production',
                'production',
                10,
                'currency',
                'sum',
                null,
                1,
                1,
                1,
                1,
                10,
            ],

            [
                'total_collections',
                'Total Collections',
                'production',
                10,
                'currency',
                'sum',
                null,
                1,
                1,
                1,
                1,
                20,
            ],

            [
                'total_services_performed',
                'Total Services Performed',
                'production',
                10,
                'number',
                'sum',
                null,
                1,
                1,
                1,
                1,
                30,
            ],

            [
                'total_retail_sales',
                'Total Retail Sales',
                'production',
                10,
                'currency',
                'sum',
                null,
                1,
                1,
                1,
                1,
                40,
            ],

            [
                'membership_sales',
                'Membership Sales',
                'production',
                10,
                'number',
                'sum',
                null,
                1,
                1,
                1,
                1,
                50,
            ],

            [
                'package_sales',
                'Package Sales',
                'production',
                10,
                'number',
                'sum',
                null,
                1,
                1,
                1,
                1,
                60,
            ],

            [
                'total_revenue',
                'Total Revenue',
                'production',
                10,
                'currency',
                'sum',
                null,
                1,
                1,
                1,
                1,
                70,
            ],

            [
                'scheduled_hours',
                'Scheduled Hours',
                'capacity',
                20,
                'hours',
                'sum',
                null,
                1,
                1,
                1,
                1,
                10,
            ],

            [
                'available_clinical_hours',
                'Available Clinical Hours',
                'capacity',
                20,
                'hours',
                'sum',
                null,
                1,
                1,
                1,
                1,
                20,
            ],

            [
                'productive_hours',
                'Productive Hours',
                'capacity',
                20,
                'hours',
                'sum',
                null,
                1,
                1,
                1,
                1,
                30,
            ],

            [
                'revenue_producing_hours',
                'Revenue-Producing Hours',
                'capacity',
                20,
                'hours',
                'sum',
                null,
                1,
                1,
                1,
                1,
                40,
            ],

            [
                'utilization_rate',
                'Utilization Rate',
                'capacity',
                20,
                'percent',
                'derived',
                'utilization_rate',
                1,
                1,
                1,
                1,
                50,
            ],

            [
                'open_hours',
                'Open Hours',
                'capacity',
                20,
                'hours',
                'derived',
                'open_hours',
                0,
                0,
                1,
                1,
                60,
            ],

            [
                'unsold_hours',
                'Unsold Hours',
                'capacity',
                20,
                'hours',
                'derived',
                'unsold_hours',
                0,
                0,
                1,
                1,
                70,
            ],

            [
                'remaining_appointment_capacity',
                'Remaining Appointment Capacity',
                'capacity',
                20,
                'number',
                'sum',
                null,
                0,
                0,
                1,
                1,
                80,
            ],

            [
                'revenue_per_hour',
                'Revenue Per Hour',
                'productivity',
                30,
                'currency',
                'derived',
                'revenue_per_hour',
                1,
                1,
                1,
                1,
                10,
            ],

            [
                'revenue_per_visit',
                'Revenue Per Visit',
                'productivity',
                30,
                'currency',
                'derived',
                'revenue_per_visit',
                1,
                1,
                1,
                1,
                20,
            ],

            [
                'average_ticket',
                'Average Ticket',
                'productivity',
                30,
                'currency',
                'derived',
                'average_ticket',
                1,
                1,
                1,
                1,
                30,
            ],

            [
                'average_service_revenue',
                'Average Service Revenue',
                'productivity',
                30,
                'currency',
                'derived',
                'average_service_revenue',
                1,
                1,
                1,
                1,
                40,
            ],

            [
                'retail_revenue_per_patient',
                'Retail Revenue Per Patient',
                'productivity',
                30,
                'currency',
                'derived',
                'retail_revenue_per_patient',
                1,
                1,
                1,
                1,
                50,
            ],

            [
                'retail_attachment_rate',
                'Retail Attachment Rate',
                'productivity',
                30,
                'percent',
                'average',
                null,
                1,
                1,
                1,
                1,
                60,
            ],

            [
                'membership_conversion_rate',
                'Membership Conversion Rate',
                'productivity',
                30,
                'percent',
                'derived',
                'membership_conversion_rate',
                1,
                1,
                1,
                1,
                70,
            ],

            [
                'package_conversion_rate',
                'Package Conversion Rate',
                'productivity',
                30,
                'percent',
                'derived',
                'package_conversion_rate',
                1,
                1,
                1,
                1,
                80,
            ],

            [
                'total_patients_seen',
                'Total Patients Seen',
                'patients',
                40,
                'number',
                'sum',
                null,
                1,
                1,
                1,
                1,
                10,
            ],

            [
                'new_patients',
                'New Patients',
                'patients',
                40,
                'number',
                'sum',
                null,
                1,
                1,
                1,
                1,
                20,
            ],

            [
                'returning_patients',
                'Returning Patients',
                'patients',
                40,
                'number',
                'sum',
                null,
                1,
                1,
                1,
                1,
                30,
            ],

            [
                'consultations_completed',
                'Consultations Completed',
                'patients',
                40,
                'number',
                'sum',
                null,
                1,
                1,
                1,
                1,
                40,
            ],

            [
                'consultations_converted',
                'Consultations Converted',
                'patients',
                40,
                'number',
                'sum',
                null,
                1,
                0,
                1,
                0,
                45,
            ],

            [
                'consultation_conversion_rate',
                'Consultation Conversion Rate',
                'patients',
                40,
                'percent',
                'derived',
                'consultation_conversion_rate',
                1,
                1,
                1,
                1,
                50,
            ],

            [
                'new_patient_leads',
                'New Patient Leads',
                'patients',
                40,
                'number',
                'sum',
                null,
                1,
                0,
                1,
                0,
                52,
            ],

            [
                'new_patient_conversions',
                'New Patient Conversions',
                'patients',
                40,
                'number',
                'sum',
                null,
                1,
                0,
                1,
                0,
                54,
            ],

            [
                'new_patient_conversion_rate',
                'New Patient Conversion Rate',
                'patients',
                40,
                'percent',
                'derived',
                'new_patient_conversion_rate',
                1,
                1,
                1,
                1,
                56,
            ],

            [
                'rebooking_rate',
                'Rebooking Rate',
                'patients',
                40,
                'percent',
                'average',
                null,
                1,
                1,
                1,
                1,
                60,
            ],

            [
                'follow_up_rate',
                'Follow-Up Rate',
                'patients',
                40,
                'percent',
                'average',
                null,
                1,
                1,
                1,
                1,
                70,
            ],

        ];


        $kpiInsert = db()->prepare(
            "INSERT INTO provider_kpi_definitions
            (
                code,
                name,
                category,
                category_sort,
                format,
                aggregation,
                formula_key,
                higher_is_better,
                goal_enabled,
                importable,
                show_on_scorecard,
                sort_order,
                status
            )
            VALUES
            (
                ?,?,?,?,?,?,?,?,?,?,?,?,
                'active'
            )
            ON DUPLICATE KEY UPDATE

                name=VALUES(name),

                category=VALUES(category),

                category_sort=
                    VALUES(category_sort),

                format=VALUES(format),

                aggregation=
                    VALUES(aggregation),

                formula_key=
                    VALUES(formula_key),

                higher_is_better=
                    VALUES(higher_is_better),

                goal_enabled=
                    VALUES(goal_enabled),

                importable=
                    VALUES(importable),

                show_on_scorecard=
                    VALUES(show_on_scorecard),

                sort_order=
                    VALUES(sort_order)"
        );


        foreach (
            $providerKpis
            as $kpi
        ) {

            $kpiInsert->execute($kpi);
        }


        db()->exec("
            CREATE TABLE IF NOT EXISTS provider_kpi_imports (

                id BIGINT UNSIGNED
                    AUTO_INCREMENT PRIMARY KEY,

                business_id BIGINT UNSIGNED
                    NOT NULL,

                period_month DATE
                    NOT NULL,

                original_filename VARCHAR(255)
                    NOT NULL,

                checksum_sha256 CHAR(64)
                    NOT NULL,

                status ENUM(
                    'preview',
                    'completed',
                    'failed',
                    'cancelled'
                ) NOT NULL DEFAULT 'preview',

                row_count INT UNSIGNED
                    NOT NULL DEFAULT 0,

                error_count INT UNSIGNED
                    NOT NULL DEFAULT 0,

                warning_count INT UNSIGNED
                    NOT NULL DEFAULT 0,

                preview_json LONGTEXT
                    NULL,

                summary_json LONGTEXT
                    NULL,

                uploaded_by BIGINT UNSIGNED
                    NOT NULL,

                confirmed_by BIGINT UNSIGNED
                    NULL,

                created_at DATETIME
                    NOT NULL DEFAULT CURRENT_TIMESTAMP,

                completed_at DATETIME
                    NULL,

                KEY idx_provider_import_business_month (
                    business_id,
                    period_month,
                    status
                ),

                KEY idx_provider_import_checksum (
                    business_id,
                    checksum_sha256
                ),

                CONSTRAINT fk_provider_import_business
                    FOREIGN KEY (business_id)
                    REFERENCES businesses(id)
                    ON DELETE CASCADE,

                CONSTRAINT fk_provider_import_uploaded_user
                    FOREIGN KEY (uploaded_by)
                    REFERENCES users(id)
                    ON DELETE RESTRICT,

                CONSTRAINT fk_provider_import_confirmed_user
                    FOREIGN KEY (confirmed_by)
                    REFERENCES users(id)
                    ON DELETE SET NULL

            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
        ");


        db()->exec("
            CREATE TABLE IF NOT EXISTS provider_kpi_goals (

                id BIGINT UNSIGNED
                    AUTO_INCREMENT PRIMARY KEY,

                business_id BIGINT UNSIGNED
                    NOT NULL,

                provider_id BIGINT UNSIGNED
                    NOT NULL,

                kpi_definition_id BIGINT UNSIGNED
                    NOT NULL,

                period_month DATE
                    NOT NULL,

                goal_value DECIMAL(20,4)
                    NOT NULL,

                set_by BIGINT UNSIGNED
                    NULL,

                created_at DATETIME
                    NOT NULL DEFAULT CURRENT_TIMESTAMP,

                updated_at TIMESTAMP
                    NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,

                UNIQUE KEY uq_provider_goal_month (
                    provider_id,
                    kpi_definition_id,
                    period_month
                ),

                KEY idx_provider_goal_business_month (
                    business_id,
                    period_month
                ),

                CONSTRAINT fk_provider_goal_business
                    FOREIGN KEY (business_id)
                    REFERENCES businesses(id)
                    ON DELETE CASCADE,

                CONSTRAINT fk_provider_goal_provider
                    FOREIGN KEY (provider_id)
                    REFERENCES provider_profiles(id)
                    ON DELETE CASCADE,

                CONSTRAINT fk_provider_goal_definition
                    FOREIGN KEY (kpi_definition_id)
                    REFERENCES provider_kpi_definitions(id)
                    ON DELETE CASCADE,

                CONSTRAINT fk_provider_goal_user
                    FOREIGN KEY (set_by)
                    REFERENCES users(id)
                    ON DELETE SET NULL

            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
        ");


        db()->exec("
            CREATE TABLE IF NOT EXISTS provider_kpi_values (

                id BIGINT UNSIGNED
                    AUTO_INCREMENT PRIMARY KEY,

                business_id BIGINT UNSIGNED
                    NOT NULL,

                provider_id BIGINT UNSIGNED
                    NOT NULL,

                kpi_definition_id BIGINT UNSIGNED
                    NOT NULL,

                period_month DATE
                    NOT NULL,

                actual_value DECIMAL(20,4)
                    NULL,

                source_type ENUM(
                    'csv',
                    'manual',
                    'api'
                ) NOT NULL DEFAULT 'csv',

                import_id BIGINT UNSIGNED
                    NULL,

                entered_by BIGINT UNSIGNED
                    NULL,

                created_at DATETIME
                    NOT NULL DEFAULT CURRENT_TIMESTAMP,

                updated_at TIMESTAMP
                    NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,

                UNIQUE KEY uq_provider_value_month (
                    provider_id,
                    kpi_definition_id,
                    period_month
                ),

                KEY idx_provider_value_business_month (
                    business_id,
                    period_month
                ),

                CONSTRAINT fk_provider_value_business
                    FOREIGN KEY (business_id)
                    REFERENCES businesses(id)
                    ON DELETE CASCADE,

                CONSTRAINT fk_provider_value_provider
                    FOREIGN KEY (provider_id)
                    REFERENCES provider_profiles(id)
                    ON DELETE CASCADE,

                CONSTRAINT fk_provider_value_definition
                    FOREIGN KEY (kpi_definition_id)
                    REFERENCES provider_kpi_definitions(id)
                    ON DELETE CASCADE,

                CONSTRAINT fk_provider_value_import
                    FOREIGN KEY (import_id)
                    REFERENCES provider_kpi_imports(id)
                    ON DELETE SET NULL,

                CONSTRAINT fk_provider_value_user
                    FOREIGN KEY (entered_by)
                    REFERENCES users(id)
                    ON DELETE SET NULL

            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
        ");


        /*
         * ============================================================
         * PROVIDER KPI DASHBOARD PHASE 2 v1.4.0
         * ============================================================
         */

        db()->exec("
            CREATE TABLE IF NOT EXISTS provider_kpi_reviews (

                id BIGINT UNSIGNED
                    AUTO_INCREMENT PRIMARY KEY,

                business_id BIGINT UNSIGNED
                    NOT NULL,

                provider_id BIGINT UNSIGNED
                    NOT NULL,

                period_month DATE
                    NOT NULL,

                review_date DATE
                    NULL,

                review_status ENUM(
                    'draft',
                    'completed'
                ) NOT NULL DEFAULT 'draft',

                summary TEXT NULL,

                wins TEXT NULL,

                risks TEXT NULL,

                opportunities TEXT NULL,

                next_review_date DATE NULL,

                created_by BIGINT UNSIGNED NULL,

                updated_by BIGINT UNSIGNED NULL,

                created_at DATETIME
                    NOT NULL DEFAULT CURRENT_TIMESTAMP,

                updated_at TIMESTAMP
                    NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,

                UNIQUE KEY uq_provider_review_month (
                    provider_id,
                    period_month
                ),

                KEY idx_provider_review_business_month (
                    business_id,
                    period_month,
                    review_status
                ),

                CONSTRAINT fk_provider_review_business
                    FOREIGN KEY (business_id)
                    REFERENCES businesses(id)
                    ON DELETE CASCADE,

                CONSTRAINT fk_provider_review_provider
                    FOREIGN KEY (provider_id)
                    REFERENCES provider_profiles(id)
                    ON DELETE CASCADE,

                CONSTRAINT fk_provider_review_created_user
                    FOREIGN KEY (created_by)
                    REFERENCES users(id)
                    ON DELETE SET NULL,

                CONSTRAINT fk_provider_review_updated_user
                    FOREIGN KEY (updated_by)
                    REFERENCES users(id)
                    ON DELETE SET NULL

            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
        ");


        db()->exec("
            CREATE TABLE IF NOT EXISTS provider_kpi_actions (

                id BIGINT UNSIGNED
                    AUTO_INCREMENT PRIMARY KEY,

                business_id BIGINT UNSIGNED
                    NOT NULL,

                provider_id BIGINT UNSIGNED
                    NOT NULL,

                review_id BIGINT UNSIGNED
                    NOT NULL,

                title VARCHAR(200)
                    NOT NULL,

                details TEXT NULL,

                priority ENUM(
                    'low',
                    'medium',
                    'high'
                ) NOT NULL DEFAULT 'medium',

                status ENUM(
                    'open',
                    'in_progress',
                    'completed',
                    'cancelled'
                ) NOT NULL DEFAULT 'open',

                assigned_to_user_id BIGINT UNSIGNED
                    NULL,

                due_date DATE NULL,

                completed_at DATETIME NULL,

                created_by BIGINT UNSIGNED NULL,

                created_at DATETIME
                    NOT NULL DEFAULT CURRENT_TIMESTAMP,

                updated_at TIMESTAMP
                    NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,

                KEY idx_provider_action_review (
                    review_id,
                    status,
                    due_date
                ),

                KEY idx_provider_action_business_status (
                    business_id,
                    status,
                    due_date
                ),

                CONSTRAINT fk_provider_action_business
                    FOREIGN KEY (business_id)
                    REFERENCES businesses(id)
                    ON DELETE CASCADE,

                CONSTRAINT fk_provider_action_provider
                    FOREIGN KEY (provider_id)
                    REFERENCES provider_profiles(id)
                    ON DELETE CASCADE,

                CONSTRAINT fk_provider_action_review
                    FOREIGN KEY (review_id)
                    REFERENCES provider_kpi_reviews(id)
                    ON DELETE CASCADE,

                CONSTRAINT fk_provider_action_assigned_user
                    FOREIGN KEY (assigned_to_user_id)
                    REFERENCES users(id)
                    ON DELETE SET NULL,

                CONSTRAINT fk_provider_action_created_user
                    FOREIGN KEY (created_by)
                    REFERENCES users(id)
                    ON DELETE SET NULL

            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
        ");


        /*
         * ============================================================
         * PROVIDER KPI DASHBOARD PHASE 3 v1.5.0
         * ============================================================
         */

        $providerImportPhase3Columns = [

            'rollback_json' =>
                'LONGTEXT NULL AFTER summary_json',

            'rolled_back_at' =>
                'DATETIME NULL AFTER completed_at',

            'rolled_back_by' =>
                'BIGINT UNSIGNED NULL AFTER rolled_back_at',

            'rollback_message' =>
                'VARCHAR(500) NULL AFTER rolled_back_by',

        ];


        foreach (
            $providerImportPhase3Columns
            as $column => $definition
        ) {

            $exists = db()->query(
                "SHOW COLUMNS FROM provider_kpi_imports LIKE "
                . db()->quote($column)
            )->fetch();

            if (!$exists) {

                db()->exec(
                    "ALTER TABLE provider_kpi_imports
                     ADD COLUMN {$column} {$definition}"
                );
            }
        }


        /*
         * ============================================================
         * AESTHETIC INTEL v1.6.0 — BUSINESS FEATURE CONTROLS
         * ============================================================
         *
         * All existing businesses start with previously available
         * optional features enabled.
         *
         * Provider KPI keeps its existing provider_kpi_settings
         * activation state and is not duplicated here.
         */

        db()->exec("
            CREATE TABLE IF NOT EXISTS business_features (

                business_id BIGINT UNSIGNED
                    NOT NULL,

                feature_code VARCHAR(80)
                    NOT NULL,

                enabled TINYINT(1)
                    NOT NULL DEFAULT 1,

                updated_by BIGINT UNSIGNED
                    NULL,

                created_at DATETIME
                    NOT NULL DEFAULT CURRENT_TIMESTAMP,

                updated_at TIMESTAMP
                    NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,

                PRIMARY KEY (
                    business_id,
                    feature_code
                ),

                KEY idx_business_feature_enabled (
                    business_id,
                    enabled
                ),

                CONSTRAINT fk_business_feature_business
                    FOREIGN KEY (business_id)
                    REFERENCES businesses(id)
                    ON DELETE CASCADE,

                CONSTRAINT fk_business_feature_user
                    FOREIGN KEY (updated_by)
                    REFERENCES users(id)
                    ON DELETE SET NULL

            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
        ");


        $featureSeed = db()->prepare(
            "INSERT IGNORE INTO business_features
            (
                business_id,
                feature_code,
                enabled
            )
            SELECT
                id,
                ?,
                ?
            FROM businesses"
        );


        foreach (
            business_feature_definitions()
            as $featureCode => $featureDefinition
        ) {

            if (
                (
                    $featureDefinition['storage']
                    ?? ''
                )
                === 'provider_kpi_settings'
            ) {
                continue;
            }


            $featureSeed->execute([
                $featureCode,

                !empty(
                    $featureDefinition['default']
                )
                    ? 1
                    : 0,
            ]);
        }


        /*
         * ============================================================
         * AESTHETIC INTEL v1.6.1 — FEATURE AVAILABILITY
         * ============================================================
         *
         * active       = feature works normally
         * maintenance  = temporarily unavailable to business users
         * coming_soon  = upcoming feature / announcement
         *
         * business_id = 0 means the rule applies to all businesses.
         * A business-specific rule may override the global rule.
         */

        db()->exec("
            CREATE TABLE IF NOT EXISTS feature_availability (

                id BIGINT UNSIGNED
                    NOT NULL AUTO_INCREMENT,

                business_id BIGINT UNSIGNED
                    NOT NULL DEFAULT 0,

                feature_key VARCHAR(100)
                    NOT NULL,

                feature_name VARCHAR(160)
                    NOT NULL,

                route_prefixes TEXT NULL,

                status VARCHAR(20)
                    NOT NULL DEFAULT 'active',

                message VARCHAR(700)
                    NULL,

                eta_text VARCHAR(160)
                    NULL,

                show_announcement TINYINT(1)
                    NOT NULL DEFAULT 0,

                starts_at DATETIME
                    NULL,

                ends_at DATETIME
                    NULL,

                created_by BIGINT UNSIGNED
                    NULL,

                updated_by BIGINT UNSIGNED
                    NULL,

                created_at DATETIME
                    NOT NULL DEFAULT CURRENT_TIMESTAMP,

                updated_at DATETIME
                    NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,

                PRIMARY KEY (id),

                UNIQUE KEY
                    uniq_feature_availability_scope
                    (
                        business_id,
                        feature_key
                    ),

                KEY
                    idx_feature_availability_status
                    (
                        business_id,
                        status
                    ),

                KEY
                    idx_feature_availability_announcement
                    (
                        show_announcement,
                        status
                    )

            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
        ");


        /*
         * ============================================================
         * AESTHETIC INTEL v1.6.2
         * GEMINI AI WEEKLY REPORT
         * ============================================================
         *
         * Main design:
         *
         * Weekly source text
         *      ↓
         * immutable SHA-256 source hash
         *      ↓
         * Gemini structured generation
         *      ↓
         * generated JSON
         *      ↓
         * Super Admin review
         *      ↓
         * explicit publication
         *
         * The AI output never replaces the original source text.
         * ============================================================
         */


        /*
         * ------------------------------------------------------------
         * Main AI Weekly Report record.
         * ------------------------------------------------------------
         */

        db()->exec("
            CREATE TABLE IF NOT EXISTS ai_weekly_reports (

                id BIGINT UNSIGNED
                    NOT NULL AUTO_INCREMENT,

                business_id BIGINT UNSIGNED
                    NOT NULL,

                period_start DATE
                    NOT NULL,

                period_end DATE
                    NOT NULL,

                source_text MEDIUMTEXT
                    NOT NULL,

                source_hash CHAR(64)
                    NOT NULL,

                generated_json LONGTEXT
                    NULL,

                generated_source_hash CHAR(64)
                    NULL,

                status VARCHAR(20)
                    NOT NULL DEFAULT 'draft',

                current_version INT UNSIGNED
                    NOT NULL DEFAULT 0,

                generated_model VARCHAR(100)
                    NULL,

                prompt_version VARCHAR(80)
                    NOT NULL DEFAULT
                    'ai-weekly-v1-openai',

                created_by BIGINT UNSIGNED
                    NULL,

                updated_by BIGINT UNSIGNED
                    NULL,

                reviewed_by BIGINT UNSIGNED
                    NULL,

                published_by BIGINT UNSIGNED
                    NULL,

                created_at DATETIME
                    NOT NULL DEFAULT CURRENT_TIMESTAMP,

                updated_at TIMESTAMP
                    NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,

                published_at DATETIME
                    NULL,

                PRIMARY KEY (id),

                KEY idx_ai_weekly_business_status (
                    business_id,
                    status,
                    period_end
                ),

                KEY idx_ai_weekly_published (
                    business_id,
                    status,
                    published_at
                ),

                CONSTRAINT fk_ai_weekly_business
                    FOREIGN KEY (business_id)
                    REFERENCES businesses(id)
                    ON DELETE CASCADE,

                CONSTRAINT fk_ai_weekly_created_user
                    FOREIGN KEY (created_by)
                    REFERENCES users(id)
                    ON DELETE SET NULL,

                CONSTRAINT fk_ai_weekly_updated_user
                    FOREIGN KEY (updated_by)
                    REFERENCES users(id)
                    ON DELETE SET NULL,

                CONSTRAINT fk_ai_weekly_reviewed_user
                    FOREIGN KEY (reviewed_by)
                    REFERENCES users(id)
                    ON DELETE SET NULL,

                CONSTRAINT fk_ai_weekly_published_user
                    FOREIGN KEY (published_by)
                    REFERENCES users(id)
                    ON DELETE SET NULL

            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
        ");


        /*
         * ------------------------------------------------------------
         * Immutable generation/version history.
         * ------------------------------------------------------------
         */

        db()->exec("
            CREATE TABLE IF NOT EXISTS ai_weekly_report_versions (

                id BIGINT UNSIGNED
                    NOT NULL AUTO_INCREMENT,

                report_id BIGINT UNSIGNED
                    NOT NULL,

                version_no INT UNSIGNED
                    NOT NULL,

                source_hash CHAR(64)
                    NOT NULL,

                generated_json LONGTEXT
                    NOT NULL,

                provider VARCHAR(40)
                    NOT NULL DEFAULT 'openai',

                model VARCHAR(100)
                    NOT NULL,

                interaction_id VARCHAR(255)
                    NULL,

                prompt_version VARCHAR(80)
                    NOT NULL,

                input_tokens INT UNSIGNED
                    NOT NULL DEFAULT 0,

                output_tokens INT UNSIGNED
                    NOT NULL DEFAULT 0,

                thought_tokens INT UNSIGNED
                    NOT NULL DEFAULT 0,

                total_tokens INT UNSIGNED
                    NOT NULL DEFAULT 0,

                created_by BIGINT UNSIGNED
                    NULL,

                created_at DATETIME
                    NOT NULL DEFAULT CURRENT_TIMESTAMP,

                PRIMARY KEY (id),

                UNIQUE KEY uq_ai_weekly_version (
                    report_id,
                    version_no
                ),

                KEY idx_ai_weekly_version_created (
                    report_id,
                    created_at
                ),

                CONSTRAINT fk_ai_weekly_version_report
                    FOREIGN KEY (report_id)
                    REFERENCES ai_weekly_reports(id)
                    ON DELETE CASCADE,

                CONSTRAINT fk_ai_weekly_version_user
                    FOREIGN KEY (created_by)
                    REFERENCES users(id)
                    ON DELETE SET NULL

            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
        ");


        /*
         * ------------------------------------------------------------
         * Generic AI usage log.
         *
         * Initially used by Gemini AI Weekly Reports.
         * The table is intentionally provider-aware so it can later
         * support OpenAI/Gemini/other AI usage in one place.
         * ------------------------------------------------------------
         */

        db()->exec("
            CREATE TABLE IF NOT EXISTS ai_usage_logs (

                id BIGINT UNSIGNED
                    NOT NULL AUTO_INCREMENT,

                business_id BIGINT UNSIGNED
                    NULL,

                feature_key VARCHAR(100)
                    NOT NULL,

                provider VARCHAR(40)
                    NOT NULL,

                model VARCHAR(100)
                    NOT NULL,

                entity_type VARCHAR(80)
                    NULL,

                entity_id BIGINT UNSIGNED
                    NULL,

                input_tokens INT UNSIGNED
                    NOT NULL DEFAULT 0,

                output_tokens INT UNSIGNED
                    NOT NULL DEFAULT 0,

                thought_tokens INT UNSIGNED
                    NOT NULL DEFAULT 0,

                total_tokens INT UNSIGNED
                    NOT NULL DEFAULT 0,

                status VARCHAR(20)
                    NOT NULL DEFAULT 'success',

                error_code VARCHAR(80)
                    NULL,

                created_by BIGINT UNSIGNED
                    NULL,

                created_at DATETIME
                    NOT NULL DEFAULT CURRENT_TIMESTAMP,

                PRIMARY KEY (id),

                KEY idx_ai_usage_business_created (
                    business_id,
                    created_at
                ),

                KEY idx_ai_usage_feature_created (
                    feature_key,
                    created_at
                ),

                CONSTRAINT fk_ai_usage_business
                    FOREIGN KEY (business_id)
                    REFERENCES businesses(id)
                    ON DELETE CASCADE,

                CONSTRAINT fk_ai_usage_user
                    FOREIGN KEY (created_by)
                    REFERENCES users(id)
                    ON DELETE SET NULL

            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
        ");


        /*
         * ============================================================
         * AESTHETIC INTEL v1.5.8
         * AUTOMATIC DAILY FULL-SYSTEM BACKUPS
         * ============================================================
         */

        db()->exec("
            CREATE TABLE IF NOT EXISTS automatic_backup_settings (

                id TINYINT UNSIGNED
                    PRIMARY KEY,

                enabled TINYINT(1)
                    NOT NULL DEFAULT 0,

                backup_time TIME
                    NOT NULL DEFAULT '03:00:00',

                timezone VARCHAR(100)
                    NOT NULL DEFAULT 'UTC',

                retention_days SMALLINT UNSIGNED
                    NOT NULL DEFAULT 14,

                password_encrypted TEXT
                    NULL,

                last_run_at DATETIME
                    NULL,

                last_success_at DATETIME
                    NULL,

                last_status VARCHAR(20)
                    NULL,

                last_message VARCHAR(500)
                    NULL,

                updated_by BIGINT UNSIGNED
                    NULL,

                updated_at TIMESTAMP
                    NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,

                CONSTRAINT fk_auto_backup_settings_user
                    FOREIGN KEY (updated_by)
                    REFERENCES users(id)
                    ON DELETE SET NULL

            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
        ");


        db()->exec("
            INSERT IGNORE INTO automatic_backup_settings
            (
                id,
                enabled,
                backup_time,
                timezone,
                retention_days
            )
            VALUES
            (
                1,
                0,
                '03:00:00',
                'UTC',
                14
            )
        ");


        db()->exec("
            CREATE TABLE IF NOT EXISTS automatic_backup_history (

                id BIGINT UNSIGNED
                    AUTO_INCREMENT PRIMARY KEY,

                run_type ENUM(
                    'automatic',
                    'manual_test'
                ) NOT NULL DEFAULT 'automatic',

                scheduled_local_date DATE
                    NULL,

                status ENUM(
                    'running',
                    'verified',
                    'failed',
                    'deleted'
                ) NOT NULL DEFAULT 'running',

                filename VARCHAR(255)
                    NULL,

                password_encrypted TEXT
                    NULL,

                size_bytes BIGINT UNSIGNED
                    NOT NULL DEFAULT 0,

                sha256 CHAR(64)
                    NULL,

                table_count INT UNSIGNED
                    NOT NULL DEFAULT 0,

                file_count INT UNSIGNED
                    NOT NULL DEFAULT 0,

                business_count INT UNSIGNED
                    NOT NULL DEFAULT 0,

                user_count INT UNSIGNED
                    NOT NULL DEFAULT 0,

                app_version VARCHAR(30)
                    NULL,

                backup_timezone VARCHAR(100)
                    NOT NULL DEFAULT 'UTC',

                validation_status ENUM(
                    'pending',
                    'verified',
                    'failed'
                ) NOT NULL DEFAULT 'pending',

                validated_at DATETIME
                    NULL,

                validation_message VARCHAR(500)
                    NULL,

                error_message VARCHAR(1000)
                    NULL,

                attempt_count SMALLINT UNSIGNED
                    NOT NULL DEFAULT 0,

                last_started_at DATETIME
                    NULL,

                completed_at DATETIME
                    NULL,

                deleted_at DATETIME
                    NULL,

                deleted_reason ENUM(
                    'manual',
                    'retention'
                ) NULL,

                created_by BIGINT UNSIGNED
                    NULL,

                created_at DATETIME
                    NOT NULL DEFAULT CURRENT_TIMESTAMP,

                UNIQUE KEY uq_auto_backup_schedule (
                    run_type,
                    scheduled_local_date
                ),

                KEY idx_auto_backup_status (
                    status,
                    completed_at
                ),

                KEY idx_auto_backup_validation (
                    validation_status,
                    validated_at
                ),

                CONSTRAINT fk_auto_backup_history_user
                    FOREIGN KEY (created_by)
                    REFERENCES users(id)
                    ON DELETE SET NULL

            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
        ");


    } catch (Throwable $e) {

        error_log(
            'Migration warning: '
            . $e->getMessage()
        );
    }

            /*
         * ============================================================
         * AI WEEKLY REPORT
         * GEMINI → OPENAI PROVIDER MIGRATION
         * ============================================================
         *
         * This changes future defaults only.
         *
         * Historical generated versions are deliberately
         * preserved with their original provider/model.
         */


        db()->exec("
            ALTER TABLE ai_weekly_reports

            MODIFY COLUMN prompt_version
            VARCHAR(80)
            NOT NULL
            DEFAULT 'ai-weekly-v1-openai'
        ");


        db()->exec("
            ALTER TABLE ai_weekly_report_versions

            MODIFY COLUMN provider
            VARCHAR(40)
            NOT NULL
            DEFAULT 'openai'
        ");


        /*
         * Update untouched drafts only.
         *
         * Do NOT rewrite already-generated history.
         */
        db()->exec("
            UPDATE ai_weekly_reports

            SET prompt_version =
                'ai-weekly-v1-openai'

            WHERE current_version = 0

              AND status = 'draft'

              AND prompt_version
                  LIKE 'gemini-weekly%'
        ");
}
