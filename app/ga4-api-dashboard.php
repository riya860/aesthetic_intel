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


/*
|--------------------------------------------------------------------------
| GA4 PDF ↔ LIVE API COMPARISON
|--------------------------------------------------------------------------
|
| This comparison is business-agnostic. It always uses:
|   - the current Aesthetic Intel business context,
|   - that business's connected Google Analytics property,
|   - the current dashboard date range,
|   - PDFs uploaded specifically for that comparison request.
|
| Therefore RUMA, Remedy, and future businesses remain isolated by
| business_id and connected property.
|
|--------------------------------------------------------------------------
*/

if (!function_exists('ga4dash_compare_metric_definitions')) {
    /**
     * @return array<string,array<string,mixed>>
     */
    function ga4dash_compare_metric_definitions(): array
    {
        return [
            'sessions' => [
                'label' => 'Sessions',
                'aliases' => [
                    'sessions',
                    'session_count',
                    'sessioncount',
                ],
                'type' => 'count',
            ],

            'activeUsers' => [
                'label' => 'Active users',
                'aliases' => [
                    'active_users',
                    'activeusers',
                    'active user',
                    'active users',
                ],
                'type' => 'count',
            ],

            'newUsers' => [
                'label' => 'New users',
                'aliases' => [
                    'new_users',
                    'newusers',
                    'new user',
                    'new users',
                ],
                'type' => 'count',
            ],

            'engagedSessions' => [
                'label' => 'Engaged sessions',
                'aliases' => [
                    'engaged_sessions',
                    'engagedsessions',
                    'engaged session',
                    'engaged sessions',
                ],
                'type' => 'count',
            ],

            'engagementRate' => [
                'label' => 'Engagement rate',
                'aliases' => [
                    'engagement_rate',
                    'engagementrate',
                    'engagement rate',
                ],
                'type' => 'percent',
            ],

            'bounceRate' => [
                'label' => 'Bounce rate',
                'aliases' => [
                    'bounce_rate',
                    'bouncerate',
                    'bounce rate',
                ],
                'type' => 'percent',
            ],

            'averageSessionDuration' => [
                'label' => 'Avg. session duration',
                'aliases' => [
                    'average_session_duration',
                    'averagesessionduration',
                    'avg_session_duration',
                    'avgsessionduration',
                    'average session duration',
                    'avg session duration',
                ],
                'type' => 'duration',
            ],

            'screenPageViews' => [
                'label' => 'Views',
                'aliases' => [
                    'screen_page_views',
                    'screenpageviews',
                    'views',
                    'page_views',
                    'pageviews',
                ],
                'type' => 'count',
            ],

            'eventCount' => [
                'label' => 'Event count',
                'aliases' => [
                    'event_count',
                    'eventcount',
                    'event count',
                    'events',
                ],
                'type' => 'count',
            ],

            'keyEvents' => [
                'label' => 'Key events',
                'aliases' => [
                    'key_events',
                    'keyevents',
                    'key events',
                    'conversions',
                    'conversion_events',
                    'conversionevents',
                ],
                'type' => 'count',
            ],

            'totalRevenue' => [
                'label' => 'Total revenue',
                'aliases' => [
                    'total_revenue',
                    'totalrevenue',
                    'total revenue',
                    'revenue',
                ],
                'type' => 'money',
            ],
        ];
    }
}

if (!function_exists('ga4dash_compare_normalize_key')) {
    function ga4dash_compare_normalize_key(string $value): string
    {
        return strtolower(
            preg_replace(
                '/[^a-z0-9]+/i',
                '',
                trim($value)
            )
        );
    }
}

