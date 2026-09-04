<?php
$pageScripts = ['ai-weekly-report.js'];


$dashboard =
    ai_weekly_report_decode(
        $report
    );

?>

<section class="page-head">

 <div>

  <p class="eyebrow">
   <?=e($report['business_name'])?>
   · AI Weekly Report
  </p>

  <h1>
   <?=e(
       $dashboard['report_title']
       ?? 'Weekly Performance Report'
   )?>
  </h1>

  <p>
   <?=e($report['period_start'])?>
   →
   <?=e($report['period_end'])?>
  </p>

 </div>


 <div class="button-row">

  <a
   class="btn btn-secondary no-print"
   href="<?=url(
       'business-ai-weekly-report-print',
       [
           'id' => (int)$report['id'],
           'autoprint' => 1,
       ]
   )?>"
   target="_blank"
   rel="noopener"
   title="Open a dedicated PDF-ready version of this report"
  >
   Print / Save PDF
  </a>

  <a
   class="btn btn-secondary"
   href="<?=url(
       'business-ai-weekly-reports'
   )?>"
  >
   Report History
  </a>

 </div>

</section>


<section
 class="content-card ai-weekly-preview-shell ai-weekly-print-surface"
>

 <?php
 require
     VIEW_PATH
     . '/partials/ai-weekly-dashboard.php';
 ?>

</section>