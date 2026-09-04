<?php

/**
 * Convert numeric GA4 API strings into PHP numbers.
 *
 * GA4 API responses normally represent metric values as strings.
 */
function ga4_cast_metric_value(
    mixed $value
): int|float|string|null {
    if ($value === null || $value === '') {
        return null;
    }

    $value = (string) $value;

    if (!is_numeric($value)) {
        return $value;
    }

    if (
        str_contains($value, '.') ||
        stripos($value, 'e') !== false
    ) {
        return (float) $value;
    }

    return (int) $value;
}


/**
 * Convert a raw GA4 runReport response into simple associative rows.
 *
 * Raw Google:
 *
 * dimensionValues[0]
 * metricValues[0]
 *
 * becomes:
 *
 * [
 *   'sessionDefaultChannelGroup' => 'Organic Search',
 *   'sessions' => 1234
 * ]
 */
function ga4_map_table(
    array $response
): array {
    $dimensionHeaders = [];

    foreach (
        ($response['dimensionHeaders'] ?? [])
        as $header
    ) {
        $dimensionHeaders[] =
            (string) ($header['name'] ?? '');
    }

    $metricHeaders = [];

    foreach (
        ($response['metricHeaders'] ?? [])
        as $header
    ) {
        $metricHeaders[] =
            (string) ($header['name'] ?? '');
    }

    $mappedRows = [];

    foreach (
        ($response['rows'] ?? [])
        as $row
    ) {
        $mapped = [];

        foreach (
            ($row['dimensionValues'] ?? [])
            as $index => $dimension
        ) {
            $name =
                $dimensionHeaders[$index]
                ?? 'dimension_' . $index;

            $mapped[$name] =
                (string) (
                    $dimension['value'] ?? ''
                );
        }

        foreach (
            ($row['metricValues'] ?? [])
            as $index => $metric
        ) {
            $name =
                $metricHeaders[$index]
                ?? 'metric_' . $index;

            $mapped[$name] =
                ga4_cast_metric_value(
                    $metric['value'] ?? null
                );
        }

        $mappedRows[] = $mapped;
    }

    return $mappedRows;
}


/**
 * Map the aggregate summary report into names suitable for
 * Aesthetic Intel.
 */
function ga4_map_summary(
    array $response
): array {

    $rows =
        ga4_map_table(
            $response
        );

    $row =
        $rows[0]
        ?? [];


    $number =
        static function (
            mixed $value
        ): float {

            if (
                $value === null
                ||
                $value === ''
            ) {
                return 0.0;
            }

            return (float)str_replace(
                ',',
                '',
                (string)$value
            );
        };


    $activeUsers =
        (int)round(
            $number(
                $row['activeUsers']
                ?? 0
            )
        );


    $newUsers =
        (int)round(
            $number(
                $row['newUsers']
                ?? 0
            )
        );


    $sessions =
        (int)round(
            $number(
                $row['sessions']
                ?? 0
            )
        );


    $engagedSessions =
        (int)round(
            $number(
                $row['engagedSessions']
                ?? 0
            )
        );


    $engagementRate =
        $number(
            $row['engagementRate']
            ?? 0
        );


    $pageViews =
        (int)round(
            $number(
                $row['screenPageViews']
                ?? 0
            )
        );


    $eventCount =
        (int)round(
            $number(
                $row['eventCount']
                ?? 0
            )
        );


    $engagementSeconds =
        $number(
            $row[
                'userEngagementDuration'
            ]
            ?? 0
        );


    /*
     * Average engagement time / session.
     */
    $averageEngagementPerSession =
        $sessions > 0
            ? $engagementSeconds
                / $sessions
            : null;


    /*
     * Average engagement time / active user.
     */
    $averageEngagementPerActiveUser =
        $activeUsers > 0
            ? $engagementSeconds
                / $activeUsers
            : null;


    /*
     * Engaged sessions / active user.
     */
    $engagedSessionsPerActiveUser =
        $activeUsers > 0
            ? $engagedSessions
                / $activeUsers
            : null;


    return [

        /*
         * Existing compatibility.
         */
        'users' =>
            $activeUsers,

        'active_users' =>
            $activeUsers,

        'new_users' =>
            $newUsers,

        'sessions' =>
            $sessions,

        'engaged_sessions' =>
            $engagedSessions,

        'engagement_rate' =>
            $engagementRate,

        'page_views' =>
            $pageViews,


        /*
         * Extended API/PDF validation metrics.
         */
        'event_count' =>
            $eventCount,

        'user_engagement_duration' =>
            $engagementSeconds,

        'average_engagement_time_per_session' =>
            $averageEngagementPerSession,

        'average_engagement_time_per_active_user' =>
            $averageEngagementPerActiveUser,

        'engaged_sessions_per_active_user' =>
            $engagedSessionsPerActiveUser,
    ];
}

/**
 * Map GA4 traffic-channel reporting.
 */
function ga4_map_channels(
    array $response
): array {
    $rows = ga4_map_table($response);

    $result = [];

    foreach ($rows as $row) {
        $channel =
            trim(
                (string) (
                    $row[
                        'sessionDefaultChannelGroup'
                    ]
                    ?? '(not set)'
                )
            );

        if ($channel === '') {
            $channel = '(not set)';
        }

        $result[] = [
            'channel' => $channel,

            'sessions' =>
                $row['sessions'] ?? 0,

            'users' =>
                $row['activeUsers'] ?? 0,

            'engaged_sessions' =>
                $row['engagedSessions']
                ?? 0,
        ];
    }

    return $result;
}


/**
 * Produce the first normalized Aesthetic Intel GA4 dataset.
 *
 * This is intentionally separate from database insertion.
 *
 * First verify that the values are correct. After we inspect your
 * existing PDF GA4 database structure, this output can be written
 * into the same canonical tables the PDF importer uses.
 */
function ga4_build_normalized_dataset(
    array $summaryResponse,
    array $channelResponse,
    string $startDate,
    string $endDate
): array {
    return [
        'source' => 'ga4_api',

        'period' => [
            'start' => $startDate,
            'end' => $endDate,
        ],

        'summary' =>
            ga4_map_summary(
                $summaryResponse
            ),

        'channels' =>
            ga4_map_channels(
                $channelResponse
            ),
    ];
}


/**
 * Simple comparison helper for PDF/API validation later.
 *
 * $pdfSummary and $apiSummary should use the same normalized names.
 */
function ga4_compare_summaries(
    array $pdfSummary,
    array $apiSummary
): array {
    $metrics = [
        'users',
        'new_users',
        'sessions',
        'engaged_sessions',
        'engagement_rate',
        'page_views',
    ];

    $comparison = [];

    foreach ($metrics as $metric) {
        $pdf =
            isset($pdfSummary[$metric])
                ? (float) $pdfSummary[$metric]
                : null;

        $api =
            isset($apiSummary[$metric])
                ? (float) $apiSummary[$metric]
                : null;

        $difference =
            ($pdf !== null && $api !== null)
                ? $api - $pdf
                : null;

        $percentDifference = null;

        if (
            $difference !== null &&
            $pdf !== null &&
            abs($pdf) > 0.000001
        ) {
            $percentDifference =
                ($difference / $pdf) * 100;
        }

        $comparison[$metric] = [
            'pdf' => $pdf,
            'api' => $api,
            'difference' => $difference,
            'percent_difference' =>
                $percentDifference,
        ];
    }

    return $comparison;
}