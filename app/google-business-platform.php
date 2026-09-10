<?php

declare(strict_types=1);

/**
 * Aesthetic Intel - Google Business Platform
 *
 * Adds:
 * - Sign in / sign up with Google Identity Services
 * - self-service business onboarding
 * - GA4 OAuth + property discovery + daily sync
 * - Google Business Profile OAuth + location discovery + daily performance sync
 *
 * This module intentionally uses its own google_* tables so it does not
 * disturb the existing GA4 test integration or manual GBP workflow.
 */

$googlePrivateConfig = ROOT_PATH . '/app/private/google-platform.php';
if (is_file($googlePrivateConfig)) {
    require_once $googlePrivateConfig;
}

$vendorAutoload = ROOT_PATH . '/vendor/autoload.php';
if (is_file($vendorAutoload)) {
    require_once $vendorAutoload;
}

function googlehub_esc(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function googlehub_cfg(string $name, string $default = ''): string
{
    if (defined($name)) {
        return trim((string)constant($name));
    }

    $value = getenv($name);
    return $value === false ? $default : trim((string)$value);
}

function googlehub_login_client_id(): string
{
    return googlehub_cfg('GOOGLE_LOGIN_CLIENT_ID');
}

function googlehub_oauth_client_id(): string
{
    return googlehub_cfg('GOOGLE_OAUTH_CLIENT_ID');
}

function googlehub_oauth_client_secret(): string
{
    return googlehub_cfg('GOOGLE_OAUTH_CLIENT_SECRET');
}

function googlehub_base_url(): string
{
    $configured = rtrim(googlehub_cfg('GOOGLE_APP_BASE_URL'), '/');
    if ($configured !== '') {
        return $configured;
    }

    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $scheme = $https ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? '127.0.0.1:8000');
    return $scheme . '://' . $host;
}

function googlehub_absolute_url(string $page, array $params = []): string
{
    return googlehub_base_url()
        . '/index.php?'
        . http_build_query(['page' => $page] + $params);
}

function googlehub_assert_configured(): void
{
    $missing = [];
    foreach ([
        'GOOGLE_LOGIN_CLIENT_ID' => googlehub_login_client_id(),
        'GOOGLE_OAUTH_CLIENT_ID' => googlehub_oauth_client_id(),
        'GOOGLE_OAUTH_CLIENT_SECRET' => googlehub_oauth_client_secret(),
    ] as $name => $value) {
        if ($value === '') {
            $missing[] = $name;
        }
    }

    if ($missing) {
        throw new RuntimeException(
            'Google platform is not configured. Set: ' . implode(', ', $missing)
        );
    }
}

function googlehub_http(
    string $method,
    string $url,
    array|string|null $body = null,
    array $headers = [],
    int $timeout = 30
): array {
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL is required for Google integration.');
    }

    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Could not initialize HTTP request.');
    }

    $method = strtoupper($method);
    $curlHeaders = ['Accept: application/json'];

    $payload = null;
    if (is_array($body)) {
        $payload = json_encode($body, JSON_UNESCAPED_SLASHES);
        $curlHeaders[] = 'Content-Type: application/json';
    } elseif (is_string($body)) {
        $payload = $body;
    }

    foreach ($headers as $header) {
        $curlHeaders[] = $header;
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $curlHeaders,
        CURLOPT_HEADER => false,
    ]);

    if ($payload !== null && $method !== 'GET') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    }

    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $errno !== 0) {
        throw new RuntimeException('Google HTTP request failed: ' . $error);
    }

    $decoded = json_decode((string)$raw, true);
    if (!is_array($decoded)) {
        $decoded = [];
    }

    if ($status < 200 || $status >= 300) {
        $message = (string)(
            $decoded['error']['message']
            ?? $decoded['error_description']
            ?? $decoded['error']
            ?? ('HTTP ' . $status)
        );
        throw new RuntimeException('Google API: ' . $message);
    }

    return $decoded;
}

function googlehub_form_http(string $url, array $form): array
{
    $payload = http_build_query($form, '', '&', PHP_QUERY_RFC3986);
    return googlehub_http(
        'POST',
        $url,
        $payload,
        ['Content-Type: application/x-www-form-urlencoded'],
        30
    );
}

function googlehub_encrypt(string $plain): string
{
    if ($plain === '') {
        return '';
    }

    if (function_exists('ai_encrypt_secret')) {
        return (string)ai_encrypt_secret($plain);
    }

    $secret = googlehub_cfg('GOOGLE_TOKEN_ENCRYPTION_KEY');
    if ($secret === '') {
        throw new RuntimeException(
            'GOOGLE_TOKEN_ENCRYPTION_KEY is required because the project encryption helper is unavailable.'
        );
    }

    $key = hash('sha256', $secret, true);
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt(
        $plain,
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );

    if ($cipher === false) {
        throw new RuntimeException('Could not encrypt Google token.');
    }

    return 'g1:' . base64_encode($iv . $tag . $cipher);
}

function googlehub_decrypt(?string $encrypted): string
{
    $encrypted = (string)$encrypted;
    if ($encrypted === '') {
        return '';
    }

    if (function_exists('ai_decrypt_secret') && !str_starts_with($encrypted, 'g1:')) {
        return (string)(ai_decrypt_secret($encrypted) ?: '');
    }

    if (!str_starts_with($encrypted, 'g1:')) {
        return '';
    }

    $secret = googlehub_cfg('GOOGLE_TOKEN_ENCRYPTION_KEY');
    if ($secret === '') {
        return '';
    }

    $raw = base64_decode(substr($encrypted, 3), true);
    if ($raw === false || strlen($raw) < 29) {
        return '';
    }

    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $cipher = substr($raw, 28);
    $key = hash('sha256', $secret, true);

    $plain = openssl_decrypt(
        $cipher,
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );

    return $plain === false ? '' : $plain;
}

