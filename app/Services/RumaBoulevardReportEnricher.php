<?php

declare(strict_types=1);

/**
 * RumaBoulevardReportEnricher
 *
 * Uses an already-processed Boulevard Report Export batch as a fallback for
 * metrics that the direct Admin API cannot return because of schema/scope
 * differences. It never overwrites a direct live metric that is already
 * available.
 */
final class RumaBoulevardReportEnricher
{
    public static function loadExactPeriodDashboard(
        int $businessId,
        string $periodStart,
        string $periodEnd
    ): ?array {
        if ($businessId < 1 || !function_exists('db')) {
            return null;
        }

        $stmt = db()->prepare(
            "SELECT dashboard_json
             FROM upload_batches
             WHERE business_id=?
               AND period_start=?
               AND period_end=?
               AND status='completed'
               AND COALESCE(validation_status,'validated') IN ('validated','warning','approved')
             ORDER BY id DESC
             LIMIT 1"
        );
        $stmt->execute([$businessId, $periodStart, $periodEnd]);
        $json = $stmt->fetchColumn();
        if (!is_string($json) || trim($json) === '') {
            return null;
        }

        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : null;
    }

    public static function merge(array $analytics, array $reportDashboard): array
    {
        $k = is_array($reportDashboard['kpis'] ?? null)
            ? $reportDashboard['kpis']
            : [];

        $metric = static function (array $source, string $key): ?array {
            $row = $source[$key] ?? null;
            return is_array($row) && array_key_exists('value', $row) ? $row : null;
        };

        $fill = static function (
            array &$target,
            string $key,
            ?array $sourceMetric,
            ?callable $transform = null
        ): void {
            if ($sourceMetric === null) {
                return;
            }
            $current = $target[$key] ?? null;
            if (is_array($current) && !empty($current['available'])) {
                return;
            }

            $value = $sourceMetric['value'] ?? null;
            if ($transform !== null) {
                $value = $transform($value);
            }

            $previous = $sourceMetric['previous'] ?? null;
            if ($previous !== null && $transform !== null) {
                $previous = $transform($previous);
            }

            $target[$key] = [
                'value' => $value,
                'format' => (string)($sourceMetric['format'] ?? 'number'),
                'available' => true,
                'source' => 'Boulevard Report Export API fallback',
                'previous' => $previous,
                'change' => isset($sourceMetric['percent_change']) && is_numeric($sourceMetric['percent_change'])
                    ? ((float)$sourceMetric['percent_change'] / 100.0)
                    : null,
            ];
        };

        $analytics['performance'] ??= [];
        $fill($analytics['performance'], 'blended_utilization', $metric($k, 'utilization'), static fn($v) => is_numeric($v) ? (float)$v / 100.0 : null);
        $fill($analytics['performance'], 'current_active_mrr', $metric($k, 'active_mrr'));
        $fill($analytics['performance'], 'new_clients', $metric($k, 'new_clients'));

        $analytics['sales_summary'] ??= [];
        $fill($analytics['sales_summary'], 'service_revenue', $metric($k, 'service_revenue'));
        $fill($analytics['sales_summary'], 'product_revenue', $metric($k, 'product_revenue'));
        $fill($analytics['sales_summary'], 'membership_revenue', $metric($k, 'membership_revenue'));
        $fill($analytics['sales_summary'], 'package_revenue', $metric($k, 'package_revenue'));
        $fill($analytics['sales_summary'], 'requested_appointments', $metric($k, 'requested_appointments'));

        $analytics['financial'] ??= [];
        $fill($analytics['financial'], 'net_sales', $metric($k, 'net_sales'));
        $fill($analytics['financial'], 'gross_payments', $metric($k, 'gross_payments'));
        $fill($analytics['financial'], 'refunds', $metric($k, 'refunds'));
        $fill($analytics['financial'], 'gift_cards_sold', $metric($k, 'gift_cards_sold'));
        $fill($analytics['financial'], 'account_credit_sold', $metric($k, 'account_credit_sold'));
        $fill($analytics['financial'], 'tax_collected', $metric($k, 'tax_collected'));
        $fill($analytics['financial'], 'card_fees', $metric($k, 'card_fees'));

        $analytics['membership'] ??= [];
        $fill($analytics['membership'], 'current_active_mrr', $metric($k, 'active_mrr'));
        $fill($analytics['membership'], 'current_arr', $metric($k, 'active_arr'));
        $fill($analytics['membership'], 'active_memberships', $metric($k, 'active_memberships'));

        if (empty($analytics['membership']['trend']) && isset($k['active_mrr']) && is_array($k['active_mrr'])) {
            $trend = [];
            $previousMrr = $k['active_mrr']['previous'] ?? null;
            if (is_numeric($previousMrr)) {
                $trend[] = [
                    'date' => 'Previous period',
                    'mrr' => (float)$previousMrr,
                ];
            }
            if (is_numeric($k['active_mrr']['value'] ?? null)) {
                $trend[] = [
                    'date' => 'Current period',
                    'mrr' => (float)$k['active_mrr']['value'],
                ];
            }
            if ($trend) {
                $analytics['membership']['trend'] = $trend;
            }
        }

        if (
            (!isset($analytics['membership']['change_since_previous_period'])
                || empty($analytics['membership']['change_since_previous_period']['available']))
            && isset($k['active_mrr']['percent_change'])
            && is_numeric($k['active_mrr']['percent_change'])
        ) {
            $analytics['membership']['change_since_previous_period'] = [
                'value' => (float)$k['active_mrr']['percent_change'] / 100.0,
                'format' => 'percent',
                'available' => true,
                'source' => 'Boulevard Report Export API fallback',
                'previous' => null,
                'change' => null,
            ];
        }

        self::mergeProviders($analytics, $reportDashboard);
        self::mergeRevenueMix($analytics, $reportDashboard);
        self::mergeDaily($analytics, $reportDashboard);
        self::refreshCoverage($analytics);

        $analytics['meta']['report_export_fallback_used'] = true;

        return $analytics;
    }

