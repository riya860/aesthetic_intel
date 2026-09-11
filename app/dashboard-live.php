<?php

declare(strict_types=1);

require_once ROOT_PATH . '/app/google-business-platform.php';
require_once ROOT_PATH . '/app/boulevard-api.php';
require_once ROOT_PATH . '/app/Services/Boulevard/BoulevardUnifiedService.php';

/**
 * Live data helpers for the focused business dashboard.
 *
 * The executive dashboard never presents a stale database snapshot as current.
 * Each source is refreshed on demand through the existing integration layer.
 * The selected reporting view controls the exact API date range:
 *
 * - weekly: last 7 completed business days vs the prior 7 days
 * - mtd:    first day of the current data month through the latest completed
 *           day vs the same number of days in the previous month
 * - ytd:    January 1 through the latest completed day vs the matching
 *           year-to-date range one year earlier
 *
 * The cutoff intentionally uses the latest completed day (normally yesterday
 * in the business/location timezone) so partial same-day activity is not mixed
 * with complete historical days.
 */

function dashboard_live_period_key(string $period): string
{
    $period = strtolower(trim($period));
    return in_array($period, ['weekly', 'mtd', 'ytd'], true) ? $period : 'weekly';
}

function dashboard_live_timezone(string $timezone): DateTimeZone
{
    try {
        return new DateTimeZone($timezone !== '' ? $timezone : 'UTC');
    } catch (Throwable) {
        return new DateTimeZone('UTC');
    }
}

function dashboard_live_period(string $timezone = 'UTC', string $period = 'weekly'): array
{
    $key = dashboard_live_period_key($period);
    $tz = dashboard_live_timezone($timezone);
    $today = new DateTimeImmutable('today', $tz);
    $end = $today->modify('-1 day');

    if ($key === 'mtd') {
        $start = $end->modify('first day of this month');
        $elapsedDays = (int)$start->diff($end)->format('%a');

        $previousStart = $start->modify('first day of previous month');
        $candidatePreviousEnd = $previousStart->modify('+' . $elapsedDays . ' days');
        $previousMonthEnd = $previousStart->modify('last day of this month');
        $previousEnd = $candidatePreviousEnd > $previousMonthEnd
            ? $previousMonthEnd
            : $candidatePreviousEnd;

        return [
            'key' => $key,
            'timezone' => $tz->getName(),
            'start' => $start,
            'end' => $end,
            'previous_start' => $previousStart,
            'previous_end' => $previousEnd,
            'label' => 'Monthly MTD',
            'short_label' => 'Month to date',
            'revenue_label' => 'MTD revenue',
            'comparison_label' => 'previous month MTD',
            'calculation_basis' => 'Daily totals from the first day of the month through the latest completed day.',
        ];
    }

    if ($key === 'ytd') {
        $year = (int)$end->format('Y');
        $month = (int)$end->format('n');
        $day = (int)$end->format('j');
        $previousYear = $year - 1;

        $start = $end->setDate($year, 1, 1);
        $previousStart = $end->setDate($previousYear, 1, 1);

        $previousMonthProbe = $end->setDate($previousYear, $month, 1);
        $daysInPreviousMonth = (int)$previousMonthProbe->format('t');
        $previousEnd = $previousMonthProbe->setDate(
            $previousYear,
            $month,
            min($day, $daysInPreviousMonth)
        );

        return [
            'key' => $key,
            'timezone' => $tz->getName(),
            'start' => $start,
            'end' => $end,
            'previous_start' => $previousStart,
            'previous_end' => $previousEnd,
            'label' => 'Yearly YTD',
            'short_label' => 'Year to date',
            'revenue_label' => 'YTD revenue',
            'comparison_label' => 'previous year YTD',
            'calculation_basis' => 'Daily totals from January 1 through the latest completed day.',
        ];
    }

    $start = $end->modify('-6 days');
    $previousEnd = $start->modify('-1 day');
    $previousStart = $previousEnd->modify('-6 days');

    return [
        'key' => 'weekly',
        'timezone' => $tz->getName(),
        'start' => $start,
        'end' => $end,
        'previous_start' => $previousStart,
        'previous_end' => $previousEnd,
        'label' => 'Weekly',
        'short_label' => 'Last 7 completed days',
        'revenue_label' => 'Total revenue',
        'comparison_label' => 'previous 7 days',
        'calculation_basis' => 'Daily totals for the latest seven completed days.',
    ];
}

