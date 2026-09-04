<?php
$preview = $restorePreview ?? null;
$caps = $capabilities ?? [];
$auto = $automaticSettings ?? [];
$history = $automaticHistory ?? [];
$retryState = $automaticRetryState ?? [];
$retryRow = $retryState['history'] ?? null;
$canResetRetry = !empty($retryState['can_reset']);
$passwordConfigured = !empty($auto['password_encrypted']);
$autoTimezone = (string)($auto['timezone'] ?? 'UTC');
$formatInBackupTimezone = static function (?string $value) use ($autoTimezone): string {
    if (!$value) return 'Never';
    try {
        $dt = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        return $dt->setTimezone(new DateTimeZone($autoTimezone))->format('m-d-Y g:i A T');
    } catch (Throwable) { return (string)$value; }
};
?>
<div class="page-head">
  <div><span class="eyebrow">SUPER ADMIN</span><h1>Backup & Restore</h1><p>Create protected full-site backups, schedule verified daily backups, or restore a previous snapshot.</p></div>
</div>

<div class="info-callout backup-warning">
  <strong>Full-site protection</strong>
  <span>The backup includes the database, users, businesses, reports, settings, encrypted API credentials, application files, logos, and uploaded report files. Host-specific database credentials are intentionally excluded. Automatic backups are stored inside protected server storage and are never publicly accessible.</span>
</div>

<section class="panel">
  <div class="panel-head"><div><h2>Server readiness</h2><p>Backup and restore are blocked automatically if any required safeguard is unavailable.</p></div></div>
  <div class="backup-readiness-grid">
    <article class="<?=!empty($caps['zip'])?'ready':'blocked'?>"><strong>ZIP support</strong><span><?=!empty($caps['zip'])?'Ready':'Missing'?></span></article>
    <article class="<?=!empty($caps['sodium'])?'ready':'blocked'?>"><strong>Encrypted backups</strong><span><?=!empty($caps['sodium'])?'Ready':'Sodium missing'?></span></article>
    <article class="<?=!empty($caps['storage_writable'])?'ready':'blocked'?>"><strong>Protected storage</strong><span><?=!empty($caps['storage_writable'])?'Writable':'Not writable'?></span></article>
    <article class="ready"><strong>Maximum restore upload</strong><span><?=e(site_backup_human_bytes((int)($caps['upload_limit']??0)))?></span></article>
  </div>
</section>

