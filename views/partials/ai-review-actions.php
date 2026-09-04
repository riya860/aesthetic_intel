<?php
$aiReviewType=(string)($aiReviewType??'');
$aiReviewExisting=$aiReview??null;
$aiReviewBusinessId=(int)($aiReviewBusinessId??business_context_id()??0);
if($aiReviewBusinessId>0 && ai_report_review_can_access($aiReviewBusinessId) && isset(ai_report_review_types()[$aiReviewType])):
  $completed=$aiReviewExisting && (($aiReviewExisting['status']??'')==='completed');
  $failed=$aiReviewExisting && (($aiReviewExisting['status']??'')==='failed');
  $pending=$aiReviewExisting && (($aiReviewExisting['status']??'')==='pending');
?>
<div class="ai-review-actions">
 <?php if($completed):?>
  <a class="btn btn-ai" href="<?=url('business-ai-reviewed-report',['id'=>(int)$aiReviewExisting['id']])?>">Open AI Review</a>
  <?php if(ai_report_review_can_regenerate($aiReviewBusinessId)):?>
   <form method="post" action="<?=url('business-ai-report-review')?>" data-ai-review-form onsubmit="return confirm('Regenerate this AI review? This uses a new AI request but never changes the original report.');">
    <?=csrf_field()?>
    <input type="hidden" name="report_type" value="<?=e($aiReviewType)?>">
    <?php if(isset($aiReviewSourceReportId)):?><input type="hidden" name="source_report_id" value="<?=e((int)$aiReviewSourceReportId)?>"><?php endif;?>
    <?php if(isset($aiReviewPeriodStart)):?><input type="hidden" name="period_start" value="<?=e($aiReviewPeriodStart)?>"><?php endif;?>
    <?php if(isset($aiReviewPeriodEnd)):?><input type="hidden" name="period_end" value="<?=e($aiReviewPeriodEnd)?>"><?php endif;?>
    <?php if(isset($aiReviewFrequency)):?><input type="hidden" name="frequency" value="<?=e($aiReviewFrequency)?>"><?php endif;?>
    <input type="hidden" name="regenerate" value="1">
    <button class="btn btn-secondary btn-small" type="submit">Regenerate AI Review</button>
   </form>
  <?php endif;?>
 <?php elseif($pending):?>
  <span class="validation-badge validation-warning">AI review pending</span>
 <?php else:?>
  <form method="post" action="<?=url('business-ai-report-review')?>" data-ai-review-form>
   <?=csrf_field()?>
   <input type="hidden" name="report_type" value="<?=e($aiReviewType)?>">
   <?php if(isset($aiReviewSourceReportId)):?><input type="hidden" name="source_report_id" value="<?=e((int)$aiReviewSourceReportId)?>"><?php endif;?>
   <?php if(isset($aiReviewPeriodStart)):?><input type="hidden" name="period_start" value="<?=e($aiReviewPeriodStart)?>"><?php endif;?>
   <?php if(isset($aiReviewPeriodEnd)):?><input type="hidden" name="period_end" value="<?=e($aiReviewPeriodEnd)?>"><?php endif;?>
   <?php if(isset($aiReviewFrequency)):?><input type="hidden" name="frequency" value="<?=e($aiReviewFrequency)?>"><?php endif;?>
   <button class="btn btn-ai" type="submit"><?=$failed?'Retry AI Review':'Review with AI'?></button>
  </form>
 <?php endif;?>
</div>
<?php endif;?>
