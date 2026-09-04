<?php
$businessColor=(string)($review['primary_color']??'#12336b');
$accentColor=(string)($review['accent_color']??'#0f766e');
$typeLabel=ai_report_review_type_label((string)$review['report_type']);
$quality=(array)($analysis['data_quality']??[]);
$qualityStatus=(string)($quality['status']??'');
$renderCards=static function(array $items,string $kind='default'): void {
    if(!$items)return;
    foreach($items as $item){
        if(!is_array($item))continue;
        $title=(string)($item['title']??$item['action']??$item['source']??$item['kpi']??'Finding');
        $detail=(string)($item['detail']??$item['summary']??$item['observation']??'');
        $evidence=(string)($item['evidence']??$item['reason']??$item['observations']??'');
        $priority=(string)($item['priority']??'');
        echo '<article class="ai-review-card ai-review-card-'.e($kind).'">';
        if($priority!=='')echo '<span class="priority priority-'.e(strtolower($priority)).'">'.e(strtoupper($priority)).'</span>';
        echo '<h3>'.e($title).'</h3>';
        if($detail!=='')echo '<p>'.e($detail).'</p>';
        if($evidence!=='')echo '<small>'.e($evidence).'</small>';
        echo '</article>';
    }
};
?>
<div class="report-toolbar no-print">
 <div>
  <a class="back-link" href="<?=url('business-history')?>">← Reports &amp; Downloads</a>
  <span class="eyebrow">REPORTS &amp; DOWNLOADS BY AI</span>
  <h1><?=e($review['business_name'])?> AI Reviewed Report</h1>
  <p><?=e(reporting_us_date($review['period_start']))?> – <?=e(reporting_us_date($review['period_end']))?> · <?=e($typeLabel)?> · <?=e(ucfirst((string)$review['frequency']))?></p>
 </div>
 <div class="toolbar-actions">
  <a class="btn btn-secondary" href="<?=e(ai_report_review_original_url($review))?>">Open Original</a>
  <?php if($canRegenerate):?>
   <form method="post" action="<?=url('business-ai-report-review')?>" data-ai-review-form onsubmit="return confirm('Regenerate this AI review? This uses a new AI request but does not change the original report.');">
    <?=csrf_field()?>
    <input type="hidden" name="report_type" value="<?=e($review['report_type'])?>">
    <?php if(!empty($review['source_report_id'])):?><input type="hidden" name="source_report_id" value="<?=e((int)$review['source_report_id'])?>"><?php endif;?>
    <input type="hidden" name="period_start" value="<?=e($review['period_start'])?>">
    <input type="hidden" name="period_end" value="<?=e($review['period_end'])?>">
    <input type="hidden" name="frequency" value="<?=e($review['frequency'])?>">
    <input type="hidden" name="regenerate" value="1">
    <button class="btn btn-secondary" type="submit">Regenerate AI Review</button>
   </form>
  <?php endif;?>
  <button class="btn btn-primary" type="button" onclick="window.print()">Save as PDF</button>
 </div>
</div>

<?php if($stale):?>
<div class="alert alert-warning no-print"><strong>Original report changed after this AI review.</strong> The saved AI version remains available for audit, but regenerate it before using it as the current analysis.</div>
<?php endif;?>

