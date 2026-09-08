<?php

declare(strict_types=1);

require_once __DIR__ . '/RumaBoulevardCanonicalReport.php';

/**
 * RumaBoulevardReportComparisonV2
 *
 * Compares Boulevard Report Export API data with the manually uploaded
 * Boulevard report/PDF-derived data for the exact same RUMA reporting period.
 *
 * IMPORTANT ARCHITECTURE:
 * - This class does NOT authenticate with Boulevard.
 * - OAuth/token handling lives in app/boulevard-api.php.
 * - API Report Export files and manual uploaded files are both normalized by
 *   RumaBoulevardCanonicalReport before values are compared.
 * - This keeps the comparison report-aligned and prevents false differences
 *   caused only by different parsing/calculation paths.
 */
final class RumaBoulevardReportComparisonV2
{
    private const CURRENCY_ABS_TOLERANCE = 1.00;
    private const CURRENCY_REL_TOLERANCE = 0.001; // 0.1%
    private const PERCENT_POINT_TOLERANCE = 0.5;
    private const DECIMAL_TOLERANCE = 0.10;

    /**
     * Return the most recent completed/partial Boulevard Report Export batch
     * for an exact business + reporting period.
     */
    public static function findApiBatch(
        int $businessId,
        string $periodStart,
        string $periodEnd
    ): ?array {
        self::assertLookupInputs($businessId, $periodStart, $periodEnd);

        $stmt = db()->prepare(
            "SELECT
                ub.id,
                ub.business_id,
                ub.period_start,
                ub.period_end,
                ub.frequency,
                ub.validation_status,
                ub.completeness_score,
                ub.warning_count,
                ub.created_at,
                ub.completed_at,
                sr.id AS sync_run_id,
                sr.status AS sync_status,
                sr.completed_at AS sync_completed_at
             FROM boulevard_sync_runs sr
             INNER JOIN upload_batches ub
                ON ub.id = sr.upload_batch_id
             WHERE sr.business_id = ?
               AND ub.business_id = ?
               AND sr.period_start = ?
               AND sr.period_end = ?
               AND ub.period_start = ?
               AND ub.period_end = ?
               AND ub.status = 'completed'
               AND sr.upload_batch_id IS NOT NULL
               AND sr.status IN ('completed', 'partial')
             ORDER BY sr.id DESC
             LIMIT 1"
        );

        $stmt->execute([
            $businessId,
            $businessId,
            $periodStart,
            $periodEnd,
            $periodStart,
            $periodEnd,
        ]);

        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    /**
     * Return an in-progress Report Export sync for the exact period.
     *
     * These statuses match the boulevard_sync_runs enum in this project.
     */
    public static function findActiveApiRun(
        int $businessId,
        string $periodStart,
        string $periodEnd
    ): ?array {
        self::assertLookupInputs($businessId, $periodStart, $periodEnd);

        $stmt = db()->prepare(
            "SELECT
                id,
                business_id,
                status,
                period_start,
                period_end,
                frequency,
                requested_count,
                completed_count,
                failed_count,
                created_at,
                started_at,
                last_checked_at,
                last_heartbeat_at,
                next_worker_at,
                status_message
             FROM boulevard_sync_runs
             WHERE business_id = ?
               AND period_start = ?
               AND period_end = ?
               AND status IN (
                    'queued',
                    'preflight',
                    'requesting',
                    'waiting',
                    'running',
                    'processing'
               )
             ORDER BY id DESC
             LIMIT 1"
        );

        $stmt->execute([
            $businessId,
            $periodStart,
            $periodEnd,
        ]);

        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    /**
     * Return manual Boulevard upload batches for the exact same period.
     *
     * A manual batch is defined here as a completed upload_batches row that is
     * NOT the generated output of boulevard_sync_runs.
     */
    public static function findManualBatches(
        int $businessId,
        string $periodStart,
        string $periodEnd
    ): array {
        self::assertLookupInputs($businessId, $periodStart, $periodEnd);

        $stmt = db()->prepare(
            "SELECT
                ub.id,
                ub.business_id,
                ub.period_start,
                ub.period_end,
                ub.frequency,
                ub.validation_status,
                ub.completeness_score,
                ub.warning_count,
                ub.created_at,
                ub.completed_at
             FROM upload_batches ub
             WHERE ub.business_id = ?
               AND ub.period_start = ?
               AND ub.period_end = ?
               AND ub.status = 'completed'
               AND NOT EXISTS (
                    SELECT 1
                    FROM boulevard_sync_runs sr
                    WHERE sr.upload_batch_id = ub.id
               )
             ORDER BY ub.id DESC"
        );

        $stmt->execute([
            $businessId,
            $periodStart,
            $periodEnd,
        ]);

        $rows = $stmt->fetchAll();
        return is_array($rows) ? $rows : [];
    }

    /**
     * Compare one API-generated Report Export batch with one manual batch.
     */
    public static function compareBatches(
        int $businessId,
        int $apiBatchId,
        int $manualBatchId
    ): array {
        if ($businessId < 1) {
            throw new InvalidArgumentException('A valid RUMA business ID is required.');
        }

        if ($apiBatchId < 1 || $manualBatchId < 1) {
            throw new InvalidArgumentException('Both API and manual batch IDs are required.');
        }

        if ($apiBatchId === $manualBatchId) {
            throw new RuntimeException(
                'API and manual comparison batches must be different.'
            );
        }

        self::assertApiBatchSource($businessId, $apiBatchId);
        self::assertManualBatchSource($businessId, $manualBatchId);

        $api = RumaBoulevardCanonicalReport::loadBatch(
            $businessId,
            $apiBatchId
        );

        $manual = RumaBoulevardCanonicalReport::loadBatch(
            $businessId,
            $manualBatchId
        );

        $apiStart = (string)($api['meta']['period_start'] ?? '');
        $apiEnd = (string)($api['meta']['period_end'] ?? '');
        $manualStart = (string)($manual['meta']['period_start'] ?? '');
        $manualEnd = (string)($manual['meta']['period_end'] ?? '');

        if ($apiStart === '' || $apiEnd === '' || $manualStart === '' || $manualEnd === '') {
            throw new RuntimeException(
                'The API or manual canonical report is missing its reporting period.'
            );
        }

        if ($apiStart !== $manualStart || $apiEnd !== $manualEnd) {
            throw new RuntimeException(
                'API and uploaded report periods are not identical.'
            );
        }

        return self::compare($api, $manual);
    }

    /**
     * Compare two already-canonicalized Boulevard report datasets.
     */
    public static function compare(array $api, array $manual): array
    {
        $definitions = [
            ['total_revenue', 'Total revenue'],
            ['appointments', 'Total appointments'],
            ['requested_appointments', 'Requested appointments'],
            ['new_clients', 'New clients'],
            ['utilization', 'Blended utilization'],
            ['active_mrr', 'Current active MRR'],
            ['active_arr', 'Current ARR'],
            ['active_memberships', 'Active memberships'],
            ['service_revenue', 'Service revenue'],
            ['product_revenue', 'Product revenue'],
            ['membership_revenue', 'Membership revenue'],
            ['package_revenue', 'Package revenue'],
            ['tips', 'Tips'],
            ['net_sales', 'Net sales'],
            ['gross_payments', 'Gross payments'],
            ['refunds', 'Refunds'],
            ['account_credit_sold', 'Account credit sold'],
            ['gift_cards_sold', 'Gift cards sold'],
            ['tax_collected', 'Tax collected'],
            ['card_fees', 'Card fees'],
            ['voucher_service_revenue', 'Voucher service revenue'],
            ['retail_revenue', 'Retail revenue'],
            ['retail_units', 'Retail units'],
            ['memberships_sold', 'Memberships sold'],
        ];

        $metricRows = [];

        foreach ($definitions as [$key, $label]) {
            $metricRows[] = self::compareMetric(
                $label,
                is_array($api['kpis'][$key] ?? null)
                    ? $api['kpis'][$key]
                    : null,
                is_array($manual['kpis'][$key] ?? null)
                    ? $manual['kpis'][$key]
                    : null
            );
        }

        $providerRows = self::compareProviders(
            is_array($api['providers'] ?? null) ? $api['providers'] : [],
            is_array($manual['providers'] ?? null) ? $manual['providers'] : []
        );

        $dailyRows = self::compareDaily(
            is_array($api['daily'] ?? null) ? $api['daily'] : [],
            is_array($manual['daily'] ?? null) ? $manual['daily'] : []
        );

        $summary = self::summarizeComparison(
            $metricRows,
            $providerRows,
            $dailyRows
        );

        return [
            'period_start' => (string)($api['meta']['period_start'] ?? ''),
            'period_end' => (string)($api['meta']['period_end'] ?? ''),
            'api_batch_id' => (int)($api['meta']['batch_id'] ?? 0),
            'manual_batch_id' => (int)($manual['meta']['batch_id'] ?? 0),

            'api_source' => 'Boulevard 2026-06 Report Export API',
            'manual_source' => 'Manual Boulevard PDF/CSV upload',

            'overall_status' => $summary['overall_status'],
            'comparable_metrics' => $summary['comparable'],
            'matched_metrics' => $summary['matched'],
            'review_metrics' => $summary['review'],
            'unavailable_metrics' => $summary['unavailable'],
            'match_percent' => $summary['match_percent'],

            'metrics' => $metricRows,
            'providers' => $providerRows,
            'daily' => $dailyRows,

            'api_parse_warnings' => is_array($api['meta']['parse_warnings'] ?? null)
                ? $api['meta']['parse_warnings']
                : [],
            'manual_parse_warnings' => is_array($manual['meta']['parse_warnings'] ?? null)
                ? $manual['meta']['parse_warnings']
                : [],

            'api_loaded_files' => is_array($api['meta']['loaded_files'] ?? null)
                ? $api['meta']['loaded_files']
                : [],
            'manual_loaded_files' => is_array($manual['meta']['loaded_files'] ?? null)
                ? $manual['meta']['loaded_files']
                : [],

            'tolerances' => [
                'currency' => '±$1.00 or ±0.1%, whichever is larger',
                'number' => 'Exact whole-number match',
                'percent' => '±0.5 percentage points',
                'decimal' => '±0.10',
            ],
        ];
    }

    private static function compareProviders(
        array $apiRows,
        array $manualRows
    ): array {
        $api = self::indexProviders($apiRows);
        $manual = self::indexProviders($manualRows);

        $keys = array_values(
            array_unique(
                array_merge(
                    array_keys($api),
                    array_keys($manual)
                )
            )
        );

        $out = [];

        foreach ($keys as $key) {
            $a = $api[$key] ?? [];
            $m = $manual[$key] ?? [];

            $name = (string)(
                ($a['name'] ?? '')
                ?: ($m['name'] ?? 'Unknown Provider')
            );

            $metrics = [
                self::compareScalar(
                    'Service revenue',
                    $a['service_revenue'] ?? null,
                    $m['service_revenue'] ?? null,
                    'currency'
                ),
                self::compareScalar(
                    'Utilization',
                    $a['utilization'] ?? null,
                    $m['utilization'] ?? null,
                    'percent_points'
                ),
                self::compareScalar(
                    'Scheduled hours',
                    $a['hours_scheduled'] ?? null,
                    $m['hours_scheduled'] ?? null,
                    'decimal'
                ),
                self::compareScalar(
                    'Appointments',
                    $a['appointments'] ?? null,
                    $m['appointments'] ?? null,
                    'number'
                ),
                self::compareScalar(
                    'Requested',
                    $a['requested'] ?? null,
                    $m['requested'] ?? null,
                    'number'
                ),
                self::compareScalar(
                    'New clients',
                    $a['new_clients'] ?? null,
                    $m['new_clients'] ?? null,
                    'number'
                ),
                self::compareScalar(
                    'Revenue / hour',
                    $a['revenue_per_hour'] ?? null,
                    $m['revenue_per_hour'] ?? null,
                    'currency'
                ),
                self::compareScalar(
                    'Product revenue',
                    $a['product_revenue'] ?? null,
                    $m['product_revenue'] ?? null,
                    'currency'
                ),
                self::compareScalar(
                    'Membership revenue',
                    $a['membership_revenue'] ?? null,
                    $m['membership_revenue'] ?? null,
                    'currency'
                ),
            ];

            $out[] = [
                'provider' => $name,
                'status' => self::groupStatus($metrics),
                'metrics' => $metrics,
            ];
        }

        usort(
            $out,
            static function (array $a, array $b): int {
                $rank = [
                    'review' => 0,
                    'incomplete' => 1,
                    'matched' => 2,
                ];

                $cmp = ($rank[$a['status']] ?? 9)
                    <=> ($rank[$b['status']] ?? 9);

                return $cmp !== 0
                    ? $cmp
                    : strcasecmp(
                        (string)$a['provider'],
                        (string)$b['provider']
                    );
            }
        );

        return $out;
    }

    private static function compareDaily(
        array $apiRows,
        array $manualRows
    ): array {
        $api = self::indexDaily($apiRows);
        $manual = self::indexDaily($manualRows);

        $dates = array_values(
            array_unique(
                array_merge(
                    array_keys($api),
                    array_keys($manual)
                )
            )
        );

        sort($dates);

        $out = [];

        foreach ($dates as $date) {
            $a = $api[$date] ?? [];
            $m = $manual[$date] ?? [];

            $metrics = [
                self::compareScalar(
                    'Revenue',
                    $a['revenue'] ?? null,
                    $m['revenue'] ?? null,
                    'currency'
                ),
                self::compareScalar(
                    'Appointments',
                    $a['appointments'] ?? null,
                    $m['appointments'] ?? null,
                    'number'
                ),
                self::compareScalar(
                    'Requested appointments',
                    $a['requested_appointments'] ?? null,
                    $m['requested_appointments'] ?? null,
                    'number'
                ),
            ];

            $out[] = [
                'date' => $date,
                'status' => self::groupStatus($metrics),
                'metrics' => $metrics,
            ];
        }

        return $out;
    }

    private static function compareMetric(
        string $label,
        ?array $api,
        ?array $manual
    ): array {
        $format = (string)(
            ($api['format'] ?? '')
            ?: ($manual['format'] ?? 'number')
        );

        $comparisonFormat = $format === 'percent'
            ? 'percent_points'
            : $format;

        $row = self::compareScalar(
            $label,
            !empty($api['available'])
                ? ($api['value'] ?? null)
                : null,
            !empty($manual['available'])
                ? ($manual['value'] ?? null)
                : null,
            $comparisonFormat
        );

        $row['api_source'] = (string)($api['source'] ?? 'Unavailable');
        $row['manual_source'] = (string)($manual['source'] ?? 'Unavailable');
        $row['definition'] = (string)(
            ($api['definition'] ?? '')
            ?: ($manual['definition'] ?? '')
        );
        $row['format'] = $format;

        return $row;
    }

    private static function compareScalar(
        string $label,
        mixed $apiValue,
        mixed $manualValue,
        string $format
    ): array {
        $apiAvailable = is_numeric($apiValue);
        $manualAvailable = is_numeric($manualValue);

        if (!$apiAvailable || !$manualAvailable) {
            $status = 'incomplete';

            if (!$apiAvailable && $manualAvailable) {
                $status = 'api_unavailable';
            } elseif ($apiAvailable && !$manualAvailable) {
                $status = 'manual_unavailable';
            } elseif (!$apiAvailable && !$manualAvailable) {
                $status = 'both_unavailable';
            }

            return [
                'label' => $label,
                'format' => $format,
                'api_value' => $apiAvailable
                    ? (float)$apiValue
                    : null,
                'manual_value' => $manualAvailable
                    ? (float)$manualValue
                    : null,
                'difference' => null,
                'difference_percent' => null,
                'comparable' => false,
                'status' => $status,
            ];
        }

        $a = (float)$apiValue;
        $m = (float)$manualValue;
        $difference = $a - $m;

        $differencePercent = abs($m) > 0.000001
            ? ($difference / abs($m)) * 100
            : ($difference == 0.0 ? 0.0 : null);

        return [
            'label' => $label,
            'format' => $format,
            'api_value' => $a,
            'manual_value' => $m,
            'difference' => $difference,
            'difference_percent' => $differencePercent,
            'comparable' => true,
            'status' => self::matches($a, $m, $format)
                ? 'matched'
                : 'review',
        ];
    }

    private static function matches(
        float $apiValue,
        float $manualValue,
        string $format
    ): bool {
        $difference = abs($apiValue - $manualValue);

        return match ($format) {
            'currency' => $difference <= max(
                self::CURRENCY_ABS_TOLERANCE,
                abs($manualValue) * self::CURRENCY_REL_TOLERANCE
            ),
            'percent_points' =>
                $difference <= self::PERCENT_POINT_TOLERANCE,
            'number' =>
                (int)round($apiValue) === (int)round($manualValue),
            'decimal' =>
                $difference <= self::DECIMAL_TOLERANCE,
            default =>
                $difference <= 0.000001,
        };
    }

    private static function indexProviders(array $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $name = trim((string)($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $key = self::providerKey($name);
            if ($key === '') {
                continue;
            }

            $out[$key] = $row;
        }

        return $out;
    }

    private static function providerKey(string $name): string
    {
        $name = trim($name);
        $name = preg_replace('/\s+/', ' ', $name) ?? $name;
        return strtolower($name);
    }

    private static function indexDaily(array $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $date = trim((string)($row['date'] ?? ''));
            if ($date === '') {
                continue;
            }

            $out[$date] = $row;
        }

        return $out;
    }

    private static function groupStatus(array $metrics): string
    {
        $comparable = 0;

        foreach ($metrics as $metric) {
            if (empty($metric['comparable'])) {
                continue;
            }

            $comparable++;

            if (($metric['status'] ?? '') === 'review') {
                return 'review';
            }
        }

        return $comparable > 0
            ? 'matched'
            : 'incomplete';
    }

    private static function summarizeComparison(
        array $metricRows,
        array $providerRows,
        array $dailyRows
    ): array {
        $comparable = 0;
        $matched = 0;
        $review = 0;
        $unavailable = 0;

        $consume = static function (array $row) use (
            &$comparable,
            &$matched,
            &$review,
            &$unavailable
        ): void {
            if (empty($row['comparable'])) {
                $unavailable++;
                return;
            }

            $comparable++;

            if (($row['status'] ?? '') === 'matched') {
                $matched++;
            } else {
                $review++;
            }
        };

        foreach ($metricRows as $row) {
            $consume($row);
        }

        foreach ($providerRows as $provider) {
            foreach ((array)($provider['metrics'] ?? []) as $row) {
                $consume($row);
            }
        }

        foreach ($dailyRows as $day) {
            foreach ((array)($day['metrics'] ?? []) as $row) {
                $consume($row);
            }
        }

        return [
            'overall_status' => $comparable === 0
                ? 'incomplete'
                : ($review === 0 ? 'verified' : 'review'),
            'comparable' => $comparable,
            'matched' => $matched,
            'review' => $review,
            'unavailable' => $unavailable,
            'match_percent' => $comparable > 0
                ? round(($matched / $comparable) * 100, 1)
                : 0.0,
        ];
    }

    private static function assertLookupInputs(
        int $businessId,
        string $periodStart,
        string $periodEnd
    ): void {
        if ($businessId < 1) {
            throw new InvalidArgumentException('A valid RUMA business ID is required.');
        }

        $start = self::strictDate($periodStart);
        $end = self::strictDate($periodEnd);

        if ($start > $end) {
            throw new InvalidArgumentException(
                'Boulevard comparison period start cannot be after period end.'
            );
        }
    }

    private static function strictDate(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));
        $errors = DateTimeImmutable::getLastErrors();

        if (
            !$date
            || ($errors !== false && (
                ($errors['warning_count'] ?? 0) > 0
                || ($errors['error_count'] ?? 0) > 0
            ))
            || $date->format('Y-m-d') !== trim($value)
        ) {
            throw new InvalidArgumentException(
                'Boulevard comparison dates must use YYYY-MM-DD.'
            );
        }

        return $date;
    }

