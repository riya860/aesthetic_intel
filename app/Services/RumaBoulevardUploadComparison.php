<?php

declare(strict_types=1);

/**
 * Compares RUMA Boulevard Priority Intelligence (live API) with the
 * canonical dashboard created by the existing Boulevard upload pipeline.
 *
 * The upload dashboard is the normalized output already stored in
 * upload_batches.dashboard_json, so this comparison does not duplicate the
 * upload parsers or introduce a second interpretation of the source reports.
 */
final class RumaBoulevardUploadComparison
{
    private const CURRENCY_ABS_TOLERANCE = 1.00;
    private const CURRENCY_REL_TOLERANCE = 0.001; // 0.1%
    private const PERCENT_POINT_TOLERANCE = 0.5;  // 0.5 percentage points

    public static function findExactPeriodBatches(
        int $businessId,
        string $periodStart,
        string $periodEnd
    ): array {
        if ($businessId < 1 || $periodStart === '' || $periodEnd === '') {
            return [];
        }

        $stmt = db()->prepare(
            "SELECT id, period_start, period_end, frequency,
                    validation_status, completeness_score, created_at
             FROM upload_batches
             WHERE business_id = ?
               AND period_start = ?
               AND period_end = ?
               AND status = 'completed'
               AND COALESCE(validation_status,'validated')
                   IN ('validated','warning','approved')
             ORDER BY id DESC"
        );
        $stmt->execute([$businessId, $periodStart, $periodEnd]);
        return $stmt->fetchAll() ?: [];
    }

