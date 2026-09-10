<?php $locations = is_array($locations ?? null) ? $locations : []; ?>
<link rel="stylesheet" href="/assets/css/google-business-platform.css?v=1.0.0">
<div class="google-picker-shell">
    <div class="google-picker-card">
        <span class="google-kicker">Google Business Profile</span>
        <h1>Choose your business location</h1>
        <p>Select the Google Business Profile listing you want Aesthetic Intel to track.</p>

        <?php if (!$locations): ?>
            <div class="google-config-warning">
                No accessible Business Profile locations were returned. Confirm that the Google account manages the listing and that your Cloud project has GBP API approval.
            </div>
        <?php else: ?>
            <form method="post" action="<?= googlehub_esc(url('business-google-select-gbp')) ?>" class="google-picker-list">
                <?= csrf_field() ?>
                <?php foreach ($locations as $i => $location): ?>
                    <label class="google-picker-option">
                        <input type="radio" name="option_index" value="<?= (int)$i ?>" <?= $i === 0 ? 'checked' : '' ?>>
                        <span>
                            <strong><?= googlehub_esc((string)$location['location_name']) ?></strong>
                            <small><?= googlehub_esc((string)($location['address'] ?: $location['location_resource'])) ?></small>
                        </span>
                    </label>
                <?php endforeach; ?>
                <button class="google-primary-button" type="submit">Use this Business Profile</button>
            </form>
        <?php endif; ?>
    </div>
</div>
