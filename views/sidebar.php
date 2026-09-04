<?php
$current = $_GET['page'] ?? '';
$superAdmin = auth_is_admin();
$businessView = $superAdmin && admin_business_view_active();
$admin = $superAdmin && !$businessView;
$toolSource = (string)($_GET['source'] ?? '');
$providerKpiBusinessId = (int)(business_context_id() ?? 0);
$businessFeatures = $providerKpiBusinessId > 0
    ? business_feature_effective_states($providerKpiBusinessId)
    : [];

$businessUserBoulevardEnabled =
    !$superAdmin
    && auth_business_id()
    && !empty($businessFeatures['boulevard'])
    && !empty($businessFeatures['boulevard_api'])
        ? !empty(
            boulevard_business_user_access(
                (int)auth_business_id()
            )['enabled']
        )
        : false;

$providerKpiVisible =
    !empty($businessFeatures['provider_kpi'])
    && provider_kpi_navigation_visible($providerKpiBusinessId);

$providerKpiPages = [
    'business-provider-kpi',
    'business-provider-kpi-provider',
    'business-provider-kpi-providers',
    'business-provider-kpi-provider-form',
    'business-provider-kpi-goals',
    'business-provider-kpi-import',
    'business-provider-kpi-import-preview',
    'business-provider-kpi-rankings',
    'business-provider-kpi-opportunities',
    'business-provider-kpi-drilldown',
    'business-provider-kpi-coaching',
    'business-provider-kpi-activity',
];

$adminAiWeeklyPages = [
    'admin-ai-weekly-reports',
    'admin-ai-weekly-report-edit',
    'admin-ai-weekly-report-generate',
    'admin-ai-weekly-report-preview',
    'admin-ai-weekly-report-publish',
    'admin-ai-weekly-report-archive',
    'admin-ai-weekly-report-delete',
];

$businessAiWeeklyPages = [
    'business-ai-weekly-reports',
    'business-ai-weekly-report',
];

$aiWeeklyReportVisible =
    !empty($businessFeatures['ai_weekly_report']);

if (!function_exists('ai_nav_icon')) {
    function ai_nav_icon(string $name): string
    {
        $icons = [
            'overview' => '<path d="M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4zM14 14h6v6h-6z"/>',
            'business' => '<path d="M3 21h18M5 21V7l7-4 7 4v14M9 9h1m4 0h1m-6 4h1m4 0h1m-6 4h6"/>',
            'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
            'upload' => '<path d="M12 16V4m0 0L7 9m5-5 5 5M4 15v5h16v-5"/>',
            'backup' => '<path d="M3 12a9 9 0 1 0 3-6.7L3 8m0-5v5h5M12 7v5l3 2"/>',
            'sparkle' => '<path d="m12 3-1.6 4.4L6 9l4.4 1.6L12 15l1.6-4.4L18 9l-4.4-1.6L12 3ZM5 15l-.8 2.2L2 18l2.2.8L5 21l.8-2.2L8 18l-2.2-.8L5 15Zm14-2-1 2.8-2.8 1L18 18l1 2.8 1-2.8 2.8-1-2.8-1L19 13Z"/>',
            'dashboard' => '<path d="M3 13h8V3H3v10Zm10 8h8V11h-8v10ZM3 21h8v-6H3v6Zm10-12h8V3h-8v6Z"/>',
            'provider' => '<path d="M8 21v-2a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v2M14 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8M3 8h5M5.5 5.5v5"/>',
            'tools' => '<path d="M14.7 6.3a4 4 0 0 0-5-5L12 3.6 9.6 6 7.3 3.7a4 4 0 0 0 5 5L5 16l3 3 7.3-7.3a4 4 0 0 0 5-5L18 9l-2.4-2.4 2.3-2.3a4 4 0 0 0-3.2 2Z"/>',
            'reports' => '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20M4 4.5A2.5 2.5 0 0 1 6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15Z"/>',
            'transfer' => '<path d="M7 7h11l-3-3m3 3-3 3M17 17H6l3 3m-3-3 3-3"/>',
            'settings' => '<path d="M12 15.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Z"/><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06-2.83 2.83-.06-.06A1.7 1.7 0 0 0 15 19.4a1.7 1.7 0 0 0-1 .6 1.7 1.7 0 0 0-.4 1V21h-4v-.09a1.7 1.7 0 0 0-1.4-1.68 1.7 1.7 0 0 0-1.5.48l-.06.06-2.83-2.83.06-.06A1.7 1.7 0 0 0 4.6 15a1.7 1.7 0 0 0-1.6-1H3v-4h.09A1.7 1.7 0 0 0 4.7 8.6a1.7 1.7 0 0 0-.48-1.5l-.06-.06 2.83-2.83.06.06A1.7 1.7 0 0 0 9 4.6a1.7 1.7 0 0 0 1-1.6V3h4v.09a1.7 1.7 0 0 0 1.4 1.61 1.7 1.7 0 0 0 1.5-.48l.06-.06 2.83 2.83-.06.06A1.7 1.7 0 0 0 19.4 9c.12.36.33.7.6 1h1v4h-.09a1.7 1.7 0 0 0-1.51 1Z"/>',
            'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/>',
            'help' => '<circle cx="12" cy="12" r="9"/><path d="M9.7 9a2.4 2.4 0 1 1 3.9 1.9c-.9.7-1.6 1.1-1.6 2.6M12 17h.01"/>',
            'logout' => '<path d="M10 17l5-5-5-5M15 12H3M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/>',
            'chevron' => '<path d="m9 18 6-6-6-6"/>',
            'menu' => '<path d="M4 6h16M4 12h16M4 18h16"/>',
        ];

        $body = $icons[$name] ?? $icons['overview'];

        return '<svg class="nav-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'
            . $body
            . '</svg>';
    }
}

