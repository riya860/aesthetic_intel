<?php
$pageScripts=['boulevard-sync.js'];$run=$payload['run'];$items=$payload['items'];
$problemCount=count(array_filter($items,fn($item)=>in_array((string)$item['status'],['needs_attention','timed_out','failed'],true)));
?>
<section class="page-head"><div><p class="eyebrow"><?=e($business['name'])?> · Boulevard API</p><h1><?=$isDiagnostic?'Single-Report Diagnostic':'Sync Progress'?></h1><p><?=e(reporting_us_date($run['period_start']))?> – <?=e(reporting_us_date($run['period_end']))?> · <?=e(ucfirst($run['frequency']))?></p></div><div class="button-row"><a class="btn btn-secondary" href="<?=url('business-boulevard-integration')?>">Back to Integration</a><?php if(!$isDiagnostic&&$payload['report_url']):?><a class="btn btn-primary" href="<?=e($payload['report_url'])?>">Open Boulevard Report</a><?php endif;?></div></section>

<div class="alert <?=$run['worker_healthy']?'alert-info':'alert-warning'?> sync-worker-banner">
 <strong><?=$run['worker_healthy']?'Background worker is active.':'Background cron has not been detected recently.'?></strong>
 <span><?=$run['worker_healthy']?'This sync continues even if you close this page.':'Keep this page open for the browser fallback, and configure the Hostinger cron shown on the integration page for true background processing.'?></span>
</div>

<?php if($isDiagnostic):?><div class="alert alert-info"><strong>Single-report diagnostic:</strong> this test logs the sanitized Boulevard request and response, validates only one CSV, and does not create or replace the business dashboard.</div><?php endif;?>
<section class="content-card boulevard-sync-progress-card" data-boulevard-sync data-run-id="<?=e($run['id'])?>" data-status-url="<?=e(url('business-boulevard-sync-status'))?>" data-csrf="<?=e(csrf_token())?>">
 <div class="sync-progress-head"><div><p class="eyebrow"><?=$isDiagnostic?'Developer Diagnostic':'Fail-safe API Processing'?></p><h2 data-sync-title><?=e($run['terminal']?($isDiagnostic?'Diagnostic finished':'Boulevard sync finished'):($isDiagnostic?'Testing one Boulevard export':'Aesthetic Intel is processing Boulevard exports'))?></h2><p data-sync-message><?=e($run['message']?:'Reports are processed in controlled batches of three.')?></p></div><span class="sync-status-badge status-<?=e($run['status'])?>" data-sync-status><?=e(ucwords(str_replace('_',' ',$run['status'])))?></span></div>
 <div class="sync-progress-track"><span data-sync-progress style="width:<?=e($run['progress'])?>%"></span></div><div class="sync-progress-meta"><strong data-sync-percent><?=e($run['progress'])?>%</strong><span data-sync-count><?=e($run['completed_count'])?> completed · <?=e($run['failed_count'])?> need attention · <?=e($run['requested_count'])?> total</span></div>
 <?php if($run['error']):?><div class="alert alert-warning" data-sync-error><?=e($run['error'])?></div><?php else:?><div class="alert alert-warning" data-sync-error hidden></div><?php endif;?>

 <div class="sync-item-list" data-sync-items>
 <?php foreach($items as $item):?>
  <article class="sync-item status-<?=e($item['status'])?>" data-item-code="<?=e($item['code'])?>">
   <div class="sync-item-icon"></div>
   <div class="sync-item-main"><strong><?=e($item['name'])?></strong><small><?=e($item['filter']?:'Uses saved Boulevard date configuration')?></small><?php if($item['completion_source']):?><small>Completed via <?=e(str_replace('_',' ',$item['completion_source']))?></small><?php endif;?></div>
   <span class="sync-item-state"><?=e($item['status_label'])?></span>
   <small class="sync-item-detail"><?php if($item['error']):?><?=e($item['error'])?><?php elseif($item['row_count']):?><?=e(numfmt($item['row_count']))?> rows<?php else:?><?=e($item['age_seconds']>=60?floor($item['age_seconds']/60).' min elapsed':'')?><?php endif;?><?php if($item['last_http_status']):?> · HTTP <?=e($item['last_http_status'])?><?php endif;?><?php if($item['provider_checks']):?> · <?=e($item['provider_checks'])?> API check(s)<?php endif;?><?php if($item['export_ref']):?> · Export …<?=e($item['export_ref'])?><?php endif;?></small>
   <?php if($item['action']==='manual_upload'):?><form class="sync-fallback-form" method="post" enctype="multipart/form-data" action="<?=e($payload['manual_fallback_url'])?>"><?=csrf_field()?><input type="hidden" name="run_id" value="<?=e($run['id'])?>"><input type="hidden" name="item_id" value="<?=e($item['id'])?>"><label><span>Manual fallback</span><input type="file" name="fallback_csv" accept=".csv,text/csv" required></label><button class="btn btn-secondary btn-small" type="submit">Upload CSV &amp; Continue</button></form><?php endif;?>
  </article>
 <?php endforeach;?>
 </div>

 <?php if(!empty($run['reconciliation']['warnings'])):?><div class="sync-reconciliation"><h3>Final review notes</h3><ul><?php foreach($run['reconciliation']['warnings'] as $warning):?><li><?=e($warning)?></li><?php endforeach;?></ul></div><?php endif;?>
 <div class="button-row sync-controls"><a class="btn btn-secondary" href="<?=url('business-boulevard-sync-diagnostics',['id'=>(int)$run['id']])?>">Download Diagnostics</a><button class="btn btn-secondary" type="button" data-sync-refresh>Check &amp; Process Now</button><button class="btn btn-danger" type="button" data-sync-retry <?=$problemCount>0?'':'hidden'?>>Retry Reports Needing Attention</button><button class="btn btn-danger" type="button" data-sync-cancel <?=$run['terminal']?'hidden':''?>>Cancel Sync</button><?php if(!$isDiagnostic):?><a class="btn btn-primary" data-sync-report-link href="<?=e($payload['report_url']?:'#')?>" <?=$payload['report_url']?'':'hidden'?>>Open Boulevard Report</a><?php endif;?></div>
 <p class="muted-text" data-sync-note><?=e($run['terminal']?'This sync has finished.':'Aesthetic Intel uses the Boulevard webhook when available, direct signed-file checks, and controlled API polling as a fallback. No report can remain pending indefinitely.')?></p>
</section>
