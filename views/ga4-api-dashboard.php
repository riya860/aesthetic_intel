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
$pdfComparison = is_array($pdfComparison ?? null) ? $pdfComparison : null;
$savedGa4PdfUploads = is_array($savedGa4PdfUploads ?? null)
    ? $savedGa4PdfUploads
    : [];
$selectedSavedPdfId = (int)($selectedSavedPdfId ?? 0);

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
            'eventCountPerUser' => 'Events / active user',
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
        /*
         * Never turn an unavailable API value into a real-looking zero.
         * The previous implementation cast the em dash to float, so an
         * internal report failure appeared as 0 / 0.0% / USD 0.00.
         */
        if (
            $value === null
            || $value === ''
            || $value === '—'
            || !is_numeric((string)$value)
        ) {
            return '—';
        }

        $raw = (float)$value;

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
    ): ?string {
        if (
            empty($report['ok'])
            || empty($report['rows'][0]['metrics'])
            || !array_key_exists(
                $metric,
                (array)$report['rows'][0]['metrics']
            )
        ) {
            return null;
        }

        return (string)$report['rows'][0]['metrics'][$metric];
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

$savedPdfGroups = [];

foreach ($savedGa4PdfUploads as $savedUpload) {
    $groupLabel =
        ga4dash_saved_pdf_group_label(
            (string)($savedUpload['created_at'] ?? '')
        );

    $savedPdfGroups[$groupLabel][] =
        $savedUpload;
}

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

/*
 * Surface partial API failures instead of silently hiding them.
 */
$failedSections = [];
$successfulSections = 0;

foreach ($dashboard as $key => $report) {
    if (!is_array($report)) {
        continue;
    }

    if (!empty($report['ok'])) {
        $successfulSections++;
        continue;
    }

    $failedSections[] = [
        'key' => (string)$key,
        'title' => (string)(
            $report['title']
            ?? ga4dash_label((string)$key)
        ),
        'error' => (string)(
            $report['error']
            ?? 'Google did not return this section.'
        ),
    ];
}

$fetchRequested =
    (string)($_GET['fetch'] ?? '') === '1';

$overviewOk =
    !empty($overview['ok'])
    && !empty($overview['rows'][0]['metrics']);

$dailyOk =
    !empty($daily['ok']);

$fetchSucceeded =
    $connection
    && $overviewOk
    && $dailyOk;
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

                <input
                    type="hidden"
                    name="fetch"
                    value="1"
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

        <?php if ($fetchRequested && $fetchSucceeded): ?>
            <div
                class="ga4-api-fetch-status ga4-api-fetch-status-success"
                data-ga4-fetch-toast
                role="status"
            >
                <strong>GA4 API data fetched successfully.</strong>
                <span>
                    Loaded live reporting for
                    <?= ga4dash_h($periodStart) ?>
                    →
                    <?= ga4dash_h($periodEnd) ?>.
                    <?= number_format($successfulSections) ?>
                    reporting section(s) responded successfully.
                </span>
            </div>
        <?php endif; ?>

        <?php if ($failedSections): ?>
            <div
                class="ga4-api-fetch-status ga4-api-fetch-status-warning"
                role="alert"
            >
                <strong>
                    Some GA4 sections could not be loaded.
                </strong>

                <span>
                    The rest of the dashboard is still valid.
                </span>

                <details>
                    <summary>
                        Show <?= count($failedSections) ?> section error(s)
                    </summary>

                    <ul>
                        <?php foreach ($failedSections as $failed): ?>
                            <li>
                                <strong>
                                    <?= ga4dash_h($failed['title']) ?>:
                                </strong>
                                <?= ga4dash_h($failed['error']) ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </details>
            </div>
        <?php endif; ?>

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

        <?php if (!$overviewOk): ?>
            <div class="ga4-api-empty">
                <strong>Overview KPIs are unavailable.</strong>
                <span>
                    <?= ga4dash_h(
                        (string)(
                            $overview['error']
                            ?? 'The Overview report did not return data.'
                        )
                    ) ?>
                </span>
            </div>
        <?php endif; ?>

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
            <a href="#pdf-compare">PDF Compare</a>
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
            class="ga4-api-panel ga4-pdf-compare"
            id="pdf-compare"
        >
            <div class="ga4-api-panel-head">
                <div>
                    <span class="ga4-api-kicker">
                        VALIDATION
                    </span>

                    <h2>Compare GA4 API with PDF</h2>

                    <p>
                        Choose a GA4 PDF upload already saved for this
                        business. Aesthetic Intel automatically uses that
                        upload's reporting period and compares its saved
                        PDF-derived values against the live Google Analytics
                        property currently connected to
                        <strong><?= ga4dash_h(
                            (string)($business['name'] ?? 'this business')
                        ) ?></strong>.
                    </p>
                </div>

                <span class="ga4-api-source-badge">
                    Property <?= ga4dash_h($propertyId) ?>
                </span>
            </div>

            <div class="ga4-compare-context">
                <div>
                    <span>Business</span>
                    <strong>
                        <?= ga4dash_h(
                            (string)($business['name'] ?? '—')
                        ) ?>
                    </strong>
                </div>

                <div>
                    <span>GA4 property</span>
                    <strong>
                        <?= ga4dash_h($propertyName) ?>
                    </strong>
                </div>

                <div>
                    <span>Comparison period</span>
                    <strong>
                        <?= ga4dash_h($periodStart) ?>
                        →
                        <?= ga4dash_h($periodEnd) ?>
                    </strong>
                </div>
            </div>

            <?php if (!$savedGa4PdfUploads): ?>
                <div class="ga4-api-empty">
                    <strong>No saved GA4 PDF uploads yet.</strong>
                    <span>
                        Upload a GA4 PDF through the existing
                        <a
                            href="<?= ga4dash_h(
                                url(
                                    'business-ai-extraction',
                                    ['source' => 'ga4']
                                )
                            ) ?>"
                        >
                            GA4 PDF Upload
                        </a>
                        page first. Saved GA4 uploads will then appear here
                        automatically.
                    </span>
                </div>
            <?php else: ?>
                <form
                    method="post"
                    action="<?= ga4dash_h(
                        url('business-ga4-api-data')
                    ) ?>#pdf-compare"
                    class="ga4-compare-form"
                    data-ga4-saved-pdf-form
                >
                    <?= csrf_field() ?>

                    <input
                        type="hidden"
                        name="action"
                        value="compare_saved_pdf"
                    >

                    <label class="ga4-saved-pdf-picker">
                        <span>Choose saved GA4 PDF upload</span>

                        <select
                            name="saved_pdf_id"
                            required
                            data-ga4-saved-pdf-select
                        >
                            <option value="">
                                Select an uploaded GA4 report…
                            </option>

                            <?php foreach (
                                $savedPdfGroups
                                as $groupLabel => $groupRows
                            ): ?>
                                <optgroup
                                    label="<?= ga4dash_h(
                                        (string)$groupLabel
                                    ) ?>"
                                >
                                    <?php foreach (
                                        $groupRows
                                        as $savedUpload
                                    ): ?>
                                        <?php
                                        $uploadId =
                                            (int)($savedUpload['id'] ?? 0);
                                        ?>
                                        <option
                                            value="<?= $uploadId ?>"
                                            <?= $selectedSavedPdfId === $uploadId
                                                ? 'selected'
                                                : ''
                                            ?>
                                        >
                                            <?= ga4dash_h(
                                                ga4dash_saved_pdf_option_label(
                                                    $savedUpload
                                                )
                                            ) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endforeach; ?>
                        </select>

                        <small>
                            Uploads are grouped by upload date. Select one and
                            Aesthetic Intel automatically fetches live GA4 data
                            for that saved report's exact period.
                        </small>
                    </label>

                    <div class="ga4-compare-actions">
                        <button
                            type="submit"
                            class="ga4-btn ga4-btn-primary"
                        >
                            Compare selected upload
                        </button>

                        <a
                            class="ga4-btn ga4-btn-secondary"
                            href="<?= ga4dash_h(
                                url(
                                    'business-ai-extraction',
                                    ['source' => 'ga4']
                                )
                            ) ?>"
                        >
                            Open GA4 PDF Upload
                        </a>
                    </div>
                </form>
            <?php endif; ?>

            <?php if ($pdfComparison): ?>
                <?php if (empty($pdfComparison['success'])): ?>
                    <div
                        class="ga4-compare-alert ga4-compare-alert-error"
                        role="alert"
                    >
                        <strong>PDF comparison failed.</strong>

                        <span>
                            <?= ga4dash_h(
                                (string)(
                                    $pdfComparison['error']
                                    ?? 'The PDF could not be compared.'
                                )
                            ) ?>
                        </span>
                    </div>
                <?php else: ?>
                    <?php
                    $compareStatus =
                        (string)(
                            $pdfComparison['overall_status']
                            ?? 'unavailable'
                        );

                    $compareClass =
                        $compareStatus === 'verified'
                            ? 'verified'
                            : (
                                $compareStatus === 'review'
                                    ? 'review'
                                    : 'unavailable'
                            );
                    ?>

                    <div
                        class="ga4-compare-summary ga4-compare-summary-<?= ga4dash_h(
                            $compareClass
                        ) ?>"
                    >
                        <article>
                            <span>API ↔ PDF alignment</span>
                            <strong>
                                <?= number_format(
                                    (float)(
                                        $pdfComparison['match_percent']
                                        ?? 0
                                    ),
                                    1
                                ) ?>%
                            </strong>
                        </article>

                        <article>
                            <span>Comparable metrics</span>
                            <strong>
                                <?= number_format(
                                    (int)(
                                        $pdfComparison['comparable_metrics']
                                        ?? 0
                                    )
                                ) ?>
                            </strong>
                        </article>

                        <article>
                            <span>Matched</span>
                            <strong>
                                <?= number_format(
                                    (int)(
                                        $pdfComparison['matched_metrics']
                                        ?? 0
                                    )
                                ) ?>
                            </strong>
                        </article>

                        <article>
                            <span>Needs review</span>
                            <strong>
                                <?= number_format(
                                    (int)(
                                        $pdfComparison['review_metrics']
                                        ?? 0
                                    )
                                ) ?>
                            </strong>
                        </article>
                    </div>

                    <div class="ga4-compare-result-head">
                        <div>
                            <strong>
                                <?= ga4dash_h(
                                    (string)(
                                        $pdfComparison['business_name']
                                        ?? ''
                                    )
                                ) ?>
                            </strong>

                            <span>
                                Property
                                <?= ga4dash_h(
                                    (string)(
                                        $pdfComparison['property_id']
                                        ?? ''
                                    )
                                ) ?>
                            </span>
                        </div>

                        <span>
                            <?= ga4dash_h(
                                (string)(
                                    $pdfComparison['period_start']
                                    ?? ''
                                )
                            ) ?>
                            →
                            <?= ga4dash_h(
                                (string)(
                                    $pdfComparison['period_end']
                                    ?? ''
                                )
                            ) ?>
                        </span>
                    </div>

                    <?php if (!empty($pdfComparison['saved_upload_id'])): ?>
                        <div class="ga4-compare-files">
                            <strong>Saved upload:</strong>
                            #<?= (int)$pdfComparison['saved_upload_id'] ?>

                            <?php if (!empty($pdfComparison['uploaded_at'])): ?>
                                · Uploaded
                                <?= ga4dash_h(
                                    date(
                                        'M j, Y g:i A',
                                        strtotime(
                                            (string)$pdfComparison[
                                                'uploaded_at'
                                            ]
                                        )
                                    )
                                ) ?>
                            <?php endif; ?>

                            <?php if (!empty($pdfComparison['uploaded_by'])): ?>
                                · by
                                <?= ga4dash_h(
                                    (string)$pdfComparison['uploaded_by']
                                ) ?>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <div class="ga4-api-table-wrap">
                        <table class="ga4-api-table ga4-compare-table">
                            <thead>
                                <tr>
                                    <th>Metric</th>
                                    <th>Live API</th>
                                    <th>PDF</th>
                                    <th>Difference</th>
                                    <th>Difference %</th>
                                    <th>Status</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php foreach (
                                    (array)($pdfComparison['metrics'] ?? [])
                                    as $metricRow
                                ): ?>
                                    <?php
                                    $status =
                                        (string)(
                                            $metricRow['status']
                                            ?? 'unavailable'
                                        );
                                    ?>
                                    <tr>
                                        <td>
                                            <strong>
                                                <?= ga4dash_h(
                                                    (string)(
                                                        $metricRow['label']
                                                        ?? ''
                                                    )
                                                ) ?>
                                            </strong>
                                        </td>

                                        <td>
                                            <?= ga4dash_h(
                                                (string)(
                                                    $metricRow['api_display']
                                                    ?? '—'
                                                )
                                            ) ?>
                                        </td>

                                        <td>
                                            <?= ga4dash_h(
                                                (string)(
                                                    $metricRow['pdf_display']
                                                    ?? '—'
                                                )
                                            ) ?>
                                        </td>

                                        <td>
                                            <?= ga4dash_h(
                                                (string)(
                                                    $metricRow['delta_display']
                                                    ?? '—'
                                                )
                                            ) ?>
                                        </td>

                                        <td>
                                            <?php if (
                                                $metricRow['delta_percent']
                                                !== null
                                            ): ?>
                                                <?= number_format(
                                                    (float)$metricRow[
                                                        'delta_percent'
                                                    ],
                                                    2
                                                ) ?>%
                                            <?php else: ?>
                                                —
                                            <?php endif; ?>
                                        </td>

                                        <td>
                                            <span
                                                class="ga4-compare-status ga4-compare-status-<?= ga4dash_h(
                                                    $status
                                                ) ?>"
                                            >
                                                <?=
                                                    $status === 'match'
                                                        ? 'Matched'
                                                        : (
                                                            $status === 'review'
                                                                ? 'Review'
                                                                : 'Not found'
                                                        )
                                                ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if (
                        (int)(
                            $pdfComparison['pdf_metrics_not_found']
                            ?? 0
                        ) > 0
                    ): ?>
                        <div class="ga4-api-muted ga4-compare-note">
                            Some API metrics were not found in the uploaded
                            PDF. They are marked “Not found” and are excluded
                            from the alignment percentage.
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>
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
            <strong>PDF upload remains intact.</strong>

            Your existing GA4 PDF upload route has not been changed.
            The PDF Compare section reads previously saved GA4 PDF
            uploads from Aesthetic Intel and compares the selected upload
            against the currently selected business's live Google Analytics
            API data.
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
