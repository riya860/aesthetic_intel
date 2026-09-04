<?php
$enabled=!empty($settings['enabled']);
$monthKey=substr($month,0,7);
$defs=$snapshot['definitions']??[];
$aggregate=$snapshot['aggregate']??[];
$providerRows=$snapshot['providers']??[];
$keyCards=['total_production','total_revenue','revenue_per_hour','utilization_rate','average_ticket','new_patients'];
$opportunityRows=$clinicOpportunities['rows']??[];
$opportunityTotals=$clinicOpportunities['totals']??[];
?>
<?php include __DIR__.'/provider-kpi-nav.php';?>
<div class="provider-print-meta"><strong><?=e(provider_kpi_business_label((int)business_context_id()))?></strong><span>Clinic Provider KPI Report · <?=e(provider_kpi_month_label((string)$month))?></span><small>Generated <?=e(date('M j, Y g:i A'))?></small></div>
<div class="page-head provider-kpi-page-head">
 <div><span class="eyebrow">Provider Performance</span><h1><?=e($settings['module_name']??'Provider KPI Dashboard')?></h1><p>Clinic-wide visibility into performance, goals, capacity, opportunities, and coaching.</p></div>
 <div class="page-actions no-print"><?php if($enabled&&$providerRows):?><a class="btn btn-secondary" href="<?=url('business-provider-kpi-clinic-export',['month'=>$monthKey])?>">Export Clinic CSV</a><button class="btn btn-secondary" type="button" onclick="window.print()">Print / Save PDF</button><?php endif;?>
 </div>
</div>

<?php if($enabled&&$canManage):?>
<details class="panel provider-readiness no-print" <?=$readiness['percent']<100?'open':''?>>
 <summary><span><strong>Production Readiness</strong><small><?=e((string)$readiness['percent'])?>% complete for <?=e(provider_kpi_month_label($month))?></small></span><span class="status <?=$readiness['percent']===100?'status-completed':'status-warning'?>"><?=e((string)$readiness['percent'])?>%</span></summary>
 <div class="provider-readiness-grid"><?php foreach($readiness['steps'] as $step):?><article class="<?=$step['complete']?'complete':''?>"><b><?=$step['complete']?'✓':'•'?></b><div><strong><?=e($step['label'])?></strong><small><?=e($step['help'])?></small></div></article><?php endforeach;?></div>
 <div class="provider-access-summary"><span><?=e((string)$readiness['active_providers'])?> active provider(s)</span><span><?=e((string)$readiness['linked_providers'])?> linked login(s)</span><span><?=e((string)$readiness['leadership'])?> leadership user(s)</span><span><?=e((string)$readiness['uploaders'])?> data uploader(s)</span><span><?=e((string)$readiness['completed_reviews'])?> completed review(s)</span><span><?=e((string)$readiness['open_actions'])?> open action(s)</span></div>
 <div class="provider-readiness-actions"><a class="btn btn-small btn-secondary" href="<?=url('business-provider-kpi-providers')?>">Manage Providers</a><a class="btn btn-small btn-secondary" href="<?=url('business-provider-kpi-goals',['month'=>$monthKey])?>">Set Goals</a><a class="btn btn-small btn-secondary" href="<?=url('business-provider-kpi-import',['month'=>$monthKey])?>">Import Data</a><?php if(auth_is_admin()):?><a class="btn btn-small btn-secondary" href="<?=url('admin-users')?>">Manage User Access</a><?php endif;?></div>
</details>
<?php endif;?>

<?php if(!$enabled):?>
<section class="panel empty-state"><h2>Provider KPI Dashboard is not enabled</h2><p><?php if(auth_is_admin()):?>Enable it from <strong>Super Admin → Businesses → Edit Business</strong>.<?php else:?>Ask your Super Admin to enable Provider KPI for this business.<?php endif;?></p><?php if(auth_is_admin()):?><a class="btn btn-primary no-print" href="<?=url('admin-business-form',['id'=>$business['id']])?>">Edit Business</a><?php endif;?></section>
<?php else:?>
<section class="panel provider-period-bar no-print">
 <form method="get" action="<?=base_url('index.php')?>"><input type="hidden" name="page" value="business-provider-kpi"><label>Reporting month<input type="month" name="month" value="<?=e($monthKey)?>"></label><button class="btn btn-primary" type="submit">View Month</button></form>
 <div class="provider-period-copy"><strong><?=e(provider_kpi_month_label($month))?></strong><span>Compared with <?=e(provider_kpi_month_label(provider_kpi_previous_month($month)))?> · YTD through <?=e(provider_kpi_month_label($month))?></span></div>
</section>

<?php if(!$providerRows):?>
<section class="panel empty-state"><h2>No provider data yet</h2><p>Start by adding providers, setting goals, and importing the monthly KPI template.</p><?php if($canManage):?><a class="btn btn-primary" href="<?=url('business-provider-kpi-providers')?>">Add First Provider</a><?php endif;?></section>
<?php else:?>
<section class="provider-kpi-card-grid">
 <?php foreach($keyCards as $code):$def=$defs[$code]??null;if(!$def)continue;$value=$aggregate[$code]??null;if($value===null)continue;?>
 <article class="provider-kpi-summary-card"><span><?=e($def['name'])?></span><strong><?=e(provider_kpi_format_value((float)$value,$def))?></strong><small>Clinic total · <?=e(provider_kpi_month_label($month))?></small></article>
 <?php endforeach;?>
 <article class="provider-kpi-summary-card provider-kpi-goal-card"><span>Average Goal Attainment</span><strong><?=$snapshot['goal_attainment']===null?'—':e(number_format((float)$snapshot['goal_attainment'],1).'%')?></strong><div class="goal-progress"><i style="width:<?=e((string)min(100,max(0,(float)($snapshot['goal_attainment']??0))))?>%"></i></div><small>Across providers with goals</small></article>