if (!function_exists('ga4dash_compare_parse_number')) {
    /**
     * Normalize a scalar PDF value into a comparable number.
     *
     * Percentages are returned as percentage points (57.9, not 0.579).
     * Durations are returned as seconds.
     */
    function ga4dash_compare_parse_number(
        mixed $value,
        string $type
    ): ?float {
        if (
            is_int($value)
            || is_float($value)
        ) {
            $number = (float)$value;

            if (
                $type === 'percent'
                && abs($number) <= 1.0
            ) {
                return $number * 100.0;
            }

            return $number;
        }

        if (!is_string($value)) {
            return null;
        }

        $raw = trim($value);

        if ($raw === '') {
            return null;
        }

        /*
         * Duration examples:
         *  3m 00s
         *  00:03:00
         *  3:00
         *  180
         */
        if ($type === 'duration') {
            if (
                preg_match(
                    '/^(?:(\d+)\s*h(?:ours?)?\s*)?'
                    . '(?:(\d+)\s*m(?:in(?:utes?)?)?\s*)?'
                    . '(?:(\d+(?:\.\d+)?)\s*s(?:ec(?:onds?)?)?)?$/i',
                    $raw,
                    $m
                )
                && (
                    ($m[1] ?? '') !== ''
                    || ($m[2] ?? '') !== ''
                    || ($m[3] ?? '') !== ''
                )
            ) {
                return
                    ((float)($m[1] ?? 0) * 3600)
                    + ((float)($m[2] ?? 0) * 60)
                    + (float)($m[3] ?? 0);
            }

            if (
                preg_match(
                    '/^(\d{1,3}):(\d{2})(?::(\d{2}(?:\.\d+)?))?$/',
                    $raw,
                    $m
                )
            ) {
                if (($m[3] ?? '') !== '') {
                    return
                        ((float)$m[1] * 3600)
                        + ((float)$m[2] * 60)
                        + (float)$m[3];
                }

                return
                    ((float)$m[1] * 60)
                    + (float)$m[2];
            }
        }

        $clean = str_replace(
            [',', '$', 'USD', 'usd', '€', '£'],
            '',
            $raw
        );

        $clean = trim($clean);

        if (
            $type === 'percent'
            && str_ends_with($clean, '%')
        ) {
            $clean = rtrim($clean, "% \t\n\r\0\x0B");
        }

        if (!is_numeric($clean)) {
            /*
             * Last-resort numeric extraction for values such as "$2,900.00 USD".
             */
            if (
                !preg_match(
                    '/-?\d+(?:\.\d+)?/',
                    str_replace(',', '', $raw),
                    $m
                )
            ) {
                return null;
            }

            $clean = $m[0];
        }

        $number = (float)$clean;

        if (
            $type === 'percent'
            && !str_contains($raw, '%')
            && abs($number) <= 1.0
        ) {
            $number *= 100.0;
        }

        return $number;
    }
}

if (!function_exists('ga4dash_compare_collect_scalars')) {
    /**
     * @return array<int,array{path:string,key:string,value:mixed}>
     */
    function ga4dash_compare_collect_scalars(
        mixed $value,
        string $path = '',
        int $depth = 0
    ): array {
        if ($depth > 12) {
            return [];
        }

        $rows = [];

        if (is_array($value)) {
            foreach ($value as $key => $child) {
                $keyText = (string)$key;

                $childPath =
                    $path === ''
                        ? $keyText
                        : $path . '.' . $keyText;

                if (is_array($child)) {
                    foreach (
                        ga4dash_compare_collect_scalars(
                            $child,
                            $childPath,
                            $depth + 1
                        )
                        as $nested
                    ) {
                        $rows[] = $nested;
                    }

                    continue;
                }

                if (
                    is_scalar($child)
                    || $child === null
                ) {
                    $rows[] = [
                        'path' => $childPath,
                        'key' => $keyText,
                        'value' => $child,
                    ];
                }
            }
        }

        return $rows;
    }
}

if (!function_exists('ga4dash_compare_pdf_metric')) {
    /**
     * Find the best scalar candidate for one metric in the normalized
     * PDF bundle returned by the project's existing GA4 PDF parser.
     *
     * @return array{value:float,path:string,raw:mixed}|null
     */
    function ga4dash_compare_pdf_metric(
        array $bundle,
        array $definition
    ): ?array {
        $aliases = array_map(
            'ga4dash_compare_normalize_key',
            (array)($definition['aliases'] ?? [])
        );

        $type =
            (string)($definition['type'] ?? 'count');

        $best = null;
        $bestScore = -PHP_INT_MAX;

        foreach (
            ga4dash_compare_collect_scalars($bundle)
            as $candidate
        ) {
            $keyNormalized =
                ga4dash_compare_normalize_key(
                    (string)$candidate['key']
                );

            $pathNormalized =
                ga4dash_compare_normalize_key(
                    (string)$candidate['path']
                );

            $matched = false;
            $score = 0;

            foreach ($aliases as $alias) {
                if ($alias === '') {
                    continue;
                }

                if ($keyNormalized === $alias) {
                    $matched = true;
                    $score = max($score, 100);
                } elseif (
                    str_ends_with(
                        $pathNormalized,
                        $alias
                    )
                ) {
                    $matched = true;
                    $score = max($score, 75);
                }
            }

            if (!$matched) {
                continue;
            }

            $pathLower =
                strtolower(
                    (string)$candidate['path']
                );

            /*
             * Prefer aggregate summary/KPI nodes.
             */
            foreach (
                [
                    'summary',
                    'overview',
                    'kpi',
                    'metrics',
                    'values',
                    'combined',
                    'totals',
                ]
                as $preferred
            ) {
                if (str_contains($pathLower, $preferred)) {
                    $score += 18;
                }
            }

            /*
             * De-prioritize row-level data if the parser includes it.
             */
            foreach (
                [
                    '.rows.',
                    '.daily.',
                    '.channels.',
                    '.pages.',
                    '.events.',
                ]
                as $rowPath
            ) {
                if (str_contains($pathLower, $rowPath)) {
                    $score -= 45;
                }
            }

            $number =
                ga4dash_compare_parse_number(
                    $candidate['value'],
                    $type
                );

            if ($number === null) {
                continue;
            }

            if ($score > $bestScore) {
                $bestScore = $score;

                $best = [
                    'value' => $number,
                    'path' => (string)$candidate['path'],
                    'raw' => $candidate['value'],
                ];
            }
        }

        return $best;
    }
}

