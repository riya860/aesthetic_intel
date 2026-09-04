<?php

$report =
    $report
    ?? null;


$businesses =
    $businesses
    ?? [];


$isEdit =
    !empty(
        $report['id']
    );


$selectedBusiness =
    (int)(
        $report[
            'business_id'
        ]
        ?? $selectedBusinessId
        ?? $defaultBusinessId
        ?? 0
    );


$periodStart =
    (string)(
        $report[
            'period_start'
        ]
        ?? $selectedStart
        ?? $defaultStart
        ?? ''
    );


$periodEnd =
    (string)(
        $report[
            'period_end'
        ]
        ?? $selectedEnd
        ?? $defaultEnd
        ?? ''
    );


$sourceAvailability =
    is_array(
        $sourceAvailability
        ?? null
    )
        ? $sourceAvailability
        : [];


$availableCount = 0;


foreach (
    $sourceAvailability
    as $source
) {

    if (
        !empty(
            $source[
                'available'
            ]
        )
    ) {

        $availableCount++;
    }
}


$totalSourceCount =
    count(
        $sourceAvailability
    );


$hasSourceData =
    $availableCount > 0;

?>


<section class="page-head">

    <div>

        <p class="eyebrow">
            AI Weekly Report · Super Admin
        </p>


        <h1>
            <?=$isEdit
                ? 'Edit Weekly Report'
                : 'Create Weekly Report'?>
        </h1>


        <p>
            Aesthetic Intel automatically collects
            validated reporting data already stored
            for the selected business and period.
            OpenAI converts that normalized source
            snapshot into a private weekly dashboard
            for your review.
        </p>

    </div>


    <div class="button-row">

        <a
            class="btn btn-secondary"
            href="<?=url(
                'admin-ai-weekly-reports'
            )?>"
        >
            Back to Reports
        </a>

    </div>

</section>


<form
    method="post"
    class="content-card ai-weekly-editor"
