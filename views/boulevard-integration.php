<?php
$nameCounts=[];
foreach($availableReports as $report){$key=strtolower(trim((string)($report['name']??'')));if($key!=='')$nameCounts[$key]=($nameCounts[$key]??0)+1;}
$statusLabels=['strong_match'=>'Strong match','likely_match'=>'Likely match','needs_review'=>'Needs review'];
$typeDisplayNumbers=[];$verifiedCount=0;$mappedPendingCount=0;$unmappedCount=0;$manualVerificationTypes=[];
foreach($types as $index=>$type){
 $tid=(int)$type['id'];$typeDisplayNumbers[$tid]=$index+1;$mapping=$mappings[$tid]??null;$verification=$verifications[$tid]??null;
 $isMapped=$mapping&&!empty($mapping['enabled'])&&!empty($mapping['boulevard_report_id']);
 $isVerified=boulevard_mapping_is_header_verified($mapping,$verification);
 if($isVerified)$verifiedCount++;elseif($isMapped)$mappedPendingCount++;else$unmappedCount++;
 if(!$isVerified)$manualVerificationTypes[]=$type;
}
?>
<section class="page-head">
 <div><p class="eyebrow"><?=e($business['name'])?> · Super Admin</p><h1>Boulevard API Integration <span class="beta-heading-badge">BETA</span></h1><p>Testing environment only. Do not rely on this integration for official reports until live exports are fully verified.</p></div>
 <div class="button-row"><a class="btn btn-secondary" href="<?=url('business-upload')?>">Manual CSV Uploads</a><a class="btn btn-secondary" href="<?=url('admin-boulevard-report-types')?>">Manage Report Types</a></div>
</section>

<!-- ======================================================
     GA4 TEST CONSOLE
     ====================================================== -->
<section class="content-card" style="margin-bottom:18px">
 <div class="card-head">
  <div>
   <p class="eyebrow">API Integration · Test Environment</p>
   <h2>Google Analytics 4</h2>
   <p>Development connection for testing the Brospro GA4 Data API without affecting Ruma or Remedy data.</p>
  </div>
  <span class="status-pill status-warning">TEST</span>
 </div>
 <div class="button-row">
  <a href="<?=e(url('ga4-test-console'))?>" class="btn btn-primary">Open GA4 Test Console</a>
 </div>
</section>

<!-- ======================================================
     EXISTING BOULEVARD CONTENT CONTINUES
     ====================================================== -->
<div class="metric-preview-grid" style="margin-bottom:18px">
 <div class="metric-preview"><small>Connection</small><strong><?=e(ucwords(str_replace('_',' ',(string)($connection['status']??'not_connected'))))?></strong></div>
 <div class="metric-preview"><small>Connected business</small><strong><?=e($connection['connected_business_name']?:'Not verified')?></strong></div>
 <div class="metric-preview"><small>Available reports fetched</small><strong><?=count($availableReports)?></strong></div>
 <div class="metric-preview"><small>Mapped report types</small><strong><?=count(array_filter($mappings,fn($m)=>!empty($m['enabled'])))?> / <?=count($types)?></strong></div>
