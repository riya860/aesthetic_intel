<?php

declare(strict_types=1);

/**
 * Standalone Boulevard 2026-06 OAuth helper.
 *
 * The main Aesthetic Intel integration uses app/boulevard-api.php. This class
 * remains for legacy diagnostic scripts/BoulevardClient and now follows the
 * same OAuth client-credentials model.
 */
final class BoulevardAuth
{
    private const TOKEN_ENDPOINT = 'https://dashboard.boulevard.io/oauth2/token';
    private const ADMIN_RESOURCE = 'https://dashboard.boulevard.io/api/2026-06/admin';

    /** @var array<string,array{access_token:string,expires_at:int}> */
    private static array $tokens = [];

    public static function authorizationHeader(
        array $config,
        bool $forceFresh = false
    ): string {
        $clientId = trim((string)($config['client_id'] ?? $config['api_key'] ?? ''));
        $clientSecret = trim((string)($config['client_secret'] ?? $config['secret_key'] ?? ''));
        $businessId = self::normalizeBusinessId((string)($config['business_id'] ?? ''));

        if ($clientId === '') {
            throw new RuntimeException('Boulevard OAuth Client ID is missing.');
        }
        if ($clientSecret === '') {
            throw new RuntimeException('Boulevard OAuth Client Secret is missing.');
        }

        $key = hash('sha256', $clientId . '|' . $businessId . '|' . self::ADMIN_RESOURCE);

        if (!$forceFresh && isset(self::$tokens[$key])) {
            $cached = self::$tokens[$key];
            if ($cached['expires_at'] > time() + 300) {
                return 'Bearer ' . $cached['access_token'];
            }
        }

        unset(self::$tokens[$key]);

        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP cURL is required for Boulevard OAuth.');
        }

        /* The Developer Portal displays the Base64 text value expected by the
         * token endpoint. Send it exactly as copied; do not encode it again. */
        $form = http_build_query([
            'grant_type' => 'client_credentials',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'business_id' => $businessId,
            'resource' => self::ADMIN_RESOURCE,
        ], '', '&', PHP_QUERY_RFC3986);

        $ch = curl_init(self::TOKEN_ENDPOINT);
        if ($ch === false) {
            throw new RuntimeException('Could not initialize Boulevard OAuth.');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
            ],
            CURLOPT_POSTFIELDS => $form,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException('Boulevard OAuth network error: ' . $error);
        }

        $json = json_decode((string)$response, true);
        if (!is_array($json)) {
            throw new RuntimeException('Boulevard OAuth returned unreadable JSON.');
        }

        if ($status < 200 || $status >= 300) {
            $code = trim((string)($json['error'] ?? ''));
            $description = trim((string)($json['error_description'] ?? $json['message'] ?? ''));

            if ($code === 'invalid_client') {
                throw new RuntimeException(
                    'Boulevard rejected the OAuth Client ID/Secret. Use credentials from the same published 2026-06 app and paste the Client Secret exactly as displayed.'
                    . ($description !== '' ? ' Boulevard response: ' . $description : '')
                );
            }
            if ($code === 'invalid_grant') {
                throw new RuntimeException(
                    'Boulevard app is not installed/authorized for this Business UUID.'
                    . ($description !== '' ? ' Boulevard response: ' . $description : '')
                );
            }

            throw new RuntimeException(
                'Boulevard OAuth failed (HTTP ' . $status . ')' . ($description !== '' ? ': ' . $description : ($code !== '' ? ': ' . $code : '.'))
            );
        }

        $token = trim((string)($json['access_token'] ?? ''));
        if ($token === '') {
            throw new RuntimeException('Boulevard OAuth did not return an access token.');
        }

        $expiresAt = time() + max(60, (int)($json['expires_in'] ?? 3600));
        self::$tokens[$key] = [
            'access_token' => $token,
            'expires_at' => $expiresAt,
        ];

        return 'Bearer ' . $token;
    }

    public static function clearToken(array $config): void
    {
        $clientId = trim((string)($config['client_id'] ?? $config['api_key'] ?? ''));
        $businessId = self::normalizeBusinessId((string)($config['business_id'] ?? ''));
        $key = hash('sha256', $clientId . '|' . $businessId . '|' . self::ADMIN_RESOURCE);
        unset(self::$tokens[$key]);
    }

    private static function normalizeBusinessId(string $value): string
    {
        $value = trim($value);
        if (str_starts_with($value, 'urn:blvd:Business:')) {
            $value = substr($value, strlen('urn:blvd:Business:'));
        }
        if (!preg_match('/^[a-f0-9-]{20,80}$/i', $value)) {
            throw new RuntimeException('Boulevard Business UUID is missing or invalid.');
        }
        return $value;
    }
}
