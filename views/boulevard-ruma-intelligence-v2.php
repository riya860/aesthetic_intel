<?php
/**
 * RUMA Boulevard Priority Intelligence — 2026-06
 *
 * Business-facing dashboard using the direct Admin API as the primary source.
 * Exact-period uploaded Boulevard/PDF data remains available for validation.
 *
 * @var array      $model
 * @var array|null $liveResult
 */

$h = static fn(mixed $value): string =>
    htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$model = is_array($model ?? null) ? $model : [];
$liveResult = is_array($liveResult ?? null) ? $liveResult : [];

$directApi = is_array($model['direct_api'] ?? null)
    ? $model['direct_api']
    : null;

$canonicalApi = null;
$reportComparison = null;
$activeRun = null;
$apiBatch = null;

$directComparison = is_array($model['direct_comparison'] ?? null)
    ? $model['direct_comparison']
    : null;

$manualBatches = is_array($model['manual_batches'] ?? null)
    ? $model['manual_batches']
    : [];

$periodStart = (string)($model['period_start'] ?? $liveResult['period_start'] ?? '');
$periodEnd = (string)($model['period_end'] ?? $liveResult['period_end'] ?? '');

$path = static function (?array $source, string $path): mixed {
    if (!$source) {
        return null;
    }

    $value = $source;
    foreach (explode('.', $path) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return null;
        }
        $value = $value[$part];
    }

    return $value;
};

$getMetric = static function (?array $source, string $metricPath) use ($path): ?array {
    $value = $path($source, $metricPath);
    return is_array($value) ? $value : null;
};

/*
 * Priority Intelligence is intentionally simple again:
 * the dashboard uses the live Direct Admin API only.
 *
 * Uploaded Boulevard reports are used only for comparison/validation.
 * No Report Export sync or canonical fallback is involved.
 */
$preferMetric = static function (
    ?array $metric,
    string $unusedUploadKey = '',
    string $unusedFormat = 'number'
): ?array {
    if (!is_array($metric)) {
        return null;
    }

    $metric['_source_plane'] = 'direct_admin';
    return $metric;
};

$metricOrigin = static function (?array $metric): string {
    return $metric ? 'Direct Admin API' : 'Unavailable';
};

$metricNumber = static function (?array $metric): ?float {
    if (
        !$metric
        || empty($metric['available'])
        || !is_numeric($metric['value'] ?? null)
    ) {
        return null;
    }

    return (float)$metric['value'];
};

$metricDisplay = static function (?array $metric) use ($metricNumber): string {
    $value = $metricNumber($metric);
    if ($value === null) {
        return '—';
    }

    return match ((string)($metric['format'] ?? 'number')) {
        'currency' => '$' . number_format($value, 2),
        'percent' => number_format($value * 100.0, 1) . '%',
        default => number_format(
            $value,
            abs($value - round($value)) < 0.0001 ? 0 : 2
        ),
    };
};

$displayDate = static function (string $date): string {
    if ($date === '') {
        return '';
    }

    try {
        return (new DateTimeImmutable($date))->format('M j, Y');
    } catch (Throwable) {
        return $date;
    }
};

$comparisonValue = static function (mixed $value, string $format): string {
    if ($value === null || !is_numeric($value)) {
        return '—';
    }

    $value = (float)$value;

    return match ($format) {
        'currency' => '$' . number_format($value, 2),
        'percent' => number_format($value * 100.0, 1) . '%',
        'percent_points' => number_format($value, 1) . '%',
        'number' => number_format($value, 0),
        default => number_format($value, 2),
    };
};

/* -------------------------------------------------------------------------
 * DIRECT KPI DATA
 * ---------------------------------------------------------------------- */
$totalRevenueMetric = $preferMetric(
    $getMetric($directApi, 'performance.total_revenue'),
    'total_revenue',
    'currency'
);
$appointmentsMetric = $preferMetric(
    $getMetric($directApi, 'performance.total_appointments'),
    'appointments',
    'number'
);
$requestedMetric = $preferMetric(
    $getMetric($directApi, 'sales_summary.requested_appointments'),
    'requested_appointments',
    'number'
);
$newClientsMetric = $preferMetric(
    $getMetric($directApi, 'performance.new_clients'),
    'new_clients',
    'number'
);
$utilizationMetric = $preferMetric(
    $getMetric($directApi, 'performance.blended_utilization'),
    'utilization',
    'percent'
);
$activeMrrMetric = $preferMetric(
    $getMetric($directApi, 'performance.current_active_mrr'),
    'active_mrr',
    'currency'
);
$activeArrMetric = $preferMetric(
    $getMetric($directApi, 'membership.current_arr'),
    'active_arr',
    'currency'
);
$refundMetric = $preferMetric(
    $getMetric($directApi, 'financial.refunds'),
    'refunds',
    'currency'
);

