<?php
$adminParams = [];
$businessId = (int)business_context_id();
$features = business_feature_effective_states($businessId);

$boulevardEnabled = !empty($features['boulevard']);
$boulevardApiEnabled = $boulevardEnabled && !empty($features['boulevard_api']);
$gbpEnabled = !empty($features['gbp']);
$podiumEnabled = !empty($features['podium']);
$growth99Enabled = !empty($features['growth99']);
$ga4Enabled = !empty($features['ga4']);
$providerKpiShow = !empty($features['provider_kpi']) && provider_kpi_navigation_visible($businessId);
$aiWeeklyEnabled = !empty($features['ai_weekly_report']);

$autoBoulevard = $boulevardApiEnabled && !empty($boulevardUserAccess['enabled']);
$boulevardActionUrl = (!$autoBoulevard || auth_is_admin())
    ? url('business-upload', $adminParams)
    : url('business-boulevard-run');
$boulevardActionLabel = (!$autoBoulevard || auth_is_admin()) ? 'Add data' : 'Run weekly report';

$hasDashboardFeatures =
    $boulevardEnabled || $gbpEnabled || $podiumEnabled || $growth99Enabled
    || $ga4Enabled || $providerKpiShow || $aiWeeklyEnabled;

$latestValidationStatus = $latest ? (string)($latest['validation_status'] ?? 'validated') : null;
$latestAllowed = $latest && report_validation_is_allowed($latestValidationStatus);
$dashboard = $latestAllowed ? (json_decode((string)$latest['dashboard_json'], true) ?: []) : [];
$dashboardKpis = is_array($dashboard['kpis'] ?? null) ? $dashboard['kpis'] : [];
$providers = is_array($dashboard['providers'] ?? null) ? $dashboard['providers'] : [];

$metricTone = static function (?array $metric): string {
    $tone = (string)($metric['sentiment'] ?? 'neutral');
    return in_array($tone, ['positive', 'negative', 'neutral'], true) ? $tone : 'neutral';
};
$metricChange = static function (?array $metric): string {
    if (!$metric) return 'No previous-period data';
    $text = trim((string)change_text($metric));
    return $text !== '' ? $text : 'No previous-period data';
};

$businessMetricRows = [];
foreach ([
    'total_revenue' => 'Total revenue',
    'appointments' => 'Appointments',
    'requested_appointments' => 'Requested appointments',
    'service_revenue' => 'Service revenue',
    'product_revenue' => 'Product revenue',
    'membership_revenue' => 'Membership revenue',
    'retail_revenue' => 'Retail revenue',
    'retail_units' => 'Retail units sold',
] as $key => $label) {
    if (!isset($dashboardKpis[$key])) continue;
    $businessMetricRows[] = [
        'label' => $label,
        'value' => metric_display($dashboardKpis[$key]),
        'change' => $metricChange($dashboardKpis[$key]),
        'tone' => $metricTone($dashboardKpis[$key]),
    ];
}

$weeklyDashboard = [];
if ($aiWeeklyEnabled && !empty($latestAiWeeklyReport)) {
    $weeklyDashboard = ai_weekly_report_decode($latestAiWeeklyReport);
    if (!is_array($weeklyDashboard)) $weeklyDashboard = [];
}

$liveSources = [];
if ($boulevardApiEnabled) $liveSources[] = 'boulevard';
if ($ga4Enabled) $liveSources[] = 'ga4';
if ($gbpEnabled) $liveSources[] = 'gbp';
$liveSourceJson = json_encode($liveSources, JSON_UNESCAPED_SLASHES) ?: '[]';
?>

<div
    class="ai-live-dashboard"
    data-live-dashboard
    data-live-endpoint="<?=e(url('business-dashboard-live-data'))?>"
    data-live-csrf="<?=e(csrf_token())?>"
    data-live-sources='<?=e($liveSourceJson)?>'
