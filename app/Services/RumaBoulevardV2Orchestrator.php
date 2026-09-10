<?php

declare(strict_types=1);

require_once __DIR__ . '/RumaBoulevardCanonicalReport.php';
require_once __DIR__ . '/RumaBoulevardReportComparisonV2.php';

/**
 * RumaBoulevardV2Orchestrator
 *
 * Coordinates the report-aligned side of the RUMA Boulevard 2026-06
 * integration.
 *
 * DATA PLANES
 * -----------
 * 1. Direct Admin GraphQL API
 *      -> RumaBoulevardUnifiedLiveConsole
 *      -> near-live operational/business intelligence
 *
 * 2. Boulevard Report Export API
 *      -> boulevard_sync_runs / boulevard_sync_items
 *      -> upload_batches / uploaded_files
 *      -> RumaBoulevardCanonicalReport
 *      -> report-aligned business intelligence
 *
 * 3. Manual Boulevard uploads
 *      -> upload_batches / uploaded_files
 *      -> RumaBoulevardCanonicalReport
 *
 * 4. Report Export API vs manual upload
 *      -> RumaBoulevardReportComparisonV2
 *
 * IMPORTANT
 * ---------
 * This class does NOT implement OAuth itself.
 *
 * app/boulevard-api.php is the single shared Boulevard transport and owns:
 * - OAuth client_credentials token exchange
 * - token caching
 * - Bearer GraphQL requests
 * - Report Export creation/download
 * - sync worker execution
 * - webhook verification
 *
 * This orchestrator only coordinates those existing services for RUMA.
 */
final class RumaBoulevardV2Orchestrator
{
    /**
     * Supported display/request frequencies.
     *
     * Report Export is forced to "custom" whenever normalizing one of these
     * frequencies would change the exact Live Console period.
     */
    private const FREQUENCIES = [
        'weekly',
        'monthly',
        'quarterly',
        'yearly',
        'custom',
    ];

    /**
     * Resolve the Aesthetic Intel business row representing RUMA.
     *
     * A preferred ID is accepted only if that business actually exists.
     * Otherwise we resolve by the known RUMA business names and, as a
     * secondary signal, the connected Boulevard business name.
     */
    public static function resolveRumaBusinessId(?int $preferred = null): int
    {
        if ($preferred !== null && $preferred > 0) {
            $stmt = db()->prepare(
                "SELECT id
                 FROM businesses
                 WHERE id = ?
                 LIMIT 1"
            );
            $stmt->execute([$preferred]);

            $id = (int)($stmt->fetchColumn() ?: 0);
            if ($id > 0) {
                return $id;
            }
        }

        /*
         * First use the application's own business name.
         */
        $stmt = db()->query(
            "SELECT id
             FROM businesses
             WHERE LOWER(TRIM(name)) IN (
                'ruma aesthetics',
                'ruma',
                'ruma medical'
             )
             ORDER BY
                CASE LOWER(TRIM(name))
                    WHEN 'ruma aesthetics' THEN 1
                    WHEN 'ruma medical' THEN 2
                    WHEN 'ruma' THEN 3
                    ELSE 4
                END,
                id
             LIMIT 1"
        );

        $id = (int)($stmt->fetchColumn() ?: 0);
        if ($id > 0) {
            return $id;
        }

        /*
         * Fallback: use the name Boulevard returned when the OAuth
         * connection was tested successfully.
         */
        try {
            $stmt = db()->query(
                "SELECT b.id
                 FROM businesses b
                 INNER JOIN boulevard_connections bc
                    ON bc.business_id = b.id
                 WHERE LOWER(TRIM(COALESCE(bc.connected_business_name, '')))
                       LIKE '%ruma%'
                 ORDER BY
                    CASE bc.status
                        WHEN 'connected' THEN 1
                        WHEN 'saved' THEN 2
                        ELSE 3
                    END,
                    b.id
                 LIMIT 1"
            );

            $id = (int)($stmt->fetchColumn() ?: 0);
            if ($id > 0) {
                return $id;
            }
        } catch (Throwable) {
            /*
             * If the Boulevard migration has not run yet, preserve the
             * clearer RUMA-not-found message below.
             */
        }

        throw new RuntimeException(
            'RUMA business could not be resolved in Aesthetic Intel.'
        );
    }

