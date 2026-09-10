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

    $https =
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (
            isset($_SERVER['HTTP_X_FORWARDED_PROTO'])
            && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https'
        );

    $scheme = $https ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost:8000');
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

function googlehub_ga4_allowed_days(int $days): int
{
    return in_array($days, [7, 30, 90], true) ? $days : 30;
}

function googlehub_ga4_period_dates(int $days, bool $previous = false, string $timezone = 'UTC'): array
{
    $days = googlehub_ga4_allowed_days($days);
    try { $tz = new DateTimeZone($timezone ?: 'UTC'); } catch (Throwable) { $tz = new DateTimeZone('UTC'); }
    $end = (new DateTimeImmutable('today', $tz))->modify('-1 day');
    $start = $end->modify('-' . ($days - 1) . ' days');
    if (!$previous) return [$start->format('Y-m-d'), $end->format('Y-m-d')];
    $prevEnd = $start->modify('-1 day');
    $prevStart = $prevEnd->modify('-' . ($days - 1) . ' days');
    return [$prevStart->format('Y-m-d'), $prevEnd->format('Y-m-d')];
}

function googlehub_ga4_property_id(int $businessId): string
{
    $connection = googlehub_connection($businessId, 'ga4');
    $propertyId = trim((string)($connection['selected_resource_id'] ?? ''));
    if (!preg_match('/^\d+$/', $propertyId)) throw new RuntimeException('Choose a valid GA4 property first.');
    return $propertyId;
}

function googlehub_ga4_live_summary(int $businessId, int $days = 30, bool $previous = false, string $timezone = 'UTC'): array
{
    [$start, $end] = googlehub_ga4_period_dates($days, $previous, $timezone);
    $propertyId = googlehub_ga4_property_id($businessId);
    $metricNames = ['sessions','activeUsers','newUsers','engagedSessions','engagementRate','eventCount','keyEvents','totalRevenue'];
    $response = googlehub_api_post($businessId, 'ga4',
        'https://analyticsdata.googleapis.com/v1beta/properties/' . rawurlencode($propertyId) . ':runReport',
        [
            'dateRanges' => [['startDate' => $start, 'endDate' => $end]],
            'metrics' => array_map(static fn(string $name): array => ['name' => $name], $metricNames),
            'limit' => 1,
        ]
    );
    $headers = [];
    foreach ((array)($response['metricHeaders'] ?? []) as $i => $header) $headers[$i] = (string)($header['name'] ?? '');
    $values = [];
    $row = is_array($response['rows'][0] ?? null) ? $response['rows'][0] : [];
    foreach ((array)($row['metricValues'] ?? []) as $i => $value) {
        $name = (string)($headers[$i] ?? '');
        if ($name !== '') $values[$name] = (float)($value['value'] ?? 0);
    }
    foreach ($metricNames as $name) if (!array_key_exists($name, $values)) $values[$name] = 0.0;
    return ['period_start' => $start, 'period_end' => $end, 'metrics' => $values];
}

function googlehub_ga4_change_percent(?float $current, ?float $previous): ?float
{
    if ($current === null || $previous === null) return null;
    if (abs($previous) < 0.000001) return abs($current) < 0.000001 ? 0.0 : null;
    return (($current - $previous) / abs($previous)) * 100.0;
}

function googlehub_ga4_comparison_model(array $current, array $previous): array
{
    $rows = [];
    foreach (['sessions','activeUsers','newUsers','engagedSessions','engagementRate','eventCount','keyEvents','totalRevenue'] as $metric) {
        $c = isset($current['metrics'][$metric]) ? (float)$current['metrics'][$metric] : null;
        $p = isset($previous['metrics'][$metric]) ? (float)$previous['metrics'][$metric] : null;
        $rows[$metric] = ['current' => $c, 'previous' => $p, 'change_percent' => googlehub_ga4_change_percent($c, $p)];
    }
    return $rows;
}

function googlehub_ga4_property_details(int $businessId): array
{
    $propertyId = googlehub_ga4_property_id($businessId);
    return googlehub_api_get($businessId, 'ga4', 'https://analyticsadmin.googleapis.com/v1beta/properties/' . rawurlencode($propertyId));
}

