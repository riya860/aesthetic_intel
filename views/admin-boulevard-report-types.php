<section class="page-head"><div><p class="eyebrow">Super Admin · Boulevard</p><h1>Boulevard Report Types</h1><p>Add, edit, activate, or deactivate Boulevard report definitions without rebuilding the platform.</p></div></section>
<div class="grid-two" style="align-items:start">
 <section class="content-card">
  <h2><?=!empty($editing)?'Edit report type':'Add report type'?></h2>
  <form method="post" autocomplete="off"><?=csrf_field()?>
   <input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?=e($editing['id']??0)?>">
   <div class="form-grid">
    <label class="field"><span>Report name</span><input name="name" value="<?=e($editing['name']??'')?>" required></label>
    <label class="field"><span>Internal code</span><input name="code" value="<?=e($editing['code']??'')?>" pattern="[a-z0-9_]+" <?=!empty($editing)?'readonly':''?> required><small>Lowercase letters, numbers, and underscores only. Existing codes stay fixed to protect saved reports.</small></label>
    <label class="field"><span>Parser</span><select name="parser_key" required><?php foreach($parserOptions as $key=>$label):?><option value="<?=e($key)?>" <?=($editing['parser_key']??'generic_csv')===$key?'selected':''?>><?=e($label)?></option><?php endforeach;?></select></label>
    <label class="field"><span>Internal sort position</span><input type="number" name="sort_order" min="0" max="65000" value="<?=e($editing['sort_order']??(($nextOrder??0)+10))?>"><small>Users always see normal numbering 1, 2, 3... This internal value leaves room to insert future reports between existing ones.</small></label>
   </div>
   <label class="field"><span>Description</span><input name="description" value="<?=e($editing['description']??'')?>"></label>
   <label class="field"><span>Boulevard navigation path</span><input name="upload_path" value="<?=e($editing['upload_path']??'')?>" placeholder="Boulevard → Reports → ..."></label>
   <label class="field"><span>Expected CSV headers (optional)</span><textarea name="expected_headers" rows="3" placeholder="Header One, Header Two, Header Three"><?=e(implode(', ',json_decode((string)($editing['expected_headers_json']??''),true)?:[]))?></textarea><small>Especially useful for Generic CSV reports. The import will stop if a listed header is missing.</small></label>
   <div class="form-grid">
    <label class="check-row"><input type="checkbox" name="required" value="1" <?=!isset($editing['required'])||!empty($editing['required'])?'checked':''?>> Count as a standard report</label>
    <label class="check-row"><input type="checkbox" name="api_enabled" value="1" <?=!isset($editing['api_enabled'])||!empty($editing['api_enabled'])?'checked':''?>> Available for API mapping</label>
    <label class="check-row"><input type="checkbox" name="status" value="active" <?=($editing['status']??'active')==='active'?'checked':''?>> Active</label>
   </div>
   <div class="button-row"><button class="btn btn-primary" type="submit"><?=!empty($editing)?'Save Changes':'Add Report Type'?></button><?php if(!empty($editing)):?><a class="btn btn-secondary" href="<?=url('admin-boulevard-report-types')?>">Cancel</a><?php endif;?></div>
  </form>
 </section>
 <section class="content-card"><h2>How scaling works</h2><p>New standard reports can use the Generic CSV parser immediately. They will be available for manual upload, Boulevard API mapping, completeness tracking, and future sync runs.</p><div class="alert alert-info">A dedicated parser is needed only when a new report requires special calculations or has a multi-section CSV layout.</div></section>
</div>
<section class="content-card" style="margin-top:18px"><div class="card-head"><div><h2>Configured reports</h2><p><?=count($types)?> report type(s)</p></div></div><div class="table-wrap"><table><thead><tr><th>Order</th><th>Report</th><th>Code</th><th>Parser</th><th>API</th><th>Status</th><th></th></tr></thead><tbody><?php foreach($types as $typeIndex=>$type):?><tr><td><?=e((string)($typeIndex+1))?></td><td><strong><?=e($type['name'])?></strong><small style="display:block"><?=e($type['description'])?></small></td><td><code><?=e($type['code'])?></code></td><td><?=e($parserOptions[$type['parser_key']]??$type['parser_key'])?></td><td><?=!empty($type['api_enabled'])?'Enabled':'Disabled'?></td><td><span class="status status-<?=e($type['status'])?>"><?=e(ucfirst($type['status']))?></span></td><td><a class="btn btn-small btn-secondary" href="<?=url('admin-boulevard-report-types',['id'=>$type['id']])?>">Edit</a></td></tr><?php endforeach;?></tbody></table></div></section>