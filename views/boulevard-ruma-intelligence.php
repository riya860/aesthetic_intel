<?php
/** @var array $analytics */
/** @var array $comparisonBatches */
/** @var array|null $comparisonResult */

$pageScripts = ['ruma-boulevard-intelligence.js'];
$comparisonBatches = is_array($comparisonBatches ?? null) ? $comparisonBatches : [];
$comparisonResult = is_array($comparisonResult ?? null) ? $comparisonResult : null;

$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

$fmt = static function (array $m): string {
    if (!($m['available'] ?? false) || !is_numeric($m['value'] ?? null)) {
        return '—';
    }
    $v = (float)$m['value'];
    return match ($m['format'] ?? 'number') {
        'currency' => '$' . number_format($v, 2),
        'percent' => number_format($v * 100, 1) . '%',
        default => number_format($v, 0),
    };
};

$fmtRaw = static function ($value, string $format): string {
    if (!is_numeric($value)) return '—';
    $v = (float)$value;
    return match ($format) {
        'currency' => '$' . number_format($v, 2),
        'percent' => number_format($v * 100, 1) . '%',
        'decimal' => number_format($v, 1),
        default => number_format($v, 0),
    };
};

$change = static function (array $m): ?float {
    return isset($m['change']) && is_numeric($m['change'])
        ? (float)$m['change']
        : null;
};

$changeLabel = static function (array $m) use ($change): string {
    $v = $change($m);
    if ($v === null) return 'No prior comparison';
    return ($v >= 0 ? '+' : '') . number_format($v * 100, 1) . '% vs previous';
};

$sourceLabel = static function (array $m): string {
    $source = trim((string)($m['source'] ?? ''));
    if ($source === '') return 'Boulevard live analytics';
    if (str_contains(strtolower($source), 'fallback')) return 'Boulevard report fallback';
    return $source;
};

$metricCard = static function (string $label, array $m, string $icon = '↗') use ($h, $fmt, $change, $changeLabel, $sourceLabel): void {
    $available = !empty($m['available']);
    $delta = $change($m);
    $trendClass = $delta === null ? 'neutral' : ($delta > 0 ? 'positive' : ($delta < 0 ? 'negative' : 'neutral'));
    $previous = $m['previous'] ?? null;
    ?>
    <article
        class="ruma-kpi-card <?= $available ? '' : 'is-unavailable' ?> trend-<?= $h($trendClass) ?>"
        tabindex="0"
        role="button"
        aria-expanded="false"
        data-ruma-kpi
    >
        <div class="ruma-kpi-topline">
            <span class="ruma-kpi-label"><?= $h($label) ?></span>
            <span class="ruma-kpi-icon" aria-hidden="true"><?= $h($icon) ?></span>
        </div>
        <div class="ruma-kpi-value"><?= $h($fmt($m)) ?></div>
        <div class="ruma-kpi-meta">
            <?php if ($available && $delta !== null): ?>
                <span class="ruma-delta ruma-delta-<?= $h($trendClass) ?>">
                    <?= $delta > 0 ? '↑' : ($delta < 0 ? '↓' : '→') ?>
                    <?= $h(abs($delta) < .0000001 ? '0.0%' : number_format(abs($delta) * 100, 1) . '%') ?>
                </span>
            <?php elseif ($available): ?>
                <span class="ruma-delta ruma-delta-neutral">Current period</span>
            <?php else: ?>
                <span class="ruma-delta ruma-delta-neutral">Unavailable</span>
            <?php endif; ?>
            <span class="ruma-kpi-hint">Click for details</span>
        </div>
        <div class="ruma-kpi-details" hidden>
            <div><span>Comparison</span><strong><?= $h($changeLabel($m)) ?></strong></div>
            <div><span>Previous value</span><strong><?php
                if (!$available || !is_numeric($previous)) echo '—';
                else {
                    $pm = $m;
                    $pm['value'] = $previous;
                    echo $h($fmt($pm));
                }
            ?></strong></div>
            <div><span>Source</span><strong><?= $h($sourceLabel($m)) ?></strong></div>
        </div>
    </article>
    <?php
};

