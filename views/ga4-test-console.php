<?php

$connected =
    $connection
    &&
    (
        $connection['status']
        ?? ''
    ) === 'connected';


$summary =
    $testResult[
        'data'
    ][
        'summary'
    ]
    ?? [];


$comparison =
    $comparisonResult[
        'comparison'
    ]
    ?? null;


$pdfBundle =
    $comparisonResult[
        'pdf_bundle'
    ]
    ?? null;


/*
 * Display helper.
 */
$formatMetric =
    static function (
        mixed $value,
        string $format = 'number',
        int $precision = 2
    ): string {

        if (
            $value === null
            ||
            $value === ''
        ) {
            return '—';
        }

        if ($format === 'integer') {

            return number_format(
                (float)$value,
                0
            );
        }

        if ($format === 'seconds') {

            return number_format(
                (float)$value,
                $precision
            ) . 's';
        }

        return number_format(
            (float)$value,
            $precision
        );
    };

?>


<section class="page-head ga4-test-head">

    <div>

        <p class="eyebrow">
            API Integration · Test Environment
        </p>

        <h1>
            Brospro GA4 Test Console
        </h1>

        <p>
            Fetch live Google Analytics data and verify it
            against the original PDFs exported from the
            Brospro Analytics dashboard.
        </p>

    </div>

</section>



<!-- ======================================================
     CONNECTION
     ====================================================== -->

<section class="content-card ga4-test-card">

    <div class="card-head">

        <div>

            <p class="eyebrow">
                Connection
            </p>

            <h2>
                Google Analytics 4
            </h2>

        </div>


        <span
            class="status-pill
            <?=$connected
                ? 'status-success'
                : 'status-warning'
            ?>"
        >

            <?=$connected
                ? 'CONNECTED'
                : 'NOT CONNECTED'
            ?>

        </span>

    </div>


    <div class="ga4-test-meta">

        <article>

            <span>
                Business
            </span>

            <strong>
                <?=e(
                    $business['name']
                    ?? 'Brospro GA4 Test'
                )?>
            </strong>

        </article>


        <article>

            <span>
                Property ID
            </span>

            <strong>
                <?=e(
                    $connection[
                        'property_id'
                    ]
                    ?? '—'
                )?>
            </strong>

        </article>


        <article>

            <span>
                Status
            </span>

            <strong>
                <?=$connected
                    ? 'Ready for API testing'
                    : 'Connect GA4 first'
                ?>
            </strong>

        </article>

    </div>

</section>



<!-- ======================================================
     FETCH API DATA
     ====================================================== -->

<?php if ($connected): ?>

<section class="content-card ga4-test-card">

    <div class="card-head">

        <div>

            <p class="eyebrow">
                Step 1
            </p>

            <h2>
                Fetch API Data
            </h2>

            <p>
                Choose the exact period used by your
                downloaded Google Analytics PDFs.
            </p>

        </div>

    </div>


    <form
        method="post"
        action="<?=e(
            url(
                'ga4-test-console-run'
            )
        )?>"
    >

        <?=csrf_field()?>


        <div class="ga4-date-grid">

            <label>

                <span>
                    Start date
                </span>

                <input
                    type="date"
                    name="period_start"
                    value="<?=e(
                        $testResult[
                            'period_start'
                        ]
                        ?? $defaultStart
                    )?>"
                    required
                >

            </label>


            <label>

                <span>
                    End date
                </span>

                <input
                    type="date"
                    name="period_end"
                    value="<?=e(
                        $testResult[
                            'period_end'
                        ]
                        ?? $defaultEnd
                    )?>"
                    required
                >

            </label>


            <div class="ga4-action-field">

                <span>
                    Live request
                </span>

                <button
                    class="btn btn-primary"
                    type="submit"
                >
                    Fetch GA4 Data
                </button>

            </div>

        </div>

    </form>

</section>

<?php endif; ?>



<!-- ======================================================
     API RESULT
     ====================================================== -->

<?php if (
    !empty(
        $testResult[
            'success'
        ]
    )
): ?>

