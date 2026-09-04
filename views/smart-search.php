<?php
$search=$search??['query'=>'','mode'=>'empty','results'=>[],'ai_reason'=>null];
$quickActions=$quickActions??[];
$query=(string)($search['query']??'');
$mode=(string)($search['mode']??'empty');
$results=(array)($search['results']??[]);
?>
<div class="page-head smart-search-head">
 <div>
  <span class="eyebrow">Feature Finder</span>
  <h1>Smart Search</h1>
  <p>Tell Aesthetic Intel what you are trying to do. Search only checks features your account is allowed to access.</p>
 </div>
 <div class="documentation-context">
  <small>Searching as</small>
  <strong><?=e($roleLabel??documentation_role_label())?></strong>
  <?php if(!empty($business['name'])):?><span><?=e($business['name'])?></span><?php endif;?>
 </div>
</div>

<section class="card smart-search-panel">
 <form method="post" action="<?=url('smart-search')?>" class="smart-search-form">
  <?=csrf_field()?>
  <label for="smart-search-query">What are you trying to do?</label>
  <div class="smart-search-input-row">
   <input id="smart-search-query" name="q" type="search" maxlength="240" value="<?=e($query)?>" placeholder="Example: Where do I upload Boulevard reports?" autocomplete="off" autofocus required>
   <button class="btn btn-primary" type="submit">Find Feature</button>
  </div>
  <small>Local feature matching runs first. AI is used only when the local match is uncertain, and it can choose only from features already allowed for your account.</small>
 </form>
</section>

<?php if($query!==''):?>
<section class="smart-search-results" aria-live="polite">
 <div class="smart-search-results-head">
  <div><span class="eyebrow">Results</span><h2>Best matches</h2></div>
  <?php if($mode==='ai'||$mode==='ai_cached'):?>
   <span class="smart-search-mode ai">AI-assisted match</span>
  <?php elseif($mode==='local_fallback'):?>
   <span class="smart-search-mode">Local matches</span>
  <?php else:?>
   <span class="smart-search-mode">Instant local match</span>
  <?php endif;?>
 </div>
 <?php if(($mode==='ai'||$mode==='ai_cached')&&!empty($search['ai_reason'])):?><p class="smart-search-reason"><?=e($search['ai_reason'])?></p><?php endif;?>
 <?php if($results):?>
  <div class="smart-search-result-list">
   <?php foreach($results as $index=>$result):?>
    <article class="smart-search-result <?=$index===0?'best':''?>">
     <div class="smart-search-result-copy">
      <div class="smart-search-result-title">
       <h3><?=e($result['title'])?></h3>
       <?php if($index===0):?><span>Best match</span><?php endif;?>
      </div>
      <p><?=e($result['summary'])?></p>
      <small><?=e($result['path'])?></small>
     </div>
     <a class="btn <?=$index===0?'btn-primary':'btn-secondary'?> btn-small" href="<?=e($result['route'])?>"><?=e($result['action']?:'Open')?></a>
    </article>
   <?php endforeach;?>
  </div>
 <?php else:?>
  <div class="smart-search-empty">
   <strong>No safe feature match found.</strong>
   <span>Try a more specific task, such as “upload Podium report,” “set provider goals,” or “open unified report.”</span>
  </div>
 <?php endif;?>
</section>
<?php endif;?>

<section class="smart-search-quick">
 <div class="smart-search-results-head"><div><span class="eyebrow">Quick actions</span><h2>Common for your access</h2></div></div>
 <div class="smart-search-quick-grid">
  <?php foreach($quickActions as $action):?>
   <a class="smart-search-quick-card" href="<?=e($action['route'])?>">
    <strong><?=e($action['title'])?></strong>
    <span><?=e($action['summary'])?></span>
    <small><?=e($action['path'])?> →</small>
   </a>
  <?php endforeach;?>
 </div>
</section>

<div class="info-callout smart-search-safety">
 <strong>Navigation only</strong>
 <span>Smart Search can take you to the correct screen, but it never performs destructive actions, changes settings, deletes data, or bypasses the normal confirmations on that screen.</span>
</div>