</section>

<?php if(array_filter($opportunityTotals,fn($v)=>(float)$v>0)):?>
<section class="panel provider-clinic-opportunity">
 <div class="section-head"><div><span class="eyebrow">Opportunity Dashboard</span><h2>Where the clinic can improve next</h2></div><p>Calculated from current goals, open hours, revenue per hour, average ticket, and patient volume.</p></div>
 <div class="provider-opportunity-summary-grid">
  <?php if(($opportunityTotals['revenue_gap']??0)>0):?><article><span>Revenue needed to goal</span><strong><?=money((float)$opportunityTotals['revenue_gap'])?></strong></article><?php endif;?>
  <?php if(($opportunityTotals['revenue_lost_open_hours']??0)>0):?><article><span>Potential revenue from open hours</span><strong><?=money((float)$opportunityTotals['revenue_lost_open_hours'])?></strong></article><?php endif;?>
  <?php if(($opportunityTotals['remaining_capacity']??0)>0):?><article><span>Estimated remaining appointments</span><strong><?=e(number_format((float)$opportunityTotals['remaining_capacity'],0))?></strong></article><?php endif;?>
 </div>
 <?php $topOpportunities=array_slice(array_values(array_filter($opportunityRows,fn($r)=>(float)($r['opportunity']['priority_score']??0)>0)),0,3);if($topOpportunities):?>
 <div class="provider-opportunity-list">
  <?php foreach($topOpportunities as $item):$p=$item['provider'];$op=$item['opportunity'];$gap=$op['cards']['revenue_gap']['value'];$lost=$op['cards']['revenue_lost_open_hours']['value'];?>
  <article><div><strong><?=e($p['name'])?></strong><small><?=e(trim(($p['provider_type']??'').(($p['provider_type']??'')&&($p['department']??'')?' · ':'').($p['department']??''))?:'Provider')?></small></div><div class="provider-opportunity-list-values"><?php if($gap!==null&&$gap>0):?><span><?=money((float)$gap)?> to goal</span><?php endif;?><?php if($lost!==null&&$lost>0):?><span><?=money((float)$lost)?> potential open-hour revenue</span><?php endif;?></div><a class="btn btn-small btn-secondary" href="<?=url('business-provider-kpi-opportunities',['id'=>$p['id'],'month'=>$monthKey])?>">View Plan</a></article>
  <?php endforeach;?>
 </div>
 <?php endif;?>
</section>
<?php endif;?>

<section class="panel">
 <div class="section-head"><div><span class="eyebrow">Clinic Overview</span><h2>Provider Scorecards</h2></div><p>Review performance, opportunities, trends, and coaching for each provider.</p></div>
 <div class="table-wrap"><table class="provider-ranking-table"><thead><tr><th>Provider</th><th>Production</th><th>Revenue / Hour</th><th>Utilization</th><th>Average Ticket</th><th>New Patients</th><th>Goal Attainment</th><?php if($canViewCoaching):?><th>Coaching</th><?php endif;?><th data-no-sort>Open</th></tr></thead><tbody>
 <?php foreach($providerRows as $row):$p=$row['provider'];$v=$row['current'];$goal=$row['goal_attainment'];?>
 <tr><td><strong><?=e($p['name'])?></strong><small class="block"><?=e(trim(($p['provider_type']??'').(($p['provider_type']??'')&&($p['department']??'')?' · ':'').($p['department']??''))?:'Provider')?></small></td>
 <td><?=isset($v['total_production'])?e(provider_kpi_format_value((float)$v['total_production'],$defs['total_production'])):'—'?></td>
 <td><?=isset($v['revenue_per_hour'])?e(provider_kpi_format_value((float)$v['revenue_per_hour'],$defs['revenue_per_hour'])):'—'?></td>
 <td><?=isset($v['utilization_rate'])?e(provider_kpi_format_value((float)$v['utilization_rate'],$defs['utilization_rate'])):'—'?></td>
 <td><?=isset($v['average_ticket'])?e(provider_kpi_format_value((float)$v['average_ticket'],$defs['average_ticket'])):'—'?></td>
 <td><?=isset($v['new_patients'])?e(provider_kpi_format_value((float)$v['new_patients'],$defs['new_patients'])):'—'?></td>
 <td><?php if($goal===null):?><span class="status status-neutral">No goals</span><?php else:$cls=$goal>=100?'completed':($goal>=80?'warning':'failed');?><span class="status status-<?=$cls?>"><?=e(number_format((float)$goal,1))?>%</span><?php endif;?></td>
 <?php if($canViewCoaching):?><td><?php if(empty($row['review'])):?><span class="status status-neutral">Not started</span><?php else:?><a class="provider-coaching-status-link" href="<?=url('business-provider-kpi-coaching',['id'=>$p['id'],'month'=>$monthKey])?>"><span class="status <?=$row['review']['review_status']==='completed'?'status-completed':'status-warning'?>"><?=e(ucfirst((string)$row['review']['review_status']))?></span><?php if(!empty($row['open_actions'])):?><small><?=e((string)$row['open_actions'])?> open action(s)</small><?php endif;?></a><?php endif;?></td><?php endif;?>
 <td><div class="provider-row-actions"><a class="btn btn-small btn-secondary" href="<?=url('business-provider-kpi-provider',['id'=>$p['id'],'month'=>$monthKey])?>">Scorecard</a><a class="btn btn-small btn-secondary" href="<?=url('business-provider-kpi-opportunities',['id'=>$p['id'],'month'=>$monthKey])?>">Opportunity</a></div></td></tr>
 <?php endforeach;?></tbody></table></div>
</section>
<?php endif;?>
<?php endif;?>