function googlehub_ga4_latest_local_date(int $businessId): ?string
{
    $stmt = db()->prepare('SELECT MAX(metric_date) FROM google_ga4_daily_metrics WHERE business_id=?');
    $stmt->execute([$businessId]);
    $value = $stmt->fetchColumn();
    return $value ? (string)$value : null;
}

function googlehub_ga4_connection_health(int $businessId, ?array $connection): array
{
    if (!$connection) return ['level'=>'disconnected','label'=>'Not connected','message'=>'Connect Google Analytics to activate live reporting.','latest_data_date'=>null];
    $status = (string)($connection['status'] ?? '');
    $resource = trim((string)($connection['selected_resource_id'] ?? ''));
    $scopes = json_decode((string)($connection['scopes_json'] ?? '[]'), true);
    if (!is_array($scopes)) $scopes = [];
    $hasScope = in_array('https://www.googleapis.com/auth/analytics.readonly', $scopes, true);
    $hasRefresh = trim((string)($connection['refresh_token_encrypted'] ?? '')) !== '';
    $lastError = trim((string)($connection['last_error'] ?? ''));
    $lastSync = trim((string)($connection['last_synced_at'] ?? ''));
    $latest = googlehub_ga4_latest_local_date($businessId);
    if ($status !== 'connected') return ['level'=>'error','label'=>'Needs attention','message'=>$lastError ?: 'The Google connection is not currently active.','latest_data_date'=>$latest];
    if (!preg_match('/^\d+$/', $resource) || !$hasScope || !$hasRefresh) {
        $msg = !$hasScope ? 'The Analytics read scope is missing.' : (!$hasRefresh ? 'A refresh token is unavailable.' : 'Choose a valid Analytics property.');
        return ['level'=>'warning','label'=>'Reconnect recommended','message'=>$msg,'latest_data_date'=>$latest];
    }
    if ($lastError !== '') return ['level'=>'warning','label'=>'Needs review','message'=>$lastError,'latest_data_date'=>$latest];
    if ($lastSync === '') return ['level'=>'warning','label'=>'Sync pending','message'=>'The connection is authorized, but a local sync has not completed yet.','latest_data_date'=>$latest];
    $ts = strtotime($lastSync);
    if ($ts !== false && $ts < time() - 172800) return ['level'=>'warning','label'=>'Data may be stale','message'=>'The last local sync is more than 48 hours old.','latest_data_date'=>$latest];
    return ['level'=>'healthy','label'=>'Healthy','message'=>'OAuth, property selection and local synchronization are available.','latest_data_date'=>$latest];
}

function googlehub_ga4_test_connection(int $businessId): array
{
    $propertyId = googlehub_ga4_property_id($businessId);
    $response = googlehub_api_post($businessId, 'ga4',
        'https://analyticsdata.googleapis.com/v1beta/properties/' . rawurlencode($propertyId) . ':runReport',
        ['dateRanges'=>[['startDate'=>'yesterday','endDate'=>'yesterday']],'metrics'=>[['name'=>'sessions']],'limit'=>1]
    );
    return ['success'=>true,'property_id'=>$propertyId,'row_count'=>(int)($response['rowCount'] ?? 0)];
}

function googlehub_ga4_preferences(int $businessId): array
{
    try {
        $stmt = db()->prepare('SELECT * FROM google_ga4_preferences WHERE business_id=? LIMIT 1');
        $stmt->execute([$businessId]);
        return $stmt->fetch() ?: ['business_id'=>$businessId,'key_events_json'=>'[]'];
    } catch (Throwable $e) {
        error_log('[GA4 preferences] '.$e->getMessage());
        return ['business_id'=>$businessId,'key_events_json'=>'[]'];
    }
}

function googlehub_ga4_selected_key_events(int $businessId): array
{
    $events = json_decode((string)(googlehub_ga4_preferences($businessId)['key_events_json'] ?? '[]'), true);
    if (!is_array($events)) return [];
    $clean=[];
    foreach ($events as $event) {
        $event=trim((string)$event);
        if ($event!=='' && preg_match('/^[A-Za-z0-9_:\-.]{1,120}$/',$event)) $clean[]=$event;
    }
    return array_values(array_unique($clean));
}