$toolsOpen = in_array(
    $current,
    [
        'business-upload',
        'business-boulevard-integration',
        'business-boulevard-sync',
        'business-boulevard-run',
        'business-boulevard-run-status',

        'business-ga4-integration',
        'business-ga4-test',
        'ga4-test-console',
        'ga4-test-console-run',
        'ga4-test-console-compare',

        'business-gbp',
        'business-gbp-history',
        'business-gbp-report',
        'business-ai-extraction',
    ],
    true
);

$toolsVisible =
    !empty($businessFeatures['gbp'])
    || !empty($businessFeatures['boulevard'])
    || !empty($businessFeatures['podium'])
    || !empty($businessFeatures['growth99'])
    || !empty($businessFeatures['ga4']);
?>

<aside class="sidebar" id="sidebar" aria-label="Main navigation">
    <a
        class="brand sidebar-brand"
        href="<?=$admin ? url('admin-dashboard') : url('business-dashboard')?>"
    >
        <span class="brand-mark">
            <img src="<?=asset('img/logo-mark.svg')?>" alt="">
        </span>

        <span class="brand-copy">
            <strong>Aesthetic Intel</strong>
            <small>Performance intelligence</small>
        </span>
    </a>

    <nav class="nav-list">

        <?php if ($admin): ?>

            <span class="nav-section-label">Workspace</span>

            <a
                class="nav-link <?=$current === 'admin-dashboard' ? 'active' : ''?>"
                href="<?=url('admin-dashboard')?>"
                <?=$current === 'admin-dashboard' ? 'aria-current="page"' : ''?>
            >
                <?=ai_nav_icon('overview')?>
                <span class="nav-text">Overview</span>
            </a>

            <a
                class="nav-link <?=in_array($current, ['admin-businesses', 'admin-business-form'], true) ? 'active' : ''?>"
                href="<?=url('admin-businesses')?>"
            >
                <?=ai_nav_icon('business')?>
                <span class="nav-text">Businesses</span>
            </a>

            <a
                class="nav-link <?=in_array($current, ['admin-users', 'admin-user-form'], true) ? 'active' : ''?>"
                href="<?=url('admin-users')?>"
            >
                <?=ai_nav_icon('users')?>
                <span class="nav-text">Users</span>
            </a>

            <a
                class="nav-link <?=$current === 'admin-feature-availability' ? 'active' : ''?>"
                href="<?=e(url('admin-feature-availability'))?>"
            >
                <span class="nav-icon">⚙</span>
                <span class="nav-text">Feature Availability</span>
            </a>

            <a
                class="nav-link <?=$current === 'admin-uploads' ? 'active' : ''?>"
                href="<?=url('admin-uploads')?>"
            >
                <?=ai_nav_icon('upload')?>
                <span class="nav-text">Upload Monitoring</span>
            </a>

            <span class="nav-section-label">Platform</span>

            <a
                class="nav-link <?=$current === 'admin-backup' ? 'active' : ''?>"
                href="<?=url('admin-backup')?>"
            >
                <?=ai_nav_icon('backup')?>
                <span class="nav-text">Backup &amp; Restore</span>
            </a>

            <a
                class="nav-link <?=$current === 'admin-ai-settings' ? 'active' : ''?>"
                href="<?=url('admin-ai-settings')?>"
            >
                <?=ai_nav_icon('sparkle')?>
                <span class="nav-text">AI Integration</span>
            </a>

            <!-- ==================================================
                 SUPER ADMIN — AI WEEKLY REPORT ACCESS
                 ================================================== -->
            <a
                class="nav-link <?=in_array($current, $adminAiWeeklyPages, true) ? 'active' : ''?>"
                href="<?=url('admin-ai-weekly-reports')?>"
                <?=in_array($current, $adminAiWeeklyPages, true) ? 'aria-current="page"' : ''?>
            >
                <?=ai_nav_icon('sparkle')?>
                <span class="nav-text">AI Weekly Reports</span>
            </a>

            <a
                class="nav-link <?=$current === 'admin-openai-weekly-test' ? 'active' : ''?>"
                href="<?=url('admin-openai-weekly-test')?>"
            >
                <?=ai_nav_icon('tools')?>
                <span class="nav-text">OpenAI Weekly Test</span>
            </a>

            <a
                class="nav-link <?=$current === 'admin-boulevard-report-types' ? 'active' : ''?>"
                href="<?=url('admin-boulevard-report-types')?>"
            >
                <?=ai_nav_icon('tools')?>
                <span class="nav-text">Boulevard Report Types</span>
            </a>

            <span class="nav-section-label">Help</span>

            <a
                class="nav-link <?=$current === 'smart-search' ? 'active' : ''?>"
                href="<?=url('smart-search')?>"
            >
                <?=ai_nav_icon('search')?>
                <span class="nav-text">Smart Search</span>
            </a>

            <a
                class="nav-link <?=$current === 'documentation' ? 'active' : ''?>"
                href="<?=url('documentation')?>"
            >
                <?=ai_nav_icon('help')?>
                <span class="nav-text">Documentation</span>
            </a>

        <?php else: ?>

            <span class="nav-section-label">Workspace</span>

            <a
                class="nav-link <?=$current === 'business-dashboard' ? 'active' : ''?>"
                href="<?=url('business-dashboard')?>"
                <?=$current === 'business-dashboard' ? 'aria-current="page"' : ''?>
            >
                <?=ai_nav_icon('dashboard')?>
                <span class="nav-text">Dashboard</span>
            </a>

            <?php if ($providerKpiVisible): ?>
                <a
                    class="nav-link <?=in_array($current, $providerKpiPages, true) ? 'active' : ''?>"
                    href="<?=url('business-provider-kpi')?>"
                >
                    <?=ai_nav_icon('provider')?>
                    <span class="nav-text">Provider KPI</span>
                </a>
            <?php endif; ?>

            <?php if ($toolsVisible): ?>
                <div
                    class="nav-group <?=$toolsOpen ? 'open' : ''?>"
                    data-nav-group
                >
                    <button
                        class="nav-group-title"
                        type="button"
                        data-nav-group-toggle
                        aria-expanded="<?=$toolsOpen ? 'true' : 'false'?>"
                    >
                        <?=ai_nav_icon('tools')?>
                        <span class="nav-text">Tools</span>
                        <?=ai_nav_icon('chevron')?>
                    </button>

                    <div class="nav-submenu">

                        <?php if (!empty($businessFeatures['gbp'])): ?>
                            <a
                                class="nav-sublink <?=in_array($current, ['business-gbp', 'business-gbp-history', 'business-gbp-report'], true) ? 'active' : ''?>"
                                href="<?=url('business-gbp')?>"
                            >
                                <span>G</span>
                                Google Business Profile
                            </a>
                        <?php endif; ?>

                        <?php if (!empty($businessFeatures['boulevard'])): ?>

                            <?php if ($businessView): ?>
                                <a
                                    class="nav-sublink <?=$current === 'business-upload' ? 'active' : ''?>"
                                    href="<?=url('business-upload')?>"
                                >
                                    <span>B</span>
                                    Boulevard Uploads
                                </a>

                                <?php if (!empty($businessFeatures['boulevard_api'])): ?>
                                    <a
                                        class="nav-sublink <?=in_array($current, ['business-boulevard-integration', 'business-boulevard-sync'], true) ? 'active' : ''?>"
                                        href="<?=url('business-boulevard-integration')?>"
                                    >
                                        <span>↳</span>
                                        API Integration
                                        <em class="nav-beta-badge">BETA</em>
                                    </a>
                                <?php endif; ?>

                            <?php elseif ($businessUserBoulevardEnabled): ?>
                                <a
                                    class="nav-sublink <?=in_array($current, ['business-boulevard-run', 'business-boulevard-run-status'], true) ? 'active' : ''?>"
                                    href="<?=url('business-boulevard-run')?>"
                                >
                                    <span>B</span>
                                    Run Boulevard Report
                                </a>

                            <?php else: ?>
                                <a
                                    class="nav-sublink <?=$current === 'business-upload' ? 'active' : ''?>"
                                    href="<?=url('business-upload')?>"
                                >
                                    <span>B</span>
                                    Boulevard
                                </a>
                            <?php endif; ?>

                        <?php endif; ?>

                        <?php if (!empty($businessFeatures['podium'])): ?>
                            <a
                                class="nav-sublink <?=$current === 'business-ai-extraction' && $toolSource === 'podium' ? 'active' : ''?>"
                                href="<?=url('business-ai-extraction', ['source' => 'podium'])?>"
                            >
                                <span>P</span>
                                Podium
                            </a>
                        <?php endif; ?>

                        <?php if (!empty($businessFeatures['growth99'])): ?>
                            <a
                                class="nav-sublink <?=$current === 'business-ai-extraction' && $toolSource === 'growth99' ? 'active' : ''?>"
                                href="<?=url('business-ai-extraction', ['source' => 'growth99'])?>"
                            >
                                <span>99</span>
                                Growth99+
                            </a>
                        <?php endif; ?>

                        <?php if (!empty($businessFeatures['ga4'])): ?>

                            <?php if ($businessView): ?>

                                <a
    class="nav-sublink <?=$current === 'business-ai-extraction' && $toolSource === 'ga4' ? 'active' : ''?>"
    href="<?=url('business-ai-extraction', ['source' => 'ga4'])?>"
