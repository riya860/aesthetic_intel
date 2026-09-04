<?php
$pageScripts=['provider-kpi.js'];
$monthKey=substr($month,0,7);$groups=[];foreach($definitions as $def)if(!empty($def['show_on_scorecard']))$groups[$def['category']][]=$def;
$topCodes=['total_production','revenue_per_hour','utilization_rate','average_ticket','new_patients'];
$opportunityCards=$opportunity['cards']??[];
$quickOpportunityCodes=['revenue_gap','revenue_lost_open_hours','additional_patients','remaining_capacity'];
$openActions=array_values(array_filter($actions??[],fn($a)=>!in_array($a['status'],['completed','cancelled'],true)));
$trendPayload=['labels'=>$trend['labels']??[],'series'=>[]];foreach(($trend['series']??[]) as $code=>$entry)$trendPayload['series'][$code]=['label'=>$entry['definition']['name']??$code,'format'=>$entry['definition']['format']??'number','values'=>$entry['values']??[]];
?>
<div class="provider-print-meta"><strong><?=e(provider_kpi_business_label((int)business_context_id()))?></strong><span>Provider KPI Report<?php if(isset($month)):?> · <?=e(provider_kpi_month_label((string)$month))?><?php endif;?></span><small>Generated <?=e(date('M j, Y g:i A'))?></small></div>
<div class="page-head"><div><span class="eyebrow">Provider Scorecard</span><h1><?=e($provider['name'])?> <?php if(($provider['status']??'active')==='inactive'):?><span class="status status-neutral">Inactive</span><?php endif;?></h1><p><?=e(trim(($provider['provider_type']??'').(($provider['provider_type']??'')&&($provider['department']??'')?' · ':'').($provider['department']??''))?:'Provider')?> · <?=e(provider_kpi_month_label($month))?></p></div><div class="page-actions no-print"><a class="btn btn-secondary" href="<?=url('business-provider-kpi',['month'=>$monthKey])?>">Clinic Overview</a><a class="btn btn-secondary" href="<?=url('business-provider-kpi-opportunities',['id'=>$provider['id'],'month'=>$monthKey])?>">Opportunities</a><?php if($canViewCoaching):?><a class="btn btn-secondary" href="<?=url('business-provider-kpi-coaching',['id'=>$provider['id'],'month'=>$monthKey])?>">Coaching</a><?php endif;?><a class="btn btn-secondary" href="<?=url('business-provider-kpi-provider-export',['id'=>$provider['id'],'month'=>$monthKey])?>">Export CSV</a><button class="btn btn-primary" type="button" onclick="window.print()">Print / Save PDF</button></div></div>
<section class="panel provider-scorecard-hero"><div><span>Overall Goal Attainment</span><strong><?=$snapshot['goal_attainment']===null?'—':e(number_format((float)$snapshot['goal_attainment'],1).'%')?></strong><div class="goal-progress large"><i style="width:<?=e((string)min(100,max(0,(float)($snapshot['goal_attainment']??0))))?>%"></i></div></div><form class="no-print" method="get" action="<?=base_url('index.php')?>"><input type="hidden" name="page" value="business-provider-kpi-provider"><input type="hidden" name="id" value="<?=$provider['id']?>"><label>Month<input type="month" name="month" value="<?=e($monthKey)?>"></label><button class="btn btn-secondary" type="submit">Change Month</button></form></section>
<section class="provider-kpi-card-grid provider-top-kpis">
<?php foreach($topCodes as $code):$def=$snapshot['definitions'][$code]??null;$actual=$snapshot['current'][$code]??null;if(!$def||$actual===null)continue;$goal=$snapshot['goals'][$code]??null;$gr=provider_kpi_goal_result((float)$actual,$goal,!empty($def['higher_is_better']));?>
<a class="provider-kpi-summary-card provider-kpi-card-link" href="<?=url('business-provider-kpi-drilldown',['id'=>$provider['id'],'code'=>$code,'month'=>$monthKey])?>"><span><?=e($def['name'])?></span><strong><?=e(provider_kpi_format_value((float)$actual,$def))?></strong><?php if($goal!==null):?><small>Goal: <?=e(provider_kpi_format_value((float)$goal,$def))?></small><div class="goal-progress <?=$gr['status']?>"><i style="width:<?=e((string)min(100,max(0,(float)$gr['percent'])))?>%"></i></div><?php else:?><small>No goal set</small><?php endif;?><em>View details</em></a>
<?php endforeach;?>
</section>

<?php $hasQuick=false;foreach($quickOpportunityCodes as $code)if(($opportunityCards[$code]['value']??null)!==null)$hasQuick=true;if($hasQuick):?>
<section class="panel provider-opportunity-preview">
 <div class="section-head"><div><span class="eyebrow">Actionable Opportunities</span><h2>What can move performance this month</h2></div><a class="btn btn-small btn-secondary no-print" href="<?=url('business-provider-kpi-opportunities',['id'=>$provider['id'],'month'=>$monthKey])?>">View Full Plan</a></div>
 <div class="provider-opportunity-card-grid">
 <?php foreach($quickOpportunityCodes as $code):$card=$opportunityCards[$code]??null;if(!$card||$card['value']===null)continue;$display=match($card['format']){'currency'=>money((float)$card['value']),'hours'=>number_format((float)$card['value'],1).' hrs',default=>number_format((float)$card['value'],0)};?>
 <article><span><?=e($card['label'])?></span><strong><?=e($display)?></strong><small><?=e($card['help'])?></small></article>
 <?php endforeach;?>
 </div>
