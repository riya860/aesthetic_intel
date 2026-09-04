<?php

declare(strict_types=1);

/**
 * Business-level feature controls.
 *
 * These switches are deliberately access controls, not data-deletion controls.
 * Disabling a feature hides its navigation/dashboard entry points and blocks
 * direct routes while preserving all historical data for later re-enablement.
 */

function business_feature_definitions(): array {
    return [

        /*
         * ============================================================
         * REPORTING SOURCES
         * ============================================================
         */

        'boulevard' => [
            'name' => 'Boulevard',
            'group' => 'Reporting Sources',
            'description' => 'Manual Boulevard CSV uploads, source reports, and Boulevard performance data.',
            'default' => true,
        ],

        'boulevard_api' => [
            'name' => 'Boulevard API Beta',
            'group' => 'Reporting Sources',
            'description' => 'Boulevard API connection, sync diagnostics, background export runs, and one-click weekly reports.',
            'default' => true,
            'depends_on' => 'boulevard',
        ],

        'gbp' => [
            'name' => 'Google Business Profile',
            'group' => 'Reporting Sources',
            'description' => 'GBP cumulative metric entry, activity calculations, history, and reports.',
            'default' => true,
        ],


        /*
         * ============================================================
         * AI DATA SOURCES
         * ============================================================
         */

        'podium' => [
            'name' => 'Podium',
            'group' => 'AI Data Sources',
            'description' => 'Podium Inbox and Calls uploads with AI-assisted metric extraction.',
            'default' => true,
        ],

        'growth99' => [
            'name' => 'Growth99+',
            'group' => 'AI Data Sources',
            'description' => 'Growth99+, Cliffhanger, and CallRail report extraction workflows.',
            'default' => true,
        ],

        'ga4' => [
            'name' => 'Google Analytics 4',
            'group' => 'AI Data Sources',
            'description' => 'GA4 report snapshot extraction for website traffic and conversion metrics.',
            'default' => true,
        ],


        /*
         * ============================================================
         * PERFORMANCE MANAGEMENT
         * ============================================================
         */

        'provider_kpi' => [
            'name' => 'Provider KPI Dashboard',
            'group' => 'Performance Management',
            'description' => 'Provider scorecards, goals, rankings, opportunities, coaching, imports, and activity.',
            'default' => false,
            'storage' => 'provider_kpi_settings',
        ],


        /*
         * ============================================================
         * REPORTS & INTELLIGENCE
         * ============================================================
         */

        'unified_reports' => [
            'name' => 'Unified Reports',
            'group' => 'Reports & Intelligence',
            'description' => 'Cross-source Unified Performance Reports using comparison-ready data.',
            'default' => true,
        ],

        'ai_reviews' => [
            'name' => 'Review with AI',
            'group' => 'Reports & Intelligence',
            'description' => 'Separate AI Reviewed Reports for completed Boulevard, GBP, and Unified reports.',
            'default' => true,
        ],

        /*
         * AI Weekly Report
         *
         * Disabled by default.
         * Super Admin explicitly enables this feature
         * for each required business.
         */
        'ai_weekly_report' => [
            'name' => 'AI Weekly Report',
            'group' => 'Reports & Intelligence',
            'description' =>
                'AI-generated weekly intelligence dashboard '
                . 'reviewed and published by a Super Admin.',
            'default' => false,
        ],


        /*
         * ============================================================
         * WORKSPACE TOOLS
         * ============================================================
         */

        'data_transfer' => [
            'name' => 'Data Transfer',
            'group' => 'Workspace Tools',
            'description' => 'Portable business data export/import workflows.',
            'default' => true,
        ],

        'smart_search' => [
            'name' => 'Smart Search',
            'group' => 'Workspace Tools',
            'description' => 'Permission-aware natural-language Feature Finder for business users.',
            'default' => true,
        ],

    ];
}


/**
 * Return the registered business feature codes.
 */
