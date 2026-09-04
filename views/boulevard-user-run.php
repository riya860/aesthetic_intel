<?php
$latest=$latestRun?:null;
$samePeriod=$latest&&((string)$latest['period_start']===(string)$periodStart)&&((string)$latest['period_end']===(string)$periodEnd);
$active=$samePeriod&&in_array((string)$latest['status'],['queued','preflight','requesting','waiting','running','processing'],true);
$ready=$samePeriod&&!empty($latest['upload_batch_id'])&&in_array((string)$latest['status'],['completed','partial'],true);
$attention=$samePeriod&&in_array((string)$latest['status'],['needs_attention','failed','cancelled'],true);
?>
<section class="page-head"><div><p class="eyebrow"><?=e($business['name'])?> · Boulevard</p><h1>Weekly Performance Report</h1><p>One click starts the approved Boulevard report. Aesthetic Intel continues securely in the background.</p></div><a class="btn btn-secondary" href="<?=url('business-history')?>">Reports &amp; Downloads</a></section>
<section class="content-card boulevard-user-run-card">
 <div class="user-run-hero"><div class="source-icon source-boulevard">B</div><div><span class="status-pill status-success">Super Admin approved</span><h2><?=e(reporting_us_date($periodStart))?> – <?=e(reporting_us_date($periodEnd))?></h2><p>Weekly reporting period based on <?=e($business['timezone'])?>.</p></div></div>
 <?php if($ready):?>
  <div class="alert alert-info"><strong>Your Boulevard report is ready.</strong> Open the full interactive performance report below.</div>
  <a class="btn btn-primary btn-lg" href="<?=url('business-report',['id'=>(int)$latest['upload_batch_id']])?>">Open Weekly Report</a>
 <?php elseif($active):?>
  <div class="alert alert-info"><strong>Your report is already processing.</strong> You may leave the page and return later.</div>
  <a class="btn btn-primary btn-lg" href="<?=url('business-boulevard-run-status',['id'=>(int)$latest['id']])?>">View Report Progress</a>
 <?php elseif($attention):?>
  <div class="alert alert-warning"><strong>The report needs Super Admin review.</strong> No action is required from you. The technical setup and any retries stay with the Super Admin.</div>
 <?php else:?>
  <form method="post"><?=csrf_field()?>
   <button class="btn btn-primary btn-lg" type="submit" onclick="return confirm('Start this week’s Boulevard performance report?')">Run Weekly Report</button>
  </form>
  <p class="muted-text">Please click once. Duplicate runs for the same active period are prevented automatically.</p>
 <?php endif;?>
</section>
<section class="content-card" style="margin-top:18px"><h2>What happens next</h2><div class="simple-step-grid"><div><strong>1</strong><span>Boulevard prepares the approved reports.</span></div><div><strong>2</strong><span>Aesthetic Intel validates and processes the data.</span></div><div><strong>3</strong><span>The interactive report appears in Reports &amp; Downloads.</span></div></div></section>
