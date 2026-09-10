<?php
/**
 * RUMA Boulevard Priority Intelligence
 *
 * Clean business-facing UI.
 * Technical/API/PDF diagnostics stay collapsed at the bottom.
 */

/*
|--------------------------------------------------------------------------
| NORMALISE INPUT
|--------------------------------------------------------------------------
|
| This page supports both the newer $priority array and the older variables
| already used by the Boulevard implementation.
|
*/

$priority = isset($priority) && is_array($priority)
    ? $priority
    : [];

if (!$priority && isset($intelligence) && is_array($intelligence)) {
    $priority = $intelligence;
}

if (!$priority && isset($dashboardData) && is_array($dashboardData)) {
    $priority = $dashboardData;
}

$direct = [];

if (isset($priority['direct']) && is_array($priority['direct'])) {
    $direct = $priority['direct'];
} elseif (isset($priority['live']) && is_array($priority['live'])) {
    $direct = $priority['live'];
} elseif (isset($directIntelligence) && is_array($directIntelligence)) {
    $direct = $directIntelligence;
} elseif (isset($liveApiData) && is_array($liveApiData)) {
    $direct = $liveApiData;
}


/*
|--------------------------------------------------------------------------
| HELPERS
|--------------------------------------------------------------------------
*/

$esc = static function ($value): string {
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
};

$path = static function ($source, string $path, $default = null) {
    if (!is_array($source)) {
        return $default;
    }

    $segments = explode('.', $path);
    $value = $source;

    foreach ($segments as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return $default;
        }

        $value = $value[$segment];
    }

    return $value;
};

$firstValue = static function (array $values, $default = null) {
    foreach ($values as $value) {
        if ($value !== null && $value !== '') {
            return $value;
        }
    }

    return $default;
};

$unwrapValue = static function ($value) {
    if (!is_array($value)) {
        return $value;
    }

    foreach ([
        'value',
        'amount',
        'current',
        'total',
        'currentTotal',
        'count'
    ] as $key) {
        if (array_key_exists($key, $value)) {
            return $value[$key];
        }
    }

    return null;
};

$money = static function ($value) use ($unwrapValue): string {
    $value = $unwrapValue($value);

    if ($value === null || $value === '' || !is_numeric($value)) {
        return '—';
    }

    return '$' . number_format((float) $value, 2);
};

$number = static function ($value, int $decimals = 0) use ($unwrapValue): string {
    $value = $unwrapValue($value);

    if ($value === null || $value === '' || !is_numeric($value)) {
        return '—';
    }

    return number_format((float) $value, $decimals);
};

$percent = static function ($value): string {
    if ($value === null || $value === '' || !is_numeric($value)) {
        return '—';
    }

    return number_format((float) $value, 1) . '%';
};

$displayDate = static function (?string $date): string {
    if (!$date) {
        return '';
    }

    try {
        return (new DateTimeImmutable($date))->format('M j, Y');
    } catch (Throwable $e) {
        return $date;
    }
};


/*
|--------------------------------------------------------------------------
| BUSINESS + PERIOD
|--------------------------------------------------------------------------
*/

$businessName = (string) $firstValue([
    $priority['business_name'] ?? null,
    $priority['businessName'] ?? null,
    $businessName ?? null,
    'RUMA',
]);

$existingPeriodStart = $periodStart ?? null;
$existingPeriodEnd   = $periodEnd ?? null;

$periodStart = (string) $firstValue([
    $path($priority, 'period.start'),
    $priority['period_start'] ?? null,
    $priority['start_date'] ?? null,
    $existingPeriodStart,
], '');

$periodEnd = (string) $firstValue([
    $path($priority, 'period.end'),
    $priority['period_end'] ?? null,
    $priority['end_date'] ?? null,
    $existingPeriodEnd,
], '');

$lastUpdated = (string) $firstValue([
    $priority['last_updated'] ?? null,
    $priority['synced_at'] ?? null,
    $priority['updated_at'] ?? null,
    $path($direct, 'last_updated'),
    $lastSyncAt ?? null,
], '');


