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
<link rel="stylesheet" href="<?=asset('css/google-business-platform.css')?>?v=<?=e(app_config('version'))?>">

<div class="google-auth-shell ai-login-shell">
    <div class="ai-login-frame">
        <aside class="ai-login-story" aria-label="Aesthetic Intel overview">
            <a class="ai-login-wordmark" href="<?=url('home')?>" aria-label="Aesthetic Intel home">
                <img src="<?=asset('img/aesthetic-intel-logo-on-dark.png')?>" alt="Aesthetic Intel" class="ai-original-logo ai-original-logo-on-dark">
            </a>

            <div class="ai-login-story-copy">
                <span class="ai-login-kicker"><i></i> Performance intelligence</span>
                <h2>Your most important signals, without the reporting noise.</h2>
                <p>Live connected data at the top. Full source detail and validated reporting remain available whenever you need to investigate.</p>
            </div>

            <div class="ai-login-mini-dashboard" aria-hidden="true">
                <div class="ai-login-mini-head"><span>Live performance</span><b><i></i> Connected</b></div>
                <div class="ai-login-mini-grid">
                    <div><small>Revenue</small><strong>$286K</strong><em>▲ 46.4%</em></div>
                    <div><small>Sessions</small><strong>3.9K</strong><em>Current</em></div>
                    <div><small>GBP actions</small><strong>230</strong><em>▲ 35.3%</em></div>
                </div>
                <div class="ai-login-mini-bars"><i></i><i></i><i></i><i></i><i></i><i></i></div>
            </div>

            <div class="ai-login-source-row">
                <span>GA4</span><span>GBP</span><span>Boulevard</span><span>Podium</span><span>Growth99+</span>
            </div>
        </aside>

        <main class="google-auth-card ai-login-card">
            <div class="ai-login-mobile-brand">
                <img src="<?=asset('img/aesthetic-intel-logo-on-light.png')?>" alt="Aesthetic Intel" class="ai-original-logo ai-original-logo-on-light">
            </div>

            <div class="google-auth-brand ai-login-heading">
                <img src="<?=asset('img/logo-mark.svg')?>" alt="" class="google-auth-logo">
                <div>
                    <span class="eyebrow">Secure workspace</span>
                    <h1>Welcome back</h1>
                    <p>Sign in to continue to your Aesthetic Intel dashboard.</p>
                </div>
            </div>

            <form
                method="post"
                action="<?=googlehub_esc(url('login'))?>"
                class="google-auth-form ai-login-form"
                data-loading-title="Signing you in"
                data-loading-message="Securely opening your Aesthetic Intel workspace…"
            >
                <?=csrf_field()?>

                <label>
                    <span>Email address</span>
                    <input type="email" name="email" autocomplete="email" required placeholder="you@company.com" value="<?=e($_POST['email'] ?? '')?>">
                </label>

                <label>
                    <span>Password</span>
                    <input type="password" name="password" autocomplete="current-password" required placeholder="Enter your password">
                </label>

                <button type="submit" class="google-primary-button google-full-button ai-login-primary">
                    <span>Sign in</span><b aria-hidden="true">→</b>
                </button>
            </form>

            <div class="google-auth-divider ai-login-divider"><span>or continue with</span></div>

            <?php if ($googleClientConfigured): ?>
                <script src="https://accounts.google.com/gsi/client" async defer></script>

                <div
                    id="g_id_onload"
                    data-client_id="<?=googlehub_esc($googleClientId)?>"
                    data-login_uri="<?=googlehub_esc($googleLoginUri)?>"
                    data-ux_mode="redirect"
                    data-auto_prompt="false"
                    data-cancel_on_tap_outside="true"
                    data-button_auto_select="false"
                ></div>

                <div class="google-login-option ai-login-google-option">
                    <div class="g_id_signin google-gis-button" data-type="standard" data-shape="rectangular" data-theme="outline" data-text="signin_with" data-size="large" data-logo_alignment="left" data-width="360"></div>
                </div>

                <div class="ai-login-switch-wrap">
                    <a href="<?=googlehub_esc($googleSwitchUrl)?>" class="google-secondary-button google-full-button">Use another Google account</a>
                </div>

                <div class="google-signup-divider ai-login-signup-divider"><span>New to Aesthetic Intel?</span></div>

                <div class="google-signup-option ai-login-google-option">
                    <div class="g_id_signin google-gis-button" data-type="standard" data-shape="rectangular" data-theme="filled_blue" data-text="signup_with" data-size="large" data-logo_alignment="left" data-width="360"></div>
                    <p class="google-auth-note">New Google accounts can continue to business onboarding after verification.</p>
                </div>

                <div id="google-gis-load-warning" class="google-config-warning" style="display:none;margin-top:14px">
                    The Google sign-in library did not render. Check the Google Client ID, Authorized JavaScript Origin and callback URL.
                </div>

                <script>
                (function () {
                    window.setTimeout(function () {
                        var renderedButtons = document.querySelectorAll('.google-gis-button iframe');
                        if (renderedButtons.length === 0) {
                            var warning = document.getElementById('google-gis-load-warning');
                            if (warning) warning.style.display = 'block';
                        }
                    }, 3500);
                })();
                </script>
            <?php else: ?>
                <div class="google-config-warning">
                    <strong>Google Sign-In is not configured yet.</strong><br><br>
                    Password sign-in remains available. Configure the Google Web OAuth Client ID in <code>app/private/google-platform.php</code> when ready.
                </div>
            <?php endif; ?>

            <p class="ai-login-security-note"><span>✓</span> Secure access to your assigned business workspace.</p>
        </main>
    </div>
</div>
