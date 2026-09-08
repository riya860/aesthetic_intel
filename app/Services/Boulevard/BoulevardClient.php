<?php

declare(strict_types=1);

require_once __DIR__ . '/BoulevardAuth.php';

/**
 * BoulevardClient
 *
 * Handles communication with Boulevard's 2026-06 Admin API.
 *
 * Responsibilities:
 *
 * - Generate a fresh Authorization header for every API request
 * - Send GraphQL requests
 * - Handle HTTP errors
 * - Handle GraphQL errors
 * - Retry temporary authentication failures
 * - Retry rate-limit failures
 * - Retry Boulevard server failures
 *
 * It does NOT:
 *
 * - Understand business-specific data
 * - Store anything in the database
 * - Calculate KPIs
 *
 * Those responsibilities belong elsewhere.
 */
final class BoulevardClient
{
    /**
     * Boulevard 2026-06 Admin API endpoint.
     *
     * This is Boulevard's endpoint.
     * It is NOT an endpoint created by Aesthetic Intel.
     */
    private const ENDPOINT =
        'https://dashboard.boulevard.io/api/2026-06/admin';


    /**
     * Maximum number of attempts for requests that may
     * reasonably succeed when retried.
     */
    private const MAX_ATTEMPTS = 3;


    /**
     * Connection timeout in seconds.
     */
    private const CONNECT_TIMEOUT = 10;


    /**
     * Total request timeout in seconds.
     */
    private const REQUEST_TIMEOUT = 30;


    /**
     * Boulevard configuration.
     *
     * Expected (legacy aliases remain accepted by BoulevardAuth):
     *
     * [
     *     'client_id'     => '...',
     *     'client_secret' => '...',
     *     'business_id'   => '...'
     * ]
     */
    private array $config;


    /**
     * Last request start time for this PHP process.
     * Retained as lightweight process-local pacing for diagnostics.
     */
    private static float $lastRequestStartedAt = 0.0;


    /**
     * Minimum diagnostic request spacing to reduce bursts.
     */
    private const MIN_REQUEST_INTERVAL_SECONDS = 0.10;


    /**
     * Constructor.
     */
    public function __construct(
        array $config
    ) {
        $this->validateConfig($config);

        $this->config = $config;
    }


    /**
     * Execute a Boulevard GraphQL query.
     *
     * This method controls retry behavior.
     *
     * IMPORTANT:
     * execute() reuses a valid OAuth access token and forces one fresh token
     * after an HTTP 401.
     */
    public function query(
        string $query,
        array $variables = []
    ): array {
        if (trim($query) === '') {
            throw new InvalidArgumentException(
                'Boulevard GraphQL query cannot be empty.'
            );
        }

        $authAttempt = 0;
        $temporaryAttempt = 0;

        while (true) {
            try {
                /*
                 * Boulevard legacy tokens are timestamped only to the second.
                 * Pace requests so back-to-back pages do not hammer the legacy
                 * endpoint with a burst of identical-second signed credentials.
                 * This also reduces calculated-query-cost bursts.
                 */
                self::paceRequest();

                return $this->execute(
                    $query,
                    $variables,
                    $authAttempt > 0
                );

            } catch (BoulevardAuthenticationException $e) {
                $authAttempt++;

                error_log(
                    '[Boulevard Authentication] '
                    . $e->getMessage()
                );

                /*
                 * The project has previously observed transient 401 responses
                 * from the legacy endpoint. Allow two fresh-token retries, but
                 * never allow an unlimited retry cascade.
                 */
                if ($authAttempt >= 2) {
                    throw $e;
                }

                error_log(
                    sprintf(
                        '[Boulevard] Retrying authentication failure. Attempt %d/%d.',
                        $authAttempt,
                        self::MAX_ATTEMPTS
                    )
                );

                continue;

            } catch (BoulevardRateLimitException $e) {
                $temporaryAttempt++;

                if ($temporaryAttempt >= self::MAX_ATTEMPTS) {
                    throw $e;
                }

                $waitSeconds = min(5, max(1, $temporaryAttempt * 2));

                $this->logRetry(
                    type: 'rate-limit',
                    attempt: $temporaryAttempt,
                    waitSeconds: $waitSeconds
                );

                sleep($waitSeconds);
                continue;

            } catch (BoulevardServerException $e) {
                $temporaryAttempt++;

                if ($temporaryAttempt >= self::MAX_ATTEMPTS) {
                    throw $e;
                }

                $waitSeconds = min(4, max(1, $temporaryAttempt));

                $this->logRetry(
                    type: 'server',
                    attempt: $temporaryAttempt,
                    waitSeconds: $waitSeconds
                );

                sleep($waitSeconds);
                continue;

            } catch (BoulevardNetworkException $e) {
                $temporaryAttempt++;

                if ($temporaryAttempt >= self::MAX_ATTEMPTS) {
                    throw $e;
                }

                $waitSeconds = min(4, max(1, $temporaryAttempt));

                $this->logRetry(
                    type: 'network',
                    attempt: $temporaryAttempt,
                    waitSeconds: $waitSeconds
                );

                sleep($waitSeconds);
                continue;
            }
        }
    }


