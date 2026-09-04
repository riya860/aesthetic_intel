<?php
$pageScripts = ['ai-weekly-report.js'];


$dashboard =
    ai_weekly_report_decode(
        $report
    );

$versions =
    $versions ?? [];

$status =
    (string)(
        $report['status']
        ?? 'draft'
    );

?>

<section class="page-head">

 <div>

  <p class="eyebrow">
   AI Weekly Report · Preview
  </p>

  <h1>
   <?=e($report['business_name'])?>
  </h1>

  <p>
   <?=e($report['period_start'])?>
   →
   <?=e($report['period_end'])?>
   · Version
   <?=e(
       (string)$report[
           'current_version'
       ]
   )?>
  </p>

 </div>


 <div class="button-row">

  <?php if($dashboard):?>

  <a
   class="btn btn-secondary no-print"
   href="<?=url(
       'admin-ai-weekly-report-print',
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

  <?php endif;?>

  <a
   class="btn btn-secondary"
   href="<?=url(
       'admin-ai-weekly-reports'
   )?>"
  >
   All Reports
  </a>

  <?php if(
      !in_array(
          $status,
          [
              'published',
              'archived',
          ],
          true
      )
  ):?>

   <a
    class="btn btn-secondary"
    href="<?=url(
        'admin-ai-weekly-report-edit',
        [
            'id' =>
                (int)$report['id']
        ]
    )?>"
   >
    Edit Source
   </a>

  <?php endif;?>

 </div>

</section>


<?php if($dashboard):?>

<section
 class="content-card ai-weekly-preview-shell ai-weekly-print-surface"
>

 <?php
 require
     VIEW_PATH
     . '/partials/ai-weekly-dashboard.php';
 ?>

</section>

<?php else:?>

<div class="alert alert-warning">
 This draft has not been generated yet.
</div>

<?php endif;?>


<section
 class="content-card"
 style="margin-top:18px"
>

 <div class="card-head">

  <div>

   <h2>Admin review &amp; publishing</h2>

   <p>
    Nothing is visible to the business
    until a Super Admin publishes it.
   </p>

  </div>

  <span class="status-pill">
   <?=e(ucfirst($status))?>
  </span>

 </div>


 <div class="button-row">

  <?php if(
      !in_array(
          $status,
          [
              'published',
              'archived',
          ],
          true
      )
  ):?>

   <form
    method="post"
    action="<?=url(
        'admin-ai-weekly-report-generate'
    )?>"
   >

    <?=csrf_field()?>

    <input
     type="hidden"
     name="id"
     value="<?=e(
         (int)$report['id']
     )?>"
    >

    <input
     type="hidden"
     name="confirm_safe_content"
     value="1"
    >

    <button
     class="btn btn-secondary"
     type="submit"
     onclick="return confirm(
       'Regenerate the private dashboard with AI?'
     )"
    >
     Regenerate
    </button>

   </form>

  <?php endif;?>


  <?php if(
      $status === 'generated'
  ):?>

   <form
    method="post"
    action="<?=url(
        'admin-ai-weekly-report-publish'
    )?>"
   >

    <?=csrf_field()?>

    <input
     type="hidden"
     name="id"
     value="<?=e(
         (int)$report['id']
     )?>"
    >

    <button
     class="btn btn-primary"
     type="submit"
     onclick="return confirm(
       'Publish this reviewed report to the selected business dashboard?'
     )"
    >
     Display on Business Dashboard
    </button>

   </form>

  <?php endif;?>


  <?php if(
      $status === 'published'
  ):?>

   <form
    method="post"
    action="<?=url(
        'admin-ai-weekly-report-archive'
    )?>"
   >

    <?=csrf_field()?>

    <input
     type="hidden"
     name="id"
     value="<?=e(
         (int)$report['id']
     )?>"
    >

    <button
     class="btn btn-secondary"
     type="submit"
     onclick="return confirm(
       'Archive this report and remove it from the business dashboard?'
     )"
    >
     Archive
    </button>

   </form>

  <?php endif;?>


  <?php if(
      in_array(
          $status,
          [
              'draft',
              'generated',
              'archived',
          ],
          true
      )
  ):?>

   <form
    method="post"
    action="<?=url(
        'admin-ai-weekly-report-delete'
    )?>"
   >

    <?=csrf_field()?>

    <input
     type="hidden"
     name="id"
     value="<?=e(
         (int)$report['id']
     )?>"
    >

    <button
     class="btn btn-danger"
     type="submit"
     onclick="return confirm(
       'Delete this non-published report permanently?'
     )"
    >
     Delete
    </button>

   </form>

  <?php endif;?>

 </div>

</section>


<details
 class="content-card ai-weekly-source-review"
 style="margin-top:18px"
>

 <summary>
  <strong>
   Review normalized source snapshot
  </strong>
 </summary>

 <pre><?=e(
     $report['source_text']
 )?></pre>

</details>


<?php if($versions):?>

<details
 class="content-card"
 style="margin-top:18px"
>

 <summary>
  <strong>Generation history</strong>
 </summary>

 <div
  class="table-wrap"
  style="margin-top:14px"
 >

  <table>

   <thead>
    <tr>
     <th>Version</th>
     <th>Provider</th>
     <th>Model</th>
     <th>Tokens</th>
     <th>Generated</th>
    </tr>
   </thead>

   <tbody>

   <?php foreach(
       $versions as $version
   ):?>

    <tr>

     <td>
      <?=e(
          (string)$version[
              'version_no'
          ]
      )?>
     </td>

     <td>
      <?=e($version['provider'])?>
     </td>

     <td>
      <?=e($version['model'])?>
     </td>

     <td>
      <?=e(
          (string)$version[
              'total_tokens'
          ]
      )?>
     </td>

     <td>
      <?=e($version['created_at'])?>
     </td>

    </tr>

   <?php endforeach;?>

   </tbody>

  </table>

 </div>

</details>

<?php endif;?>