function googlehub_ga4_save_key_events(int $businessId, array $events): void
{
    $clean=[];
    foreach ($events as $event) {
        $event=trim((string)$event);
        if ($event==='') continue;
        if (!preg_match('/^[A-Za-z0-9_:\-.]{1,120}$/',$event)) throw new RuntimeException('One selected GA4 event name is invalid.');
        $clean[]=$event;
    }
    $clean=array_slice(array_values(array_unique($clean)),0,25);
    db()->prepare("INSERT INTO google_ga4_preferences (business_id,key_events_json,created_at,updated_at) VALUES (?,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE key_events_json=VALUES(key_events_json),updated_at=NOW()")
        ->execute([$businessId,json_encode($clean,JSON_UNESCAPED_SLASHES)]);
}

function googlehub_ga4_available_key_events(int $businessId, int $days = 30, string $timezone = 'UTC'): array
{
    [$start,$end]=googlehub_ga4_period_dates($days,false,$timezone);
    $propertyId=googlehub_ga4_property_id($businessId);
    $response=googlehub_api_post($businessId,'ga4',
        'https://analyticsdata.googleapis.com/v1beta/properties/'.rawurlencode($propertyId).':runReport',
        [
            'dateRanges'=>[['startDate'=>$start,'endDate'=>$end]],
            'dimensions'=>[['name'=>'eventName']],
            'metrics'=>[['name'=>'keyEvents'],['name'=>'eventCount'],['name'=>'activeUsers']],
            'orderBys'=>[['metric'=>['metricName'=>'keyEvents'],'desc'=>true]],
            'limit'=>100,
        ]
    );
    $headers=[];
    foreach ((array)($response['metricHeaders'] ?? []) as $i=>$h) $headers[$i]=(string)($h['name'] ?? '');
    $rows=[];
    foreach ((array)($response['rows'] ?? []) as $row) {
        if (!is_array($row)) continue;
        $event=trim((string)($row['dimensionValues'][0]['value'] ?? ''));
        if ($event==='') continue;
        $metrics=[];
        foreach ((array)($row['metricValues'] ?? []) as $i=>$v) { $name=(string)($headers[$i] ?? ''); if($name!=='') $metrics[$name]=(float)($v['value'] ?? 0); }
        if ((float)($metrics['keyEvents'] ?? 0)<=0) continue;
        $rows[]=['event_name'=>$event,'key_events'=>(float)($metrics['keyEvents'] ?? 0),'event_count'=>(float)($metrics['eventCount'] ?? 0),'active_users'=>(float)($metrics['activeUsers'] ?? 0)];
    }
    return $rows;
}

function googlehub_sync_history_log(int $businessId,string $service,string $action,string $status,int $rowsSynced=0,?string $message=null): void
{
    try {
        db()->prepare('INSERT INTO google_sync_history (business_id,service,action_name,status,rows_synced,message,created_by,created_at) VALUES (?,?,?,?,?,?,?,NOW())')
            ->execute([$businessId,$service,$action,$status,$rowsSynced,$message!==null?substr($message,0,1000):null,auth_id()?(int)auth_id():null]);
    } catch (Throwable $e) { error_log('[Google sync history] '.$e->getMessage()); }
}

function googlehub_sync_history(int $businessId,string $service='ga4',int $limit=10): array
{
    try {
        $limit=max(1,min(50,$limit));
        $stmt=db()->prepare("SELECT h.*,u.name actor_name FROM google_sync_history h LEFT JOIN users u ON u.id=h.created_by WHERE h.business_id=? AND h.service=? ORDER BY h.created_at DESC,h.id DESC LIMIT {$limit}");
        $stmt->execute([$businessId,$service]);
        return $stmt->fetchAll() ?: [];
    } catch (Throwable $e) { error_log('[Google sync history list] '.$e->getMessage()); return []; }
}

function googlehub_ga4_saved_views(int $businessId): array
{
    try {
        $stmt=db()->prepare('SELECT v.*,u.name created_by_name FROM google_ga4_saved_views v LEFT JOIN users u ON u.id=v.created_by WHERE v.business_id=? ORDER BY v.created_at DESC,v.id DESC');
        $stmt->execute([$businessId]);
        return $stmt->fetchAll() ?: [];
    } catch (Throwable $e) { error_log('[GA4 saved views] '.$e->getMessage()); return []; }
}