>

    <?=csrf_field()?>


    <?php if($isEdit):?>

        <input
            type="hidden"
            name="id"
            value="<?=(int)$report['id']?>"
        >

    <?php endif;?>


    <!-- ==========================================================
         BUSINESS + REPORTING PERIOD
         ========================================================== -->

    <div class="form-grid three">

        <label class="field">

            <span>
                Business
            </span>


            <select
                name="business_id"
                required
            >

                <option value="">
                    Select business
                </option>


                <?php foreach(
                    $businesses
                    as $business
                ):?>

                    <option
                        value="<?=(int)$business['id']?>"

                        <?=$selectedBusiness
                            ===
                            (int)$business['id']
                                ? 'selected'
                                : ''?>
                    >

                        <?=e(
                            (string)$business[
                                'name'
                            ]
                        )?>

                    </option>

                <?php endforeach;?>

            </select>

        </label>


        <label class="field">

            <span>
                Period Start
            </span>


            <input
                type="date"
                name="period_start"
                value="<?=e(
                    $periodStart
                )?>"
                required
            >

        </label>


        <label class="field">

            <span>
                Period End
            </span>


            <input
                type="date"
                name="period_end"
                value="<?=e(
                    $periodEnd
                )?>"
                required
            >

        </label>

    </div>


    <!-- ==========================================================
         SOURCE REFRESH
         ========================================================== -->

    <div
        class="button-row"
        style="margin-top:16px"
    >

        <button
            type="button"
            class="btn btn-secondary"
            id="aiw-refresh-sources"
        >
            Refresh Source Data
        </button>

    </div>


    <!-- ==========================================================
         SOURCE DATA AVAILABILITY
         ========================================================== -->

    <section
        class="aiw-source-section"
        style="margin-top:24px"
    >

        <div class="card-head">

            <div>

                <h2>
                    Source Data Availability
                </h2>


                <p>
                    Only reporting data already stored
                    and allowed by Report Intelligence
                    will be used.
                </p>

            </div>


            <?php if(
                $totalSourceCount > 0
            ):?>

                <div class="aiw-source-summary">

                    <strong>
                        <?=$availableCount?>
                        /
                        <?=$totalSourceCount?>
                    </strong>

                    <span>
                        available
                    </span>

                </div>

            <?php endif;?>

        </div>


        <?php if(
            $selectedBusiness <= 0
        ):?>

            <div class="alert alert-info">

                Choose a business and reporting
                period, then click
                <strong>Refresh Source Data</strong>.

            </div>


        <?php elseif(
            empty(
                $sourceAvailability
            )
        ):?>

            <div class="alert alert-info">

                Click
                <strong>Refresh Source Data</strong>
                to check the selected period.

            </div>


        <?php else:?>


            <div class="aiw-source-grid">

                <?php foreach(
                    $sourceAvailability
                    as $sourceCode => $source
                ):?>


                    <?php

                    $available =
                        !empty(
                            $source[
                                'available'
                            ]
                        );


                    $status =
                        (string)(
                            $source[
                                'status'
                            ]
                            ?? 'Missing'
                        );

                    ?>


                    <article
                        class="aiw-source-card <?=$available
                            ? 'is-available'
                            : (
                                $status
                                === 'Needs review'
                                    ? 'needs-review'
                                    : 'is-missing'
                            )?>"
                    >

                        <div class="aiw-source-card-main">

                            <strong>
                                <?=e(
                                    (string)(
                                        $source[
                                            'name'
                                        ]
                                        ?? $sourceCode
                                    )
                                )?>
                            </strong>


                            <?php if(
                                $available
                            ):?>

                                <span class="status-pill status-completed">
                                    Available
                                </span>


                            <?php elseif(
                                $status
                                === 'Needs review'
                            ):?>

                                <span class="status-pill status-warning">
                                    Needs Review
                                </span>


                            <?php else:?>

                                <span class="status-pill">
                                    Missing
                                </span>

                            <?php endif;?>

                        </div>


                        <?php if($available):?>

                            <p>
                                Validated reporting data
                                can be included in this
                                weekly snapshot.
                            </p>


                        <?php elseif(
                            $status
                            === 'Needs review'
                        ):?>

                            <p>
                                Data exists, but Report
                                Intelligence is holding
                                it out of automatic use.
                            </p>


                        <?php else:?>

                            <p>
                                No usable report was
                                found for this exact
                                reporting period.
                            </p>

                        <?php endif;?>

                    </article>

                <?php endforeach;?>

            </div>


            <?php if(
                !$hasSourceData
            ):?>

                <div
                    class="alert alert-warning"
                    style="margin-top:18px"
                >

                    <strong>
                        No usable reporting data found.
                    </strong>

                    Upload or approve at least one
                    reporting source before generating
                    an AI Weekly Report.

                </div>


            <?php elseif(
                $availableCount
                <
                $totalSourceCount
            ):?>

                <div
                    class="alert alert-info"
                    style="margin-top:18px"
                >

                    <strong>
                        Partial source coverage.
                    </strong>

                    The report can use the available
                    sources. Missing or held sources
                    will not be guessed by AI.

                </div>

            <?php endif;?>

        <?php endif;?>

    </section>


    <!-- ==========================================================
         DATA SAFETY
         ========================================================== -->

    <div
        class="alert alert-info"
        style="margin-top:22px"
    >

        <strong>
            Data safety:
        </strong>

        AI Weekly Report uses Aesthetic Intel's
        normalized business-reporting snapshot.
        Raw uploaded files and manually pasted
        patient information are not required by
        this workflow.

    </div>


    <!-- ==========================================================
         ACTIONS
         ========================================================== -->

    <div class="sticky-action ai-weekly-actions">

        <div>

            <strong>
                Review before publishing
            </strong>

            <span>
                Generating creates a private preview.
                Business users cannot see it until
                you explicitly publish it.
            </span>

        </div>


        <div class="button-row">

            <button
                class="btn btn-secondary"
                type="submit"
                name="action"
                value="save"
                <?=$hasSourceData
                    ? ''
                    : 'disabled'?>
            >
                Save Source Snapshot
            </button>


            <button
                class="btn btn-primary btn-lg"
                type="submit"
                name="action"
                value="generate"
                <?=$hasSourceData
                    ? ''
                    : 'disabled'?>
            >
                Generate AI Weekly Report
            </button>

        </div>

    </div>

</form>


<script>

document.addEventListener(
    'DOMContentLoaded',
    function () {

        const refreshButton =
            document.getElementById(
                'aiw-refresh-sources'
            );


        if (!refreshButton) {
            return;
        }


        refreshButton.addEventListener(
            'click',
            function () {

                const business =
                    document.querySelector(
                        '[name="business_id"]'
                    );


                const start =
                    document.querySelector(
                        '[name="period_start"]'
                    );


                const end =
                    document.querySelector(
                        '[name="period_end"]'
                    );


                if (
                    !business
                    ||
                    !business.value
                ) {

                    alert(
                        'Choose a business first.'
                    );

                    return;
                }


                if (
                    !start
                    ||
                    !start.value
                    ||
                    !end
                    ||
                    !end.value
                ) {

                    alert(
                        'Choose the reporting period first.'
                    );

                    return;
                }


                const params =
                    new URLSearchParams();


                params.set(
                    'page',
                    'admin-ai-weekly-report-edit'
                );


                params.set(
                    'business_id',
                    business.value
                );


                params.set(
                    'period_start',
                    start.value
                );


                params.set(
                    'period_end',
                    end.value
                );


                <?php if($isEdit):?>

                    params.set(
                        'id',
                        <?=json_encode(
                            (string)$report['id']
                        )?>
                    );

                <?php endif;?>


                window.location.href =
                    'index.php?'
                    +
                    params.toString();
            }
        );
    }
);

</script>