<section class="content-card ga4-test-card">

    <div class="card-head">

        <div>

            <p class="eyebrow">
                Step 2 · Live API Result
            </p>

            <h2>
                GA4 API Results
            </h2>

            <p>
                <?=e(
                    $testResult[
                        'period_start'
                    ]
                )?>

                →

                <?=e(
                    $testResult[
                        'period_end'
                    ]
                )?>
            </p>

        </div>


        <button
            type="button"
            class="btn btn-primary"
            id="ga4CompareToggle"
        >
            Compare with PDF Uploads
        </button>

    </div>


    <div class="ga4-api-metric-grid">

        <article>

            <span>
                Active Users
            </span>

            <strong>
                <?=e(
                    $formatMetric(
                        $summary[
                            'active_users'
                        ]
                        ?? null,
                        'integer',
                        0
                    )
                )?>
            </strong>

        </article>


        <article>

            <span>
                Sessions
            </span>

            <strong>
                <?=e(
                    $formatMetric(
                        $summary[
                            'sessions'
                        ]
                        ?? null,
                        'integer',
                        0
                    )
                )?>
            </strong>

        </article>


        <article>

            <span>
                New Users
            </span>

            <strong>
                <?=e(
                    $formatMetric(
                        $summary[
                            'new_users'
                        ]
                        ?? null,
                        'integer',
                        0
                    )
                )?>
            </strong>

        </article>


        <article>

            <span>
                Engaged Sessions
            </span>

            <strong>
                <?=e(
                    $formatMetric(
                        $summary[
                            'engaged_sessions'
                        ]
                        ?? null,
                        'integer',
                        0
                    )
                )?>
            </strong>

        </article>


        <article>

            <span>
                Views
            </span>

            <strong>
                <?=e(
                    $formatMetric(
                        $summary[
                            'page_views'
                        ]
                        ?? null,
                        'integer',
                        0
                    )
                )?>
            </strong>

        </article>


        <article>

            <span>
                Event Count
            </span>

            <strong>
                <?=e(
                    $formatMetric(
                        $summary[
                            'event_count'
                        ]
                        ?? null,
                        'integer',
                        0
                    )
                )?>
            </strong>

        </article>


        <article>

            <span>
                Avg Engagement / Session
            </span>

            <strong>
                <?=e(
                    $formatMetric(
                        $summary[
                            'average_engagement_time_per_session'
                        ]
                        ?? null,
                        'seconds',
                        2
                    )
                )?>
            </strong>

        </article>


        <article>

            <span>
                Avg Engagement / Active User
            </span>

            <strong>
                <?=e(
                    $formatMetric(
                        $summary[
                            'average_engagement_time_per_active_user'
                        ]
                        ?? null,
                        'seconds',
                        2
                    )
                )?>
            </strong>

        </article>


        <article>

            <span>
                Engaged Sessions / Active User
            </span>

            <strong>
                <?=e(
                    $formatMetric(
                        $summary[
                            'engaged_sessions_per_active_user'
                        ]
                        ?? null,
                        'number',
                        4
                    )
                )?>
            </strong>

        </article>

    </div>

</section>



<!-- ======================================================
     PDF UPLOAD
     ====================================================== -->

<section
    class="content-card ga4-test-card ga4-pdf-panel"
    id="ga4PdfComparePanel"
    <?=empty($comparisonResult)
        ? 'hidden'
        : ''
    ?>
>

    <div class="card-head">

        <div>

            <p class="eyebrow">
                Step 3 · Source Validation
            </p>

            <h2>
                Upload GA4 PDFs
            </h2>

            <p>
                Upload all Google Analytics PDFs downloaded
                for the same reporting period.
            </p>

        </div>

        <span class="status-pill status-warning">
            PDF vs API
        </span>

    </div>


    <div class="info-callout">

        <strong>
            Required PDF period
        </strong>

        <span>
            <?=e(
                $testResult[
                    'period_start'
                ]
            )?>

            →

            <?=e(
                $testResult[
                    'period_end'
                ]
            )?>

            · PDFs from another date range will be
            rejected instead of creating a misleading comparison.
        </span>

    </div>


    <form
        method="post"
        action="<?=e(
            url(
                'ga4-test-console-compare'
            )
        )?>"
        enctype="multipart/form-data"
        class="ga4-pdf-form"
    >

        <?=csrf_field()?>


        <label class="ga4-pdf-drop">

            <span class="ga4-pdf-icon">
                PDF
            </span>

            <strong>
                Select Google Analytics PDF reports
            </strong>

            <small>
                You can select multiple PDFs at once.
                Landing Page, Engagement Overview and
                other GA4 exports can all be uploaded together.
            </small>

            <input
                type="file"
                name="ga4_pdfs[]"
                accept=".pdf,application/pdf"
                multiple
                required
            >

        </label>


        <div class="button-row">

            <button
                class="btn btn-primary"
                type="submit"
            >
                Run PDF vs API Comparison
            </button>

        </div>

    </form>

