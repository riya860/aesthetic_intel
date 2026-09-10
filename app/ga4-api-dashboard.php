<?php

declare(strict_types=1);

/**
 * Aesthetic Intel — GA4 API Dashboard
 *
 * Uses the existing Google Connections OAuth layer from
 * app/google-business-platform.php.
 *
 * IMPORTANT:
 * - This module does not change or remove the existing GA4 PDF upload flow.
 * - Dashboard data is fetched live from the Google Analytics Data API.
 * - Existing google_ga4_daily_metrics remains available for scheduled/cache
 *   workflows, but this page does not require the user to inspect the DB.
 */

if (!function_exists('ga4dash_connection')) {
    function ga4dash_connection(int $businessId): ?array
    {
        $connection = googlehub_connection($businessId, 'ga4');

        if (!$connection) {
            return null;
        }

        $propertyId = trim(
            (string)($connection['selected_resource_id'] ?? '')
        );

        if (!preg_match('/^\d+$/', $propertyId)) {
            return null;
        }

        return $connection;
    }
}

if (!function_exists('ga4dash_property_id')) {
    function ga4dash_property_id(int $businessId): string
    {
        $connection = ga4dash_connection($businessId);

        if (!$connection) {
            throw new RuntimeException(
                'Connect a Google Analytics property before opening API Data.'
            );
        }

        return (string)$connection['selected_resource_id'];
    }
}

if (!function_exists('ga4dash_validate_date')) {
    function ga4dash_validate_date(string $value, string $label): string
    {
        $value = trim($value);

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if (
            !$date
            || $date->format('Y-m-d') !== $value
        ) {
            throw new RuntimeException(
                $label . ' must be a valid date.'
            );
        }

        return $value;
    }
}

if (!function_exists('ga4dash_period')) {
    /**
     * @return array{0:string,1:string}
     */
    function ga4dash_period(
        array $request,
        string $timezone = 'UTC'
    ): array {
        try {
            $tz = new DateTimeZone($timezone ?: 'UTC');
        } catch (Throwable) {
            $tz = new DateTimeZone('UTC');
        }

        $today = new DateTimeImmutable('today', $tz);

        $defaultEnd = $today
            ->modify('-1 day')
            ->format('Y-m-d');

        $defaultStart = $today
            ->modify('-30 days')
            ->format('Y-m-d');

        $start = trim(
            (string)($request['period_start'] ?? $defaultStart)
        );

        $end = trim(
            (string)($request['period_end'] ?? $defaultEnd)
        );

        $start = ga4dash_validate_date($start, 'Start date');
        $end = ga4dash_validate_date($end, 'End date');

        if ($start > $end) {
            throw new RuntimeException(
                'Start date cannot be after end date.'
            );
        }

        $startDate = new DateTimeImmutable($start);
        $endDate = new DateTimeImmutable($end);

        $days = (int)$startDate->diff($endDate)->days + 1;

        /*
         * Keep the interactive dashboard responsive and quota-friendly.
         * The Data Explorer can still query any supported fields within this
         * period.
         */
        if ($days > 366) {
            throw new RuntimeException(
                'Choose a date range of 366 days or less for the live dashboard.'
            );
        }

        return [$start, $end];
    }
}

