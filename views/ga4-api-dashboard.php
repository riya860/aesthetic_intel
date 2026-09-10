<?php
$business = is_array($business ?? null) ? $business : [];
$connection = is_array($connection ?? null) ? $connection : null;
$dashboard = is_array($dashboard ?? null) ? $dashboard : [];
$metadata = is_array($metadata ?? null) ? $metadata : [
    'dimensions' => [],
    'metrics' => [],
    'error' => null,
];
$customReport = is_array($customReport ?? null) ? $customReport : null;

$periodStart = (string)($periodStart ?? '');
$periodEnd = (string)($periodEnd ?? '');
$currencyCode = (string)($currencyCode ?? 'USD');

if (!function_exists('ga4dash_h')) {
    function ga4dash_h(mixed $value): string
    {
        return htmlspecialchars(
            (string)$value,
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

if (!function_exists('ga4dash_label')) {
    function ga4dash_label(string $name): string
    {
        $labels = [
            'activeUsers' => 'Active users',
            'newUsers' => 'New users',
            'sessions' => 'Sessions',
            'engagedSessions' => 'Engaged sessions',
            'engagementRate' => 'Engagement rate',
            'bounceRate' => 'Bounce rate',
            'averageSessionDuration' => 'Avg. session duration',
            'screenPageViews' => 'Views',
            'eventCount' => 'Event count',
            'keyEvents' => 'Key events',
            'totalRevenue' => 'Total revenue',
            'userEngagementDuration' => 'Engagement duration',
            'eventCountPerActiveUser' => 'Events / active user',
            'itemsViewed' => 'Items viewed',
            'itemsAddedToCart' => 'Added to cart',
            'itemsPurchased' => 'Items purchased',
            'itemRevenue' => 'Item revenue',

            'date' => 'Date',
            'sessionDefaultChannelGroup' => 'Channel',
            'sessionSource' => 'Source',
            'sessionMedium' => 'Medium',
            'landingPagePlusQueryString' => 'Landing page',
            'pagePath' => 'Page path',
            'pageTitle' => 'Page title',
            'eventName' => 'Event',
            'deviceCategory' => 'Device',
            'browser' => 'Browser',
            'operatingSystem' => 'Operating system',
            'country' => 'Country',
            'city' => 'City',
            'firstUserDefaultChannelGroup' => 'First-user channel',
            'itemName' => 'Item',
            'itemCategory' => 'Item category',
        ];

        if (isset($labels[$name])) {
            return $labels[$name];
        }

        return ucwords(
            trim(
                preg_replace(
                    '/(?<!^)[A-Z]/',
                    ' $0',
                    $name
                )
            )
        );
    }
}

if (!function_exists('ga4dash_number')) {
    function ga4dash_number(
        string $metric,
        string|int|float|null $value,
        string $currencyCode = 'USD'
    ): string {
        $raw = (float)($value ?? 0);

        if (
            str_contains($metric, 'Rate')
            || in_array(
                $metric,
                ['engagementRate', 'bounceRate'],
                true
            )
        ) {
            return number_format(
                $raw * 100,
                1
            ) . '%';
        }

        if (
            $metric === 'averageSessionDuration'
            || $metric === 'userEngagementDuration'
        ) {
            $seconds = max(0, (int)round($raw));

            $hours = intdiv($seconds, 3600);
            $minutes = intdiv($seconds % 3600, 60);
            $seconds = $seconds % 60;

            return $hours > 0
                ? sprintf(
                    '%dh %02dm %02ds',
                    $hours,
                    $minutes,
                    $seconds
                )
                : sprintf(
                    '%dm %02ds',
                    $minutes,
                    $seconds
                );
        }

        if (
            str_contains(
                strtolower($metric),
                'revenue'
            )
        ) {
            return ga4dash_h($currencyCode)
                . ' '
                . number_format($raw, 2);
        }

        if (
            abs($raw - round($raw)) < 0.000001
        ) {
            return number_format((int)round($raw));
        }

        return number_format($raw, 2);
    }
}

if (!function_exists('ga4dash_overview_metric')) {
    function ga4dash_overview_metric(
        array $report,
        string $metric
    ): string {
        if (
            empty($report['ok'])
            || empty($report['rows'][0]['metrics'])
        ) {
            return '—';
        }

        return (string)(
            $report['rows'][0]['metrics'][$metric]
            ?? '0'
        );
    }
}

if (!function_exists('ga4dash_table')) {
    function ga4dash_table(
        array $report,
        string $currencyCode,
        int $visibleRows = 25
    ): void {
        if (empty($report['ok'])) {
            ?>
            <div class="ga4-api-empty">
                <strong>Section unavailable</strong>
                <span>
                    <?= ga4dash_h(
                        (string)(
                            $report['error']
                            ?? 'Google did not return this report.'
                        )
                    ) ?>
                </span>
            </div>
            <?php
            return;
        }

        $dimensions =
            (array)($report['dimension_headers'] ?? []);

        $metrics =
            (array)($report['metric_headers'] ?? []);

        $rows =
            (array)($report['rows'] ?? []);

        if (!$rows) {
            ?>
            <div class="ga4-api-empty">
                No data returned for this date range.
            </div>
            <?php
            return;
        }

        ?>
        <div
            class="ga4-api-table-wrap"
            data-ga4-table-wrap
        >
            <table class="ga4-api-table">
                <thead>
                    <tr>
                        <?php foreach ($dimensions as $dimension): ?>
                            <th>
                                <?= ga4dash_h(
                                    ga4dash_label(
                                        (string)$dimension
                                    )
                                ) ?>
                            </th>
                        <?php endforeach; ?>

                        <?php foreach ($metrics as $metric): ?>
                            <th>
                                <?= ga4dash_h(
                                    ga4dash_label(
                                        (string)($metric['name'] ?? '')
                                    )
                                ) ?>
                            </th>
                        <?php endforeach; ?>
                    </tr>
                </thead>

                <tbody>
                    <?php foreach ($rows as $index => $row): ?>
                        <tr
                            <?= $index >= $visibleRows
                                ? 'class="ga4-extra-row" hidden'
                                : ''
                            ?>
                        >
                            <?php foreach ($dimensions as $dimension): ?>
                                <td>
                                    <?= ga4dash_h(
                                        (string)(
                                            $row['dimensions'][$dimension]
                                            ?? '—'
                                        )
                                    ) ?>
                                </td>
                            <?php endforeach; ?>

                            <?php foreach ($metrics as $metric): ?>
                                <?php
                                $metricName =
                                    (string)($metric['name'] ?? '');
                                ?>
                                <td>
                                    <?= ga4dash_number(
                                        $metricName,
                                        $row['metrics'][$metricName]
                                            ?? 0,
                                        $currencyCode
                                    ) ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php if (count($rows) > $visibleRows): ?>
                <button
                    type="button"
                    class="ga4-table-more"
                    data-ga4-show-more
                >
                    Show all <?= number_format(count($rows)) ?> loaded rows
                </button>
            <?php endif; ?>
        </div>
        <?php
    }
}

$overview = (array)($dashboard['overview'] ?? []);
$daily = (array)($dashboard['daily'] ?? []);
$realtime = (array)($dashboard['realtime'] ?? []);

$propertyId =
    (string)($connection['selected_resource_id'] ?? '');

$propertyName =
    (string)(
        $connection['selected_resource_name']
        ?? 'Google Analytics'
    );

$googleEmail =
    (string)($connection['google_email'] ?? '');

$dailyChart = [];

if (!empty($daily['ok'])) {
    foreach ((array)($daily['rows'] ?? []) as $row) {
        $rawDate =
            (string)($row['dimensions']['date'] ?? '');

        $displayDate = $rawDate;

        if (
            preg_match(
                '/^(\d{4})(\d{2})(\d{2})$/',
                $rawDate,
                $m
            )
        ) {
            $displayDate =
                $m[1] . '-' . $m[2] . '-' . $m[3];
        }

        $dailyChart[] = [
            'date' => $displayDate,
            'sessions' => (float)(
                $row['metrics']['sessions']
                ?? 0
            ),
            'users' => (float)(
                $row['metrics']['activeUsers']
                ?? 0
            ),
        ];
    }
}

$realtimeActive =
    ga4dash_overview_metric(
        $realtime,
        'activeUsers'
    );

$realtimeEvents =
    ga4dash_overview_metric(
        $realtime,
        'eventCount'
    );
?>

<link
    rel="stylesheet"
    href="<?= asset('css/ga4-api-dashboard.css') ?>?v=1.0.0"
>

<div class="ga4-api-page">
    <header class="ga4-api-hero">
        <div>
            <div class="ga4-api-kicker">
                GOOGLE ANALYTICS 4 · LIVE API
            </div>

            <h1>GA4 Performance Intelligence</h1>

            <p>
                Live reporting from the connected Google Analytics property.
                PDF upload remains available as a separate reporting source.
            </p>
        </div>

        <div class="ga4-api-hero-actions">
            <a
                class="ga4-btn ga4-btn-secondary"
                href="<?= ga4dash_h(
                    url(
                        'business-ai-extraction',
                        ['source' => 'ga4']
                    )
                ) ?>"
            >
                Upload GA4 PDF
            </a>

            <a
                class="ga4-btn ga4-btn-secondary"
                href="<?= ga4dash_h(
                    url('business-google')
                ) ?>"
            >
                Google Connections
            </a>
        </div>
    </header>

    <?php if (!$connection): ?>
        <section class="ga4-api-panel ga4-api-connect-state">
            <div>
                <div class="ga4-api-kicker">CONNECTION REQUIRED</div>
                <h2>Connect Google Analytics first</h2>
                <p>
                    Authorize a Google account and choose the GA4 property
                    for this business. Your existing PDF upload feature is
                    unaffected.
                </p>
            </div>

            <a
                class="ga4-btn ga4-btn-primary"
                href="<?= ga4dash_h(url('business-google')) ?>"
            >
                Open Google Connections
            </a>
        </section>
    <?php else: ?>

        <section class="ga4-api-connection">
            <div>
                <span class="ga4-live-dot"></span>
                <strong>Connected</strong>
                <span><?= ga4dash_h($propertyName) ?></span>
                <span>Property <?= ga4dash_h($propertyId) ?></span>
                <span><?= ga4dash_h($googleEmail) ?></span>
            </div>

            <span class="ga4-api-source-badge">
                Source: Google Analytics Data API
            </span>
        </section>

        <section class="ga4-api-panel ga4-api-filter-panel">
            <form
                method="get"
                action="<?= ga4dash_h(
                    url('business-ga4-api-data')
                ) ?>"
                class="ga4-api-filter"
            >
                <input
                    type="hidden"
                    name="page"
                    value="business-ga4-api-data"
                >

                <label>
                    <span>Start date</span>
                    <input
                        type="date"
                        name="period_start"
                        value="<?= ga4dash_h($periodStart) ?>"
                        required
                    >
                </label>

                <label>
                    <span>End date</span>
                    <input
                        type="date"
                        name="period_end"
                        value="<?= ga4dash_h($periodEnd) ?>"
                        required
                    >
                </label>

                <button
                    class="ga4-btn ga4-btn-primary"
                    type="submit"
                >
                    Fetch API data
                </button>

                <a
                    class="ga4-btn ga4-btn-ghost"
                    href="<?= ga4dash_h(
                        url('business-ga4-api-data')
                    ) ?>"
                >
                    Last 30 days
                </a>
            </form>

            <div class="ga4-api-range-note">
                <?= ga4dash_h($periodStart) ?>
                →
                <?= ga4dash_h($periodEnd) ?>
            </div>
        </section>

        <section class="ga4-api-realtime-grid">
            <article class="ga4-api-realtime-card">
                <span>Realtime active users</span>
                <strong>
                    <?= ga4dash_number(
                        'activeUsers',
                        $realtimeActive,
                        $currencyCode
                    ) ?>
                </strong>
                <small>Current Google Realtime report</small>
            </article>

            <article class="ga4-api-realtime-card">
                <span>Realtime events</span>
                <strong>
                    <?= ga4dash_number(
                        'eventCount',
                        $realtimeEvents,
                        $currencyCode
                    ) ?>
                </strong>
                <small>Current Google Realtime report</small>
            </article>
        </section>

        <section class="ga4-api-kpis">
            <?php
            $overviewCards = [
                'sessions',
                'activeUsers',
                'newUsers',
                'engagedSessions',
                'engagementRate',
                'bounceRate',
                'averageSessionDuration',
                'screenPageViews',
                'eventCount',
                'keyEvents',
                'totalRevenue',
            ];
            ?>

            <?php foreach ($overviewCards as $metric): ?>
                <article class="ga4-api-kpi-card">
                    <span>
                        <?= ga4dash_h(
                            ga4dash_label($metric)
                        ) ?>
                    </span>

                    <strong>
                        <?= ga4dash_number(
                            $metric,
                            ga4dash_overview_metric(
                                $overview,
                                $metric
                            ),
                            $currencyCode
                        ) ?>
                    </strong>
                </article>
            <?php endforeach; ?>
        </section>

        <nav class="ga4-api-section-nav">
            <a href="#trend">Trend</a>
            <a href="#acquisition">Acquisition</a>
            <a href="#content">Content</a>
            <a href="#audience">Audience</a>
            <a href="#events">Events</a>
            <a href="#ecommerce">E-commerce</a>
            <a href="#explorer">Data Explorer</a>
        </nav>

        <section
            class="ga4-api-panel"
            id="trend"
        >
            <div class="ga4-api-panel-head">
                <div>
                    <span class="ga4-api-kicker">PERFORMANCE TREND</span>
                    <h2>Sessions & active users</h2>
                </div>
            </div>

            <?php if (!empty($dailyChart)): ?>
                <div class="ga4-chart-shell">
                    <canvas
                        id="ga4-performance-chart"
                        height="250"
                        aria-label="GA4 sessions and users trend"
                    ></canvas>
                </div>
            <?php endif; ?>

            <?php ga4dash_table(
                $daily,
                $currencyCode,
                15
            ); ?>
        </section>

        <section
            class="ga4-api-two-column"
            id="acquisition"
        >
            <article class="ga4-api-panel">
                <div class="ga4-api-panel-head">
                    <div>
                        <span class="ga4-api-kicker">ACQUISITION</span>
                        <h2>Traffic channels</h2>
                    </div>
                </div>

                <?php ga4dash_table(
                    (array)($dashboard['traffic'] ?? []),
                    $currencyCode,
                    15
                ); ?>
            </article>

            <article class="ga4-api-panel">
                <div class="ga4-api-panel-head">
                    <div>
                        <span class="ga4-api-kicker">ACQUISITION</span>
                        <h2>User acquisition</h2>
                    </div>
                </div>

                <?php ga4dash_table(
                    (array)($dashboard['first_user'] ?? []),
                    $currencyCode,
                    15
                ); ?>
            </article>
        </section>

        <section class="ga4-api-panel">
            <div class="ga4-api-panel-head">
                <div>
                    <span class="ga4-api-kicker">TRAFFIC SOURCE</span>
                    <h2>Source / medium</h2>
                </div>
            </div>

            <?php ga4dash_table(
                (array)($dashboard['source_medium'] ?? []),
                $currencyCode,
                25
            ); ?>
        </section>

        <section
            class="ga4-api-two-column"
            id="content"
        >
            <article class="ga4-api-panel">
                <div class="ga4-api-panel-head">
                    <div>
                        <span class="ga4-api-kicker">CONTENT</span>
                        <h2>Landing pages</h2>
                    </div>
                </div>

                <?php ga4dash_table(
                    (array)($dashboard['landing_pages'] ?? []),
                    $currencyCode,
                    20
                ); ?>
            </article>

            <article class="ga4-api-panel">
                <div class="ga4-api-panel-head">
                    <div>
                        <span class="ga4-api-kicker">CONTENT</span>
                        <h2>Pages & screens</h2>
                    </div>
                </div>

                <?php ga4dash_table(
                    (array)($dashboard['pages'] ?? []),
                    $currencyCode,
                    20
                ); ?>
            </article>
        </section>

        <section
            class="ga4-api-two-column"
            id="audience"
        >
            <article class="ga4-api-panel">
                <div class="ga4-api-panel-head">
                    <div>
                        <span class="ga4-api-kicker">AUDIENCE</span>
                        <h2>Devices</h2>
                    </div>
                </div>

                <?php ga4dash_table(
                    (array)($dashboard['devices'] ?? []),
                    $currencyCode,
                    20
                ); ?>
            </article>

            <article class="ga4-api-panel">
                <div class="ga4-api-panel-head">
                    <div>
                        <span class="ga4-api-kicker">AUDIENCE</span>
                        <h2>Geography</h2>
                    </div>
                </div>

                <?php ga4dash_table(
                    (array)($dashboard['geography'] ?? []),
                    $currencyCode,
                    20
                ); ?>
            </article>
        </section>

        <section class="ga4-api-panel">
            <div class="ga4-api-panel-head">
                <div>
                    <span class="ga4-api-kicker">TECHNOLOGY</span>
                    <h2>Browser, OS & device</h2>
                </div>
            </div>

            <?php ga4dash_table(
                (array)($dashboard['technology'] ?? []),
                $currencyCode,
                25
            ); ?>
        </section>

        <section
            class="ga4-api-panel"
            id="events"
        >
            <div class="ga4-api-panel-head">
                <div>
                    <span class="ga4-api-kicker">ENGAGEMENT</span>
                    <h2>Events & key events</h2>
                </div>
            </div>

            <?php ga4dash_table(
                (array)($dashboard['events'] ?? []),
                $currencyCode,
                30
            ); ?>
        </section>

        <section
            class="ga4-api-panel"
            id="ecommerce"
        >
            <div class="ga4-api-panel-head">
                <div>
                    <span class="ga4-api-kicker">MONETIZATION</span>
                    <h2>E-commerce</h2>
                </div>

                <span class="ga4-api-muted">
                    Shows zero/empty when this property does not track
                    e-commerce events.
                </span>
            </div>

            <?php ga4dash_table(
                (array)($dashboard['ecommerce'] ?? []),
                $currencyCode,
                25
            ); ?>
        </section>

        <section
            class="ga4-api-panel ga4-explorer"
            id="explorer"
        >
            <div class="ga4-api-panel-head">
                <div>
                    <span class="ga4-api-kicker">ADVANCED</span>
                    <h2>GA4 Data Explorer</h2>
                    <p>
                        Build a live report from every dimension and metric
                        Google exposes for this property, including registered
                        custom definitions.
                    </p>
                </div>
            </div>

            <?php if (!empty($metadata['error'])): ?>
                <div class="ga4-api-empty">
                    Metadata unavailable:
                    <?= ga4dash_h(
                        (string)$metadata['error']
                    ) ?>
                </div>
            <?php else: ?>
                <form
                    method="post"
                    action="<?= ga4dash_h(
                        url('business-ga4-api-data')
                    ) ?>"
                    class="ga4-explorer-form"
                >
                    <?= csrf_field() ?>

                    <input
                        type="hidden"
                        name="action"
                        value="custom"
                    >

                    <input
                        type="hidden"
                        name="period_start"
                        value="<?= ga4dash_h($periodStart) ?>"
                    >

                    <input
                        type="hidden"
                        name="period_end"
                        value="<?= ga4dash_h($periodEnd) ?>"
                    >

                    <div class="ga4-explorer-grid">
                        <div class="ga4-field-picker">
                            <div class="ga4-field-picker-head">
                                <div>
                                    <strong>Dimensions</strong>
                                    <small>
                                        Up to 9
                                    </small>
                                </div>

                                <span data-dimension-count>0 selected</span>
                            </div>

                            <input
                                type="search"
                                class="ga4-field-search"
                                placeholder="Search dimensions..."
                                data-field-search="dimension"
                            >

                            <div
                                class="ga4-field-list"
                                data-field-list="dimension"
                            >
                                <?php foreach (
                                    (array)$metadata['dimensions']
                                    as $dimension
                                ): ?>
                                    <label
                                        class="ga4-field-option"
                                        data-field-option
                                        data-search="<?= ga4dash_h(
                                            strtolower(
                                                (string)(
                                                    $dimension['ui_name']
                                                    . ' '
                                                    . $dimension['api_name']
                                                    . ' '
                                                    . $dimension['category']
                                                )
                                            )
                                        ) ?>"
                                    >
                                        <input
                                            type="checkbox"
                                            name="custom_dimensions[]"
                                            value="<?= ga4dash_h(
                                                (string)$dimension['api_name']
                                            ) ?>"
                                            data-ga4-dimension
                                        >

                                        <span>
                                            <strong>
                                                <?= ga4dash_h(
                                                    (string)$dimension['ui_name']
                                                ) ?>
                                            </strong>

                                            <small>
                                                <?= ga4dash_h(
                                                    (string)$dimension['api_name']
                                                ) ?>
                                                ·
                                                <?= ga4dash_h(
                                                    (string)$dimension['category']
                                                ) ?>
                                                <?= !empty(
                                                    $dimension['custom_definition']
                                                )
                                                    ? ' · Custom'
                                                    : ''
                                                ?>
                                            </small>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="ga4-field-picker">
                            <div class="ga4-field-picker-head">
                                <div>
                                    <strong>Metrics</strong>
                                    <small>
                                        Up to 10
                                    </small>
                                </div>

                                <span data-metric-count>0 selected</span>
                            </div>

                            <input
                                type="search"
                                class="ga4-field-search"
                                placeholder="Search metrics..."
                                data-field-search="metric"
                            >

                            <div
                                class="ga4-field-list"
                                data-field-list="metric"
                            >
                                <?php foreach (
                                    (array)$metadata['metrics']
                                    as $metric
                                ): ?>
                                    <label
                                        class="ga4-field-option"
                                        data-field-option
                                        data-search="<?= ga4dash_h(
                                            strtolower(
                                                (string)(
                                                    $metric['ui_name']
                                                    . ' '
                                                    . $metric['api_name']
                                                    . ' '
                                                    . $metric['category']
                                                )
                                            )
                                        ) ?>"
                                    >
                                        <input
                                            type="checkbox"
                                            name="custom_metrics[]"
                                            value="<?= ga4dash_h(
                                                (string)$metric['api_name']
                                            ) ?>"
                                            data-ga4-metric
                                        >

                                        <span>
                                            <strong>
                                                <?= ga4dash_h(
                                                    (string)$metric['ui_name']
                                                ) ?>
                                            </strong>

                                            <small>
                                                <?= ga4dash_h(
                                                    (string)$metric['api_name']
                                                ) ?>
                                                ·
                                                <?= ga4dash_h(
                                                    (string)$metric['category']
                                                ) ?>
                                                <?= !empty(
                                                    $metric['custom_definition']
                                                )
                                                    ? ' · Custom'
                                                    : ''
                                                ?>
                                            </small>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <div class="ga4-explorer-actions">
                        <label>
                            <span>Rows to load</span>
                            <select name="custom_limit">
                                <option value="50">50</option>
                                <option
                                    value="100"
                                    selected
                                >
                                    100
                                </option>
                                <option value="250">250</option>
                                <option value="500">500</option>
                                <option value="1000">1,000</option>
                            </select>
                        </label>

                        <button
                            type="submit"
                            class="ga4-btn ga4-btn-primary"
                        >
                            Run custom API report
                        </button>
                    </div>
                </form>
            <?php endif; ?>

            <?php if ($customReport): ?>
                <div class="ga4-custom-results">
                    <div class="ga4-api-panel-head">
                        <div>
                            <span class="ga4-api-kicker">
                                CUSTOM RESULT
                            </span>
                            <h3>
                                <?= number_format(
                                    (int)(
                                        $customReport['row_count']
                                        ?? 0
                                    )
                                ) ?>
                                matching rows
                            </h3>
                        </div>

                        <span class="ga4-api-muted">
                            Loaded:
                            <?= number_format(
                                count(
                                    (array)(
                                        $customReport['rows']
                                        ?? []
                                    )
                                )
                            ) ?>
                        </span>
                    </div>

                    <?php ga4dash_table(
                        $customReport,
                        $currencyCode,
                        50
                    ); ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="ga4-api-footnote">
            <strong>PDF and API remain separate sources.</strong>

            Your existing GA4 PDF upload route has not been changed.
            Use API Data for live Google reporting, and use PDF Upload whenever
            you still need the existing uploaded-report workflow.
        </section>

        <script
            id="ga4-daily-chart-data"
            type="application/json"
        ><?= json_encode(
            $dailyChart,
            JSON_HEX_TAG
            | JSON_HEX_AMP
            | JSON_HEX_APOS
            | JSON_HEX_QUOT
        ) ?></script>

        <script
            src="<?= asset('js/ga4-api-dashboard.js') ?>?v=1.0.0"
            defer
        ></script>
    <?php endif; ?>
</div>
