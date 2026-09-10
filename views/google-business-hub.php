<?php
$business = is_array($model['business'] ?? null) ? $model['business'] : [];
$ga4 = is_array($model['ga4'] ?? null) ? $model['ga4'] : null;
$gbp = is_array($model['gbp'] ?? null) ? $model['gbp'] : null;
$ga4Summary = is_array($model['ga4_summary'] ?? null) ? $model['ga4_summary'] : [];
$gbpSummary = is_array($model['gbp_summary'] ?? null) ? $model['gbp_summary'] : [];
$identity = is_array($model['google_identity'] ?? null) ? $model['google_identity'] : null;
?>
<link rel="stylesheet" href="/assets/css/google-business-platform.css?v=1.0.0">

<div class="google-hub-page">
    <header class="google-hub-header">
        <div>
            <span class="google-kicker"><?= googlehub_esc((string)($business['name'] ?? 'Business')) ?></span>
            <h1>Google Connections</h1>
            <p>Connect Google Analytics and Google Business Profile, then keep their reporting data synchronized.</p>
        </div>
        <a href="<?= googlehub_esc(url('business-dashboard')) ?>" class="google-secondary-button">Back to dashboard</a>
    </header>

    <section class="google-identity-bar">
        <div>
            <strong>Google login</strong>
            <span><?= $identity ? 'Linked to ' . googlehub_esc((string)$identity['provider_email']) : 'Not linked' ?></span>
        </div>

        <?php if (!$identity): ?>
            <script src="https://accounts.google.com/gsi/client" async defer></script>
            <div id="g_id_onload"
                 data-client_id="<?= googlehub_esc(googlehub_login_client_id()) ?>"
                 data-login_uri="<?= googlehub_esc(googlehub_absolute_url('google-auth-callback')) ?>"
                 data-auto_prompt="false"></div>
            <div class="g_id_signin" data-type="standard" data-text="continue_with" data-size="medium"></div>
        <?php endif; ?>
    </section>

    <div class="google-connection-grid">
        <article class="google-connection-card">
            <div class="google-connection-top">
                <div>
                    <span class="google-kicker">Google Analytics 4</span>
                    <h2><?= $ga4 ? 'Connected' : 'Not connected' ?></h2>
                </div>
                <span class="google-status <?= $ga4 && ($ga4['status'] ?? '') === 'connected' ? 'is-good' : '' ?>">
                    <?= googlehub_esc((string)($ga4['status'] ?? 'disconnected')) ?>
                </span>
            </div>

            <?php if ($ga4): ?>
                <p class="google-resource-name">
                    <?= googlehub_esc((string)($ga4['selected_resource_name'] ?? 'Choose a property')) ?>
                </p>
                <p class="google-muted">
                    Property ID:
                    <?= googlehub_esc((string)($ga4['selected_resource_id'] ?? 'Not selected')) ?>
                </p>
                <p class="google-muted">Google account: <?= googlehub_esc((string)($ga4['google_email'] ?? '')) ?></p>
                <p class="google-muted">Last sync: <?= googlehub_esc((string)($ga4['last_synced_at'] ?? 'Never')) ?></p>

                <div class="google-mini-kpis">
                    <div><span>Sessions · 30d</span><strong><?= number_format((int)($ga4Summary['sessions'] ?? 0)) ?></strong></div>
                    <div><span>New users</span><strong><?= number_format((int)($ga4Summary['new_users'] ?? 0)) ?></strong></div>
                    <div><span>Event count</span><strong><?= number_format((int)($ga4Summary['event_count'] ?? 0)) ?></strong></div>
                    <div><span>Engagement</span><strong><?= number_format(((float)($ga4Summary['engagement_rate'] ?? 0))*100, 1) ?>%</strong></div>
                </div>

                <div class="google-actions">
                    <form method="post" action="<?= googlehub_esc(url('business-google-sync')) ?>">
                        <?= csrf_field() ?><input type="hidden" name="service" value="ga4">
                        <button class="google-primary-button" type="submit">Sync GA4</button>
                    </form>
                    <a
                        class="google-secondary-button"
                        href="<?= googlehub_esc(url('business-google-select-ga4')) ?>"
                    >
                        Change property
                    </a>
                    <form method="post" action="<?= googlehub_esc(url('business-google-connect')) ?>">
                        <?= csrf_field() ?><input type="hidden" name="service" value="ga4">
                        <button class="google-secondary-button" type="submit">Use different Google account</button>
                    </form>
                    <form method="post" action="<?= googlehub_esc(url('business-google-disconnect')) ?>" onsubmit="return confirm('Disconnect GA4? Historical synced metrics will be kept.');">
                        <?= csrf_field() ?><input type="hidden" name="service" value="ga4">
                        <button class="google-link-button" type="submit">Disconnect</button>
                    </form>
                </div>
            <?php else: ?>
                <p>Authorize Aesthetic Intel to read your Analytics properties and reporting metrics.</p>
                <form method="post" action="<?= googlehub_esc(url('business-google-connect')) ?>">
                    <?= csrf_field() ?><input type="hidden" name="service" value="ga4">
                    <button class="google-primary-button" type="submit">Connect Google Analytics</button>
                </form>
            <?php endif; ?>
        </article>

        <article class="google-connection-card">
            <div class="google-connection-top">
                <div>
                    <span class="google-kicker">Google Business Profile</span>
                    <h2><?= $gbp ? 'Connected' : 'Not connected' ?></h2>
                </div>
                <span class="google-status <?= $gbp && ($gbp['status'] ?? '') === 'connected' ? 'is-good' : '' ?>">
                    <?= googlehub_esc((string)($gbp['status'] ?? 'disconnected')) ?>
                </span>
            </div>

            <?php if ($gbp): ?>
                <p class="google-resource-name">
                    <?= googlehub_esc((string)($gbp['selected_resource_name'] ?? 'Choose a location')) ?>
                </p>
                <p class="google-muted">Google account: <?= googlehub_esc((string)($gbp['google_email'] ?? '')) ?></p>
                <p class="google-muted">Last sync: <?= googlehub_esc((string)($gbp['last_synced_at'] ?? 'Never')) ?></p>

                <div class="google-mini-kpis">
                    <div><span>Search views · 30d</span><strong><?= number_format((int)($gbpSummary['search_impressions'] ?? 0)) ?></strong></div>
                    <div><span>Maps views</span><strong><?= number_format((int)($gbpSummary['maps_impressions'] ?? 0)) ?></strong></div>
                    <div><span>Website clicks</span><strong><?= number_format((int)($gbpSummary['website_clicks'] ?? 0)) ?></strong></div>
                    <div><span>Calls</span><strong><?= number_format((int)($gbpSummary['call_clicks'] ?? 0)) ?></strong></div>
                </div>

                <div class="google-actions">
                    <form method="post" action="<?= googlehub_esc(url('business-google-sync')) ?>">
                        <?= csrf_field() ?><input type="hidden" name="service" value="gbp">
                        <button class="google-primary-button" type="submit">Sync GBP</button>
                    </form>
                    <form method="post" action="<?= googlehub_esc(url('business-google-connect')) ?>">
                        <?= csrf_field() ?><input type="hidden" name="service" value="gbp">
                        <button class="google-secondary-button" type="submit">Reconnect</button>
                    </form>
                    <form method="post" action="<?= googlehub_esc(url('business-google-disconnect')) ?>" onsubmit="return confirm('Disconnect Google Business Profile? Historical synced metrics will be kept.');">
                        <?= csrf_field() ?><input type="hidden" name="service" value="gbp">
                        <button class="google-link-button" type="submit">Disconnect</button>
                    </form>
                </div>
            <?php else: ?>
                <p>Connect a Business Profile location to track Search, Maps, website, call and direction activity.</p>
                <form method="post" action="<?= googlehub_esc(url('business-google-connect')) ?>">
                    <?= csrf_field() ?><input type="hidden" name="service" value="gbp">
                    <button class="google-primary-button" type="submit">Connect Business Profile</button>
                </form>
            <?php endif; ?>
        </article>
    </div>

    <section class="google-data-note">
        <strong>How reporting works</strong>
        <p>Aesthetic Intel stores normalized daily metrics locally. Dashboard requests read the local reporting tables instead of calling Google on every page load.</p>
    </section>
</div>
