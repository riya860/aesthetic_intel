<?php

declare(strict_types=1);

require_once __DIR__ . '/BoulevardAuth.php';

/**
 * BoulevardClient
 *
 * Handles communication with Boulevard's legacy Admin API.
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
     * Boulevard legacy Admin API endpoint.
     *
     * This is Boulevard's endpoint.
     * It is NOT an endpoint created by Aesthetic Intel.
     */
    private const ENDPOINT =
        'https://dashboard.boulevard.io/api/2020-01/admin';


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
     * Expected:
     *
     * [
     *     'api_key'     => '...',
     *     'secret_key'  => '...',
     *     'business_id' => '...'
     * ]
     */
    private array $config;


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
     * execute() creates a NEW Boulevard auth token
     * for every attempt.
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

        for (
            $attempt = 1;
            $attempt <= self::MAX_ATTEMPTS;
            $attempt++
        ) {

            try {

                return $this->execute(
                    $query,
                    $variables
                );

            } catch (
                BoulevardAuthenticationException $e
            ) {

                /*
                 * Your local testing showed that Boulevard's
                 * legacy authentication can intermittently
                 * return HTTP 401 even for an otherwise valid
                 * RUMA business query.
                 *
                 * Retry using a completely new signed token.
                 */
                if (
                    $attempt >= self::MAX_ATTEMPTS
                ) {
                    throw $e;
                }

                $this->logRetry(
                    type: 'authentication',
                    attempt: $attempt,
                    waitSeconds: $attempt * 2
                );

                /*
                 * Attempt 1 failure → wait 2 sec
                 * Attempt 2 failure → wait 4 sec
                 */
                sleep(
                    $attempt * 2
                );

            } catch (
                BoulevardRateLimitException $e
            ) {

                if (
                    $attempt >= self::MAX_ATTEMPTS
                ) {
                    throw $e;
                }

                $waitSeconds =
                    $attempt * 3;

                $this->logRetry(
                    type: 'rate-limit',
                    attempt: $attempt,
                    waitSeconds: $waitSeconds
                );

                sleep(
                    $waitSeconds
                );

            } catch (
                BoulevardServerException $e
            ) {

                if (
                    $attempt >= self::MAX_ATTEMPTS
                ) {
                    throw $e;
                }

                $waitSeconds =
                    $attempt * 2;

                $this->logRetry(
                    type: 'server',
                    attempt: $attempt,
                    waitSeconds: $waitSeconds
                );

                sleep(
                    $waitSeconds
                );

            } catch (
                BoulevardNetworkException $e
            ) {

                if (
                    $attempt >= self::MAX_ATTEMPTS
                ) {
                    throw $e;
                }

                $waitSeconds =
                    $attempt * 2;

                $this->logRetry(
                    type: 'network',
                    attempt: $attempt,
                    waitSeconds: $waitSeconds
                );

                sleep(
                    $waitSeconds
                );
            }
        }

        /*
         * This should never normally execute because the
         * loop either returns successfully or throws.
         */
        throw new RuntimeException(
            'Boulevard request could not be completed.'
        );
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
        array $variables
    ): array {

        /*
         * Generate fresh authentication for
         * this exact HTTP request.
         */
        $authorization =
            BoulevardAuth::authorizationHeader(
                $this->config
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
         * generated signed Boulevard token.
         */
        if ($status === 401) {

            throw new BoulevardAuthenticationException(
                'Boulevard authentication failed. '
                . 'HTTP 401. Response: '
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
     * Validate required Boulevard configuration.
     */
    private function validateConfig(
        array $config
    ): void {

        $required = [
            'api_key',
            'secret_key',
            'business_id',
        ];


        foreach ($required as $key) {

            if (
                !isset($config[$key]) ||
                trim(
                    (string) $config[$key]
                ) === ''
            ) {

                throw new InvalidArgumentException(
                    sprintf(
                        'Boulevard configuration value "%s" is missing.',
                        $key
                    )
                );
            }
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