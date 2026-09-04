<?php
$adminParams=[];
$today=date('Y-m-d');$defaultStart=date('Y-m-d',strtotime('-7 days'));
?>
<div class="page-head"><div><span class="eyebrow"><?=e($business['name'])?></span><h1>Google Business Profile</h1><p>Enter the current six-month cumulative totals shown in GBP. Aesthetic Intel will calculate new activity since the last saved entry.</p></div><a class="btn btn-secondary" href="<?=url('business-gbp-history',$adminParams)?>">View GBP History</a></div>
<form method="post" class="panel form-panel" data-period-form>
<?=csrf_field()?>
<div class="panel-head"><div><h2><?=!empty($entry)?'Edit GBP Entry':'Add GBP Data'?></h2><p class="muted">Blank fields are allowed. Enter only the metrics available to you.</p></div></div>
<div class="form-grid three">
<label><span>Frequency</span><select name="frequency" data-frequency><option value="weekly" <?=($entry['frequency']??'weekly')==='weekly'?'selected':''?>>Weekly</option><option value="monthly" <?=($entry['frequency']??'')==='monthly'?'selected':''?>>Monthly</option><option value="quarterly" <?=($entry['frequency']??'')==='quarterly'?'selected':''?>>Quarterly</option><option value="yearly" <?=($entry['frequency']??'')==='yearly'?'selected':''?>>Yearly</option><option value="custom" <?=($entry['frequency']??'')==='custom'?'selected':''?>>Custom</option></select></label>
<label><span>Period start</span><input type="date" name="period_start" data-period-start value="<?=e($entry['period_start']??$defaultStart)?>" required></label>
<label><span>Period end</span><input type="date" name="period_end" data-period-end value="<?=e($entry['period_end']??$today)?>" required></label>
</div>
<div class="info-callout"><strong>How this works</strong><span>For interactions, calls, directions, and website clicks, enter the cumulative totals currently shown for the same rolling six-month GBP window. The system subtracts the previous saved total to calculate new activity.</span></div>
<div class="form-grid two gbp-metric-grid">
<label><span>Interactions</span><input type="number" min="0" step="1" name="interactions" placeholder="Example: 6200" value="<?=e($entry['interactions']??'')?>"><small>Current cumulative GBP interactions</small></label>
<label><span>Calls</span><input type="number" min="0" step="1" name="calls" placeholder="Example: 1250" value="<?=e($entry['calls']??'')?>"><small>Current cumulative phone calls</small></label>
<label><span>Directions</span><input type="number" min="0" step="1" name="directions" placeholder="Example: 980" value="<?=e($entry['directions']??'')?>"><small>Current cumulative direction requests</small></label>
<label><span>Website clicks</span><input type="number" min="0" step="1" name="website_clicks" placeholder="Example: 3970" value="<?=e($entry['website_clicks']??'')?>"><small>Current cumulative website clicks</small></label>
<label><span>Total reviews</span><input type="number" min="0" step="1" name="total_reviews" placeholder="Example: 418" value="<?=e($entry['total_reviews']??'')?>"><small>Current lifetime review count</small></label>
<label><span>New reviews this period</span><input type="number" min="0" step="1" name="new_reviews_manual" placeholder="Optional" value="<?=e($entry['new_reviews_manual']??'')?>"><small>Optional override; otherwise calculated from total reviews</small></label>
<label><span>Average rating</span><input type="number" min="0" max="5" step="0.1" name="average_rating" placeholder="Example: 5.0" value="<?=e($entry['average_rating']??'')?>"><small>Value between 0 and 5</small></label>
<label><span>Unanswered reviews</span><input type="number" min="0" step="1" name="unanswered_reviews" placeholder="Optional" value="<?=e($entry['unanswered_reviews']??'')?>"><small>Lower is better</small></label>
</div>
<label><span>Notes</span><textarea name="notes" rows="3" placeholder="Optional context about this reporting period"><?=e($entry['notes']??'')?></textarea></label>
<div class="form-actions"><button class="btn btn-primary" type="submit"><?=!empty($entry)?'Update & Recalculate':'Save & Compare'?></button></div>
</form>
