<?php
/** @var array $model */
/** @var array|null $liveResult */

$h = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$api = is_array($model['api_canonical'] ?? null) ? $model['api_canonical'] : null;
$comparison = is_array($model['comparison'] ?? null) ? $model['comparison'] : null;
$manualBatches = (array)($model['manual_batches'] ?? []);
$activeRun = is_array($model['active_run'] ?? null) ? $model['active_run'] : null;
$apiBatch = is_array($model['api_batch'] ?? null) ? $model['api_batch'] : null;
$directApi = is_array($model['direct_api'] ?? null) ? $model['direct_api'] : null;
$directComparison = is_array($model['direct_comparison'] ?? null) ? $model['direct_comparison'] : null;

$metricValue = static function (?array $metric): string {
    if (!$metric || empty($metric['available']) || !is_numeric($metric['value'] ?? null)) {
        return 'Unavailable';
    }
    $value = (float)$metric['value'];
    return match ((string)($metric['format'] ?? 'number')) {
        'currency' => '$' . number_format($value, 2),
        'percent' => number_format($value, 1) . '%',
        default => number_format($value, abs($value - round($value)) < 0.0001 ? 0 : 2),
    };
};

$comparisonValue = static function (?float $value, string $format): string {
    if ($value === null) return '—';
    return match ($format) {
        'currency' => '$' . number_format($value, 2),
        'percent', 'percent_points' => number_format($value, 1) . '%',
        'number' => number_format($value, 0),
        default => number_format($value, 2),
    };
};

$directMetricValue = static function (?array $metric): string {
    if (!$metric || empty($metric['available']) || !is_numeric($metric['value'] ?? null)) {
        return 'Unavailable';
    }
    $value = (float)$metric['value'];
    return match ((string)($metric['format'] ?? 'number')) {
        'currency' => '$' . number_format($value, 2),
        'percent' => number_format($value * 100.0, 1) . '%',
        default => number_format($value, abs($value - round($value)) < 0.0001 ? 0 : 2),
    };
};

$directComparisonValue = static function (?float $value, string $format): string {
    if ($value === null) return '—';
    return match ($format) {
        'currency' => '$' . number_format($value, 2),
        'percent' => number_format($value * 100.0, 1) . '%',
        'number' => number_format($value, 0),
        default => number_format($value, 2),
    };
};

$path = static function (array $source, string $path): mixed {
    $value = $source;
    foreach (explode('.', $path) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return null;
        }
        $value = $value[$part];
    }
    return $value;
};

$directKpis = [
    'performance.total_revenue' => 'Total revenue',
    'performance.total_appointments' => 'Appointments',
    'performance.new_clients' => 'New clients',
    'performance.blended_utilization' => 'Blended utilization',
    'performance.current_active_mrr' => 'Active MRR',
    'sales_summary.requested_appointments' => 'Requested appointments',
    'sales_summary.service_revenue' => 'Service revenue',
    'sales_summary.product_revenue' => 'Product revenue',
    'sales_summary.membership_revenue' => 'Membership revenue',
    'sales_summary.package_revenue' => 'Package revenue',
    'financial.refunds' => 'Refunds',
    'membership.current_arr' => 'Active ARR',
];

$kpiOrder = [
    'total_revenue' => 'Total revenue',
    'appointments' => 'Appointments',
    'requested_appointments' => 'Requested appointments',
    'new_clients' => 'New clients',
    'utilization' => 'Blended utilization',
    'active_mrr' => 'Active MRR',
    'active_memberships' => 'Active memberships',
    'service_revenue' => 'Service revenue',
    'product_revenue' => 'Product revenue',
    'membership_revenue' => 'Membership revenue',
    'package_revenue' => 'Package revenue',
    'refunds' => 'Refunds',
];
?>

<link rel="stylesheet" href="<?= $h(url('assets/css/ruma-boulevard-v2.css')) ?>">