$serviceRevenueMetric = $preferMetric(
    $getMetric($directApi, 'sales_summary.service_revenue'),
    'service_revenue',
    'currency'
);
$productRevenueMetric = $preferMetric(
    $getMetric($directApi, 'sales_summary.product_revenue'),
    'product_revenue',
    'currency'
);
$membershipRevenueMetric = $preferMetric(
    $getMetric($directApi, 'sales_summary.membership_revenue'),
    'membership_revenue',
    'currency'
);
$packageRevenueMetric = $preferMetric(
    $getMetric($directApi, 'sales_summary.package_revenue'),
    'package_revenue',
    'currency'
);

$totalRevenue = $metricNumber($totalRevenueMetric);
$appointments = $metricNumber($appointmentsMetric);
$refunds = $metricNumber($refundMetric);

$averageRevenuePerAppointment = (
    $totalRevenue !== null
    && $appointments !== null
    && $appointments > 0
)
    ? $totalRevenue / $appointments
    : null;

$refundRate = (
    $totalRevenue !== null
    && $totalRevenue > 0
    && $refunds !== null
)
    ? ($refunds / $totalRevenue) * 100.0
    : null;

$mixMetrics = [
    'Service revenue' => $serviceRevenueMetric,
    'Product revenue' => $productRevenueMetric,
    'Membership revenue' => $membershipRevenueMetric,
    'Package revenue' => $packageRevenueMetric,
];

/* -------------------------------------------------------------------------
 * PROVIDERS — direct first, canonical Report Export fallback
 * ---------------------------------------------------------------------- */
$providerKey = static function (string $value): string {
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/i', ' ', $value) ?? $value;
    return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
};

$directProviderRows = [];
foreach ([
    $directApi['provider_details'] ?? null,
    $directApi['providers'] ?? null,
    $directApi['provider_performance'] ?? null,
    $path($directApi, 'performance.providers'),
] as $candidate) {
    if (is_array($candidate) && $candidate !== []) {
        $directProviderRows = $candidate;
        break;
    }
}

$canonicalProviderRows = [];

$providerMap = [];

foreach ($directProviderRows as $provider) {
    if (!is_array($provider)) {
        continue;
    }

    $name = trim((string)(
        $provider['provider']
        ?? $provider['name']
        ?? $provider['provider_name']
        ?? ''
    ));
    if ($name === '') {
        continue;
    }

    $provider['_direct_available'] = true;
    $providerMap[$providerKey($name)] = $provider;
}

foreach ($canonicalProviderRows as $provider) {
    if (!is_array($provider)) {
        continue;
    }

    $name = trim((string)($provider['name'] ?? ''));
    if ($name === '') {
        continue;
    }

    $key = $providerKey($name);
    $row = $providerMap[$key] ?? [
        'provider' => $name,
        'name' => $name,
        'appointments' => null,
        'requested' => null,
        'new_clients' => null,
        'service_revenue' => null,
        'utilization' => null,
    ];

    $row['provider'] = (string)($row['provider'] ?? $name);
    $row['name'] = (string)($row['name'] ?? $name);

    if (
        (empty($row['service_revenue_available']) || !is_numeric($row['service_revenue'] ?? null))
        && is_numeric($provider['service_revenue'] ?? null)
    ) {
        $row['service_revenue'] = (float)$provider['service_revenue'];
        $row['service_revenue_available'] = true;
        $row['_service_revenue_source'] = 'report_export';
    }

    if (
        !is_numeric($row['appointments'] ?? null)
        && is_numeric($provider['appointments'] ?? null)
    ) {
        $row['appointments'] = (float)$provider['appointments'];
        $row['_appointments_source'] = 'report_export';
    }

    if (
        !is_numeric($row['requested'] ?? null)
        && is_numeric($provider['requested'] ?? null)
    ) {
        $row['requested'] = (float)$provider['requested'];
        $row['requested_appointments'] = (float)$provider['requested'];
        $row['requested_available'] = true;
        $row['_requested_source'] = 'report_export';
    }

    if (
        (empty($row['new_clients_available']) || !is_numeric($row['new_clients'] ?? null))
        && is_numeric($provider['new_clients'] ?? null)
    ) {
        $row['new_clients'] = (float)$provider['new_clients'];
        $row['new_clients_available'] = true;
        $row['_new_clients_source'] = 'report_export';
    }

    if (
        (empty($row['utilization_available']) || !is_numeric($row['utilization'] ?? null))
        && is_numeric($provider['utilization'] ?? null)
    ) {
        $util = (float)$provider['utilization'];
        $row['utilization'] = abs($util) > 1.0 ? $util / 100.0 : $util;
        $row['utilization_available'] = true;
        $row['_utilization_source'] = 'report_export';
    }

    if (
        !is_numeric($row['scheduled_hours'] ?? null)
        && is_numeric($provider['hours_scheduled'] ?? null)
    ) {
        $row['scheduled_hours'] = (float)$provider['hours_scheduled'];
        $row['_scheduled_hours_source'] = 'report_export';
    }

    if (
        !is_numeric($row['revenue_per_hour'] ?? null)
        && is_numeric($provider['revenue_per_hour'] ?? null)
    ) {
        $row['revenue_per_hour'] = (float)$provider['revenue_per_hour'];
        $row['revenue_per_hour_available'] = true;
        $row['_revenue_per_hour_source'] = 'report_export';
    }

    if (
        !is_numeric($row['retail_sales'] ?? null)
        && is_numeric($provider['product_revenue'] ?? null)
    ) {
        $row['retail_sales'] = (float)$provider['product_revenue'];
        $row['retail_sales_available'] = true;
        $row['_retail_source'] = 'report_export';
    }

    $row['_canonical_available'] = true;
    $providerMap[$key] = $row;
}

