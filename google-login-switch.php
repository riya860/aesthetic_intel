<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/google-business-platform.php';

/**
 * Explicit Google account chooser for Aesthetic Intel login.
 *
 * This route deliberately uses Google's OpenID Connect authorization-code
 * flow with prompt=select_account. It is separate from GA4/GBP authorization:
 * only openid/email/profile are requested here.
 */

try {
    if (auth_check()) {
        redirect(
            auth_is_admin()
                ? url('admin-dashboard')
                : url('business-dashboard')
        );
    }

    googlehub_assert_configured();

    $clientId = googlehub_login_client_id();
    if ($clientId === '') {
        throw new RuntimeException(
            'GOOGLE_LOGIN_CLIENT_ID is not configured.'
        );
    }

    /*
     * A server-side authorization-code exchange requires a client secret.
     *
     * During your current setup the login and data OAuth clients are the same,
     * so GOOGLE_OAUTH_CLIENT_SECRET is reused safely.
     *
     * If you later separate the clients, define GOOGLE_LOGIN_CLIENT_SECRET in
     * app/private/google-platform.php.
     */
    $clientSecret = googlehub_cfg('GOOGLE_LOGIN_CLIENT_SECRET');

    if (
        $clientSecret === ''
        && hash_equals(
            googlehub_oauth_client_id(),
            $clientId
        )
    ) {
        $clientSecret = googlehub_oauth_client_secret();
    }

    if ($clientSecret === '') {
        throw new RuntimeException(
            'A Google login client secret is required for the explicit '
            . 'account-switch flow. Define GOOGLE_LOGIN_CLIENT_SECRET, or use '
            . 'the same Web OAuth client for login and integration OAuth.'
        );
    }

    /*
     * Remove stale pre-signup state before deliberately choosing a different
     * Google identity.
     */
    unset($_SESSION['_google_signup_pending']);

    $state = bin2hex(random_bytes(32));
    $nonce = bin2hex(random_bytes(32));

    $_SESSION['_google_login_switch_states'][$state] = [
        'nonce' => $nonce,
        'created_at' => time(),
    ];

    /*
     * Keep only recent states so repeated/cancelled login attempts cannot grow
     * the session indefinitely.
     */
    foreach (
        (array)($_SESSION['_google_login_switch_states'] ?? [])
        as $savedState => $saved
    ) {
        if (
            !is_array($saved)
            || (int)($saved['created_at'] ?? 0) < time() - 900
        ) {
            unset(
                $_SESSION['_google_login_switch_states'][$savedState]
            );
        }
    }

    $redirectUri =
        googlehub_base_url()
        . '/google-login-switch-callback.php';

    $params = [
        'client_id' => $clientId,
        'redirect_uri' => $redirectUri,
        'response_type' => 'code',
        'scope' => 'openid email profile',
        'prompt' => 'select_account',
        'include_granted_scopes' => 'false',
        'state' => $state,
        'nonce' => $nonce,
    ];

    redirect(
        'https://accounts.google.com/o/oauth2/v2/auth?'
        . http_build_query(
            $params,
            '',
            '&',
            PHP_QUERY_RFC3986
        )
    );

} catch (Throwable $e) {
    error_log(
        '[Google login / account switch start] '
        . $e->getMessage()
    );

    flash(
        'error',
        'Could not open Google account selection: '
        . $e->getMessage()
    );

    redirect(url('login'));
}