    private static function assertApiBatchSource(
        int $businessId,
        int $batchId
    ): void {
        $stmt = db()->prepare(
            "SELECT sr.id
             FROM boulevard_sync_runs sr
             INNER JOIN upload_batches ub
                ON ub.id = sr.upload_batch_id
             WHERE sr.business_id = ?
               AND ub.business_id = ?
               AND ub.id = ?
               AND ub.status = 'completed'
               AND sr.status IN ('completed', 'partial')
             ORDER BY sr.id DESC
             LIMIT 1"
        );

        $stmt->execute([
            $businessId,
            $businessId,
            $batchId,
        ]);

        if (!$stmt->fetchColumn()) {
            throw new RuntimeException(
                'The selected API batch is not a completed Boulevard Report Export batch.'
            );
        }
    }

    private static function assertManualBatchSource(
        int $businessId,
        int $batchId
    ): void {
        $stmt = db()->prepare(
            "SELECT ub.id
             FROM upload_batches ub
             WHERE ub.business_id = ?
               AND ub.id = ?
               AND ub.status = 'completed'
               AND NOT EXISTS (
                    SELECT 1
                    FROM boulevard_sync_runs sr
                    WHERE sr.upload_batch_id = ub.id
               )
             LIMIT 1"
        );

        $stmt->execute([
            $businessId,
            $batchId,
        ]);

        if (!$stmt->fetchColumn()) {
            throw new RuntimeException(
                'The selected manual batch is not a completed manual Boulevard upload.'
            );
        }
    }
}
