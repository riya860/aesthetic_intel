<?php
$topics = $topics ?? documentation_visible_topics();
$sections = $sections ?? documentation_sections($topics);
$business = $business ?? documentation_business_context();
$roleLabel = $roleLabel ?? documentation_role_label();
$topicCount = count($topics);
?>
<div class="page-head documentation-head">
 <div>
  <span class="eyebrow">HELP · <?=e($roleLabel)?></span>
  <h1>Documentation</h1>
  <p>Short, practical steps for the Aesthetic Intel features available to your account.</p>
 </div>
 <div class="documentation-version"><strong>Updated for v<?=e(app_config('version'))?></strong><span><?=numfmt($topicCount)?> help topic<?=($topicCount===1?'':'s')?> available</span></div>
</div>

<section class="panel documentation-start">
 <div class="documentation-start-copy">
  <span class="eyebrow">Quick start</span>
  <h2><?=auth_is_admin()&&!admin_business_view_active()?'Manage the platform in three steps':'Use your workspace in three steps'?></h2>
  <?php if(auth_is_admin()&&!admin_business_view_active()):?>
   <ol class="documentation-steps"><li><b>1</b><span><strong>Open a business</strong><small>Use Businesses to add, edit, or enter a business workspace.</small></span></li><li><b>2</b><span><strong>Manage access</strong><small>Create users and assign the correct business and Provider KPI role.</small></span></li><li><b>3</b><span><strong>Protect the platform</strong><small>Create a backup before major changes or restores.</small></span></li></ol>
  <?php else:?>
   <ol class="documentation-steps"><li><b>1</b><span><strong>Choose the correct period</strong><small>Weekly, monthly, quarterly, yearly, or custom dates must match the source report.</small></span></li><li><b>2</b><span><strong>Add the source data</strong><small>Use the matching tool in the left sidebar and review the saved values.</small></span></li><li><b>3</b><span><strong>Open the result</strong><small>Use Reports & Downloads or Provider KPI to review performance.</small></span></li></ol>
  <?php endif;?>
 </div>
 <?php if($business):?><div class="documentation-context"><small>Current business</small><strong><?=e($business['name'])?></strong><span><?=e($business['timezone']??'')?></span></div><?php endif;?>
</section>

<div class="documentation-layout">
 <aside class="panel documentation-toc no-print" aria-label="Documentation sections">
  <strong>On this page</strong>
  <?php foreach(array_keys($sections) as $section):$anchor='docs-'.strtolower(trim((string)preg_replace('/[^a-z0-9]+/i','-',$section),'-'));?><a href="#<?=e($anchor)?>"><?=e($section)?></a><?php endforeach;?>
  <small>Only topics available to your account are shown.</small>
 </aside>

 <div class="documentation-content">
  <?php foreach($sections as $section=>$sectionTopics):$anchor='docs-'.strtolower(trim((string)preg_replace('/[^a-z0-9]+/i','-',$section),'-'));?>
   <section class="documentation-section" id="<?=e($anchor)?>">
    <div class="documentation-section-head"><span class="eyebrow"><?=e($section)?></span><h2><?=e($section)?></h2></div>
    <div class="documentation-topic-grid">
     <?php foreach($sectionTopics as $topic):?>
      <article class="panel documentation-topic" id="help-<?=e($topic['id'])?>">
       <div class="documentation-topic-head"><div><h3><?=e($topic['title'])?></h3><p><?=e($topic['summary'])?></p></div><?php if(!empty($topic['route'])):?><a class="btn btn-secondary btn-small no-print" href="<?=e($topic['route'])?>"><?=e($topic['action'])?></a><?php endif;?></div>
       <ol><?php foreach($topic['steps'] as $index=>$step):?><li><b><?=e((string)($index+1))?></b><span><?=e($step)?></span></li><?php endforeach;?></ol>
       <?php if(!empty($topic['note'])):?><div class="documentation-note"><strong>Remember</strong><span><?=e($topic['note'])?></span></div><?php endif;?>
      </article>
     <?php endforeach;?>
    </div>
   </section>
  <?php endforeach;?>
 </div>
</div>

<section class="panel documentation-footer-note">
 <div><span class="eyebrow">Always current</span><h2>Documentation is part of every release</h2><p>This guide is maintained with each user-facing release so feature, workflow, permission, upload, and reporting instructions stay aligned with the live platform.</p></div>
</section>