</div>
<div class="grid-two" style="align-items:start">
 <section class="content-card"><h2>1. API credentials</h2><p>Use a dedicated Boulevard API application for Aesthetic Intel whenever possible. Saved credentials are encrypted.</p>
  <form method="post" autocomplete="off"><?=csrf_field()?>
   <label class="field"><span>API application key</span><input type="password" name="api_key" autocomplete="new-password" placeholder="Boulevard API key"><small>Leave blank to keep the saved key. Current: <?=e($maskedApiKey)?></small></label>
   <label class="field"><span>API application secret</span><input type="password" name="api_secret" autocomplete="new-password" placeholder="Boulevard API secret"><small>Leave blank to keep the saved secret. Current: <?=e($maskedApiSecret)?></small></label>
   <label class="field"><span>Boulevard Business ID</span><input name="boulevard_business_id" value="<?=e($connection['boulevard_business_id']??'')?>" placeholder="UUID or urn:blvd:Business:..."><small>The UUID used to scope the signed API token.</small></label>
   <label class="check-row"><input type="checkbox" name="apply_timezone" value="1"> Update Aesthetic Intel’s business timezone from Boulevard after a successful test</label>
   <div class="button-row"><button class="btn btn-secondary" name="action" value="save_connection" type="submit">Save Connection</button><button class="btn btn-primary" name="action" value="test_connection" type="submit">Save &amp; Test Connection</button><?php if(!empty($connection['api_key_encrypted'])):?><button class="btn btn-danger" name="action" value="disconnect" type="submit" onclick="return confirm('Remove the saved Boulevard API credentials for this business?')">Disconnect</button><?php endif;?></div>
  </form>
  <?php if(!empty($connection['last_tested_at'])):?><hr><p><strong>Last test:</strong> <?=e($connection['last_tested_at'])?></p><div class="alert <?=($connection['status']??'')==='connected'?'alert-info':'alert-warning'?>"><?=e($connection['last_test_message']??'')?></div><?php endif;?>
 </section>
 <section class="content-card"><h2>2. Fetch Boulevard reports</h2><p>This retrieves Boulevard’s saved report catalogue only. It does not download weekly or monthly report data.</p>
  <form method="post"><?=csrf_field()?><button class="btn btn-primary" name="action" value="fetch_reports" type="submit" <?=($connection['status']??'')==='not_connected'?'disabled':''?>>Fetch Available Reports</button></form>
  <?php if(!empty($connection['last_reports_fetched_at'])):?><p style="margin-top:14px"><strong>Last fetched:</strong> <?=e($connection['last_reports_fetched_at'])?></p><?php endif;?>
  <?php if(!empty($fetchWarning)):?><div class="alert alert-warning"><?=e($fetchWarning)?></div><?php endif;?>
  <div class="alert alert-info"><strong>Safe fallback:</strong> For low-confidence matches, paste the Boulevard report URL and upload one sample CSV in the Manual Verification section below. Aesthetic Intel will check the real headers before mapping.</div>
 </section>
</div>
<section class="content-card boulevard-ai-card" style="margin-top:18px">
 <div class="card-head">
  <div><p class="eyebrow">AI Mapping Assistant</p><h2>3. Shortlist the safest report matches</h2><p>OpenAI reviews each Aesthetic Intel report definition, expected CSV headers, Boulevard names, template IDs, and available filter metadata. It recommends a match and confidence score without changing existing mappings.</p></div>
  <form method="post" data-boulevard-ai-form><?=csrf_field()?><input type="hidden" name="action" value="ai_match_reports"><button class="btn btn-primary" type="submit" <?=count($availableReports)?'':'disabled'?>>Analyze <?=count($availableReports)?> Reports with AI</button></form>
 </div>
 <div class="alert alert-warning"><strong>Important:</strong> AI confidence is only a recommendation. A report becomes <strong>Header Verified</strong> only after its sample CSV reaches 100% compatibility and the exact saved Boulevard report is approved.</div>
