<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/google-business-platform.php';

/**
 * Callback for the explicit "Use another Google account" login flow.
 */

function aesthetic_google_switch_dashboard_url(): string
{
    return auth_is_admin()
        ? url('admin-dashboard')
        : url('business-dashboard');
}

/**
 * Auto-linking by matching email is intentionally conservative.
 *
 * Gmail addresses are controlled by Google. For a Google Workspace address,
 * the signed ID token must contain an hd claim matching the email domain.
 */
function aesthetic_google_switch_can_auto_link(
    array $claims,
    array $user
): bool {
    if (
        empty($claims['email_verified'])
        || (string)($user['status'] ?? '') !== 'active'
    ) {
        return false;
    }

    $googleEmail = strtolower(
        trim((string)($claims['email'] ?? ''))
    );

    $localEmail = strtolower(
        trim((string)($user['email'] ?? ''))
    );

    if (
        $googleEmail === ''
        || $localEmail === ''
        || !hash_equals($localEmail, $googleEmail)
        || !str_contains($googleEmail, '@')
    ) {
        return false;
    }

    $domain = substr(
        $googleEmail,
        strrpos($googleEmail, '@') + 1
    );

    if ($domain === 'gmail.com') {
        return true;
    }

    $hostedDomain = strtolower(
        trim((string)($claims['hd'] ?? ''))
    );

    return $hostedDomain !== ''
        && hash_equals($domain, $hostedDomain);
}

try {
    if (auth_check()) {
        redirect(aesthetic_google_switch_dashboard_url());
    }

    $googleError = trim(
        (string)($_GET['error'] ?? '')
    );

    if ($googleError !== '') {
        $description = trim(
            (string)($_GET['error_description'] ?? '')
        );

        if ($googleError === 'access_denied') {
            throw new RuntimeException(
                'Google did not authorize this account. If the Google OAuth '
                . 'app is still in Testing, add this email under '
                . 'Google Auth Platform → Audience → Test users and try again.'
                . ($description !== ''
                    ? ' Google response: ' . $description
                    : '')
            );
        }

        throw new RuntimeException(
            'Google returned '
            . $googleError
            . ($description !== ''
                ? ': ' . $description
                : '.')
        );
    }

    $state = trim(
        (string)($_GET['state'] ?? '')
    );

    $code = trim(
        (string)($_GET['code'] ?? '')
    );

    if ($state === '' || $code === '') {
        throw new RuntimeException(
            'Google account selection did not return a valid authorization '
            . 'code.'
        );
    }

    $states = is_array(
        $_SESSION['_google_login_switch_states'] ?? null
    )
        ? $_SESSION['_google_login_switch_states']
        : [];

    $saved = $states[$state] ?? null;

    unset(
        $_SESSION['_google_login_switch_states'][$state]
    );

    if (
        !is_array($saved)
        || (int)($saved['created_at'] ?? 0)
            < time() - 900
    ) {
        throw new RuntimeException(
            'Google login state is invalid or expired. Start again.'
        );
    }

    $clientId = googlehub_login_client_id();

    $clientSecret =
        googlehub_cfg('GOOGLE_LOGIN_CLIENT_SECRET');

    if (
        $clientSecret === ''
        && hash_equals(
            googlehub_oauth_client_id(),
            $clientId
        )
    ) {
        $clientSecret =
            googlehub_oauth_client_secret();
    }

    if ($clientId === '' || $clientSecret === '') {
        throw new RuntimeException(
            'Google login OAuth credentials are incomplete.'
        );
    }

    $redirectUri =
        googlehub_base_url()
        . '/google-login-switch-callback.php';

    $token = googlehub_form_http(
        'https://oauth2.googleapis.com/token',
        [
            'code' => $code,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code',
        ]
    );

    $idToken = trim(
        (string)($token['id_token'] ?? '')
    );

    if ($idToken === '') {
        throw new RuntimeException(
            'Google did not return an ID token for the selected account.'
        );
    }

    $claims =
        googlehub_verify_id_token(
            $idToken,
            $clientId
        );

    $expectedNonce =
        trim((string)($saved['nonce'] ?? ''));

    $actualNonce =
        trim((string)($claims['nonce'] ?? ''));

    if (
        $expectedNonce === ''
        || $actualNonce === ''
        || !hash_equals(
            $expectedNonce,
            $actualNonce
        )
    ) {
        throw new RuntimeException(
            'Google login nonce validation failed.'
        );
    }

    /*
     * 1. Already-linked Google identity -> normal dashboard.
     */
    $identity =
        googlehub_identity_by_sub(
            (string)$claims['sub']
        );

    if ($identity) {
        googlehub_login_user(
            (int)$identity['user_id']
        );

        if (function_exists('audit')) {
            audit(
                'user_login_google_account_switch',
                [
                    'google_email' =>
                        $claims['email'] ?? null,
                ],
                (int)($identity['business_id'] ?? 0)
                    ?: null
            );
        }

        flash(
            'success',
            'Signed in with '
            . (string)$claims['email']
            . '.'
        );

        redirect(aesthetic_google_switch_dashboard_url());
    }

    /*
     * 2. The Google identity is new, but an active Aesthetic Intel account
     *    already uses the same authoritative Google email -> safely link it.
     */
    $existing =
        googlehub_existing_user_by_email(
            (string)$claims['email']
        );

    if (
        $existing
        && aesthetic_google_switch_can_auto_link(
            $claims,
            $existing
        )
    ) {
        googlehub_link_identity(
            (int)$existing['id'],
            $claims
        );

        googlehub_login_user(
            (int)$existing['id']
        );

        if (function_exists('audit')) {
            audit(
                'google_identity_linked_existing_account_switch',
                [
                    'google_email' =>
                        $claims['email'] ?? null,
                ],
                (int)($existing['business_id'] ?? 0)
                    ?: null
            );
        }

        flash(
            'success',
            'Google was linked to your existing Aesthetic Intel account.'
        );

        redirect(aesthetic_google_switch_dashboard_url());
    }

    /*
     * 3. Matching local account exists but automatic linking is not safe.
     *    Do not create a duplicate business/user.
     */
    if ($existing) {
        throw new RuntimeException(
            'An Aesthetic Intel account already exists for '
            . (string)$claims['email']
            . '. For security, sign in with that account\'s existing password '
            . 'first, then link Google from Google Connections.'
        );
    }

    /*
     * 4. Completely new Google account -> existing business-onboarding flow.
     */
    googlehub_pending_signup_set($claims);

    flash(
        'success',
        'Google account selected: '
        . (string)$claims['email']
        . '. Complete business setup to continue.'
    );

    redirect(url('google-onboarding'));

} catch (Throwable $e) {
    error_log(
        '[Google login / account switch callback] '
        . $e->getMessage()
    );

    flash(
        'error',
        'Google account sign-in failed: '
        . $e->getMessage()
    );

    redirect(url('login'));
}
