<?php
$adminParams=[];
$businessId=(int)business_context_id();
$features=business_feature_effective_states($businessId);
$boulevardEnabled=!empty($features['boulevard']);
$boulevardApiEnabled=$boulevardEnabled&&!empty($features['boulevard_api']);
$gbpEnabled=!empty($features['gbp']);
$podiumEnabled=!empty($features['podium']);
$growth99Enabled=!empty($features['growth99']);
$ga4Enabled=!empty($features['ga4']);
$providerKpiShow=!empty($features['provider_kpi'])&&provider_kpi_navigation_visible($businessId);
$autoBoulevard=$boulevardApiEnabled&&!empty($boulevardUserAccess['enabled']);
$boulevardActionUrl=(!$autoBoulevard||auth_is_admin())?url('business-upload',$adminParams):url('business-boulevard-run');
$boulevardActionLabel=(!$autoBoulevard||auth_is_admin())?'Add Data':'Run Weekly Report';
$hasDashboardFeatures=$boulevardEnabled||$gbpEnabled||$podiumEnabled||$growth99Enabled||$ga4Enabled||$providerKpiShow;
$featureCount=count(array_filter([$boulevardEnabled,$gbpEnabled,$podiumEnabled,$growth99Enabled,$ga4Enabled,$providerKpiShow]));

$latestValidationStatus=$latest?(string)($latest['validation_status']??'validated'):null;
$latestAllowed=$latest&&report_validation_is_allowed($latestValidationStatus);
$dashboard=$latestAllowed?(json_decode((string)$latest['dashboard_json'],true)?:[]):[];
$dashboardKpis=$dashboard['kpis']??[];
$dailyRows=$dashboard['daily']??[];
$providers=$dashboard['providers']??[];
$topRetail=$dashboard['top_retail']??[];
$topServices=$dashboard['top_services']??[];
$revenueCategories=$dashboard['revenue_categories']??[];

$heroKeys=['total_revenue','appointments','active_mrr'];
$heroMetrics=[];
foreach($heroKeys as $key){if(isset($dashboardKpis[$key]))$heroMetrics[$key]=$dashboardKpis[$key];}
$utilization=(float)($dashboardKpis['utilization']['value']??0);
$utilGauge=max(0,min(100,$utilization));
$maxDaily=0.0;
foreach($dailyRows as $row)$maxDaily=max($maxDaily,(float)($row['revenue']??0));
if(count($dailyRows)>14)$dailyRows=array_slice($dailyRows,-14);

$mix=[];$mixTotal=0.0;
foreach($revenueCategories as $row){$value=max(0,(float)($row['value']??0));if($value<=0)continue;$mix[]=['label'=>(string)($row['label']??'Other'),'value'=>$value];$mixTotal+=$value;}
$mixColors=['#f06f5b','#b34f82','#6d4cf0','#281c5f','#19191b'];
$mixStops=[];$cursor=0.0;
if($mixTotal>0){foreach($mix as $i=>$row){$start=$cursor;$cursor+=($row['value']/$mixTotal)*100;$mixStops[]=$mixColors[$i%count($mixColors)].' '.number_format($start,2,'.','').'% '.number_format($cursor,2,'.','').'%';}}
$mixStyle=$mixStops?'background:conic-gradient('.implode(',',$mixStops).')':'';

$leaderItems=$topRetail?:$topServices;
$leaderTitle=$topRetail?'Top Retail Products':'Top Services';
?>
<div class="page-head ai-dashboard-head">
 <div>
  <span class="eyebrow"><?=e($business['name'])?></span>
  <h1>Performance Dashboard</h1>
  <p>Your enabled intelligence sources, latest performance signals, and reporting actions in one focused workspace.</p>
 </div>
 <a class="btn btn-primary" href="<?=url('business-history',$adminParams)?>">Open Reports</a>
</div>

