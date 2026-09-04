<?php

use Google\Client as GoogleClient;

/**
 * Load Google/GA4 configuration.
 */
function ga4_config(?string $key = null, mixed $default = null): mixed
{
    static $config = null;

    if ($config === null) {
        $file = ROOT_PATH . '/config/google.php';

        if (!is_file($file)) {
            throw new RuntimeException(
                'Google configuration file is missing: config/google.php'
            );
        }

        $config = require $file;

        if (!is_array($config)) {
            throw new RuntimeException(
                'Google configuration file must return an array.'
            );
        }
    }

    if ($key === null) {
        return $config;
    }

    return $config[$key] ?? $default;
}


/**
 * Create the Google OAuth client used by Aesthetic Intel.
 */
function ga4_google_client(): GoogleClient
{
    $clientId = trim((string) ga4_config('client_id'));
    $clientSecret = trim((string) ga4_config('client_secret'));
    $redirectUri = trim((string) ga4_config('redirect_uri'));
    $scope = trim(
        (string) ga4_config(
            'scope',
            'https://www.googleapis.com/auth/analytics.readonly'
        )
    );

    if ($clientId === '' || $clientSecret === '' || $redirectUri === '') {
        throw new RuntimeException(
            'Google OAuth configuration is incomplete.'
        );
    }

    $client = new GoogleClient();

    $client->setClientId($clientId);
    $client->setClientSecret($clientSecret);
    $client->setRedirectUri($redirectUri);

    $client->setScopes([
        $scope,
    ]);

    /*
     * Offline access is important because later Hostinger cron jobs
     * need to refresh GA4 access without the user logging in again.
     */
    $client->setAccessType('offline');

    /*
     * Include permissions previously approved by the user.
     */
    $client->setIncludeGrantedScopes(true);

    return $client;
}


/**
 * Encrypt GA4 secrets.
 *
 * Aesthetic Intel already has encryption logic for API secrets through
 * ai_encrypt_secret(). Reuse it for now rather than inventing a second
 * encryption mechanism.
 */
function ga4_encrypt_secret(string $value): string
{
    if (!function_exists('ai_encrypt_secret')) {
        throw new RuntimeException(
            'Aesthetic Intel encryption service is unavailable.'
        );
    }

    return ai_encrypt_secret($value);
}


/**
 * Decrypt GA4 secrets.
 */
function ga4_decrypt_secret(?string $value): ?string
{
    if (!$value) {
        return null;
    }

    if (!function_exists('ai_decrypt_secret')) {
        throw new RuntimeException(
            'Aesthetic Intel decryption service is unavailable.'
        );
    }

    $plain = ai_decrypt_secret($value);

    return $plain !== false && $plain !== ''
        ? (string) $plain
        : null;
}


/**
 * Get the GA4 connection belonging to one Aesthetic Intel business.
 */
function ga4_connection(int $businessId): ?array
{
    $stmt = db()->prepare(
        'SELECT *
         FROM ga4_connections
         WHERE business_id = ?
         LIMIT 1'
    );

    $stmt->execute([$businessId]);

    $row = $stmt->fetch();

    return $row ?: null;
}


/**
 * Validate a GA4 numeric Property ID.
 */
function ga4_normalize_property_id(string $propertyId): string
{
    $propertyId = trim($propertyId);

    /*
     * Allow either:
     * 123456789
     * or
     * properties/123456789
     */
    $propertyId = preg_replace(
        '#^properties/#',
        '',
        $propertyId
    );

    if (
        !$propertyId ||
        !preg_match('/^\d+$/', $propertyId)
    ) {
        throw new RuntimeException(
            'Enter a valid numeric GA4 Property ID.'
        );
    }

    return $propertyId;
}


/**
 * Start OAuth authorization.
 *
 * For the first Brospro pilot we already know the Property ID, so
 * it is stored in the session before redirecting to Google.
 */
function ga4_authorization_url(
    int $businessId,
    string $propertyId,
    ?string $propertyName = null
): string {
    if ($businessId <= 0) {
        throw new RuntimeException(
            'A valid Aesthetic Intel business is required.'
        );
    }

    $propertyId = ga4_normalize_property_id($propertyId);

    $client = ga4_google_client();

    /*
     * CSRF protection for the OAuth round trip.
     */
    $state = bin2hex(random_bytes(32));

    $_SESSION['_ga4_oauth'] = [
        'state' => $state,
        'business_id' => $businessId,
        'property_id' => $propertyId,
        'property_name' => trim((string) $propertyName),
        'started_at' => time(),
    ];

    $client->setState($state);

    /*
     * During first connection we want Google to return a refresh token.
     *
     * Google normally returns the refresh token when offline access
     * is granted. Prompting for consent is useful during this pilot.
     */
    $client->setPrompt('consent');

    return $client->createAuthUrl();
}


/**
 * Complete the Google OAuth callback and save the connection.
 */