if (!function_exists('ga4dash_report_definitions')) {
    /**
     * A comprehensive set of production-friendly Core Reporting views.
     *
     * Each report is isolated. If a property does not support a particular
     * e-commerce/content combination, only that card shows unavailable rather
     * than failing the entire dashboard.
     *
     * @return array<string,array<string,mixed>>
     */
    function ga4dash_report_definitions(): array
    {
        return [
            /*
             * Keep the main overview within the Data API/Core report limit
             * enforced by this integration: 10 metrics per request.
             *
             * totalRevenue is fetched separately below and merged back into
             * $result['overview'] so the frontend still sees one complete
             * overview object.
             */
            'overview' => [
                'title' => 'Overview',
                'dimensions' => [],
                'metrics' => [
                    'activeUsers',
                    'newUsers',
                    'sessions',
                    'engagedSessions',
                    'engagementRate',
                    'bounceRate',
                    'averageSessionDuration',
                    'screenPageViews',
                    'eventCount',
                    'keyEvents',
                ],
                'limit' => 1,
            ],

            'overview_revenue' => [
                'title' => 'Overview revenue',
                'dimensions' => [],
                'metrics' => [
                    'totalRevenue',
                ],
                'limit' => 1,
            ],

            'daily' => [
                'title' => 'Daily performance',
                'dimensions' => ['date'],
                'metrics' => [
                    'sessions',
                    'activeUsers',
                    'newUsers',
                    'engagedSessions',
                    'engagementRate',
                    'eventCount',
                    'keyEvents',
                    'totalRevenue',
                ],
                'limit' => 1000,
                'order_by' => [
                    [
                        'dimension' => [
                            'dimensionName' => 'date',
                        ],
                        'desc' => false,
                    ],
                ],
            ],

            'traffic' => [
                'title' => 'Traffic acquisition',
                'dimensions' => ['sessionDefaultChannelGroup'],
                'metrics' => [
                    'sessions',
                    'activeUsers',
                    'newUsers',
                    'engagedSessions',
                    'engagementRate',
                    'keyEvents',
                    'totalRevenue',
                ],
                'limit' => 100,
                'order_metric' => 'sessions',
            ],

            'source_medium' => [
                'title' => 'Source / medium',
                'dimensions' => ['sessionSource', 'sessionMedium'],
                'metrics' => [
                    'sessions',
                    'activeUsers',
                    'newUsers',
                    'engagedSessions',
                    'keyEvents',
                    'totalRevenue',
                ],
                'limit' => 250,
                'order_metric' => 'sessions',
            ],

            'landing_pages' => [
                'title' => 'Landing pages',
                'dimensions' => ['landingPagePlusQueryString'],
                'metrics' => [
                    'sessions',
                    'activeUsers',
                    'newUsers',
                    'engagedSessions',
                    'keyEvents',
                    'totalRevenue',
                ],
                'limit' => 250,
                'order_metric' => 'sessions',
            ],

            'pages' => [
                'title' => 'Pages & screens',
                'dimensions' => ['pagePath', 'pageTitle'],
                'metrics' => [
                    'screenPageViews',
                    'activeUsers',
                    'eventCount',
                    'userEngagementDuration',
                ],
                'limit' => 250,
                'order_metric' => 'screenPageViews',
            ],

            'events' => [
                'title' => 'Events & key events',
                'dimensions' => ['eventName'],
                'metrics' => [
                    'eventCount',
                    'activeUsers',
                    'eventCountPerUser',
                    'keyEvents',
                ],
                'limit' => 250,
                'order_metric' => 'eventCount',
            ],

            'devices' => [
                'title' => 'Devices',
                'dimensions' => ['deviceCategory'],
                'metrics' => [
                    'sessions',
                    'activeUsers',
                    'newUsers',
                    'engagedSessions',
                    'keyEvents',
                ],
                'limit' => 100,
                'order_metric' => 'sessions',
            ],

            'technology' => [
                'title' => 'Technology',
                'dimensions' => [
                    'browser',
                    'operatingSystem',
                    'deviceCategory',
                ],
                'metrics' => [
                    'sessions',
                    'activeUsers',
                    'engagedSessions',
                ],
                'limit' => 250,
                'order_metric' => 'sessions',
            ],

            'geography' => [
                'title' => 'Geography',
                'dimensions' => ['country', 'city'],
                'metrics' => [
                    'sessions',
                    'activeUsers',
                    'newUsers',
                    'keyEvents',
                ],
                'limit' => 250,
                'order_metric' => 'sessions',
            ],

            'first_user' => [
                'title' => 'User acquisition',
                'dimensions' => ['firstUserDefaultChannelGroup'],
                'metrics' => [
                    'activeUsers',
                    'newUsers',
                    'sessions',
                    'keyEvents',
                    'totalRevenue',
                ],
                'limit' => 100,
                'order_metric' => 'activeUsers',
            ],

            'ecommerce' => [
                'title' => 'E-commerce',
                'dimensions' => ['itemName', 'itemCategory'],
                'metrics' => [
                    'itemsViewed',
                    'itemsAddedToCart',
                    'itemsPurchased',
                    'itemRevenue',
                ],
                'limit' => 250,
                'order_metric' => 'itemRevenue',
            ],
        ];
    }
}