>
    <div class="page-head ai-focus-page-head ai-live-page-head">
        <div>
            <span class="eyebrow"><?=e($business['name'])?></span>
            <div class="ai-live-title-row">
                <h1>Live Performance Intelligence</h1>
                <span class="ai-live-badge"><i></i> Live API</span>
            </div>
            <p>Fresh connected-source data loads directly from GA4, Google Business Profile and Boulevard. Historical reports remain available below for deeper investigation.</p>
        </div>
        <div class="ai-focus-head-actions ai-live-head-actions">
            <div class="ai-live-period-filter">
                <label for="ai-live-period-select">View</label>
                <select id="ai-live-period-select" data-live-period-select aria-label="Dashboard reporting period">
                    <option value="weekly">Weekly · last 7 completed days</option>
                    <option value="mtd">Monthly · MTD</option>
                    <option value="ytd">Yearly · YTD</option>
                </select>
                <div class="ai-live-period-context" aria-live="polite">
                    <strong data-live-period-title>Weekly view</strong>
                    <small data-live-period-summary>Last 7 completed days vs previous 7 days</small>
                </div>
            </div>
            <button class="btn btn-secondary" type="button" data-live-refresh-all>
                <span data-refresh-label>Refresh live data</span>
            </button>
            <a class="btn btn-secondary" href="<?=url('business-history', $adminParams)?>">Reports &amp; history</a>
        </div>
    </div>

    <div class="ai-live-freshness-strip" aria-live="polite">
        <?php foreach ([
            ['boulevard', 'Boulevard', $boulevardApiEnabled],
            ['ga4', 'GA4', $ga4Enabled],
            ['gbp', 'Google Business Profile', $gbpEnabled],
        ] as [$sourceKey, $sourceLabel, $enabled]): ?>
            <div class="ai-live-source-state <?= $enabled ? 'is-loading' : 'is-disabled' ?>" data-live-source-state="<?=e($sourceKey)?>">
                <span class="ai-live-source-dot"></span>
                <div>
                    <strong><?=e($sourceLabel)?></strong>
                    <small data-live-source-message><?= $enabled ? 'Fetching fresh API data…' : 'Not enabled' ?></small>
                </div>
                <?php if ($enabled): ?><span class="ai-live-mini-spinner" aria-hidden="true"></span><?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if (!$liveSources): ?>
        <section class="ai-live-no-sources">
            <div><strong>No live API sources are enabled for this business.</strong><p>Enable GA4, GBP or Boulevard API to populate the live executive layer. Existing reports and tools are preserved in In-depth analysis.</p></div>
            <?php if (auth_is_admin()): ?><a class="btn btn-primary" href="<?=url('admin-business-form', ['id' => $businessId])?>">Manage feature controls</a><?php endif; ?>
        </section>
    <?php endif; ?>

    <section class="ai-snapshot-section" aria-labelledby="live-overview-heading">
        <header class="ai-snapshot-section-head">
            <span class="ai-snapshot-number">1</span>
            <div><h2 id="live-overview-heading">Live performance overview</h2><p><span data-live-period-copy>Weekly view</span> uses fresh API-backed KPIs only. Failed sources stay clearly unavailable rather than showing old data as current.</p></div>
        </header>
        <div class="ai-snapshot-kpi-grid ai-live-kpi-grid">
            <?php foreach ([
                ['boulevard','revenue','Total revenue','Boulevard'],
                ['boulevard','appointments','Appointments','Boulevard'],
                ['ga4','sessions','Website sessions','GA4'],
                ['ga4','activeUsers','Active users','GA4'],
                ['gbp','actions','GBP actions','Google Business Profile'],
                ['gbp','website_clicks','Website clicks','Google Business Profile'],
            ] as [$source, $key, $label, $sourceName]): ?>
                <?php $enabled = in_array($source, $liveSources, true); ?>
                <article class="ai-snapshot-kpi ai-live-kpi <?= $enabled ? 'is-loading' : 'is-disabled' ?>" data-live-metric="<?=e($source . ':' . $key)?>">
                    <small<?= $source === 'boulevard' && $key === 'revenue' ? ' data-period-revenue-label' : '' ?>><?=e($label)?></small>
                    <strong data-live-value><?= $enabled ? '<span class="ai-skeleton ai-skeleton-value"></span>' : '—' ?></strong>
                    <span class="ai-snapshot-trend is-neutral" data-live-change><?= $enabled ? 'Loading fresh data…' : 'Source not enabled' ?></span>
                    <em><?=e($sourceName)?> · <span data-live-period>Live</span></em>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="ai-snapshot-section" aria-labelledby="live-marketing-heading">
        <header class="ai-snapshot-section-head">
            <span class="ai-snapshot-number">2</span>
            <div><h2 id="live-marketing-heading">Marketing &amp; visibility</h2><p>Fresh source summaries without exposing the full API payload.</p></div>
        </header>
        <div class="ai-focus-three-column">
            <article class="ai-focus-source-summary ai-live-source-card" data-live-source-card="ga4">
                <div class="ai-focus-source-head"><div><span class="ai-focus-source-kicker">Website / GA4</span><h3>Website demand</h3></div><span class="ai-focus-source-status" data-card-status><?= $ga4Enabled ? 'Loading' : 'Not enabled' ?></span></div>
                <div class="ai-focus-mini-metrics">
                    <?php foreach ([['sessions','Sessions'],['newUsers','New users'],['engagementRate','Engagement'],['keyEvents','Key events']] as [$key,$label]): ?>
                        <div><span><?=e($label)?></span><strong data-live-inline="ga4:<?=e($key)?>"><?= $ga4Enabled ? '<span class="ai-skeleton ai-skeleton-small"></span>' : '—' ?></strong></div>
                    <?php endforeach; ?>
                </div>
                <p class="ai-focus-source-note" data-source-note><?= $ga4Enabled ? 'Contacting Google Analytics…' : 'Enable GA4 to show live website data.' ?></p>
                <div class="ai-focus-source-links"><a href="<?=url('business-ga4-api-data')?>">Open GA4 analysis</a><a href="<?=url('business-ai-extraction', ['source' => 'ga4'])?>">PDF upload</a></div>
            </article>

            <article class="ai-focus-source-summary ai-live-source-card" data-live-source-card="gbp">
                <div class="ai-focus-source-head"><div><span class="ai-focus-source-kicker">Google Business Profile</span><h3>Local visibility</h3></div><span class="ai-focus-source-status" data-card-status><?= $gbpEnabled ? 'Loading' : 'Not enabled' ?></span></div>
                <div class="ai-focus-mini-metrics">
                    <?php foreach ([['website_clicks','Website clicks'],['call_clicks','Calls'],['direction_requests','Directions'],['search_impressions','Search views']] as [$key,$label]): ?>
                        <div><span><?=e($label)?></span><strong data-live-inline="gbp:<?=e($key)?>"><?= $gbpEnabled ? '<span class="ai-skeleton ai-skeleton-small"></span>' : '—' ?></strong></div>
                    <?php endforeach; ?>
                </div>
                <p class="ai-focus-source-note" data-source-note><?= $gbpEnabled ? 'Refreshing Google Business Profile…' : 'Enable GBP to show live local visibility.' ?></p>
                <div class="ai-focus-source-links"><a href="<?=url('business-google')?>">Google connections</a><?php if ($gbpEnabled): ?><a href="<?=url('business-gbp')?>">GBP details</a><?php endif; ?></div>
            </article>

            <article class="ai-focus-source-summary ai-live-source-card" data-live-source-card="boulevard">
                <div class="ai-focus-source-head"><div><span class="ai-focus-source-kicker">Boulevard</span><h3>Business pulse</h3></div><span class="ai-focus-source-status" data-card-status><?= $boulevardApiEnabled ? 'Loading' : 'Not enabled' ?></span></div>
                <div class="ai-focus-mini-metrics">
                    <?php foreach ([['revenue','Revenue'],['orders','Closed orders'],['service_bookings','Service bookings'],['cancellation_rate','Cancellation']] as [$key,$label]): ?>
                        <div><span><?=e($label)?></span><strong data-live-inline="boulevard:<?=e($key)?>"><?= $boulevardApiEnabled ? '<span class="ai-skeleton ai-skeleton-small"></span>' : '—' ?></strong></div>
                    <?php endforeach; ?>
                </div>
                <p class="ai-focus-source-note" data-source-note><?= $boulevardApiEnabled ? 'Contacting Boulevard…' : 'Enable Boulevard API to show live business data.' ?></p>
                <div class="ai-focus-source-links"><?php if ($boulevardEnabled): ?><a href="<?=e($boulevardActionUrl)?>"><?=e($boulevardActionLabel)?></a><?php endif; ?><?php if (auth_is_admin() && $boulevardApiEnabled): ?><a href="<?=url('boulevard-live-console')?>">API console</a><?php endif; ?></div>
            </article>
        </div>
    </section>

    <section class="ai-live-decision-grid">
        <section class="ai-snapshot-section ai-snapshot-section-fill" aria-labelledby="live-business-heading">
            <header class="ai-snapshot-section-head"><span class="ai-snapshot-number">3</span><div><h2 id="live-business-heading">Revenue &amp; business performance</h2><p><span data-live-revenue-basis>Weekly revenue is summed across the latest seven completed business days.</span> Live Boulevard operating metrics use the selected reporting location.</p></div></header>
            <div class="ai-focus-metric-list ai-live-business-list" data-live-business-list>
                <?php foreach ([['revenue','Total revenue'],['appointments','Appointments'],['orders','Closed orders'],['service_bookings','Service bookings'],['refunds','Refunds'],['cancellation_rate','Cancellation rate']] as [$key,$label]): ?>
                    <div data-live-row="boulevard:<?=e($key)?>"><span<?= $key === 'revenue' ? ' data-period-revenue-label' : '' ?>><?=e($label)?></span><strong><?= $boulevardApiEnabled ? '<span class="ai-skeleton ai-skeleton-small"></span>' : '—' ?></strong><em class="is-neutral"><?= $boulevardApiEnabled ? 'Loading…' : 'Not enabled' ?></em></div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="ai-snapshot-section ai-snapshot-section-fill" aria-labelledby="live-insights-heading">
            <header class="ai-snapshot-section-head"><span class="ai-snapshot-number">4</span><div><h2 id="live-insights-heading">What needs attention</h2><p>Automatically prioritized from the fresh <span data-live-comparison-copy>weekly vs prior-week</span> API changes.</p></div></header>
            <div class="ai-live-insights" data-live-insights>
                <div class="ai-live-insight-loading"><span class="ai-live-spinner"></span><div><strong>Building live insights</strong><small>Waiting for connected sources to finish.</small></div></div>
            </div>
        </section>
    </section>

    <?php if ($weeklyDashboard): ?>
        <section class="ai-focus-brief ai-focus-brief-ai ai-live-weekly-brief">
            <div class="ai-focus-brief-mark">AI</div>
            <div class="ai-focus-brief-copy"><span>Published weekly context</span><h2><?=e((string)($weeklyDashboard['report_title'] ?? 'AI Weekly Report'))?></h2><p>This report is preserved as published context. It is intentionally separate from the live API snapshot above.</p></div>
            <a class="btn btn-primary" href="<?=url('business-ai-weekly-report', ['id' => (int)$latestAiWeeklyReport['id']])?>">Open weekly report</a>
        </section>
    <?php endif; ?>