function business_feature_codes(bool $includeProviderKpi=true): array {

    $codes =
        array_keys(
            business_feature_definitions()
        );


    if (!$includeProviderKpi) {

        $codes =
            array_values(
                array_filter(
                    $codes,
                    static fn(string $code): bool =>
                        $code !== 'provider_kpi'
                )
            );
    }


    return $codes;
}


/**
 * Return the default state of one feature.
 */
function business_feature_default(string $code): bool {

    $definition =
        business_feature_definitions()[$code]
        ?? null;


    return
        is_array($definition)
        &&
        !empty(
            $definition['default']
        );
}


/**
 * Return the raw saved/default feature states.
 */
function business_feature_raw_states(int $businessId): array {

    if (
        $businessId > 0
        &&
        isset(
            $GLOBALS[
                '_business_feature_state_cache'
            ][$businessId]
        )
        &&
        is_array(
            $GLOBALS[
                '_business_feature_state_cache'
            ][$businessId]
        )
    ) {

        return
            $GLOBALS[
                '_business_feature_state_cache'
            ][$businessId];
    }


    $states = [];


    foreach (
        business_feature_definitions()
        as $code => $definition
    ) {

        $states[$code] =
            (bool)(
                $definition['default']
                ?? false
            );
    }


    if ($businessId <= 0) {

        return $states;
    }


    try {

        $stmt =
            db()->prepare(
                'SELECT feature_code, enabled
                 FROM business_features
                 WHERE business_id=?'
            );


        $stmt->execute([
            $businessId
        ]);


        foreach (
            $stmt->fetchAll()
            as $row
        ) {

            $code =
                (string)(
                    $row['feature_code']
                    ?? ''
                );


            if (
                array_key_exists(
                    $code,
                    $states
                )
            ) {

                $states[$code] =
                    !empty(
                        $row['enabled']
                    );
            }
        }

    } catch (Throwable) {

        /*
         * During the first request after an upgrade
         * the migration may still be creating the table.
         *
         * Defaults preserve the existing application UI.
         */
    }


    /*
     * Provider KPI has its own original settings table.
     */
    if (
        array_key_exists(
            'provider_kpi',
            $states
        )
    ) {

        try {

            $stmt =
                db()->prepare(
                    'SELECT enabled
                     FROM provider_kpi_settings
                     WHERE business_id=?
                     LIMIT 1'
                );


            $stmt->execute([
                $businessId
            ]);


            $value =
                $stmt->fetchColumn();


            if ($value !== false) {

                $states['provider_kpi'] =
                    (bool)$value;
            }

        } catch (Throwable) {

            // Preserve fallback/default behavior.
        }
    }


    if ($businessId > 0) {

        $GLOBALS[
            '_business_feature_state_cache'
        ][$businessId] =
            $states;
    }


    return $states;
}


/**
 * Return whether a feature is effectively enabled.
 *
 * Dependencies are also respected.
 */
function business_feature_enabled(
    int $businessId,
    string $code
): bool {

    $definitions =
        business_feature_definitions();


    if (
        $businessId <= 0
        ||
        !isset(
            $definitions[$code]
        )
    ) {

        return false;
    }


    $states =
        business_feature_raw_states(
            $businessId
        );


    $enabled =
        !empty(
            $states[$code]
        );


    $dependency =
        (string)(
            $definitions[$code][
                'depends_on'
            ]
            ?? ''
        );


    if (
        $enabled
        &&
        $dependency !== ''
    ) {

        $enabled =
            business_feature_enabled(
                $businessId,
                $dependency
            );
    }


    return $enabled;
}


/**
 * Return effective feature states for one business.
 */
function business_feature_effective_states(
    int $businessId
): array {

    $out = [];


    foreach (
        business_feature_definitions()
        as $code => $definition
    ) {

        $out[$code] =
            business_feature_enabled(
                $businessId,
                $code
            );
    }


    return $out;
}


/**
 * Initialize feature rows for a newly-created business.
 */