<section class="panel auto-backup-panel">
  <div class="panel-head">
    <div><span class="eyebrow">AUTOMATIC PROTECTION</span><h2>Automatic Daily Full-System Backup</h2><p>Create one encrypted, fully verified snapshot every day without keeping a browser open.</p></div>
    <span class="status-pill <?=!empty($auto['enabled'])?'success':'neutral'?>"><?=!empty($auto['enabled'])?'Enabled':'Disabled'?></span>
  </div>

  <div class="auto-backup-summary">
    <article><small>Encryption password</small><strong><?=$passwordConfigured?'Configured':'Not configured'?></strong></article>
    <article><small>Next backup</small><strong><?=!empty($auto['enabled'])?e((string)($automaticNext['label']??'Pending cron check')):'Automatic backup disabled'?></strong></article>
    <article><small>Last verified backup</small><strong><?=e($formatInBackupTimezone($auto['last_success_at']??null))?></strong></article>
    <article><small>Retention</small><strong>Latest <?=e((string)($auto['retention_days']??14))?> daily backups</strong></article>
  </div>

  <form method="post" action="<?=url('admin-backup')?>" class="stack-form auto-backup-settings-form">
    <?=csrf_field()?>
    <input type="hidden" name="action" value="save_auto_settings">
    <label class="toggle-row"><span><strong>Enable automatic daily backup</strong><small>The cron runner checks the schedule and creates only one daily backup.</small></span><input type="checkbox" name="enabled" value="1" <?=!empty($auto['enabled'])?'checked':''?>></label>
    <div class="form-grid three">
      <label>Backup time<span>Uses the timezone selected below.</span><input type="time" name="backup_time" step="300" value="<?=e(substr((string)($auto['backup_time']??'03:00:00'),0,5))?>" required></label>
      <label>Timezone<span>Use an IANA timezone such as America/Denver.</span><input type="text" name="timezone" list="backup-timezones" value="<?=e($autoTimezone)?>" autocomplete="off" required></label>
      <label>Retention<span>Old backups are removed only after a new backup is verified.</span><select name="retention_days" required><?php foreach([7,14,30] as $days):?><option value="<?=$days?>" <?=(int)($auto['retention_days']??14)===$days?'selected':''?>><?=$days?> days</option><?php endforeach;?></select></label>
    </div>
    <datalist id="backup-timezones">
      <option value="UTC"><option value="America/Denver"><option value="America/Chicago"><option value="America/New_York"><option value="America/Los_Angeles"><option value="America/Phoenix"><option value="Asia/Kolkata">
    </datalist>
    <div class="form-grid two">
      <label><?=$passwordConfigured?'Change encryption password':'Encryption password'?><span><?=$passwordConfigured?'Leave blank to keep the current password. Existing retained backups keep their own encrypted password reference.':'Minimum 10 characters. Required before enabling automatic backup.'?></span><input type="password" name="backup_password" minlength="10" autocomplete="new-password"></label>
      <label>Confirm password<input type="password" name="backup_password_confirm" minlength="10" autocomplete="new-password"></label>
    </div>
    <div class="page-actions">
      <button class="btn btn-primary" type="submit" <?=empty($caps['zip'])||empty($caps['sodium'])||empty($caps['storage_writable'])?'disabled':''?>>Save Automatic Backup Settings</button>
    </div>
  </form>

  <div class="cron-setup-card">
    <div><strong>One-time Hostinger cron setup</strong><p>Create one cron job that runs every 5 minutes. Aesthetic Intel itself decides whether the selected daily backup is due, so repeated cron checks do not create duplicate backups.</p></div>
    <code><?=e((string)($cronCommand??''))?></code>
    <small>Schedule: <strong>*/5 * * * *</strong>. If your hosting plan allows a different minimum interval, the runner is still safe; it creates at most one scheduled backup per local day and retries a failed run safely.</small>
  </div>

  <div class="page-actions auto-backup-test-actions">
    <form method="post" action="<?=url('admin-backup')?>" data-backup-submit="Creating and verifying test backup…">
      <?=csrf_field()?>
      <input type="hidden" name="action" value="test_auto_backup">
      <button class="btn btn-secondary" type="submit" <?=!$passwordConfigured||empty($caps['zip'])||empty($caps['sodium'])||empty($caps['storage_writable'])?'disabled':''?>>Run Test Backup Now</button>
    </form>
    <span class="muted">A test backup uses the configured encryption password and is saved in Backup History. It does not affect the daily schedule.</span>
  </div>

  <?php if($canResetRetry):?>
  <div class="info-callout alert-warning" style="margin-top:16px">
    <strong>Scheduled backup retry limit reached</strong>
    <span>Today has <?=e((string)($retryState['attempt_count']??0))?> failed automatic attempt<?=((int)($retryState['attempt_count']??0)===1)?'':'s'?>. Resetting retries does not delete or change any backup file or failure record; it only lets the next cron check try again immediately.</span>
    <form method="post" action="<?=url('admin-backup')?>" class="inline-form" onsubmit="return confirm('Reset today\'s automatic backup retry counter? Existing backup files and failure history will remain unchanged.');">
      <?=csrf_field()?>
      <input type="hidden" name="action" value="reset_auto_retry">
      <button class="btn btn-secondary" type="submit">Reset Today’s Automatic Backup Retry</button>
    </form>
  </div>
  <?php endif;?>
</section>