if (!function_exists('ga4dash_compare_api_metric')) {
    function ga4dash_compare_api_metric(
        array $dashboard,
        string $metric,
        string $type
    ): ?float {
        $overview =
            (array)($dashboard['overview'] ?? []);

        if (
            empty($overview['ok'])
            || empty($overview['rows'][0]['metrics'])
            || !array_key_exists(
                $metric,
                (array)$overview['rows'][0]['metrics']
            )
        ) {
            return null;
        }

        $raw =
            $overview['rows'][0]['metrics'][$metric];

        $value =
            ga4dash_compare_parse_number(
                $raw,
                $type
            );

        /*
         * Google returns rates as fractions.
         */
        if (
            $type === 'percent'
            && $value !== null
            && abs((float)$raw) <= 1.0
        ) {
            $value = (float)$raw * 100.0;
        }

        return $value;
    }
}

if (!function_exists('ga4dash_compare_tolerance')) {
    function ga4dash_compare_tolerance(
        string $type,
        float $apiValue
    ): float {
        return match ($type) {
            'percent' => 0.25,   // percentage points
            'duration' => max(2.0, abs($apiValue) * 0.01),
            'money' => max(1.0, abs($apiValue) * 0.005),
            default => max(1.0, abs($apiValue) * 0.005),
        };
    }
}

if (!function_exists('ga4dash_compare_format_value')) {
    function ga4dash_compare_format_value(
        string $type,
        ?float $value,
        string $currencyCode = 'USD'
    ): string {
        if ($value === null) {
            return '—';
        }

        if ($type === 'percent') {
            return number_format($value, 1) . '%';
        }

        if ($type === 'money') {
            return
                $currencyCode
                . ' '
                . number_format($value, 2);
        }

        if ($type === 'duration') {
            $seconds = max(0, (int)round($value));

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
            abs($value - round($value))
            < 0.000001
        ) {
            return number_format((int)round($value));
        }

        return number_format($value, 2);
    }
}