function dashboard_live_period_payload(array $period): array
{
    return [
        'period_key' => (string)$period['key'],
        'period_label' => (string)$period['label'],
        'period_short_label' => (string)$period['short_label'],
        'revenue_label' => (string)$period['revenue_label'],
        'comparison_label' => (string)$period['comparison_label'],
        'calculation_basis' => (string)$period['calculation_basis'],
        'period_start' => $period['start']->format('Y-m-d'),
        'period_end' => $period['end']->format('Y-m-d'),
        'previous_period_start' => $period['previous_start']->format('Y-m-d'),
        'previous_period_end' => $period['previous_end']->format('Y-m-d'),
        'data_through' => $period['end']->format('Y-m-d'),
    ];
}

function dashboard_live_change(?float $current, ?float $previous): ?float
{
    if ($current === null || $previous === null) {
        return null;
    }

    if (abs($previous) < 0.000001) {
        return abs($current) < 0.000001 ? 0.0 : null;
    }

    return (($current - $previous) / abs($previous)) * 100.0;
}

function dashboard_live_metric(
    string $key,
    string $label,
    float $current,
    float $previous,
    string $format = 'number'
): array {
    return [
        'key' => $key,
        'label' => $label,
        'value' => $current,
        'previous' => $previous,
        'change_percent' => dashboard_live_change($current, $previous),
        'format' => $format,
    ];
}

function dashboard_live_ga4_range_summary(int $businessId, string $start, string $end): array
{
    $propertyId = googlehub_ga4_property_id($businessId);
    $metricNames = [
        'sessions',
        'activeUsers',
        'newUsers',
        'engagedSessions',
        'engagementRate',
        'eventCount',
        'keyEvents',
        'totalRevenue',
    ];

    $response = googlehub_api_post(
        $businessId,
        'ga4',
        'https://analyticsdata.googleapis.com/v1beta/properties/'
            . rawurlencode($propertyId)
            . ':runReport',
        [
            'dateRanges' => [[
                'startDate' => $start,
                'endDate' => $end,
            ]],
            'metrics' => array_map(
                static fn(string $name): array => ['name' => $name],
                $metricNames
            ),
            'limit' => 1,
        ]
    );

    $headers = [];
    foreach ((array)($response['metricHeaders'] ?? []) as $index => $header) {
        $headers[$index] = (string)($header['name'] ?? '');
    }

    $values = [];
    $row = is_array($response['rows'][0] ?? null) ? $response['rows'][0] : [];
    foreach ((array)($row['metricValues'] ?? []) as $index => $value) {
        $name = (string)($headers[$index] ?? '');
        if ($name !== '') {
            $values[$name] = (float)($value['value'] ?? 0);
        }
    }

    foreach ($metricNames as $name) {
        if (!array_key_exists($name, $values)) {
            $values[$name] = 0.0;
        }
    }

    return $values;
}