    public static function loadBatch(
        int $businessId,
        int $batchId,
        string $periodStart,
        string $periodEnd
    ): ?array {
        $stmt = db()->prepare(
            "SELECT id, business_id, period_start, period_end, frequency,
                    validation_status, completeness_score, created_at,
                    dashboard_json
             FROM upload_batches
             WHERE id = ?
               AND business_id = ?
               AND period_start = ?
               AND period_end = ?
               AND status = 'completed'
               AND COALESCE(validation_status,'validated')
                   IN ('validated','warning','approved')
             LIMIT 1"
        );
        $stmt->execute([$batchId, $businessId, $periodStart, $periodEnd]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        $dashboard = json_decode((string)$row['dashboard_json'], true);
        if (!is_array($dashboard)) {
            return null;
        }

        $row['dashboard'] = $dashboard;
        unset($row['dashboard_json']);
        return $row;
    }

    public static function compare(array $api, array $uploadDashboard): array
    {
        $uploadKpis = is_array($uploadDashboard['kpis'] ?? null)
            ? $uploadDashboard['kpis']
            : [];

        $definitions = [
            ['Total revenue', 'performance.total_revenue', 'total_revenue', 'currency'],
            ['Total appointments', 'performance.total_appointments', 'appointments', 'number'],
            ['New clients', 'performance.new_clients', 'new_clients', 'number'],
            ['Blended utilization', 'performance.blended_utilization', 'utilization', 'percent'],
            ['Current active MRR', 'performance.current_active_mrr', 'active_mrr', 'currency'],
            ['Requested appointments', 'sales_summary.requested_appointments', 'requested_appointments', 'number'],
            ['Service revenue', 'sales_summary.service_revenue', 'service_revenue', 'currency'],
            ['All product revenue', 'sales_summary.product_revenue', 'product_revenue', 'currency'],
            ['Membership revenue', 'sales_summary.membership_revenue', 'membership_revenue', 'currency'],
            ['Package revenue', 'sales_summary.package_revenue', 'package_revenue', 'currency'],
            ['Tips', 'sales_summary.tips', 'tips', 'currency'],
            ['Net sales', 'financial.net_sales', 'net_sales', 'currency'],
            ['Gross payments', 'financial.gross_payments', 'gross_payments', 'currency'],
            ['Refunds', 'financial.refunds', 'refunds', 'currency'],
            ['Gift cards sold', 'financial.gift_cards_sold', 'gift_cards_sold', 'currency'],
            ['Account credit sold', 'financial.account_credit_sold', 'account_credit_sold', 'currency'],
            ['Tax collected', 'financial.tax_collected', 'tax_collected', 'currency'],
            ['Card fees', 'financial.card_fees', 'card_fees', 'currency'],
            ['Current ARR', 'membership.current_arr', 'active_arr', 'currency'],
            ['Active memberships', 'membership.active_memberships', 'active_memberships', 'number'],
        ];

        $rows = [];
        foreach ($definitions as [$label, $apiPath, $uploadKey, $format]) {
            $apiMetric = self::path($api, $apiPath);
            $uploadMetric = $uploadKpis[$uploadKey] ?? null;

            /* The legacy Boulevard dashboard stores utilization as 0..100,
             * while Priority Intelligence stores percentages as 0..1. */
            if (
                $format === 'percent'
                && is_array($uploadMetric)
                && is_numeric($uploadMetric['value'] ?? null)
            ) {
                $uploadMetric['value'] = (float)$uploadMetric['value'] / 100.0;
            }

            $rows[] = self::compareMetric(
                $label,
                is_array($apiMetric) ? $apiMetric : null,
                is_array($uploadMetric) ? $uploadMetric : null,
                $format
            );
        }

        $providerRows = self::compareProviders(
            is_array($api['provider_details'] ?? null) ? $api['provider_details'] : [],
            is_array($uploadDashboard['providers'] ?? null) ? $uploadDashboard['providers'] : []
        );

        $comparable = count(array_filter($rows, static fn(array $r): bool => $r['comparable']));
        $matched = count(array_filter($rows, static fn(array $r): bool => $r['status'] === 'matched'));
        $review = count(array_filter($rows, static fn(array $r): bool => $r['status'] === 'review'));
        $unavailable = count($rows) - $comparable;

        $providerComparable = 0;
        $providerMatched = 0;
        $providerReview = 0;
        foreach ($providerRows as $provider) {
            foreach ($provider['metrics'] as $metric) {
                if (!$metric['comparable']) {
                    continue;
                }
                $providerComparable++;
                if ($metric['status'] === 'matched') {
                    $providerMatched++;
                } elseif ($metric['status'] === 'review') {
                    $providerReview++;
                }
            }
        }

        $allComparable = $comparable + $providerComparable;
        $allMatched = $matched + $providerMatched;
        $allReview = $review + $providerReview;

        return [
            'overall_status' => $allComparable === 0
                ? 'incomplete'
                : ($allReview === 0 ? 'verified' : 'review'),
            'comparable_metrics' => $allComparable,
            'matched_metrics' => $allMatched,
            'review_metrics' => $allReview,
            'unavailable_metrics' => $unavailable,
            'match_percent' => $allComparable > 0
                ? round(($allMatched / $allComparable) * 100, 1)
                : 0.0,
            'metrics' => $rows,
            'providers' => $providerRows,
            'tolerances' => [
                'currency' => '±$1.00 or ±0.1%, whichever is larger',
                'number' => 'Exact whole-number match',
                'percent' => '±0.5 percentage points',
            ],
        ];
    }

    private static function compareProviders(array $apiProviders, array $uploadProviders): array
    {
        $apiByName = [];
        foreach ($apiProviders as $provider) {
            if (!is_array($provider)) continue;
            $name = trim((string)($provider['provider'] ?? ''));
            if ($name !== '') $apiByName[self::key($name)] = $provider;
        }

        $uploadByName = [];
        foreach ($uploadProviders as $provider) {
            if (!is_array($provider)) continue;
            $name = trim((string)($provider['name'] ?? ''));
            if ($name !== '') $uploadByName[self::key($name)] = $provider;
        }

        $keys = array_values(array_unique(array_merge(array_keys($apiByName), array_keys($uploadByName))));
        $rows = [];

        foreach ($keys as $key) {
            $a = $apiByName[$key] ?? null;
            $u = $uploadByName[$key] ?? null;
            $name = trim((string)(($a['provider'] ?? null) ?: ($u['name'] ?? 'Unknown Provider')));

            $apiMetric = static function (?array $provider, string $valueKey, string $availableKey = ''): ?array {
                if ($provider === null) return null;
                $available = $availableKey === '' ? array_key_exists($valueKey, $provider) : !empty($provider[$availableKey]);
                return [
                    'available' => $available,
                    'value' => $provider[$valueKey] ?? null,
                ];
            };
            $uploadMetric = static function (?array $provider, string $valueKey): ?array {
                if ($provider === null || !array_key_exists($valueKey, $provider)) return null;
                return ['available' => true, 'value' => $provider[$valueKey]];
            };

            $metrics = [
                self::compareMetric('Service revenue', $apiMetric($a, 'service_revenue', 'service_revenue_available'), $uploadMetric($u, 'service_revenue'), 'currency'),
                self::compareMetric('Utilization', $apiMetric($a, 'utilization', 'utilization_available'), self::percentUploadMetric($u, 'utilization'), 'percent'),
                self::compareMetric('Scheduled hours', $apiMetric($a, 'scheduled_hours', 'utilization_available'), $uploadMetric($u, 'hours_scheduled'), 'decimal'),
                self::compareMetric('Appointments', $apiMetric($a, 'appointments'), $uploadMetric($u, 'appointments'), 'number'),
                self::compareMetric('New clients', $apiMetric($a, 'new_clients', 'new_clients_available'), $uploadMetric($u, 'new_clients'), 'number'),
                self::compareMetric('Revenue / hour', $apiMetric($a, 'revenue_per_hour', 'revenue_per_hour_available'), $uploadMetric($u, 'revenue_per_hour'), 'currency'),
                self::compareMetric('Retail sales', $apiMetric($a, 'retail_sales'), $uploadMetric($u, 'product_revenue'), 'currency'),
            ];

            $rows[] = [
                'provider' => $name,
                'status' => count(array_filter($metrics, static fn(array $m): bool => $m['status'] === 'review')) > 0
                    ? 'review'
                    : (count(array_filter($metrics, static fn(array $m): bool => $m['comparable'])) > 0 ? 'matched' : 'incomplete'),
                'metrics' => $metrics,
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            $rank = ['review' => 0, 'incomplete' => 1, 'matched' => 2];
            $cmp = ($rank[$a['status']] ?? 9) <=> ($rank[$b['status']] ?? 9);
            return $cmp !== 0 ? $cmp : strcasecmp($a['provider'], $b['provider']);
        });

        return $rows;
    }

    private static function percentUploadMetric(?array $provider, string $key): ?array
    {
        if ($provider === null || !array_key_exists($key, $provider)) return null;
        return [
            'available' => true,
            'value' => is_numeric($provider[$key]) ? ((float)$provider[$key] / 100.0) : null,
        ];
    }

    private static function compareMetric(
        string $label,
        ?array $apiMetric,
        ?array $uploadMetric,
        string $format
    ): array {
        $apiAvailable = is_array($apiMetric)
            && !empty($apiMetric['available'])
            && is_numeric($apiMetric['value'] ?? null);
        $uploadAvailable = is_array($uploadMetric)
            && (array_key_exists('available', $uploadMetric) ? !empty($uploadMetric['available']) : true)
            && is_numeric($uploadMetric['value'] ?? null);

        $apiValue = $apiAvailable ? (float)$apiMetric['value'] : null;
        $uploadValue = $uploadAvailable ? (float)$uploadMetric['value'] : null;

        if (!$apiAvailable || !$uploadAvailable) {
            return [
                'label' => $label,
                'format' => $format,
                'api_value' => $apiValue,
                'upload_value' => $uploadValue,
                'difference' => null,
                'difference_percent' => null,
                'comparable' => false,
                'status' => !$apiAvailable ? 'api_unavailable' : 'upload_unavailable',
            ];
        }

        $difference = $apiValue - $uploadValue;
        $differencePercent = abs($uploadValue) > 0.000001
            ? ($difference / abs($uploadValue)) * 100.0
            : ($difference == 0.0 ? 0.0 : null);
        $matched = self::matches($apiValue, $uploadValue, $format);

        return [
            'label' => $label,
            'format' => $format,
            'api_value' => $apiValue,
            'upload_value' => $uploadValue,
            'difference' => $difference,
            'difference_percent' => $differencePercent,
            'comparable' => true,
            'status' => $matched ? 'matched' : 'review',
        ];
    }

    private static function matches(float $api, float $upload, string $format): bool
    {
        $diff = abs($api - $upload);

        return match ($format) {
            'currency' => $diff <= max(
                self::CURRENCY_ABS_TOLERANCE,
                abs($upload) * self::CURRENCY_REL_TOLERANCE
            ),
            'percent' => ($diff * 100.0) <= self::PERCENT_POINT_TOLERANCE,
            'number' => (int)round($api) === (int)round($upload),
            'decimal' => $diff <= 0.1,
            default => $diff <= 0.000001,
        };
    }

    private static function path(array $source, string $path): mixed
    {
        $value = $source;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }
        return $value;
    }

    private static function key(string $value): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', $value) ?? $value));
    }
}