    private static function mergeProviders(array &$analytics, array $reportDashboard): void
    {
        $reportProviders = is_array($reportDashboard['providers'] ?? null)
            ? $reportDashboard['providers']
            : [];
        if (!$reportProviders) {
            return;
        }

        $byName = [];
        foreach ($analytics['provider_details'] ?? [] as $i => $row) {
            $name = self::key((string)($row['provider'] ?? ''));
            if ($name !== '') {
                $byName[$name] = $i;
            }
        }

        foreach ($reportProviders as $rp) {
            if (!is_array($rp)) {
                continue;
            }
            $name = trim((string)($rp['name'] ?? ''));
            $key = self::key($name);
            if ($key === '') {
                continue;
            }

            if (!isset($byName[$key])) {
                $analytics['provider_details'][] = [
                    'provider' => $name,
                    'service_revenue' => 0.0,
                    'service_revenue_available' => false,
                    'booked_service_value' => 0.0,
                    'utilization' => null,
                    'utilization_available' => false,
                    'scheduled_hours' => 0.0,
                    'appointments' => 0,
                    'new_clients' => 0,
                    'new_clients_available' => false,
                    'revenue_per_hour' => null,
                    'revenue_per_hour_available' => false,
                    'retail_sales' => 0.0,
                ];
                $byName[$key] = array_key_last($analytics['provider_details']);
            }

            $idx = $byName[$key];
            $row =& $analytics['provider_details'][$idx];

            if (empty($row['service_revenue_available'])) {
                $row['service_revenue'] = (float)($rp['service_revenue'] ?? 0);
                $row['service_revenue_available'] = true;
            }
            if (empty($row['utilization_available'])) {
                $row['utilization'] = isset($rp['utilization']) ? (float)$rp['utilization'] / 100.0 : null;
                $row['utilization_available'] = $row['utilization'] !== null;
            }
            if ((float)($row['scheduled_hours'] ?? 0) <= 0 && isset($rp['hours_scheduled'])) {
                $row['scheduled_hours'] = (float)$rp['hours_scheduled'];
            }
            if ((int)($row['appointments'] ?? 0) <= 0 && isset($rp['appointments'])) {
                $row['appointments'] = (int)round((float)$rp['appointments']);
            }
            if (empty($row['new_clients_available']) && isset($rp['new_clients'])) {
                $row['new_clients'] = (int)round((float)$rp['new_clients']);
                $row['new_clients_available'] = true;
            }
            if (empty($row['revenue_per_hour_available']) && isset($rp['revenue_per_hour'])) {
                $row['revenue_per_hour'] = (float)$rp['revenue_per_hour'];
                $row['revenue_per_hour_available'] = true;
            }
            if ((float)($row['retail_sales'] ?? 0) <= 0 && isset($rp['product_revenue'])) {
                $row['retail_sales'] = (float)$rp['product_revenue'];
            }
            unset($row);
        }

        $analytics['provider_performance'] = array_map(
            static fn(array $provider): array => [
                'provider' => $provider['provider'],
                'service_revenue' => $provider['service_revenue'],
                'service_revenue_available' => $provider['service_revenue_available'],
                'booked_service_value' => $provider['booked_service_value'],
                'utilization' => $provider['utilization'],
                'utilization_available' => $provider['utilization_available'],
                'scheduled_hours' => $provider['scheduled_hours'],
                'appointments' => $provider['appointments'],
                'new_clients' => $provider['new_clients'],
                'new_clients_available' => $provider['new_clients_available'],
                'revenue_per_hour' => $provider['revenue_per_hour'],
                'revenue_per_hour_available' => $provider['revenue_per_hour_available'],
                'retail_sales' => $provider['retail_sales'],
            ],
            $analytics['provider_details']
        );
        $analytics['revenue_per_scheduled_hour'] = $analytics['provider_performance'];
    }

