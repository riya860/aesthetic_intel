<?php

$status =
    (string)(
        $rule['status']
        ?? 'maintenance'
    );


$isMaintenance =
    $status ===
    'maintenance';


$titleText =
    $isMaintenance
        ? 'Temporarily under maintenance'
        : 'Coming soon';


$defaultMessage =
    $isMaintenance
        ? 'We are currently improving this feature. Your existing data is safe, and the feature will return when maintenance is complete.'
        : 'This feature is currently being prepared and will be available soon.';

?>


<div class="feature-status-page">


    <section
        class="
            feature-status-card
            feature-status-<?=e($status)?>
        "
    >


        <div class="feature-status-icon">

            <?php if($isMaintenance): ?>

                <span>⚙</span>

            <?php else: ?>

                <span>✦</span>

            <?php endif;?>

        </div>



        <p class="eyebrow">

            <?=$isMaintenance
                ? 'Maintenance'
                : 'Upcoming Feature'
            ?>

        </p>



        <h1>
            <?=e(
                $rule[
                    'feature_name'
                ]
            )?>
        </h1>



        <h2>
            <?=e(
                $titleText
            )?>
        </h2>



        <p class="feature-status-message">

            <?=e(
                $rule[
                    'message'
                ]
                ?: $defaultMessage
            )?>

        </p>



        <?php if(
            !empty(
                $rule[
                    'eta_text'
                ]
            )
        ): ?>

            <div class="feature-status-eta">

                <span>
                    Expected availability
                </span>

                <strong>
                    <?=e(
                        $rule[
                            'eta_text'
                        ]
                    )?>
                </strong>

            </div>

        <?php endif;?>



        <div class="button-row">

            <a
                class="btn btn-primary"
                href="<?=e(
                    auth_is_admin()
                        ? url(
                            'admin-dashboard'
                        )
                        : url(
                            'business-dashboard'
                        )
                )?>"
            >
                Back to Dashboard
            </a>

        </div>



        <small class="feature-status-note">

            <?=$isMaintenance
                ? 'No action is required from you.'
                : 'We’ll make this available once it is ready.'
            ?>

        </small>


    </section>


</div>