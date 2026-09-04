<?php
$activeBusiness = admin_business_view();
$switchBusinesses = db()->query("SELECT id,name FROM businesses WHERE status='active' ORDER BY name")->fetchAll();
?>
<?php if($activeBusiness):?>
<section class="admin-business-bar no-print" aria-label="Super Admin business view">
 <div class="admin-business-context">
  <span class="admin-business-badge">Super Admin View</span>
  <div><strong>Viewing <?=e($activeBusiness['name'])?></strong><small>Your Super Admin identity and audit trail remain active.</small></div>
 </div>
 <div class="admin-business-actions">
  <form method="post" action="<?=url('admin-business-view')?>" class="admin-business-switch-form">
   <?=csrf_field()?>
   <label for="admin-business-switch">Switch business</label>
   <select id="admin-business-switch" name="business_id" onchange="this.form.submit()">
    <?php foreach($switchBusinesses as $switchBusiness):?>
     <option value="<?=e($switchBusiness['id'])?>" <?=(int)$switchBusiness['id']===(int)$activeBusiness['id']?'selected':''?>><?=e($switchBusiness['name'])?></option>
    <?php endforeach;?>
   </select>
   <input type="hidden" name="destination" value="dashboard">
   <button class="btn btn-secondary btn-small" type="submit">Switch</button>
  </form>
  <form method="post" action="<?=url('admin-business-view-exit')?>">
   <?=csrf_field()?>
   <button class="btn btn-secondary btn-small" type="submit">Return to Admin</button>
  </form>
 </div>
</section>
<?php endif;?>
