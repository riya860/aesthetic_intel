<?php
$pageScripts=['dashboard.js','unified-report.js','pdf-export.js'];
$adminParam=[];
$labels=unified_source_labels();
$sources=$report['sources'];
$sectionNo=1;
?>
<div class="report-toolbar no-print"><div><a class="back-link" href="<?=url('business-history',$adminParam)?>">← Reports &amp; Downloads</a><h1><?=e($report['business']['name'])?> Unified Performance Report</h1><p><?=e(reporting_us_date($report['period_start']))?> – <?=e(reporting_us_date($report['period_end']))?> · all available tools combined</p></div><div class="toolbar-actions"><?php $aiReviewType='unified';$aiReviewBusinessId=(int)$report['business']['id'];$aiReviewPeriodStart=(string)$report['period_start'];$aiReviewPeriodEnd=(string)$report['period_end'];$aiReviewFrequency=(string)$report['frequency'];include VIEW_PATH.'/partials/ai-review-actions.php';?><button class="btn btn-secondary" type="button" data-copy-digest>Copy Email Digest</button><button class="btn btn-primary" type="button" data-export-pdf>Save as PDF</button></div></div>
<article class="report-canvas unified-report" data-report-canvas style="--business-primary:<?=e($report['business']['primary_color'])?>;--business-accent:<?=e($report['business']['accent_color'])?>">
<header class="report-header report-section"><div class="report-brand"><img class="report-business-logo" src="<?=!empty($report['business']['logo_path'])?base_url($report['business']['logo_path']):asset('img/logo-mark.svg')?>" alt=""><div><span>Aesthetic Intel</span><strong><?=e($report['business']['name'])?> <?=e(ucfirst($report['frequency']))?> Performance Summary</strong></div></div><div class="report-period"><?=e(reporting_us_date($report['period_start']))?> – <?=e(reporting_us_date($report['period_end']))?></div></header>
<?php if(!empty($report['held_sources'])): ?><section class="report-section unified-validation-banner validation-danger"><strong>Report Intelligence held <?=e(implode(', ',array_map(fn($c)=>$labels[$c]??$c,$report['held_sources'])))?> out of this comparison.</strong><span>The source was saved, but a possible date-range, frequency, or data anomaly needs review. No values were guessed or silently corrected.</span></section><?php endif; ?>
<?php if(!empty($report['validation_warnings'])): ?><section class="report-section unified-validation-banner validation-warning"><strong>Validated with warning</strong><?php foreach($report['validation_warnings'] as $warning):?><span><?=e($warning)?></span><?php endforeach;?></section><?php endif; ?>

<?php if(!empty($report['summary'])): ?>
<section class="report-section infographic-section"><div class="section-title"><b><?=$sectionNo++?></b><h2>Executive Summary</h2></div><div class="source-status-grid"><?php foreach($sources as $code=>$sourceRow):$vm=report_validation_status_meta($sourceRow['validation_status']??'validated');?><article class="source-status available"><strong><?=e($labels[$code]??$code)?></strong><span><?=e($vm['label'])?></span></article><?php endforeach;?><?php foreach(($report['held_sources']??[]) as $code):?><article class="source-status held"><strong><?=e($labels[$code]??$code)?></strong><span>Held for review</span></article><?php endforeach;?></div><div class="summary-list"><?php foreach($report['summary'] as $line):?><p>• <?=e($line)?></p><?php endforeach;?></div></section>
<?php endif; ?>

<?php if(isset($sources['gbp'])): $a=$sources['gbp']['analysis'];$gm=$a['metrics']??[]; ?>
<?php
$gbpCards=[];
foreach(['interactions','calls','directions','website_clicks'] as $key){
    $m=$gm[$key]??[];
    if(value_available($m['activity']??null))$gbpCards[]=['label'=>$m['label'],'value'=>numfmt($m['activity']),'comparison'=>value_available($m['previous_activity']??null)?gbp_activity_text($m):null];
    elseif(value_available($m['current_total']??null))$gbpCards[]=['label'=>$m['label'].' total','value'=>numfmt($m['current_total']),'comparison'=>null];
}
$r=$gm['reviews']??[];
if(value_available($r['new']??null))$gbpCards[]=['label'=>'New reviews','value'=>numfmt($r['new']),'comparison'=>value_available($r['previous_new']??null)?(($r['new_change']>=0?'+':'').numfmt($r['new_change']).($r['percent_change']!==null?' · '.($r['percent_change']>=0?'+':'').number_format($r['percent_change'],1).'%':'')):null];
if(value_available($r['total']??null))$gbpCards[]=['label'=>'Total reviews','value'=>numfmt($r['total']),'comparison'=>value_available($r['previous_total']??null)?(($r['total']-$r['previous_total']>=0?'+':'').numfmt($r['total']-$r['previous_total'])):null];
$rating=$gm['average_rating']??[];if(value_available($rating['value']??null))$gbpCards[]=['label'=>'Average rating','value'=>number_format((float)$rating['value'],1),'comparison'=>value_available($rating['previous']??null)?(($rating['change']>=0?'+':'').number_format((float)$rating['change'],1)):null];
$unanswered=$gm['unanswered_reviews']??[];if(value_available($unanswered['value']??null))$gbpCards[]=['label'=>'Unanswered reviews','value'=>numfmt($unanswered['value']),'comparison'=>value_available($unanswered['previous']??null)?(($unanswered['change']<0?'Improved ':'').($unanswered['change']>=0?'+':'').numfmt($unanswered['change'])):null];
?>
<?php if($gbpCards): ?><section class="report-section infographic-section"><div class="section-title"><b><?=$sectionNo++?></b><h2>Google Business Profile</h2></div><div class="report-kpis financial-kpis"><?php foreach($gbpCards as $card):?><article class="report-kpi"><small><?=e($card['label'])?></small><strong><?=e($card['value'])?></strong><?php if($card['comparison']):?><span><?=e($card['comparison'])?></span><?php endif;?></article><?php endforeach;?></div></section><?php endif; ?>
<?php endif; ?>

