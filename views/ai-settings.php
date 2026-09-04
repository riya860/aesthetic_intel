<section class="page-head"><div><p class="eyebrow">Super Admin</p><h1>OpenAI Integration</h1><p>Connect Aesthetic Intel to OpenAI for screenshot and PDF extraction, then monitor organization usage when an Admin API key is available.</p></div></section>
<div class="content-card" style="max-width:900px">
 <div class="alert alert-warning"><strong>Important:</strong> OpenAI API billing is separate from a ChatGPT subscription. Keep both keys private and never expose them to business users.</div>
 <form method="post" autocomplete="off">
  <?=csrf_field()?>
  <div class="form-grid">
   <label class="field"><span>OpenAI project API key</span><input type="password" name="api_key" placeholder="sk-..." autocomplete="new-password"><small>Used for report extraction. Leave blank to keep the saved key. Current: <?=e($maskedKey)?></small></label>
   <label class="field"><span>Model</span><input type="text" name="model" value="<?=e($settings['model']??'gpt-5-mini')?>" required><small>The selected model must support the Responses API and image/file inputs.</small></label>
  </div>
  <label class="check-row"><input type="checkbox" name="is_enabled" value="1" <?=!empty($settings['is_enabled'])?'checked':''?>> Enable AI report extraction</label>
  <div class="button-row">
   <button class="btn btn-primary" type="submit" name="action" value="save">Save Settings</button>
   <button class="btn btn-secondary" type="submit" name="action" value="test">Save & Test Connection</button>
   <?php if(!empty($settings['api_key_encrypted'])):?><button class="btn btn-danger" type="submit" name="action" value="remove" onclick="return confirm('Remove the saved OpenAI project API key?')">Remove Project Key</button><?php endif;?>
  </div>
 </form>
 <?php if(!empty($settings['last_tested_at'])):?><hr><p><strong>Last connection test:</strong> <?=e($settings['last_test_status']??'unknown')?> · <?=e($settings['last_test_message']??'')?> · <?=e($settings['last_tested_at'])?></p><?php endif;?>
</div>

<div class="content-card" style="max-width:900px;margin-top:18px">
 <h2>Organization Usage & Spend</h2>
 <p>OpenAI organization usage, costs, and hard spend limits require a separate <strong>Admin API key</strong>. A normal project key usually cannot access these organization endpoints.</p>
 <form method="post" autocomplete="off">
  <?=csrf_field()?>
  <label class="field"><span>OpenAI Admin API key</span><input type="password" name="admin_api_key" placeholder="Admin API key" autocomplete="new-password"><small>Leave blank to keep the saved Admin key. Current: <?=e($maskedAdminKey)?></small></label>
  <div class="button-row" style="margin-top:14px">
   <button class="btn btn-primary" type="submit" name="action" value="fetch_usage">Save Key & Fetch Usage</button>
   <?php if(!empty($settings['admin_api_key_encrypted'])):?><button class="btn btn-danger" type="submit" name="action" value="remove_admin" onclick="return confirm('Remove the saved OpenAI Admin API key and usage snapshot?')">Remove Admin Key</button><?php endif;?>
  </div>
 </form>

 <?php if(!empty($settings['last_usage_checked_at'])):?>
  <hr>
  <div class="metric-preview-grid">
   <div class="metric-preview"><small>Month-to-date cost</small><strong>$<?=number_format((float)($settings['last_usage_spend']??0),4)?></strong></div>
   <div class="metric-preview"><small>Model requests</small><strong><?=number_format((int)($settings['last_usage_requests']??0))?></strong></div>
   <div class="metric-preview"><small>Input tokens</small><strong><?=number_format((int)($settings['last_usage_input_tokens']??0))?></strong></div>
   <div class="metric-preview"><small>Output tokens</small><strong><?=number_format((int)($settings['last_usage_output_tokens']??0))?></strong></div>
   <div class="metric-preview"><small>Hard monthly spend limit</small><strong><?=($settings['last_usage_spend_limit']??null)!==null?'$'.number_format((float)$settings['last_usage_spend_limit'],2):'Not available'?></strong></div>
   <div class="metric-preview"><small>Remaining to hard limit</small><strong><?=($settings['last_usage_remaining']??null)!==null?'$'.number_format((float)$settings['last_usage_remaining'],2):'Not available'?></strong></div>
  </div>
  <p style="margin-top:14px"><strong>Usage period:</strong> <?=e((string)($settings['last_usage_period_start']??''))?> – <?=e((string)($settings['last_usage_period_end']??''))?> · <strong>Checked:</strong> <?=e((string)$settings['last_usage_checked_at'])?></p>
  <div class="alert <?=($settings['last_usage_status']??'')==='success'?'alert-info':'alert-warning'?>"><?=e((string)($settings['last_usage_message']??''))?></div>
 <?php endif;?>
 <div class="alert alert-info"><strong>Important:</strong> OpenAI does not provide the prepaid credit balance through these standard usage endpoints. “Remaining” is shown only when an organization hard monthly spend limit is available, calculated as spend limit minus month-to-date cost.</div>
</div>

<div class="content-card" style="max-width:900px;margin-top:18px"><h2>Current AI workflow</h2><p>Business users upload a Podium, Growth99+, or GA4 report. Aesthetic Intel reads the file with OpenAI, extracts the configured metrics, saves the structured values, and discards the temporary upload.</p></div>