</section>
<section class="content-card boulevard-mapping-review-card" style="margin-top:18px">
 <div class="card-head"><div><h2>4. Review and map reports</h2><p>This is the master status list. A manually approved 100% header match overrides and hides any older AI confidence score.</p></div></div>
 <div class="mapping-status-summary">
  <span class="mapping-status-chip verified"><strong><?=e($verifiedCount)?></strong> Header verified</span>
  <span class="mapping-status-chip pending"><strong><?=e($mappedPendingCount)?></strong> Mapped, verification pending</span>
  <span class="mapping-status-chip unmapped"><strong><?=e($unmappedCount)?></strong> Not mapped</span>
 </div>
 <form method="post" class="boulevard-mapping-form" data-boulevard-mapping-form><?=csrf_field()?><input type="hidden" name="action" value="save_mappings">
  <div class="boulevard-mapping-list">
  <?php foreach($types as $typeIndex=>$type):
   $tid=(int)$type['id'];$mapping=$mappings[$tid]??null;$suggestion=$suggestions[$tid]??null;$verification=$verifications[$tid]??null;$headers=boulevard_expected_headers($type);
   $currentReportId=(string)($mapping['boulevard_report_id']??'');$currentReportName=(string)($mapping['boulevard_report_name']??'');
   $isMapped=$mapping&&!empty($mapping['enabled'])&&$currentReportId!=='';$isHeaderVerified=boulevard_mapping_is_header_verified($mapping,$verification);
  ?><article class="boulevard-mapping-card <?=$isHeaderVerified?'mapping-header-verified':($isMapped?'mapping-pending-verification':'')?>" id="mapping-report-<?=e($tid)?>" data-mapping-row="<?=e($tid)?>">
   <input type="hidden" name="approved_report_id[<?=e($tid)?>]" value="<?=e($currentReportId)?>" data-approved-report-id>
   <input type="hidden" name="approved_report_name[<?=e($tid)?>]" value="<?=e($currentReportName)?>" data-approved-report-name>
   <header class="boulevard-mapping-card-head">
    <div class="mapping-report-identity">
     <span class="mapping-step-number"><?=e((string)($typeIndex+1))?></span>
     <div><h3><?=e($type['name'])?></h3><p><?=e($type['code'])?> · <?=e($type['parser_key'])?></p></div>
    </div>
    <div class="mapping-header-actions">
     <?php if($isHeaderVerified):?><span class="status-pill status-success">Mapped &amp; Header Verified</span><?php elseif($isMapped):?><span class="status-pill status-warning">Mapped · Verify CSV</span><?php else:?><span class="status-pill">Not mapped</span><?php endif;?>
     <label class="mapping-enable-toggle"><input type="checkbox" name="mapping_enabled[<?=e($tid)?>]" value="1" <?=!$mapping||!empty($mapping['enabled'])?'checked':''?>><span>Include this report</span></label>
    </div>
   </header>
   <?php if($headers):?><details class="expected-headers mapping-headers"><summary>View expected CSV headers</summary><small><?=e(implode(', ',$headers))?></small></details><?php endif;?>
   <div class="boulevard-mapping-card-grid">
    <section class="mapping-ai-panel" aria-label="Mapping status for <?=e($type['name'])?>">
     <span class="mapping-panel-label"><?=$isHeaderVerified?'Verified mapping':'AI recommendation'?></span>
     <?php if($isHeaderVerified):?>
      <div class="mapping-verified-state">
       <div class="mapping-verified-icon">✓</div>
       <div><strong>100% CSV header compatibility</strong><p><?=e($currentReportName)?> is the approved saved Boulevard report.</p><small>Verified <?=e((string)($verification['verified_at']??''))?>. The older AI confidence is no longer used for this report.</small></div>
      </div>
     <?php elseif($suggestion):$confidence=(int)$suggestion['confidence'];$status=(string)$suggestion['status'];?>
      <div class="ai-suggestion <?=e($status)?>">
       <div class="ai-suggestion-top"><span class="confidence-badge"><?=e($confidence)?>%</span><strong><?=e($statusLabels[$status]??'Needs review')?></strong></div>
       <p class="ai-suggestion-name"><?=e($suggestion['suggested_report_name']?:'No safe match found')?></p>
       <small><?=e($suggestion['reason']??'')?></small>
       <?php if(!empty($suggestion['suggested_report_id'])):?><button class="btn btn-small btn-secondary" type="submit" name="apply_suggestion" value="<?=e($tid)?>" formnovalidate data-use-boulevard-suggestion="<?=e($suggestion['suggested_report_id'])?>" data-suggestion-name="<?=e($suggestion['suggested_report_name']??'Boulevard report')?>">Use this suggestion</button><small class="suggestion-save-note">This maps the suggestion immediately, but sample CSV verification is still recommended.</small><?php endif;?>
       <small class="analysis-time">Analyzed <?=e($suggestion['analyzed_at'])?></small>
      </div>
     <?php else:?><div class="mapping-empty-state"><strong><?=$isMapped?'Mapping saved; header verification pending':'No AI suggestion yet'?></strong><span><?=$isMapped?'Use Section 5 to verify the mapped report with its URL and sample CSV.':'Run AI analysis above or use the safe manual verification workflow.'?></span></div><?php endif;?>
    </section>
    <section class="mapping-selection-panel" aria-label="Approved mapping for <?=e($type['name'])?>">
     <span class="mapping-panel-label">Approved Boulevard report</span>
     <div class="selected-report-summary <?=$currentReportId!==''?'has-selection':''?>" data-selected-report-summary>
      <span>Currently selected</span>
      <strong data-selected-report-name><?=e($currentReportName!==''?$currentReportName:'Not selected yet')?></strong>
      <small data-selected-report-id><?=e($currentReportId!==''?$currentReportId:'Apply the AI suggestion, search the catalogue, or enter a manual ID.')?></small>
     </div>
     <?php if($isHeaderVerified):?><div class="alert alert-info mapping-change-warning">Changing this selection will remove the Header Verified status until the new report is checked with a sample CSV.</div><?php endif;?>
     <div class="mapping-picker-grid">
      <label class="field mapping-search-field"><span>Search fetched reports</span><input class="boulevard-report-search" type="search" placeholder="Search report name or ID" data-report-search aria-label="Search Boulevard reports for <?=e($type['name'])?>"></label>
      <label class="field mapping-select-field"><span>Choose Boulevard report</span><select name="selected_report[<?=e($tid)?>]" data-report-select>
       <option value="">Choose a fetched report</option>
       <?php $found=false;foreach($availableReports as $report):$rid=(string)($report['id']??'');$rname=(string)($report['name']??$rid);$selected=$mapping&&$rid===(string)$mapping['boulevard_report_id'];if($selected)$found=true;$duplicate=($nameCounts[strtolower(trim($rname))]??0)>1;$shortId=strlen($rid)>20?'…'.substr($rid,-16):$rid;?>
        <option value="<?=e($rid)?>" data-report-name="<?=e($rname)?>" data-search="<?=e(strtolower($rname.' '.$rid.' '.($report['templateId']??'')))?>" <?=$selected?'selected':''?>><?=e($rname)?><?=($duplicate?' · duplicate':'')?> · <?=e($shortId)?></option>
       <?php endforeach;?>
       <?php if($mapping&&!$found):?><option value="<?=e($mapping['boulevard_report_id'])?>" data-report-name="<?=e($mapping['boulevard_report_name']?:$mapping['boulevard_report_id'])?>" selected><?=e($mapping['boulevard_report_name']?:$mapping['boulevard_report_id'])?> · saved mapping</option><?php endif;?>
      </select><small data-search-result-count><?=count($availableReports)?> available reports</small></label>
     </div>
     <details class="manual-mapping-details">
      <summary>Advanced / Manual Report ID</summary>
      <label class="field"><span>Manual Boulevard Report ID</span><input name="manual_report_id[<?=e($tid)?>]" data-manual-report-id placeholder="urn:blvd:Report:..." value=""><small>Use this only when the report is not available in the fetched catalogue. A manual ID overrides the dropdown selection when saved.</small></label>
     </details>
    </section>
   </div>
  </article><?php endforeach;?>
  </div>
  <div class="mapping-save-bar"><div><strong>Ready to save manual changes?</strong><span>Changing an already verified report will correctly return it to “verification pending.”</span></div><button class="btn btn-primary" type="submit">Save Approved Mappings</button></div>
 </form>