function dashboard_live_ga4(int $businessId, array $business, string $periodKey = 'weekly'): array
{
    $connection = googlehub_connection($businessId, 'ga4');

    if (
        !$connection
        || (string)($connection['status'] ?? '') !== 'connected'
        || empty($connection['selected_resource_id'])
    ) {
        throw new RuntimeException('GA4 is not connected or no property is selected.');
    }

    $timezone = (string)($business['timezone'] ?? 'UTC');
    $period = dashboard_live_period($timezone, $periodKey);

    $current = dashboard_live_ga4_range_summary(
        $businessId,
        $period['start']->format('Y-m-d'),
        $period['end']->format('Y-m-d')
    );
    $previous = dashboard_live_ga4_range_summary(
        $businessId,
        $period['previous_start']->format('Y-m-d'),
        $period['previous_end']->format('Y-m-d')
    );

    $metrics = [
        dashboard_live_metric('sessions', 'Website sessions', (float)($current['sessions'] ?? 0), (float)($previous['sessions'] ?? 0)),
        dashboard_live_metric('activeUsers', 'Active users', (float)($current['activeUsers'] ?? 0), (float)($previous['activeUsers'] ?? 0)),
        dashboard_live_metric('newUsers', 'New users', (float)($current['newUsers'] ?? 0), (float)($previous['newUsers'] ?? 0)),
        dashboard_live_metric('engagementRate', 'Engagement rate', (float)($current['engagementRate'] ?? 0), (float)($previous['engagementRate'] ?? 0), 'ratio_percent'),
        dashboard_live_metric('keyEvents', 'Key events', (float)($current['keyEvents'] ?? 0), (float)($previous['keyEvents'] ?? 0)),
        dashboard_live_metric('totalRevenue', 'GA4 revenue', (float)($current['totalRevenue'] ?? 0), (float)($previous['totalRevenue'] ?? 0), 'currency'),
    ];

    return array_merge(
        [
            'source' => 'ga4',
            'label' => 'Google Analytics 4',
            'status' => 'live',
            'fetched_at' => date(DATE_ATOM),
            'resource_name' => (string)($connection['selected_resource_name'] ?? 'Selected GA4 property'),
            'metrics' => $metrics,
        ],
        dashboard_live_period_payload($period)
    );
}

function dashboard_live_gbp_range_summary(
    int $businessId,
    string $location,
    DateTimeImmutable $start,
    DateTimeImmutable $end
): array {
    if (!preg_match('#^locations/[^/]+$#', $location)) {
        throw new RuntimeException('Saved GBP location is invalid.');
    }

    $metricNames = [
        'BUSINESS_IMPRESSIONS_DESKTOP_MAPS',
        'BUSINESS_IMPRESSIONS_MOBILE_MAPS',
        'BUSINESS_IMPRESSIONS_DESKTOP_SEARCH',
        'BUSINESS_IMPRESSIONS_MOBILE_SEARCH',
        'WEBSITE_CLICKS',
        'CALL_CLICKS',
        'BUSINESS_DIRECTION_REQUESTS',
    ];

    $params = [];
    foreach ($metricNames as $metric) {
        $params[] = 'dailyMetrics=' . rawurlencode($metric);
    }

    foreach ([
        'dailyRange.start_date.year' => (int)$start->format('Y'),
        'dailyRange.start_date.month' => (int)$start->format('n'),
        'dailyRange.start_date.day' => (int)$start->format('j'),
        'dailyRange.end_date.year' => (int)$end->format('Y'),
        'dailyRange.end_date.month' => (int)$end->format('n'),
        'dailyRange.end_date.day' => (int)$end->format('j'),
    ] as $key => $value) {
        $params[] = rawurlencode($key) . '=' . $value;
    }

    $url = 'https://businessprofileperformance.googleapis.com/v1/'
        . $location
        . ':fetchMultiDailyMetricsTimeSeries?'
        . implode('&', $params);

    $response = googlehub_api_get($businessId, 'gbp', $url);
    $totals = array_fill_keys($metricNames, 0.0);

    foreach (($response['multiDailyMetricTimeSeries'] ?? []) as $group) {
        if (!is_array($group)) {
            continue;
        }

        foreach (($group['dailyMetricTimeSeries'] ?? []) as $series) {
            if (!is_array($series)) {
                continue;
            }

            $metric = (string)($series['dailyMetric'] ?? '');
            if (!array_key_exists($metric, $totals)) {
                continue;
            }

            foreach (($series['timeSeries']['datedValues'] ?? []) as $point) {
                if (!is_array($point)) {
                    continue;
                }
                $totals[$metric] += (float)($point['value'] ?? 0);
            }
        }
    }

    return [
        'maps_impressions' =>
            $totals['BUSINESS_IMPRESSIONS_DESKTOP_MAPS']
            + $totals['BUSINESS_IMPRESSIONS_MOBILE_MAPS'],
        'search_impressions' =>
            $totals['BUSINESS_IMPRESSIONS_DESKTOP_SEARCH']
            + $totals['BUSINESS_IMPRESSIONS_MOBILE_SEARCH'],
        'website_clicks' => $totals['WEBSITE_CLICKS'],
        'call_clicks' => $totals['CALL_CLICKS'],
        'direction_requests' => $totals['BUSINESS_DIRECTION_REQUESTS'],
    ];
}

