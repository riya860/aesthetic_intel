<?php

$reports =
    $reports ?? [];

?>

<section class="page-head">

 <div>

  <p class="eyebrow">
   <?=e($business['name'])?>
   · Intelligence
  </p>

  <h1>AI Weekly Reports</h1>

  <p>
   Published weekly performance
   summaries and recommended actions.
  </p>

 </div>

</section>


<section class="content-card">

 <?php if(!$reports):?>

  <div class="ai-weekly-empty">

   <h3>
    No published weekly reports yet
   </h3>

   <p>
    Your latest approved report will
    appear here after it is published.
   </p>

  </div>

 <?php else:?>

  <div class="ai-weekly-history-grid">

   <?php foreach(
       $reports as $row
   ):?>

    <?php
    $data =
        ai_weekly_report_decode(
            $row
        );
    ?>

    <a
     class="ai-weekly-history-card"
     href="<?=url(
         'business-ai-weekly-report',
         [
             'id' =>
                 (int)$row['id']
         ]
     )?>"
    >

     <span>
      <?=e($row['period_start'])?>
      →
      <?=e($row['period_end'])?>
     </span>

     <strong>
      <?=e(
          $data['report_title']
          ?? 'AI Weekly Report'
      )?>
     </strong>

     <p>
      <?=e(
          $data['executive_summary']
          ?? ''
      )?>
     </p>

     <small>
      Published
      <?=e($row['published_at'])?>
     </small>

    </a>

   <?php endforeach;?>

  </div>

 <?php endif;?>

</section>