    /**
     * Start (or reuse) the exact-period Boulevard Report Export sync used for
     * apples-to-apples comparison with manually uploaded Boulevard reports.
     *
     * The returned array is intentionally stable for the existing index.php
     * routes.
     */
    public static function startCanonicalApiSync(
        int $businessId,
        int $userId,
        string $periodStart,
        string $periodEnd,
        string $timezone,
        string $frequency = 'weekly'
    ): array {
        self::assertBusinessExists($businessId);

        if ($userId < 1) {
            throw new InvalidArgumentException(
                'A valid admin user is required to start the Boulevard sync.'
            );
        }

        [$periodStart, $periodEnd] = self::validatePeriod(
            $periodStart,
            $periodEnd
        );

        $timezone = self::validateTimezone($timezone);
        $frequency = self::validateFrequency($frequency);

        /*
         * app/boulevard-api.php is intentionally loaded only when an admin
         * explicitly starts the Report Export flow. The direct Live Console
         * remains independent of report-export orchestration.
         */
        require_once ROOT_PATH . '/app/boulevard-api.php';

        /*
         * If a finished API-export batch already exists for this exact
         * business and period, reuse it instead of generating duplicates.
         */
        $existingBatch = RumaBoulevardReportComparisonV2::findApiBatch(
            $businessId,
            $periodStart,
            $periodEnd
        );

        if ($existingBatch) {
            return [
                'status' => 'completed',
                'batch_id' => (int)$existingBatch['id'],
                'sync_run_id' => (int)$existingBatch['sync_run_id'],
                'existing' => true,
                'requested_frequency' => $frequency,
                'sync_frequency' => (string)($existingBatch['frequency'] ?? $frequency),
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
            ];
        }

        /*
         * Likewise reuse an in-progress exact-period run.
         */
        $active = RumaBoulevardReportComparisonV2::findActiveApiRun(
            $businessId,
            $periodStart,
            $periodEnd
        );

        if ($active) {
            return [
                'status' => (string)$active['status'],
                'batch_id' => null,
                'sync_run_id' => (int)$active['id'],
                'existing' => true,
                'requested_frequency' => $frequency,
                'sync_frequency' => (string)($active['frequency'] ?? $frequency),
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
            ];
        }

        /*
         * Fail early with a useful configuration message before constructing
         * sync rows. boulevard_connection_credentials() reads the encrypted
         * OAuth Client ID, Client Secret and Boulevard Business UUID from
         * boulevard_connections.
         */
        if (!function_exists('boulevard_connection_credentials')) {
            throw new RuntimeException(
                'Boulevard integration services are not loaded.'
            );
        }

        boulevard_connection_credentials($businessId);

        /*
         * CRITICAL EXACT-PERIOD SAFETY
         * ----------------------------
         * boulevard_start_sync_run() normalizes named frequencies using the
         * reporting-period helper. If that normalization changes the dates
         * selected in the Live Console, the API-export batch would no longer
         * be comparable to the exact uploaded period.
         *
         * Therefore preserve weekly/monthly/etc. only when normalization
         * produces exactly the requested start/end. Otherwise use "custom"
         * so the stored sync period remains byte-for-byte identical.
         */
        $syncFrequency = self::frequencyThatPreservesExactPeriod(
            $frequency,
            $periodStart,
            $periodEnd,
            $timezone
        );

        /*
         * The existing Report Export engine already performs:
         * - OAuth-backed preflight
         * - report mapping validation
         * - report export creation
         * - controlled polling/retries
         * - CSV download and safety checks
         * - header/parser validation
         * - upload_batches generation
         * - reconciliation metadata
         */
        $runId = boulevard_start_sync_run(
            $businessId,
            $userId,
            $syncFrequency,
            $periodStart,
            $periodEnd,
            $timezone,
            []
        );

        if ($runId < 1) {
            throw new RuntimeException(
                'Boulevard Report Export sync did not return a valid run ID.'
            );
        }

        return [
            'status' => 'queued',
            'batch_id' => null,
            'sync_run_id' => $runId,
            'existing' => false,
            'requested_frequency' => $frequency,
            'sync_frequency' => $syncFrequency,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
        ];
    }