function dashboard_live_gbp(int $businessId, array $business, string $periodKey = 'weekly'): array
{
    $connection = googlehub_connection($businessId, 'gbp');

    if (
        !$connection
        || (string)($connection['status'] ?? '') !== 'connected'
        || empty($connection['selected_resource_id'])
    ) {
        throw new RuntimeException('Google Business Profile is not connected or no location is selected.');
    }

    $timezone = (string)($business['timezone'] ?? 'UTC');
    $period = dashboard_live_period($timezone, $periodKey);
    $location = (string)$connection['selected_resource_id'];

    /*
     * Query the GBP Performance API directly for the selected range. This is
     * intentionally not calculated from an older local sync, and it also avoids
     * the 180-day sync-window limit when the user selects Yearly YTD.
     */
    $current = dashboard_live_gbp_range_summary(
        $businessId,
        $location,
        $period['start'],
        $period['end']
    );
    $previous = dashboard_live_gbp_range_summary(
        $businessId,
        $location,
        $period['previous_start'],
        $period['previous_end']
    );

    $currentActions =
        (float)($current['website_clicks'] ?? 0)
        + (float)($current['call_clicks'] ?? 0)
        + (float)($current['direction_requests'] ?? 0);

    $previousActions =
        (float)($previous['website_clicks'] ?? 0)
        + (float)($previous['call_clicks'] ?? 0)
        + (float)($previous['direction_requests'] ?? 0);

    $metrics = [
        dashboard_live_metric('actions', 'GBP actions', $currentActions, $previousActions),
        dashboard_live_metric('website_clicks', 'Website clicks', (float)($current['website_clicks'] ?? 0), (float)($previous['website_clicks'] ?? 0)),
        dashboard_live_metric('call_clicks', 'Calls', (float)($current['call_clicks'] ?? 0), (float)($previous['call_clicks'] ?? 0)),
        dashboard_live_metric('direction_requests', 'Directions', (float)($current['direction_requests'] ?? 0), (float)($previous['direction_requests'] ?? 0)),
        dashboard_live_metric('search_impressions', 'Search views', (float)($current['search_impressions'] ?? 0), (float)($previous['search_impressions'] ?? 0)),
        dashboard_live_metric('maps_impressions', 'Maps views', (float)($current['maps_impressions'] ?? 0), (float)($previous['maps_impressions'] ?? 0)),
    ];

    return array_merge(
        [
            'source' => 'gbp',
            'label' => 'Google Business Profile',
            'status' => 'live',
            'fetched_at' => date(DATE_ATOM),
            'resource_name' => (string)($connection['selected_resource_name'] ?? 'Selected GBP location'),
            'metrics' => $metrics,
        ],
        dashboard_live_period_payload($period)
    );
}

function dashboard_live_choose_boulevard_location(int $businessId, array $locations): array
{
    if (!$locations) {
        throw new RuntimeException('Boulevard returned no locations.');
    }

    if (count($locations) === 1) {
        return $locations[0];
    }

    try {
        $stmt = db()->prepare(
            'SELECT boulevard_id, name FROM boulevard_locations WHERE business_id=? ORDER BY synced_at DESC, id DESC LIMIT 1'
        );
        $stmt->execute([$businessId]);
        $cached = $stmt->fetch();

        if ($cached) {
            foreach ($locations as $location) {
                if (
                    is_array($location)
                    && (string)($location['id'] ?? '') === (string)($cached['boulevard_id'] ?? '')
                ) {
                    return $location;
                }
            }
        }
    } catch (Throwable $e) {
        error_log('[Live dashboard / Boulevard location preference] ' . $e->getMessage());
    }

    foreach ($locations as $location) {
        if (is_array($location) && empty($location['isRemote'])) {
            return $location;
        }
    }

    return $locations[0];
}