if (!function_exists('ga4dash_run_report')) {
    /**
     * Run one Analytics Data API Core report.
     *
     * @return array<string,mixed>
     */
    function ga4dash_run_report(
        int $businessId,
        string $startDate,
        string $endDate,
        array $dimensions,
        array $metrics,
        int $limit = 100,
        int $offset = 0,
        array $orderBys = []
    ): array {
        if (count($dimensions) > 9) {
            throw new RuntimeException(
                'GA4 supports at most 9 dimensions in one Core report.'
            );
        }

        if (!$metrics) {
            throw new RuntimeException(
                'Choose at least one metric.'
            );
        }

        if (count($metrics) > 10) {
            throw new RuntimeException(
                'GA4 supports at most 10 metrics in one Core report.'
            );
        }

        $propertyId = ga4dash_property_id($businessId);

        $body = [
            'dateRanges' => [[
                'startDate' => $startDate,
                'endDate' => $endDate,
            ]],
            'dimensions' => array_map(
                static fn(string $name): array => ['name' => $name],
                array_values($dimensions)
            ),
            'metrics' => array_map(
                static fn(string $name): array => ['name' => $name],
                array_values($metrics)
            ),
            'limit' => max(1, min(250000, $limit)),
            'offset' => max(0, $offset),
            'keepEmptyRows' => false,
            'returnPropertyQuota' => true,
        ];

        if ($orderBys) {
            $body['orderBys'] = $orderBys;
        }

        return googlehub_api_post(
            $businessId,
            'ga4',
            'https://analyticsdata.googleapis.com/v1beta/properties/'
                . rawurlencode($propertyId)
                . ':runReport',
            $body
        );
    }
}

