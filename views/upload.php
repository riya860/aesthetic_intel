<?php $pageScripts=['upload.js'];
$paths=[
'sales_summary'=>'Boulevard → Reports → Summaries → Sales Summary',
'daily_summary'=>'Boulevard → Reports → Summaries → Daily Summary',
'appointment_metrics'=>'Boulevard → Reports → Appointments → Appointment Metrics',
'staff_schedule'=>'Boulevard → Reports → Staff → Staff Schedule',
'service_commission'=>'Boulevard → Reports → Commissions → Service Commission',
'product_commission'=>'Boulevard → Reports → Commissions → Product Commission',
'membership_commission'=>'Boulevard → Reports → Commissions → Membership Commission',
'membership_sales'=>'Boulevard → Reports → Sales → Membership Sales',
'product_sales'=>'Boulevard → Reports → Catalog → Product Sales (all categories)',
'retail_product_sales'=>'Boulevard → Reports → Catalog → Product Sales → filter Category: Retail',
'subscriptions'=>'Boulevard → Reports → Memberships → Subscriptions'
];?>
<div class="page-head"><div><span class="eyebrow"><?=e($business['name'])?> · Boulevard</span><h1>Upload Reporting Data</h1><p>Upload any Boulevard CSV reports you have. Aesthetic Intel will generate every metric supported by the available data.</p><?php if(auth_is_admin()&&business_feature_enabled((int)business_context_id(),'boulevard_api')):?><div class="button-row" style="margin-top:14px"><a class="btn btn-secondary" href="<?=url('business-boulevard-integration')?>">Boulevard API Integration</a></div><?php endif;?></div><div class="upload-progress"><strong data-upload-count>0 / <?=count($types)?></strong><span>files selected</span></div></div>
<form method="post" enctype="multipart/form-data" class="upload-form" data-upload-form data-period-form><?=csrf_field()?>
<?php $selectedFrequency=(string)($_POST['frequency']??'weekly');$today=reporting_business_today((string)$business['timezone']);[$defaultStart,$defaultEnd]=reporting_period_bounds($selectedFrequency==='custom'?'weekly':$selectedFrequency,$today,(string)$business['timezone']);?>
<section class="panel"><div class="form-grid three"><label>Frequency<select name="frequency" data-frequency><option value="weekly" <?=$selectedFrequency==='weekly'?'selected':''?>>Weekly</option><option value="monthly" <?=$selectedFrequency==='monthly'?'selected':''?>>Monthly</option><option value="quarterly" <?=$selectedFrequency==='quarterly'?'selected':''?>>Quarterly</option><option value="yearly" <?=$selectedFrequency==='yearly'?'selected':''?>>Yearly</option><option value="custom" <?=$selectedFrequency==='custom'?'selected':''?>>Custom</option></select></label><label>Period start<input type="date" name="period_start" data-period-start value="<?=e($_POST['period_start']??$defaultStart)?>" required></label><label>Period end<input type="date" name="period_end" data-period-end value="<?=e($_POST['period_end']??$defaultEnd)?>" required></label></div></section>
<div class="alert alert-info"><strong>Report Intelligence is active.</strong> Aesthetic Intel validates reporting periods and unusual changes before comparisons are published.</div>
<div class="upload-grid"><?php foreach($types as $t):?><label class="upload-card" data-upload-card><input type="file" name="<?=e($t['code'])?>" accept=".csv,text/csv"><span class="upload-number"><?=str_pad((string)($t['sort_order']/10),2,'0',STR_PAD_LEFT)?></span><span class="upload-copy"><strong><?=e($t['name'])?></strong><small><?=e($t['description'])?></small><small class="report-path"><?=e($t['upload_path']?:($paths[$t['code']]??'Export this report from Boulevard as CSV'))?></small><em data-file-name>Choose CSV file (optional)</em></span><span class="upload-status" data-upload-status>○</span></label><?php endforeach;?></div>
<div class="sticky-action"><div><strong>Upload at least one report</strong><span>Unavailable metrics will be clearly marked as “Data not available.”</span></div><button class="btn btn-primary btn-lg" type="submit" data-submit-upload disabled>Validate & Generate Dashboard</button></div></form>