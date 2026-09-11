<div class="page-head">
    <div>
        <span class="eyebrow">Super Admin</span>
        <h1>Businesses</h1>
        <p>Create business accounts, manage access, and configure which dashboard features each business uses.</p>
    </div>

    <a class="btn btn-primary" href="<?=url('admin-business-form')?>">+ Add Business</a>
</div>

<section class="panel ai-businesses-panel">
    <!-- <div class="ai-businesses-guidance">
        <div>
            <strong>Business setup and feature access are separate actions.</strong>
            <span><b>Edit</b> manages the business profile and appearance. <b>Features</b> opens the enable/disable controls for that workspace.</span>
        </div>
    </div> -->

    <div class="table-wrap">
        <table class="ai-businesses-table">
            <thead>
                <tr>
                    <th>Business</th>
                    <th>Status</th>
                    <th>Users</th>
                    <th>Timezone</th>
                    <th>Last Report</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php
            $visibleBusinessCount = 0;
            foreach ($businesses as $b):
                /*
                 * TEMPORARILY HIDDEN — Brospro GA4 test business.
                 *
                 * The database record and all existing routes/data are intentionally
                 * retained. Only this Super Admin business listing is hiding the test
                 * workspace so it does not distract from real client businesses.
                 */
                if (strcasecmp(trim((string)($b['name'] ?? '')), 'Brospro GA4 test') === 0) {
                    continue;
                }

                $visibleBusinessCount++;
            ?>
                <tr>
                    <td>
                        <strong><?=e($b['name'])?></strong>
                        <small class="block"><?=e($b['contact_email'] ?: 'No contact email')?></small>
                    </td>

                    <td>
                        <span class="status status-<?=e($b['status'])?>"><?=e(ucfirst($b['status']))?></span>
                    </td>

                    <td><?=numfmt($b['user_count'])?></td>
                    <td><?=e($b['timezone'])?></td>
                    <td><?=e($b['last_report_at'] ? reporting_us_date($b['last_report_at']) : 'No reports')?></td>

                    <td class="ai-business-actions-cell">
                        <div class="ai-business-actions" aria-label="Actions for <?=e($b['name'])?>">
                            <a
                                class="ai-business-action ai-business-action-edit"
                                href="<?=e(url('admin-business-form', ['id' => $b['id']]) . '#business-profile')?>"
                                title="Edit business profile and appearance"
                            >
                                <span class="ai-business-action-label">Edit</span>
                                <small>Profile &amp; appearance</small>
                            </a>

                            <a
                                class="ai-business-action ai-business-action-features"
                                href="<?=e(url('admin-business-form', ['id' => $b['id']]) . '#business-feature-controls')?>"
                                title="Enable or disable dashboard features"
                            >
                                <span class="ai-business-action-label">Features</span>
                                <small>Enable / disable</small>
                            </a>

                            <?php if ($b['status'] === 'active'): ?>
                                <form method="post" action="<?=url('admin-business-view')?>" class="inline-action-form ai-business-open-form">
                                    <?=csrf_field()?>
                                    <input type="hidden" name="business_id" value="<?=e($b['id'])?>">
                                    <input type="hidden" name="destination" value="dashboard">
                                    <button class="ai-business-action ai-business-action-open" type="submit" title="Open this client's dashboard with Super Admin access">
                                        <span class="ai-business-action-label">Open as Business</span>
                                        <small>Enter workspace</small>
                                    </button>
                                </form>
                            <?php else: ?>
                                <span class="ai-business-action ai-business-action-disabled">
                                    <span class="ai-business-action-label">Inactive</span>
                                    <small>Workspace unavailable</small>
                                </span>
                            <?php endif; ?>

                            <a
                                class="ai-business-action ai-business-action-delete"
                                href="<?=url('admin-business-delete', ['id' => $b['id']])?>"
                                title="Delete this business"
                            >
                                <span class="ai-business-action-label">Delete</span>
                                <small>Permanent action</small>
                            </a>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>

            <?php if ($visibleBusinessCount === 0): ?>
                <tr><td colspan="6" class="empty-cell">No businesses yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