/*
|--------------------------------------------------------------------------
| KPI SOURCES
|--------------------------------------------------------------------------
*/

$kpis = [];

if (isset($priority['kpis']) && is_array($priority['kpis'])) {
    $kpis = $priority['kpis'];
} elseif (isset($direct['kpis']) && is_array($direct['kpis'])) {
    $kpis = $direct['kpis'];
}

$totalRevenue = $firstValue([
    $path($kpis, 'total_revenue.value'),
    $kpis['total_revenue'] ?? null,
    $path($kpis, 'revenue.value'),
    $kpis['revenue'] ?? null,

    $path($direct, 'orders.summary.currentTotal'),
    $path($direct, 'order_summary.currentTotal'),
    $path($direct, 'summary.currentTotal'),

    $totalRevenue ?? null,
]);

$refunds = $firstValue([
    $path($kpis, 'refunds.value'),
    $kpis['refunds'] ?? null,

    $path($direct, 'orders.summary.refundAmount'),
    $path($direct, 'order_summary.refundAmount'),
    $path($direct, 'summary.refundAmount'),

    $refundAmount ?? null,
]);

$appointmentsValue = $firstValue([
    $path($kpis, 'appointments.value'),
    $kpis['appointments'] ?? null,
    $direct['appointment_count'] ?? null,
    $direct['appointments_count'] ?? null,
    $appointmentCount ?? null,
]);

if (
    $appointmentsValue === null
    && isset($direct['appointments'])
    && is_array($direct['appointments'])
) {
    if (isset($direct['appointments']['count'])) {
        $appointmentsValue = $direct['appointments']['count'];
    } elseif (array_is_list($direct['appointments'])) {
        $appointmentsValue = count($direct['appointments']);
    }
}

$newClients = $firstValue([
    $path($kpis, 'new_clients.value'),
    $kpis['new_clients'] ?? null,
    $direct['new_client_count'] ?? null,
    $direct['new_clients'] ?? null,
    $newClientCount ?? null,
]);

$utilization = $firstValue([
    $path($kpis, 'blended_utilization.value'),
    $kpis['blended_utilization'] ?? null,
    $path($kpis, 'utilization.value'),
    $kpis['utilization'] ?? null,
    $direct['blended_utilization'] ?? null,
    $utilization ?? null,
]);

$activeArr = $firstValue([
    $path($kpis, 'active_arr.value'),
    $kpis['active_arr'] ?? null,
    $direct['active_arr'] ?? null,
    $activeArr ?? null,
]);

$activeMrr = $firstValue([
    $path($kpis, 'active_mrr.value'),
    $kpis['active_mrr'] ?? null,
    $direct['active_mrr'] ?? null,
    $mrr ?? null,
]);

if (
    ($activeArr === null || $activeArr === '')
    && is_numeric($activeMrr)
) {
    $activeArr = (float) $activeMrr * 12;
}

$averageTicket = null;

if (
    is_numeric($totalRevenue)
    && is_numeric($appointmentsValue)
    && (float) $appointmentsValue > 0
) {
    $averageTicket =
        (float) $totalRevenue / (float) $appointmentsValue;
}

$refundRate = null;

if (
    is_numeric($totalRevenue)
    && (float) $totalRevenue > 0
    && is_numeric($refunds)
) {
    $refundRate =
        ((float) $refunds / (float) $totalRevenue) * 100;
}


/*
|--------------------------------------------------------------------------
| CONNECTION STATUS
|--------------------------------------------------------------------------
*/

$apiAvailable = $firstValue([
    $path($priority, 'connection.available'),
    $path($priority, 'connection.connected'),
    $priority['api_available'] ?? null,
    $directApiAvailable ?? null,
], null);

if ($apiAvailable === null) {
    $apiAvailable =
        $totalRevenue !== null
        || $appointmentsValue !== null
        || !empty($direct);
}

$apiAvailable = (bool) $apiAvailable;


