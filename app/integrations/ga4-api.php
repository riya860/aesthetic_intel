<?php

use Google\Client as GoogleClient;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\GuzzleException;


/**
 * Base HTTP client for Google Analytics REST APIs.
 */
function ga4_http_client(): HttpClient
{
    return new HttpClient([
        'timeout' => 30,
        'connect_timeout' => 10,
        'http_errors' => false,
    ]);
}


/**
 * Convert Google API errors into readable Aesthetic Intel exceptions.
 */
function ga4_google_error_message(
    int $statusCode,
    string $body
): string {
    $json = json_decode($body, true);

    if (
        is_array($json) &&
        isset($json['error']['message'])
    ) {
        return sprintf(
            'Google Analytics API error (%d): %s',
            $statusCode,
            (string) $json['error']['message']
        );
    }

    return sprintf(
        'Google Analytics API returned HTTP %d.',
        $statusCode
    );
}


/**
 * Generic authenticated Google API request.
 */
function ga4_request(
    int $businessId,
    string $method,
    string $url,
    ?array $jsonBody = null
): array {
    $accessToken = ga4_access_token($businessId);

    $options = [
        'headers' => [
            'Authorization' =>
                'Bearer ' . $accessToken,
            'Accept' => 'application/json',
        ],
    ];

    if ($jsonBody !== null) {
        $options['headers']['Content-Type'] =
            'application/json';

        $options['json'] = $jsonBody;
    }

    try {
        $response = ga4_http_client()->request(
            strtoupper($method),
            $url,
            $options
        );
    } catch (GuzzleException $e) {
        throw new RuntimeException(
            'Could not contact Google Analytics: '
            . $e->getMessage()
        );
    }

    $status = $response->getStatusCode();

    $body = (string) $response->getBody();

    if ($status < 200 || $status >= 300) {
        throw new RuntimeException(
            ga4_google_error_message(
                $status,
                $body
            )
        );
    }

    $decoded = json_decode(
        $body,
        true
    );

    if (!is_array($decoded)) {
        return [];
    }

    return $decoded;
}


/**
 * Verify a property during the initial OAuth callback.
 *
 * This version receives an already-authenticated GoogleClient because
 * the connection has not yet been saved in MariaDB.
 */
function ga4_verify_property_access_with_token(
    GoogleClient $googleClient,
    string $propertyId
): void {
    $propertyId =
        ga4_normalize_property_id($propertyId);

    $token = $googleClient->getAccessToken();

    $accessToken =
        is_array($token)
            ? (string) ($token['access_token'] ?? '')
            : '';

    if ($accessToken === '') {
        throw new RuntimeException(
            'Google OAuth access token is unavailable.'
        );
    }

    $url =
        'https://analyticsdata.googleapis.com/'
        . 'v1beta/properties/'
        . rawurlencode($propertyId)
        . ':runReport';

    try {
        $response = ga4_http_client()->post(
            $url,
            [
                'headers' => [
                    'Authorization' =>
                        'Bearer ' . $accessToken,
                    'Content-Type' =>
                        'application/json',
                    'Accept' =>
                        'application/json',
                ],
                'json' => [
                    'dateRanges' => [
                        [
                            'startDate' =>
                                'yesterday',
                            'endDate' =>
                                'yesterday',
                        ],
                    ],
                    'metrics' => [
                        [
                            'name' =>
                                'sessions',
                        ],
                    ],
                    'limit' => 1,
                ],
            ]
        );
    } catch (GuzzleException $e) {
        throw new RuntimeException(
            'Unable to verify the Google Analytics property: '
            . $e->getMessage()
        );
    }

    $status = $response->getStatusCode();

    if ($status < 200 || $status >= 300) {
        throw new RuntimeException(
            ga4_google_error_message(
                $status,
                (string) $response->getBody()
            )
        );
    }
}


/**
 * Get the property attached to an Aesthetic Intel business.
 */