</section>
<section class="content-card boulevard-manual-verification" style="margin-top:18px">
 <div class="card-head"><div><p class="eyebrow">Safe Manual Mapping</p><h2>5. Analyze and map unresolved reports</h2><p>Paste the Boulevard URL and upload its sample CSV. Aesthetic Intel now maps the report automatically whenever one safe saved-report match exists. You will only see a short choice when Boulevard contains genuine duplicates.</p></div></div>
 <div class="alert alert-info"><strong>Simplified workflow:</strong> URL + sample CSV → header verification → automatic mapping. No Report ID or large dropdown is required. If two saved Boulevard reports are truly indistinguishable, Aesthetic Intel will show only those few choices as buttons.</div>
 <?php if(!$manualVerificationTypes):?>
  <div class="mapping-all-verified"><span>✓</span><div><h3>All Boulevard reports are mapped and header verified</h3><p>Nothing requires manual verification right now.</p></div></div>
 <?php else:?>
 <div class="manual-verification-list">
 <?php foreach($manualVerificationTypes as $type):
  $tid=(int)$type['id'];$mapping=$mappings[$tid]??null;$verification=$verifications[$tid]??null;$expectedHeaders=boulevard_expected_headers($type);
  $verificationCandidates=$verification['candidate_reports']??[];$verificationStatus=(string)($verification['status']??'');$score=(int)($verification['compatibility_score']??0);
  $isMapped=$mapping&&!empty($mapping['enabled'])&&!empty($mapping['boulevard_report_id']);
 ?>
  <article class="manual-verification-card" id="manual-verification-<?=e($tid)?>">
   <header><div class="mapping-report-identity"><span class="mapping-step-number"><?=e((string)($typeDisplayNumbers[$tid]??$tid))?></span><div><h3><?=e($type['name'])?></h3><p><?=e($isMapped?'Mapped, but header verification is still pending':'Not mapped yet')?></p></div></div><?php if($verificationStatus==='verified'):?><span class="status-pill status-warning">Headers matched · approval pending</span><?php elseif($isMapped):?><span class="status-pill status-warning">Mapped · verify CSV</span><?php else:?><span class="status-pill">Needs mapping</span><?php endif;?></header>
   <form method="post" enctype="multipart/form-data" class="manual-verification-form">
    <?=csrf_field()?><input type="hidden" name="action" value="verify_mapping_sample"><input type="hidden" name="report_type_id" value="<?=e($tid)?>">
    <div class="manual-verification-fields">
     <label class="field"><span>Boulevard report URL</span><input type="text" inputmode="url" name="report_url" required placeholder="https://dashboard.boulevard.io/report/classic/..." value="<?=e($verification['report_url']??'')?>"><small>Copy the complete URL while the exact report is open in Boulevard.</small></label>
     <label class="field"><span>Sample CSV from that report</span><input type="file" name="sample_csv" accept=".csv,text/csv" required><small>Export this exact report once. The file is deleted automatically after header analysis.</small></label>
    </div>
    <div class="button-row"><button class="btn btn-secondary" type="submit">Analyze URL &amp; CSV</button></div>
   </form>
   <?php if($verification):?>
    <div class="verification-result <?=e($verificationStatus)?>">
     <div class="verification-score"><span>CSV compatibility</span><strong><?=e($score)?>%</strong><small><?=e($verificationStatus==='verified'?'Headers verified':ucfirst($verificationStatus))?></small></div>
     <div class="verification-details">
      <p><strong>Template found:</strong> <?=e($verification['template_slug']?:'Direct Report ID')?></p>
      <p><strong>Sample:</strong> <?=e($verification['sample_filename']?:'CSV analyzed')?> · <?=e($verification['verified_at']?:'')?></p>
      <?php if(!empty($verification['matched_headers'])):?><p class="verification-good"><strong>Matched:</strong> <?=e(implode(', ',$verification['matched_headers']))?></p><?php endif;?>
      <?php if(!empty($verification['missing_headers'])):?><p class="verification-bad"><strong>Missing:</strong> <?=e(implode(', ',$verification['missing_headers']))?></p><?php endif;?>
     </div>
    </div>
    <?php if($verificationStatus==='verified'&&$verificationCandidates):
      $visibleCandidates=array_slice($verificationCandidates,0,3);$extraCandidates=array_slice($verificationCandidates,3,7);
     ?>
     <div class="verified-candidate-form">
      <div class="alert alert-warning"><strong>Rare duplicate found:</strong> The CSV headers are fully verified, but Boulevard has more than one saved report that could use this template. Pick the report name or filter that matches the page you opened. There is no Report ID typing and no 270-item dropdown.</div>
      <div class="candidate-choice-list">
       <?php foreach($visibleCandidates as $candidateIndex=>$candidate):$filters=$candidate['filters']??[];?>
       <form method="post" class="candidate-choice-card">
        <?=csrf_field()?><input type="hidden" name="action" value="approve_verified_mapping"><input type="hidden" name="report_type_id" value="<?=e($tid)?>"><input type="hidden" name="verified_report_id" value="<?=e($candidate['id'])?>">
        <div><span class="candidate-choice-label"><?=e($candidateIndex===0?'Best available match':'Alternative')?></span><h4><?=e($candidate['name'])?></h4><p><?=e($candidate['reason']?:'Saved Boulevard report compatible with this report type.')?></p><?php if($filters):?><small><strong>Filters:</strong> <?=e(json_encode($filters,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE))?></small><?php endif;?></div>
        <?php if((string)$type['code']==='retail_product_sales'):?><label class="check-row"><input type="checkbox" name="confirm_retail_filter" value="1"> This saved Product Sales report is filtered to Retail.</label><?php endif;?>
        <button class="btn btn-primary" type="submit">Use This Saved Report</button>
       </form>
       <?php endforeach;?>
      </div>
      <?php if($extraCandidates):?><details class="other-candidates"><summary>Show other possible saved reports</summary><div class="candidate-choice-list"><?php foreach($extraCandidates as $candidate):?><form method="post" class="candidate-choice-card"><?=csrf_field()?><input type="hidden" name="action" value="approve_verified_mapping"><input type="hidden" name="report_type_id" value="<?=e($tid)?>"><input type="hidden" name="verified_report_id" value="<?=e($candidate['id'])?>"><div><span class="candidate-choice-label">Other possibility</span><h4><?=e($candidate['name'])?></h4><p><?=e($candidate['reason']?:'Another saved Boulevard report using a compatible template.')?></p></div><?php if((string)$type['code']==='retail_product_sales'):?><label class="check-row"><input type="checkbox" name="confirm_retail_filter" value="1"> This saved Product Sales report is filtered to Retail.</label><?php endif;?><button class="btn btn-secondary" type="submit">Use This Saved Report</button></form><?php endforeach;?></div></details><?php endif;?>
     </div>
    <?php elseif($verificationStatus!=='verified'):?><div class="alert alert-warning">This CSV is not a complete header match. Export the correct report or review the expected headers before mapping it.</div>
    <?php else:?><div class="alert alert-warning">The headers match, but Boulevard did not return a saved report that can be identified safely from this URL. Fetch Available Reports again. If it still appears, use the Search Fetched Reports control in Section 4.</div><?php endif;?>
   <?php endif;?>
  </article>
 <?php endforeach;?>
 </div>
 <?php endif;?>