<section class="ai-bento-dashboard" aria-label="Performance overview">
 <article class="ai-bento-card ai-performance-hero">
  <div class="ai-card-topline">
   <div><span class="ai-card-kicker"><?=$latestAllowed?'Latest Boulevard snapshot':'Performance workspace'?></span><h2><?=$latestAllowed?'Clinic Performance':'Ready for your next report'?></h2></div>
   <span class="ai-dot-menu" aria-hidden="true">•••</span>
  </div>
  <?php if($latestAllowed):?>
   <div class="ai-hero-metrics">
    <?php foreach($heroMetrics as $metric):?>
     <div><small><?=e($metric['label'])?></small><strong><?=metric_display($metric)?></strong><span class="trend trend-<?=e($metric['sentiment']??'neutral')?>"><?=e(change_text($metric))?></span></div>
    <?php endforeach;?>
   </div>
   <div class="ai-revenue-flow" aria-label="Revenue trend for the latest reporting period">
    <?php if($dailyRows&&$maxDaily>0):?>
     <div class="ai-flow-grid">
      <?php foreach($dailyRows as $row):$height=max(12,min(100,((float)($row['revenue']??0)/$maxDaily)*100));?>
       <i style="--bar:<?=e(number_format($height,1,'.',''))?>%" title="<?=e((string)($row['label']??''))?> · <?=e(money((float)($row['revenue']??0)))?>"></i>
      <?php endforeach;?>
     </div>
    <?php else:?><div class="ai-flow-empty">Performance trend will appear when daily revenue data is available.</div><?php endif;?>
   </div>
   <a class="ai-inline-link" href="<?=url('business-report',['id'=>$latest['id']]+$adminParams)?>">Explore complete report <span>→</span></a>
  <?php else:?>
   <div class="ai-hero-empty-copy">
    <strong><?=e((string)$featureCount)?> enabled module<?=$featureCount===1?'':'s'?></strong>
    <p>Add current data from any enabled source. Aesthetic Intel will keep the existing validation and reporting workflow while this dashboard updates automatically.</p>
   </div>
   <div class="ai-orbit-visual" aria-hidden="true"><i></i><i></i><i></i><b></b></div>
   <?php if($boulevardEnabled):?><a class="ai-inline-link" href="<?=e($boulevardActionUrl)?>"><?=e($boulevardActionLabel)?> <span>→</span></a><?php else:?><a class="ai-inline-link" href="<?=url('business-history')?>">Open reports <span>→</span></a><?php endif;?>
  <?php endif;?>
 </article>
<?php if(
    !empty(
        $featureStates[
            'ai_weekly_report'
        ]
    )
    &&
    !empty(
        $latestAiWeeklyReport
    )
):?>

<section
 class="content-card ai-weekly-dashboard-home"
 style="margin-top:18px"
>

 <div class="card-head">

  <div>

   <p class="eyebrow">
    AI Weekly Report
   </p>

   <h2>
    Latest Weekly Intelligence
   </h2>

   <p>
    <?=e(
        $latestAiWeeklyReport[
            'period_start'
        ]
    )?>

    →

    <?=e(
        $latestAiWeeklyReport[
            'period_end'
        ]
    )?>
   </p>

  </div>

  <a
   class="btn btn-secondary btn-small"
   href="<?=url(
       'business-ai-weekly-report',
       [
           'id' =>
               (int)$latestAiWeeklyReport[
                   'id'
               ]
       ]
   )?>"
  >
   Open Full Report
  </a>

 </div>

 <?php

 $report =
     $latestAiWeeklyReport;

 $dashboard =
     ai_weekly_report_decode(
         $report
     );

 require
     VIEW_PATH
     . '/partials/ai-weekly-dashboard.php';

 ?>

</section>