function googlehub_ga4_save_view(int $businessId,string $name,int $days,string $section): void
{
    $name=trim($name);
    if (mb_strlen($name)<2 || mb_strlen($name)>80) throw new RuntimeException('Saved report name must contain 2 to 80 characters.');
    $days=googlehub_ga4_allowed_days($days);
    $allowed=['trend','acquisition','content','audience','events','ecommerce','pdf-compare','explorer'];
    if(!in_array($section,$allowed,true)) $section='trend';
    db()->prepare('INSERT INTO google_ga4_saved_views (business_id,view_name,period_days,section_anchor,created_by,created_at,updated_at) VALUES (?,?,?,?,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE period_days=VALUES(period_days),section_anchor=VALUES(section_anchor),updated_at=NOW()')
        ->execute([$businessId,$name,$days,$section,(int)auth_id()]);
}

function googlehub_ga4_delete_view(int $businessId,int $viewId): void
{
    if($viewId<1) throw new RuntimeException('Saved report was not found.');
    db()->prepare('DELETE FROM google_ga4_saved_views WHERE id=? AND business_id=?')->execute([$viewId,$businessId]);
}

function googlehub_ga4_dashboard_url(int $days,string $section='trend',string $timezone='UTC'): string
{
    [$start,$end]=googlehub_ga4_period_dates($days,false,$timezone);
    $allowed=['trend','acquisition','content','audience','events','ecommerce','pdf-compare','explorer'];
    if(!in_array($section,$allowed,true)) $section='trend';
    return url('business-ga4-api-data',['fetch'=>1,'period_start'=>$start,'period_end'=>$end]).'#'.$section;
}

function googlehub_ga4_latest_pdf_upload(int $businessId): ?array
{
    try {
        $stmt=db()->prepare("SELECT ae.id,ae.period_start,ae.period_end,ae.frequency,ae.validation_status,ae.validation_score,ae.created_at,u.name uploaded_by_name FROM ai_extractions ae LEFT JOIN users u ON u.id=ae.created_by WHERE ae.business_id=? AND ae.source_code='ga4' AND ae.extracted_json IS NOT NULL AND ae.extracted_json<>'' ORDER BY ae.created_at DESC,ae.id DESC LIMIT 1");
        $stmt->execute([$businessId]);
        $row=$stmt->fetch();
        return is_array($row)?$row:null;
    } catch(Throwable $e){ error_log('[GA4 latest PDF] '.$e->getMessage()); return null; }
}

function googlehub_ga4_store_pdf_comparison(int $businessId,array $comparison): void
{
    if(empty($comparison['success'])) return;
    try {
        db()->prepare('INSERT INTO google_ga4_pdf_comparison_runs (business_id,extraction_id,property_id,period_start,period_end,match_percent,comparable_metrics,matched_metrics,review_metrics,comparison_json,created_by,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW())')
            ->execute([$businessId,(int)($comparison['saved_upload_id'] ?? 0) ?: null,(string)($comparison['property_id'] ?? ''),(string)($comparison['period_start'] ?? ''),(string)($comparison['period_end'] ?? ''),(float)($comparison['match_percent'] ?? 0),(int)($comparison['comparable_metrics'] ?? 0),(int)($comparison['matched_metrics'] ?? 0),(int)($comparison['review_metrics'] ?? 0),json_encode($comparison,JSON_UNESCAPED_SLASHES),auth_id()?(int)auth_id():null]);
    } catch(Throwable $e){ error_log('[GA4 comparison history] '.$e->getMessage()); }
}

function googlehub_ga4_latest_pdf_comparison(int $businessId): ?array
{
    try {
        $stmt=db()->prepare('SELECT * FROM google_ga4_pdf_comparison_runs WHERE business_id=? ORDER BY created_at DESC,id DESC LIMIT 1');
        $stmt->execute([$businessId]);
        $row=$stmt->fetch();
        return is_array($row)?$row:null;
    } catch(Throwable $e){ error_log('[GA4 latest comparison] '.$e->getMessage()); return null; }
}

