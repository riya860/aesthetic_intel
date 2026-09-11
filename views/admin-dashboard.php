<div class="page-head ai-admin-page-head">
    <div>
        <span class="eyebrow">Super Admin</span>
        <h1>Platform Overview</h1>
        <p>A focused view of Aesthetic Intel — manage client workspaces, connected intelligence, and the reporting experiences your businesses use.</p>
    </div>

    <a class="btn btn-primary" href="<?=url('admin-business-form')?>">+ Add Business</a>
</div>

<div class="kpi-grid four ai-admin-kpi-grid">
    <article class="kpi-card">
        <small>Active Businesses</small>
        <strong><?=numfmt($counts['businesses'])?></strong>
        <span class="ai-admin-kpi-note">Client workspaces currently active</span>
    </article>

    <article class="kpi-card">
        <small>Business Users</small>
        <strong><?=numfmt($counts['users'])?></strong>
        <span class="ai-admin-kpi-note">Active client-facing accounts</span>
    </article>

    <article class="kpi-card">
        <small>Completed Reports</small>
        <strong><?=numfmt($counts['reports'])?></strong>
        <span class="ai-admin-kpi-note">Processed reporting snapshots</span>
    </article>

    <article class="kpi-card">
        <small>Failed Batches</small>
        <strong><?=numfmt($counts['failed'])?></strong>
        <span class="ai-admin-kpi-note">Items that may need attention</span>
    </article>
</div>

<section class="panel ai-admin-intelligence-panel">
    <div class="ai-admin-intelligence-head">
        <div class="ai-admin-product-lockup">
            <span class="ai-admin-product-mark ai-admin-original-logo" aria-label="Aesthetic Intel">
                <img class="ai-theme-logo ai-theme-logo-on-light" src="<?=asset('img/aesthetic-intel-logo-on-light.png')?>" alt="">
                <img class="ai-theme-logo ai-theme-logo-on-dark" src="<?=asset('img/aesthetic-intel-logo-on-dark.png')?>" alt="">
            </span>

            <div>
                <span class="eyebrow">Aesthetic Intel</span>
                <h2>Turn connected business data into clear next actions.</h2>
                <p>
                    Aesthetic Intel brings marketing, operational, revenue, provider, and weekly reporting data into one workspace. The executive layer keeps the most important signals visible, while deeper source-level analysis stays available when it is needed.
                </p>
            </div>
        </div>

        <a class="btn btn-secondary" href="<?=url('admin-businesses')?>">Manage Businesses</a>
    </div>

    <div class="ai-admin-capability-grid" aria-label="Aesthetic Intel platform workflow">
        <article class="ai-admin-capability-card">
            <span class="ai-admin-step">01</span>
            <div class="ai-admin-capability-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 3v12"/><path d="m8 11 4 4 4-4"/><path d="M5 19h14"/>
                </svg>
            </div>
            <h3>Connect the sources</h3>
            <p>Bring GA4, Google Business Profile, Boulevard, uploaded reports, and other enabled sources into the same business context.</p>
            <div class="ai-admin-source-chips" aria-label="Common connected sources">
                <span>GA4</span><span>GBP</span><span>Boulevard</span><span>Reports</span>
            </div>
        </article>

        <article class="ai-admin-capability-card">
            <span class="ai-admin-step">02</span>
            <div class="ai-admin-capability-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M4 19V9"/><path d="M10 19V5"/><path d="M16 19v-7"/><path d="M22 19V3"/>
                </svg>
            </div>
            <h3>See the signal first</h3>
            <p>Surface the KPIs, movement, risks, and opportunities that deserve attention instead of putting every API field on the first screen.</p>
            <a class="ai-admin-text-link" href="<?=url('admin-ai-settings')?>">Review AI configuration <span aria-hidden="true">→</span></a>
        </article>

        <article class="ai-admin-capability-card">
            <span class="ai-admin-step">03</span>
            <div class="ai-admin-capability-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M5 20V10"/><path d="M12 20V4"/><path d="M19 20v-7"/><path d="m4 6 4-3 4 3 4-3 4 3"/>
                </svg>
            </div>
            <h3>Turn insight into reporting</h3>
            <p>Use focused dashboards and AI Weekly Reports for decision-ready summaries, while preserving detailed analysis for validation and deeper investigation.</p>
            <a class="ai-admin-text-link" href="<?=url('admin-ai-weekly-reports')?>">Open AI Weekly Reports <span aria-hidden="true">→</span></a>
        </article>
    </div>

    <div class="ai-admin-quick-row">
        <div class="ai-admin-quick-copy">
            <strong>Choose the workspace, then go deeper only when needed.</strong>
            <span>Business settings, feature controls, AI review, and reporting stay separated so each administrative task has a clear purpose.</span>
        </div>

        <div class="ai-admin-quick-actions">
            <a href="<?=url('admin-businesses')?>">Businesses</a>
            <a href="<?=url('admin-ai-settings')?>">Review with AI</a>
            <a href="<?=url('admin-ai-weekly-reports')?>">Weekly Reports</a>
        </div>
    </div>
</section>