    /**
     * Execute ONE HTTP request.
     *
     * IMPORTANT:
     * The Boulevard Authorization header is generated HERE,
     * not in the constructor.
     *
     * Therefore every retry gets:
     *
     * - a new timestamp
     * - a new HMAC signature
     * - a new Authorization header
     */
    private function execute(
        string $query,
        array $variables,
        bool $forceFreshToken = false
    ): array {

        /*
         * Generate fresh authentication for
         * this exact HTTP request.
         */
        $authorization =
            BoulevardAuth::authorizationHeader(
                $this->config,
                $forceFreshToken
            );


        /*
         * Build GraphQL request body.
         *
         * IMPORTANT:
         *
         * Previously:
         *
         * 'variables' => []
         *
         * produced:
         *
         * "variables": []
         *
         * Boulevard requires variables to be an object/map.
         *
         * Therefore we simply omit "variables"
         * when there aren't any.
         */
        $requestBody = [
            'query' => $query,
        ];

        if (!empty($variables)) {
            $requestBody['variables'] =
                $variables;
        }


        try {

            $payload =
                json_encode(
                    $requestBody,
                    JSON_THROW_ON_ERROR |
                    JSON_UNESCAPED_SLASHES
                );

        } catch (JsonException $e) {

            throw new RuntimeException(
                'Failed to encode Boulevard GraphQL request.',
                0,
                $e
            );
        }


        /*
         * Initialize cURL.
         */
        $ch =
            curl_init(
                self::ENDPOINT
            );


        if ($ch === false) {
            throw new BoulevardNetworkException(
                'Unable to initialize Boulevard HTTP connection.'
            );
        }


        /*
         * Configure HTTP request.
         */
        curl_setopt_array(
            $ch,
            [
                CURLOPT_POST =>
                    true,

                CURLOPT_RETURNTRANSFER =>
                    true,

                CURLOPT_CONNECTTIMEOUT =>
                    self::CONNECT_TIMEOUT,

                CURLOPT_TIMEOUT =>
                    self::REQUEST_TIMEOUT,

                CURLOPT_HTTPHEADER => [
                    'Authorization: '
                        . $authorization,

                    'Content-Type: application/json',

                    'Accept: application/json',
                ],

                CURLOPT_POSTFIELDS =>
                    $payload,

                /*
                 * SSL verification should remain enabled
                 * in both development and production.
                 */
                CURLOPT_SSL_VERIFYPEER =>
                    true,

                CURLOPT_SSL_VERIFYHOST =>
                    2,
            ]
        );


        /*
         * Execute request.
         */
        $response =
            curl_exec($ch);


        /*
         * Capture details BEFORE closing cURL.
         */
        $curlError =
            curl_error($ch);

        $curlErrno =
            curl_errno($ch);

        $status =
            (int) curl_getinfo(
                $ch,
                CURLINFO_HTTP_CODE
            );


        curl_close($ch);


        /*
         * Network / cURL failure.
         */
        if ($response === false) {

            throw new BoulevardNetworkException(
                sprintf(
                    'Boulevard network error (%d): %s',
                    $curlErrno,
                    $curlError !== ''
                        ? $curlError
                        : 'Unknown cURL error'
                )
            );
        }


        /*
         * Authentication failure.
         *
         * query() will retry this with a freshly
         * minted Boulevard OAuth access token.
         */
        if ($status === 401) {

            BoulevardAuth::clearToken($this->config);

            throw new BoulevardAuthenticationException(
                'Boulevard OAuth access token was rejected. ' . 'HTTP 401. Response: '
                . self::safeResponsePreview(
                    $response
                )
            );
        }


        /*
         * Forbidden.
         *
         * We deliberately do NOT automatically retry 403,
         * because it normally indicates an actual
         * permission/access problem.
         */
        if ($status === 403) {

            throw new BoulevardAuthorizationException(
                'Boulevard denied access to this resource. '
                . 'HTTP 403. Response: '
                . self::safeResponsePreview(
                    $response
                )
            );
        }


        /*
         * Rate limiting.
         */
        if ($status === 429) {

            throw new BoulevardRateLimitException(
                'Boulevard rate limit reached. '
                . 'HTTP 429. Response: '
                . self::safeResponsePreview(
                    $response
                )
            );
        }


        /*
         * Temporary Boulevard server-side failure.
         */
        if (
            $status >= 500 &&
            $status <= 599
        ) {

            throw new BoulevardServerException(
                'Boulevard server error. '
                . 'HTTP '
                . $status
                . '. Response: '
                . self::safeResponsePreview(
                    $response
                )
            );
        }


        /*
         * Other HTTP errors.
         *
         * Examples:
         *
         * 400 Bad Request
         * 404 Not Found
         *
         * These usually indicate an actual request/query
         * problem and should NOT automatically be retried.
         */
        if (
            $status < 200 ||
            $status >= 300
        ) {

            throw new BoulevardHttpException(
                'Boulevard HTTP error '
                . $status
                . '. Response: '
                . self::safeResponsePreview(
                    $response
                )
            );
        }


        /*
         * Decode Boulevard JSON.
         */
        try {

            $decoded =
                json_decode(
                    $response,
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );

        } catch (JsonException $e) {

            throw new BoulevardResponseException(
                'Boulevard returned invalid JSON.',
                0,
                $e
            );
        }


        if (!is_array($decoded)) {

            throw new BoulevardResponseException(
                'Boulevard returned an unexpected response format.'
            );
        }


        /*
         * GraphQL errors.
         *
         * IMPORTANT:
         *
         * GraphQL errors are NOT automatically retried.
         *
         * Examples:
         *
         * - Unknown field
         * - Invalid query argument
         * - Permission-group requirement
         *
         * Retrying these would normally produce the
         * exact same error.
         */
        if (
            isset($decoded['errors']) &&
            is_array($decoded['errors']) &&
            !empty($decoded['errors'])
        ) {

            throw new BoulevardGraphQLException(
                'Boulevard GraphQL error: '
                . json_encode(
                    $decoded['errors'],
                    JSON_UNESCAPED_SLASHES
                )
            );
        }


        /*
         * Boulevard should normally return:
         *
         * {
         *     "data": {...}
         * }
         */
        $data =
            $decoded['data'] ?? [];


        if (!is_array($data)) {

            throw new BoulevardResponseException(
                'Boulevard GraphQL response did not contain valid data.'
            );
        }


        return $data;
    }