function business_feature_initialize(
    int $businessId,
    ?int $updatedBy=null
): void {

    if ($businessId <= 0) {
        return;
    }


    unset(
        $GLOBALS[
            '_business_feature_state_cache'
        ][$businessId]
    );


    $stmt =
        db()->prepare(
            'INSERT IGNORE INTO business_features
            (
                business_id,
                feature_code,
                enabled,
                updated_by
            )
            VALUES
            (
                ?,?,?,?
            )'
        );


    foreach (
        business_feature_definitions()
        as $code => $definition
    ) {

        /*
         * Provider KPI continues to use
         * provider_kpi_settings.
         */
        if (
            (
                $definition['storage']
                ?? ''
            )
            === 'provider_kpi_settings'
        ) {

            continue;
        }


        $stmt->execute([
            $businessId,
            $code,
            !empty(
                $definition['default']
            )
                ? 1
                : 0,
            $updatedBy,
        ]);
    }
}


/**
 * Save Feature Controls from the Super Admin
 * Business Edit workflow.
 */
function business_feature_save_states(
    int $businessId,
    array $submitted,
    ?int $updatedBy=null
): array {

    if ($businessId <= 0) {

        throw new RuntimeException(
            'Business not found.'
        );
    }


    $definitions =
        business_feature_definitions();


    $before =
        business_feature_raw_states(
            $businessId
        );


    unset(
        $GLOBALS[
            '_business_feature_state_cache'
        ][$businessId]
    );


    $pdo =
        db();


    $started =
        !$pdo->inTransaction();


    if ($started) {

        $pdo->beginTransaction();
    }


    try {

        $upsert =
            $pdo->prepare(
                "INSERT INTO business_features
                (
                    business_id,
                    feature_code,
                    enabled,
                    updated_by
                )
                VALUES
                (
                    ?,?,?,?
                )
                ON DUPLICATE KEY UPDATE
                    enabled=VALUES(enabled),
                    updated_by=VALUES(updated_by),
                    updated_at=CURRENT_TIMESTAMP"
            );


        foreach (
            $definitions
            as $code => $definition
        ) {

            $enabled =
                array_key_exists(
                    $code,
                    $submitted
                )
                &&
                !empty(
                    $submitted[$code]
                );


            /*
             * Provider KPI continues using
             * provider_kpi_settings.
             */
            if (
                (
                    $definition['storage']
                    ?? ''
                )
                === 'provider_kpi_settings'
            ) {

                provider_kpi_settings(
                    $businessId
                );


                $pdo->prepare(
                    'UPDATE provider_kpi_settings
                     SET enabled=?,
                         updated_by=?
                     WHERE business_id=?'
                )->execute([
                    $enabled ? 1 : 0,
                    $updatedBy,
                    $businessId,
                ]);


                continue;
            }


            $upsert->execute([
                $businessId,
                $code,
                $enabled ? 1 : 0,
                $updatedBy,
            ]);
        }


        /*
         * Keep the original business_data_sources
         * table aligned with the two legacy
         * data-source rows so older code/exports
         * retain consistent state.
         */

        $sourceMap = [
            'boulevard' =>
                'boulevard',

            'gbp' =>
                'google-business-profile',
        ];


        $sourceStmt =
            $pdo->prepare(
                'SELECT id
                 FROM data_sources
                 WHERE code=?
                 LIMIT 1'
            );


        $sourceUpsert =
            $pdo->prepare(
                'INSERT INTO business_data_sources
                (
                    business_id,
                    data_source_id,
                    enabled
                )
                VALUES
                (
                    ?,?,?
                )
                ON DUPLICATE KEY UPDATE
                    enabled=VALUES(enabled)'
            );


        foreach (
            $sourceMap
            as $featureCode => $sourceCode
        ) {

            $sourceStmt->execute([
                $sourceCode
            ]);


            $sourceId =
                (int)(
                    $sourceStmt->fetchColumn()
                    ?: 0
                );


            if ($sourceId) {

                $sourceUpsert->execute([
                    $businessId,
                    $sourceId,
                    !empty(
                        $submitted[
                            $featureCode
                        ]
                    )
                        ? 1
                        : 0,
                ]);
            }
        }


        if ($started) {

            $pdo->commit();
        }


    } catch (Throwable $e) {


        if (
            $started
            &&
            $pdo->inTransaction()
        ) {

            $pdo->rollBack();
        }


        throw $e;
    }


    unset(
        $GLOBALS[
            '_business_feature_state_cache'
        ][$businessId]
    );


    $after =
        business_feature_raw_states(
            $businessId
        );


    $changed = [];


    foreach (
        $definitions
        as $code => $definition
    ) {

        if (
            (bool)(
                $before[$code]
                ?? false
            )
            ===
            (bool)(
                $after[$code]
                ?? false
            )
        ) {

            continue;
        }


        $changed[$code] = [

            'name' =>
                (string)(
                    $definition['name']
                    ?? $code
                ),

            'from' =>
                (bool)(
                    $before[$code]
                    ?? false
                ),

            'to' =>
                (bool)(
                    $after[$code]
                    ?? false
                ),
        ];
    }


    return [
        'states' =>
            $after,

        'changed' =>
            $changed,
    ];
}


