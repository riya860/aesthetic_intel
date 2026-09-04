<?php
$validationRow=$validationRow??[];$sourceType=$sourceType??'';$recordId=(int)($recordId??0);
$vStatus=(string)($validationRow['validation_status']??'validated');$vMeta=report_validation_status_meta($vStatus);$vData=report_validation_decoded($validationRow);$vIssues=(array)($vData['issues']??[]);$vScore=$validationRow['validation_score']??($vData['score']??null);
?>
<section class="report-validation-card validation-<?=e($vMeta['class'])?>">
 <div class="report-validation-head">
  <div><span class="validation-badge validation-<?=e($vMeta['class'])?>"><?=e($vMeta['icon'].' '.$vMeta['label'])?></span><h3>Report Intelligence</h3></div>
  <?php if($vScore!==null):?><strong>Confidence <?=e((string)$vScore)?>/100</strong><?php endif;?>
 </div>
 <p><?=e(report_validation_summary($validationRow))?></p>
 <?php if($vStatus==='review_required'):?><div class="validation-hold-note"><strong>Not used in automatic comparisons.</strong><span>Correct and re-upload the report, or ask the Super Admin to approve it after checking the source.</span></div><?php endif;?>
 <?php if($vIssues):?><details><summary>Why this status?</summary><ul><?php foreach(array_slice($vIssues,0,6) as $issue):?><li><b><?=e(ucfirst((string)($issue['severity']??'medium')))?></b> <?=e((string)($issue['message']??''))?></li><?php endforeach;?></ul></details><?php endif;?>
 <?php if($vStatus==='review_required'&&auth_is_admin()&&$sourceType&&$recordId):?><form class="no-print" method="post" action="<?=url('business-report-validation-approve')?>" onsubmit="return confirm('Approve this report for comparisons after reviewing the source data? Aesthetic Intel will not change any numbers.');"><?=csrf_field()?><input type="hidden" name="source_type" value="<?=e($sourceType)?>"><input type="hidden" name="record_id" value="<?=e((string)$recordId)?>"><button class="btn btn-small btn-secondary" type="submit">Approve for Comparison</button></form><?php endif;?>
</section>