</section>
<?php endif;?>

<?php if(!empty($trendPayload['labels'])):?>
<section class="panel provider-trend-panel">
 <div class="section-head"><div><span class="eyebrow">Trending</span><h2>Performance over time</h2><p>90 days uses the latest three monthly reporting periods.</p></div><div class="provider-trend-controls"><label>Metric<select data-provider-trend-metric><?php foreach($trendPayload['series'] as $code=>$entry):?><option value="<?=e($code)?>"><?=e($entry['label'])?></option><?php endforeach;?></select></label><div class="segmented-control" role="group" aria-label="Trend period"><button type="button" data-provider-trend-period="3">90 Days</button><button type="button" data-provider-trend-period="6">6 Months</button><button class="active" type="button" data-provider-trend-period="12">12 Months</button></div></div></div>
 <div class="provider-trend-chart"><canvas id="providerTrendChart" aria-label="Provider KPI trend chart"></canvas></div>
 <script type="application/json" id="providerKpiTrendData"><?=json_encode($trendPayload,JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?></script>
</section>
<?php endif;?>

<?php if($canViewCoaching):?>
<section class="panel provider-coaching-preview">
 <div class="section-head"><div><span class="eyebrow">Coaching Workspace</span><h2><?=e($review?'Review in progress':'Turn the scorecard into an action plan')?></h2></div><a class="btn btn-small btn-secondary no-print" href="<?=url('business-provider-kpi-coaching',['id'=>$provider['id'],'month'=>$monthKey])?>"><?=e($review?'Open Coaching':'Start Coaching Review')?></a></div>
 <?php if($review):?><div class="provider-review-summary"><article><span>Status</span><strong><?=e(ucfirst((string)$review['review_status']))?></strong></article><article><span>Next review</span><strong><?=e($review['next_review_date']?date('M j, Y',strtotime((string)$review['next_review_date'])):'Not set')?></strong></article><article><span>Open actions</span><strong><?=count($openActions)?></strong></article></div><?php else:?><p class="muted">Record provider wins, performance risks, opportunities, and assigned next steps for monthly reviews and weekly one-on-ones.</p><?php endif;?>
</section>
<?php endif;?>

<?php foreach($groups as $category=>$categoryDefs):?>
<section class="panel provider-scorecard-section"><div class="section-head"><div><span class="eyebrow">Performance Details</span><h2><?=e(provider_kpi_category_label($category))?></h2></div><p>Click a KPI name to see its drivers, source, and 12-month trend.</p></div><div class="table-wrap"><table><thead><tr><th>KPI</th><th>Current Month</th><th>Previous Month</th><th>MoM Change</th><th>Year-to-Date</th><th>Goal</th><th>Variance</th><th>% to Goal</th></tr></thead><tbody>
<?php $shown=0;foreach($categoryDefs as $def):$code=(string)$def['code'];$current=$snapshot['current'][$code]??null;$previous=$snapshot['previous'][$code]??null;$ytd=$snapshot['ytd'][$code]??null;$goal=$snapshot['goals'][$code]??null;if($current===null&&$previous===null&&$ytd===null&&$goal===null)continue;$shown++;$change=provider_kpi_change($current,$previous);$gr=provider_kpi_goal_result($current,$goal,!empty($def['higher_is_better']));?>
<tr><td><a class="provider-kpi-name-link" href="<?=url('business-provider-kpi-drilldown',['id'=>$provider['id'],'code'=>$code,'month'=>$monthKey])?>"><strong><?=e($def['name'])?></strong><span>Details</span></a></td><td><?=e(provider_kpi_format_value($current,$def))?></td><td><?=e(provider_kpi_format_value($previous,$def))?></td><td><?php if($change['value']===null):?>—<?php else:?><span class="kpi-change <?=$change['value']>=0?'positive':'negative'?>"><?=e(($change['value']>=0?'+':'').provider_kpi_format_value($change['value'],$def))?><?=$change['percent']===null?'':' ('.e(($change['percent']>=0?'+':'').number_format($change['percent'],1)).'%)'?></span><?php endif;?></td><td><?=e(provider_kpi_format_value($ytd,$def))?></td><td><?=e(provider_kpi_format_value($goal,$def))?></td><td><?=e(provider_kpi_format_value($gr['variance'],$def))?></td><td><?php if($gr['percent']===null):?>—<?php else:?><span class="goal-pill <?=$gr['status']?>"><?=e(number_format((float)$gr['percent'],1))?>%</span><?php endif;?></td></tr>
<?php endforeach;?><?php if(!$shown):?><tr><td colspan="8"><div class="empty-inline">No data has been imported for this section.</div></td></tr><?php endif;?></tbody></table></div></section>
<?php endforeach;?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js"></script>