function ga4_business_property_id(
    int $businessId
): string {
    $connection = ga4_connection($businessId);

    if (!$connection) {
        throw new RuntimeException(
            'Google Analytics is not connected.'
        );
    }

    return ga4_normalize_property_id(
        (string) $connection['property_id']
    );
}


/**
 * Execute a standard GA4 Data API runReport request.
 *
 * Example:
 *
 * ga4_run_report(
 *     $businessId,
 *     '2026-08-01',
 *     '2026-08-26',
 *     ['date'],
 *     ['sessions', 'activeUsers']
 * );
 */
function ga4_run_report(
    int $businessId,
    string $startDate,
    string $endDate,
    array $dimensions,
    array $metrics,
    array $extra = []
): array {
    $propertyId =
        ga4_business_property_id($businessId);

    if (!$metrics) {
        throw new RuntimeException(
            'At least one GA4 metric is required.'
        );
    }

    $dimensionPayload = [];

    foreach ($dimensions as $dimension) {
        $dimension = trim((string) $dimension);

        if ($dimension !== '') {
            $dimensionPayload[] = [
                'name' => $dimension,
            ];
        }
    }

    $metricPayload = [];

    foreach ($metrics as $metric) {
        $metric = trim((string) $metric);

        if ($metric !== '') {
            $metricPayload[] = [
                'name' => $metric,
            ];
        }
    }

    $payload = [
        'dateRanges' => [
            [
                'startDate' => $startDate,
                'endDate' => $endDate,
            ],
        ],

        'metrics' => $metricPayload,

        'limit' => 100000,
    ];

    if ($dimensionPayload) {
        $payload['dimensions'] =
            $dimensionPayload;
    }

    /*
     * Allow carefully controlled additional GA4 request options later.
     */
    foreach ($extra as $key => $value) {
        $payload[$key] = $value;
    }

    $url =
        'https://analyticsdata.googleapis.com/'
        . 'v1beta/properties/'
        . rawurlencode($propertyId)
        . ':runReport';

    return ga4_request(
        $businessId,
        'POST',
        $url,
        $payload
    );
}


/**
 * First report to test with Brospro.
 *
 * No dimension means GA4 returns aggregate values for the period.
 */
function ga4_fetch_summary(
    int $businessId,
    string $startDate,
    string $endDate
): array {
    return ga4_run_report(
        $businessId,
        $startDate,
        $endDate,
        [],
        [
            'activeUsers',
            'newUsers',
            'sessions',
            'engagedSessions',
            'engagementRate',
            'screenPageViews',
        ]
    );
}

/**
 * Fetch the complete set needed for PDF/API validation.
 */
function ga4_fetch_validation_summary(
    int $businessId,
    string $startDate,
    string $endDate
): array {

    return ga4_run_report(
        $businessId,
        $startDate,
        $endDate,

        /*
         * No dimension means one aggregate total row.
         */
        [],

        [
            'activeUsers',
            'newUsers',
            'sessions',
            'engagedSessions',
            'engagementRate',
            'screenPageViews',
            'eventCount',
            'userEngagementDuration',
        ]
    );
}

/**
 * Fetch traffic/channel data.
 */
function ga4_fetch_channels(
    int $businessId,
    string $startDate,
    string $endDate
): array {
    return ga4_run_report(
        $businessId,
        $startDate,
        $endDate,
        [
            'sessionDefaultChannelGroup',
        ],
        [
            'sessions',
            'activeUsers',
            'engagedSessions',
        ],
        [
            'orderBys' => [
                [
                    'metric' => [
                        'metricName' =>
                            'sessions',
                    ],
                    'desc' => true,
                ],
            ],
        ]
    );
}


/**
 * List all Analytics properties visible to the connected account.
 *
 * We don't strictly need this for the first manually configured
 * Brospro property, but this is what Ruma/Remedy property selection
 * will use later.
 */