if (!function_exists('ga4dash_normalize_report')) {
    /**
     * Convert Google's table response to a frontend-friendly structure.
     *
     * @return array<string,mixed>
     */
    function ga4dash_normalize_report(
        array $response,
        string $title = ''
    ): array {
        $dimensionHeaders = [];

        foreach (($response['dimensionHeaders'] ?? []) as $header) {
            $dimensionHeaders[] =
                (string)($header['name'] ?? '');
        }

        $metricHeaders = [];

        foreach (($response['metricHeaders'] ?? []) as $header) {
            $metricHeaders[] = [
                'name' => (string)($header['name'] ?? ''),
                'type' => (string)($header['type'] ?? ''),
            ];
        }

        $rows = [];

        foreach (($response['rows'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $dimensions = [];

            foreach (($row['dimensionValues'] ?? []) as $i => $value) {
                $name = $dimensionHeaders[$i] ?? ('dimension_' . $i);

                $dimensions[$name] =
                    (string)($value['value'] ?? '');
            }

            $metrics = [];

            foreach (($row['metricValues'] ?? []) as $i => $value) {
                $name =
                    (string)($metricHeaders[$i]['name'] ?? ('metric_' . $i));

                $metrics[$name] =
                    (string)($value['value'] ?? '0');
            }

            $rows[] = [
                'dimensions' => $dimensions,
                'metrics' => $metrics,
            ];
        }

        return [
            'ok' => true,
            'title' => $title,
            'dimension_headers' => $dimensionHeaders,
            'metric_headers' => $metricHeaders,
            'rows' => $rows,
            'row_count' => (int)($response['rowCount'] ?? count($rows)),
            'metadata' => $response['metadata'] ?? [],
            'property_quota' => $response['propertyQuota'] ?? [],
            'totals' => $response['totals'] ?? [],
            'maximums' => $response['maximums'] ?? [],
            'minimums' => $response['minimums'] ?? [],
        ];
    }
}

if (!function_exists('ga4dash_safe_report')) {
    /**
     * A report error must not take down the full GA4 dashboard.
     *
     * @return array<string,mixed>
     */
    function ga4dash_safe_report(
        int $businessId,
        string $startDate,
        string $endDate,
        array $definition
    ): array {
        try {
            $orderBys =
                is_array($definition['order_by'] ?? null)
                    ? $definition['order_by']
                    : [];

            $orderMetric =
                trim((string)($definition['order_metric'] ?? ''));

            if ($orderMetric !== '' && !$orderBys) {
                $orderBys[] = [
                    'metric' => [
                        'metricName' => $orderMetric,
                    ],
                    'desc' => true,
                ];
            }

            $response = ga4dash_run_report(
                $businessId,
                $startDate,
                $endDate,
                (array)($definition['dimensions'] ?? []),
                (array)($definition['metrics'] ?? []),
                (int)($definition['limit'] ?? 100),
                0,
                $orderBys
            );

            return ga4dash_normalize_report(
                $response,
                (string)($definition['title'] ?? '')
            );

        } catch (Throwable $e) {
            error_log(
                '[GA4 API Dashboard / '
                . (string)($definition['title'] ?? 'report')
                . '] '
                . $e->getMessage()
            );

            return [
                'ok' => false,
                'title' => (string)($definition['title'] ?? ''),
                'error' => $e->getMessage(),
                'dimension_headers' => [],
                'metric_headers' => [],
                'rows' => [],
                'row_count' => 0,
            ];
        }
    }
}

if (!function_exists('ga4dash_realtime')) {
    /**
     * Live users/events from the Analytics Realtime API.
     *
     * @return array<string,mixed>
     */
    function ga4dash_realtime(int $businessId): array
    {
        try {
            $propertyId = ga4dash_property_id($businessId);

            $response = googlehub_api_post(
                $businessId,
                'ga4',
                'https://analyticsdata.googleapis.com/v1beta/properties/'
                    . rawurlencode($propertyId)
                    . ':runRealtimeReport',
                [
                    'metrics' => [
                        ['name' => 'activeUsers'],
                        ['name' => 'eventCount'],
                    ],
                    'returnPropertyQuota' => true,
                ]
            );

            return ga4dash_normalize_report(
                $response,
                'Realtime'
            );

        } catch (Throwable $e) {
            return [
                'ok' => false,
                'title' => 'Realtime',
                'error' => $e->getMessage(),
                'rows' => [],
                'metric_headers' => [],
                'dimension_headers' => [],
                'row_count' => 0,
            ];
        }
    }
}

if (!function_exists('ga4dash_fetch_dashboard')) {
    /**
     * @return array<string,mixed>
     */
    function ga4dash_fetch_dashboard(
        int $businessId,
        string $startDate,
        string $endDate
    ): array {
        $result = [];

        foreach (ga4dash_report_definitions() as $key => $definition) {
            $result[$key] = ga4dash_safe_report(
                $businessId,
                $startDate,
                $endDate,
                $definition
            );
        }

        /*
         * Merge the separate revenue request into the main Overview object.
         * This preserves the frontend contract while keeping each Google
         * Core report request inside the supported metric count.
         */
        if (
            !empty($result['overview']['ok'])
            && !empty($result['overview_revenue']['ok'])
            && !empty($result['overview']['rows'][0])
            && !empty($result['overview_revenue']['rows'][0])
        ) {
            $revenueMetrics =
                (array)(
                    $result['overview_revenue']['rows'][0]['metrics']
                    ?? []
                );

            $result['overview']['rows'][0]['metrics'] =
                array_merge(
                    (array)$result['overview']['rows'][0]['metrics'],
                    $revenueMetrics
                );

            $existingHeaders =
                (array)($result['overview']['metric_headers'] ?? []);

            $existingNames = [];
            foreach ($existingHeaders as $header) {
                if (is_array($header) && isset($header['name'])) {
                    $existingNames[(string)$header['name']] = true;
                }
            }

            foreach (
                (array)($result['overview_revenue']['metric_headers'] ?? [])
                as $header
            ) {
                $name = is_array($header)
                    ? (string)($header['name'] ?? '')
                    : '';

                if ($name !== '' && !isset($existingNames[$name])) {
                    $existingHeaders[] = $header;
                    $existingNames[$name] = true;
                }
            }

            $result['overview']['metric_headers'] =
                $existingHeaders;
        } elseif (
            !empty($result['overview']['ok'])
            && empty($result['overview_revenue']['ok'])
        ) {
            /*
             * Do not fail the full Overview just because revenue is unavailable.
             * The view will show an em dash for the missing revenue metric.
             */
            $result['overview']['partial_errors'][] =
                (string)(
                    $result['overview_revenue']['error']
                    ?? 'Revenue metric unavailable.'
                );
        }

        unset($result['overview_revenue']);

        $result['realtime'] =
            ga4dash_realtime($businessId);

        return $result;
    }
}

if (!function_exists('ga4dash_metadata')) {
    /**
     * Get every Core Reporting field available to this property, including
     * registered custom dimensions/metrics.
     *
     * @return array{
     *   dimensions:array<int,array<string,mixed>>,
     *   metrics:array<int,array<string,mixed>>,
     *   error:?string
     * }
     */
    function ga4dash_metadata(int $businessId): array
    {
        try {
            $propertyId = ga4dash_property_id($businessId);

            $response = googlehub_api_get(
                $businessId,
                'ga4',
                'https://analyticsdata.googleapis.com/v1beta/properties/'
                    . rawurlencode($propertyId)
                    . '/metadata'
            );

            $normalize = static function (
                array $items,
                string $kind
            ): array {
                $out = [];

                foreach ($items as $item) {
                    if (!is_array($item)) {
                        continue;
                    }

                    $apiName =
                        trim((string)($item['apiName'] ?? ''));

                    if ($apiName === '') {
                        continue;
                    }

                    $out[] = [
                        'kind' => $kind,
                        'api_name' => $apiName,
                        'ui_name' => (string)(
                            $item['uiName']
                            ?? $apiName
                        ),
                        'description' => (string)(
                            $item['description']
                            ?? ''
                        ),
                        'category' => (string)(
                            $item['category']
                            ?? 'Other'
                        ),
                        'deprecated' => !empty(
                            $item['deprecatedApiNames']
                        ),
                        'custom_definition' => !empty(
                            $item['customDefinition']
                        ),
                        'type' => (string)(
                            $item['type']
                            ?? ''
                        ),
                    ];
                }

                usort(
                    $out,
                    static fn(array $a, array $b): int =>
                        strcasecmp(
                            $a['ui_name'],
                            $b['ui_name']
                        )
                );

                return $out;
            };

            return [
                'dimensions' => $normalize(
                    (array)($response['dimensions'] ?? []),
                    'dimension'
                ),
                'metrics' => $normalize(
                    (array)($response['metrics'] ?? []),
                    'metric'
                ),
                'error' => null,
            ];

        } catch (Throwable $e) {
            error_log(
                '[GA4 API Dashboard metadata] '
                . $e->getMessage()
            );

            return [
                'dimensions' => [],
                'metrics' => [],
                'error' => $e->getMessage(),
            ];
        }
    }
}

if (!function_exists('ga4dash_metadata_index')) {
    /**
     * Only allow property metadata fields to enter the custom report request.
     *
     * @return array<string,bool>
     */
    function ga4dash_metadata_index(
        array $metadata,
        string $key
    ): array {
        $index = [];

        foreach ((array)($metadata[$key] ?? []) as $item) {
            $name = trim(
                (string)($item['api_name'] ?? '')
            );

            if ($name !== '') {
                $index[$name] = true;
            }
        }

        return $index;
    }
}

if (!function_exists('ga4dash_custom_report')) {
    /**
     * Metadata-driven Data Explorer.
     *
     * @return array<string,mixed>
     */
    function ga4dash_custom_report(
        int $businessId,
        string $startDate,
        string $endDate,
        array $metadata,
        array $request
    ): array {
        $dimensions = array_values(
            array_unique(
                array_filter(
                    array_map(
                        'strval',
                        (array)($request['custom_dimensions'] ?? [])
                    )
                )
            )
        );

        $metrics = array_values(
            array_unique(
                array_filter(
                    array_map(
                        'strval',
                        (array)($request['custom_metrics'] ?? [])
                    )
                )
            )
        );

        if (count($dimensions) > 9) {
            throw new RuntimeException(
                'Choose at most 9 dimensions.'
            );
        }

        if (!$metrics) {
            throw new RuntimeException(
                'Choose at least one metric.'
            );
        }

        if (count($metrics) > 10) {
            throw new RuntimeException(
                'Choose at most 10 metrics.'
            );
        }

        $allowedDimensions =
            ga4dash_metadata_index(
                $metadata,
                'dimensions'
            );

        $allowedMetrics =
            ga4dash_metadata_index(
                $metadata,
                'metrics'
            );

        foreach ($dimensions as $dimension) {
            if (!isset($allowedDimensions[$dimension])) {
                throw new RuntimeException(
                    'Unsupported GA4 dimension: '
                    . $dimension
                );
            }
        }

        foreach ($metrics as $metric) {
            if (!isset($allowedMetrics[$metric])) {
                throw new RuntimeException(
                    'Unsupported GA4 metric: '
                    . $metric
                );
            }
        }

        $limit = max(
            10,
            min(
                1000,
                (int)($request['custom_limit'] ?? 100)
            )
        );

        $response = ga4dash_run_report(
            $businessId,
            $startDate,
            $endDate,
            $dimensions,
            $metrics,
            $limit
        );

        $normalized = ga4dash_normalize_report(
            $response,
            'Custom Data Explorer report'
        );

        $normalized['selected_dimensions'] =
            $dimensions;

        $normalized['selected_metrics'] =
            $metrics;

        $normalized['limit'] =
            $limit;

        return $normalized;
    }
}

if (!function_exists('ga4dash_currency_code')) {
    function ga4dash_currency_code(?array $connection): string
    {
        if (!$connection) {
            return 'USD';
        }

        $meta = json_decode(
            (string)($connection['resource_meta_json'] ?? ''),
            true
        );

        $code = is_array($meta)
            ? strtoupper(
                trim((string)($meta['currency_code'] ?? ''))
            )
            : '';

        return preg_match('/^[A-Z]{3}$/', $code)
            ? $code
            : 'USD';
    }
}