$providerRows = array_values($providerMap);

usort($providerRows, static function (array $a, array $b): int {
    if (($a['provider'] ?? '') === 'Unassigned') {
        return 1;
    }
    if (($b['provider'] ?? '') === 'Unassigned') {
        return -1;
    }

    $cmp = ((int)($b['appointments'] ?? 0)) <=> ((int)($a['appointments'] ?? 0));
    return $cmp !== 0
        ? $cmp
        : strcasecmp((string)($a['provider'] ?? ''), (string)($b['provider'] ?? ''));
});

$providerRevenueMeaningful = false;
foreach ($providerRows as $provider) {
    if (
        is_array($provider)
        && !empty($provider['service_revenue_available'])
        && is_numeric($provider['service_revenue'] ?? null)
    ) {
        $providerRevenueMeaningful = true;
        break;
    }
}

/* -------------------------------------------------------------------------
 * WARNINGS / ACCESS COVERAGE
 * ---------------------------------------------------------------------- */
$warnings = is_array($liveResult['priority_intelligence_warnings'] ?? null)
    ? array_values(array_filter(array_map(
        static fn($value): string => trim((string)$value),
        $liveResult['priority_intelligence_warnings']
    )))
    : [];

$accessNeeds = [];
if ($metricNumber($newClientsMetric) === null) {
    $accessNeeds['client:read'] = 'New clients';
}
if ($metricNumber($utilizationMetric) === null) {
    $accessNeeds['shift:read'] = 'Utilization';
}
if ($metricNumber($activeMrrMetric) === null || $metricNumber($activeArrMetric) === null) {
    $accessNeeds['membership:read'] = 'MRR / ARR';
}
if ($metricNumber($membershipRevenueMetric) === null) {
    $accessNeeds['membership_plan:read'] = 'Membership classification';
}
if ($metricNumber($packageRevenueMetric) === null) {
    $accessNeeds['package:read'] = 'Package classification';
}

/* -------------------------------------------------------------------------
 * PRIORITY OPPORTUNITIES
 * ---------------------------------------------------------------------- */
$priorities = [];

if ($averageRevenuePerAppointment !== null) {
    $priorities[] = [
        'tone' => 'positive',
        'title' => 'Revenue per appointment',
        'body' => 'Average revenue per appointment is $'
            . number_format($averageRevenuePerAppointment, 2)
            . ' for the selected period.',
    ];
}

if ($refundRate !== null) {
    $priorities[] = [
        'tone' => $refundRate >= 2.0 ? 'attention' : 'neutral',
        'title' => $refundRate >= 2.0 ? 'Review refund drivers' : 'Refund rate',
        'body' => 'Refunds represent '
            . number_format($refundRate, 1)
            . '% of revenue for this period.',
    ];
}

$requested = $metricNumber($requestedMetric);
if ($requested !== null && $appointments !== null && $appointments > 0) {
    $priorities[] = [
        'tone' => 'neutral',
        'title' => 'Requested-provider demand',
        'body' => number_format(($requested / $appointments) * 100.0, 1)
            . '% of appointments include a requested provider.',
    ];
}