</section>

<section class="content-card" style="margin-top:18px" id="business-user-access">
 <div class="card-head"><div><p class="eyebrow">Super Admin Control</p><h2>6. Business-user Run Report access</h2><p>All API credentials, mapping, diagnostics, retries, webhook settings, and fallbacks remain visible only to Super Admins and developers.</p></div><span class="sync-status-badge status-<?=!empty($businessUserAccess['enabled'])?'completed':'queued'?>"><?=!empty($businessUserAccess['enabled'])?'Enabled':'Hidden from users'?></span></div>
 <?php if(!$businessUserReadiness['ready']):?><div class="alert alert-warning"><strong>Not ready to enable:</strong><ul><?php foreach($businessUserReadiness['issues'] as $issue):?><li><?=e($issue)?></li><?php endforeach;?></ul></div><?php else:?><div class="alert alert-info"><strong>Ready:</strong> <?=e($businessUserReadiness['mapped_count'])?> verified mappings and an active background worker are available.</div><?php endif;?>
 <div class="grid-two">
  <div class="integration-setup-box"><h3>What the business user sees</h3><p>A single <strong>Run Weekly Report</strong> button, a simple progress screen, and the finished report. No keys, report IDs, mappings, diagnostics, retries, or technical errors are exposed.</p></div>
  <form method="post" class="integration-setup-box"><?=csrf_field()?><input type="hidden" name="action" value="set_business_user_access"><input type="hidden" name="enabled" value="<?=!empty($businessUserAccess['enabled'])?'0':'1'?>"><h3><?=!empty($businessUserAccess['enabled'])?'Disable user access':'Approve for business users'?></h3><p><?=!empty($businessUserAccess['enabled'])?'The Run Weekly Report button will be removed from business-user accounts.':'After approval, business users can launch only the fixed weekly report. Technical controls stay with Super Admins.'?></p><button class="btn <?=!empty($businessUserAccess['enabled'])?'btn-danger':'btn-primary'?>" type="submit" <?=empty($businessUserAccess['enabled'])&&!$businessUserReadiness['ready']?'disabled':''?> onclick="return confirm('<?=!empty($businessUserAccess['enabled'])?'Disable':'Enable'?> business-user Boulevard report access?')"><?=!empty($businessUserAccess['enabled'])?'Disable Business-User Access':'Enable Run Weekly Report'?></button></form>
 </div>