    private static function mergeRevenueMix(array &$analytics, array $reportDashboard): void
    {
        $categories = is_array($reportDashboard['revenue_categories'] ?? null)
            ? $reportDashboard['revenue_categories']
            : [];
        if (!$categories) {
            return;
        }

        $map = [];
        foreach ($categories as $row) {
            if (!is_array($row)) {
                continue;
            }
            $label = strtolower(trim((string)($row['label'] ?? '')));
            $map[$label] = (float)($row['value'] ?? 0);
        }

        $analytics['revenue_mix'] ??= [];
        $analytics['revenue_mix']['service'] = $analytics['revenue_mix']['available_types']['service'] ?? false
            ? ($analytics['revenue_mix']['service'] ?? 0.0)
            : (float)($map['services'] ?? 0);
        $analytics['revenue_mix']['product'] = $analytics['revenue_mix']['available_types']['product'] ?? false
            ? ($analytics['revenue_mix']['product'] ?? 0.0)
            : (float)($map['all products'] ?? 0);
        $analytics['revenue_mix']['membership'] = $analytics['revenue_mix']['available_types']['membership'] ?? false
            ? ($analytics['revenue_mix']['membership'] ?? 0.0)
            : (float)($map['memberships'] ?? 0);
        $analytics['revenue_mix']['package'] = $analytics['revenue_mix']['available_types']['package'] ?? false
            ? ($analytics['revenue_mix']['package'] ?? 0.0)
            : (float)($map['packages'] ?? 0);
        $analytics['revenue_mix']['gratuity'] = (float)($analytics['revenue_mix']['gratuity'] ?? ($map['tips'] ?? 0));

        $analytics['revenue_mix']['available_types'] ??= [];
        foreach (['service', 'product', 'membership', 'package'] as $type) {
            $analytics['revenue_mix']['available_types'][$type] = true;
        }
    }

    private static function mergeDaily(array &$analytics, array $reportDashboard): void
    {
        if (!empty($analytics['daily_performance'])) {
            return;
        }
        $daily = is_array($reportDashboard['daily'] ?? null)
            ? $reportDashboard['daily']
            : [];
        if (!$daily) {
            return;
        }

        $rows = [];
        foreach ($daily as $row) {
            if (!is_array($row)) {
                continue;
            }
            $label = (string)($row['label'] ?? '');
            $ts = strtotime($label);
            $rows[] = [
                'date' => $ts !== false ? date('Y-m-d', $ts) : $label,
                'revenue' => (float)($row['revenue'] ?? 0),
                'appointments' => (int)round((float)($row['appointments'] ?? 0)),
                'cancelled' => 0,
            ];
        }
        $analytics['daily_performance'] = $rows;
    }

    private static function refreshCoverage(array &$analytics): void
    {
        $performance = $analytics['performance'] ?? [];
        $sales = $analytics['sales_summary'] ?? [];
        $financial = $analytics['financial'] ?? [];

        $has = static fn(array $section, string $key): bool =>
            !empty($section[$key]['available']);

        $analytics['coverage'] = [
            [
                'area' => 'Appointments / new clients',
                'available' => $has($performance, 'total_appointments') && $has($performance, 'new_clients'),
                'note' => 'Direct Admin API or Boulevard Report Export API.',
            ],
            [
                'area' => 'Provider utilization / scheduled hours',
                'available' => $has($performance, 'blended_utilization'),
                'note' => 'Direct shift data or Appointment Metrics report fallback.',
            ],
            [
                'area' => 'Membership MRR / ARR',
                'available' => $has($performance, 'current_active_mrr'),
                'note' => 'Direct membership data or Subscriptions report fallback.',
            ],
            [
                'area' => 'Service / product / membership / package revenue',
                'available' => $has($sales, 'service_revenue')
                    && $has($sales, 'product_revenue')
                    && $has($sales, 'membership_revenue')
                    && $has($sales, 'package_revenue'),
                'note' => 'Detailed order lines or Sales/Daily Summary report fallback.',
            ],
            [
                'area' => 'Gift cards / account credit / card fees',
                'available' => $has($financial, 'gift_cards_sold')
                    && $has($financial, 'account_credit_sold')
                    && $has($financial, 'card_fees'),
                'note' => 'Typed order lines/payment groups or Sales Summary report fallback.',
            ],
        ];
    }

    private static function key(string $value): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', $value) ?? $value));
    }
}