<div class="ruma-v2-page">
    <header class="ruma-v2-hero">
        <div>
            <div class="ruma-v2-eyebrow">RUMA · Boulevard v2</div>
            <h1>Report-aligned Priority Intelligence</h1>
            <p>
                Direct GraphQL remains the complete operational console. Reporting KPIs on this page come from
                Boulevard Report Export API files parsed with the same rules as manually uploaded Boulevard reports.
            </p>
        </div>
        <div class="ruma-v2-actions">
            <a class="btn" href="<?= $h(url('boulevard-live-console')) ?>">Live API Console</a>
            <?php if (!$apiBatch): ?>
                <form method="post" action="<?= $h(url('boulevard-ruma-intelligence-sync')) ?>">
                    <?= csrf_field() ?>
                    <button class="btn btn-primary" type="submit">Fetch report-aligned API data</button>
                </form>
            <?php endif; ?>
        </div>
    </header>

    <section class="ruma-v2-source-grid">
        <div class="ruma-v2-source-card">
            <span>Period</span>
            <strong><?= $h($model['period_start']) ?> → <?= $h($model['period_end']) ?></strong>
        </div>
        <div class="ruma-v2-source-card">
            <span>Direct API fetch</span>
            <strong><?= !empty($liveResult['success']) ? 'Available' : 'Fetch required' ?></strong>
            <small>Complete cursor pagination is handled before UI paging.</small>
        </div>
        <div class="ruma-v2-source-card">
            <span>Report-aligned API</span>
            <strong><?= $apiBatch ? 'Ready' : ($activeRun ? 'Processing' : 'Not fetched') ?></strong>
            <?php if ($activeRun): ?>
                <small>Sync run #<?= (int)$activeRun['id'] ?> · <?= $h($activeRun['status']) ?></small>
            <?php elseif ($apiBatch): ?>
                <small>API batch #<?= (int)$apiBatch['id'] ?></small>
            <?php endif; ?>
        </div>
        <div class="ruma-v2-source-card">
            <span>Manual uploads found</span>
            <strong><?= count($manualBatches) ?></strong>
            <small>Only exact-period manual batches are eligible for comparison.</small>
        </div>
    </section>

    <?php if ($directApi): ?>
        <section class="ruma-v2-section" id="direct-api-intelligence">
            <div class="ruma-v2-section-head">
                <div><span>LIVE</span><h2>Direct Boulevard Admin API intelligence</h2></div>
                <p>
                    Near-live KPIs reconstructed from appointments, orders, shifts, memberships and catalogue data.
                    This layer is intentionally separate from Boulevard Report Export definitions.
                </p>
            </div>

            <div class="ruma-v2-kpis">
                <?php foreach ($directKpis as $metricPath => $label):
                    $metric = $path($directApi, $metricPath);
                    $metric = is_array($metric) ? $metric : null;
                ?>
                    <article class="ruma-v2-kpi <?= !empty($metric['available']) ? '' : 'is-unavailable' ?>" tabindex="0">
                        <span class="ruma-v2-kpi-label"><?= $h($label) ?></span>
                        <strong><?= $h($directMetricValue($metric)) ?></strong>
                        <small><?= $h($metric['source'] ?? 'Direct Admin GraphQL') ?></small>
                        <div class="ruma-v2-kpi-definition">
                            <?= $h($metric['definition'] ?? ($metric['source'] ?? '')) ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

            <?php if (!empty($liveResult['priority_intelligence_warnings'])): ?>
                <div class="ruma-v2-note" style="margin-top:14px">
                    <strong>Coverage notes:</strong>
                    <?= $h(implode(' ', array_map('strval', (array)$liveResult['priority_intelligence_warnings']))) ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="ruma-v2-section" id="direct-comparison">
            <div class="ruma-v2-section-head">
                <div><span>A</span><h2>Direct Admin API vs uploaded data</h2></div>
                <p>
                    This comparison shows which uploaded/PDF-derived KPIs can be recreated directly from live GraphQL objects.
                    A difference here can be a metric-definition difference, not necessarily an API error.
                </p>
            </div>

            <?php if (!$manualBatches): ?>
                <div class="ruma-v2-note">No completed RUMA upload exists for this exact period.</div>
            <?php else: ?>
                <form class="ruma-v2-compare-form" method="post" action="<?= $h(url('boulevard-ruma-intelligence-compare-v2')) ?>">
                    <?= csrf_field() ?>
                    <label>
                        Uploaded period to compare
                        <select name="manual_batch_id" required>
                            <option value="">Choose exact-period upload</option>
                            <?php foreach ($manualBatches as $batch): ?>
                                <option value="<?= (int)$batch['id'] ?>" <?= (int)($model['direct_comparison_batch']['id'] ?? $model['selected_manual_batch']['id'] ?? 0) === (int)$batch['id'] ? 'selected' : '' ?>>
                                    Batch #<?= (int)$batch['id'] ?> · <?= $h($batch['frequency']) ?> · <?= $h($batch['created_at']) ?> · completeness <?= number_format((float)$batch['completeness_score'], 1) ?>%
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <button class="btn btn-primary" type="submit">Compare live API with uploaded data</button>
                </form>
            <?php endif; ?>

            <?php if ($directComparison): ?>
                <div class="ruma-v2-compare-summary">
                    <div><span>Direct match rate</span><strong><?= number_format((float)$directComparison['match_percent'], 1) ?>%</strong></div>
                    <div><span>Comparable</span><strong><?= (int)$directComparison['comparable_metrics'] ?></strong></div>
                    <div><span>Matched</span><strong><?= (int)$directComparison['matched_metrics'] ?></strong></div>
                    <div><span>Review</span><strong><?= (int)$directComparison['review_metrics'] ?></strong></div>
                </div>

                <div class="table-scroll">
                    <table class="ruma-v2-table ruma-v2-comparison-table">
                        <thead>
                        <tr><th>Metric</th><th>Direct Admin API</th><th>Uploaded data</th><th>Difference</th><th>Status</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ((array)$directComparison['metrics'] as $row): ?>
                            <tr class="is-<?= $h($row['status']) ?>">
                                <td><strong><?= $h($row['label']) ?></strong></td>
                                <td><?= $h($directComparisonValue($row['api_value'], (string)$row['format'])) ?></td>
                                <td><?= $h($directComparisonValue($row['upload_value'], (string)$row['format'])) ?></td>
                                <td><?= $row['difference'] === null ? '—' : $h($directComparisonValue((float)$row['difference'], (string)$row['format'])) ?></td>
                                <td><span class="ruma-v2-status ruma-v2-status-<?= $h($row['status']) ?>"><?= $h(str_replace('_', ' ', $row['status'])) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <details class="ruma-v2-details">
                    <summary>Provider-level direct API comparison</summary>
                    <?php foreach ((array)$directComparison['providers'] as $provider): ?>
                        <div class="ruma-v2-provider-compare">
                            <h3><?= $h($provider['provider']) ?> <span class="ruma-v2-status ruma-v2-status-<?= $h($provider['status']) ?>"><?= $h($provider['status']) ?></span></h3>
                            <div class="table-scroll">
                                <table class="ruma-v2-table">
                                    <thead><tr><th>Metric</th><th>Direct API</th><th>Upload</th><th>Difference</th><th>Status</th></tr></thead>
                                    <tbody>
                                    <?php foreach ((array)$provider['metrics'] as $row): ?>
                                        <tr>
                                            <td><?= $h($row['label']) ?></td>
                                            <td><?= $h($directComparisonValue($row['api_value'], (string)$row['format'])) ?></td>
                                            <td><?= $h($directComparisonValue($row['upload_value'], (string)$row['format'])) ?></td>
                                            <td><?= $row['difference'] === null ? '—' : $h($directComparisonValue((float)$row['difference'], (string)$row['format'])) ?></td>
                                            <td><span class="ruma-v2-status ruma-v2-status-<?= $h($row['status']) ?>"><?= $h(str_replace('_', ' ', $row['status'])) ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </details>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if (!$api): ?>
        <section class="ruma-v2-empty">
            <h2>Canonical API reporting data is not ready yet</h2>
            <p>
                Run the report-aligned API sync. The existing Boulevard sync worker will download and validate the
                mapped Boulevard reports, then create a completed API batch for this exact period.
            </p>
            <?php if ($activeRun): ?>
                <a class="btn btn-primary" href="<?= $h(url('business-boulevard-sync', ['id' => (int)$activeRun['id']])) ?>">
                    Open sync progress
                </a>
            <?php endif; ?>
        </section>
    <?php else: ?>

        <section class="ruma-v2-section">
            <div class="ruma-v2-section-head">
                <div>
                    <span>01</span>
                    <h2>Canonical business KPIs</h2>
                </div>
                <p>Every card shows the exact Boulevard report source used for its definition.</p>
            </div>

            <div class="ruma-v2-kpis">
                <?php foreach ($kpiOrder as $key => $label):
                    $metric = is_array($api['kpis'][$key] ?? null) ? $api['kpis'][$key] : null;
                ?>
                    <article class="ruma-v2-kpi <?= !empty($metric['available']) ? '' : 'is-unavailable' ?>" tabindex="0">
                        <span class="ruma-v2-kpi-label"><?= $h($label) ?></span>
                        <strong><?= $h($metricValue($metric)) ?></strong>
                        <small><?= $h($metric['source'] ?? 'Source unavailable') ?></small>
                        <div class="ruma-v2-kpi-definition"><?= $h($metric['definition'] ?? '') ?></div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="ruma-v2-section ruma-v2-chart-section">
            <div class="ruma-v2-section-head">
                <div><span>02</span><h2>Daily performance</h2></div>
                <p>Revenue and appointment volume use every report day in the selected period.</p>
            </div>
            <div class="ruma-v2-chart-wrap"><canvas id="rumaV2DailyChart"></canvas></div>
        </section>

        <section class="ruma-v2-section ruma-v2-chart-section">
            <div class="ruma-v2-section-head">
                <div><span>03</span><h2>Revenue mix</h2></div>
                <p>Service, product, membership, package and tip revenue from Daily Summary.</p>
            </div>
            <div class="ruma-v2-chart-wrap ruma-v2-chart-small"><canvas id="rumaV2RevenueMixChart"></canvas></div>
        </section>

        <section class="ruma-v2-section">
            <div class="ruma-v2-section-head">
                <div><span>04</span><h2>Provider detail</h2></div>
                <p>Provider service revenue comes from Service Commission; utilization and appointment metrics come from Appointment Metrics.</p>
            </div>
            <div class="table-scroll">
                <table class="ruma-v2-table">
                    <thead>
                    <tr>
                        <th>Provider</th>
                        <th>Service revenue</th>
                        <th>Utilization</th>
                        <th>Scheduled hours</th>
                        <th>Appointments</th>
                        <th>Requested</th>
                        <th>New clients</th>
                        <th>Revenue/hour</th>
                        <th>Product revenue</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ((array)$api['providers'] as $provider): ?>
                        <tr>
                            <td><strong><?= $h($provider['name'] ?? '') ?></strong></td>
                            <td>$<?= number_format((float)($provider['service_revenue'] ?? 0), 2) ?></td>
                            <td><?= $provider['utilization'] === null ? '—' : number_format((float)$provider['utilization'], 1) . '%' ?></td>
                            <td><?= $provider['hours_scheduled'] === null ? '—' : number_format((float)$provider['hours_scheduled'], 2) ?></td>
                            <td><?= $provider['appointments'] === null ? '—' : number_format((float)$provider['appointments'], 0) ?></td>
                            <td><?= $provider['requested'] === null ? '—' : number_format((float)$provider['requested'], 0) ?></td>
                            <td><?= $provider['new_clients'] === null ? '—' : number_format((float)$provider['new_clients'], 0) ?></td>
                            <td><?= $provider['revenue_per_hour'] === null ? '—' : '$' . number_format((float)$provider['revenue_per_hour'], 2) ?></td>
                            <td><?= $provider['product_revenue'] === null ? '—' : '$' . number_format((float)$provider['product_revenue'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="ruma-v2-section" id="comparison">
            <div class="ruma-v2-section-head">
                <div><span>B</span><h2>Report Export API vs uploaded Boulevard reports</h2></div>
                <p>The API side is the Boulevard Report Export API batch, not a reconstruction of raw order/appointment objects.</p>
            </div>

            <?php if (!$manualBatches): ?>
                <div class="ruma-v2-note">No manual RUMA upload exists for this exact period on this server.</div>
            <?php else: ?>
                <form class="ruma-v2-compare-form" method="post" action="<?= $h(url('boulevard-ruma-intelligence-compare-v2')) ?>">
                    <?= csrf_field() ?>
                    <label>
                        Manual upload to compare
                        <select name="manual_batch_id" required>
                            <option value="">Choose exact-period upload</option>
                            <?php foreach ($manualBatches as $batch): ?>
                                <option value="<?= (int)$batch['id'] ?>" <?= (int)($model['selected_manual_batch']['id'] ?? 0) === (int)$batch['id'] ? 'selected' : '' ?>>
                                    Batch #<?= (int)$batch['id'] ?> · <?= $h($batch['frequency']) ?> · <?= $h($batch['created_at']) ?> · completeness <?= number_format((float)$batch['completeness_score'], 1) ?>%
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <button class="btn btn-primary" type="submit">Compare Report Export with uploaded reports</button>
                </form>
            <?php endif; ?>

            <?php if ($comparison): ?>
                <div class="ruma-v2-compare-summary">
                    <div><span>Match rate</span><strong><?= number_format((float)$comparison['match_percent'], 1) ?>%</strong></div>
                    <div><span>Comparable</span><strong><?= (int)$comparison['comparable_metrics'] ?></strong></div>
                    <div><span>Matched</span><strong><?= (int)$comparison['matched_metrics'] ?></strong></div>
                    <div><span>Review</span><strong><?= (int)$comparison['review_metrics'] ?></strong></div>
                </div>

                <div class="table-scroll">
                    <table class="ruma-v2-table ruma-v2-comparison-table">
                        <thead>
                        <tr>
                            <th>Metric</th>
                            <th>API Report Export</th>
                            <th>Manual Upload</th>
                            <th>Difference</th>
                            <th>Definition</th>
                            <th>Status</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ((array)$comparison['metrics'] as $row): ?>
                            <tr class="is-<?= $h($row['status']) ?>">
                                <td><strong><?= $h($row['label']) ?></strong></td>
                                <td><?= $h($comparisonValue($row['api_value'], (string)$row['format'])) ?></td>
                                <td><?= $h($comparisonValue($row['manual_value'], (string)$row['format'])) ?></td>
                                <td><?= $row['difference'] === null ? '—' : $h($comparisonValue((float)$row['difference'], (string)$row['format'])) ?></td>
                                <td class="ruma-v2-definition-cell"><?= $h($row['definition'] ?? '') ?></td>
                                <td><span class="ruma-v2-status ruma-v2-status-<?= $h($row['status']) ?>"><?= $h(str_replace('_', ' ', $row['status'])) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <details class="ruma-v2-details">
                    <summary>Provider-level comparison</summary>
                    <?php foreach ((array)$comparison['providers'] as $provider): ?>
                        <div class="ruma-v2-provider-compare">
                            <h3><?= $h($provider['provider']) ?> <span class="ruma-v2-status ruma-v2-status-<?= $h($provider['status']) ?>"><?= $h($provider['status']) ?></span></h3>
                            <div class="table-scroll">
                                <table class="ruma-v2-table">
                                    <thead><tr><th>Metric</th><th>API</th><th>Manual</th><th>Difference</th><th>Status</th></tr></thead>
                                    <tbody>
                                    <?php foreach ((array)$provider['metrics'] as $row): ?>
                                        <tr>
                                            <td><?= $h($row['label']) ?></td>
                                            <td><?= $h($comparisonValue($row['api_value'], (string)$row['format'])) ?></td>
                                            <td><?= $h($comparisonValue($row['manual_value'], (string)$row['format'])) ?></td>
                                            <td><?= $row['difference'] === null ? '—' : $h($comparisonValue((float)$row['difference'], (string)$row['format'])) ?></td>
                                            <td><?= $h(str_replace('_', ' ', $row['status'])) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </details>
            <?php endif; ?>
        </section>

        <script id="rumaBoulevardV2Data" type="application/json"><?= json_encode([
            'daily' => array_values((array)$api['daily']),
            'revenueMix' => array_values((array)$api['revenue_mix']),
        ], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
        <script src="<?= $h(url('assets/js/ruma-boulevard-v2.js')) ?>"></script>
    <?php endif; ?>
</div>
