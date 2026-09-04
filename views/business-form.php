<?php
$featureDefinitions=$featureDefinitions??business_feature_definitions();
$currentFeatureStates=$featureStates??[];
if(is_post()&&$business){
    foreach($featureDefinitions as $featureCode=>$definition)$currentFeatureStates[$featureCode]=isset($_POST['feature_'.$featureCode]);
    if(empty($currentFeatureStates['boulevard']))$currentFeatureStates['boulevard_api']=false;
}
$featureGroups=[];
foreach($featureDefinitions as $featureCode=>$definition)$featureGroups[(string)$definition['group']][$featureCode]=$definition;
$enabledFeatureCount=0;
foreach($featureDefinitions as $featureCode=>$definition)if(!empty($currentFeatureStates[$featureCode]))$enabledFeatureCount++;
?>
<div class="page-head">
 <div>
  <span class="eyebrow">Business setup</span>
  <h1><?=e($title)?></h1>
  <p>Manage business details, branding, and the exact dashboard features this business should use.</p>
 </div>
 <div class="page-actions">
  <a class="btn btn-secondary" href="<?=url('admin-businesses')?>">Back</a>
  <?php if($business):?><a class="btn btn-danger" href="<?=url('admin-business-delete',['id'=>$business['id']])?>">Delete Business</a><?php endif;?>
 </div>
</div>

<form method="post" class="panel form-panel feature-controls-form" data-feature-controls-form>
 <?=csrf_field()?>
 <div class="form-grid two">
  <label>Business name<input name="name" required value="<?=e($_POST['name']??$business['name']??'')?>"></label>
  <label>Status<select name="status"><option value="active" <?=($_POST['status']??$business['status']??'active')==='active'?'selected':''?>>Active</option><option value="inactive" <?=($_POST['status']??$business['status']??'')==='inactive'?'selected':''?>>Inactive</option></select></label>
  <label>Contact name<input name="contact_name" value="<?=e($_POST['contact_name']??$business['contact_name']??'')?>"></label>
  <label>Contact email<input type="email" name="contact_email" value="<?=e($_POST['contact_email']??$business['contact_email']??'')?>"></label>
  <label>Phone<input name="phone" value="<?=e($_POST['phone']??$business['phone']??'')?>"></label>
  <label>Timezone<input name="timezone" required value="<?=e($_POST['timezone']??$business['timezone']??'America/Denver')?>"></label>
  <label>Primary colour<input type="color" name="primary_color" value="<?=e($_POST['primary_color']??$business['primary_color']??'#12336b')?>"></label>
  <label>Accent colour<input type="color" name="accent_color" value="<?=e($_POST['accent_color']??$business['accent_color']??'#0f766e')?>"></label>
 </div>

 <?php if($business):?>
 <section class="feature-controls-shell" aria-labelledby="feature-controls-heading">
  <div class="feature-controls-head">
   <div>
    <span class="eyebrow">Workspace configuration</span>
    <h2 id="feature-controls-heading">Feature Controls</h2>
    <p>Show only the tools this business needs. Turning a feature off hides it from the dashboard/navigation and blocks direct access, but <strong>does not delete its existing data</strong>.</p>
   </div>
   <div class="feature-controls-summary" aria-live="polite">
    <strong data-feature-enabled-count><?=$enabledFeatureCount?></strong>
    <span>of <?=count($featureDefinitions)?> enabled</span>
   </div>
  </div>

  <div class="feature-controls-actions no-print">
   <button class="btn btn-secondary btn-small" type="button" data-feature-enable-all>Enable all</button>
   <button class="btn btn-secondary btn-small" type="button" data-feature-disable-all>Disable optional features</button>
  </div>

  <?php foreach($featureGroups as $groupName=>$features):?>
   <div class="feature-control-group">
    <div class="feature-control-group-title"><h3><?=e($groupName)?></h3><span><?=count($features)?> feature<?=count($features)===1?'':'s'?></span></div>
    <div class="feature-control-grid">
     <?php foreach($features as $featureCode=>$definition):
      $enabled=!empty($currentFeatureStates[$featureCode]);
      $dependency=(string)($definition['depends_on']??'');
     ?>
      <label class="feature-toggle-card <?=$enabled?'is-enabled':'is-disabled'?>" data-feature-card data-feature-code="<?=e($featureCode)?>" <?=$dependency!==''?'data-feature-depends="'.e($dependency).'"':''?>>
       <span class="feature-toggle-copy">
        <span class="feature-toggle-title-row"><strong><?=e($definition['name'])?></strong><span class="feature-state-badge" data-feature-state><?=$enabled?'Enabled':'Disabled'?></span></span>
        <small><?=e($definition['description'])?></small>
        <?php if($dependency!==''):?><em>Requires <?=e($featureDefinitions[$dependency]['name']??$dependency)?>.</em><?php endif;?>
       </span>
       <span class="feature-switch" aria-hidden="true"><input class="feature-switch-input" type="checkbox" name="feature_<?=e($featureCode)?>" value="1" <?=$enabled?'checked':''?> data-feature-toggle><span class="feature-switch-track"><span class="feature-switch-thumb"></span></span></span>
      </label>
     <?php endforeach;?>
    </div>
   </div>
  <?php endforeach;?>

  <div class="feature-controls-note">
   <strong>Safe to change anytime</strong>
   <span>Disabled features keep their saved reports, Provider KPI history, imports, reviews, and configuration. Re-enable the feature to restore access immediately.</span>
  </div>
 </section>
 <?php else:?>
 <div class="form-note">
  <strong>Feature Controls</strong>
  <span>New businesses start with standard reporting tools enabled and Provider KPI disabled. After creating the business, open Edit Business to customize its dashboard features.</span>
 </div>
 <?php endif;?>

 <div class="form-actions"><button class="btn btn-primary" type="submit"><?=$business?'Save Business & Feature Controls':'Create Business'?></button></div>
</form>