function googlehub_verify_id_token(string $credential, ?string $expectedAudience = null): array
{
    $credential = trim($credential);
    if ($credential === '') {
        throw new RuntimeException('Google did not return an ID token.');
    }

    $expectedAudience = $expectedAudience ?: googlehub_login_client_id();

    // Preferred verification path when google/apiclient is installed.
    try {
        if (class_exists('Google\\Client')) {
            $client = new \Google\Client(['client_id' => $expectedAudience]);
            $payload = $client->verifyIdToken($credential);
            if (is_array($payload)) {
                return googlehub_validate_google_claims($payload, $expectedAudience);
            }
        }

        if (class_exists('Google_Client')) {
            $client = new Google_Client(['client_id' => $expectedAudience]);
            $payload = $client->verifyIdToken($credential);
            if (is_array($payload)) {
                return googlehub_validate_google_claims($payload, $expectedAudience);
            }
        }
    } catch (Throwable $e) {
        error_log('[Google login / local token verify] ' . $e->getMessage());
    }

    // Fallback: Google's token-info endpoint verifies signature and token state.
    $payload = googlehub_http(
        'GET',
        'https://oauth2.googleapis.com/tokeninfo?id_token=' . rawurlencode($credential)
    );

    return googlehub_validate_google_claims($payload, $expectedAudience);
}