</section>
<section class="content-card" style="margin-top:18px" id="boulevard-diagnostic">
 <div class="card-head"><div><p class="eyebrow">Developer Diagnostic</p><h2>7. Test one Boulevard report safely</h2><p>Use this before another full 11-report sync. It requests only one mapped report and logs the exact sanitized mutation input and Boulevard creation response.</p></div></div>
 <?php if(!$syncMappings):?><div class="alert alert-warning">Map and verify at least one Boulevard report first.</div><?php else:?><form method="post" class="form-grid three"><?=csrf_field()?><input type="hidden" name="action" value="start_diagnostic">
  <label>Report to test<select name="diagnostic_report_type_id" required><?php foreach($syncMappings as $row):?><option value="<?=e((int)$row['report_type_id'])?>"><?=e($row['report_type_name'])?> · <?=e($row['boulevard_report_name'])?></option><?php endforeach;?></select></label>
  <label>Request variant<select name="diagnostic_variant"><option value="saved_configuration">Saved report configuration · no added filter</option><option value="detected_filter">Detected weekly date filter</option></select></label>
  <label class="diagnostic-submit-label"><span>Controlled test</span><button class="btn btn-primary" type="submit" onclick="return confirm('Start a single-report Boulevard diagnostic?')">Run Single-Report Test</button><small>This test does not generate or replace the business dashboard.</small></label>
 </form><?php endif;?>