<?php foreach(['podium'=>'Podium: Leads, Messaging & Calls','growth99'=>'Growth99+ Lead & Call Performance','ga4'=>'Google Analytics 4'] as $sourceCode=>$heading): ?>
<?php if(isset($sources[$sourceCode])):$x=$sources[$sourceCode];$present=unified_present_values($x['values'],ai_extraction_sources()[$sourceCode]['fields']); ?>
<?php if($present): ?><section class="report-section infographic-section"><div class="section-title"><b><?=$sectionNo++?></b><h2><?=e($heading)?></h2></div><div class="report-kpis financial-kpis"><?php foreach($present as $key=>$item):$comparison=unified_change_text($x['changes'][$key]??[]);?><article class="report-kpi"><small><?=e($item['label'])?></small><strong><?=unified_format_value($key,$item['value'])?></strong><?php if($comparison!==null):?><span><?=e($comparison)?></span><?php endif;?></article><?php endforeach;?></div></section><?php endif; ?>
<?php endif; ?>
<?php endforeach; ?>

<?php if(isset($sources['boulevard'])):
    $dashboard=$sources['boulevard']['dashboard'];
    $insights=$sources['boulevard']['insights'];
    $uploadedReportCodes=$sources['boulevard']['report_codes'];
    $mrrHistory=$sources['boulevard']['mrr_history'];
?>
<div class="report-section infographic-section"><div class="section-title"><b><?=$sectionNo++?></b><h2>Boulevard Interactive Performance</h2></div><div class="insight-banner">Complete Boulevard analytics, charts, provider detail, retail performance, memberships, and financial results are included below.</div></div>
<?php include VIEW_PATH.'/partials/boulevard-sections.php'; ?>
<?php endif; ?>

<?php if(!empty($report['focus'])): ?><section class="report-section infographic-section"><div class="section-title"><b><?=$sectionNo++?></b><h2>Main Focus Areas</h2></div><div class="insight-grid"><?php foreach($report['focus'] as $f):?><article class="insight-card"><span class="priority priority-medium">ACTION</span><h3><?=e($f)?></h3></article><?php endforeach;?></div></section><?php endif; ?>
<footer class="report-footer report-section"><span>Generated by Aesthetic Intel</span><span><?=count($sources)?> source<?=count($sources)===1?'':'s'?> included</span></footer></article>
<?php $validationDigest='';if(!empty($report['held_sources']))$validationDigest.="\n\nHeld from comparison: ".implode(', ',array_map(fn($c)=>$labels[$c]??$c,$report['held_sources']));if(!empty($report['validation_warnings']))$validationDigest.="\n\nValidation warnings\n- ".implode("\n- ",$report['validation_warnings']);$digest=$report['business']['name'].' unified performance summary for '.reporting_us_date($report['period_start']).'–'.reporting_us_date($report['period_end'])."\n\n".implode("\n",$report['summary']).$validationDigest.(!empty($report['focus'])?"\n\nMain Focus Areas\n- ".implode("\n- ",$report['focus']):'');?><textarea hidden data-email-digest><?=e($digest)?></textarea>
<?php if(isset($sources['boulevard'])): ?>
<script type="application/json" id="dashboardData"><?=json_encode(['dashboard'=>$dashboard,'mrrHistory'=>$mrrHistory,'visibleKpis'=>array_values(array_filter(array_keys($dashboard['kpis']??[]),fn($key)=>boulevard_metric_available($key,$uploadedReportCodes,$dashboard['kpis'][$key]??null)))],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js"></script>
<?php endif; ?>