>
    <span>G4</span>
    Google Analytics 4
</a>

                                <a
                                    class="nav-sublink <?=in_array($current, ['ga4-test-console', 'ga4-test-console-run', 'ga4-test-console-compare'], true) ? 'active' : ''?>"
                                    href="<?=url('ga4-test-console')?>"
                                >
                                    <span>↳</span>
                                    Brospro API Test
                                    <em class="nav-beta-badge">TEST</em>
                                </a>
                              <a
    href="<?= e(
        url(
            'boulevard-live-console'
        )
    ) ?>"
    class="
        nav-sublink
        boulevard-api-nav
        <?= (($page ?? '') === 'boulevard-live-console')
            ? 'active'
            : ''
        ?>
    "
    title="Boulevard API Test"
>

    <span class="boulevard-api-nav__icon">
        B
    </span>


    <div class="boulevard-api-nav__copy">

        <span class="boulevard-api-nav__label">
            Boulevard API
        </span>

        <small class="boulevard-api-nav__sub">
            Test
        </small>

    </div>


    <em class="boulevard-api-nav__badge">
        TEST
    </em>

</a>
                            <?php else: ?>

                                <a
                                    class="nav-sublink <?=$current === 'business-ai-extraction' && $toolSource === 'ga4' ? 'active' : ''?>"
                                    href="<?=url('business-ai-extraction', ['source' => 'ga4'])?>"
                                >
                                    <span>G4</span>
                                    Google Analytics 4
                                </a>

                            <?php endif; ?>

                        <?php endif; ?>

                    </div>
                </div>
            <?php endif; ?>

            <a
                class="nav-link <?=in_array($current, ['business-history', 'business-report', 'business-unified-report', 'business-ai-reviewed-report'], true) ? 'active' : ''?>"
                href="<?=url('business-history')?>"
            >
                <?=ai_nav_icon('reports')?>
                <span class="nav-text">Reports &amp; Downloads</span>
            </a>

            <!-- ==================================================
                 BUSINESS — AI WEEKLY REPORT ACCESS

                 Visible only when the Super Admin has enabled
                 ai_weekly_report for the current business.
                 Works both for a normal business user and for a
                 Super Admin using Business View.
                 ================================================== -->
            <?php if ($aiWeeklyReportVisible): ?>
                <a
                    class="nav-link <?=in_array($current, $businessAiWeeklyPages, true) ? 'active' : ''?>"
                    href="<?=url('business-ai-weekly-reports')?>"
                    <?=in_array($current, $businessAiWeeklyPages, true) ? 'aria-current="page"' : ''?>
                >
                    <?=ai_nav_icon('sparkle')?>
                    <span class="nav-text">AI Weekly Reports</span>
                </a>
            <?php endif; ?>

            <?php if (!empty($businessFeatures['data_transfer'])): ?>
                <a
                    class="nav-link <?=$current === 'business-data-transfer' ? 'active' : ''?>"
                    href="<?=url('business-data-transfer')?>"
                >
                    <?=ai_nav_icon('transfer')?>
                    <span class="nav-text">Data Transfer</span>
                </a>
            <?php endif; ?>

            <a
                class="nav-link <?=$current === 'business-settings' ? 'active' : ''?>"
                href="<?=url('business-settings')?>"
            >
                <?=ai_nav_icon('settings')?>
                <span class="nav-text">Settings</span>
            </a>

            <span class="nav-section-label">Help</span>

            <?php if (!empty($businessFeatures['smart_search'])): ?>
                <a
                    class="nav-link <?=$current === 'smart-search' ? 'active' : ''?>"
                    href="<?=url('smart-search')?>"
                >
                    <?=ai_nav_icon('search')?>
                    <span class="nav-text">Smart Search</span>
                </a>
            <?php endif; ?>

            <a
                class="nav-link <?=$current === 'documentation' ? 'active' : ''?>"
                href="<?=url('documentation')?>"
            >
                <?=ai_nav_icon('help')?>
                <span class="nav-text">Documentation</span>
            </a>

        <?php endif; ?>

    </nav>

    <div class="sidebar-user">
        <div class="avatar">
            <?=e(strtoupper(substr(auth_user()['name'] ?? 'U', 0, 1)))?>
        </div>

        <div class="sidebar-user-copy">
            <strong><?=e(auth_user()['name'] ?? '')?></strong>
            <small>
                <?=e(
                    $businessView
                        ? 'Super Admin · ' . (admin_business_view()['name'] ?? 'Business View')
                        : (auth_user()['email'] ?? '')
                )?>
            </small>
        </div>

        <a
            class="sidebar-logout"
            href="<?=base_url('logout.php')?>"
            title="Log out"
            aria-label="Log out"
        >
            <?=ai_nav_icon('logout')?>
        </a>
    </div>
</aside>

<div class="sidebar-scrim" data-sidebar-scrim></div>

<div class="mobile-app-bar no-print">
    <button
        class="mobile-menu"
        type="button"
        data-sidebar-toggle
        aria-label="Open navigation"
    >
        <?=ai_nav_icon('menu')?>
    </button>

    <a
        class="mobile-brand"
        href="<?=$admin ? url('admin-dashboard') : url('business-dashboard')?>"
    >
        <img src="<?=asset('img/logo-mark.svg')?>" alt="">
        <span>Aesthetic Intel</span>
    </a>
</div>
