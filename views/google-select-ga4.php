<?php
$properties = is_array($properties ?? null) ? $properties : [];
$currentConnection = is_array($currentConnection ?? null) ? $currentConnection : null;
$businessName = trim((string)($businessName ?? 'this business'));
$connectedGoogleEmail = trim((string)($currentConnection['google_email'] ?? ''));
?>
<link rel="stylesheet" href="/assets/css/google-business-platform.css?v=1.3.0">

<style>
.ga4-property-layout{
    display:grid;
    grid-template-columns:minmax(0,1fr);
    gap:18px;
}
.ga4-property-toolbar{
    display:flex;
    gap:10px;
    align-items:center;
    justify-content:space-between;
    flex-wrap:wrap;
    margin:18px 0 8px;
}
.ga4-property-search{
    width:min(100%,360px);
    border:1px solid #dadbe0;
    border-radius:10px;
    padding:11px 13px;
    font:inherit;
}
.ga4-manual-card{
    border:1px solid #e2e3e7;
    border-radius:14px;
    padding:18px;
    background:#fafafa;
    margin-top:18px;
}
.ga4-manual-card h2{
    margin:0 0 7px;
    font-size:18px;
}
.ga4-manual-grid{
    display:grid;
    grid-template-columns:1fr;
    gap:12px;
    margin-top:14px;
}
.ga4-manual-grid label{
    display:grid;
    gap:6px;
    font-size:13px;
    font-weight:700;
}
.ga4-manual-grid input{
    width:100%;
    border:1px solid #dadbe0;
    border-radius:10px;
    padding:11px 12px;
    font:inherit;
    background:#fff;
}
.ga4-current-map{
    padding:13px 14px;
    border-radius:12px;
    background:#eef7f1;
    border:1px solid #cfe6d7;
    margin:14px 0;
}
.ga4-help{
    color:#6f7077;
    font-size:12px;
    line-height:1.55;
}
.ga4-actions-row{
    display:flex;
    gap:9px;
    align-items:center;
    flex-wrap:wrap;
    margin-top:14px;
}
</style>