function googlehub_ga4_smart_insights(array $comparison,array $keyEvents,?array $latestPdfComparison=null): array
{
    $insights=[];
    foreach ([['sessions','Sessions',5.0],['newUsers','New users',5.0],['keyEvents','Key events',3.0],['totalRevenue','Revenue',3.0]] as [$key,$label,$threshold]) {
        $change=$comparison[$key]['change_percent'] ?? null;
        if($change===null || abs((float)$change)<$threshold) continue;
        $positive=(float)$change>0;
        $insights[]=['tone'=>$positive?'positive':'negative','title'=>$label.($positive?' increased':' decreased'),'body'=>$label.' is '.number_format(abs((float)$change),1).'% '.($positive?'higher':'lower').' than the previous equivalent period.'];
    }
    if($keyEvents){$top=$keyEvents[0];$insights[]=['tone'=>'neutral','title'=>'Top key event','body'=>(string)($top['event_name'] ?? 'Key event').' generated '.number_format((float)($top['key_events'] ?? 0),0).' key event(s) in this period.'];}
    if($latestPdfComparison){$alignment=(float)($latestPdfComparison['match_percent'] ?? 0);$insights[]=['tone'=>$alignment>=90?'positive':($alignment>=70?'neutral':'negative'),'title'=>'PDF / API alignment','body'=>'The latest saved GA4 PDF comparison is '.number_format($alignment,1).'% aligned with the API.'];}
    if(!$insights)$insights[]=['tone'=>'neutral','title'=>'Performance is stable','body'=>'No large period-over-period movement was detected in the primary GA4 metrics.'];
    return array_slice($insights,0,4);
}

function googlehub_ga4_control_center(int $businessId,array $business,?array $connection,int $days=30): array
{
    $days=googlehub_ga4_allowed_days($days);
    $timezone=(string)($business['timezone'] ?? 'UTC');
    $model=[
        'days'=>$days,
        'health'=>googlehub_ga4_connection_health($businessId,$connection),
        'current'=>null,'previous'=>null,'comparison'=>[],'property_details'=>[],
        'property_error'=>null,'live_error'=>null,'key_events_error'=>null,
        'available_key_events'=>[],'selected_key_events'=>googlehub_ga4_selected_key_events($businessId),
        'trend'=>googlehub_recent_ga4($businessId,$days),
        'sync_history'=>googlehub_sync_history($businessId,'ga4',10),
        'saved_views'=>googlehub_ga4_saved_views($businessId),
        'latest_pdf'=>googlehub_ga4_latest_pdf_upload($businessId),
        'latest_pdf_comparison'=>googlehub_ga4_latest_pdf_comparison($businessId),
        'insights'=>[],
    ];
    if(!$connection || (string)($connection['status'] ?? '')!=='connected' || empty($connection['selected_resource_id'])) return $model;
    try{$model['property_details']=googlehub_ga4_property_details($businessId);}catch(Throwable $e){$model['property_error']=$e->getMessage();}
    try{
        $model['current']=googlehub_ga4_live_summary($businessId,$days,false,$timezone);
        $model['previous']=googlehub_ga4_live_summary($businessId,$days,true,$timezone);
        $model['comparison']=googlehub_ga4_comparison_model($model['current'],$model['previous']);
    }catch(Throwable $e){$model['live_error']=$e->getMessage();}
    try{$model['available_key_events']=googlehub_ga4_available_key_events($businessId,$days,$timezone);}catch(Throwable $e){$model['key_events_error']=$e->getMessage();}
    $model['insights']=googlehub_ga4_smart_insights($model['comparison'],$model['available_key_events'],$model['latest_pdf_comparison']);
    return $model;
}

function googlehub_hub_model(int $businessId, int $ga4Days = 30): array
{
    $stmt = db()->prepare('SELECT * FROM businesses WHERE id=? LIMIT 1');
    $stmt->execute([$businessId]);
    $business = $stmt->fetch();
    if (!$business) throw new RuntimeException('Business not found.');

    $ga4 = googlehub_connection($businessId, 'ga4');
    $gbp = googlehub_connection($businessId, 'gbp');

    return [
        'business' => $business,
        'ga4' => $ga4,
        'gbp' => $gbp,
        'ga4_summary' => googlehub_ga4_summary($businessId, 30),
        'gbp_summary' => googlehub_gbp_summary($businessId, 30),
        'ga4_daily' => googlehub_recent_ga4($businessId, 30),
        'gbp_daily' => googlehub_recent_gbp($businessId, 30),
        'ga4_control' => googlehub_ga4_control_center($businessId, $business, $ga4, $ga4Days),
        'google_identity' => googlehub_identity_for_user((int)auth_id()),
    ];
}