    /**
     * Build the RUMA Report Export / upload-comparison page model.
     *
     * This method intentionally does not depend on the direct Admin API live
     * result. index.php adds the direct API data and the separate
     * RumaBoulevardUploadComparison result afterwards.
     */
    public static function pageModel(
        int $businessId,
        string $periodStart,
        string $periodEnd,
        ?int $selectedManualBatchId = null
    ): array {
        self::assertBusinessExists($businessId);

        [$periodStart, $periodEnd] = self::validatePeriod(
            $periodStart,
            $periodEnd
        );

        if ($selectedManualBatchId !== null && $selectedManualBatchId < 1) {
            $selectedManualBatchId = null;
        }

        $apiBatch = RumaBoulevardReportComparisonV2::findApiBatch(
            $businessId,
            $periodStart,
            $periodEnd
        );

        $activeRun = RumaBoulevardReportComparisonV2::findActiveApiRun(
            $businessId,
            $periodStart,
            $periodEnd
        );

        $manualBatches = RumaBoulevardReportComparisonV2::findManualBatches(
            $businessId,
            $periodStart,
            $periodEnd
        );

        /*
         * If there is exactly one eligible exact-period upload, select it
         * automatically. This keeps the comparison deterministic while
         * avoiding an unnecessary second click. When multiple uploads exist
         * the administrator must still choose explicitly.
         */
        if ($selectedManualBatchId === null && count($manualBatches) === 1) {
            $selectedManualBatchId = (int)($manualBatches[0]['id'] ?? 0);
            if ($selectedManualBatchId < 1) {
                $selectedManualBatchId = null;
            }
        }

        $apiCanonical = null;

        if ($apiBatch) {
            $apiCanonical = RumaBoulevardCanonicalReport::loadBatch(
                $businessId,
                (int)$apiBatch['id']
            );

            self::assertCanonicalPeriod(
                $apiCanonical,
                $periodStart,
                $periodEnd,
                'Boulevard API export'
            );
        }

        $comparison = null;
        $selectedManual = null;

        if ($selectedManualBatchId !== null) {
            foreach ($manualBatches as $batch) {
                if ((int)($batch['id'] ?? 0) === $selectedManualBatchId) {
                    $selectedManual = $batch;
                    break;
                }
            }

            if (!$selectedManual) {
                throw new RuntimeException(
                    'Selected manual upload does not belong to this exact RUMA period.'
                );
            }

            /*
             * A manual batch can still be selected before the Report Export
             * sync finishes. Keep the selection in the model, but only build
             * the report-aligned comparison once the API batch exists.
             */
            if ($apiBatch) {
                $comparison = RumaBoulevardReportComparisonV2::compareBatches(
                    $businessId,
                    (int)$apiBatch['id'],
                    $selectedManualBatchId
                );
            }
        }

        return [
            'business_id' => $businessId,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,

            'api_batch' => $apiBatch,
            'active_run' => $activeRun,
            'api_canonical' => $apiCanonical,

            'manual_batches' => $manualBatches,
            'selected_manual_batch' => $selectedManual,

            'comparison' => $comparison,

            /*
             * Additional non-breaking state helpers for current/future UI.
             */
            'has_api_batch' => $apiBatch !== null,
            'has_active_run' => $activeRun !== null,
            'has_manual_batches' => $manualBatches !== [],
            'comparison_ready' => $apiBatch !== null && $manualBatches !== [],
            'comparison_selected' => $comparison !== null,

            /*
             * UI/source-plane helpers. Report Export is the canonical fallback
             * for scope-gated direct KPIs and the primary report-parity plane.
             */
            'canonical_fallback_available' => $apiCanonical !== null,
            'parity_source' => 'boulevard_report_export_api',
            'direct_source' => 'boulevard_admin_graphql_2026_06',
        ];
    }

