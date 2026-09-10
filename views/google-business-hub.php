<?php
$business=is_array($model['business']??null)?$model['business']:[];
$ga4=is_array($model['ga4']??null)?$model['ga4']:null;
$gbp=is_array($model['gbp']??null)?$model['gbp']:null;
$gbpSummary=is_array($model['gbp_summary']??null)?$model['gbp_summary']:[];
$identity=is_array($model['google_identity']??null)?$model['google_identity']:null;
$control=is_array($model['ga4_control']??null)?$model['ga4_control']:[];
$days=(int)($control['days']??30);
$health=is_array($control['health']??null)?$control['health']:['level'=>'disconnected','label'=>'Not connected','message'=>''];
$current=is_array($control['current']??null)?$control['current']:[];
$comparison=is_array($control['comparison']??null)?$control['comparison']:[];
$propertyDetails=is_array($control['property_details']??null)?$control['property_details']:[];
$keyEvents=is_array($control['available_key_events']??null)?$control['available_key_events']:[];
$selectedKeyEvents=is_array($control['selected_key_events']??null)?$control['selected_key_events']:[];
$syncHistory=is_array($control['sync_history']??null)?$control['sync_history']:[];
$savedViews=is_array($control['saved_views']??null)?$control['saved_views']:[];
$latestPdf=is_array($control['latest_pdf']??null)?$control['latest_pdf']:null;
$latestComparison=is_array($control['latest_pdf_comparison']??null)?$control['latest_pdf_comparison']:null;
$insights=is_array($control['insights']??null)?$control['insights']:[];
$trend=is_array($control['trend']??null)?$control['trend']:[];
$timezone=(string)($propertyDetails['timeZone']??$business['timezone']??'UTC');
$currency=strtoupper((string)($propertyDetails['currencyCode']??'USD'))?:'USD';
$currentMetrics=is_array($current['metrics']??null)?$current['metrics']:[];

$ga4GoogleEmail=strtolower(
 trim((string)($ga4['google_email']??''))
);
$gbpGoogleEmail=strtolower(
 trim((string)($gbp['google_email']??''))
);
$gbpUsesGa4Account=
 $ga4GoogleEmail!==''
 && $gbpGoogleEmail!==''
 && hash_equals(
  $ga4GoogleEmail,
  $gbpGoogleEmail
 );

if(!function_exists('googlehub_ui_metric')){
 function googlehub_ui_metric(string $metric,?float $value,string $currency='USD'):string{
  if($value===null)return '—';
  if($metric==='engagementRate')return number_format($value*100,1).'%';
  if($metric==='totalRevenue')return googlehub_esc($currency).' '.number_format($value,2);
  return number_format($value,abs($value-round($value))<0.000001?0:2);
 }
}
if(!function_exists('googlehub_ui_change')){
 function googlehub_ui_change(?float $change):array{
  if($change===null)return ['text'=>'No prior baseline','class'=>'neutral'];
  if(abs($change)<0.05)return ['text'=>'0.0% vs previous','class'=>'neutral'];
  return ['text'=>($change>0?'↑ ':'↓ ').number_format(abs($change),1).'% vs previous','class'=>$change>0?'positive':'negative'];
 }
}
if(!function_exists('googlehub_ui_date')){
 function googlehub_ui_date(?string $value,string $fallback='Never'):string{
  $value=trim((string)$value);if($value==='')return $fallback;$ts=strtotime($value);return $ts===false?$value:date('M j, Y g:i A',$ts);
 }
}

$metricCards=[
 'sessions'=>'Sessions','activeUsers'=>'Active users','newUsers'=>'New users',
 'engagementRate'=>'Engagement rate','keyEvents'=>'Key events','totalRevenue'=>'Revenue',
];
$builtIns=[
 ['Executive Overview','Core KPI trend and period performance.','trend'],
 ['Marketing Acquisition','Channels, source / medium and campaign traffic.','acquisition'],
 ['Website Engagement','Landing pages, content and audience behavior.','content'],
 ['Revenue','E-commerce and monetization performance.','ecommerce'],
 ['Leads & Bookings','Events and key-event performance.','events'],
];
$trendData=[];
foreach($trend as $row){$trendData[]=['date'=>(string)($row['metric_date']??''),'sessions'=>(float)($row['sessions']??0),'activeUsers'=>(float)($row['active_users']??0)];}
?>
<link rel="stylesheet" href="<?=googlehub_esc(asset('css/google-business-platform.css'))?>?v=2.0.0">

