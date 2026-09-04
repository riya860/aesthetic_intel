<?php
$adminParams=[];
$labels=unified_source_labels();
$aiReviews=$aiReviews??[];
$aiReviewIndex=$aiReviewIndex??[];
$canAiReview=$canAiReview??false;
$businessId=(int)($business['id']??business_context_id()??0);
$features=business_feature_effective_states($businessId);
$showBoulevard=!empty($features['boulevard']);
$showUnified=!empty($features['unified_reports']);
$showTransfer=!empty($features['data_transfer']);
?>
<div class="page-head">
 <div><span class="eyebrow"><?=e($business['name'])?></span><h1>Reports &amp; Downloads</h1><p>Original reports stay unchanged. Optional report experiences appear only when they are enabled for this business.</p></div>
 <div class="page-actions">
  <?php if($showTransfer):?><a class="btn btn-secondary" href="<?=url('business-data-transfer',$adminParams)?>">Export / Import Data</a><?php endif;?>
  <?php if($showBoulevard):?><a class="btn btn-primary" href="<?=url('business-upload',$adminParams)?>">Upload Boulevard Reports</a><?php else:?><a class="btn btn-primary" href="<?=url('business-dashboard')?>">Add Data</a><?php endif;?>
 </div>
</div>

<?php if($canAiReview):?>
<section class="panel ai-downloads-panel">
 <div class="panel-head"><div><span class="eyebrow">SEPARATE AI VERSION</span><h2>Reports &amp; Downloads by AI</h2><p class="muted">Generated only when you click Review with AI. Reopening a completed review does not call AI again.</p></div></div>
 <div class="table-wrap"><table><thead><tr><th>Reporting Period</th><th>AI Review Type</th><th>Frequency</th><th>Model</th><th>Generated</th><th>Action</th></tr></thead><tbody>
 <?php foreach($aiReviews as $r):?><tr>
  <td><strong><?=e(reporting_us_date($r['period_start']))?> – <?=e(reporting_us_date($r['period_end']))?></strong></td>
  <td><?=e(ai_report_review_type_label((string)$r['report_type']))?></td>
  <td><?=e(ucfirst((string)$r['frequency']))?></td>
  <td><?=e($r['model']?:'OpenAI')?></td>
  <td><?=e(reporting_us_date($r['completed_at']??$r['requested_at'],true))?><?php if(!empty($r['requested_by_name'])):?><small class="block">by <?=e($r['requested_by_name'])?></small><?php endif;?></td>
  <td><div class="table-actions"><a class="btn btn-small btn-ai" href="<?=url('business-ai-reviewed-report',['id'=>(int)$r['id']])?>">Open AI Reviewed Report</a><a class="btn btn-small btn-secondary" href="<?=e(ai_report_review_original_url($r))?>">Original</a></div></td>
 </tr><?php endforeach;?>
 <?php if(!$aiReviews):?><tr><td colspan="6" class="empty-cell">No AI Reviewed Reports yet. Use <strong>Review with AI</strong> on an enabled original report below.</td></tr><?php endif;?>
 </tbody></table></div>
</section>
<?php endif;?>

<?php if($showUnified):?>
<section class="panel">
 <div class="panel-head"><div><span class="eyebrow">ORIGINAL REPORTS</span><h2>Unified Performance Reports</h2><p class="muted">Only enabled data sources are merged. A disabled source is preserved in storage but excluded from the active Unified Report workspace.</p></div></div>
 <div class="table-wrap"><table><thead><tr><th>Reporting Period</th><th>Frequency</th><th>Included Tools</th><th>Validation</th><th>Last Updated</th><th>Original / AI</th></tr></thead><tbody>
 <?php foreach($unifiedPeriods as $r):
   $reviewKey='unified|unified:'.$r['period_start'].':'.$r['period_end'].':'.$r['frequency'];
   $savedReview=$aiReviewIndex[$reviewKey]??null;
 ?>
 <tr>
  <td><strong><?=e(reporting_us_date($r['period_start']))?> – <?=e(reporting_us_date($r['period_end']))?></strong></td>
  <td><?=e(ucfirst($r['frequency']))?></td>
  <td><?php foreach($r['sources'] as $code):?><span class="source-chip"><?=e($labels[$code]??$code)?></span><?php endforeach;?><?php foreach(($r['held_sources']??[]) as $code):?><span class="source-chip source-chip-held"><?=e($labels[$code]??$code)?> · held</span><?php endforeach;?></td>
  <td><?php if(!empty($r['held_sources'])):?><span class="validation-badge validation-danger">Review required</span><?php else:?><span class="validation-badge validation-success">Comparison ready</span><?php endif;?></td>
  <td><?=e(reporting_us_date($r['generated_at'],true))?></td>
  <td><div class="table-actions">
   <?php if(!empty($r['sources'])):?><a class="btn btn-small btn-secondary" href="<?=url('business-unified-report',['start'=>$r['period_start'],'end'=>$r['period_end'],'frequency'=>$r['frequency']]+$adminParams)?>">Original</a><?php else:?><span class="muted">Held for review</span><?php endif;?>
   <?php if($canAiReview&&!empty($r['sources'])):?>
     <?php if($savedReview&&($savedReview['status']??'')==='completed'):?><a class="btn btn-small btn-ai" href="<?=url('business-ai-reviewed-report',['id'=>(int)$savedReview['id']])?>">AI Review</a>
     <?php elseif($savedReview&&($savedReview['status']??'')==='pending'):?><span class="validation-badge validation-warning">AI pending</span>
     <?php else:?><form method="post" action="<?=url('business-ai-report-review')?>" data-ai-review-form><?=csrf_field()?><input type="hidden" name="report_type" value="unified"><input type="hidden" name="period_start" value="<?=e($r['period_start'])?>"><input type="hidden" name="period_end" value="<?=e($r['period_end'])?>"><input type="hidden" name="frequency" value="<?=e($r['frequency'])?>"><button class="btn btn-small btn-ai" type="submit"><?=($savedReview&&($savedReview['status']??'')==='failed')?'Retry AI Review':'Review with AI'?></button></form><?php endif;?>
   <?php endif;?>
  </div></td>
 </tr>
 <?php endforeach;?>
 <?php if(!$unifiedPeriods):?><tr><td colspan="6" class="empty-cell">No comparison-ready periods are available from currently enabled sources.</td></tr><?php endif;?>
 </tbody></table></div>