</section>

<section class="content-card boulevard-reliability-center" style="margin-top:18px" id="boulevard-reliability">
 <div class="card-head"><div><p class="eyebrow">Reliability Center</p><h2>8. Background processing &amp; webhook</h2><p>The worker makes the sync independent of the browser. Boulevard webhooks can signal completion immediately; signed-file probing and controlled API polling remain as backups.</p></div><span class="sync-status-badge status-<?=!empty($workerHeartbeat['healthy'])?'completed':'needs_attention'?>"><?=!empty($workerHeartbeat['healthy'])?'Worker active':'Cron setup needed'?></span></div>
 <div class="grid-two">
  <div class="integration-setup-box"><h3>Hostinger cron worker</h3><p>Run every 2 minutes. Keep this URL private because it contains the worker key.</p><label class="field"><span>Worker URL</span><input readonly value="<?=e($workerUrl)?>"></label><pre class="code-snippet">curl -fsS '<?=e($workerUrl)?>' &gt;/dev/null 2&gt;&amp;1</pre><small>Last heartbeat: <?=e($workerHeartbeat['at']??'Not detected yet')?></small><div class="button-row" style="margin-top:12px"><a class="btn btn-secondary btn-small" href="<?=url('business-boulevard-sync-diagnostics')?>">Download Diagnostics</a></div></div>
  <div class="integration-setup-box"><h3>Boulevard completion webhook</h3><p>Register this HTTPS endpoint for <strong>REPORT_EXPORT_COMPLETED</strong>. Aesthetic Intel verifies Boulevard’s HMAC headers and deduplicates retries.</p><label class="field"><span>Webhook URL</span><input readonly value="<?=e($webhookUrl)?>"></label><small>Webhook is optional but recommended. The cron worker remains the fail-safe completion path.</small></div>
 </div>
 <div class="alert alert-info"><strong>How reliability works:</strong> webhooks first, direct signed-CSV checks second, controlled API polling third, and manual CSV fallback for any report that still needs attention.</div>