<?php endif;?>
 <article class="ai-bento-card ai-gauge-card">
  <div class="ai-card-topline"><div><span class="ai-card-kicker"><?=$latestAllowed?'Utilization':'Workspace readiness'?></span><h2><?=$latestAllowed?'Capacity Conversion':'Enabled tools'?></h2></div><span class="ai-dot-menu" aria-hidden="true">•••</span></div>
  <?php if($latestAllowed&&isset($dashboardKpis['utilization'])):?>
   <div class="ai-semi-gauge" style="--gauge-angle:<?=e(number_format($utilGauge*1.8,1,'.',''))?>deg;--gauge-mid:<?=e(number_format($utilGauge*1.8*.58,1,'.',''))?>deg"><div><strong><?=e(number_format($utilization,1))?>%</strong><small><?=e(change_text($dashboardKpis['utilization']))?></small></div></div>
   <div class="ai-gauge-caption"><strong><?= $utilization>=80?'Strong capacity use':($utilization>=60?'Healthy room to grow':'Capacity opportunity') ?></strong><span>Blended booked hours vs scheduled hours</span></div>
  <?php else:?>
   <?php $readinessPct=round(($featureCount/max(1,6))*100);?>
   <div class="ai-semi-gauge ai-readiness-gauge" style="--gauge-angle:<?=e(number_format($readinessPct*1.8,1,'.',''))?>deg;--gauge-mid:<?=e(number_format($readinessPct*1.8*.58,1,'.',''))?>deg"><div><strong><?=e((string)$featureCount)?> / 6</strong><small>Core dashboard modules</small></div></div>
   <div class="ai-gauge-caption"><strong>Workspace is configured</strong><span>Only enabled features are shown throughout the UI.</span></div>
  <?php endif;?>
 </article>

 <?php $smallCards=[
  $dashboardKpis['total_revenue']??null,
  $dashboardKpis['average_ticket']??null,
  $dashboardKpis['new_clients']??null,
 ];?>
 <?php foreach($smallCards as $idx=>$metric):?>
 <article class="ai-bento-card ai-mini-kpi ai-mini-kpi-<?=e((string)($idx+1))?>">
  <?php if($metric):?><small><?=e($metric['label'])?></small><strong><?=metric_display($metric)?></strong><span class="trend trend-<?=e($metric['sentiment']??'neutral')?>"><?=e(change_text($metric))?></span>
  <?php else:?><small><?=['Reporting sources','AI data sources','Performance tools'][$idx]?></small><strong><?=e((string)($idx===0?($boulevardEnabled+$gbpEnabled):($idx===1?($podiumEnabled+$growth99Enabled+$ga4Enabled):($providerKpiShow?1:0))))?></strong><span class="trend trend-neutral">Enabled for this business</span><?php endif;?>
 </article>
 <?php endforeach;?>

 <article class="ai-bento-card ai-leader-card">
  <div class="ai-card-topline"><div><span class="ai-card-kicker">Performance leaders</span><h2><?=e($leaderItems?$leaderTitle:'Connected Sources')?></h2></div><span class="ai-dot-menu" aria-hidden="true">•••</span></div>
  <?php if($leaderItems):?>
   <div class="ai-leader-list">
    <?php foreach(array_slice($leaderItems,0,4) as $i=>$item):?>
     <div class="ai-leader-row"><span class="ai-rank"><?=e((string)($i+1))?></span><div><strong><?=e($item['name'])?></strong><small><?=isset($item['quantity'])&&$item['quantity']>0?e(numfmt($item['quantity'])).' sold':'Latest period'?></small></div><b><?=money((float)($item['value']??0))?></b></div>
    <?php endforeach;?>
   </div>
  <?php else:?>
   <div class="ai-source-pills"><?php foreach([['Boulevard',$boulevardEnabled],['GBP',$gbpEnabled],['Podium',$podiumEnabled],['Growth99+',$growth99Enabled],['GA4',$ga4Enabled],['Provider KPI',$providerKpiShow]] as [$label,$on]):if(!$on)continue;?><span><?=e($label)?></span><?php endforeach;?></div>
   <p class="ai-card-note">Enabled sources stay available through the same tools and reporting routes.</p>
  <?php endif;?>
  <a class="ai-inline-link" href="<?=url('business-history')?>">See reporting history <span>→</span></a>
 </article>

 <article class="ai-bento-card ai-mix-card">
  <div class="ai-card-topline"><div><span class="ai-card-kicker">Composition</span><h2><?=$mixTotal>0?'Revenue Mix':'Data Sources'?></h2></div><span class="ai-dot-menu" aria-hidden="true">•••</span></div>
  <?php if($mixTotal>0):?>
   <div class="ai-mix-layout"><div class="ai-donut" style="<?=e($mixStyle)?>"><span></span></div><div class="ai-mix-legend"><?php foreach(array_slice($mix,0,5) as $i=>$row):?><div><i style="--legend:<?=e($mixColors[$i%count($mixColors)])?>"></i><span><?=e($row['label'])?></span><b><?=e(number_format(($row['value']/$mixTotal)*100,0))?>%</b></div><?php endforeach;?></div></div>
  <?php else:?>
   <div class="ai-mix-placeholder"><span><?=e((string)$featureCount)?></span><p>enabled modules adapt into the dashboard automatically.</p></div>
  <?php endif;?>
 </article>

 <article class="ai-bento-card ai-team-card">
  <div class="ai-card-topline"><div><span class="ai-card-kicker">Provider performance</span><h2><?=$providers?'Top Providers':'Provider Intelligence'?></h2></div><span class="ai-dot-menu" aria-hidden="true">•••</span></div>
  <?php if($providers):?>
   <div class="ai-provider-stack"><?php foreach(array_slice($providers,0,4) as $i=>$provider):$parts=preg_split('/\s+/',trim((string)$provider['name']));$initials='';foreach(array_slice($parts,0,2) as $part)$initials.=strtoupper(substr($part,0,1));?><span style="--i:<?=e((string)$i)?>" title="<?=e($provider['name'])?>"><?=e($initials?:'P')?></span><?php endforeach;?><?php if(count($providers)>4):?><b>+<?=e((string)(count($providers)-4))?></b><?php endif;?></div>
   <div class="ai-team-leader"><strong><?=e($providers[0]['name'])?></strong><span><?=money((float)$providers[0]['service_revenue'])?> service revenue</span></div>
  <?php else:?>
   <div class="ai-provider-stack ai-provider-empty"><span>KPI</span></div><div class="ai-team-leader"><strong><?=$providerKpiShow?'Provider KPI is enabled':'Provider KPI can be enabled per business'?></strong><span>Scorecards and goals keep their existing workflow.</span></div>
  <?php endif;?>
  <?php if($providerKpiShow):?><a class="ai-card-arrow" href="<?=url('business-provider-kpi')?>" aria-label="Open Provider KPI">→</a><?php endif;?>
 </article>