<div class="google-picker-shell">
    <div class="google-picker-card" style="width:min(100%,760px)">
        <span class="google-kicker">Google Analytics</span>
        <h1>Choose GA4 for <?= googlehub_esc($businessName) ?></h1>

        <p>
            Select one of the properties available to the connected Google
            account, or enter a known GA4 Property ID manually.
        </p>

        <?php if ($connectedGoogleEmail !== ''): ?>
            <p class="google-muted" style="text-align:left">
                Connected Google account:
                <strong><?= googlehub_esc($connectedGoogleEmail) ?></strong>
            </p>
        <?php endif; ?>

        <?php if (
            $currentConnection
            && trim((string)($currentConnection['selected_resource_id'] ?? '')) !== ''
        ): ?>
            <div class="ga4-current-map">
                <strong>Current mapping</strong><br>
                <?= googlehub_esc(
                    (string)(
                        $currentConnection['selected_resource_name']
                        ?? 'GA4 property'
                    )
                ) ?>
                · Property
                <?= googlehub_esc(
                    (string)$currentConnection['selected_resource_id']
                ) ?>
            </div>
        <?php endif; ?>

        <div class="ga4-property-layout">
            <section>
                <div class="ga4-property-toolbar">
                    <div>
                        <strong>Properties discovered from Google</strong>
                        <div class="ga4-help">
                            Every property this Google account can access is shown here.
                        </div>
                    </div>

                    <?php if (count($properties) > 1): ?>
                        <input
                            id="ga4-property-search"
                            class="ga4-property-search"
                            type="search"
                            placeholder="Search property or account..."
                            autocomplete="off"
                        >
                    <?php endif; ?>
                </div>

                <?php if (!$properties): ?>
                    <div class="google-config-warning">
                        No GA4 properties were returned for this Google account.
                        You can reconnect using another Google account, or enter
                        a Property ID below. Manual IDs are still validated against
                        the connected Google account's permissions.
                    </div>
                <?php else: ?>
                    <form
                        method="post"
                        action="<?= googlehub_esc(url('business-google-select-ga4')) ?>"
                        class="google-picker-list"
                        id="ga4-discovered-form"
                    >
                        <?= csrf_field() ?>
                        <input
                            type="hidden"
                            name="selection_mode"
                            value="discovered"
                        >

                        <div id="ga4-property-options">
                            <?php foreach ($properties as $i => $property): ?>
                                <?php
                                $searchText = strtolower(
                                    trim(
                                        (string)($property['property_name'] ?? '')
                                        . ' '
                                        . (string)($property['account_name'] ?? '')
                                        . ' '
                                        . (string)($property['property_id'] ?? '')
                                    )
                                );
                                ?>
                                <label
                                    class="google-picker-option"
                                    data-ga4-property
                                    data-search="<?= googlehub_esc($searchText) ?>"
                                >
                                    <input
                                        type="radio"
                                        name="option_index"
                                        value="<?= (int)$i ?>"
                                        <?= $i === 0 ? 'checked' : '' ?>
                                    >
                                    <span>
                                        <strong>
                                            <?= googlehub_esc(
                                                (string)$property['property_name']
                                            ) ?>
                                        </strong>
                                        <small>
                                            <?= googlehub_esc(
                                                (string)$property['account_name']
                                            ) ?>
                                            · Property
                                            <?= googlehub_esc(
                                                (string)$property['property_id']
                                            ) ?>
                                        </small>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>

                        <button
                            class="google-primary-button"
                            type="submit"
                        >
                            Use selected GA4 property
                        </button>
                    </form>
                <?php endif; ?>
            </section>

            <section class="ga4-manual-card">
                <h2>Property not listed? Add it by Property ID</h2>

                <p class="ga4-help">
                    Use this when you already know the GA4 Property ID for
                    <?= googlehub_esc($businessName) ?>. Aesthetic Intel will
                    test the property through the Google Analytics Data API
                    before saving it.
                </p>

                <form
                    method="post"
                    action="<?= googlehub_esc(url('business-google-select-ga4')) ?>"
                >
                    <?= csrf_field() ?>
                    <input
                        type="hidden"
                        name="selection_mode"
                        value="manual"
                    >

                    <div class="ga4-manual-grid">
                        <label>
                            <span>GA4 Property ID</span>
                            <input
                                type="text"
                                name="property_id"
                                placeholder="Example: 478490401"
                                inputmode="numeric"
                                required
                            >
                        </label>

                        <label>
                            <span>Business / property label <small>optional</small></span>
                            <input
                                type="text"
                                name="property_name"
                                placeholder="Example: Remedy Medspa Website"
                                maxlength="180"
                            >
                        </label>

                        <label>
                            <span>Google Analytics account label <small>optional</small></span>
                            <input
                                type="text"
                                name="account_name"
                                placeholder="Example: Remedy Medspa"
                                maxlength="180"
                            >
                        </label>
                    </div>

                    <div class="ga4-actions-row">
                        <button
                            class="google-primary-button"
                            type="submit"
                        >
                            Verify &amp; use this Property ID
                        </button>
                    </div>
                </form>

                <p class="ga4-help" style="margin-top:12px">
                    Important: knowing the Property ID is not enough. The
                    Google account connected above must already have permission
                    to read that GA4 property.
                </p>
            </section>

            <section>
                <div class="ga4-actions-row">
                    <form
                        method="post"
                        action="<?= googlehub_esc(url('business-google-connect')) ?>"
                    >
                        <?= csrf_field() ?>
                        <input type="hidden" name="service" value="ga4">

                        <button
                            class="google-secondary-button"
                            type="submit"
                        >
                            Use a different Google account
                        </button>
                    </form>

                    <a
                        class="google-link-button"
                        href="<?= googlehub_esc(url('business-google')) ?>"
                    >
                        Back to Google Connections
                    </a>
                </div>

                <p class="ga4-help">
                    For another Aesthetic Intel business, switch business first,
                    then connect that business's Google account or select its GA4
                    property. Each business keeps its own Google authorization
                    and GA4 mapping.
                </p>
            </section>
        </div>
    </div>
</div>

<script>
(function () {
    var search = document.getElementById('ga4-property-search');
    if (!search) return;

    search.addEventListener('input', function () {
        var q = (search.value || '').trim().toLowerCase();

        document.querySelectorAll('[data-ga4-property]').forEach(function (row) {
            var haystack = (row.getAttribute('data-search') || '').toLowerCase();
            row.style.display = !q || haystack.indexOf(q) !== -1 ? '' : 'none';
        });
    });
})();
</script>
