<?php
$pending = is_array($pending ?? null) ? $pending : [];
$existingUser = is_array($existingUser ?? null) ? $existingUser : null;
$canOpenExisting = !empty($canOpenExisting);
?>
<link rel="stylesheet" href="/assets/css/google-business-platform.css?v=1.2.0">

<div class="google-onboarding-shell">
    <div class="google-onboarding-card">
        <span class="google-kicker">Google sign-in</span>
        <h1>Choose how you want to continue</h1>

        <p>
            Signed in as
            <strong><?= googlehub_esc((string)($pending['email'] ?? '')) ?></strong>.
        </p>

        <?php if ($existingUser): ?>
            <div style="
                padding:16px;
                margin:18px 0;
                border:1px solid #dfe3e8;
                border-radius:14px;
                background:#fafafa;
            ">
                <strong>Existing Aesthetic Intel account found</strong>

                <p style="margin:7px 0 14px">
                    <?= googlehub_esc((string)($existingUser['name'] ?? 'Your account')) ?>
                    already has an Aesthetic Intel login using this email.
                </p>

                <?php if ($canOpenExisting): ?>
                    <form
                        method="post"
                        action="<?= googlehub_esc(url('google-use-existing-account')) ?>"
                    >
                        <?= csrf_field() ?>

                        <button
                            class="google-primary-button google-full-button"
                            type="submit"
                        >
                            Open my existing dashboard
                        </button>
                    </form>

                    <p class="google-muted" style="margin-top:10px">
                        Google will be linked to your existing Aesthetic Intel
                        account. No new business will be created.
                    </p>
                <?php else: ?>
                    <a
                        class="google-secondary-button google-full-button"
                        href="<?= googlehub_esc(url('login')) ?>"
                    >
                        Sign in to my existing dashboard
                    </a>

                    <p class="google-muted" style="margin-top:10px">
                        Sign in with your existing password first. You can then
                        link this Google account from Google Connections.
                    </p>
                <?php endif; ?>
            </div>

            <div class="google-auth-divider">
                <span>or create a separate workspace</span>
            </div>
        <?php endif; ?>

        <h2 style="margin:0 0 8px;font-size:22px">
            Create a new business workspace
        </h2>

        <p>
            Use this only when this Google account belongs to a business that
            does not already have an Aesthetic Intel workspace.
        </p>

        <form
            method="post"
            action="<?= googlehub_esc(url('google-onboarding')) ?>"
            class="google-auth-form"
        >
            <?= csrf_field() ?>

            <label>
                <span>Business name</span>
                <input
                    type="text"
                    name="business_name"
                    maxlength="180"
                    required
                    autofocus
                >
            </label>

            <label>
                <span>Business timezone</span>

                <select name="timezone" required>
                    <?php foreach (timezone_identifiers_list() as $tz): ?>
                        <option
                            value="<?= googlehub_esc($tz) ?>"
                            <?= $tz === 'America/Denver' ? 'selected' : '' ?>
                        >
                            <?= googlehub_esc($tz) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                <span>Phone <small>optional</small></span>
                <input type="text" name="phone" maxlength="60">
            </label>

            <button class="google-primary-button" type="submit">
                Create new business
            </button>
        </form>

        <?php if (!$existingUser): ?>
            <div class="google-auth-divider">
                <span>already use Aesthetic Intel?</span>
            </div>

            <a
                class="google-secondary-button google-full-button"
                href="<?= googlehub_esc(url('login')) ?>"
            >
                Sign in to an existing account
            </a>
        <?php endif; ?>
    </div>
</div>