function dashboard_live_local_date(?string $value, DateTimeZone $timezone): ?string
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }

    try {
        return (new DateTimeImmutable($value))->setTimezone($timezone)->format('Y-m-d');
    } catch (Throwable) {
        return null;
    }
}

/**
 * Build the Boulevard equivalent of a Daily Summary from fresh API records.
 * Revenue is grouped by each order's closedAt business-local date, and the
 * dashboard period total is the sum of those daily rows. This lets MTD/YTD use
 * the exact first-of-month / first-of-year workflow without falling back to a
 * previously uploaded CSV snapshot.
 */
function dashboard_live_boulevard_daily_summaries(
    array $appointments,
    array $orders,
    DateTimeZone $timezone
): array {
    $days = [];

    $ensureDay = static function (array &$rows, string $date): void {
        if (!isset($rows[$date])) {
            $rows[$date] = [
                'date' => $date,
                'revenue' => 0.0,
                'refunds' => 0.0,
                'appointments' => 0.0,
                'orders' => 0.0,
                'cancelled_appointments' => 0.0,
                'service_bookings' => 0.0,
            ];
        }
    };

    foreach ($orders as $order) {
        if (!is_array($order)) {
            continue;
        }

        $date = dashboard_live_local_date((string)($order['closedAt'] ?? ''), $timezone);
        if ($date === null) {
            continue;
        }

        $ensureDay($days, $date);
        $summary = is_array($order['summary'] ?? null) ? $order['summary'] : [];
        $days[$date]['orders'] += 1.0;
        $days[$date]['revenue'] += ((int)($summary['currentTotal'] ?? 0)) / 100;
        $days[$date]['refunds'] += ((int)($summary['refundAmount'] ?? 0)) / 100;
    }

    foreach ($appointments as $appointment) {
        if (!is_array($appointment)) {
            continue;
        }

        $date = dashboard_live_local_date((string)($appointment['startAt'] ?? ''), $timezone);
        if ($date === null) {
            continue;
        }

        $ensureDay($days, $date);
        $days[$date]['appointments'] += 1.0;

        if (!empty($appointment['cancelled'])) {
            $days[$date]['cancelled_appointments'] += 1.0;
        }

        $services = is_array($appointment['appointmentServices'] ?? null)
            ? $appointment['appointmentServices']
            : [];
        $days[$date]['service_bookings'] += (float)count($services);
    }

    ksort($days);
    return array_values($days);
}

function dashboard_live_boulevard_totals_from_daily(array $dailyRows): array
{
    $totals = [
        'revenue' => 0.0,
        'refunds' => 0.0,
        'appointments' => 0.0,
        'orders' => 0.0,
        'cancelled_appointments' => 0.0,
        'service_bookings' => 0.0,
    ];

    foreach ($dailyRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        foreach (array_keys($totals) as $key) {
            $totals[$key] += (float)($row[$key] ?? 0);
        }
    }

    $totals['cancellation_rate'] = $totals['appointments'] > 0
        ? ($totals['cancelled_appointments'] / $totals['appointments'])
        : 0.0;

    return $totals;
}