/*
|--------------------------------------------------------------------------
| PROVIDER PERFORMANCE
|--------------------------------------------------------------------------
*/

$providers = [];

foreach ([
    $priority['providers'] ?? null,
    $priority['provider_performance'] ?? null,
    $direct['providers'] ?? null,
    $direct['provider_performance'] ?? null,
    $providerPerformance ?? null,
] as $candidate) {
    if (is_array($candidate) && !empty($candidate)) {
        $providers = $candidate;
        break;
    }
}


/*
|--------------------------------------------------------------------------
| BUSINESS MIX
|--------------------------------------------------------------------------
*/

$businessMix = [];

foreach ([
    $priority['business_mix'] ?? null,
    $priority['mix'] ?? null,
    $direct['business_mix'] ?? null,
    $direct['mix'] ?? null,
    $businessMix ?? null,
] as $candidate) {
    if (is_array($candidate) && !empty($candidate)) {
        $businessMix = $candidate;
        break;
    }
}


/*
|--------------------------------------------------------------------------
| COMPARISON
|--------------------------------------------------------------------------
*/

$comparisonRows = [];

foreach ([
    $path($priority, 'comparison.rows'),
    $priority['comparison_rows'] ?? null,
    $comparisonRows ?? null,
    $comparison ?? null,
] as $candidate) {
    if (is_array($candidate) && !empty($candidate)) {
        $comparisonRows = $candidate;
        break;
    }
}


/*
|--------------------------------------------------------------------------
| COVERAGE / MISSING SCOPES
|--------------------------------------------------------------------------
*/

$missingScopes = [];

foreach ([
    $path($priority, 'coverage.missing_scopes'),
    $priority['missing_scopes'] ?? null,
    $missingScopes ?? null,
] as $candidate) {
    if (is_array($candidate)) {
        $missingScopes = array_values(
            array_unique(
                array_filter(
                    array_map('strval', $candidate)
                )
            )
        );

        if ($missingScopes) {
            break;
        }
    }
}

$coverageNotesValue = $firstValue([
    $priority['coverage_notes'] ?? null,
    $path($priority, 'coverage.notes'),
    $coverageNotes ?? null,
], '');

if (is_array($coverageNotesValue)) {
    $coverageNotesValue = implode(
        ' ',
        array_map('strval', $coverageNotesValue)
    );
}


/*
|--------------------------------------------------------------------------
| PRIORITIES
|--------------------------------------------------------------------------
*/

$priorities = [];

if (
    isset($priority['priorities'])
    && is_array($priority['priorities'])
) {
    $priorities = $priority['priorities'];
}

if (!$priorities) {
    if ($refundRate !== null && $refundRate >= 2) {
        $priorities[] = [
            'level' => 'attention',
            'title' => 'Review refund drivers',
            'text'  => 'Refunds represent '
                . number_format($refundRate, 1)
                . '% of revenue for this period.',
        ];
    }

    if (
        $averageTicket !== null
        && $appointmentsValue !== null
    ) {
        $priorities[] = [
            'level' => 'positive',
            'title' => 'Revenue per appointment',
            'text'  => 'Average revenue per appointment is '
                . '$'
                . number_format($averageTicket, 2)
                . ' for this period.',
        ];
    }

    if (
        $newClients !== null
        && is_numeric($newClients)
        && is_numeric($appointmentsValue)
        && (float) $appointmentsValue > 0
    ) {
        $share = (
            (float) $newClients
            / (float) $appointmentsValue
        ) * 100;

        $priorities[] = [
            'level' => 'neutral',
            'title' => 'New-client contribution',
            'text'  => number_format($share, 1)
                . '% of appointments are associated with new clients.',
        ];
    }

    if (!$priorities) {
        $priorities[] = [
            'level' => 'neutral',
            'title' => 'Boulevard data is ready',
            'text'  => 'The dashboard is using available Boulevard API data for the selected period.',
        ];
    }
}


/*
|--------------------------------------------------------------------------
| FLASH MESSAGES
|--------------------------------------------------------------------------
*/