</section>
<?php endif;?>

<?php if($showBoulevard):?>
<section class="panel">
 <div class="panel-head"><div><span class="eyebrow">ORIGINAL REPORTS</span><h2>Boulevard Source Reports</h2><p class="muted">Manage the original Boulevard report batches. AI analysis is always stored separately.</p></div></div>
 <div class="table-wrap"><table><thead><tr><th>Reporting Period</th><th>Frequency</th><th>Completeness</th><th>Validation</th><th>Generated</th><th>Original / AI</th></tr></thead><tbody>
 <?php foreach($batches as $b):
   $reviewKey='boulevard|boulevard:'.$b['id'];
   $savedReview=$aiReviewIndex[$reviewKey]??null;
 ?>
 <tr>
  <td><strong><?=e(reporting_us_date($b['period_start']))?> – <?=e(reporting_us_date($b['period_end']))?></strong></td>
  <td><?=e(ucfirst($b['frequency']))?></td>
  <td><?=numfmt($b['completeness_score'])?>%</td>
  <td><?php $vm=report_validation_status_meta($b['validation_status']??'validated');?><span class="validation-badge validation-<?=e($vm['class'])?>"><?=e($vm['label'])?></span></td>
  <td><?=e(reporting_us_date($b['created_at'],true))?><small class="block">by <?=e($b['uploaded_by_name'])?></small></td>
  <td><div class="table-actions">
   <?php if($b['status']==='completed'):?><a class="btn btn-small btn-secondary" href="<?=url('business-report',['id'=>$b['id']]+$adminParams)?>">Original</a><?php endif;?>
   <?php if($canAiReview&&$b['status']==='completed'):?>
    <?php if($savedReview&&($savedReview['status']??'')==='completed'):?><a class="btn btn-small btn-ai" href="<?=url('business-ai-reviewed-report',['id'=>(int)$savedReview['id']])?>">AI Review</a>
    <?php elseif($savedReview&&($savedReview['status']??'')==='pending'):?><span class="validation-badge validation-warning">AI pending</span>
    <?php else:?><form method="post" action="<?=url('business-ai-report-review')?>" data-ai-review-form><?=csrf_field()?><input type="hidden" name="report_type" value="boulevard"><input type="hidden" name="source_report_id" value="<?=e((int)$b['id'])?>"><button class="btn btn-small btn-ai" type="submit"><?=($savedReview&&($savedReview['status']??'')==='failed')?'Retry AI Review':'Review with AI'?></button></form><?php endif;?>
   <?php endif;?>
   <form method="post" action="<?=url('business-report-delete')?>" onsubmit="return confirm('Delete this Boulevard report and its uploaded files?');"><?=csrf_field()?><input type="hidden" name="id" value="<?=e($b['id'])?>"><input type="hidden" name="business_id" value="<?=e($business['id'])?>"><button class="btn btn-small btn-danger" type="submit">Delete</button></form>
  </div></td>
 </tr>
 <?php endforeach;?>
 <?php if(!$batches):?><tr><td colspan="6" class="empty-cell">No Boulevard reports yet.</td></tr><?php endif;?>
 </tbody></table></div>
</section>
<?php endif;?>

<?php if(!$showUnified&&!$showBoulevard&&!$canAiReview):?>
<section class="empty-state"><div class="empty-icon">R</div><h2>No report modules are enabled</h2><p>Saved data is preserved. A Super Admin can enable Boulevard, Unified Reports, or Review with AI from the business Feature Controls.</p><?php if(auth_is_admin()):?><a class="btn btn-primary" href="<?=url('admin-business-form',['id'=>$businessId])?>">Manage Feature Controls</a><?php endif;?></section>
<?php endif;?>