if (!function_exists('ga4dash_compare_pdf_upload')) {
    /**
     * Compare one or more uploaded GA4 PDFs against the LIVE API overview
     * for the selected business/property/date range.
     *
     * @return array<string,mixed>
     */
    function ga4dash_compare_pdf_upload(
        int $businessId,
        array $business,
        ?array $connection,
        string $startDate,
        string $endDate,
        array $dashboard,
        mixed $uploadedFiles
    ): array {
        if (!$connection) {
            throw new RuntimeException(
                'Connect Google Analytics before comparing a PDF.'
            );
        }

        if (!function_exists('ga4_validation_build_pdf_bundle')) {
            throw new RuntimeException(
                'The existing GA4 PDF parser is unavailable. '
                . 'Confirm the current Aesthetic Intel bootstrap includes '
                . 'the GA4 PDF validation functions.'
            );
        }

        if (
            !is_array($uploadedFiles)
            || empty($uploadedFiles['name'])
        ) {
            throw new RuntimeException(
                'Choose at least one GA4 PDF to compare.'
            );
        }

        /*
         * Reuse the exact parser already used by the legacy Brospro
         * PDF/API validation feature. This preserves the existing PDF
         * extraction behavior instead of creating a second parser.
         */
        $pdfBundle =
            ga4_validation_build_pdf_bundle(
                $uploadedFiles,
                $startDate,
                $endDate
            );

        if (!is_array($pdfBundle)) {
            throw new RuntimeException(
                'The GA4 PDF parser did not return a valid result.'
            );
        }

        $currencyCode =
            ga4dash_currency_code($connection);

        $rows = [];
        $comparable = 0;
        $matched = 0;
        $review = 0;
        $notFound = 0;

        foreach (
            ga4dash_compare_metric_definitions()
            as $apiMetric => $definition
        ) {
            $type =
                (string)($definition['type'] ?? 'count');

            $apiValue =
                ga4dash_compare_api_metric(
                    $dashboard,
                    $apiMetric,
                    $type
                );

            $pdfMetric =
                ga4dash_compare_pdf_metric(
                    $pdfBundle,
                    $definition
                );

            $pdfValue =
                $pdfMetric['value']
                ?? null;

            $status = 'unavailable';
            $delta = null;
            $deltaPercent = null;
            $tolerance = null;

            if (
                $apiValue !== null
                && $pdfValue !== null
            ) {
                $comparable++;

                $delta =
                    $pdfValue - $apiValue;

                $deltaPercent =
                    abs($apiValue) > 0.000001
                        ? (
                            $delta
                            / $apiValue
                            * 100.0
                        )
                        : (
                            abs($pdfValue) <= 0.000001
                                ? 0.0
                                : null
                        );

                $tolerance =
                    ga4dash_compare_tolerance(
                        $type,
                        $apiValue
                    );

                if (abs($delta) <= $tolerance) {
                    $status = 'match';
                    $matched++;
                } else {
                    $status = 'review';
                    $review++;
                }
            } elseif ($pdfValue === null) {
                $notFound++;
            }

            $rows[] = [
                'api_metric' => $apiMetric,
                'label' => (string)$definition['label'],
                'type' => $type,
                'api_value' => $apiValue,
                'pdf_value' => $pdfValue,
                'api_display' =>
                    ga4dash_compare_format_value(
                        $type,
                        $apiValue,
                        $currencyCode
                    ),
                'pdf_display' =>
                    ga4dash_compare_format_value(
                        $type,
                        $pdfValue,
                        $currencyCode
                    ),
                'delta' => $delta,
                'delta_display' =>
                    $delta === null
                        ? '—'
                        : ga4dash_compare_format_value(
                            $type,
                            $delta,
                            $currencyCode
                        ),
                'delta_percent' => $deltaPercent,
                'tolerance' => $tolerance,
                'status' => $status,
                'pdf_source_path' =>
                    (string)($pdfMetric['path'] ?? ''),
            ];
        }

        $matchPercent =
            $comparable > 0
                ? round(
                    ($matched / $comparable) * 100,
                    1
                )
                : 0.0;

        $overallStatus =
            $comparable < 1
                ? 'unavailable'
                : (
                    $review === 0
                        ? 'verified'
                        : 'review'
                );

        $fileNames = [];

        $names =
            $uploadedFiles['name'] ?? [];

        if (!is_array($names)) {
            $names = [$names];
        }

        foreach ($names as $name) {
            $name = basename((string)$name);

            if ($name !== '') {
                $fileNames[] = $name;
            }
        }

        return [
            'success' => true,
            'business_id' => $businessId,
            'business_name' => (string)($business['name'] ?? ''),
            'property_id' =>
                (string)(
                    $connection['selected_resource_id']
                    ?? ''
                ),
            'property_name' =>
                (string)(
                    $connection['selected_resource_name']
                    ?? ''
                ),
            'period_start' => $startDate,
            'period_end' => $endDate,
            'currency_code' => $currencyCode,
            'files' => $fileNames,
            'metrics' => $rows,
            'comparable_metrics' => $comparable,
            'matched_metrics' => $matched,
            'review_metrics' => $review,
            'pdf_metrics_not_found' => $notFound,
            'match_percent' => $matchPercent,
            'overall_status' => $overallStatus,
        ];
    }
}


/*
|--------------------------------------------------------------------------
| SAVED GA4 PDF UPLOADS
|--------------------------------------------------------------------------
|
| Existing GA4 PDF uploads are already normalized into ai_extractions.
| The comparison page reads those saved records directly so the user does
| not have to upload the same PDF again.
|
|--------------------------------------------------------------------------
*/