function ga4_handle_callback(
    string $authorizationCode,
    string $returnedState
): array {
    $oauth = $_SESSION['_ga4_oauth'] ?? null;

    if (!is_array($oauth)) {
        throw new RuntimeException(
            'The Google Analytics connection session has expired. Start the connection again.'
        );
    }

    $expectedState = (string) ($oauth['state'] ?? '');

    if (
        $expectedState === '' ||
        $returnedState === '' ||
        !hash_equals($expectedState, $returnedState)
    ) {
        unset($_SESSION['_ga4_oauth']);

        throw new RuntimeException(
            'Google OAuth security validation failed. Please reconnect.'
        );
    }

    /*
     * Do not allow an OAuth initiation session to remain valid forever.
     */
    $startedAt = (int) ($oauth['started_at'] ?? 0);

    if (
        $startedAt <= 0 ||
        time() - $startedAt > 900
    ) {
        unset($_SESSION['_ga4_oauth']);

        throw new RuntimeException(
            'Google authorization expired. Start the connection again.'
        );
    }

    $businessId = (int) ($oauth['business_id'] ?? 0);
    $propertyId = ga4_normalize_property_id(
        (string) ($oauth['property_id'] ?? '')
    );
    $propertyName = trim(
        (string) ($oauth['property_name'] ?? '')
    );

    if ($businessId <= 0) {
        throw new RuntimeException(
            'Aesthetic Intel business context was lost.'
        );
    }

    $client = ga4_google_client();

    $token = $client->fetchAccessTokenWithAuthCode(
        $authorizationCode
    );

    if (
        isset($token['error']) ||
        empty($token['access_token'])
    ) {
        $message =
            (string) (
                $token['error_description']
                ?? $token['error']
                ?? 'Google authorization failed.'
            );

        throw new RuntimeException($message);
    }

    $refreshToken =
        (string) ($token['refresh_token'] ?? '');

    /*
     * If reconnecting, Google may not always return another refresh
     * token. Preserve the currently stored one if necessary.
     */
    $existing = ga4_connection($businessId);

    if (
        $refreshToken === '' &&
        $existing &&
        !empty($existing['refresh_token_encrypted'])
    ) {
        $refreshToken = (string)
            ga4_decrypt_secret(
                $existing['refresh_token_encrypted']
            );
    }

    if ($refreshToken === '') {
        throw new RuntimeException(
            'Google did not return a refresh token. Reconnect and approve Analytics access again.'
        );
    }

    /*
     * Verify access to the requested Brospro property before saving
     * the connection permanently.
     */
    $client->setAccessToken($token);

    ga4_verify_property_access_with_token(
        $client,
        $propertyId
    );

    $encryptedRefreshToken =
        ga4_encrypt_secret($refreshToken);

    $stmt = db()->prepare(
        "INSERT INTO ga4_connections (
            business_id,
            property_id,
            property_name,
            refresh_token_encrypted,
            status,
            connected_at,
            last_sync_at
        )
        VALUES (
            ?, ?, ?, ?, 'connected', NOW(), NULL
        )
        ON DUPLICATE KEY UPDATE
            property_id = VALUES(property_id),
            property_name = VALUES(property_name),
            refresh_token_encrypted = VALUES(refresh_token_encrypted),
            status = 'connected',
            connected_at = NOW(),
            updated_at = CURRENT_TIMESTAMP"
    );

    $stmt->execute([
        $businessId,
        $propertyId,
        $propertyName !== ''
            ? $propertyName
            : null,
        $encryptedRefreshToken,
    ]);

    unset($_SESSION['_ga4_oauth']);

    audit(
        'ga4_connected',
        [
            'property_id' => $propertyId,
            'property_name' => $propertyName,
        ],
        $businessId
    );

    return ga4_connection($businessId)
        ?? throw new RuntimeException(
            'GA4 connection could not be saved.'
        );
}


/**
 * Produce a fresh access token from the stored refresh token.
 */
function ga4_access_token(int $businessId): string
{
    $connection = ga4_connection($businessId);

    if (!$connection) {
        throw new RuntimeException(
            'Google Analytics is not connected for this business.'
        );
    }

    if (
        (string) ($connection['status'] ?? '')
        !== 'connected'
    ) {
        throw new RuntimeException(
            'The Google Analytics connection is not active.'
        );
    }

    $refreshToken = ga4_decrypt_secret(
        $connection['refresh_token_encrypted']
            ?? null
    );

    if (!$refreshToken) {
        throw new RuntimeException(
            'The saved Google Analytics refresh token is unavailable.'
        );
    }

    $client = ga4_google_client();

    $token = $client->fetchAccessTokenWithRefreshToken(
        $refreshToken
    );

    if (
        isset($token['error']) ||
        empty($token['access_token'])
    ) {
        $message =
            (string) (
                $token['error_description']
                ?? $token['error']
                ?? 'Unable to refresh Google Analytics access.'
            );

        db()->prepare(
            "UPDATE ga4_connections
             SET status = 'failed'
             WHERE business_id = ?"
        )->execute([$businessId]);

        throw new RuntimeException($message);
    }

    return (string) $token['access_token'];
}


/**
 * Remove the OAuth connection.
 *
 * Existing imported analytics data is intentionally left untouched.
 */
function ga4_disconnect(int $businessId): void
{
    db()->prepare(
        'DELETE FROM ga4_connections
         WHERE business_id = ?'
    )->execute([$businessId]);

    audit(
        'ga4_disconnected',
        [],
        $businessId
    );
}