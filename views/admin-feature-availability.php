<?php

$current =
    $editRule
    ?? [];


$currentKey =
    (string)(
        $current[
            'feature_key'
        ]
        ?? ''
    );


$isBuiltin =
    isset(
        $registry[
            $currentKey
        ]
    );


$currentPreset =
    $currentKey === ''
        ? ''
        : (
            $isBuiltin
                ? $currentKey
                : '__custom__'
        );


function fa_datetime_local(
    ?string $value
): string {

    if (!$value) {
        return '';
    }


    try {

        return
            (
                new DateTimeImmutable(
                    $value
                )
            )
            ->format(
                'Y-m-d\TH:i'
            );

    } catch (Throwable) {

        return '';
    }
}

?>


<section class="page-head">

    <div>

        <p class="eyebrow">
            Super Admin · Platform Operations
        </p>

        <h1>
            Maintenance & Coming Soon
        </h1>

        <p>
            Temporarily place individual features under
            maintenance or announce upcoming functionality
            without changing the business's normal Feature
            Controls.
        </p>

    </div>

</section>



<div class="availability-layout">


<section class="content-card">

    <div class="card-head">

        <div>

            <p class="eyebrow">
                Availability Rule
            </p>

            <h2>
                <?=$editRule
                    ? 'Edit Rule'
                    : 'Create Rule'
                ?>
            </h2>

        </div>

    </div>


    <form method="post">

        <?=csrf_field()?>

        <input
            type="hidden"
            name="action"
            value="save"
        >


        <div class="form-grid two">


            <label class="field">

                <span>
                    Feature
                </span>

                <select
                    name="preset_key"
                    id="availabilityPreset"
                    required
                >

                    <option value="">
                        Choose feature
                    </option>


                    <?php
                    foreach (
                        $registry
                        as $key => $definition
                    ):
                    ?>

                        <option
                            value="<?=e($key)?>"
                            <?=$currentPreset === $key
                                ? 'selected'
                                : ''
                            ?>
                        >

                            <?=e(
                                $definition[
                                    'name'
                                ]
                            )?>

                        </option>

                    <?php endforeach;?>


                    <option
                        value="__custom__"
                        <?=$currentPreset === '__custom__'
                            ? 'selected'
                            : ''
                        ?>
                    >
                        + New / Custom Feature
                    </option>

                </select>

            </label>



            <label class="field">

                <span>
                    Scope
                </span>

                <select
                    name="business_id"
                >

                    <option
                        value="0"
                        <?=(
                            (int)(
                                $current[
                                    'business_id'
                                ]
                                ?? 0
                            ) === 0
                        )
                            ? 'selected'
                            : ''
                        ?>
                    >
                        All businesses
                    </option>


                    <?php
                    foreach (
                        $businesses
                        as $business
                    ):
                    ?>

                        <option
                            value="<?=e(
                                (int)$business[
                                    'id'
                                ]
                            )?>"

                            <?=(
                                (int)(
                                    $current[
                                        'business_id'
                                    ]
                                    ?? 0
                                )
                                ===
                                (int)$business[
                                    'id'
                                ]
                            )
                                ? 'selected'
                                : ''
                            ?>
                        >

                            <?=e(
                                $business[
                                    'name'
                                ]
                            )?>

                        </option>

                    <?php endforeach;?>

                </select>

            </label>

        </div>



        <div
            class="availability-custom-fields"
            id="availabilityCustomFields"
        >

            <label class="field">

                <span>
                    Custom Feature Key
                </span>

                <input
                    name="custom_feature_key"
                    value="<?=e(
                        !$isBuiltin
                            ? $currentKey
                            : ''
                    )?>"
                    placeholder="meta-ads"
                >

                <small>
                    Example:
                    meta-ads,
                    ai-weekly-report,
                    search-console
                </small>

            </label>


            <label class="field">

                <span>
                    Feature Name
                </span>

                <input
                    name="feature_name"
                    value="<?=e(
                        !$isBuiltin
                            ? (
                                $current[
                                    'feature_name'
                                ]
                                ?? ''
                            )
                            : ''
                    )?>"
                    placeholder="Meta Ads Integration"
                >

            </label>


            <label class="field">

                <span>
                    Route prefixes
                </span>

                <textarea
                    name="route_prefixes"
                    rows="3"
                    placeholder="business-meta&#10;business-meta-report"
                ><?=e(
                    !$isBuiltin
                        ? (
                            $current[
                                'route_prefixes'
                            ]
                            ?? ''
                        )
                        : ''
                )?></textarea>

                <small>
                    Leave blank for an announcement-only
                    Coming Soon feature.
                </small>

            </label>

        </div>



        <label class="field">

            <span>
                Status
            </span>

            <?php
            $currentStatus =
                (string)(
                    $current[
                        'status'
                    ]
                    ?? 'active'
                );
            ?>

            <select name="status">

                <option
                    value="active"
                    <?=$currentStatus === 'active'
                        ? 'selected'
                        : ''
                    ?>
                >
                    Active
                </option>


                <option
                    value="maintenance"
                    <?=$currentStatus === 'maintenance'
                        ? 'selected'
                        : ''
                    ?>
                >
                    Under Maintenance
                </option>


                <option
                    value="coming_soon"
                    <?=$currentStatus === 'coming_soon'
                        ? 'selected'
                        : ''
                    ?>
                >
                    Coming Soon
                </option>

            </select>

        </label>



        <label class="field">

            <span>
                Message
            </span>

            <textarea
                name="message"
                rows="4"
                maxlength="700"
                placeholder="We're improving this feature. It will be available again shortly."
            ><?=e(
                $current[
                    'message'
                ]
                ?? ''
            )?></textarea>

        </label>



        <label class="field">

            <span>
                ETA / release note
            </span>

            <input
                name="eta_text"
                maxlength="160"
                value="<?=e(
                    $current[
                        'eta_text'
                    ]
                    ?? ''
                )?>"
                placeholder="Expected back Tuesday morning"
            >

        </label>



        <div class="form-grid two">

            <label class="field">

                <span>
                    Starts at
                </span>

                <input
                    type="datetime-local"
                    name="starts_at"
                    value="<?=e(
                        fa_datetime_local(
                            $current[
                                'starts_at'
                            ]
                            ?? null
                        )
                    )?>"
                >

                <small>
                    Optional. Leave blank to start immediately.
                </small>

            </label>


            <label class="field">

                <span>
                    Ends at
                </span>

                <input
                    type="datetime-local"
                    name="ends_at"
                    value="<?=e(
                        fa_datetime_local(
                            $current[
                                'ends_at'
                            ]
                            ?? null
                        )
                    )?>"
                >

                <small>
                    Optional.
                </small>

            </label>

        </div>



        <label class="check-row">

            <input
                type="checkbox"
                name="show_announcement"
                value="1"

                <?=!empty(
                    $current[
                        'show_announcement'
                    ]
                )
                    ? 'checked'
                    : ''
                ?>
            >

            Show this as an announcement to users

        </label>



        <div class="button-row">

            <button
                class="btn btn-primary"
                type="submit"
            >
                <?=$editRule
                    ? 'Update Availability'
                    : 'Save Availability'
                ?>
            </button>


            <?php if($editRule): ?>

                <a
                    class="btn btn-secondary"
                    href="<?=e(
                        url(
                            'admin-feature-availability'
                        )
                    )?>"
                >
                    Cancel
                </a>

            <?php endif;?>

        </div>

    </form>