/**
 * Convert an AI extraction source code
 * into its Feature Control code.
 */
function business_feature_source_code(
    string $aiSource
): ?string {

    return match($aiSource) {

        'podium' =>
            'podium',

        'growth99' =>
            'growth99',

        'ga4' =>
            'ga4',

        default =>
            null,
    };
}


/**
 * Map a requested page/route to the
 * corresponding business feature.
 *
 * Returning null means the route is not
 * controlled by Business Feature Controls.
 */
function business_feature_request_code(
    string $page
): ?string {

    /*
     * Provider KPI
     */
    if (
        str_starts_with(
            $page,
            'business-provider-kpi'
        )
    ) {

        return 'provider_kpi';
    }


    /*
     * Google Business Profile
     */
    if (
        in_array(
            $page,
            [
                'business-gbp',
                'business-gbp-history',
                'business-gbp-report',
                'business-gbp-delete',
            ],
            true
        )
    ) {

        return 'gbp';
    }


    /*
     * Boulevard manual reporting
     */
    if (
        in_array(
            $page,
            [
                'business-upload',
                'business-report',
                'business-report-delete',
            ],
            true
        )
    ) {

        return 'boulevard';
    }


    /*
     * Boulevard API
     */
    if (
        in_array(
            $page,
            [
                'business-boulevard-integration',
                'business-boulevard-sync-diagnostics',
                'business-boulevard-sync',
                'business-boulevard-sync-status',
                'business-boulevard-sync-fallback',
                'business-boulevard-run',
                'business-boulevard-run-status',
                'business-boulevard-user-status',
            ],
            true
        )
    ) {

        return 'boulevard_api';
    }


    /*
     * Unified Reports
     */
    if (
        $page ===
        'business-unified-report'
    ) {

        return 'unified_reports';
    }


    /*
     * Review with AI
     */
    if (
        in_array(
            $page,
            [
                'business-ai-report-review',
                'business-ai-reviewed-report',
            ],
            true
        )
    ) {

        return 'ai_reviews';
    }


    /*
     * ============================================================
     * AI WEEKLY REPORT
     * ============================================================
     *
     * Both the report history/list and individual report route
     * are protected by the central Feature Controls system.
     */

    if (
        in_array(
            $page,
            [
                'business-ai-weekly-reports',
                'business-ai-weekly-report',
            ],
            true
        )
    ) {

        return 'ai_weekly_report';
    }


    /*
     * Data Transfer
     */
    if (
        in_array(
            $page,
            [
                'business-data-transfer',
                'business-data-export',
            ],
            true
        )
    ) {

        return 'data_transfer';
    }


    /*
     * AI extraction sources.
     */
    if (
        $page ===
        'business-ai-extraction'
    ) {

        return
            business_feature_source_code(
                (string)(
                    $_POST['source']
                    ?? $_GET['source']
                    ?? 'podium'
                )
            );
    }


    /*
     * Smart Search only uses a business-level
     * feature when a business context exists.
     */
    if (
        $page === 'smart-search'
        &&
        (int)(
            business_context_id()
            ?? 0
        ) > 0
    ) {

        return 'smart_search';
    }


    /*
     * Report Intelligence approval should still
     * respect the underlying source feature.
     */
    if (
        $page ===
        'business-report-validation-approve'
    ) {

        $type =
            (string)(
                $_POST['source_type']
                ?? ''
            );


        if ($type === 'boulevard') {

            return 'boulevard';
        }


        if ($type === 'gbp') {

            return 'gbp';
        }


        if ($type === 'ai') {

            return
                business_feature_source_code(
                    (string)(
                        $_POST['source_code']
                        ?? $_GET['source']
                        ?? ''
                    )
                );
        }
    }


    return null;
}