<div class="google-hub-page">
 <header class="google-hub-header">
  <div>
   <span class="google-kicker"><?=googlehub_esc((string)($business['name']??'Business'))?></span>
   <h1>Google Connections</h1>
   <p>Manage Google access, monitor GA4 health, compare performance periods and jump directly into reporting.</p>
  </div>
  <a class="google-secondary-button" href="<?=googlehub_esc(url('business-dashboard'))?>">Back to dashboard</a>
 </header>

 <section class="google-identity-bar">
  <div><strong>Google login</strong><span><?=$identity?'Linked to '.googlehub_esc((string)$identity['provider_email']):'Not linked'?></span></div>
  <?php if(!$identity):?>
   <script src="https://accounts.google.com/gsi/client" async defer></script>
   <div id="g_id_onload" data-client_id="<?=googlehub_esc(googlehub_login_client_id())?>" data-login_uri="<?=googlehub_esc(googlehub_absolute_url('google-auth-callback'))?>" data-auto_prompt="false"></div>
   <div class="g_id_signin" data-type="standard" data-text="continue_with" data-size="medium"></div>
  <?php endif;?>
 </section>

 <section class="google-ga4-control-card" id="ga4-control">
  <div class="google-ga4-control-top">
   <div>
    <span class="google-kicker">Google Analytics 4</span>
    <div class="google-title-row">
     <h2><?=$ga4?googlehub_esc((string)($ga4['selected_resource_name']??'Connected')):'Not connected'?></h2>
     <span class="google-health google-health-<?=googlehub_esc((string)($health['level']??'warning'))?>"><?=googlehub_esc((string)($health['label']??'Unknown'))?></span>
    </div>
    <?php if($ga4):?><p class="google-muted">Property <?=googlehub_esc((string)($ga4['selected_resource_id']??'—'))?> · <?=googlehub_esc((string)($ga4['google_email']??''))?></p><?php endif;?>
   </div>
   <?php if($ga4):?>
    <form method="post" action="<?=googlehub_esc(url('business-google-ga4-action'))?>">
     <?=csrf_field()?>
     <input type="hidden" name="action" value="test_connection"><input type="hidden" name="ga4_days" value="<?=$days?>">
     <button class="google-secondary-button" type="submit">Test connection</button>
    </form>
   <?php endif;?>
  </div>

  <?php if(!$ga4):?>
   <div class="google-empty-connection">
    <div><h3>Connect Google Analytics</h3><p>Authorize a Google account and choose the GA4 property for this business.</p></div>
    <form method="post" action="<?=googlehub_esc(url('business-google-connect'))?>"><?=csrf_field()?><input type="hidden" name="service" value="ga4"><button class="google-primary-button" type="submit">Connect Google Analytics</button></form>
   </div>
  <?php else:?>
   <div class="google-health-grid">
    <article><span>Connection health</span><strong><?=googlehub_esc((string)($health['label']??'Unknown'))?></strong><small><?=googlehub_esc((string)($health['message']??''))?></small></article>
    <article><span>Last successful sync</span><strong><?=googlehub_esc(googlehub_ui_date((string)($ga4['last_synced_at']??'')))?></strong><small>Local data through <?=googlehub_esc((string)($health['latest_data_date']??'—'))?></small></article>
    <article><span>Property settings</span><strong><?=googlehub_esc($timezone)?></strong><small>Currency: <?=googlehub_esc($currency)?></small></article>
   </div>

   <?php if(!empty($control['live_error'])):?><div class="google-control-warning"><strong>Live snapshot could not refresh.</strong><span><?=googlehub_esc((string)$control['live_error'])?></span></div><?php endif;?>

   <div class="google-period-toolbar">
    <div><span>Performance window</span><strong><?=$days?> days</strong></div>
    <nav class="google-period-tabs">
     <?php foreach([7,30,90] as $period):?><a class="<?=$days===$period?'is-active':''?>" href="<?=googlehub_esc(url('business-google',['ga4_days'=>$period]).'#ga4-control')?>"><?=$period?>D</a><?php endforeach;?>
    </nav>
   </div>

   <?php if($current):?>
    <div class="google-performance-kpis">
     <?php foreach($metricCards as $metric=>$label):
      $value=isset($currentMetrics[$metric])?(float)$currentMetrics[$metric]:null;
      $change=array_key_exists('change_percent',$comparison[$metric]??[])?($comparison[$metric]['change_percent']!==null?(float)$comparison[$metric]['change_percent']:null):null;
      $changeUi=googlehub_ui_change($change);
     ?>
      <article class="google-performance-kpi"><span><?=googlehub_esc($label)?></span><strong><?=googlehub_ui_metric($metric,$value,$currency)?></strong><small class="is-<?=googlehub_esc($changeUi['class'])?>"><?=googlehub_esc($changeUi['text'])?></small></article>
     <?php endforeach;?>
    </div>
   <?php endif;?>

   <div class="google-ga4-mid-grid">
    <article class="google-subpanel">
     <div class="google-subpanel-head"><div><span class="google-kicker">Trend</span><h3>Sessions & active users</h3></div><a href="<?=googlehub_esc(googlehub_ga4_dashboard_url($days,'trend',$timezone))?>">Full report</a></div>
     <div class="google-mini-chart"><canvas id="google-ga4-trend-chart" aria-label="GA4 trend"></canvas></div>
    </article>
    <article class="google-subpanel">
     <div class="google-subpanel-head"><div><span class="google-kicker">AI Insights</span><h3>What changed</h3></div><span class="google-small-chip">Rule-based</span></div>
     <div class="google-insight-list">
      <?php foreach($insights as $insight):?><div class="google-insight google-insight-<?=googlehub_esc((string)($insight['tone']??'neutral'))?>"><strong><?=googlehub_esc((string)($insight['title']??''))?></strong><span><?=googlehub_esc((string)($insight['body']??''))?></span></div><?php endforeach;?>
     </div>
    </article>
   </div>

   <div class="google-quick-actions">
    <a class="google-primary-button" href="<?=googlehub_esc(googlehub_ga4_dashboard_url($days,'trend',$timezone))?>">Open API Dashboard</a>
    <a class="google-secondary-button" href="<?=googlehub_esc(googlehub_ga4_dashboard_url($days,'pdf-compare',$timezone))?>">Compare with PDF</a>
    <a class="google-secondary-button" href="<?=googlehub_esc(url('business-ai-extraction',['source'=>'ga4']))?>">Upload GA4 PDF</a>
    <form method="post" action="<?=googlehub_esc(url('business-google-sync'))?>"><?=csrf_field()?><input type="hidden" name="service" value="ga4"><input type="hidden" name="ga4_days" value="<?=$days?>"><button class="google-secondary-button" type="submit">Sync now</button></form>
   </div>

   <div class="google-ga4-lower-grid">
    <article class="google-subpanel">
     <div class="google-subpanel-head"><div><span class="google-kicker">Business goals</span><h3>Key events</h3><p>Choose the conversions that matter most to this business.</p></div></div>
     <?php if(!empty($control['key_events_error'])):?><div class="google-control-warning"><?=googlehub_esc((string)$control['key_events_error'])?></div><?php endif;?>
     <?php if($keyEvents):?>
      <input class="google-event-search" type="search" placeholder="Search key events..." data-google-event-search>
      <form method="post" action="<?=googlehub_esc(url('business-google-ga4-action'))?>">
       <?=csrf_field()?><input type="hidden" name="action" value="save_key_events"><input type="hidden" name="ga4_days" value="<?=$days?>">
       <div class="google-key-event-list" data-google-event-list>
        <?php foreach($keyEvents as $event):$eventName=(string)($event['event_name']??'');?>
         <label class="google-key-event" data-google-event-row data-event-name="<?=googlehub_esc(strtolower($eventName))?>">
          <input type="checkbox" name="key_events[]" value="<?=googlehub_esc($eventName)?>" <?=in_array($eventName,$selectedKeyEvents,true)?'checked':''?>>
          <span><strong><?=googlehub_esc($eventName)?></strong><small><?=number_format((float)($event['key_events']??0),0)?> key events · <?=number_format((float)($event['event_count']??0),0)?> total events</small></span>
         </label>
        <?php endforeach;?>
       </div>
       <button class="google-primary-button" type="submit">Save key events</button>
      </form>
     <?php else:?><div class="google-empty-small">No key events with activity were returned for this period.</div><?php endif;?>
    </article>

    <article class="google-subpanel">
     <div class="google-subpanel-head"><div><span class="google-kicker">PDF validation</span><h3>Latest API ↔ PDF check</h3></div></div>
     <?php if($latestComparison):?>
      <div class="google-pdf-alignment"><strong><?=number_format((float)($latestComparison['match_percent']??0),1)?>%</strong><span>alignment</span></div>
      <div class="google-pdf-stats"><span><?=(int)($latestComparison['matched_metrics']??0)?> matched</span><span><?=(int)($latestComparison['review_metrics']??0)?> review</span></div>
      <p class="google-muted"><?=googlehub_esc((string)($latestComparison['period_start']??''))?> → <?=googlehub_esc((string)($latestComparison['period_end']??''))?></p>
     <?php elseif($latestPdf):?>
      <div class="google-pdf-ready"><strong>Saved PDF ready to compare</strong><span><?=googlehub_esc((string)($latestPdf['period_start']??''))?> → <?=googlehub_esc((string)($latestPdf['period_end']??''))?></span></div>
     <?php else:?><div class="google-empty-small">No GA4 PDF upload has been saved for this business.</div><?php endif;?>
     <a class="google-secondary-button google-block-button" href="<?=googlehub_esc(googlehub_ga4_dashboard_url($days,'pdf-compare',$timezone))?>">Open PDF comparison</a>
    </article>
   </div>

   <article class="google-subpanel">
    <div class="google-subpanel-head"><div><span class="google-kicker">Saved reporting</span><h3>Reporting views</h3><p>Open a focused report or save your own starting view.</p></div></div>
    <div class="google-report-view-grid">
     <?php foreach($builtIns as [$name,$description,$section]):?><a class="google-report-view" href="<?=googlehub_esc(googlehub_ga4_dashboard_url($days,$section,$timezone))?>"><strong><?=googlehub_esc($name)?></strong><span><?=googlehub_esc($description)?></span></a><?php endforeach;?>
    </div>
    <?php if($savedViews):?><div class="google-custom-view-list">
     <?php foreach($savedViews as $savedView):?><div class="google-custom-view">
      <a href="<?=googlehub_esc(googlehub_ga4_dashboard_url((int)($savedView['period_days']??30),(string)($savedView['section_anchor']??'trend'),$timezone))?>"><strong><?=googlehub_esc((string)($savedView['view_name']??''))?></strong><span><?=(int)($savedView['period_days']??30)?>D · <?=googlehub_esc(ucfirst(str_replace('-',' ',(string)($savedView['section_anchor']??'trend'))))?></span></a>
      <form method="post" action="<?=googlehub_esc(url('business-google-ga4-action'))?>" onsubmit="return confirm('Remove this saved GA4 view?');"><?=csrf_field()?><input type="hidden" name="action" value="delete_view"><input type="hidden" name="view_id" value="<?=(int)($savedView['id']??0)?>"><input type="hidden" name="ga4_days" value="<?=$days?>"><button type="submit" aria-label="Delete saved view">×</button></form>
     </div><?php endforeach;?>
    </div><?php endif;?>
    <details class="google-create-view"><summary>+ Save a custom view</summary>
     <form method="post" action="<?=googlehub_esc(url('business-google-ga4-action'))?>"><?=csrf_field()?><input type="hidden" name="action" value="save_view"><input type="hidden" name="ga4_days" value="<?=$days?>">
      <label><span>View name</span><input type="text" name="view_name" maxlength="80" placeholder="Monthly Marketing Review" required></label>
      <label><span>Period</span><select name="period_days"><option value="7">7 days</option><option value="30" selected>30 days</option><option value="90">90 days</option></select></label>
      <label><span>Open at</span><select name="section_anchor"><option value="trend">Trend</option><option value="acquisition">Acquisition</option><option value="content">Content</option><option value="audience">Audience</option><option value="events">Events</option><option value="ecommerce">E-commerce</option><option value="pdf-compare">PDF Compare</option><option value="explorer">Data Explorer</option></select></label>
      <button class="google-primary-button" type="submit">Save view</button>
     </form>
    </details>
   </article>

   <details class="google-sync-history"><summary>Sync history <span><?=count($syncHistory)?> recent item(s)</span></summary>
    <?php if($syncHistory):?><div class="google-sync-history-list"><?php foreach($syncHistory as $history):?><div class="google-sync-history-row"><span class="google-history-dot google-history-<?=googlehub_esc((string)($history['status']??'error'))?>"></span><div><strong><?=googlehub_esc(ucwords(str_replace('_',' ',(string)($history['action_name']??''))))?></strong><small><?=googlehub_esc(googlehub_ui_date((string)($history['created_at']??'')))?><?php if((int)($history['rows_synced']??0)>0):?> · <?=number_format((int)$history['rows_synced'])?> rows<?php endif;?></small><?php if(!empty($history['message'])):?><span><?=googlehub_esc((string)$history['message'])?></span><?php endif;?></div></div><?php endforeach;?></div>
    <?php else:?><div class="google-empty-small">New sync and connection-test activity will appear here.</div><?php endif;?>
   </details>

   <details class="google-connection-settings"><summary>Connection settings</summary><div class="google-actions">
    <a class="google-secondary-button" href="<?=googlehub_esc(url('business-google-select-ga4'))?>">Change property</a>
    <form method="post" action="<?=googlehub_esc(url('business-google-connect'))?>"><?=csrf_field()?><input type="hidden" name="service" value="ga4"><button class="google-secondary-button" type="submit">Use different Google account</button></form>
    <form method="post" action="<?=googlehub_esc(url('business-google-disconnect'))?>" onsubmit="return confirm('Disconnect GA4? Historical synchronized metrics will be kept.');"><?=csrf_field()?><input type="hidden" name="service" value="ga4"><button class="google-link-button google-danger-link" type="submit">Disconnect</button></form>
   </div></details>
  <?php endif;?>
 </section>

 <section class="google-secondary-service" id="gbp-control">
  <div class="google-section-heading">
   <div>
    <span class="google-kicker">Google Business Profile</span>
    <div class="google-title-row">
     <h2><?=$gbp?googlehub_esc((string)($gbp['selected_resource_name']??'Connected')):'Not connected'?></h2>
     <?php if($gbp&&$gbpUsesGa4Account):?>
      <span class="google-account-match">Same account as GA4</span>
     <?php elseif($gbp&&$ga4GoogleEmail!==''):?>
      <span class="google-account-mismatch">Different Google account</span>
     <?php endif;?>
    </div>
   </div>
   <span class="google-status <?=$gbp&&($gbp['status']??'')==='connected'?'is-good':''?>"><?=googlehub_esc((string)($gbp['status']??'disconnected'))?></span>
  </div>

  <?php if($ga4GoogleEmail!==''):?>
   <div class="google-shared-account-banner">
    <div>
     <span>GA4 Google account</span>
     <strong><?=googlehub_esc($ga4GoogleEmail)?></strong>
     <small>Google Business Profile will use this same Google identity by default.</small>
    </div>

    <?php if($gbp&&!$gbpUsesGa4Account):?>
     <form method="post" action="<?=googlehub_esc(url('business-google-connect'))?>">
      <?=csrf_field()?>
      <input type="hidden" name="service" value="gbp">
      <button class="google-primary-button" type="submit">Reconnect GBP with GA4 account</button>
     </form>
    <?php endif;?>
   </div>
  <?php endif;?>

  <?php if($gbp):?>
   <p class="google-muted">
    GBP Google account:
    <strong><?=googlehub_esc((string)($gbp['google_email']??''))?></strong>
    · Last sync:
    <?=googlehub_esc(googlehub_ui_date((string)($gbp['last_synced_at']??'')))?>
   </p>

   <?php if($ga4GoogleEmail!==''&&!$gbpUsesGa4Account):?>
    <div class="google-account-warning">
     <strong>GBP is connected with a different Google account.</strong>
     <span>
      GA4 uses <?=googlehub_esc($ga4GoogleEmail)?> while GBP uses
      <?=googlehub_esc($gbpGoogleEmail!==''?$gbpGoogleEmail:'another account')?>.
      Reconnect GBP to keep both services under one Google identity.
     </span>
    </div>
   <?php endif;?>

   <div class="google-mini-kpis">
    <div><span>Search views · 30d</span><strong><?=number_format((int)($gbpSummary['search_impressions']??0))?></strong></div>
    <div><span>Maps views</span><strong><?=number_format((int)($gbpSummary['maps_impressions']??0))?></strong></div>
    <div><span>Website clicks</span><strong><?=number_format((int)($gbpSummary['website_clicks']??0))?></strong></div>
    <div><span>Calls</span><strong><?=number_format((int)($gbpSummary['call_clicks']??0))?></strong></div>
   </div>

   <div class="google-actions">
    <form method="post" action="<?=googlehub_esc(url('business-google-sync'))?>">
     <?=csrf_field()?>
     <input type="hidden" name="service" value="gbp">
     <button class="google-primary-button" type="submit">Sync GBP</button>
    </form>

    <?php if($ga4GoogleEmail!==''&&!$gbpUsesGa4Account):?>
     <form method="post" action="<?=googlehub_esc(url('business-google-connect'))?>">
      <?=csrf_field()?>
      <input type="hidden" name="service" value="gbp">
      <button class="google-secondary-button" type="submit">Use GA4 Google account</button>
     </form>
    <?php endif;?>

    <details class="google-inline-details">
     <summary>More</summary>
     <div class="google-inline-details-menu">
      <form method="post" action="<?=googlehub_esc(url('business-google-connect'))?>">
       <?=csrf_field()?>
       <input type="hidden" name="service" value="gbp">
       <input type="hidden" name="allow_different_google_account" value="1">
       <button class="google-link-button" type="submit">Use a different Google account</button>
      </form>

      <form method="post" action="<?=googlehub_esc(url('business-google-disconnect'))?>" onsubmit="return confirm('Disconnect Google Business Profile? Historical synchronized metrics will be kept.');">
       <?=csrf_field()?>
       <input type="hidden" name="service" value="gbp">
       <button class="google-link-button google-danger-link" type="submit">Disconnect</button>
      </form>
     </div>
    </details>
   </div>

  <?php else:?>

   <?php if($ga4GoogleEmail!==''):?>
    <div class="google-gbp-connect-state">
     <div>
      <strong>Connect GBP using the GA4 account</strong>
      <span><?=googlehub_esc($ga4GoogleEmail)?></span>
      <small>
       Google will ask this same account to approve Business Profile access.
       Aesthetic Intel will reject the callback if a different Google account is returned.
      </small>
     </div>

     <form method="post" action="<?=googlehub_esc(url('business-google-connect'))?>">
      <?=csrf_field()?>
      <input type="hidden" name="service" value="gbp">
      <button class="google-primary-button" type="submit">
       Connect GBP with <?=googlehub_esc($ga4GoogleEmail)?>
      </button>
     </form>
    </div>

    <details class="google-inline-details google-gbp-other-account">
     <summary>Need another Google account?</summary>
     <form method="post" action="<?=googlehub_esc(url('business-google-connect'))?>">
      <?=csrf_field()?>
      <input type="hidden" name="service" value="gbp">
      <input type="hidden" name="allow_different_google_account" value="1">
      <button class="google-secondary-button" type="submit">Choose another Google account</button>
     </form>
    </details>

   <?php else:?>
    <p>Connect GA4 first if you want GBP to automatically use the exact same Google account.</p>
    <form method="post" action="<?=googlehub_esc(url('business-google-connect'))?>">
     <?=csrf_field()?>
     <input type="hidden" name="service" value="gbp">
     <button class="google-primary-button" type="submit">Connect Business Profile</button>
    </form>
   <?php endif;?>

  <?php endif;?>
 </section>

 <section class="google-data-note"><strong>How reporting works</strong><p>Live GA4 calls power the current/previous snapshot. Manual and scheduled syncs keep normalized history available for trends, reports, PDF validation and AI workflows.</p></section>
</div>

<script id="google-ga4-trend-data" type="application/json"><?=json_encode($trendData,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?></script>
<script src="<?=googlehub_esc(asset('js/google-connections-ga4.js'))?>?v=2.0.0" defer></script>
