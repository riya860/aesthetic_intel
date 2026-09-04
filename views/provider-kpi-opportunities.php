<?php
$monthKey=substr($month,0,7);$cards=$opportunity['cards']??[];$inputs=$opportunity['inputs']??[];$snapshot=$opportunity['snapshot']??[];$defs=$snapshot['definitions']??[];
$formatCard=static function(array $card):string{if($card['value']===null)return '—';return match($card['format']){'currency'=>money((float)$card['value']),'hours'=>number_format((float)$card['value'],1).' hrs',default=>number_format((float)$card['value'],0)};};
?>
<div class="provider-print-meta"><strong><?=e(provider_kpi_business_label((int)business_context_id()))?></strong><span>Provider KPI Report<?php if(isset($month)):?> · <?=e(provider_kpi_month_label((string)$month))?><?php endif;?></span><small>Generated <?=e(date('M j, Y g:i A'))?></small></div>
<div class="page-head"><div><span class="eyebrow">Opportunity Dashboard</span><h1><?=e($provider['name'])?></h1><p>Translate performance gaps and unused capacity into clear, actionable numbers for <?=e(provider_kpi_month_label($month))?>.</p></div><div class="page-actions no-print"><a class="btn btn-secondary" href="<?=url('business-provider-kpi-provider',['id'=>$provider['id'],'month'=>$monthKey])?>">Scorecard</a><?php if($canViewCoaching):?><a class="btn btn-secondary" href="<?=url('business-provider-kpi-coaching',['id'=>$provider['id'],'month'=>$monthKey])?>">Coaching</a><?php endif;?><button class="btn btn-primary" type="button" onclick="window.print()">Print / Save PDF</button></div></div>
<section class="panel provider-period-bar no-print"><form method="get" action="<?=base_url('index.php')?>"><input type="hidden" name="page" value="business-provider-kpi-opportunities"><input type="hidden" name="id" value="<?=$provider['id']?>"><label>Reporting month<input type="month" name="month" value="<?=e($monthKey)?>"></label><button class="btn btn-primary" type="submit">View Month</button></form><div class="provider-period-copy"><strong><?=e(provider_kpi_month_label($month))?></strong><span>Uses the provider's current actuals, goals, capacity, and productivity data.</span></div></section>

<section class="provider-opportunity-card-grid provider-opportunity-full-grid">
<?php foreach($cards as $code=>$card):$available=$card['value']!==null;?>
<article class="<?=$available?'':'provider-opportunity-unavailable'?>"><span><?=e($card['label'])?></span><strong><?=e($formatCard($card))?></strong><small><?=e($card['help'])?></small><?php if(!$available):?><em>More source data or a revenue goal is needed.</em><?php endif;?></article>
<?php endforeach;?>
</section>

<section class="panel">
 <div class="section-head"><div><span class="eyebrow">Recommended Focus</span><h2>What the numbers suggest</h2></div><p>These suggestions are calculated, not manually written.</p></div>
 <div class="provider-focus-list">
  <?php $gap=$cards['revenue_gap']['value']??null;$lost=$cards['revenue_lost_open_hours']['value']??null;$patients=$cards['additional_patients']['value']??null;$ticket=$cards['average_ticket_increase']['value']??null;$rate=$cards['required_open_hour_rate']['value']??null;?>
  <?php if($gap!==null&&$gap>0):?><article><span class="provider-focus-number">1</span><div><strong>Close the monthly revenue gap</strong><p><?=money((float)$gap)?> remains to reach the selected revenue goal.<?php if($patients!==null):?> At the current average ticket, this is approximately <?=number_format((float)$patients,0)?> additional patient visit(s).<?php endif;?></p></div></article><?php endif;?>
  <?php if($lost!==null&&$lost>0):?><article><span class="provider-focus-number">2</span><div><strong>Convert unused schedule capacity</strong><p>Current open hours represent approximately <?=money((float)$lost)?> in revenue opportunity at the provider's current revenue per hour.<?php if($rate!==null):?> To close the goal entirely through open time, those hours need to average <?=money((float)$rate)?> per hour.<?php endif;?></p></div></article><?php endif;?>
  <?php if($ticket!==null&&$ticket>0):?><article><span class="provider-focus-number">3</span><div><strong>Raise the value of existing visits</strong><p>An average-ticket increase of <?=money((float)$ticket)?> across current patients would close the calculated revenue gap without requiring additional visits.</p></div></article><?php endif;?>
  <?php if(($gap===null||$gap<=0)&&($lost===null||$lost<=0)&&($ticket===null||$ticket<=0)):?><div class="empty-inline">No actionable revenue gap is currently available. Add a Total Revenue or Total Production goal and schedule/productivity data to unlock the opportunity plan.</div><?php endif;?>
 </div>
</section>

<section class="panel">
 <div class="section-head"><div><span class="eyebrow">Calculation Inputs</span><h2>Numbers used by the opportunity engine</h2></div><p>Review these inputs before using the results in a provider meeting.</p></div>
 <div class="table-wrap"><table><thead><tr><th>Input</th><th>Value</th><th>How it is used</th></tr></thead><tbody>
 <?php
 $inputRows=[
  ['Open Hours',$inputs['open_hours']??null,'hours','Calculates unused schedule opportunity and the required rate across open hours.'],
  ['Productive / Revenue-Producing Hours',$inputs['productive_hours']??null,'hours','Calculates required revenue per hour and average visit duration.'],
  ['Revenue Per Hour',$inputs['revenue_per_hour']??null,'currency','Estimates revenue available from open hours and additional hours needed.'],
  ['Average Ticket',$inputs['average_ticket']??null,'currency','Calculates the additional patients needed to reach the goal.'],
  ['Patients Seen',$inputs['patients']??null,'number','Calculates average-ticket increase and estimated visit duration.'],
  ['Average Visit Duration',$inputs['average_visit_hours']??null,'hours','Estimates remaining appointment capacity when a capacity value was not imported.'],
 ];
 foreach($inputRows as [$label,$value,$format,$help]):$display=$value===null?'—':match($format){'currency'=>money((float)$value),'hours'=>number_format((float)$value,2).' hrs',default=>number_format((float)$value,0)};?>
 <tr><td><strong><?=e($label)?></strong></td><td><?=e($display)?></td><td><?=e($help)?></td></tr><?php endforeach;?>
 </tbody></table></div>
</section>