</div>

<details class="ai-deep-analysis" id="in-depth-analysis">
    <summary>
        <div>
            <span>In-depth analysis</span>
            <strong>Open source tools, validation states, full embedded reports and recent history</strong>
        </div>
        <span class="ai-deep-analysis-action">Show details</span>
    </summary>

    <div class="ai-deep-analysis-body">
        <?php if ($latestAllowed && ($businessMetricRows || $providers)): ?>
            <section class="ai-focus-historical-grid">
                <article class="panel">
                    <div class="panel-head">
                        <div><span class="eyebrow">Stored report snapshot</span><h2>Revenue &amp; business metrics</h2><p class="muted">Historical/validated reporting data is preserved here for comparison and audit context.</p></div>
                    </div>
                    <?php if ($businessMetricRows): ?>
                        <div class="ai-focus-metric-list">
                            <?php foreach ($businessMetricRows as $row): ?>
                                <div><span><?=e($row['label'])?></span><strong><?=e($row['value'])?></strong><em class="is-<?=e($row['tone'])?>"><?=e($row['change'])?></em></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </article>
                <article class="panel">
                    <div class="panel-head">
                        <div><span class="eyebrow">Stored provider snapshot</span><h2>Provider performance</h2><p class="muted">The last validated provider rollup remains available without being presented as live API data.</p></div>
                    </div>
                    <?php if ($providers): ?>
                        <div class="ai-focus-provider-list">
                            <?php foreach (array_slice($providers, 0, 8) as $index => $provider): ?>
                                <div><span class="ai-focus-rank"><?=e((string)($index + 1))?></span><div><strong><?=e((string)$provider['name'])?></strong><span><?=number_format((float)($provider['utilization'] ?? 0), 1)?>% utilization</span></div><b><?=money((float)($provider['service_revenue'] ?? 0))?></b></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </article>
            </section>
        <?php endif; ?>
        <?php if ($hasDashboardFeatures): ?>
            <section>
                <div class="panel-head dashboard-section-head">
                    <div>
                        <span class="eyebrow">Data &amp; integrations</span>
                        <h2>Connected sources</h2>
                        <p class="muted">The original upload, API, history and feature-control workflows are preserved here.</p>
                    </div>
                </div>

                <div class="source-grid source-grid-premium ai-source-grid">
                    <?php if ($boulevardEnabled): ?>
                        <article class="source-card">
                            <div class="source-main"><div class="source-icon source-boulevard">B</div><div><h3>Boulevard</h3><p>Revenue, appointments, memberships, retail and provider performance.</p></div></div>
                            <div class="source-actions"><a class="btn btn-primary" href="<?=e($boulevardActionUrl)?>"><?=e($boulevardActionLabel)?></a><a class="btn btn-secondary" href="<?=url('business-history', $adminParams)?>">History</a></div>
                        </article>
                    <?php endif; ?>

                    <?php if ($gbpEnabled): ?>
                        <article class="source-card">
                            <div class="source-main"><div class="source-icon source-gbp">G</div><div><h3>Google Business Profile</h3><p>Calls, directions, website clicks, interactions and reviews.</p></div></div>
                            <div class="source-actions"><a class="btn btn-primary" href="<?=url('business-gbp', $adminParams)?>">Open GBP</a><a class="btn btn-secondary" href="<?=url('business-google')?>">Connection</a></div>
                        </article>
                    <?php endif; ?>

                    <?php if ($ga4Enabled): ?>
                        <article class="source-card">
                            <div class="source-main"><div class="source-icon source-ga4">G4</div><div><h3>Google Analytics 4</h3><p>Use the live API dashboard or the existing saved-PDF workflow.</p></div></div>
                            <div class="source-actions"><a class="btn btn-primary" href="<?=url('business-ga4-api-data')?>">API analysis</a><a class="btn btn-secondary" href="<?=url('business-ai-extraction', ['source' => 'ga4'])?>">PDF upload</a></div>
                        </article>
                    <?php endif; ?>

                    <?php if ($podiumEnabled): ?>
                        <article class="source-card"><div class="source-main"><div class="source-icon source-podium">P</div><div><h3>Podium</h3><p>Inbox and call-report extraction.</p></div></div><div class="source-actions"><a class="btn btn-primary" href="<?=url('business-ai-extraction', ['source' => 'podium'])?>">Open Podium</a></div></article>
                    <?php endif; ?>

                    <?php if ($growth99Enabled): ?>
                        <article class="source-card"><div class="source-main"><div class="source-icon source-growth">99</div><div><h3>Growth99+</h3><p>Lead, Cliffhanger and CallRail insight reports.</p></div></div><div class="source-actions"><a class="btn btn-primary" href="<?=url('business-ai-extraction', ['source' => 'growth99'])?>">Open Growth99+</a></div></article>
                    <?php endif; ?>

                    <?php if ($providerKpiShow): ?>
                        <article class="source-card source-card-provider-kpi"><div class="source-main"><div class="source-icon source-provider-kpi">KPI</div><div><h3>Provider KPI</h3><p>Provider scorecards, goals and clinic rollups.</p></div></div><div class="source-actions"><a class="btn btn-primary" href="<?=url('business-provider-kpi')?>">Open KPI</a></div></article>
                    <?php endif; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($boulevardEnabled): ?>
            <section class="panel feature-panel">
                <?php if ($latest): ?>
                    <?php if (!report_validation_is_allowed($latestValidationStatus)): ?>
                        <div><span class="validation-badge validation-danger">Review required</span><h2>Latest Boulevard report is being held safely</h2><p><?=e(reporting_us_date($latest['period_start']))?> - <?=e(reporting_us_date($latest['period_end']))?> is excluded from automatic comparisons until it is reviewed.</p></div>
                        <a class="btn btn-secondary" href="<?=url('business-report', ['id' => $latest['id']] + $adminParams)?>">Review report</a>
                    <?php else: ?>
                        <div><span class="status status-completed">Latest Boulevard report ready</span><h2><?=e(reporting_us_date($latest['period_start']))?> - <?=e(reporting_us_date($latest['period_end']))?></h2><p>The complete infographic, revenue mix, provider detail and original report intelligence are still available.</p></div>
                        <a class="btn btn-primary" href="<?=url('business-report', ['id' => $latest['id']] + $adminParams)?>">Open full report</a>
                    <?php endif; ?>
                <?php else: ?>
                    <div><span class="status status-draft">Boulevard ready</span><h2>No Boulevard report yet</h2><p>Add or run the first report to populate the business-performance snapshot.</p></div>
                    <a class="btn btn-primary" href="<?=e($boulevardActionUrl)?>"><?=e($boulevardActionLabel)?></a>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <?php if ($aiWeeklyEnabled && $weeklyDashboard): ?>
            <section class="panel ai-embedded-weekly-report">
                <div class="panel-head">
                    <div><span class="eyebrow">Embedded detail</span><h2>Full AI Weekly Report</h2><p class="muted">Preserved from the previous dashboard, but collapsed behind the in-depth layer.</p></div>
                    <a class="btn btn-secondary" href="<?=url('business-ai-weekly-report', ['id' => (int)$latestAiWeeklyReport['id']])?>">Open dedicated view</a>
                </div>
                <?php
                $report = $latestAiWeeklyReport;
                $dashboard = $weeklyDashboard;
                require VIEW_PATH . '/partials/ai-weekly-dashboard.php';
                ?>
            </section>
        <?php endif; ?>

        <?php if ($boulevardEnabled): ?>
            <section class="panel ai-history-panel">
                <div class="panel-head"><div><span class="eyebrow">Timeline</span><h2>Recent Boulevard reports</h2></div><a href="<?=url('business-history', $adminParams)?>">View all</a></div>
                <div class="history-cards">
                    <?php foreach ($history as $h): ?>
                        <a class="history-card" href="<?=$h['status'] === 'completed' ? url('business-report', ['id' => $h['id']] + $adminParams) : '#'?>">
                            <strong><?=e(reporting_us_date($h['period_start']))?> - <?=e(reporting_us_date($h['period_end']))?></strong>
                            <?php if ($h['status'] === 'completed'): $hm = report_validation_status_meta($h['validation_status'] ?? 'validated'); ?>
                                <span class="validation-badge validation-<?=e($hm['class'])?>"><?=e($hm['label'])?></span>
                            <?php else: ?>
                                <span class="status status-<?=e($h['status'])?>"><?=e(ucfirst($h['status']))?></span>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                    <?php if (!$history): ?><p class="muted">No Boulevard history yet.</p><?php endif; ?>
                </div>
            </section>
        <?php endif; ?>
    </div>
</details>

<?php if (!$hasDashboardFeatures): ?>
    <section class="empty-state">
        <div class="empty-icon">⚙</div>
        <h2>Workspace is ready</h2>
        <p>No optional business features are enabled right now. <?php if (auth_is_admin()): ?>Use Edit Business → Feature Controls to turn on only the tools this business needs.<?php else: ?>Ask your Super Admin to enable the tools your team uses.<?php endif; ?></p>
        <?php if (auth_is_admin()): ?><a class="btn btn-primary" href="<?=url('admin-business-form', ['id' => $businessId])?>">Manage feature controls</a><?php endif; ?>
    </section>
<?php endif; ?>