if (!function_exists('ga4dash_saved_pdf_uploads')) {
    function ga4dash_saved_pdf_uploads(
        int $businessId,
        int $limit = 250
    ): array {
        $limit = max(1, min(500, $limit));

        $stmt = db()->prepare(
            "
            SELECT
                ae.id,
                ae.business_id,
                ae.source_code,
                ae.period_start,
                ae.period_end,
                ae.frequency,
                ae.extracted_json,
                ae.notes,
                ae.status,
                ae.validation_status,
                ae.validation_score,
                ae.created_by,
                ae.created_at,
                u.name AS uploaded_by_name
            FROM ai_extractions ae
            LEFT JOIN users u
                ON u.id = ae.created_by
            WHERE ae.business_id = ?
              AND ae.source_code = 'ga4'
              AND ae.extracted_json IS NOT NULL
              AND ae.extracted_json <> ''
            ORDER BY ae.created_at DESC, ae.id DESC
            LIMIT {$limit}
            "
        );

        $stmt->execute([$businessId]);

        $rows = $stmt->fetchAll();

        return is_array($rows) ? $rows : [];
    }
}

if (!function_exists('ga4dash_saved_pdf_upload')) {
    function ga4dash_saved_pdf_upload(
        int $businessId,
        int $uploadId
    ): ?array {
        if ($uploadId < 1) {
            return null;
        }

        $stmt = db()->prepare(
            "
            SELECT
                ae.*,
                u.name AS uploaded_by_name
            FROM ai_extractions ae
            LEFT JOIN users u
                ON u.id = ae.created_by
            WHERE ae.id = ?
              AND ae.business_id = ?
              AND ae.source_code = 'ga4'
            LIMIT 1
            "
        );

        $stmt->execute([
            $uploadId,
            $businessId,
        ]);

        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }
}

if (!function_exists('ga4dash_saved_pdf_group_label')) {
    function ga4dash_saved_pdf_group_label(
        string $createdAt
    ): string {
        $timestamp = strtotime($createdAt);

        return $timestamp === false
            ? 'Unknown upload date'
            : date('F j, Y', $timestamp);
    }
}

if (!function_exists('ga4dash_saved_pdf_option_label')) {
    function ga4dash_saved_pdf_option_label(
        array $row
    ): string {
        $createdAt =
            (string)($row['created_at'] ?? '');

        $timestamp = strtotime($createdAt);

        $time =
            $timestamp === false
                ? ''
                : date('g:i A', $timestamp);

        $periodStart =
            (string)($row['period_start'] ?? '');

        $periodEnd =
            (string)($row['period_end'] ?? '');

        $frequency =
            ucfirst(
                (string)($row['frequency'] ?? 'custom')
            );

        $parts = [];

        if ($time !== '') {
            $parts[] = $time;
        }

        if (
            $periodStart !== ''
            && $periodEnd !== ''
        ) {
            $parts[] =
                $periodStart
                . ' → '
                . $periodEnd;
        }

        if ($frequency !== '') {
            $parts[] = $frequency;
        }

        $parts[] =
            'Upload #'
            . (int)($row['id'] ?? 0);

        return implode(' · ', $parts);
    }
}