</section>



<section class="content-card">

    <div class="card-head">

        <div>

            <p class="eyebrow">
                Current Controls
            </p>

            <h2>
                Availability Rules
            </h2>

        </div>

    </div>


    <?php if(!$rules): ?>

        <div class="empty-state">

            <h3>
                No availability rules yet
            </h3>

            <p>
                All currently enabled features operate normally.
            </p>

        </div>

    <?php else: ?>


        <div class="availability-rule-list">


        <?php foreach($rules as $rule): ?>


            <?php
            $status =
                (string)$rule[
                    'status'
                ];
            ?>


            <article
                class="
                    availability-rule
                    availability-rule-<?=e($status)?>
                "
            >


                <div class="availability-rule-main">

                    <div>

                        <span
                            class="
                                availability-state
                                state-<?=e($status)?>
                            "
                        >

                            <?php
                            if (
                                $status ===
                                'maintenance'
                            ):

                                echo 'Maintenance';

                            elseif (
                                $status ===
                                'coming_soon'
                            ):

                                echo 'Coming Soon';

                            else:

                                echo 'Active';

                            endif;
                            ?>

                        </span>


                        <h3>
                            <?=e(
                                $rule[
                                    'feature_name'
                                ]
                            )?>
                        </h3>


                        <p>
                            <?=e(
                                $rule[
                                    'message'
                                ]
                                ?: 'No custom message.'
                            )?>
                        </p>


                        <small>

                            Scope:
                            <strong>
                                <?=e(
                                    $rule[
                                        'scope_name'
                                    ]
                                    ?: 'Unknown'
                                )?>
                            </strong>

                            <?php if(
                                !empty(
                                    $rule[
                                        'eta_text'
                                    ]
                                )
                            ): ?>

                                ·
                                <?=e(
                                    $rule[
                                        'eta_text'
                                    ]
                                )?>

                            <?php endif;?>

                        </small>

                    </div>

                </div>



                <div class="button-row">

                    <a
                        class="btn btn-secondary btn-small"
                        href="<?=e(
                            url(
                                'admin-feature-availability',
                                [
                                    'edit' =>
                                        (int)$rule[
                                            'id'
                                        ],
                                ]
                            )
                        )?>"
                    >
                        Edit
                    </a>


                    <form method="post">

                        <?=csrf_field()?>

                        <input
                            type="hidden"
                            name="action"
                            value="delete"
                        >

                        <input
                            type="hidden"
                            name="id"
                            value="<?=e(
                                (int)$rule[
                                    'id'
                                ]
                            )?>"
                        >

                        <button
                            class="btn btn-danger btn-small"
                            type="submit"
                            onclick="return confirm(
                                'Remove this availability rule? The actual feature will not be deleted.'
                            )"
                        >
                            Remove
                        </button>

                    </form>

                </div>


            </article>


        <?php endforeach;?>


        </div>


    <?php endif;?>

</section>


</div>



<script>
(function () {

    const select =
        document.getElementById(
            'availabilityPreset'
        );

    const custom =
        document.getElementById(
            'availabilityCustomFields'
        );

    if (!select || !custom) {
        return;
    }

    function updateCustomState() {

        custom.hidden =
            select.value !== '__custom__';
    }

    select.addEventListener(
        'change',
        updateCustomState
    );

    updateCustomState();

})();
</script>