$perf = is_array($analytics['performance'] ?? null) ? $analytics['performance'] : [];
$providers = is_array($analytics['provider_details'] ?? null) ? $analytics['provider_details'] : [];
$membership = is_array($analytics['membership'] ?? null) ? $analytics['membership'] : [];
$sales = is_array($analytics['sales_summary'] ?? null) ? $analytics['sales_summary'] : [];
$fin = is_array($analytics['financial'] ?? null) ? $analytics['financial'] : [];
$mix = is_array($analytics['revenue_mix'] ?? null) ? $analytics['revenue_mix'] : [];
$daily = is_array($analytics['daily_performance'] ?? null) ? $analytics['daily_performance'] : [];
$insights = is_array($analytics['insights'] ?? null) ? $analytics['insights'] : [];
$coverage = is_array($analytics['coverage'] ?? null) ? $analytics['coverage'] : [];
$meta = is_array($analytics['meta'] ?? null) ? $analytics['meta'] : [];
$period = is_array($analytics['period'] ?? null) ? $analytics['period'] : [];

$chartPayload = [
    'performance' => [
        ['label' => 'Revenue', 'metric' => $perf['total_revenue'] ?? []],
        ['label' => 'Appointments', 'metric' => $perf['total_appointments'] ?? []],
        ['label' => 'New clients', 'metric' => $perf['new_clients'] ?? []],
        ['label' => 'Utilization', 'metric' => $perf['blended_utilization'] ?? []],
        ['label' => 'Active MRR', 'metric' => $perf['current_active_mrr'] ?? []],
    ],
    'providers' => $providers,
    'membershipTrend' => is_array($membership['trend'] ?? null) ? $membership['trend'] : [],
    'revenueMix' => [
        ['label' => 'Services', 'value' => (float)($mix['service'] ?? 0), 'available' => !empty($mix['available_types']['service'])],
        ['label' => 'Products', 'value' => (float)($mix['product'] ?? 0), 'available' => !empty($mix['available_types']['product'])],
        ['label' => 'Memberships', 'value' => (float)($mix['membership'] ?? 0), 'available' => !empty($mix['available_types']['membership'])],
        ['label' => 'Packages', 'value' => (float)($mix['package'] ?? 0), 'available' => !empty($mix['available_types']['package'])],
        ['label' => 'Tips', 'value' => (float)($mix['gratuity'] ?? ($sales['tips']['value'] ?? 0)), 'available' => !empty($sales['tips']['available'])],
    ],
    'daily' => $daily,
    'period' => $period,
];

$comparison = is_array($comparisonResult['comparison'] ?? null)
    ? $comparisonResult['comparison']
    : null;
$comparePanelOpen = $comparison !== null;
$comparisonBusinessId = (int)($meta['comparison_business_id'] ?? $meta['aesthetic_business_id'] ?? 0);
?>

<link rel="stylesheet" href="<?= asset('css/ruma-boulevard-intelligence.css') ?>?v=<?= $h(app_config('version')) ?>">