<section class="panel">
  <div class="panel-head"><div><h2>Backup History</h2><p>Only a backup that passes archive, encryption, manifest, table/file count, and SHA-256 verification is labeled <strong>Backup Verified</strong>.</p></div></div>
  <?php if(!$history):?>
    <div class="empty-cell"><strong>No retained backups yet.</strong><p>Save the automatic backup password, then run a test backup or let the daily cron create the first verified snapshot.</p></div>
  <?php else:?>
    <div class="table-wrap backup-history-table"><table>
      <thead><tr><th>Created</th><th>Type</th><th>Status</th><th>Size</th><th>Snapshot</th><th>SHA-256</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach($history as $row):
        $status=(string)$row['status'];$verified=$status==='verified';$deleted=$status==='deleted';
      ?>
        <tr>
          <td><strong><?=e($formatInBackupTimezone($row['completed_at']??$row['created_at']??null))?></strong><?php if(!empty($row['scheduled_local_date'])):?><small>Scheduled <?=e($row['scheduled_local_date'])?></small><?php endif;?></td>
          <td><span class="source-chip"><?=($row['run_type']??'automatic')==='manual_test'?'Test':'Daily'?></span></td>
          <td>
            <?php if($verified):?><span class="status-pill success">Backup Verified</span>
            <?php elseif($deleted):?><span class="status-pill neutral"><?=($row['deleted_reason']??'')==='retention'?'Retention removed':'Deleted'?></span>
            <?php else:?><span class="status-pill danger">Failed safely</span><?php endif;?>
            <?php if(!empty($row['validation_message'])):?><small class="backup-history-note"><?=e($row['validation_message'])?></small><?php elseif(!empty($row['error_message'])):?><small class="backup-history-note"><?=e($row['error_message'])?></small><?php endif;?>
          </td>
          <td><?=e(site_backup_human_bytes((int)($row['size_bytes']??0)))?></td>
          <td><small><?=numfmt((int)($row['table_count']??0))?> tables · <?=numfmt((int)($row['file_count']??0))?> files</small><small><?=numfmt((int)($row['business_count']??0))?> businesses · <?=numfmt((int)($row['user_count']??0))?> users</small></td>
          <td><code class="backup-hash"><?=e(!empty($row['sha256'])?substr((string)$row['sha256'],0,12).'…':'—')?></code></td>
          <td class="actions backup-history-actions">
            <?php if($verified):?>
              <a class="btn btn-ghost btn-sm" href="<?=url('admin-backup',['action'=>'download_saved','id'=>(int)$row['id']])?>">Download</a>
              <form method="post" action="<?=url('admin-backup')?>" data-backup-submit="Validating retained backup…"><?=csrf_field()?><input type="hidden" name="action" value="validate_saved"><input type="hidden" name="history_id" value="<?=(int)$row['id']?>"><button class="btn btn-ghost btn-sm" type="submit">Validate</button></form>
              <form method="post" action="<?=url('admin-backup')?>" data-backup-submit="Validating backup before restore…"><?=csrf_field()?><input type="hidden" name="action" value="restore_saved"><input type="hidden" name="history_id" value="<?=(int)$row['id']?>"><button class="btn btn-secondary btn-sm" type="submit">Restore</button></form>
              <form method="post" action="<?=url('admin-backup')?>" onsubmit="return confirm('Delete this retained backup permanently?');"><?=csrf_field()?><input type="hidden" name="action" value="delete_saved"><input type="hidden" name="history_id" value="<?=(int)$row['id']?>"><input type="hidden" name="delete_confirmation" value="DELETE"><button class="btn btn-danger btn-sm" type="submit">Delete</button></form>
            <?php else:?><span class="muted">No stored file</span><?php endif;?>
          </td>
        </tr>
      <?php endforeach;?>
      </tbody>
    </table></div>
  <?php endif;?>
</section>