$flashSuccess = $_SESSION['flash_success'] ?? null;
$flashError   = $_SESSION['flash_error'] ?? null;

unset(
    $_SESSION['flash_success'],
    $_SESSION['flash_error']
);
?>

<link
    rel="stylesheet"
    href="assets/css/ruma-boulevard-v2.css?v=2.0.0"
>

<div class="ruma-priority-page">

    <?php if ($flashSuccess): ?>
        <div class="pi-alert pi-alert-success">
            <?= $esc($flashSuccess) ?>
        </div>
    <?php endif; ?>

    <?php if ($flashError): ?>
        <div class="pi-alert pi-alert-error">
            <?= $esc($flashError) ?>
        </div>
    <?php endif; ?>


    <header class="pi-header">

        <div class="pi-header-main">

            <div class="pi-eyebrow">
                <?= $esc($businessName) ?> · Boulevard
            </div>

            <h1>Priority Intelligence</h1>

            <div class="pi-meta-row">

                <?php if ($periodStart && $periodEnd): ?>
                    <span class="pi-period">
                        <?= $esc($displayDate($periodStart)) ?>
                        –
                        <?= $esc($displayDate($periodEnd)) ?>
                    </span>
                <?php endif; ?>

                <span
                    class="pi-status-pill <?= $apiAvailable
                        ? 'is-connected'
                        : 'is-disconnected' ?>"
                >
                    <span class="pi-status-dot"></span>

                    <?= $apiAvailable
                        ? 'Boulevard API connected'
                        : 'Boulevard API unavailable' ?>
                </span>

                <?php if ($lastUpdated): ?>
                    <span class="pi-last-sync">
                        Updated
                        <?= $esc($lastUpdated) ?>
                    </span>
                <?php endif; ?>

            </div>

        </div>


        <form
            method="post"
            action="index.php?page=boulevard-ruma-intelligence-sync"
            class="pi-refresh-form"
        >

            <?php
            if (function_exists('csrf_field')) {
                echo csrf_field();
            }
            ?>

            <?php if ($periodStart): ?>
                <input
                    type="hidden"
                    name="period_start"
                    value="<?= $esc($periodStart) ?>"
                >

                <input
                    type="hidden"
                    name="start_date"
                    value="<?= $esc($periodStart) ?>"
                >
            <?php endif; ?>

            <?php if ($periodEnd): ?>
                <input
                    type="hidden"
                    name="period_end"
                    value="<?= $esc($periodEnd) ?>"
                >

                <input
                    type="hidden"
                    name="end_date"
                    value="<?= $esc($periodEnd) ?>"
                >
            <?php endif; ?>

            <button type="submit" class="pi-primary-button">
                Refresh Boulevard Data
            </button>

        </form>

    </header>


    <section class="pi-section">

        <div class="pi-section-heading">
            <div>
                <div class="pi-section-kicker">
                    Performance
                </div>

                <h2>Business snapshot</h2>
            </div>

            <p>
                Near-live performance reconstructed from Boulevard
                operational data.
            </p>
        </div>


        <div class="pi-kpi-grid">

            <article class="pi-kpi-card">
                <div class="pi-kpi-label">
                    Total Revenue
                </div>

                <div class="pi-kpi-value">
                    <?= $money($totalRevenue) ?>
                </div>

                <div class="pi-kpi-note">
                    Selected period
                </div>
            </article>


            <article class="pi-kpi-card">
                <div class="pi-kpi-label">
                    Appointments
                </div>

                <div class="pi-kpi-value">
                    <?= $number($appointmentsValue) ?>
                </div>

                <div class="pi-kpi-note">
                    Boulevard appointments
                </div>
            </article>


            <article class="pi-kpi-card">
                <div class="pi-kpi-label">
                    Avg. Revenue / Appointment
                </div>

                <div class="pi-kpi-value">
                    <?= $money($averageTicket) ?>
                </div>

                <div class="pi-kpi-note">
                    Revenue ÷ appointments
                </div>
            </article>


            <article class="pi-kpi-card">
                <div class="pi-kpi-label">
                    Refunds
                </div>

                <div class="pi-kpi-value">
                    <?= $money($refunds) ?>
                </div>

                <div class="pi-kpi-note">
                    <?php if ($refundRate !== null): ?>
                        <?= $percent($refundRate) ?>
                        of revenue
                    <?php else: ?>
                        Selected period
                    <?php endif; ?>
                </div>
            </article>


            <article class="pi-kpi-card">
                <div class="pi-kpi-label">
                    New Clients
                </div>

                <div class="pi-kpi-value">
                    <?= $number($newClients) ?>
                </div>

                <div class="pi-kpi-note">
                    <?php if ($newClients === null): ?>
                        Requires client access
                    <?php else: ?>
                        New-client activity
                    <?php endif; ?>
                </div>
            </article>


            <article class="pi-kpi-card">
                <div class="pi-kpi-label">
                    Blended Utilization
                </div>

                <div class="pi-kpi-value">
                    <?= $utilization === null
                        ? '—'
                        : $percent($utilization) ?>
                </div>

                <div class="pi-kpi-note">
                    <?php if ($utilization === null): ?>
                        Requires shift access
                    <?php else: ?>
                        Booked vs scheduled time
                    <?php endif; ?>
                </div>
            </article>


            <article class="pi-kpi-card">
                <div class="pi-kpi-label">
                    Active ARR
                </div>

                <div class="pi-kpi-value">
                    <?= $money($activeArr) ?>
                </div>

                <div class="pi-kpi-note">
                    <?php if ($activeArr === null): ?>
                        Requires membership access
                    <?php else: ?>
                        Annualised recurring revenue
                    <?php endif; ?>
                </div>
            </article>

        </div>

    </section>


    <section class="pi-section">

        <div class="pi-section-heading">
            <div>
                <div class="pi-section-kicker">
                    Intelligence
                </div>

                <h2>Priority opportunities</h2>
            </div>
        </div>


        <div class="pi-priority-grid">

            <?php foreach ($priorities as $item): ?>

                <?php
                if (!is_array($item)) {
                    continue;
                }

                $level = (string) (
                    $item['level']
                    ?? $item['status']
                    ?? 'neutral'
                );

                if (!in_array(
                    $level,
                    ['positive', 'attention', 'neutral'],
                    true
                )) {
                    $level = 'neutral';
                }
                ?>

                <article
                    class="pi-priority-card pi-priority-<?= $esc($level) ?>"
                >
                    <div class="pi-priority-marker"></div>

                    <div>
                        <h3>
                            <?= $esc(
                                $item['title']
                                ?? $item['label']
                                ?? 'Insight'
                            ) ?>
                        </h3>

                        <p>
                            <?= $esc(
                                $item['text']
                                ?? $item['description']
                                ?? ''
                            ) ?>
                        </p>
                    </div>
                </article>

            <?php endforeach; ?>

        </div>

    </section>


    <?php if ($providers): ?>

        <section class="pi-section">

            <div class="pi-section-heading">
                <div>
                    <div class="pi-section-kicker">
                        Team
                    </div>

                    <h2>Provider performance</h2>
                </div>

                <p>
                    Performance from appointments and attributable
                    Boulevard sales data.
                </p>
            </div>


            <div class="pi-table-card">

                <div class="pi-table-scroll">

                    <table class="pi-table">

                        <thead>
                            <tr>
                                <th>Provider</th>
                                <th>Revenue</th>
                                <th>Appointments</th>
                                <th>Avg. Ticket</th>
                                <th>Utilization</th>
                            </tr>
                        </thead>

                        <tbody>

                        <?php foreach ($providers as $provider): ?>

                            <?php
                            if (!is_array($provider)) {
                                continue;
                            }

                            $providerRevenue = $provider['revenue']
                                ?? $provider['total_revenue']
                                ?? null;

                            $providerAppointments =
                                $provider['appointments']
                                ?? $provider['appointment_count']
                                ?? null;

                            $providerTicket =
                                $provider['average_ticket']
                                ?? $provider['avg_ticket']
                                ?? null;

                            if (
                                $providerTicket === null
                                && is_numeric($providerRevenue)
                                && is_numeric($providerAppointments)
                                && (float) $providerAppointments > 0
                            ) {
                                $providerTicket =
                                    (float) $providerRevenue
                                    / (float) $providerAppointments;
                            }
                            ?>

                            <tr>
                                <td>
                                    <strong>
                                        <?= $esc(
                                            $provider['name']
                                            ?? $provider['provider_name']
                                            ?? $provider['staff_name']
                                            ?? 'Provider'
                                        ) ?>
                                    </strong>
                                </td>

                                <td>
                                    <?= $money($providerRevenue) ?>
                                </td>

                                <td>
                                    <?= $number(
                                        $providerAppointments
                                    ) ?>
                                </td>

                                <td>
                                    <?= $money($providerTicket) ?>
                                </td>

                                <td>
                                    <?php
                                    $providerUtilization =
                                        $provider['utilization']
                                        ?? null;

                                    echo $providerUtilization === null
                                        ? '—'
                                        : $percent(
                                            $providerUtilization
                                        );
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


    <?php if ($businessMix): ?>

        <section class="pi-section">

            <div class="pi-section-heading">
                <div>
                    <div class="pi-section-kicker">
                        Revenue Mix
                    </div>

                    <h2>Business mix</h2>
                </div>
            </div>


            <div class="pi-mix-grid">

                <?php foreach ($businessMix as $key => $mixItem): ?>

                    <?php
                    if (is_array($mixItem)) {
                        $mixLabel =
                            $mixItem['label']
                            ?? $mixItem['name']
                            ?? ucwords(
                                str_replace(
                                    '_',
                                    ' ',
                                    (string) $key
                                )
                            );

                        $mixValue =
                            $mixItem['value']
                            ?? $mixItem['revenue']
                            ?? $mixItem['amount']
                            ?? null;
                    } else {
                        $mixLabel = ucwords(
                            str_replace(
                                '_',
                                ' ',
                                (string) $key
                            )
                        );

                        $mixValue = $mixItem;
                    }
                    ?>

                    <article class="pi-mix-card">
                        <span>
                            <?= $esc($mixLabel) ?>
                        </span>

                        <strong>
                            <?= $money($mixValue) ?>
                        </strong>
                    </article>

                <?php endforeach; ?>

            </div>

        </section>

    <?php endif; ?>


    <section class="pi-diagnostics-section">

        <details class="pi-diagnostics">

            <summary>

                <span>
                    Data validation &amp; Boulevard diagnostics
                </span>

                <span class="pi-diagnostics-hint">
                    API scopes, PDF comparison and technical coverage
                </span>

            </summary>


            <div class="pi-diagnostics-body">

                <div class="pi-diagnostic-status-grid">

                    <div class="pi-diagnostic-status">
                        <span>Direct Boulevard API</span>

                        <strong class="<?= $apiAvailable
                            ? 'is-good'
                            : 'is-bad' ?>">
                            <?= $apiAvailable
                                ? 'Available'
                                : 'Unavailable' ?>
                        </strong>
                    </div>


                    <div class="pi-diagnostic-status">
                        <span>Selected period</span>

                        <strong>
                            <?= $periodStart && $periodEnd
                                ? $esc(
                                    $displayDate($periodStart)
                                    . ' – '
                                    . $displayDate($periodEnd)
                                )
                                : 'Not selected' ?>
                        </strong>
                    </div>


                    <div class="pi-diagnostic-status">
                        <span>Missing optional scopes</span>

                        <strong>
                            <?= count($missingScopes) ?>
                        </strong>
                    </div>

                </div>


                <?php if ($missingScopes): ?>

                    <div class="pi-debug-block">

                        <h3>Optional scopes not currently granted</h3>

                        <div class="pi-scope-list">

                            <?php foreach ($missingScopes as $scope): ?>
                                <code>
                                    <?= $esc($scope) ?>
                                </code>
                            <?php endforeach; ?>

                        </div>

                        <p>
                            These do not need to break the whole
                            dashboard. Only the KPIs that depend on
                            those scopes should remain unavailable.
                        </p>

                    </div>

                <?php endif; ?>


                <?php if ($coverageNotesValue): ?>

                    <div class="pi-debug-block">

                        <h3>Coverage notes</h3>

                        <p>
                            <?= nl2br(
                                $esc($coverageNotesValue)
                            ) ?>
                        </p>

                    </div>

                <?php endif; ?>


                <div class="pi-debug-block">

                    <h3>Boulevard PDF comparison</h3>

                    <p class="pi-debug-description">
                        API and uploaded-report values should only be
                        compared when period, timezone and KPI
                        definitions are equivalent.
                    </p>


                    <?php if ($comparisonRows): ?>

                        <div class="pi-table-scroll">

                            <table class="pi-table">

                                <thead>
                                    <tr>
                                        <th>Metric</th>
                                        <th>API</th>
                                        <th>Uploaded</th>
                                        <th>Difference</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>

                                <tbody>

                                <?php foreach ($comparisonRows as $row): ?>

                                    <?php
                                    if (!is_array($row)) {
                                        continue;
                                    }

                                    $metric =
                                        $row['metric']
                                        ?? $row['label']
                                        ?? 'Metric';

                                    $apiValue =
                                        $row['api']
                                        ?? $row['api_value']
                                        ?? null;

                                    $uploadedValue =
                                        $row['uploaded']
                                        ?? $row['pdf']
                                        ?? $row['uploaded_value']
                                        ?? null;

                                    $difference =
                                        $row['difference']
                                        ?? null;

                                    $status =
                                        $row['status']
                                        ?? 'review';

                                    $isMoney =
                                        str_contains(
                                            strtolower(
                                                (string) $metric
                                            ),
                                            'revenue'
                                        )
                                        || str_contains(
                                            strtolower(
                                                (string) $metric
                                            ),
                                            'refund'
                                        )
                                        || str_contains(
                                            strtolower(
                                                (string) $metric
                                            ),
                                            'sales'
                                        )
                                        || str_contains(
                                            strtolower(
                                                (string) $metric
                                            ),
                                            'arr'
                                        )
                                        || str_contains(
                                            strtolower(
                                                (string) $metric
                                            ),
                                            'mrr'
                                        );
                                    ?>

                                    <tr>
                                        <td>
                                            <strong>
                                                <?= $esc($metric) ?>
                                            </strong>
                                        </td>

                                        <td>
                                            <?= $isMoney
                                                ? $money($apiValue)
                                                : $number($apiValue, 2) ?>
                                        </td>

                                        <td>
                                            <?= $isMoney
                                                ? $money($uploadedValue)
                                                : $number(
                                                    $uploadedValue,
                                                    2
                                                ) ?>
                                        </td>

                                        <td>
                                            <?= $difference === null
                                                ? '—'
                                                : (
                                                    $isMoney
                                                        ? $money(
                                                            $difference
                                                        )
                                                        : $number(
                                                            $difference,
                                                            2
                                                        )
                                                ) ?>
                                        </td>

                                        <td>
                                            <span
                                                class="pi-compare-status"
                                            >
                                                <?= $esc(
                                                    ucfirst(
                                                        (string) $status
                                                    )
                                                ) ?>
                                            </span>
                                        </td>
                                    </tr>

                                <?php endforeach; ?>

                                </tbody>

                            </table>

                        </div>

                    <?php else: ?>

                        <div class="pi-empty-state">
                            No exact-period PDF comparison has been
                            selected. This does not prevent the live
                            Priority Intelligence dashboard from
                            working.
                        </div>

                    <?php endif; ?>

                </div>

            </div>

        </details>

    </section>

</div>