if ($priorities === []) {
    $priorities[] = [
        'tone' => 'neutral',
        'title' => 'Boulevard operational data is available',
        'body' => 'Core Priority Intelligence is using the available Boulevard Admin API data for this period.',
    ];
}

/* -------------------------------------------------------------------------
 * CHART DATA — no extra API requests are performed here.
 * ---------------------------------------------------------------------- */
$chartRevenueMix = [];
foreach ($mixMetrics as $label => $mixMetric) {
    $value = $metricNumber($mixMetric);
    if ($value !== null && $value > 0) {
        $chartRevenueMix[] = [
            'label' => $label,
            'value' => $value,
        ];
    }
}

$chartProviders = [];
foreach ($providerRows as $provider) {
    if (!is_array($provider)) {
        continue;
    }

    $name = trim((string)(
        $provider['name']
        ?? $provider['provider']
        ?? $provider['provider_name']
        ?? ''
    ));

    $count = $provider['appointments']
        ?? $provider['appointment_count']
        ?? null;

    if ($name !== '' && is_numeric($count) && (float)$count > 0) {
        $chartProviders[] = [
            'label' => $name,
            'value' => (float)$count,
        ];
    }
}

usort(
    $chartProviders,
    static fn(array $a, array $b): int => ($b['value'] <=> $a['value'])
);
$chartProviders = array_slice($chartProviders, 0, 10);

$dailyRows = [];
foreach ([
    $directApi['daily'] ?? null,
    $path($directApi, 'performance.daily'),
] as $candidate) {
    if (is_array($candidate) && $candidate !== []) {
        $dailyRows = array_values($candidate);
        break;
    }
}

$chartPayload = [
    'revenueMix' => $chartRevenueMix,
    'providers' => $chartProviders,
    'daily' => $dailyRows,
];
?>

<link rel="stylesheet" href="/assets/css/ruma-boulevard-v2.css?v=3.1.0">