if (!function_exists('ga4dash_compare_saved_pdf_extraction')) {
    function ga4dash_compare_saved_pdf_extraction(
        int $businessId,
        array $business,
        ?array $connection,
        array $savedUpload,
        array $dashboard
    ): array {
        if (!$connection) {
            throw new RuntimeException(
                'Connect Google Analytics before comparing a saved PDF.'
            );
        }

        if (
            (int)($savedUpload['business_id'] ?? 0)
            !== $businessId
            || (string)($savedUpload['source_code'] ?? '')
            !== 'ga4'
        ) {
            throw new RuntimeException(
                'The selected GA4 upload does not belong to this business.'
            );
        }

        $decoded = json_decode(
            (string)($savedUpload['extracted_json'] ?? ''),
            true
        );

        if (!is_array($decoded) || !$decoded) {
            throw new RuntimeException(
                'The selected GA4 upload does not contain saved extracted data.'
            );
        }

        /*
         * The stored extracted_json is already PDF-derived data.
         * Wrap it in summary/values nodes so the generic metric matcher can
         * reuse the same alias logic as the manual PDF comparator.
         */
        $pdfBundle = [
            'summary' => $decoded,
            'values' => $decoded,
            'metadata' => [
                'upload_id' =>
                    (int)($savedUpload['id'] ?? 0),
                'period_start' =>
                    (string)($savedUpload['period_start'] ?? ''),
                'period_end' =>
                    (string)($savedUpload['period_end'] ?? ''),
                'frequency' =>
                    (string)($savedUpload['frequency'] ?? ''),
                'created_at' =>
                    (string)($savedUpload['created_at'] ?? ''),
            ],
        ];

        $currencyCode =
            ga4dash_currency_code($connection);

        $rows = [];
        $comparable = 0;
        $matched = 0;
        $review = 0;
        $notFound = 0;

        foreach (
            ga4dash_compare_metric_definitions()
            as $apiMetric => $definition
        ) {
            $type =
                (string)($definition['type'] ?? 'count');

            $apiValue =
                ga4dash_compare_api_metric(
                    $dashboard,
                    $apiMetric,
                    $type
                );

            $pdfMetric =
                ga4dash_compare_pdf_metric(
                    $pdfBundle,
                    $definition
                );

            $pdfValue =
                $pdfMetric['value'] ?? null;

            $status = 'unavailable';
            $delta = null;
            $deltaPercent = null;
            $tolerance = null;

            if (
                $apiValue !== null
                && $pdfValue !== null
            ) {
                $comparable++;

                $delta =
                    $pdfValue - $apiValue;

                $deltaPercent =
                    abs($apiValue) > 0.000001
                        ? ($delta / $apiValue * 100.0)
                        : (
                            abs($pdfValue) <= 0.000001
                                ? 0.0
                                : null
                        );

                $tolerance =
                    ga4dash_compare_tolerance(
                        $type,
                        $apiValue
                    );

                if (abs($delta) <= $tolerance) {
                    $status = 'match';
                    $matched++;
                } else {
                    $status = 'review';
                    $review++;
                }
            } elseif ($pdfValue === null) {
                $notFound++;
            }

            $rows[] = [
                'api_metric' => $apiMetric,
                'label' =>
                    (string)$definition['label'],
                'type' => $type,
                'api_value' => $apiValue,
                'pdf_value' => $pdfValue,
                'api_display' =>
                    ga4dash_compare_format_value(
                        $type,
                        $apiValue,
                        $currencyCode
                    ),
                'pdf_display' =>
                    ga4dash_compare_format_value(
                        $type,
                        $pdfValue,
                        $currencyCode
                    ),
                'delta' => $delta,
                'delta_display' =>
                    $delta === null
                        ? '—'
                        : ga4dash_compare_format_value(
                            $type,
                            $delta,
                            $currencyCode
                        ),
                'delta_percent' => $deltaPercent,
                'tolerance' => $tolerance,
                'status' => $status,
                'pdf_source_path' =>
                    (string)($pdfMetric['path'] ?? ''),
            ];
        }

        $matchPercent =
            $comparable > 0
                ? round(
                    ($matched / $comparable) * 100,
                    1
                )
                : 0.0;

        $overallStatus =
            $comparable < 1
                ? 'unavailable'
                : (
                    $review === 0
                        ? 'verified'
                        : 'review'
                );

        return [
            'success' => true,
            'comparison_source' =>
                'saved_ga4_pdf_extraction',
            'saved_upload_id' =>
                (int)($savedUpload['id'] ?? 0),
            'uploaded_at' =>
                (string)($savedUpload['created_at'] ?? ''),
            'uploaded_by' =>
                (string)($savedUpload['uploaded_by_name'] ?? ''),
            'frequency' =>
                (string)($savedUpload['frequency'] ?? ''),
            'validation_status' =>
                (string)($savedUpload['validation_status'] ?? ''),
            'business_id' => $businessId,
            'business_name' =>
                (string)($business['name'] ?? ''),
            'property_id' =>
                (string)(
                    $connection['selected_resource_id']
                    ?? ''
                ),
            'property_name' =>
                (string)(
                    $connection['selected_resource_name']
                    ?? ''
                ),
            'period_start' =>
                (string)($savedUpload['period_start'] ?? ''),
            'period_end' =>
                (string)($savedUpload['period_end'] ?? ''),
            'currency_code' => $currencyCode,
            'metrics' => $rows,
            'comparable_metrics' => $comparable,
            'matched_metrics' => $matched,
            'review_metrics' => $review,
            'pdf_metrics_not_found' => $notFound,
            'match_percent' => $matchPercent,
            'overall_status' => $overallStatus,
        ];
    }
}