<div class="ruma-intel-page">
    <header class="ruma-intel-header ruma-glass-panel">
        <div class="ruma-header-copy">
            <p class="ruma-eyebrow">Ruma · Boulevard live analytics</p>
            <h1>Priority Intelligence Dashboard</h1>
            <p>
                Complete live Boulevard data for
                <strong><?= $h($period['start'] ?? '') ?> → <?= $h($period['end'] ?? '') ?></strong>,
                enriched with the existing Boulevard reporting pipeline when required.
            </p>
            <?php if (!empty($meta['dataset_counts'])): ?>
                <div class="ruma-dataset-strip">
                    <?php foreach ($meta['dataset_counts'] as $dataset => $count): ?>
                        <span><strong><?= number_format((int)$count) ?></strong><?= $h(ucwords(str_replace('_', ' ', $dataset))) ?></span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="ruma-header-actions no-print">
            <a class="ruma-btn ruma-btn-secondary" href="<?= $h(url('boulevard-live-console')) ?>">← Live API Console</a>
            <button class="ruma-btn ruma-btn-secondary" type="button" id="boulevardCompareToggle">Compare with PDF Upload Data</button>
            <button class="ruma-btn ruma-btn-primary" type="button" onclick="window.print()">Print / Save PDF</button>
        </div>
    </header>

    <section
        class="ruma-compare-panel no-print"
        id="boulevardPdfComparePanel"
        <?= $comparePanelOpen ? '' : 'hidden' ?>
    >
        <div class="ruma-compare-panel-head">
            <div>
                <p class="ruma-eyebrow">Source verification</p>
                <h2>Compare Live API with Uploaded Boulevard Data</h2>
                <p>
                    Only completed uploaded/report data for the exact live API period
                    <strong><?= $h($period['start'] ?? '') ?> → <?= $h($period['end'] ?? '') ?></strong>
                    is eligible. This prevents a misleading period mismatch.
                </p>
            </div>
            <button type="button" class="ruma-icon-btn" id="boulevardCompareClose" aria-label="Close comparison panel">×</button>
        </div>

        <?php if ($comparisonBatches): ?>
            <form method="post" action="<?= $h(url('boulevard-ruma-intelligence-compare')) ?>" class="ruma-compare-form">
                <?= csrf_field() ?>
                <label>
                    <span>Exact-period uploaded Boulevard report</span>
                    <select name="batch_id" required>
                        <?php foreach ($comparisonBatches as $batch): ?>
                            <option value="<?= (int)$batch['id'] ?>" <?= (int)($comparisonResult['batch_id'] ?? 0) === (int)$batch['id'] ? 'selected' : '' ?>>
                                #<?= (int)$batch['id'] ?> · <?= $h(ucfirst((string)$batch['frequency'])) ?> ·
                                <?= $h((string)$batch['validation_status']) ?> ·
                                <?= $h((string)$batch['created_at']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <div class="ruma-compare-note">
                    <strong>Comparison source:</strong>
                    the normalized dashboard generated by your existing Boulevard upload/report workflow.
                    Live API values are never overwritten by this comparison.
                </div>
                <button class="ruma-btn ruma-btn-primary" type="submit">Run API vs Upload Comparison</button>
            </form>
        <?php else: ?>
            <div class="ruma-empty-state">
                <strong>No exact-period Boulevard upload is available yet.</strong>
                <p>Upload/process the Boulevard source reports for the same dates, then return here and run the comparison.</p>
                <a class="ruma-btn ruma-btn-primary" href="<?= $h(url('business-upload', ['business_id' => $comparisonBusinessId])) ?>">Open Boulevard Uploads</a>
            </div>
        <?php endif; ?>
    </section>

    <?php if ($comparison): ?>
        <section class="ruma-section ruma-comparison-result no-print" id="boulevardComparisonResult">
            <div class="ruma-section-title">
                <span>✓</span>
                <div>
                    <h2>API vs Uploaded Data Verification</h2>
                    <p>Exact-period verification of dashboard KPIs and provider-level metrics.</p>
                </div>
                <span class="ruma-verify-badge is-<?= $h($comparison['overall_status']) ?>">
                    <?= $comparison['overall_status'] === 'verified' ? '✓ VERIFIED' : ($comparison['overall_status'] === 'review' ? '⚠ REVIEW' : 'INCOMPLETE') ?>
                </span>
            </div>

            <div class="ruma-compare-summary-grid">
                <article><span>Comparable metrics</span><strong><?= number_format((int)$comparison['comparable_metrics']) ?></strong></article>
                <article><span>Matched</span><strong><?= number_format((int)$comparison['matched_metrics']) ?></strong></article>
                <article><span>Needs review</span><strong><?= number_format((int)$comparison['review_metrics']) ?></strong></article>
                <article><span>Match rate</span><strong><?= number_format((float)$comparison['match_percent'], 1) ?>%</strong></article>
            </div>

            <div class="ruma-table-wrap ruma-comparison-table-wrap">
                <table class="ruma-table ruma-comparison-table">
                    <thead>
                    <tr>
                        <th>Metric</th>
                        <th>Live API</th>
                        <th>Uploaded data</th>
                        <th>Difference</th>
                        <th>Status</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($comparison['metrics'] as $row): ?>
                        <tr>
                            <td><strong><?= $h($row['label']) ?></strong></td>
                            <td><?= $h($fmtRaw($row['api_value'], $row['format'])) ?></td>
                            <td><?= $h($fmtRaw($row['upload_value'], $row['format'])) ?></td>
                            <td>
                                <?php if ($row['comparable']): ?>
                                    <?= $h($fmtRaw($row['difference'], $row['format'])) ?>
                                    <?php if ($row['difference_percent'] !== null && $row['format'] !== 'percent'): ?>
                                        <small><?= ($row['difference_percent'] >= 0 ? '+' : '') . number_format((float)$row['difference_percent'], 2) ?>%</small>
                                    <?php endif; ?>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                            <td><span class="ruma-compare-status status-<?= $h($row['status']) ?>"><?php
                                echo match ($row['status']) {
                                    'matched' => '✓ Match',
                                    'review' => '⚠ Review',
                                    'api_unavailable' => 'API unavailable',
                                    'upload_unavailable' => 'Not in upload',
                                    default => 'Incomplete',
                                };
                            ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if (!empty($comparison['providers'])): ?>
                <div class="ruma-provider-compare-list">
                    <h3>Provider comparison</h3>
                    <?php foreach ($comparison['providers'] as $provider): ?>
                        <details class="ruma-provider-compare" <?= $provider['status'] === 'review' ? 'open' : '' ?>>
                            <summary>
                                <strong><?= $h($provider['provider']) ?></strong>
                                <span class="ruma-compare-status status-<?= $h($provider['status']) ?>">
                                    <?= $provider['status'] === 'matched' ? '✓ Match' : ($provider['status'] === 'review' ? '⚠ Review' : 'Incomplete') ?>
                                </span>
                            </summary>
                            <div class="ruma-provider-compare-grid">
                                <?php foreach ($provider['metrics'] as $row): ?>
                                    <div>
                                        <span><?= $h($row['label']) ?></span>
                                        <strong><?= $h($fmtRaw($row['api_value'], $row['format'])) ?> <em>API</em></strong>
                                        <strong><?= $h($fmtRaw($row['upload_value'], $row['format'])) ?> <em>Upload</em></strong>
                                        <small class="status-<?= $h($row['status']) ?>"><?= $row['status'] === 'matched' ? 'Matched' : ($row['status'] === 'review' ? 'Review' : 'Unavailable') ?></small>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </details>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <section class="ruma-section">
        <div class="ruma-section-title">
            <span>01</span>
            <div><h2>Performance comparison</h2><p>Business health plus percentage movement versus the immediately previous equivalent period.</p></div>
        </div>
        <div class="ruma-kpi-grid">
            <?php $metricCard('Total revenue', $perf['total_revenue'] ?? [], '$'); ?>
            <?php $metricCard('Total appointments', $perf['total_appointments'] ?? [], '↗'); ?>
            <?php $metricCard('New clients', $perf['new_clients'] ?? [], '+'); ?>
            <?php $metricCard('Blended utilization', $perf['blended_utilization'] ?? [], '%'); ?>
            <?php $metricCard('Current active MRR', $perf['current_active_mrr'] ?? [], 'M'); ?>
        </div>
        <div class="ruma-chart-card ruma-chart-card-featured">
            <div class="ruma-chart-heading">
                <div><h3>Performance movement vs previous period</h3><p>All bars use the same percentage-change scale for an apples-to-apples comparison.</p></div>
                <span class="ruma-chart-chip">Change %</span>
            </div>
            <div class="ruma-chart-canvas-wrap"><canvas id="rumaPerformanceChart"></canvas></div>
        </div>
    </section>

    <section class="ruma-section">
        <div class="ruma-section-title">
            <span>02</span>
            <div><h2>Provider performance</h2><p>Realized service revenue and utilization shown together with labeled provider axes.</p></div>
        </div>
        <div class="ruma-chart-card ruma-chart-card-featured">
            <div class="ruma-chart-heading">
                <div><h3>Service revenue vs utilization</h3><p>Bars represent provider revenue; the line represents utilization percentage.</p></div>
                <span class="ruma-chart-chip">Provider view</span>
            </div>
            <div class="ruma-chart-scroll"><div class="ruma-chart-provider-width" data-provider-chart-width><canvas id="rumaProviderPerformanceChart"></canvas></div></div>
        </div>
    </section>

    <section class="ruma-section">
        <div class="ruma-section-title"><span>03</span><div><h2>Revenue per scheduled hour and retail sales</h2><p>Provider productivity and retail attachment on a common currency scale.</p></div></div>
        <div class="ruma-chart-card">
            <div class="ruma-chart-heading"><div><h3>Provider productivity</h3><p>Revenue per scheduled hour compared with retail sales attributed to each provider.</p></div><span class="ruma-chart-chip">USD</span></div>
            <div class="ruma-chart-scroll"><div class="ruma-chart-provider-height" data-provider-chart-height><canvas id="rumaProviderProductivityChart"></canvas></div></div>
        </div>
        <div class="ruma-table-wrap ruma-table-card">
            <table class="ruma-table">
                <thead><tr><th>Provider</th><th>Scheduled hours</th><th>Service revenue/hour</th><th>Retail sales</th></tr></thead>
                <tbody>
                <?php foreach ($providers as $p): ?>
                    <tr>
                        <td><strong><?= $h($p['provider']) ?></strong></td>
                        <td><?= !empty($p['utilization_available']) ? number_format((float)$p['scheduled_hours'], 1) : '—' ?></td>
                        <td><?= !empty($p['revenue_per_hour_available']) ? '$'.number_format((float)$p['revenue_per_hour'], 2) : '—' ?></td>
                        <td>$<?= number_format((float)($p['retail_sales'] ?? 0), 2) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="ruma-section">
        <div class="ruma-section-title"><span>04</span><div><h2>Membership and recurring revenue</h2><p>Current MRR, ARR, active membership base and recurring-revenue trend.</p></div></div>
        <div class="ruma-kpi-grid ruma-kpi-grid-4">
            <?php $metricCard('Current active MRR', $membership['current_active_mrr'] ?? [], 'M'); ?>
            <?php $metricCard('Change since previous period', $membership['change_since_previous_period'] ?? [], 'Δ'); ?>
            <?php $metricCard('Current ARR', $membership['current_arr'] ?? [], 'A'); ?>
            <?php $metricCard('Active memberships', $membership['active_memberships'] ?? [], '#'); ?>
        </div>
        <div class="ruma-chart-card">
            <div class="ruma-chart-heading"><div><h3>Membership recurring revenue trend</h3><p>MRR in USD across every period supplied by the live/report-enrichment layer.</p></div><span class="ruma-chart-chip">MRR</span></div>
            <div class="ruma-chart-canvas-wrap"><canvas id="rumaMrrChart"></canvas></div>
        </div>
    </section>

    <section class="ruma-section">
        <div class="ruma-section-title"><span>05</span><div><h2>Boulevard sales summary</h2><p>Core appointment and revenue-source outcomes.</p></div></div>
        <div class="ruma-kpi-grid ruma-kpi-grid-4">
            <?php $metricCard('Total Revenue', $sales['total_revenue'] ?? [], '$'); ?>
            <?php $metricCard('Total appointments', $sales['total_appointments'] ?? [], '#'); ?>
            <?php $metricCard('Requested appointments', $sales['requested_appointments'] ?? [], 'R'); ?>
            <?php $metricCard('Service revenue', $sales['service_revenue'] ?? [], 'S'); ?>
            <?php $metricCard('All product revenue', $sales['product_revenue'] ?? [], 'P'); ?>
            <?php $metricCard('Membership revenue', $sales['membership_revenue'] ?? [], 'M'); ?>
            <?php $metricCard('Package revenue', $sales['package_revenue'] ?? [], 'K'); ?>
            <?php $metricCard('Tips', $sales['tips'] ?? [], 'T'); ?>
        </div>
    </section>

    <section class="ruma-section">
        <div class="ruma-section-title"><span>06</span><div><h2>Financial detail and sales quality</h2><p>Revenue quality, refunds and payment-related totals.</p></div></div>
        <div class="ruma-kpi-grid ruma-kpi-grid-4">
            <?php $metricCard('Net Sales', $fin['net_sales'] ?? [], '$'); ?>
            <?php $metricCard('Gross payments', $fin['gross_payments'] ?? [], 'G'); ?>
            <?php $metricCard('Refunds', $fin['refunds'] ?? [], '↩'); ?>
            <?php $metricCard('Gift cards sold', $fin['gift_cards_sold'] ?? [], 'G'); ?>
            <?php $metricCard('Account credit sold', $fin['account_credit_sold'] ?? [], 'C'); ?>
            <?php $metricCard('Tax collected', $fin['tax_collected'] ?? [], 'T'); ?>
            <?php $metricCard('Card fees', $fin['card_fees'] ?? [], 'F'); ?>
        </div>
    </section>

    <section class="ruma-section">
        <div class="ruma-section-title"><span>07</span><div><h2>Revenue mix and daily performance</h2><p>Revenue composition plus every day in the selected reporting range.</p></div></div>
        <div class="ruma-two-col">
            <div class="ruma-chart-card">
                <div class="ruma-chart-heading"><div><h3>Revenue mix</h3><p>Share of revenue by major Boulevard sales category.</p></div><span class="ruma-chart-chip">Mix</span></div>
                <div class="ruma-chart-canvas-wrap"><canvas id="rumaRevenueMixChart"></canvas></div>
            </div>
            <div class="ruma-chart-card">
                <div class="ruma-chart-heading"><div><h3>Daily revenue and appointments</h3><p>Revenue uses the left axis; appointment count uses the right axis.</p></div><span class="ruma-chart-chip">Daily</span></div>
                <div class="ruma-chart-scroll"><div class="ruma-chart-daily-width" data-daily-chart-width><canvas id="rumaDailyPerformanceChart"></canvas></div></div>
            </div>
        </div>
    </section>

    <section class="ruma-section">
        <div class="ruma-section-title"><span>08</span><div><h2>Provider details</h2><p>Provider-level revenue, capacity and client activity.</p></div></div>
        <div class="ruma-table-wrap ruma-table-card">
            <table class="ruma-table">
                <thead><tr><th>Provider</th><th>Service revenue</th><th>Utilization</th><th>Scheduled hours</th><th>Appointments</th><th>New clients</th><th>Revenue/hour</th></tr></thead>
                <tbody>
                <?php foreach ($providers as $p): ?>
                    <tr>
                        <td><strong><?= $h($p['provider']) ?></strong></td>
                        <td><?= !empty($p['service_revenue_available']) ? '$'.number_format((float)$p['service_revenue'], 2) : '<span class="muted">Booked $'.number_format((float)$p['booked_service_value'], 2).'</span>' ?></td>
                        <td><?= !empty($p['utilization_available']) ? number_format((float)$p['utilization'] * 100, 1).'%' : '—' ?></td>
                        <td><?= !empty($p['utilization_available']) ? number_format((float)$p['scheduled_hours'], 1) : '—' ?></td>
                        <td><?= number_format((int)($p['appointments'] ?? 0)) ?></td>
                        <td><?= !empty($p['new_clients_available']) ? number_format((int)$p['new_clients']) : '—' ?></td>
                        <td><?= !empty($p['revenue_per_hour_available']) ? '$'.number_format((float)$p['revenue_per_hour'], 2) : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="ruma-section">
        <div class="ruma-section-title"><span>09</span><div><h2>Business insights</h2><p>Rules-based findings ordered by business priority and urgency.</p></div></div>
        <div class="ruma-insight-grid">
            <?php foreach ($insights as $i): ?>
                <article class="ruma-insight priority-<?= strtolower($h($i['priority'])) ?>">
                    <div class="ruma-insight-meta"><span><?= $h($i['priority']) ?> priority</span><span><?= $h($i['method']) ?></span></div>
                    <h3><?= $h($i['title']) ?></h3>
                    <p><?= $h($i['observation']) ?></p>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="ruma-section no-print">
        <div class="ruma-section-title"><span>✓</span><div><h2>Data coverage</h2><p>Direct API and report-enrichment coverage for each analytics area.</p></div></div>
        <div class="ruma-table-wrap ruma-table-card">
            <table class="ruma-table">
                <thead><tr><th>Area</th><th>Status</th><th>Requirement / source</th></tr></thead>
                <tbody>
                <?php foreach ($coverage as $c): ?>
                    <tr>
                        <td><?= $h($c['area']) ?></td>
                        <td><span class="status-pill <?= !empty($c['available']) ? 'ok' : 'missing' ?>"><?= !empty($c['available']) ? 'Available' : 'Needs enrichment' ?></span></td>
                        <td><?= $h($c['note']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<script type="application/json" id="rumaBoulevardChartData"><?= json_encode(
    $chartPayload,
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES
) ?></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js"></script>