</section>

<?php if($hasDashboardFeatures):?>
<div class="panel-head dashboard-section-head ai-sources-heading"><div><span class="eyebrow">Workspace tools</span><h2>Connected Sources</h2><p class="muted">The actions below use the exact same uploads, API runs, history, and feature controls as before.</p></div></div>
<section class="source-grid source-grid-premium ai-source-grid">
 <?php if($boulevardEnabled):?><article class="source-card"><div class="source-main"><div class="source-icon source-boulevard">B</div><div><h3>Boulevard</h3><p><?=$autoBoulevard&&!auth_is_admin()?'Run the approved weekly API report with one click.':'Revenue, appointments, memberships, retail, and provider performance.'?></p></div></div><div class="source-actions"><a class="btn btn-primary" href="<?=e($boulevardActionUrl)?>"><?=e($boulevardActionLabel)?></a><a class="btn btn-secondary" href="<?=url('business-history',$adminParams)?>">History</a></div></article><?php endif;?>
 <?php if($gbpEnabled):?><article class="source-card"><div class="source-main"><div class="source-icon source-gbp">G</div><div><h3>Google Business Profile</h3><p>Track calls, directions, website clicks, interactions, and reviews.</p></div></div><div class="source-actions"><a class="btn btn-primary" href="<?=url('business-gbp',$adminParams)?>">Add Data</a><a class="btn btn-secondary" href="<?=url('business-gbp-history',$adminParams)?>">History</a></div></article><?php endif;?>
 <?php if($podiumEnabled):?><article class="source-card"><div class="source-main"><div class="source-icon source-podium">P</div><div><h3>Podium</h3><p>Upload Inbox and Calls reports for AI-powered extraction.</p></div></div><div class="source-actions"><a class="btn btn-primary" href="<?=url('business-ai-extraction',['source'=>'podium']+$adminParams)?>">Upload Report</a></div></article><?php endif;?>
 <?php if($growth99Enabled):?><article class="source-card"><div class="source-main"><div class="source-icon source-growth">99</div><div><h3>Growth99+</h3><p>Bring in lead, Cliffhanger, and CallRail insight reports.</p></div></div><div class="source-actions"><a class="btn btn-primary" href="<?=url('business-ai-extraction',['source'=>'growth99']+$adminParams)?>">Upload Report</a></div></article><?php endif;?>
 <?php if($ga4Enabled):?><article class="source-card"><div class="source-main"><div class="source-icon source-ga4">G4</div><div><h3>Google Analytics 4</h3><p>Extract users, engagement, page views, clicks, and conversions.</p></div></div><div class="source-actions"><a class="btn btn-primary" href="<?=url('business-ai-extraction',['source'=>'ga4']+$adminParams)?>">Upload Report</a></div></article><?php endif;?>
 <?php if($providerKpiShow):?><article class="source-card source-card-provider-kpi"><div class="source-main"><div class="source-icon source-provider-kpi">KPI</div><div><h3>Provider KPI Dashboard</h3><p>Provider scorecards, monthly goals, clinic rollups, and performance visibility.</p></div></div><div class="source-actions"><a class="btn btn-primary" href="<?=url('business-provider-kpi')?>">Open Dashboard</a></div></article><?php endif;?>
