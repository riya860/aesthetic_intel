<?php if($layout==='app'):?></div><?php endif;?>
    <?php if($layout==='app'):?></div><?php endif;?>


<?php
$featureAnnouncements =
    (
        $layout === 'app'
        &&
        auth_check()
    )
        ? feature_availability_announcements()
        : [];
?>


<?php if($featureAnnouncements): ?>

<div class="feature-announcement-stack no-print">

    <?php
    foreach (
        $featureAnnouncements
        as $announcement
    ):
    ?>

        <?php
        $announcementStatus =
            (string)$announcement[
                'status'
            ];
        ?>


        <a
            class="
                feature-announcement
                feature-announcement-<?=e(
                    $announcementStatus
                )?>
            "
            href="<?=e(
                url(
                    'feature-status',
                    [
                        'feature' =>
                            $announcement[
                                'feature_key'
                            ],
                    ]
                )
            )?>"
        >


            <span class="feature-announcement-icon">

                <?=$announcementStatus ===
                    'maintenance'
                    ? '⚙'
                    : '✦'
                ?>

            </span>


            <span class="feature-announcement-copy">

                <small>

                    <?=$announcementStatus ===
                        'maintenance'
                        ? 'Under maintenance'
                        : 'Coming soon'
                    ?>

                </small>


                <strong>
                    <?=e(
                        $announcement[
                            'feature_name'
                        ]
                    )?>
                </strong>


                <?php if(
                    !empty(
                        $announcement[
                            'eta_text'
                        ]
                    )
                ): ?>

                    <em>
                        <?=e(
                            $announcement[
                                'eta_text'
                            ]
                        )?>
                    </em>

                <?php endif;?>


            </span>


            <span class="feature-announcement-arrow">
                →
            </span>


        </a>

    <?php endforeach;?>

</div>

<?php endif;?>


<button
    class="theme-toggle no-print"
    type="button"
    data-theme-toggle
    aria-label="Switch theme"
>
<button class="theme-toggle no-print" type="button" data-theme-toggle aria-label="Switch theme">
 <svg class="theme-icon theme-icon-sun" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M4.93 4.93l1.42 1.42m11.3 11.3 1.42 1.42M2 12h2m16 0h2M4.93 19.07l1.42-1.42m11.3-11.3 1.42-1.42"/></svg>
 <svg class="theme-icon theme-icon-moon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.8A9 9 0 1 1 11.2 3 7 7 0 0 0 21 12.8Z"/></svg>
 <span class="theme-label">Dark mode</span>
</button>
<script src="<?=asset('js/app.js')?>?v=<?=e(app_config('version'))?>"></script><?php if(!empty($pageScripts)):foreach($pageScripts as $script):?><script src="<?=asset('js/'.$script)?>?v=<?=e(app_config('version'))?>"></script><?php endforeach;endif;?></body></html>