/**
 * Human-readable disabled-feature message.
 */
function business_feature_unavailable_message(
    string $code
): string {

    $name =
        (string)(
            business_feature_definitions()[
                $code
            ]['name']
            ?? 'This feature'
        );


    return
        $name
        . ' is disabled for this business. '
        . 'A Super Admin can re-enable it from '
        . 'Businesses → Edit Business → Feature Controls.';
}


/**
 * Enforce Business Feature Controls
 * for the currently requested page.
 */
function business_feature_enforce_request(
    string $page
): void {

    if (!auth_check()) {
        return;
    }


    $businessId =
        (int)(
            business_context_id()
            ?? 0
        );


    if ($businessId <= 0) {
        return;
    }


    $code =
        business_feature_request_code(
            $page
        );


    if (
        $code === null
        ||
        business_feature_enabled(
            $businessId,
            $code
        )
    ) {

        return;
    }


    $message =
        business_feature_unavailable_message(
            $code
        );


    if (
        is_ajax_request()
        ||
        in_array(
            $page,
            [
                'business-boulevard-user-status',
                'business-boulevard-sync-status',
            ],
            true
        )
    ) {

        json_response(
            [
                'ok' =>
                    false,

                'message' =>
                    $message,
            ],
            403
        );
    }


    flash(
        'warning',
        $message
    );


    redirect(
        url(
            'business-dashboard'
        )
    );
}


/**
 * Check whether a report type can be used
 * by the Review with AI feature.
 */
function business_feature_ai_review_source_allowed(
    int $businessId,
    string $reportType
): bool {

    if (
        !business_feature_enabled(
            $businessId,
            'ai_reviews'
        )
    ) {

        return false;
    }


    return match($reportType) {

        'boulevard' =>
            business_feature_enabled(
                $businessId,
                'boulevard'
            ),

        'gbp' =>
            business_feature_enabled(
                $businessId,
                'gbp'
            ),

        'unified' =>
            business_feature_enabled(
                $businessId,
                'unified_reports'
            ),

        default =>
            false,
    };
}


/**
 * Map Documentation / Smart Search topics
 * to Business Feature Controls.
 *
 * Documentation entries for disabled features
 * are therefore hidden automatically.
 */
function business_feature_documentation_code(
    string $topicId
): ?string {

    return match($topicId) {

        'smart-search' =>
            'smart_search',


        'business-gbp' =>
            'gbp',


        'business-boulevard-admin',
        'business-boulevard-run',
        'business-boulevard-upload' =>
            'boulevard',


        'business-podium' =>
            'podium',


        'business-growth99' =>
            'growth99',


        'business-ga4' =>
            'ga4',


        'business-ai-reviewed-report' =>
            'ai_reviews',


        /*
         * ============================================================
         * AI WEEKLY REPORT DOCUMENTATION
         * ============================================================
         */
        'business-ai-weekly-reports' =>
            'ai_weekly_report',


        'business-unified' =>
            'unified_reports',


        'business-transfer' =>
            'data_transfer',


        'provider-overview',
        'provider-scorecard',
        'provider-opportunities',
        'provider-rankings',
        'provider-goals',
        'provider-import',
        'provider-management',
        'provider-coaching',
        'provider-activity' =>
            'provider_kpi',


        default =>
            null,
    };
}