</section>
<?php else:?>
<section class="empty-state"><div class="empty-icon">⚙</div><h2>Workspace is ready</h2><p>No optional business features are enabled right now. <?php if(auth_is_admin()):?>Use Edit Business → Feature Controls to turn on only the tools this business needs.<?php else:?>Ask your Super Admin to enable the tools your team uses.<?php endif;?></p><?php if(auth_is_admin()):?><a class="btn btn-primary" href="<?=url('admin-business-form',['id'=>$businessId])?>">Manage Feature Controls</a><?php endif;?></section>
<?php endif;?>

<?php if($boulevardEnabled):?>
 <?php if($latest):?>
  <?php if(!report_validation_is_allowed($latestValidationStatus)):?>
  <section class="panel feature-panel validation-dashboard-hold"><div><span class="validation-badge validation-danger">Review required</span><h2>Latest Boulevard report is being held safely</h2><p><?=e(reporting_us_date($latest['period_start']))?> – <?=e(reporting_us_date($latest['period_end']))?> is not being used as the current comparison because Report Intelligence found a possible period or data issue.</p></div><a class="btn btn-secondary" href="<?=url('business-report',['id'=>$latest['id']]+$adminParams)?>">Review Report</a></section>
  <?php else:?>
  <section class="panel feature-panel ai-latest-report-panel"><div><span class="status status-completed">Latest Boulevard report ready</span><h2><?=e(reporting_us_date($latest['period_start']))?> – <?=e(reporting_us_date($latest['period_end']))?></h2><p>Review the complete interactive infographic, provider performance, memberships, retail details, and insights.</p></div><a class="btn btn-primary" href="<?=url('business-report',['id'=>$latest['id']]+$adminParams)?>">Open Full Report</a></section>
  <?php endif;?>
 <?php else:?><section class="empty-state"><div class="empty-icon">B</div><h2>No Boulevard report yet</h2><p><?=$autoBoulevard&&!auth_is_admin()?'Run the approved weekly Boulevard report. Aesthetic Intel will continue processing in the background.':'Upload one or more Boulevard CSV exports to create the first performance dashboard.'?></p><a class="btn btn-primary" href="<?=e($boulevardActionUrl)?>"><?=$autoBoulevard&&!auth_is_admin()?'Run Weekly Report':'Start First Upload'?></a></section><?php endif;?>