function dashboard_live_boulevard(int $businessId, array $business, string $periodKey = 'weekly'): array
{
    $connection = boulevard_connection($businessId);

    if ((string)($connection['status'] ?? '') !== 'connected') {
        throw new RuntimeException('Boulevard is not connected for this business.');
    }

    $service = BoulevardUnifiedService::forAestheticBusiness($businessId);
    $remoteBusiness = $service->verifyBusiness($service->getConfiguredBusinessId());
    $locations = $service->getLocations();
    $location = dashboard_live_choose_boulevard_location($businessId, $locations);

    $timezone = trim((string)(
        $location['tz']
        ?? $remoteBusiness['tz']
        ?? $business['timezone']
        ?? 'UTC'
    ));

    $period = dashboard_live_period($timezone, $periodKey);
    $tz = dashboard_live_timezone($period['timezone']);

    $currentFrom = new DateTimeImmutable($period['start']->format('Y-m-d') . ' 00:00:00', $tz);
    $currentTo = new DateTimeImmutable($period['end']->modify('+1 day')->format('Y-m-d') . ' 00:00:00', $tz);
    $previousFrom = new DateTimeImmutable($period['previous_start']->format('Y-m-d') . ' 00:00:00', $tz);
    $previousTo = new DateTimeImmutable($period['previous_end']->modify('+1 day')->format('Y-m-d') . ' 00:00:00', $tz);

    $locationId = trim((string)($location['id'] ?? ''));
    if ($locationId === '') {
        throw new RuntimeException('Boulevard location is missing its ID.');
    }

    $currentAppointments = $service->getAppointments($locationId, $currentFrom, $currentTo);
    $currentOrders = $service->getOrders($locationId, $currentFrom, $currentTo);
    $previousAppointments = $service->getAppointments($locationId, $previousFrom, $previousTo);
    $previousOrders = $service->getOrders($locationId, $previousFrom, $previousTo);

    $currentDaily = dashboard_live_boulevard_daily_summaries($currentAppointments, $currentOrders, $tz);
    $previousDaily = dashboard_live_boulevard_daily_summaries($previousAppointments, $previousOrders, $tz);
    $current = dashboard_live_boulevard_totals_from_daily($currentDaily);
    $previous = dashboard_live_boulevard_totals_from_daily($previousDaily);

    $metrics = [
        dashboard_live_metric('revenue', (string)$period['revenue_label'], (float)$current['revenue'], (float)$previous['revenue'], 'currency'),
        dashboard_live_metric('appointments', 'Appointments', (float)$current['appointments'], (float)$previous['appointments']),
        dashboard_live_metric('orders', 'Closed orders', (float)$current['orders'], (float)$previous['orders']),
        dashboard_live_metric('service_bookings', 'Service bookings', (float)$current['service_bookings'], (float)$previous['service_bookings']),
        dashboard_live_metric('refunds', 'Refunds', (float)$current['refunds'], (float)$previous['refunds'], 'currency'),
        dashboard_live_metric('cancellation_rate', 'Cancellation rate', (float)$current['cancellation_rate'], (float)$previous['cancellation_rate'], 'ratio_percent'),
    ];

    $periodPayload = dashboard_live_period_payload($period);
    $periodPayload['calculation_basis'] =
        'Fresh Boulevard orders are grouped by business-local day and summed across the selected range. '
        . $periodPayload['calculation_basis'];

    return array_merge(
        [
            'source' => 'boulevard',
            'label' => 'Boulevard',
            'status' => 'live',
            'fetched_at' => date(DATE_ATOM),
            'resource_name' => (string)($location['name'] ?? 'Boulevard location'),
            'business_name' => (string)($remoteBusiness['name'] ?? $business['name'] ?? ''),
            'daily_summary_days' => count($currentDaily),
            'metrics' => $metrics,
        ],
        $periodPayload
    );
}

function dashboard_live_fetch_source(
    int $businessId,
    array $business,
    string $source,
    string $periodKey = 'weekly'
): array {
    $periodKey = dashboard_live_period_key($periodKey);

    return match ($source) {
        'ga4' => dashboard_live_ga4($businessId, $business, $periodKey),
        'gbp' => dashboard_live_gbp($businessId, $business, $periodKey),
        'boulevard' => dashboard_live_boulevard($businessId, $business, $periodKey),
        default => throw new InvalidArgumentException('Unknown live dashboard source.'),
    };
}