function ga4_list_properties(
    int $businessId
): array {
    $properties = [];

    $pageToken = null;

    do {
        $url =
            'https://analyticsadmin.googleapis.com/'
            . 'v1beta/accountSummaries?pageSize=200';

        if ($pageToken) {
            $url .=
                '&pageToken='
                . rawurlencode($pageToken);
        }

        $response = ga4_request(
            $businessId,
            'GET',
            $url
        );

        foreach (
            ($response['accountSummaries'] ?? [])
            as $account
        ) {
            foreach (
                ($account['propertySummaries'] ?? [])
                as $property
            ) {
                $resource =
                    (string) (
                        $property['property'] ?? ''
                    );

                $propertyId =
                    preg_replace(
                        '#^properties/#',
                        '',
                        $resource
                    );

                if (
                    !$propertyId ||
                    !preg_match(
                        '/^\d+$/',
                        $propertyId
                    )
                ) {
                    continue;
                }

                $properties[] = [
                    'property_id' =>
                        $propertyId,

                    'property_name' =>
                        (string) (
                            $property['displayName']
                            ?? ''
                        ),

                    'account_name' =>
                        (string) (
                            $account['displayName']
                            ?? ''
                        ),

                    'property_resource' =>
                        $resource,

                    'can_edit' =>
                        (bool) (
                            $property['canEdit']
                            ?? false
                        ),
                ];
            }
        }

        $pageToken =
            $response['nextPageToken']
            ?? null;

    } while ($pageToken);

    return $properties;
}


/**
 * Basic API connection test after the GA4 connection has been stored.
 */
function ga4_test_connection(
    int $businessId
): array {
    $response = ga4_run_report(
        $businessId,
        'yesterday',
        'yesterday',
        [],
        [
            'sessions',
            'activeUsers',
        ],
        [
            'limit' => 1,
        ]
    );

    return [
        'ok' => true,
        'property_id' =>
            ga4_business_property_id(
                $businessId
            ),
        'response' => $response,
    ];
}


/**
 * Start one sync audit record.
 */
function ga4_sync_start(
    int $businessId,
    string $startDate,
    string $endDate
): int {
    $connection = ga4_connection(
        $businessId
    );

    if (!$connection) {
        throw new RuntimeException(
            'GA4 is not connected.'
        );
    }

    $stmt = db()->prepare(
        "INSERT INTO ga4_sync_runs (
            business_id,
            ga4_connection_id,
            period_start,
            period_end,
            status,
            rows_received,
            started_at
        )
        VALUES (
            ?, ?, ?, ?, 'running', 0, NOW()
        )"
    );

    $stmt->execute([
        $businessId,
        (int) $connection['id'],
        $startDate,
        $endDate,
    ]);

    return (int) db()->lastInsertId();
}


/**
 * Mark a sync as successful.
 */
function ga4_sync_success(
    int $syncId,
    int $businessId,
    int $rowsReceived
): void {
    db()->prepare(
        "UPDATE ga4_sync_runs
         SET status = 'success',
             rows_received = ?,
             completed_at = NOW(),
             error_message = NULL
         WHERE id = ?
           AND business_id = ?"
    )->execute([
        $rowsReceived,
        $syncId,
        $businessId,
    ]);

    db()->prepare(
        "UPDATE ga4_connections
         SET last_sync_at = NOW(),
             status = 'connected'
         WHERE business_id = ?"
    )->execute([$businessId]);
}


/**
 * Mark a sync as failed.
 */
function ga4_sync_failure(
    int $syncId,
    int $businessId,
    Throwable $error
): void {
    db()->prepare(
        "UPDATE ga4_sync_runs
         SET status = 'failed',
             error_message = ?,
             completed_at = NOW()
         WHERE id = ?
           AND business_id = ?"
    )->execute([
        substr(
            $error->getMessage(),
            0,
            2000
        ),
        $syncId,
        $businessId,
    ]);
}