<?php endif;?>

<?php if($gbpEnabled):?>
 <?php if($latestGbp):$latestGbpValidation=(string)($latestGbp['validation_status']??'validated');?>
  <?php if(!report_validation_is_allowed($latestGbpValidation)):?>
  <section class="panel feature-panel validation-dashboard-hold"><div><span class="validation-badge validation-danger">Review required</span><h2>Latest Google Business Profile data is being held safely</h2><p><?=e(reporting_us_date($latestGbp['period_start']))?> – <?=e(reporting_us_date($latestGbp['period_end']))?> is not being used for automatic activity comparisons until it is reviewed.</p></div><a class="btn btn-secondary" href="<?=url('business-gbp-report',['id'=>$latestGbp['id']]+$adminParams)?>">Review GBP Report</a></section>
  <?php else:$previous=gbp_previous_entries((int)$business['id'],(string)$latestGbp['period_end'],(int)$latestGbp['id'],2,(string)$latestGbp['frequency'],(string)$latestGbp['period_start']);$ga=gbp_build_analysis($latestGbp,$previous);?>
  <section class="panel ai-gbp-snapshot"><div class="panel-head"><div><span class="eyebrow">Latest snapshot</span><h2>Google Business Profile</h2><p class="muted"><?=e(reporting_us_date($latestGbp['period_start']))?> – <?=e(reporting_us_date($latestGbp['period_end']))?></p></div><a class="btn btn-secondary" href="<?=url('business-gbp-report',['id'=>$latestGbp['id']]+$adminParams)?>">Open GBP Report</a></div><div class="kpi-grid four"><?php foreach(['interactions','calls','directions','website_clicks'] as $key):$x=$ga['metrics'][$key];?><article class="kpi-card"><small><?=e($x['label'])?></small><strong><?=$x['activity']===null?'Baseline':numfmt($x['activity'])?></strong><span class="trend trend-neutral"><?=e(gbp_activity_text($x))?></span></article><?php endforeach;?></div></section>
  <?php endif;?>
 <?php else:?><section class="panel feature-panel"><div><span class="status status-draft">GBP ready to configure</span><h2>Add your first GBP baseline</h2><p>Enter the current cumulative totals. The next entry will calculate new activity automatically.</p></div><a class="btn btn-primary" href="<?=url('business-gbp',$adminParams)?>">Add GBP Baseline</a></section><?php endif;?>
<?php endif;?>

<?php if($boulevardEnabled):?><section class="panel ai-history-panel"><div class="panel-head"><div><span class="eyebrow">Timeline</span><h2>Recent Boulevard Reports</h2></div><a href="<?=url('business-history',$adminParams)?>">View all</a></div><div class="history-cards"><?php foreach($history as $h):?><a class="history-card" href="<?=$h['status']==='completed'?url('business-report',['id'=>$h['id']]+$adminParams):'#'?>"><strong><?=e(reporting_us_date($h['period_start']))?> – <?=e(reporting_us_date($h['period_end']))?></strong><?php if($h['status']==='completed'):$hm=report_validation_status_meta($h['validation_status']??'validated');?><span class="validation-badge validation-<?=e($hm['class'])?>"><?=e($hm['label'])?></span><?php else:?><span class="status status-<?=e($h['status'])?>"><?=e(ucfirst($h['status']))?></span><?php endif;?></a><?php endforeach;?><?php if(!$history):?><p class="muted">No Boulevard history yet.</p><?php endif;?></div></section><?php endif;?>