<div class="pi-page">
    <header class="pi-hero">
        <div class="pi-hero-copy">
            <div class="pi-eyebrow">RUMA · Boulevard 2026-06</div>
            <h1>Priority Intelligence</h1>

            <div class="pi-meta">
                <?php if ($periodStart !== '' && $periodEnd !== ''): ?>
                    <span class="pi-period">
                        <?= $h($displayDate($periodStart)) ?> – <?= $h($displayDate($periodEnd)) ?>
                    </span>
                <?php endif; ?>

                <span class="pi-status <?= $directApi ? 'is-good' : 'is-muted' ?>">
                    <span class="pi-status-dot"></span>
                    <?= $directApi ? 'Boulevard API connected' : 'Boulevard API data unavailable' ?>
                </span>
            </div>
        </div>

        <div class="pi-hero-actions no-print">
            <a class="pi-button pi-button-primary" href="<?= $h(url('boulevard-live-console')) ?>">
                Refresh Boulevard Data
            </a>
        </div>
    </header>

    <?php if ($accessNeeds): ?>
        <div class="pi-coverage-banner">
            <div>
                <strong>Core API data is available.</strong>
                <span>
                    Some optional KPIs remain locked by Boulevard scopes. The dashboard does not treat those as zero.
                </span>
            </div>
            <div class="pi-scope-chips">
                <?php foreach ($accessNeeds as $scope => $label): ?>
                    <span title="<?= $h($label) ?>"><?= $h($scope) ?></span>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <section class="pi-section">
        <div class="pi-section-head">
            <div>
                <span class="pi-kicker">Performance</span>
                <h2>Business snapshot</h2>
            </div>
            <p>Near-live KPIs reconstructed from Boulevard operational objects.</p>
        </div>

        <div class="pi-kpi-grid">
            <article class="pi-kpi-card">
                <span>Total Revenue</span>
                <strong><?= $h($metricDisplay($totalRevenueMetric)) ?></strong>
                <small><?= $h($metricOrigin($totalRevenueMetric)) ?> · selected period</small>
            </article>

            <article class="pi-kpi-card">
                <span>Appointments</span>
                <strong><?= $h($metricDisplay($appointmentsMetric)) ?></strong>
                <small><?= $h($metricOrigin($appointmentsMetric)) ?> · appointments</small>
            </article>

            <article class="pi-kpi-card">
                <span>Avg. Revenue / Appointment</span>
                <strong>
                    <?= $averageRevenuePerAppointment === null
                        ? '—'
                        : '$' . $h(number_format($averageRevenuePerAppointment, 2)) ?>
                </strong>
                <small>Revenue ÷ appointments</small>
            </article>

            <article class="pi-kpi-card">
                <span>Refunds</span>
                <strong><?= $h($metricDisplay($refundMetric)) ?></strong>
                <small>
                    <?= $refundRate === null
                        ? 'Selected period'
                        : $h(number_format($refundRate, 1)) . '% of revenue' ?>
                </small>
            </article>

            <article class="pi-kpi-card <?= $metricNumber($requestedMetric) === null ? 'is-unavailable' : '' ?>">
                <span>Requested Appointments</span>
                <strong><?= $h($metricDisplay($requestedMetric)) ?></strong>
                <small>
                    <?= $metricNumber($requestedMetric) === null
                        ? 'Appointment-service detail unavailable'
                        : $h($metricOrigin($requestedMetric)) . ' · provider specifically requested' ?>
                </small>
            </article>

            <article class="pi-kpi-card <?= $metricNumber($newClientsMetric) === null ? 'is-unavailable' : '' ?>">
                <span>New Clients</span>
                <strong><?= $h($metricDisplay($newClientsMetric)) ?></strong>
                <small>
                    <?= $metricNumber($newClientsMetric) === null
                        ? 'Requires client:read'
                        : $h($metricOrigin($newClientsMetric)) . ' · new-client activity' ?>
                </small>
            </article>

            <article class="pi-kpi-card <?= $metricNumber($utilizationMetric) === null ? 'is-unavailable' : '' ?>">
                <span>Blended Utilization</span>
                <strong><?= $h($metricDisplay($utilizationMetric)) ?></strong>
                <small>
                    <?= $metricNumber($utilizationMetric) === null
                        ? 'Requires shift:read'
                        : $h($metricOrigin($utilizationMetric)) . ' · booked vs scheduled time' ?>
                </small>
            </article>

            <article class="pi-kpi-card <?= $metricNumber($activeMrrMetric) === null ? 'is-unavailable' : '' ?>">
                <span>Active MRR</span>
                <strong><?= $h($metricDisplay($activeMrrMetric)) ?></strong>
                <small>
                    <?= $metricNumber($activeMrrMetric) === null
                        ? 'Requires membership:read'
                        : $h($metricOrigin($activeMrrMetric)) . ' · current recurring revenue' ?>
                </small>
            </article>

            <article class="pi-kpi-card <?= $metricNumber($activeArrMetric) === null ? 'is-unavailable' : '' ?>">
                <span>Active ARR</span>
                <strong><?= $h($metricDisplay($activeArrMetric)) ?></strong>
                <small>
                    <?= $metricNumber($activeArrMetric) === null
                        ? 'Requires membership:read'
                        : $h($metricOrigin($activeArrMetric)) . ' · annualized recurring revenue' ?>
                </small>
            </article>
        </div>
    </section>

    <section class="pi-section">
        <div class="pi-section-head">
            <div>
                <span class="pi-kicker">Intelligence</span>
                <h2>Priority opportunities</h2>
            </div>
        </div>

        <div class="pi-priority-grid">
            <?php foreach ($priorities as $priority): ?>
                <article class="pi-priority-card is-<?= $h($priority['tone']) ?>">
                    <span class="pi-priority-marker"></span>
                    <div>
                        <h3><?= $h($priority['title']) ?></h3>
                        <p><?= $h($priority['body']) ?></p>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="pi-section">
        <div class="pi-section-head">
            <div>
                <span class="pi-kicker">Revenue mix</span>
                <h2>Business mix</h2>
            </div>
        </div>

        <div class="pi-mix-grid">
            <?php foreach ($mixMetrics as $label => $mixMetric): ?>
                <article class="pi-mix-card <?= $metricNumber($mixMetric) === null ? 'is-unavailable' : '' ?>">
                    <span><?= $h($label) ?></span>
                    <strong><?= $h($metricDisplay($mixMetric)) ?></strong>
                    <small>
                        <?= $metricNumber($mixMetric) === null
                            ? 'Not available with current API coverage'
                            : $h($metricOrigin($mixMetric)) . ' · selected period' ?>
                    </small>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

    <?php if ($chartRevenueMix || $chartProviders || $dailyRows): ?>
        <section class="pi-section">
            <div class="pi-section-head">
                <div>
                    <span class="pi-kicker">Visual trends</span>
                    <h2>Performance charts</h2>
                </div>
                <p>Charts use the same API values shown in the KPI cards and provider table.</p>
            </div>

            <div class="pi-chart-grid">
                <?php if ($chartRevenueMix): ?>
                    <article class="pi-chart-card">
                        <div class="pi-chart-card-head">
                            <div>
                                <span>Revenue mix</span>
                                <strong>Sales composition</strong>
                            </div>
                        </div>
                        <div class="pi-chart-canvas-wrap">
                            <canvas id="piRevenueMixChart" aria-label="Revenue mix chart"></canvas>
                        </div>
                    </article>
                <?php endif; ?>

                <?php if ($chartProviders): ?>
                    <article class="pi-chart-card">
                        <div class="pi-chart-card-head">
                            <div>
                                <span>Provider activity</span>
                                <strong>Top providers by appointments</strong>
                            </div>
                        </div>
                        <div class="pi-chart-canvas-wrap pi-chart-canvas-tall">
                            <canvas id="piProviderChart" aria-label="Provider appointment chart"></canvas>
                        </div>
                    </article>
                <?php endif; ?>

                <?php if ($dailyRows): ?>
                    <article class="pi-chart-card pi-chart-card-wide">
                        <div class="pi-chart-card-head">
                            <div>
                                <span>Daily performance</span>
                                <strong>Revenue and appointment trend</strong>
                            </div>
                        </div>
                        <div class="pi-chart-canvas-wrap pi-chart-canvas-wide">
                            <canvas id="piDailyChart" aria-label="Daily performance chart"></canvas>
                        </div>
                    </article>
                <?php endif; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($providerRows): ?>
        <section class="pi-section">
            <div class="pi-section-head">
                <div>
                    <span class="pi-kicker">Team</span>
                    <h2>Provider performance</h2>
                </div>
                <p>
                    Appointment activity is always shown when available.
                    <?= !$providerRevenueMeaningful && $metricNumber($serviceRevenueMetric) !== null
                        ? 'Revenue attribution is hidden rather than displaying misleading $0 values.'
                        : 'Revenue is shown when Boulevard can attribute service lines to staff.' ?>
                </p>
            </div>

            <div class="pi-table-card">
                <div class="pi-table-scroll">
                    <table class="pi-table">
                        <thead>
                            <tr>
                                <th>Provider</th>
                                <th>Attributed revenue</th>
                                <th>Appointments</th>
                                <th>Requested</th>
                                <th>New clients</th>
                                <th>Utilization</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($providerRows as $provider): ?>
                            <?php
                            if (!is_array($provider)) {
                                continue;
                            }

                            $providerName = (string)(
                                $provider['name']
                                ?? $provider['provider']
                                ?? $provider['provider_name']
                                ?? 'Provider'
                            );

                            $providerRevenue = $provider['service_revenue']
                                ?? $provider['revenue']
                                ?? $provider['total_revenue']
                                ?? null;

                            $providerAppointments = $provider['appointments']
                                ?? $provider['appointment_count']
                                ?? null;

                            $providerRequested = $provider['requested']
                                ?? $provider['requested_appointments']
                                ?? null;

                            $providerNewClients = $provider['new_clients'] ?? null;
                            $providerUtilization = $provider['utilization'] ?? null;
                            ?>
                            <tr>
                                <td><strong><?= $h($providerName) ?></strong></td>
                                <td>
                                    <?= $providerRevenueMeaningful && is_numeric($providerRevenue)
                                        ? '$' . $h(number_format((float)$providerRevenue, 2))
                                        : '—' ?>
                                </td>
                                <td>
                                    <?= is_numeric($providerAppointments)
                                        ? $h(number_format((float)$providerAppointments, 0))
                                        : '—' ?>
                                </td>
                                <td>
                                    <?= is_numeric($providerRequested)
                                        ? $h(number_format((float)$providerRequested, 0))
                                        : '—' ?>
                                </td>
                                <td>
                                    <?= is_numeric($providerNewClients)
                                        ? $h(number_format((float)$providerNewClients, 0))
                                        : '—' ?>
                                </td>
                                <td>
                                    <?php
                                    if (is_numeric($providerUtilization)) {
                                        $util = (float)$providerUtilization;
                                        if ($util <= 1.0) {
                                            $util *= 100.0;
                                        }
                                        echo $h(number_format($util, 1)) . '%';
                                    } else {
                                        echo '—';
                                    }
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <section class="pi-section" id="comparison">
        <div class="pi-section-head">
            <div>
                <span class="pi-kicker">Validation</span>
                <h2>API vs uploaded Boulevard data</h2>
            </div>
            <p>
                Compare the live Boulevard Admin API data with an exact-period
                Boulevard upload. Missing API coverage is shown as unavailable
                rather than treated as a match or a zero.
            </p>
        </div>

        <div class="pi-comparison-card">
            <?php if ($manualBatches): ?>
                <form
                    class="pi-simple-compare-form no-print"
                    method="post"
                    action="<?= $h(url('boulevard-ruma-intelligence-compare-v2')) ?>"
                >
                    <?= csrf_field() ?>

                    <label>
                        <span>Exact-period uploaded Boulevard report</span>

                        <select name="manual_batch_id" required>
                            <option value="">Choose exact-period upload</option>

                            <?php foreach ($manualBatches as $batch): ?>
                                <?php
                                $selectedId = (int)(
                                    $model['direct_comparison_batch']['id']
                                    ?? $model['selected_manual_batch']['id']
                                    ?? 0
                                );
                                ?>
                                <option
                                    value="<?= (int)($batch['id'] ?? 0) ?>"
                                    <?= $selectedId === (int)($batch['id'] ?? 0)
                                        ? 'selected'
                                        : '' ?>
                                >
                                    Batch #<?= (int)($batch['id'] ?? 0) ?>
                                    · <?= $h((string)($batch['frequency'] ?? '')) ?>
                                    · <?= $h((string)($batch['created_at'] ?? '')) ?>
                                    · <?= $h(number_format(
                                        (float)($batch['completeness_score'] ?? 0),
                                        1
                                    )) ?>% complete
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <button
                        class="pi-button pi-button-primary"
                        type="submit"
                    >
                        Compare with uploaded data
                    </button>
                </form>
            <?php else: ?>
                <div class="pi-empty">
                    No completed Boulevard upload exists for this exact period.
                </div>
            <?php endif; ?>

            <?php
            $selectedBatchForNotice =
                $model['direct_comparison_batch']
                ?? $model['selected_manual_batch']
                ?? null;
            ?>

            <?php if (
                is_array($selectedBatchForNotice)
                && is_numeric($selectedBatchForNotice['completeness_score'] ?? null)
                && (float)$selectedBatchForNotice['completeness_score'] < 100.0
            ): ?>
                <div class="pi-validation-warning">
                    Uploaded batch completeness is
                    <?= $h(number_format(
                        (float)$selectedBatchForNotice['completeness_score'],
                        1
                    )) ?>%.
                    Missing report sections can make some uploaded metrics unavailable
                    or different.
                </div>
            <?php endif; ?>

            <?php if ($directComparison): ?>
                <div class="pi-parity-primary">
                    <div class="pi-parity-title">
                        <div>
                            <span class="pi-kicker">Comparison result</span>
                            <h3>Live Boulevard API vs uploaded data</h3>
                        </div>
                        <span class="pi-source-badge">Direct API</span>
                    </div>

                    <div class="pi-comparison-summary">
                        <div>
                            <span>Business alignment</span>
                            <strong>
                                <?= $h(number_format(
                                    (float)(
                                        $directComparison['business_match_percent']
                                        ?? $directComparison['match_percent']
                                        ?? 0
                                    ),
                                    1
                                )) ?>%
                            </strong>
                        </div>

                        <div>
                            <span>Business comparable</span>
                            <strong>
                                <?= (int)(
                                    $directComparison['business_comparable_metrics']
                                    ?? $directComparison['comparable_metrics']
                                    ?? 0
                                ) ?>
                            </strong>
                        </div>

                        <div>
                            <span>Business matched</span>
                            <strong>
                                <?= (int)(
                                    $directComparison['business_matched_metrics']
                                    ?? $directComparison['matched_metrics']
                                    ?? 0
                                ) ?>
                            </strong>
                        </div>

                        <div>
                            <span>Provider alignment</span>
                            <strong>
                                <?= $h(number_format(
                                    (float)($directComparison['provider_match_percent'] ?? 0),
                                    1
                                )) ?>%
                            </strong>
                        </div>
                    </div>

                    <div class="pi-table-scroll">
                        <table class="pi-table">
                            <thead>
                                <tr>
                                    <th>Metric</th>
                                    <th>Direct API</th>
                                    <th>Uploaded data</th>
                                    <th>Difference</th>
                                    <th>Status</th>
                                </tr>
                            </thead>

                            <tbody>
                            <?php foreach ((array)($directComparison['metrics'] ?? []) as $row): ?>
                                <?php
                                if (!is_array($row)) {
                                    continue;
                                }

                                $format = (string)($row['format'] ?? 'number');
                                ?>
                                <tr>
                                    <td>
                                        <strong>
                                            <?= $h((string)($row['label'] ?? 'Metric')) ?>
                                        </strong>
                                    </td>

                                    <td>
                                        <?= $h($comparisonValue(
                                            $row['api_value'] ?? null,
                                            $format
                                        )) ?>
                                    </td>

                                    <td>
                                        <?= $h($comparisonValue(
                                            $row['upload_value'] ?? null,
                                            $format
                                        )) ?>
                                    </td>

                                    <td>
                                        <?= $h($comparisonValue(
                                            $row['difference'] ?? null,
                                            $format
                                        )) ?>
                                    </td>

                                    <td>
                                        <span
                                            class="pi-compare-status is-<?= $h(
                                                (string)($row['status'] ?? 'review')
                                            ) ?>"
                                        >
                                            <?= $h(ucwords(str_replace(
                                                '_',
                                                ' ',
                                                (string)($row['status'] ?? 'review')
                                            ))) ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if (!empty($directComparison['providers'])): ?>
                        <details class="pi-inline-details">
                            <summary>Provider-level comparison</summary>

                            <?php foreach ((array)$directComparison['providers'] as $provider): ?>
                                <?php if (!is_array($provider)) continue; ?>

                                <div class="pi-provider-compare-block">
                                    <h4>
                                        <?= $h((string)($provider['provider'] ?? 'Provider')) ?>
                                    </h4>

                                    <div class="pi-table-scroll">
                                        <table class="pi-table">
                                            <thead>
                                                <tr>
                                                    <th>Metric</th>
                                                    <th>Direct API</th>
                                                    <th>Uploaded data</th>
                                                    <th>Difference</th>
                                                    <th>Status</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                            <?php foreach ((array)($provider['metrics'] ?? []) as $row): ?>
                                                <?php
                                                if (!is_array($row)) continue;
                                                $format = (string)($row['format'] ?? 'number');
                                                ?>
                                                <tr>
                                                    <td><?= $h((string)($row['label'] ?? 'Metric')) ?></td>
                                                    <td><?= $h($comparisonValue($row['api_value'] ?? null, $format)) ?></td>
                                                    <td><?= $h($comparisonValue($row['upload_value'] ?? null, $format)) ?></td>
                                                    <td><?= $h($comparisonValue($row['difference'] ?? null, $format)) ?></td>
                                                    <td>
                                                        <span
                                                            class="pi-compare-status is-<?= $h(
                                                                (string)($row['status'] ?? 'review')
                                                            ) ?>"
                                                        >
                                                            <?= $h(ucwords(str_replace(
                                                                '_',
                                                                ' ',
                                                                (string)($row['status'] ?? 'review')
                                                            ))) ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </details>
                    <?php endif; ?>
                </div>
            <?php elseif ($manualBatches): ?>
                <div class="pi-empty pi-empty-spaced">
                    Choose an exact-period upload and click
                    <strong>Compare with uploaded data</strong>.
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="pi-section pi-diagnostics-section">
        <details class="pi-diagnostics">
            <summary>
                <span>Data coverage & technical diagnostics</span>
                <small>Optional scopes and API coverage warnings</small>
            </summary>

            <div class="pi-diagnostics-body">
                <div class="pi-diagnostic-grid">
                    <div>
                        <span>Direct Admin API</span>
                        <strong><?= $directApi ? 'Available' : 'Unavailable' ?></strong>
                    </div>
                    <div>
                        <span>Exact-period uploads</span>
                        <strong><?= count($manualBatches) ?></strong>
                    </div>
                </div>

                <?php if ($accessNeeds): ?>
                    <div class="pi-debug-block">
                        <h3>Scopes needed for currently unavailable KPIs</h3>
                        <div class="pi-scope-chips">
                            <?php foreach ($accessNeeds as $scope => $label): ?>
                                <span><?= $h($scope) ?> · <?= $h($label) ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($warnings): ?>
                    <div class="pi-debug-block">
                        <h3>Coverage notes</h3>
                        <ul class="pi-warning-list">
                            <?php foreach ($warnings as $warning): ?>
                                <li><?= $h($warning) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
</div>
        </details>
    </section>
</div>

<script id="rumaBoulevardPriorityChartData" type="application/json"><?= json_encode(
    $chartPayload,
    JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
) ?></script>
<script src="/assets/js/ruma-boulevard-v2.js?v=3.1.0"></script>
