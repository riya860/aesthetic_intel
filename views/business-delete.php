<div class="page-head">
 <div><span class="eyebrow">Super Admin · Security confirmation</span><h1>Delete <?=e($business['name'])?></h1><p>Permanently remove this business and all of its Aesthetic Intel data.</p></div>
 <a class="btn btn-secondary" href="<?=url('admin-businesses')?>">Cancel</a>
</div>

<section class="panel">
 <div class="alert alert-warning"><strong>This action cannot be undone.</strong> Deleting this business removes its business users, reports, uploaded report files, Provider KPI data, goals, coaching records, integrations, settings, and business logo. Platform-level Super Admin accounts and other businesses are not affected.</div>

 <div class="backup-preview-grid">
  <article><small>Business users</small><strong><?=numfmt($summary['users'])?></strong></article>
  <article><small>Boulevard reports</small><strong><?=numfmt($summary['reports'])?></strong></article>
  <article><small>Provider profiles</small><strong><?=numfmt($summary['providers'])?></strong></article>
  <article><small>Provider KPI values</small><strong><?=numfmt($summary['provider_values'])?></strong></article>
  <article><small>Provider KPI goals</small><strong><?=numfmt($summary['provider_goals'])?></strong></article>
  <article><small>Coaching / actions</small><strong><?=numfmt($summary['provider_reviews']+$summary['provider_actions'])?></strong></article>
 </div>

 <div class="danger-zone">
  <h3>Confirm permanent deletion</h3>
  <p>For security, enter the password for your currently signed-in <strong>Super Admin account</strong>. A business user's password will not work.</p>
  <form method="post" action="<?=url('admin-business-delete',['id'=>$business['id']])?>" class="stack-form" autocomplete="off">
   <?=csrf_field()?>
   <input type="hidden" name="business_id" value="<?=e($business['id'])?>">
   <label><span>Super Admin password</span><input type="password" name="admin_password" autocomplete="current-password" required autofocus></label>
   <label class="checkbox-row"><input type="checkbox" name="confirm_delete" value="1" required> <span>I understand that <strong><?=e($business['name'])?></strong> and its business data will be permanently deleted.</span></label>
   <div class="page-actions"><a class="btn btn-secondary" href="<?=url('admin-businesses')?>">Keep Business</a><button class="btn btn-danger" type="submit">Delete Business Permanently</button></div>
  </form>
 </div>
</section>