function googlehub_validate_google_claims(array $payload, string $expectedAudience): array
{
    $aud = (string)($payload['aud'] ?? '');
    $iss = (string)($payload['iss'] ?? '');
    $sub = trim((string)($payload['sub'] ?? ''));
    $email = strtolower(trim((string)($payload['email'] ?? '')));
    $verified = filter_var($payload['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $exp = (int)($payload['exp'] ?? 0);

    if ($aud === '' || !hash_equals($expectedAudience, $aud)) {
        throw new RuntimeException('Google ID token audience is invalid.');
    }

    if (!in_array($iss, ['accounts.google.com', 'https://accounts.google.com'], true)) {
        throw new RuntimeException('Google ID token issuer is invalid.');
    }

    if ($exp > 0 && $exp < time() - 30) {
        throw new RuntimeException('Google ID token has expired.');
    }

    if ($sub === '' || $email === '' || !$verified) {
        throw new RuntimeException('Google account email could not be verified.');
    }

    $payload['sub'] = $sub;
    $payload['email'] = $email;
    $payload['email_verified'] = true;
    return $payload;
}

function googlehub_gis_csrf_enforce(): void
{
    $cookie = (string)($_COOKIE['g_csrf_token'] ?? '');
    $posted = (string)($_POST['g_csrf_token'] ?? '');

    if ($cookie === '' || $posted === '' || !hash_equals($cookie, $posted)) {
        throw new RuntimeException('Google sign-in CSRF validation failed.');
    }
}

function googlehub_identity_by_sub(string $sub): ?array
{
    $stmt = db()->prepare(
        "SELECT gi.*, u.business_id, u.name user_name, u.email user_email,
                u.role, u.provider_kpi_role, u.status user_status,
                u.must_change_password
         FROM google_user_identities gi
         JOIN users u ON u.id = gi.user_id
         WHERE gi.provider = 'google'
           AND gi.provider_subject = ?
         LIMIT 1"
    );
    $stmt->execute([$sub]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function googlehub_identity_for_user(int $userId): ?array
{
    $stmt = db()->prepare(
        "SELECT * FROM google_user_identities
         WHERE user_id = ? AND provider = 'google'
         LIMIT 1"
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function googlehub_existing_user_by_email(string $email): ?array
{
    $stmt = db()->prepare('SELECT * FROM users WHERE LOWER(email)=LOWER(?) LIMIT 1');
    $stmt->execute([$email]);
    $row = $stmt->fetch();
    return $row ?: null;
}


/**
 * Google is authoritative for @gmail.com accounts. For custom-domain Google
 * Workspace accounts, require the ID token's hosted-domain (hd) claim to match
 * the email domain before we auto-link an existing Aesthetic Intel account.
 *
 * This prevents silently linking an existing local account solely because an
 * arbitrary third-party Google identity currently controls the same email.
 */
function googlehub_google_is_authoritative_for_email(array $claims): bool
{
    if (empty($claims['email_verified'])) {
        return false;
    }

    $email = strtolower(trim((string)($claims['email'] ?? '')));
    if ($email === '' || !str_contains($email, '@')) {
        return false;
    }

    $domain = substr($email, strrpos($email, '@') + 1);

    if ($domain === 'gmail.com') {
        return true;
    }

    $hostedDomain = strtolower(trim((string)($claims['hd'] ?? '')));

    return $hostedDomain !== ''
        && hash_equals($domain, $hostedDomain);
}

function googlehub_can_auto_link_existing_user(
    array $claims,
    array $user
): bool {
    $googleEmail = strtolower(trim((string)($claims['email'] ?? '')));
    $userEmail = strtolower(trim((string)($user['email'] ?? '')));

    if (
        $googleEmail === ''
        || $userEmail === ''
        || !hash_equals($userEmail, $googleEmail)
    ) {
        return false;
    }

    if ((string)($user['status'] ?? '') !== 'active') {
        return false;
    }

    return googlehub_google_is_authoritative_for_email($claims);
}

/**
 * Return the existing account that matches the pending Google signup email.
 * The returned row is only a UI hint; direct linking still goes through
 * googlehub_can_auto_link_existing_user().
 */
function googlehub_pending_existing_user(): ?array
{
    $pending = googlehub_pending_signup();

    if (!$pending) {
        return null;
    }

    return googlehub_existing_user_by_email(
        (string)($pending['email'] ?? '')
    );
}

/**
 * Safely turn a pending Google identity into an existing-account login.
 * This is used by the "Open existing dashboard" option.
 */
function googlehub_use_existing_account_from_pending(): array
{
    $pending = googlehub_pending_signup();

    if (!$pending) {
        throw new RuntimeException(
            'Google sign-in session expired. Start again from the sign-in page.'
        );
    }

    $user = googlehub_existing_user_by_email(
        (string)($pending['email'] ?? '')
    );

    if (!$user) {
        throw new RuntimeException(
            'No existing Aesthetic Intel account was found for this Google email.'
        );
    }

    if (!googlehub_can_auto_link_existing_user($pending, $user)) {
        throw new RuntimeException(
            'For security, this Google account cannot be linked automatically. '
            . 'Sign in with your existing Aesthetic Intel password first, then '
            . 'link Google from Google Connections.'
        );
    }

    googlehub_link_identity((int)$user['id'], $pending);
    unset($_SESSION['_google_signup_pending']);

    googlehub_login_user((int)$user['id']);

    return $user;
}

function googlehub_link_identity(int $userId, array $claims): void
{
    $sub = (string)$claims['sub'];
    $email = (string)$claims['email'];
    $name = trim((string)($claims['name'] ?? ''));
    $picture = trim((string)($claims['picture'] ?? ''));

    $existing = googlehub_identity_by_sub($sub);
    if ($existing && (int)$existing['user_id'] !== $userId) {
        throw new RuntimeException('This Google account is already linked to another Aesthetic Intel user.');
    }

    $stmt = db()->prepare(
        "INSERT INTO google_user_identities
            (user_id, provider, provider_subject, provider_email,
             display_name, picture_url, created_at, last_login_at)
         VALUES (?, 'google', ?, ?, ?, ?, NOW(), NOW())
         ON DUPLICATE KEY UPDATE
            provider_email = VALUES(provider_email),
            display_name = VALUES(display_name),
            picture_url = VALUES(picture_url),
            last_login_at = NOW()"
    );
    $stmt->execute([$userId, $sub, $email, $name ?: null, $picture ?: null]);
}

function googlehub_login_user(int $userId): void
{
    $stmt = db()->prepare('SELECT * FROM users WHERE id=? LIMIT 1');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user || (string)($user['status'] ?? '') !== 'active') {
        throw new RuntimeException('This Aesthetic Intel account is not active.');
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    session_regenerate_id(true);

    // Match the session shape used by the existing auth helpers.
    $_SESSION['auth_user'] = $user;
    $_SESSION['auth_user']['id'] = (int)$user['id'];
    $_SESSION['auth_user']['business_id'] = $user['business_id'] === null
        ? null
        : (int)$user['business_id'];
    $_SESSION['auth_user']['must_change_password'] = 0;

    db()->prepare(
        "UPDATE google_user_identities
         SET last_login_at = NOW()
         WHERE user_id = ? AND provider = 'google'"
    )->execute([$userId]);
}

function googlehub_pending_signup_set(array $claims): void
{
    $_SESSION['_google_signup_pending'] = [
        'sub' => (string)$claims['sub'],
        'email' => strtolower(trim((string)$claims['email'])),
        'email_verified' => !empty($claims['email_verified']),
        'hd' => strtolower(trim((string)($claims['hd'] ?? ''))),
        'name' => trim((string)($claims['name'] ?? '')),
        'picture' => trim((string)($claims['picture'] ?? '')),
        'created_at' => time(),
    ];
}

function googlehub_pending_signup(): ?array
{
    $pending = $_SESSION['_google_signup_pending'] ?? null;
    if (!is_array($pending)) {
        return null;
    }

    if ((int)($pending['created_at'] ?? 0) < time() - 1800) {
        unset($_SESSION['_google_signup_pending']);
        return null;
    }

    return $pending;
}

function googlehub_slug(string $name): string
{
    $slug = strtolower(trim((string)preg_replace('/[^a-z0-9]+/i', '-', $name), '-'));
    if ($slug === '') {
        $slug = 'business';
    }

    $base = substr($slug, 0, 80);
    $candidate = $base;
    $i = 1;

    while (true) {
        $stmt = db()->prepare('SELECT id FROM businesses WHERE slug=? LIMIT 1');
        $stmt->execute([$candidate]);
        if (!$stmt->fetchColumn()) {
            return $candidate;
        }
        $i++;
        $candidate = substr($base, 0, 70) . '-' . $i;
    }
}

function googlehub_create_business_from_pending(array $input): array
{
    $pending = googlehub_pending_signup();
    if (!$pending) {
        throw new RuntimeException('Google signup session expired. Start again from the sign-in page.');
    }

    $businessName = trim((string)($input['business_name'] ?? ''));
    $timezone = trim((string)($input['timezone'] ?? 'America/Denver'));
    $phone = trim((string)($input['phone'] ?? ''));

    if ($businessName === '') {
        throw new RuntimeException('Business name is required.');
    }

    if (!in_array($timezone, timezone_identifiers_list(), true)) {
        throw new RuntimeException('Choose a valid business timezone.');
    }

    $email = (string)$pending['email'];
    if (googlehub_existing_user_by_email($email)) {
        throw new RuntimeException(
            'An Aesthetic Intel account already exists for this email. Sign in with your password first, then link Google from Google Connections.'
        );
    }

    $pdo = db();
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
    }

    try {
        $slug = googlehub_slug($businessName);
        $businessStmt = $pdo->prepare(
            "INSERT INTO businesses
                (name, slug, contact_name, contact_email, phone, timezone,
                 primary_color, accent_color, status)
             VALUES (?, ?, ?, ?, ?, ?, '#12336b', '#0f766e', 'active')"
        );
        $businessStmt->execute([
            $businessName,
            $slug,
            (string)($pending['name'] ?: $businessName),
            $email,
            $phone,
            $timezone,
        ]);
        $businessId = (int)$pdo->lastInsertId();

        // Google-only accounts still receive an unusable random local password hash
        // because the existing users table expects password_hash.
        $randomPassword = bin2hex(random_bytes(32));
        $userStmt = $pdo->prepare(
            "INSERT INTO users
                (business_id, name, email, password_hash, role,
                 provider_kpi_role, status)
             VALUES (?, ?, ?, ?, 'business_user', 'none', 'active')"
        );
        $userStmt->execute([
            $businessId,
            (string)($pending['name'] ?: $email),
            $email,
            password_hash($randomPassword, PASSWORD_DEFAULT),
        ]);
        $userId = (int)$pdo->lastInsertId();

        $membershipStmt = $pdo->prepare(
            "INSERT INTO google_business_memberships
                (business_id, user_id, membership_role, created_at)
             VALUES (?, ?, 'owner', NOW())"
        );
        $membershipStmt->execute([$businessId, $userId]);

        googlehub_link_identity($userId, $pending);

        if (function_exists('business_feature_initialize')) {
            business_feature_initialize($businessId, $userId);
        }

        if ($pdo->inTransaction()) {
            $pdo->commit();
        }

        unset($_SESSION['_google_signup_pending']);
        googlehub_login_user($userId);

        if (function_exists('audit')) {
            audit('google_business_signup', ['user_id' => $userId], $businessId);
        }

        return ['business_id' => $businessId, 'user_id' => $userId];

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function googlehub_membership(int $userId, int $businessId): ?array
{
    $stmt = db()->prepare(
        "SELECT * FROM google_business_memberships
         WHERE user_id=? AND business_id=? LIMIT 1"
    );
    $stmt->execute([$userId, $businessId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function googlehub_business_id(): int
{
    $id = function_exists('business_context_id') ? (int)business_context_id() : 0;
    if ($id > 0) {
        return $id;
    }

    return (int)($_SESSION['auth_user']['business_id'] ?? 0);
}

function googlehub_require_owner(): int
{
    require_auth();

    $businessId = googlehub_business_id();
    if ($businessId < 1) {
        throw new RuntimeException('Open a business first.');
    }

    if (auth_is_admin()) {
        return $businessId;
    }

    $membership = googlehub_membership((int)auth_id(), $businessId);
    if ($membership && in_array((string)$membership['membership_role'], ['owner', 'admin'], true)) {
        return $businessId;
    }

    /*
     * Backward compatibility for businesses/users created before Google
     * self-service onboarding existed. The current Aesthetic Intel user model
     * already assigns business users directly to one business. Allow that
     * business user to manage its own Google connections. New self-signups
     * still receive an explicit owner membership above.
     */
    $sessionBusinessId = (int)($_SESSION['auth_user']['business_id'] ?? 0);
    $sessionRole = (string)($_SESSION['auth_user']['role'] ?? '');
    if ($sessionRole === 'business_user' && $sessionBusinessId === $businessId) {
        return $businessId;
    }

    http_response_code(403);
    throw new RuntimeException('Only a user from this business can manage Google connections.');
}

function googlehub_connection(int $businessId, string $service): ?array
{
    $stmt = db()->prepare(
        "SELECT * FROM google_connections
         WHERE business_id=? AND service=? LIMIT 1"
    );
    $stmt->execute([$businessId, $service]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function googlehub_oauth_scopes(string $service): array
{
    return match ($service) {
        'ga4' => [
            'openid',
            'email',
            'profile',
            'https://www.googleapis.com/auth/analytics.readonly',
        ],
        'gbp' => [
            'openid',
            'email',
            'profile',
            'https://www.googleapis.com/auth/business.manage',
        ],
        default => throw new InvalidArgumentException('Unknown Google service.'),
    };
}

function googlehub_oauth_start(string $service, int $businessId, int $userId): string
{
    googlehub_assert_configured();
    googlehub_oauth_scopes($service); // validates service

    $state = bin2hex(random_bytes(24));
    $_SESSION['_google_oauth_states'][$state] = [
        'service' => $service,
        'business_id' => $businessId,
        'user_id' => $userId,
        'created_at' => time(),
    ];

    $params = [
        'client_id' => googlehub_oauth_client_id(),
        'redirect_uri' => googlehub_absolute_url('google-oauth-callback'),
        'response_type' => 'code',
        'scope' => implode(' ', googlehub_oauth_scopes($service)),
        'access_type' => 'offline',
        'include_granted_scopes' => 'true',
        'prompt' => 'consent select_account',
        'state' => $state,
    ];

    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
}

function googlehub_consume_oauth_state(string $state): array
{
    $states = $_SESSION['_google_oauth_states'] ?? [];
    $saved = is_array($states) ? ($states[$state] ?? null) : null;
    unset($_SESSION['_google_oauth_states'][$state]);

    if (!is_array($saved) || (int)($saved['created_at'] ?? 0) < time() - 900) {
        throw new RuntimeException('Google OAuth state is invalid or expired.');
    }

    if ((int)$saved['user_id'] !== (int)auth_id()) {
        throw new RuntimeException('Google OAuth user session changed. Please reconnect.');
    }

    return $saved;
}

function googlehub_exchange_code(string $code): array
{
    return googlehub_form_http(
        'https://oauth2.googleapis.com/token',
        [
            'code' => $code,
            'client_id' => googlehub_oauth_client_id(),
            'client_secret' => googlehub_oauth_client_secret(),
            'redirect_uri' => googlehub_absolute_url('google-oauth-callback'),
            'grant_type' => 'authorization_code',
        ]
    );
}

function googlehub_google_user_from_token(array $token): array
{
    if (!empty($token['id_token'])) {
        return googlehub_verify_id_token(
            (string)$token['id_token'],
            googlehub_oauth_client_id()
        );
    }

    $access = (string)($token['access_token'] ?? '');
    if ($access === '') {
        return [];
    }

    return googlehub_http(
        'GET',
        'https://openidconnect.googleapis.com/v1/userinfo',
        null,
        ['Authorization: Bearer ' . $access]
    );
}

function googlehub_save_connection(
    int $businessId,
    int $userId,
    string $service,
    array $token,
    array $googleUser
): array {
    $existing = googlehub_connection($businessId, $service);

    $access = trim((string)($token['access_token'] ?? ''));
    if ($access === '') {
        throw new RuntimeException('Google did not return an access token.');
    }

    $refresh = trim((string)($token['refresh_token'] ?? ''));
    if ($refresh === '' && $existing) {
        $refreshEncrypted = (string)($existing['refresh_token_encrypted'] ?? '');
    } else {
        $refreshEncrypted = $refresh === '' ? '' : googlehub_encrypt($refresh);
    }

    $expiresIn = max(60, (int)($token['expires_in'] ?? 3600));
    $expiresAt = date('Y-m-d H:i:s', time() + $expiresIn - 30);
    $scopes = trim((string)($token['scope'] ?? ''));
    $scopeArray = $scopes === '' ? googlehub_oauth_scopes($service) : preg_split('/\s+/', $scopes);
    if (!is_array($scopeArray)) $scopeArray = [];

    $stmt = db()->prepare(
        "INSERT INTO google_connections
            (business_id, user_id, service, google_subject, google_email,
             access_token_encrypted, refresh_token_encrypted, token_expires_at,
             scopes_json, status, last_error, connected_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'connected', NULL, NOW(), NOW())
         ON DUPLICATE KEY UPDATE
            user_id=VALUES(user_id),
            google_subject=VALUES(google_subject),
            google_email=VALUES(google_email),
            access_token_encrypted=VALUES(access_token_encrypted),
            refresh_token_encrypted=VALUES(refresh_token_encrypted),
            token_expires_at=VALUES(token_expires_at),
            scopes_json=VALUES(scopes_json),
            status='connected',
            last_error=NULL,
            updated_at=NOW()"
    );

    $stmt->execute([
        $businessId,
        $userId,
        $service,
        trim((string)($googleUser['sub'] ?? '')) ?: null,
        strtolower(trim((string)($googleUser['email'] ?? ''))) ?: null,
        googlehub_encrypt($access),
        $refreshEncrypted ?: null,
        $expiresAt,
        json_encode(array_values(array_unique($scopeArray)), JSON_UNESCAPED_SLASHES),
    ]);

    return googlehub_connection($businessId, $service) ?? [];
}

function googlehub_access_token(int $businessId, string $service): string
{
    $connection = googlehub_connection($businessId, $service);
    if (!$connection || (string)($connection['status'] ?? '') !== 'connected') {
        throw new RuntimeException(strtoupper($service) . ' is not connected.');
    }

    $expiresAt = strtotime((string)($connection['token_expires_at'] ?? '')) ?: 0;
    $access = googlehub_decrypt((string)($connection['access_token_encrypted'] ?? ''));

    if ($access !== '' && $expiresAt > time() + 120) {
        return $access;
    }

    $refresh = googlehub_decrypt((string)($connection['refresh_token_encrypted'] ?? ''));
    if ($refresh === '') {
        db()->prepare(
            "UPDATE google_connections
             SET status='expired', last_error=?, updated_at=NOW()
             WHERE id=?"
        )->execute(['Google refresh token is unavailable. Reconnect the service.', (int)$connection['id']]);
        throw new RuntimeException('Google authorization expired. Reconnect ' . strtoupper($service) . '.');
    }

    try {
        $token = googlehub_form_http(
            'https://oauth2.googleapis.com/token',
            [
                'client_id' => googlehub_oauth_client_id(),
                'client_secret' => googlehub_oauth_client_secret(),
                'refresh_token' => $refresh,
                'grant_type' => 'refresh_token',
            ]
        );

        $newAccess = trim((string)($token['access_token'] ?? ''));
        if ($newAccess === '') {
            throw new RuntimeException('Google did not return a refreshed access token.');
        }

        $expiresIn = max(60, (int)($token['expires_in'] ?? 3600));
        db()->prepare(
            "UPDATE google_connections
             SET access_token_encrypted=?, token_expires_at=?, status='connected',
                 last_error=NULL, updated_at=NOW()
             WHERE id=?"
        )->execute([
            googlehub_encrypt($newAccess),
            date('Y-m-d H:i:s', time() + $expiresIn - 30),
            (int)$connection['id'],
        ]);

        return $newAccess;
    } catch (Throwable $e) {
        db()->prepare(
            "UPDATE google_connections
             SET status='error', last_error=?, updated_at=NOW()
             WHERE id=?"
        )->execute([substr($e->getMessage(), 0, 500), (int)$connection['id']]);
        throw $e;
    }
}

function googlehub_api_get(int $businessId, string $service, string $url): array
{
    return googlehub_http(
        'GET',
        $url,
        null,
        ['Authorization: Bearer ' . googlehub_access_token($businessId, $service)]
    );
}

function googlehub_api_post(int $businessId, string $service, string $url, array $body): array
{
    return googlehub_http(
        'POST',
        $url,
        $body,
        ['Authorization: Bearer ' . googlehub_access_token($businessId, $service)]
    );
}

function googlehub_discover_ga4_properties(int $businessId): array
{
    $url = 'https://analyticsadmin.googleapis.com/v1beta/accountSummaries?pageSize=200';
    $rows = [];

    do {
        $response = googlehub_api_get($businessId, 'ga4', $url);
        foreach (($response['accountSummaries'] ?? []) as $account) {
            if (!is_array($account)) continue;
            foreach (($account['propertySummaries'] ?? []) as $property) {
                if (!is_array($property)) continue;
                $resource = trim((string)($property['property'] ?? ''));
                if (!preg_match('#^properties/(\d+)$#', $resource, $m)) continue;
                $rows[] = [
                    'property_id' => $m[1],
                    'property_resource' => $resource,
                    'property_name' => (string)($property['displayName'] ?? $resource),
                    'account_resource' => (string)($account['account'] ?? ''),
                    'account_name' => (string)($account['displayName'] ?? ''),
                ];
            }
        }

        $token = trim((string)($response['nextPageToken'] ?? ''));
        $url = $token === ''
            ? ''
            : 'https://analyticsadmin.googleapis.com/v1beta/accountSummaries?pageSize=200&pageToken=' . rawurlencode($token);
    } while ($url !== '');

    usort($rows, static fn(array $a, array $b): int =>
        strcasecmp($a['property_name'], $b['property_name'])
    );

    return $rows;
}

function googlehub_select_ga4_property(int $businessId, array $property): void
{
    $propertyId = trim((string)($property['property_id'] ?? ''));
    if (!preg_match('/^\d+$/', $propertyId)) {
        throw new RuntimeException('Choose a valid GA4 property.');
    }

    db()->prepare(
        "UPDATE google_connections
         SET selected_resource_id=?, selected_resource_name=?, resource_meta_json=?,
             updated_at=NOW()
         WHERE business_id=? AND service='ga4'"
    )->execute([
        $propertyId,
        (string)($property['property_name'] ?? ('Property ' . $propertyId)),
        json_encode($property, JSON_UNESCAPED_SLASHES),
        $businessId,
    ]);
}


/**
 * Accept either "478490401" or "properties/478490401".
 */
function googlehub_normalize_ga4_property_id(string $value): string
{
    $value = trim($value);

    if (preg_match('#^properties/(\d+)$#i', $value, $m)) {
        return $m[1];
    }

    if (preg_match('/^\d+$/', $value)) {
        return $value;
    }

    throw new RuntimeException(
        'GA4 Property ID must contain only digits, for example 478490401.'
    );
}

/**
 * Verify that the Google account currently connected for this Aesthetic Intel
 * business can actually read the requested GA4 property.
 *
 * Entering a Property ID never bypasses Google permissions. If the connected
 * Google account cannot read the property, this method rejects the mapping.
 *
 * @return array<string,mixed>
 */
function googlehub_validate_ga4_property_access(
    int $businessId,
    string $propertyId
): array {
    $propertyId = googlehub_normalize_ga4_property_id($propertyId);

    $end = new DateTimeImmutable('yesterday');
    $start = $end->modify('-6 days');

    try {
        googlehub_api_post(
            $businessId,
            'ga4',
            'https://analyticsdata.googleapis.com/v1beta/properties/'
                . rawurlencode($propertyId)
                . ':runReport',
            [
                'dateRanges' => [[
                    'startDate' => $start->format('Y-m-d'),
                    'endDate' => $end->format('Y-m-d'),
                ]],
                'metrics' => [
                    ['name' => 'activeUsers'],
                ],
                'limit' => 1,
            ]
        );
    } catch (Throwable $e) {
        throw new RuntimeException(
            'The Google account connected to this business cannot read GA4 '
            . 'Property ' . $propertyId . '. Grant that Google account Viewer '
            . 'access to the property, or reconnect GA4 using a Google account '
            . 'that already has access. Google said: ' . $e->getMessage()
        );
    }

    /*
     * Metadata is useful for display, but a successful Data API request is the
     * authoritative access test. Do not reject a valid property if Admin API
     * metadata happens to be unavailable.
     */
    $metadata = [];

    try {
        $metadata = googlehub_api_get(
            $businessId,
            'ga4',
            'https://analyticsadmin.googleapis.com/v1beta/properties/'
                . rawurlencode($propertyId)
        );
    } catch (Throwable $e) {
        error_log(
            '[Google GA4 property metadata] Property '
            . $propertyId
            . ': '
            . $e->getMessage()
        );
    }

    return is_array($metadata) ? $metadata : [];
}

/**
 * Manually map a known GA4 Property ID to the currently selected Aesthetic
 * Intel business, but only after validating real Google API access.
 *
 * This is useful when:
 * - the property picker contains many businesses;
 * - a Super Admin already knows the Property ID;
 * - an accessible property is not convenient to locate in the discovered list.
 *
 * It does NOT let a user access a property they do not have permission to.
 *
 * @return array<string,mixed>
 */
function googlehub_select_ga4_property_by_id(
    int $businessId,
    string $propertyId,
    string $displayName = '',
    string $accountName = ''
): array {
    $propertyId = googlehub_normalize_ga4_property_id($propertyId);

    $connection = googlehub_connection($businessId, 'ga4');
    if (!$connection) {
        throw new RuntimeException(
            'Connect Google Analytics for this business before entering a Property ID.'
        );
    }

    $metadata = googlehub_validate_ga4_property_access(
        $businessId,
        $propertyId
    );

    $metadataDisplayName = trim((string)($metadata['displayName'] ?? ''));
    $displayName = trim($displayName);
    $accountName = trim($accountName);

    $property = [
        'property_id' => $propertyId,
        'property_resource' => 'properties/' . $propertyId,
        'property_name' => $displayName !== ''
            ? $displayName
            : ($metadataDisplayName !== ''
                ? $metadataDisplayName
                : 'Property ' . $propertyId),
        'account_resource' => (string)($metadata['parent'] ?? ''),
        'account_name' => $accountName,
        'time_zone' => (string)($metadata['timeZone'] ?? ''),
        'currency_code' => (string)($metadata['currencyCode'] ?? ''),
        'selection_mode' => 'manual_property_id',
        'validated_at' => date('Y-m-d H:i:s'),
    ];

    googlehub_select_ga4_property($businessId, $property);

    return $property;
}


function googlehub_discover_gbp_locations(int $businessId): array
{
    $readMask = 'name,title,storeCode,websiteUri,phoneNumbers,metadata,storefrontAddress,openInfo';
    $url = 'https://mybusinessbusinessinformation.googleapis.com/v1/accounts/-/locations'
        . '?readMask=' . rawurlencode($readMask)
        . '&pageSize=100';
    $rows = [];

    do {
        $response = googlehub_api_get($businessId, 'gbp', $url);
        foreach (($response['locations'] ?? []) as $location) {
            if (!is_array($location)) continue;
            $resource = trim((string)($location['name'] ?? ''));
            if (!preg_match('#^locations/[^/]+$#', $resource)) continue;

            $address = $location['storefrontAddress'] ?? [];
            $addressParts = [];
            if (is_array($address)) {
                foreach (($address['addressLines'] ?? []) as $line) $addressParts[] = $line;
                foreach (['locality','administrativeArea','postalCode'] as $key) {
                    if (!empty($address[$key])) $addressParts[] = $address[$key];
                }
            }

            $rows[] = [
                'location_resource' => $resource,
                'location_name' => (string)($location['title'] ?? $resource),
                'address' => implode(', ', array_filter($addressParts)),
                'website_uri' => (string)($location['websiteUri'] ?? ''),
                'place_id' => (string)($location['metadata']['placeId'] ?? ''),
                'store_code' => (string)($location['storeCode'] ?? ''),
            ];
        }

        $token = trim((string)($response['nextPageToken'] ?? ''));
        $url = $token === ''
            ? ''
            : 'https://mybusinessbusinessinformation.googleapis.com/v1/accounts/-/locations'
                . '?readMask=' . rawurlencode($readMask)
                . '&pageSize=100&pageToken=' . rawurlencode($token);
    } while ($url !== '');

    usort($rows, static fn(array $a, array $b): int =>
        strcasecmp($a['location_name'], $b['location_name'])
    );

    return $rows;
}

function googlehub_select_gbp_location(int $businessId, array $location): void
{
    $resource = trim((string)($location['location_resource'] ?? ''));
    if (!preg_match('#^locations/[^/]+$#', $resource)) {
        throw new RuntimeException('Choose a valid Google Business Profile location.');
    }

    db()->prepare(
        "UPDATE google_connections
         SET selected_resource_id=?, selected_resource_name=?, resource_meta_json=?,
             updated_at=NOW()
         WHERE business_id=? AND service='gbp'"
    )->execute([
        $resource,
        (string)($location['location_name'] ?? $resource),
        json_encode($location, JSON_UNESCAPED_SLASHES),
        $businessId,
    ]);
}

function googlehub_sync_ga4(int $businessId, int $days = 90): int
{
    $connection = googlehub_connection($businessId, 'ga4');
    if (!$connection || empty($connection['selected_resource_id'])) {
        throw new RuntimeException('Choose a GA4 property before syncing.');
    }

    $propertyId = (string)$connection['selected_resource_id'];
    if (!preg_match('/^\d+$/', $propertyId)) {
        throw new RuntimeException('Saved GA4 Property ID is invalid.');
    }

    $days = max(1, min(365, $days));
    $end = new DateTimeImmutable('yesterday');
    $start = $end->modify('-' . ($days - 1) . ' days');

    $body = [
        'dateRanges' => [[
            'startDate' => $start->format('Y-m-d'),
            'endDate' => $end->format('Y-m-d'),
        ]],
        'dimensions' => [['name' => 'date']],
        'metrics' => [
            ['name' => 'activeUsers'],
            ['name' => 'newUsers'],
            ['name' => 'sessions'],
            ['name' => 'engagedSessions'],
            ['name' => 'engagementRate'],
            ['name' => 'eventCount'],
            ['name' => 'totalRevenue'],
        ],
        'limit' => 100000,
    ];

    $response = googlehub_api_post(
        $businessId,
        'ga4',
        'https://analyticsdata.googleapis.com/v1beta/properties/' . rawurlencode($propertyId) . ':runReport',
        $body
    );

    $headers = [];
    foreach (($response['metricHeaders'] ?? []) as $i => $header) {
        $headers[$i] = (string)($header['name'] ?? '');
    }

    $sql = db()->prepare(
        "INSERT INTO google_ga4_daily_metrics
            (business_id, property_id, metric_date, active_users, new_users,
             sessions, engaged_sessions, engagement_rate, event_count,
             total_revenue, synced_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE
            active_users=VALUES(active_users),
            new_users=VALUES(new_users),
            sessions=VALUES(sessions),
            engaged_sessions=VALUES(engaged_sessions),
            engagement_rate=VALUES(engagement_rate),
            event_count=VALUES(event_count),
            total_revenue=VALUES(total_revenue),
            synced_at=NOW()"
    );

    $saved = 0;
    foreach (($response['rows'] ?? []) as $row) {
        if (!is_array($row)) continue;
        $rawDate = (string)($row['dimensionValues'][0]['value'] ?? '');
        if (!preg_match('/^(\d{4})(\d{2})(\d{2})$/', $rawDate, $m)) continue;
        $date = $m[1] . '-' . $m[2] . '-' . $m[3];

        $metrics = [];
        foreach (($row['metricValues'] ?? []) as $i => $value) {
            $name = $headers[$i] ?? '';
            if ($name !== '') $metrics[$name] = (float)($value['value'] ?? 0);
        }

        $sql->execute([
            $businessId,
            $propertyId,
            $date,
            (int)round($metrics['activeUsers'] ?? 0),
            (int)round($metrics['newUsers'] ?? 0),
            (int)round($metrics['sessions'] ?? 0),
            (int)round($metrics['engagedSessions'] ?? 0),
            (float)($metrics['engagementRate'] ?? 0),
            (int)round($metrics['eventCount'] ?? 0),
            (float)($metrics['totalRevenue'] ?? 0),
        ]);
        $saved++;
    }

    db()->prepare(
        "UPDATE google_connections
         SET last_synced_at=NOW(), last_error=NULL, status='connected', updated_at=NOW()
         WHERE business_id=? AND service='ga4'"
    )->execute([$businessId]);

    return $saved;
}

function googlehub_sync_gbp(int $businessId, int $days = 90): int
{
    $connection = googlehub_connection($businessId, 'gbp');
    if (!$connection || empty($connection['selected_resource_id'])) {
        throw new RuntimeException('Choose a Google Business Profile location before syncing.');
    }

    $location = (string)$connection['selected_resource_id'];
    if (!preg_match('#^locations/[^/]+$#', $location)) {
        throw new RuntimeException('Saved GBP location is invalid.');
    }

    $days = max(1, min(180, $days));
    $end = new DateTimeImmutable('yesterday');
    $start = $end->modify('-' . ($days - 1) . ' days');

    $metrics = [
        'BUSINESS_IMPRESSIONS_DESKTOP_MAPS',
        'BUSINESS_IMPRESSIONS_MOBILE_MAPS',
        'BUSINESS_IMPRESSIONS_DESKTOP_SEARCH',
        'BUSINESS_IMPRESSIONS_MOBILE_SEARCH',
        'WEBSITE_CLICKS',
        'CALL_CLICKS',
        'BUSINESS_DIRECTION_REQUESTS',
    ];

    $params = [];
    foreach ($metrics as $metric) {
        $params[] = 'dailyMetrics=' . rawurlencode($metric);
    }

    foreach ([
        'dailyRange.start_date.year' => (int)$start->format('Y'),
        'dailyRange.start_date.month' => (int)$start->format('n'),
        'dailyRange.start_date.day' => (int)$start->format('j'),
        'dailyRange.end_date.year' => (int)$end->format('Y'),
        'dailyRange.end_date.month' => (int)$end->format('n'),
        'dailyRange.end_date.day' => (int)$end->format('j'),
    ] as $key => $value) {
        $params[] = rawurlencode($key) . '=' . $value;
    }

    $url = 'https://businessprofileperformance.googleapis.com/v1/'
        . $location
        . ':fetchMultiDailyMetricsTimeSeries?'
        . implode('&', $params);

    $response = googlehub_api_get($businessId, 'gbp', $url);
    $byDate = [];

    foreach (($response['multiDailyMetricTimeSeries'] ?? []) as $group) {
        if (!is_array($group)) continue;
        foreach (($group['dailyMetricTimeSeries'] ?? []) as $series) {
            if (!is_array($series)) continue;
            $metric = (string)($series['dailyMetric'] ?? '');
            foreach (($series['timeSeries']['datedValues'] ?? []) as $point) {
                if (!is_array($point)) continue;
                $dateObj = $point['date'] ?? [];
                if (!is_array($dateObj)) continue;
                $date = sprintf(
                    '%04d-%02d-%02d',
                    (int)($dateObj['year'] ?? 0),
                    (int)($dateObj['month'] ?? 0),
                    (int)($dateObj['day'] ?? 0)
                );
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) continue;
                $byDate[$date][$metric] = (int)round((float)($point['value'] ?? 0));
            }
        }
    }

    $stmt = db()->prepare(
        "INSERT INTO google_gbp_daily_metrics
            (business_id, location_resource, metric_date,
             desktop_maps_impressions, mobile_maps_impressions,
             desktop_search_impressions, mobile_search_impressions,
             website_clicks, call_clicks, direction_requests, synced_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE
            desktop_maps_impressions=VALUES(desktop_maps_impressions),
            mobile_maps_impressions=VALUES(mobile_maps_impressions),
            desktop_search_impressions=VALUES(desktop_search_impressions),
            mobile_search_impressions=VALUES(mobile_search_impressions),
            website_clicks=VALUES(website_clicks),
            call_clicks=VALUES(call_clicks),
            direction_requests=VALUES(direction_requests),
            synced_at=NOW()"
    );

    $saved = 0;
    foreach ($byDate as $date => $m) {
        $stmt->execute([
            $businessId,
            $location,
            $date,
            (int)($m['BUSINESS_IMPRESSIONS_DESKTOP_MAPS'] ?? 0),
            (int)($m['BUSINESS_IMPRESSIONS_MOBILE_MAPS'] ?? 0),
            (int)($m['BUSINESS_IMPRESSIONS_DESKTOP_SEARCH'] ?? 0),
            (int)($m['BUSINESS_IMPRESSIONS_MOBILE_SEARCH'] ?? 0),
            (int)($m['WEBSITE_CLICKS'] ?? 0),
            (int)($m['CALL_CLICKS'] ?? 0),
            (int)($m['BUSINESS_DIRECTION_REQUESTS'] ?? 0),
        ]);
        $saved++;
    }

    db()->prepare(
        "UPDATE google_connections
         SET last_synced_at=NOW(), last_error=NULL, status='connected', updated_at=NOW()
         WHERE business_id=? AND service='gbp'"
    )->execute([$businessId]);

    return $saved;
}

function googlehub_ga4_summary(int $businessId, int $days = 30): array
{
    $start = (new DateTimeImmutable('today'))->modify('-' . max(0, $days - 1) . ' days')->format('Y-m-d');
    $stmt = db()->prepare(
        "SELECT
            COALESCE(SUM(active_users),0) active_users,
            COALESCE(SUM(new_users),0) new_users,
            COALESCE(SUM(sessions),0) sessions,
            COALESCE(SUM(engaged_sessions),0) engaged_sessions,
            CASE WHEN SUM(sessions)>0 THEN SUM(engaged_sessions)/SUM(sessions) ELSE 0 END engagement_rate,
            COALESCE(SUM(event_count),0) event_count,
            COALESCE(SUM(total_revenue),0) total_revenue
         FROM google_ga4_daily_metrics
         WHERE business_id=? AND metric_date>=?"
    );
    $stmt->execute([$businessId, $start]);
    return $stmt->fetch() ?: [];
}

function googlehub_gbp_summary(int $businessId, int $days = 30): array
{
    $start = (new DateTimeImmutable('today'))->modify('-' . max(0, $days - 1) . ' days')->format('Y-m-d');
    $stmt = db()->prepare(
        "SELECT
            COALESCE(SUM(desktop_maps_impressions + mobile_maps_impressions),0) maps_impressions,
            COALESCE(SUM(desktop_search_impressions + mobile_search_impressions),0) search_impressions,
            COALESCE(SUM(website_clicks),0) website_clicks,
            COALESCE(SUM(call_clicks),0) call_clicks,
            COALESCE(SUM(direction_requests),0) direction_requests
         FROM google_gbp_daily_metrics
         WHERE business_id=? AND metric_date>=?"
    );
    $stmt->execute([$businessId, $start]);
    return $stmt->fetch() ?: [];
}

function googlehub_recent_ga4(int $businessId, int $days = 30): array
{
    $stmt = db()->prepare(
        "SELECT * FROM google_ga4_daily_metrics
         WHERE business_id=?
         ORDER BY metric_date DESC LIMIT ?"
    );
    $stmt->bindValue(1, $businessId, PDO::PARAM_INT);
    $stmt->bindValue(2, max(1, min(90, $days)), PDO::PARAM_INT);
    $stmt->execute();
    return array_reverse($stmt->fetchAll() ?: []);
}

function googlehub_recent_gbp(int $businessId, int $days = 30): array
{
    $stmt = db()->prepare(
        "SELECT * FROM google_gbp_daily_metrics
         WHERE business_id=?
         ORDER BY metric_date DESC LIMIT ?"
    );
    $stmt->bindValue(1, $businessId, PDO::PARAM_INT);
    $stmt->bindValue(2, max(1, min(90, $days)), PDO::PARAM_INT);
    $stmt->execute();
    return array_reverse($stmt->fetchAll() ?: []);
}

function googlehub_disconnect(int $businessId, string $service): void
{
    if (!in_array($service, ['ga4', 'gbp'], true)) {
        throw new InvalidArgumentException('Unknown Google service.');
    }

    db()->prepare(
        "UPDATE google_connections
         SET access_token_encrypted=NULL,
             refresh_token_encrypted=NULL,
             token_expires_at=NULL,
             status='disconnected',
             last_error=NULL,
             updated_at=NOW()
         WHERE business_id=? AND service=?"
    )->execute([$businessId, $service]);
}

function googlehub_sync_service(int $businessId, string $service, int $days = 90): int
{
    return match ($service) {
        'ga4' => googlehub_sync_ga4($businessId, $days),
        'gbp' => googlehub_sync_gbp($businessId, $days),
        default => throw new InvalidArgumentException('Unknown Google service.'),
    };
}

function googlehub_hub_model(int $businessId): array
{
    $stmt = db()->prepare('SELECT * FROM businesses WHERE id=? LIMIT 1');
    $stmt->execute([$businessId]);
    $business = $stmt->fetch();
    if (!$business) {
        throw new RuntimeException('Business not found.');
    }

    return [
        'business' => $business,
        'ga4' => googlehub_connection($businessId, 'ga4'),
        'gbp' => googlehub_connection($businessId, 'gbp'),
        'ga4_summary' => googlehub_ga4_summary($businessId, 30),
        'gbp_summary' => googlehub_gbp_summary($businessId, 30),
        'ga4_daily' => googlehub_recent_ga4($businessId, 30),
        'gbp_daily' => googlehub_recent_gbp($businessId, 30),
        'google_identity' => googlehub_identity_for_user((int)auth_id()),
    ];
}
