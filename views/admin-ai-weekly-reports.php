<?php

$reports =
    $reports ?? [];

?>

<section class="page-head">

 <div>

  <p class="eyebrow">
   AI Intelligence · Super Admin
  </p>

  <h1>AI Weekly Reports</h1>

  <p>
   Generate a visual weekly intelligence dashboard
   from validated business data, review it privately,
   then publish it to the selected business.
  </p>

 </div>

 <div class="button-row">

  <a
    class="btn btn-primary"
    href="<?=url(
        'admin-ai-weekly-report-edit'
    )?>"
>
    Generate New Weekly Report
</a>

 </div>

</section>


<section class="content-card">

 <div class="card-head">

  <div>
   <h2>Report history</h2>

   <p>
    Drafts stay private.
    Only reports explicitly published by
    a Super Admin are visible to
    business users.
   </p>
  </div>

 </div>


 <?php if(!$reports):?>

  <div class="ai-weekly-empty">

   <h3>No AI Weekly Reports yet</h3>

   <p>
    Create the first draft from validated
    reporting data and generate an
    OpenAI-powered intelligence dashboard.
   </p>

  </div>

 <?php else:?>

 <div class="table-wrap">

  <table>

   <thead>
    <tr>
     <th>Business</th>
     <th>Period</th>
     <th>Status</th>
     <th>Version</th>
     <th>Model</th>
     <th>Updated</th>
     <th></th>
    </tr>
   </thead>

   <tbody>

   <?php foreach($reports as $row):?>

    <?php
    $status =
        (string)$row['status'];
    ?>

    <tr>

     <td>
      <strong>
       <?=e($row['business_name'])?>
      </strong>
     </td>

     <td>
      <?=e($row['period_start'])?>
      →
      <?=e($row['period_end'])?>
     </td>

     <td>

      <span
       class="status-pill status-<?=e(
           $status === 'published'
               ? 'success'
               : (
                   $status === 'generated'
                       ? 'warning'
                       : ''
               )
       )?>"
      >
       <?=e(ucfirst($status))?>
      </span>

     </td>

     <td>
      <?=e(
          (string)$row['current_version']
      )?>
     </td>

     <td>
      <?=e(
          $row['generated_model']
          ?: '—'
      )?>
     </td>

     <td>
      <?=e($row['updated_at'])?>
     </td>

     <td class="table-actions">

      <?php if(
          in_array(
              $status,
              [
                  'generated',
                  'published',
                  'archived',
              ],
              true
          )
      ):?>

       <a
        class="btn btn-secondary btn-small"
        href="<?=url(
            'admin-ai-weekly-report-preview',
            [
                'id' =>
                    (int)$row['id']
            ]
        )?>"
       >
        Open
       </a>

      <?php else:?>

       <a
        class="btn btn-secondary btn-small"
        href="<?=url(
            'admin-ai-weekly-report-edit',
            [
                'id' =>
                    (int)$row['id']
            ]
        )?>"
       >
        Continue
       </a>

      <?php endif;?>

     </td>

    </tr>

   <?php endforeach;?>

   </tbody>

  </table>

 </div>

 <?php endif;?>

</section>