<div class="backup-layout">
  <section class="panel backup-card">
    <div class="panel-head"><div><h2>Download manual full backup</h2><p>Creates an encrypted ZIP and downloads it directly. Nothing from this manual download is retained on the server after the download begins.</p></div><span class="source-icon">↓</span></div>
    <form method="post" action="<?=url('admin-backup')?>" class="stack-form" data-backup-submit="Creating encrypted backup…">
      <?=csrf_field()?>
      <input type="hidden" name="action" value="create">
      <label>Backup password<span>Use at least 10 characters. You must enter the same password during restore.</span><input type="password" name="backup_password" minlength="10" autocomplete="new-password" required></label>
      <label>Confirm backup password<input type="password" name="backup_password_confirm" minlength="10" autocomplete="new-password" required></label>
      <button class="btn btn-primary" type="submit" <?=empty($caps['zip'])||empty($caps['sodium'])||empty($caps['storage_writable'])?'disabled':''?>>Create & Download Backup</button>
    </form>
  </section>

  <section class="panel backup-card">
    <div class="panel-head"><div><h2>Upload backup</h2><p>The file is decrypted, integrity-checked, and compared with the current database structure before the Restore button appears.</p></div><span class="source-icon">↑</span></div>
    <form method="post" action="<?=url('admin-backup')?>" enctype="multipart/form-data" class="stack-form" data-backup-submit="Validating backup…">
      <?=csrf_field()?>
      <input type="hidden" name="action" value="inspect">
      <label>Aesthetic Intel backup ZIP<input type="file" name="backup_zip" accept=".zip,application/zip" required></label>
      <label>Backup password<input type="password" name="backup_password" minlength="10" autocomplete="current-password" required></label>
      <button class="btn btn-secondary" type="submit" <?=empty($caps['zip'])||empty($caps['sodium'])||empty($caps['storage_writable'])?'disabled':''?>>Validate Backup</button>
    </form>
  </section>
</div>

<?php if(is_array($preview)):?>
<section class="panel restore-preview">
  <div class="panel-head"><div><span class="eyebrow">VALIDATED</span><h2>Backup is ready to restore</h2><p>Nothing has been changed yet. Review the details below before starting.</p></div><span class="status-pill success">Integrity verified</span></div>
  <div class="backup-preview-grid">
    <article><small>Created</small><strong><?=e(date('m-d-Y g:i A',strtotime((string)$preview['created_at'])))?> UTC</strong></article>
    <article><small>Source</small><strong><?=e($preview['source_host']?:'Not recorded')?></strong></article>
    <article><small>Version</small><strong><?=e($preview['app_version'])?></strong></article>
    <article><small>Database tables</small><strong><?=numfmt($preview['table_count'])?></strong></article>
    <article><small>Site files</small><strong><?=numfmt($preview['file_count'])?></strong></article>
    <article><small>Businesses / users</small><strong><?=numfmt($preview['business_count'])?> / <?=numfmt($preview['user_count'])?></strong></article>
  </div>
  <div class="danger-zone">
    <h3>This replaces the entire current Aesthetic Intel installation</h3>
    <p>A fresh automatic rollback snapshot is created first. If any database or file validation fails, the system returns to the current working state.</p>
    <form method="post" action="<?=url('admin-backup')?>" class="stack-form" data-backup-submit="Restoring Aesthetic Intel… Do not close this tab.">
      <?=csrf_field()?>
      <input type="hidden" name="action" value="restore">
      <input type="hidden" name="restore_token" value="<?=e($preview['token'])?>">
      <label>Type <strong>RESTORE</strong> to continue<input type="text" name="restore_confirmation" autocomplete="off" pattern="RESTORE" required></label>
      <div class="page-actions"><button class="btn btn-danger" type="submit">Restore Full Backup</button></div>
    </form>
    <form method="post" action="<?=url('admin-backup')?>" class="inline-form">
      <?=csrf_field()?>
      <input type="hidden" name="action" value="cancel">
      <button class="btn btn-ghost" type="submit">Cancel validated backup</button>
    </form>
  </div>
</section>
<?php endif;?>

<div class="ai-upload-overlay" data-backup-overlay aria-hidden="true">
  <div class="ai-upload-dialog"><div class="ai-loader-ring"></div><h2 data-backup-title>Working…</h2><p>Large backups can take several minutes. Please keep this tab open for manual/test actions.</p></div>
</div>