</section>

<?php endif; ?>



<!-- ======================================================
     COMPARISON RESULT
     ====================================================== -->

<?php if (
    !empty(
        $comparisonResult[
            'success'
        ]
    )
    &&
    $comparison
): ?>

<section class="content-card ga4-test-card">

    <div class="card-head">

        <div>

            <p class="eyebrow">
                Comparison Analysis
            </p>

            <h2>
                PDF vs API Verification
            </h2>

            <p>
                <?=e(
                    $comparisonResult[
                        'period_start'
                    ]
                )?>

                →

                <?=e(
                    $comparisonResult[
                        'period_end'
                    ]
                )?>
            </p>

        </div>


        <span
            class="ga4-compare-status
            ga4-status-<?=e(
                $comparison[
                    'overall_status'
                ]
            )?>"
        >

            <?php
            if (
                $comparison[
                    'overall_status'
                ]
                === 'verified'
            ):
            ?>

                ✓ VERIFIED

            <?php
            elseif (
                $comparison[
                    'overall_status'
                ]
                === 'review'
            ):
            ?>

                ⚠ REVIEW

            <?php
            else:
            ?>

                INCOMPLETE

            <?php endif; ?>

        </span>

    </div>



    <div class="ga4-comparison-summary">

        <article>

            <span>
                Comparable metrics
            </span>

            <strong>
                <?=e(
                    $comparison[
                        'comparable_metrics'
                    ]
                )?>
            </strong>

        </article>


        <article>

            <span>
                Matched
            </span>

            <strong>
                <?=e(
                    $comparison[
                        'matched_metrics'
                    ]
                )?>
            </strong>

        </article>


        <article>

            <span>
                Need review
            </span>

            <strong>
                <?=e(
                    $comparison[
                        'review_metrics'
                    ]
                )?>
            </strong>

        </article>


        <article>

            <span>
                Match score
            </span>

            <strong>
                <?=e(
                    $comparison[
                        'match_percent'
                    ]
                )?>%
            </strong>

        </article>

    </div>



    <!-- Uploaded PDF analysis -->

    <div class="ga4-pdf-source-list">

        <h3>
            Uploaded PDF Sources
        </h3>


        <?php
        foreach (
            $pdfBundle['files']
            as $file
        ):
        ?>

            <article>

                <div>

                    <strong>
                        <?=e(
                            $file[
                                'file_name'
                            ]
                        )?>
                    </strong>

                    <small>

                        <?php
                        if (
                            $file[
                                'report_type'
                            ]
                            === 'landing_page'
                        ):
                        ?>

                            Landing Page report

                        <?php
                        elseif (
                            $file[
                                'report_type'
                            ]
                            === 'engagement_overview'
                        ):
                        ?>

                            Engagement Overview

                        <?php
                        else:
                        ?>

                            Other GA4 report

                        <?php endif; ?>

                    </small>

                </div>


                <?php if (
                    $file[
                        'status'
                    ]
                    === 'used'
                ): ?>

                    <span class="ga4-source-used">
                        <?=count(
                            $file[
                                'metrics'
                            ]
                        )?>
                        metrics used
                    </span>

                <?php else: ?>

                    <span class="ga4-source-unused">
                        Uploaded · no supported summary metrics
                    </span>

                <?php endif; ?>

            </article>

        <?php endforeach; ?>

    </div>



    <div class="table-wrap">

        <table class="ga4-comparison-table">

            <thead>

                <tr>

                    <th>
                        Metric
                    </th>

                    <th>
                        PDF
                    </th>

                    <th>
                        API
                    </th>

                    <th>
                        Difference
                    </th>

                    <th>
                        PDF Source
                    </th>

                    <th>
                        Status
                    </th>

                </tr>

            </thead>


            <tbody>

            <?php
            foreach (
                $comparison[
                    'rows'
                ]
                as $row
            ):
            ?>

                <tr>

                    <td>

                        <strong>
                            <?=e(
                                $row[
                                    'label'
                                ]
                            )?>
                        </strong>

                    </td>


                    <td>

                        <?=e(
                            $formatMetric(
                                $row[
                                    'pdf_value'
                                ],
                                $row[
                                    'format'
                                ],
                                $row[
                                    'precision'
                                ]
                            )
                        )?>

                    </td>


                    <td>

                        <?=e(
                            $formatMetric(
                                $row[
                                    'api_value'
                                ],
                                $row[
                                    'format'
                                ],
                                $row[
                                    'precision'
                                ] === 0
                                    &&
                                    $row[
                                        'format'
                                    ]
                                    === 'seconds'
                                        ? 2
                                        : $row[
                                            'precision'
                                        ]
                            )
                        )?>

                    </td>


                    <td>

                        <?php
                        if (
                            $row[
                                'difference'
                            ]
                            === null
                        ):
                        ?>

                            —

                        <?php else: ?>

                            <?=e(
                                $formatMetric(
                                    $row[
                                        'difference'
                                    ],
                                    $row[
                                        'format'
                                    ],
                                    $row[
                                        'format'
                                    ]
                                    === 'integer'
                                        ? 0
                                        : 2
                                )
                            )?>

                        <?php endif; ?>

                    </td>


                    <td class="ga4-source-cell">

                        <?php
                        if (
                            !empty(
                                $row[
                                    'sources'
                                ]
                            )
                        ):
                        ?>

                            <?=e(
                                implode(
                                    ', ',
                                    $row[
                                        'sources'
                                    ]
                                )
                            )?>

                        <?php else: ?>

                            —

                        <?php endif; ?>

                    </td>


                    <td>

                        <?php
                        if (
                            $row[
                                'status'
                            ]
                            === 'match'
                        ):
                        ?>

                            <span class="ga4-result-match">
                                ✓ Match
                            </span>


                        <?php
                        elseif (
                            $row[
                                'status'
                            ]
                            === 'review'
                        ):
                        ?>

                            <span class="ga4-result-review">
                                ⚠ Review
                            </span>


                        <?php
                        elseif (
                            $row[
                                'status'
                            ]
                            === 'conflict'
                        ):
                        ?>

                            <span class="ga4-result-review">
                                PDF conflict
                            </span>


                        <?php
                        elseif (
                            $row[
                                'status'
                            ]
                            === 'not_in_pdf'
                        ):
                        ?>

                            <span class="ga4-result-neutral">
                                Not shown in PDFs
                            </span>


                        <?php else: ?>

                            <span class="ga4-result-neutral">
                                API unavailable
                            </span>

                        <?php endif; ?>

                    </td>

                </tr>

            <?php endforeach; ?>

            </tbody>

        </table>

    </div>



    <?php if (
        $comparison[
            'overall_status'
        ]
        === 'verified'
    ): ?>

        <div class="alert alert-success ga4-final-message">

            <strong>
                Validation passed.
            </strong>

            All metrics that could be directly compared
            between the uploaded GA4 PDFs and the Data API
            matched at the same display precision used by
            Google Analytics.

        </div>

    <?php else: ?>

        <div class="alert alert-warning ga4-final-message">

            <strong>
                Validation is not complete yet.
            </strong>

            Review the highlighted differences before
            treating the API integration as equivalent to
            the manual GA4 reporting workflow.

        </div>

    <?php endif; ?>


</section>

<?php endif; ?>



<script>
(function () {

    const button =
        document.getElementById(
            'ga4CompareToggle'
        );

    const panel =
        document.getElementById(
            'ga4PdfComparePanel'
        );

    if (!button || !panel) {
        return;
    }

    button.addEventListener(
        'click',
        function () {

            panel.hidden = false;

            panel.scrollIntoView({
                behavior: 'smooth',
                block: 'start'
            });
        }
    );

})();
</script>