    /**
     * Pace diagnostic requests to the Boulevard Admin API.
     *
     * This is intentionally process-local and lightweight. It does not limit
     * the total number of cursor pages; it only spaces HTTP calls.
     */
    private static function paceRequest(): void
    {
        $now = microtime(true);

        if (self::$lastRequestStartedAt > 0.0) {
            $elapsed = $now - self::$lastRequestStartedAt;
            $remaining = self::MIN_REQUEST_INTERVAL_SECONDS - $elapsed;

            if ($remaining > 0) {
                usleep((int) ceil($remaining * 1000000));
            }
        }

        self::$lastRequestStartedAt = microtime(true);
    }


    /**
     * Validate required Boulevard configuration.
     */
    private function validateConfig(
        array $config
    ): void {

        $clientId = trim((string)($config['client_id'] ?? $config['api_key'] ?? ''));
        $clientSecret = trim((string)($config['client_secret'] ?? $config['secret_key'] ?? ''));
        $businessId = trim((string)($config['business_id'] ?? ''));

        if ($clientId === '' || $clientSecret === '' || $businessId === '') {
            throw new InvalidArgumentException(
                'Boulevard OAuth client_id, client_secret, and business_id are required.'
            );
        }
    }


    /**
     * Log retry information safely.
     *
     * IMPORTANT:
     * Never log:
     *
     * - API key
     * - Secret key
     * - Authorization header
     * - Signed Boulevard token
     */
    private function logRetry(
        string $type,
        int $attempt,
        int $waitSeconds
    ): void {

        error_log(
            sprintf(
                '[Boulevard] Retrying after %s failure. '
                . 'Attempt %d/%d. Waiting %d seconds.',
                $type,
                $attempt,
                self::MAX_ATTEMPTS,
                $waitSeconds
            )
        );
    }


    /**
     * Return a limited response preview for errors.
     *
     * Prevents very large Boulevard responses from
     * being inserted into exception messages.
     */
    private static function safeResponsePreview(
        string $response
    ): string {

        $response =
            trim($response);


        if ($response === '') {
            return '[empty response]';
        }


        /*
         * Limit diagnostic response to 1000 characters.
         */
        return substr(
            $response,
            0,
            1000
        );
    }
}


/*
|--------------------------------------------------------------------------
| BOULEVARD EXCEPTIONS
|--------------------------------------------------------------------------
|
| Keeping separate exception classes allows BoulevardClient::query()
| to decide which problems should be retried and which should immediately
| stop execution.
|
*/


/**
 * Boulevard rejected the signed authentication token.
 */
class BoulevardAuthenticationException
    extends RuntimeException
{
}


/**
 * Authentication succeeded but Boulevard denied
 * permission to the requested resource.
 */
class BoulevardAuthorizationException
    extends RuntimeException
{
}


/**
 * Boulevard rate limit reached.
 */
class BoulevardRateLimitException
    extends RuntimeException
{
}


/**
 * Temporary Boulevard server-side failure.
 */
class BoulevardServerException
    extends RuntimeException
{
}


/**
 * Network / cURL failure.
 */
class BoulevardNetworkException
    extends RuntimeException
{
}


/**
 * Non-success HTTP response not covered
 * by another specific exception.
 */
class BoulevardHttpException
    extends RuntimeException
{
}


/**
 * Boulevard returned invalid/unexpected data.
 */
class BoulevardResponseException
    extends RuntimeException
{
}


/**
 * Boulevard successfully processed the HTTP request
 * but GraphQL returned one or more errors.
 */
class BoulevardGraphQLException
    extends RuntimeException
{
}