<article class="report-canvas ai-reviewed-report" style="--business-primary:<?=e($businessColor)?>;--business-accent:<?=e($accentColor)?>">
 <header class="report-header report-section">
  <div class="report-brand"><img class="report-business-logo" src="<?=!empty($review['logo_path'])?base_url($review['logo_path']):asset('img/logo-mark.svg')?>" alt=""><div><span>Aesthetic Intel · AI Review</span><strong><?=e($review['business_name'])?> <?=e(ucfirst((string)$review['frequency']))?> Business Analysis</strong></div></div>
  <div class="report-period"><?=e(reporting_us_date($review['period_start']))?> – <?=e(reporting_us_date($review['period_end']))?></div>
 </header>

 <section class="report-section ai-integrity-banner">
  <strong>Original report preserved</strong>
  <span><?=e((string)($analysis['integrity_statement']??'Original source numbers were not modified by this AI review.'))?></span>
 </section>

 <section class="report-section infographic-section">
  <div class="section-title"><b>1</b><h2>Executive Summary</h2></div>
  <div class="ai-review-prose"><p><?=nl2br(e((string)($analysis['executive_summary']??'No executive summary was returned.')))?></p></div>
 </section>

 <section class="report-section infographic-section">
  <div class="section-title"><b>2</b><h2>Data Quality &amp; Validation</h2></div>
  <div class="ai-quality-head"><span class="validation-badge validation-<?=str_contains(strtolower($qualityStatus),'review')?'danger':(str_contains(strtolower($qualityStatus),'warning')?'warning':'success')?>"><?=e($qualityStatus!==''?$qualityStatus:'Reviewed')?></span></div>
  <?php if(!empty($quality['summary'])):?><p><?=e($quality['summary'])?></p><?php endif;?>
  <?php if(!empty($quality['warnings'])):?><ul class="ai-review-list"><?php foreach($quality['warnings'] as $item):?><li><?=e($item)?></li><?php endforeach;?></ul><?php else:?><p class="muted">No additional data-quality warning was added by the on-demand AI review.</p><?php endif;?>
 </section>

 <?php if(!empty($analysis['key_wins'])):?><section class="report-section infographic-section"><div class="section-title"><b>3</b><h2>Key Wins</h2></div><div class="ai-review-grid"><?php $renderCards((array)$analysis['key_wins'],'win');?></div></section><?php endif;?>
 <?php if(!empty($analysis['key_risks'])):?><section class="report-section infographic-section"><div class="section-title"><b>4</b><h2>Key Risks</h2></div><div class="ai-review-grid"><?php $renderCards((array)$analysis['key_risks'],'risk');?></div></section><?php endif;?>
 <?php if(!empty($analysis['unusual_changes'])):?><section class="report-section infographic-section"><div class="section-title"><b>5</b><h2>Unusual Changes &amp; Anomalies</h2></div><div class="ai-review-grid"><?php $renderCards((array)$analysis['unusual_changes'],'anomaly');?></div></section><?php endif;?>

 <?php if(!empty($analysis['source_analysis'])):?><section class="report-section infographic-section"><div class="section-title"><b>6</b><h2>Source-by-Source Analysis</h2></div><div class="ai-review-grid"><?php $renderCards((array)$analysis['source_analysis'],'source');?></div></section><?php endif;?>

 <?php if(!empty($analysis['kpi_observations'])):?><section class="report-section infographic-section"><div class="section-title"><b>7</b><h2>KPI-by-KPI Observations</h2></div><div class="table-wrap"><table class="compact ai-kpi-table"><thead><tr><th>KPI</th><th>Current</th><th>Comparison</th><th>AI Observation</th></tr></thead><tbody><?php foreach((array)$analysis['kpi_observations'] as $item):if(!is_array($item))continue;?><tr><td><strong><?=e($item['kpi']??'KPI')?></strong></td><td><?=e($item['current']??'')?></td><td><?=e($item['comparison']??'')?></td><td><?=e($item['observation']??'')?></td></tr><?php endforeach;?></tbody></table></div></section><?php endif;?>

 <?php if(!empty($analysis['period_comparison'])):?><section class="report-section infographic-section"><div class="section-title"><b>8</b><h2>Period Comparison Commentary</h2></div><div class="ai-review-prose"><p><?=nl2br(e((string)$analysis['period_comparison']))?></p></div></section><?php endif;?>

 <?php if(!empty($analysis['business_opportunities'])):?><section class="report-section infographic-section"><div class="section-title"><b>9</b><h2>Business Opportunities</h2></div><div class="ai-review-grid"><?php $renderCards((array)$analysis['business_opportunities'],'opportunity');?></div></section><?php endif;?>

 <?php if(!empty($analysis['recommended_actions'])):?><section class="report-section infographic-section"><div class="section-title"><b>10</b><h2>Recommended Actions &amp; Priorities</h2></div><div class="ai-review-grid"><?php $renderCards((array)$analysis['recommended_actions'],'action');?></div></section><?php endif;?>

 <?php if(!empty($analysis['review_required_warnings'])):?><section class="report-section infographic-section ai-review-warning-section"><div class="section-title"><b>11</b><h2>Review Required Warnings</h2></div><ul class="ai-review-list"><?php foreach((array)$analysis['review_required_warnings'] as $item):?><li><?=e($item)?></li><?php endforeach;?></ul></section><?php endif;?>

 <?php if(!empty($analysis['unavailable_metrics'])):?><section class="report-section infographic-section"><div class="section-title"><b>12</b><h2>Unavailable or Incomparable Metrics</h2></div><ul class="ai-review-list"><?php foreach((array)$analysis['unavailable_metrics'] as $item):?><li><?=e($item)?></li><?php endforeach;?></ul></section><?php endif;?>

 <footer class="report-footer report-section"><span>AI analysis generated by Aesthetic Intel · original numbers unchanged</span><span><?=e($review['model']??'OpenAI')?> · <?=e(reporting_us_date($review['completed_at']??$review['requested_at'],true))?><?php if(!empty($review['requested_by_name'])):?> · requested by <?=e($review['requested_by_name'])?><?php endif;?></span></footer>
</article>