    /**
     * Ensure the requested frequency will not silently change the exact
     * selected report period. Falls back to custom when necessary.
     */
    private static function frequencyThatPreservesExactPeriod(
        string $frequency,
        string $periodStart,
        string $periodEnd,
        string $timezone
    ): string {
        if ($frequency === 'custom') {
            return 'custom';
        }

        if (!function_exists('reporting_normalize_period')) {
            return 'custom';
        }

        try {
            [$normalizedStart, $normalizedEnd] = reporting_normalize_period(
                $frequency,
                $periodStart,
                $periodEnd,
                $timezone
            );
        } catch (Throwable) {
            return 'custom';
        }

        return (
            $normalizedStart === $periodStart
            && $normalizedEnd === $periodEnd
        )
            ? $frequency
            : 'custom';
    }

    /**
     * Validate Aesthetic Intel business existence.
     */
    private static function assertBusinessExists(int $businessId): void
    {
        if ($businessId < 1) {
            throw new InvalidArgumentException(
                'A valid RUMA business ID is required.'
            );
        }

        $stmt = db()->prepare(
            "SELECT id
             FROM businesses
             WHERE id = ?
             LIMIT 1"
        );
        $stmt->execute([$businessId]);

        if (!(int)($stmt->fetchColumn() ?: 0)) {
            throw new RuntimeException(
                'The selected RUMA business does not exist in Aesthetic Intel.'
            );
        }
    }

    /**
     * Strict YYYY-MM-DD validation without timezone/date coercion.
     *
     * @return array{0:string,1:string}
     */
    private static function validatePeriod(
        string $periodStart,
        string $periodEnd
    ): array {
        $periodStart = trim($periodStart);
        $periodEnd = trim($periodEnd);

        $start = self::strictDate($periodStart);
        $end = self::strictDate($periodEnd);

        if ($start > $end) {
            throw new InvalidArgumentException(
                'RUMA Boulevard period start cannot be after period end.'
            );
        }

        return [
            $periodStart,
            $periodEnd,
        ];
    }

    private static function strictDate(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $value
        );

        $errors = DateTimeImmutable::getLastErrors();

        if (
            !$date
            || (
                $errors !== false
                && (
                    ($errors['warning_count'] ?? 0) > 0
                    || ($errors['error_count'] ?? 0) > 0
                )
            )
            || $date->format('Y-m-d') !== $value
        ) {
            throw new InvalidArgumentException(
                'RUMA Boulevard dates must use YYYY-MM-DD.'
            );
        }

        return $date;
    }

    /**
     * Validate a real IANA timezone name.
     */
    private static function validateTimezone(string $timezone): string
    {
        $timezone = trim($timezone);

        if ($timezone === '') {
            throw new InvalidArgumentException(
                'A Boulevard business timezone is required.'
            );
        }

        try {
            $tz = new DateTimeZone($timezone);
        } catch (Throwable) {
            throw new InvalidArgumentException(
                'Invalid Boulevard business timezone: ' . $timezone
            );
        }

        return $tz->getName();
    }

    private static function validateFrequency(string $frequency): string
    {
        $frequency = strtolower(trim($frequency));

        if (!in_array($frequency, self::FREQUENCIES, true)) {
            throw new InvalidArgumentException(
                'Invalid Boulevard reporting frequency.'
            );
        }

        return $frequency;
    }

    /**
     * Defensive exact-period verification after canonicalization.
     */
    private static function assertCanonicalPeriod(
        array $canonical,
        string $periodStart,
        string $periodEnd,
        string $label
    ): void {
        $actualStart = trim(
            (string)($canonical['meta']['period_start'] ?? '')
        );
        $actualEnd = trim(
            (string)($canonical['meta']['period_end'] ?? '')
        );

        if (
            $actualStart !== $periodStart
            || $actualEnd !== $periodEnd
        ) {
            throw new RuntimeException(
                $label
                . ' period does not match the selected RUMA period. '
                . 'Expected '
                . $periodStart
                . ' to '
                . $periodEnd
                . ', received '
                . ($actualStart !== '' ? $actualStart : 'unknown')
                . ' to '
                . ($actualEnd !== '' ? $actualEnd : 'unknown')
                . '.'
            );
        }
    }
}
