<?php
$googleClientId = trim((string)($googleClientId ?? ''));
$googleLoginUri = trim((string)($googleLoginUri ?? ''));

$googleClientConfigured =
    $googleClientId !== ''
    && $googleLoginUri !== ''
    && stripos($googleClientId, 'YOUR_') === false
    && stripos($googleClientId, 'REPLACE_') === false
    && str_ends_with($googleClientId, '.apps.googleusercontent.com');

$googleSwitchUrl = googlehub_base_url() . '/google-login-switch.php';
?>
<link rel="stylesheet" href="/assets/css/google-business-platform.css?v=1.4.0">

<div class="google-auth-shell">
    <div class="google-auth-card">
        <div class="google-auth-brand">
            <img
                src="/assets/img/logo-mark.svg"
                alt="Aesthetic Intel"
                class="google-auth-logo"
            >

            <div>
                <h1>Sign in to Aesthetic Intel</h1>
                <p>
                    Sign in with your existing account or use Google.
                </p>
            </div>
        </div>

        <form
            method="post"
            action="<?= googlehub_esc(url('login')) ?>"
            class="google-auth-form"
        >
            <?= csrf_field() ?>

            <label>
                <span>Email</span>
                <input
                    type="email"
                    name="email"
                    autocomplete="email"
                    required
                >
            </label>

            <label>
                <span>Password</span>
                <input
                    type="password"
                    name="password"
                    autocomplete="current-password"
                    required
                >
            </label>

            <button
                type="submit"
                class="google-primary-button google-full-button"
            >
                Sign in
            </button>
        </form>

        <div class="google-auth-divider">
            <span>or</span>
        </div>

        <?php if ($googleClientConfigured): ?>
            <script
                src="https://accounts.google.com/gsi/client"
                async
                defer
            ></script>

            <div
                id="g_id_onload"
                data-client_id="<?= googlehub_esc($googleClientId) ?>"
                data-login_uri="<?= googlehub_esc($googleLoginUri) ?>"
                data-ux_mode="redirect"
                data-auto_prompt="false"
                data-cancel_on_tap_outside="true"
                data-button_auto_select="false"
            ></div>

            <div class="google-login-option">
                <div class="google-login-option-title">
                    Sign in with Google
                </div>

                <div
                    class="g_id_signin google-gis-button"
                    data-type="standard"
                    data-shape="rectangular"
                    data-theme="outline"
                    data-text="signin_with"
                    data-size="large"
                    data-logo_alignment="left"
                    data-width="360"
                ></div>
            </div>

            <div style="margin-top:12px">
                <a
                    href="<?= googlehub_esc($googleSwitchUrl) ?>"
                    class="google-secondary-button google-full-button"
                >
                    Use another Google account
                </a>
            </div>

            <p
                class="google-auth-note"
                style="margin-top:10px"
            >
                This option always opens Google's account chooser instead of
                reusing the most recent Google session.
            </p>

            <div class="google-signup-divider">
                <span>New to Aesthetic Intel?</span>
            </div>

            <div class="google-signup-option">
                <div
                    class="g_id_signin google-gis-button"
                    data-type="standard"
                    data-shape="rectangular"
                    data-theme="filled_blue"
                    data-text="signup_with"
                    data-size="large"
                    data-logo_alignment="left"
                    data-width="360"
                ></div>

                <p class="google-auth-note">
                    A Google account that does not already belong to an
                    Aesthetic Intel user will continue to business onboarding.
                </p>
            </div>

            <div
                class="google-config-warning"
                style="margin-top:16px"
            >
                <strong>Development note:</strong>
                while the Google OAuth app is in <strong>Testing</strong>,
                every Google account you want to try must be added under
                Google Auth Platform → Audience → Test users.
            </div>

            <div
                id="google-gis-load-warning"
                class="google-config-warning"
                style="display:none;margin-top:14px"
            >
                The Google sign-in library did not render. Check the browser
                console, Google Client ID, Authorized JavaScript Origin, and
                callback URL in Google Cloud.
            </div>

            <script>
            (function () {
                window.setTimeout(function () {
                    var renderedButtons =
                        document.querySelectorAll('.google-gis-button iframe');

                    if (renderedButtons.length === 0) {
                        var warning =
                            document.getElementById('google-gis-load-warning');

                        if (warning) {
                            warning.style.display = 'block';
                        }
                    }
                }, 3500);
            })();
            </script>

        <?php else: ?>
            <div class="google-config-warning">
                <strong>Google Sign-In is not configured yet.</strong><br><br>

                Open:
                <code>app/private/google-platform.php</code>

                <br><br>

                Add the real Web OAuth Client ID and use:

                <pre>http://localhost:8000</pre>

                for local development.
            </div>
        <?php endif; ?>
    </div>
</div>