</section>
<section class="content-card boulevard-sync-launch" style="margin-top:18px" id="run-sync-now">
 <div class="card-head"><div><p class="eyebrow">Manual API Fetch</p><h2>9. Run Sync Now</h2><p>Run a live preflight, queue the verified mappings, process no more than two exports at once, validate every downloaded CSV, and build the same interactive dashboard used by manual uploads.</p></div></div>
 <?php if(($connection['status']??'')!=='connected'):?><div class="alert alert-warning">Test the Boulevard connection before running a sync.</div>
 <?php elseif(!$syncMappings):?><div class="alert alert-warning">No active Boulevard report mappings are available yet.</div>
 <?php else:?>
 <?php $unfiltered=array_values(array_filter($syncMappings,fn($row)=>$row['default_filter']===null&&(string)$row['code']!=='subscriptions'));?>
 <div class="alert alert-info"><strong>Accuracy safeguard:</strong> Boulevard’s one-time Report Export API uses a relative lookback. Aesthetic Intel therefore locks non-custom Period End to today in the business timezone (<?=e($business['timezone'])?>). Historical end dates are blocked so the dashboard cannot silently represent the wrong dates.</div>
 <?php if($unfiltered):?><div class="alert alert-warning"><strong><?=count($unfiltered)?> mapped report(s) have no detectable date filter.</strong> They will use the saved report’s current configuration. Review the filter choices below before syncing.</div><?php endif;?>
 <form method="post" data-period-form data-business-today="<?=e($syncToday)?>" data-boulevard-sync-launch class="boulevard-sync-form"><?=csrf_field()?><input type="hidden" name="action" value="start_sync">
  <div class="form-grid three">
   <label>Frequency<select name="frequency" data-frequency><option value="weekly">Weekly</option><option value="monthly">Monthly</option><option value="quarterly">Quarterly</option><option value="yearly">Yearly</option><option value="custom">Custom</option></select></label>
   <label>Period start<input type="date" name="period_start" data-period-start value="<?=e($syncDefaultStart)?>" required></label>
   <label>Period end<input type="date" name="period_end" data-period-end value="<?=e($syncDefaultEnd)?>" max="<?=e($syncToday)?>" required></label>
  </div>
  <details class="sync-filter-details"><summary>Review date filters for <?=count($syncMappings)?> mapped reports</summary><div class="sync-filter-list">
   <?php foreach($syncMappings as $index=>$row):$candidates=$row['filter_candidates']??[];$selected=$row['default_filter']??null;?>
   <label class="sync-filter-row"><span><strong><?=e((string)($index+1))?>. <?=e($row['report_type_name'])?></strong><small><?=e($row['boulevard_report_name'])?></small></span><select name="date_filter[<?=e((int)$row['report_type_id'])?>">
    <option value="__none__" <?=$selected===null?'selected':''?>>No added date filter<?=((string)$row['code']==='subscriptions'?' · current snapshot':' · use saved report setting')?></option>
    <?php foreach($candidates as $candidate):?><option value="<?=e($candidate)?>" <?=$selected===$candidate?'selected':''?>><?=e($candidate)?></option><?php endforeach;?>
   </select></label>
   <?php endforeach;?>
  </div></details>
  <div class="sticky-action boulevard-sync-action"><div><strong><?=count($syncMappings)?> mapped report(s) will be preflight-checked</strong><span>Invalid mappings stop before export; successful reports still generate a partial dashboard when another source needs attention.</span></div><button class="btn btn-primary btn-lg" type="submit" onclick="return confirm('Start a Boulevard API sync for the displayed reporting period?')">Run Sync Now</button></div>
 </form>
 <?php endif;?>
</section>
<section class="content-card boulevard-sync-history" style="margin-top:18px">
 <div class="card-head"><div><h2>10. Recent Boulevard Syncs</h2><p>Open a run to view live report-by-report status, retry failures, or open the generated dashboard.</p></div></div>
 <?php if(!$syncRuns):?><p class="muted-text">No Boulevard API sync has been run for this business yet.</p><?php else:?><div class="table-wrap"><table><thead><tr><th>Period</th><th>Frequency</th><th>Status</th><th>Completed</th><th>Started</th><th></th></tr></thead><tbody><?php foreach($syncRuns as $run):?><tr><td><?=e(reporting_us_date($run['period_start']))?> – <?=e(reporting_us_date($run['period_end']))?></td><td><?=e(ucfirst($run['frequency']))?></td><td><span class="sync-status-badge status-<?=e($run['status'])?>"><?=e(ucfirst($run['status']))?></span></td><td><?=e((int)$run['completed_count'])?> / <?=e((int)$run['requested_count'])?><?php if((int)$run['failed_count']):?> · <?=e((int)$run['failed_count'])?> failed<?php endif;?></td><td><?=e(reporting_us_date($run['started_at'],true))?></td><td><a class="btn btn-secondary btn-small" href="<?=url('business-boulevard-sync',['id'=>(int)$run['id']])?>">Open Sync</a></td></tr><?php endforeach;?></tbody></table></div><?php endif;?>
</section>
