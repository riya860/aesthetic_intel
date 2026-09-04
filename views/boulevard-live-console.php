<?php

/*
|--------------------------------------------------------------------------
| BOULEVARD LIVE API CONSOLE VIEW
|--------------------------------------------------------------------------
|
| Aesthetic Intel
| RUMA Medical / Lehi
|
| This view receives data from:
|
| index.php
|   -> boulevard-live-console
|   -> boulevard-live-console-run
|
| No Boulevard credentials are exposed here.
| No patient/client-identifiable data is displayed.
|
|--------------------------------------------------------------------------
*/


/*
|--------------------------------------------------------------------------
| NORMALIZE VIEW DATA
|--------------------------------------------------------------------------
*/

$testResult =
    is_array(
        $testResult ?? null
    )
        ? $testResult
        : null;


$success =
    $testResult
    &&
    !empty(
        $testResult['success']
    );


$failed =
    $testResult
    &&
    empty(
        $testResult['success']
    );


$metrics =
    is_array(
        $testResult['metrics']
        ?? null
    )
        ? $testResult['metrics']
        : [];


$counts =
    is_array(
        $testResult['counts']
        ?? null
    )
        ? $testResult['counts']
        : [];


$business =
    is_array(
        $testResult['business']
        ?? null
    )
        ? $testResult['business']
        : [];


$location =
    is_array(
        $testResult['location']
        ?? null
    )
        ? $testResult['location']
        : [];


$staff =
    is_array(
        $testResult['staff']
        ?? null
    )
        ? $testResult['staff']
        : [];


$services =
    is_array(
        $testResult['services']
        ?? null
    )
        ? $testResult['services']
        : [];


$appointments =
    is_array(
        $testResult['appointments']
        ?? null
    )
        ? $testResult['appointments']
        : [];


$orders =
    is_array(
        $testResult['orders']
        ?? null
    )
        ? $testResult['orders']
        : [];


$providerSummary =
    is_array(
        $testResult['provider_summary']
        ?? null
    )
        ? $testResult['provider_summary']
        : [];


$warnings =
    is_array(
        $testResult['warnings']
        ?? null
    )
        ? $testResult['warnings']
        : [];


/*
|--------------------------------------------------------------------------
| DATE VALUES
|--------------------------------------------------------------------------
*/

$formStart =
    (string)(
        $testResult['period_start']
        ?? $defaultStart
        ?? ''
    );


$formEnd =
    (string)(
        $testResult['period_end']
        ?? $defaultEnd
        ?? ''
    );


$displayLocationName =
    (string)(
        $location['name']
        ?? $locationName
        ?? 'Lehi'
    );


$displayTimezone =
    (string)(
        $location['tz']
        ?? $locationTimezone
        ?? 'America/Denver'
    );


try {

    $viewTimezone =
        new DateTimeZone(
            $displayTimezone
        );

} catch (Throwable) {

    $displayTimezone =
        'America/Denver';

    $viewTimezone =
        new DateTimeZone(
            $displayTimezone
        );
}


/*
|--------------------------------------------------------------------------
| VIEW HELPERS
|--------------------------------------------------------------------------
*/

$money =
    static function (
        mixed $cents
    ): string {

        return '$'
            . number_format(
                ((int)$cents) / 100,
                2
            );
    };


$formatDateTime =
    static function (
        mixed $value
    ) use (
        $viewTimezone
    ): string {

        $value =
            trim(
                (string)$value
            );


        if (
            $value === ''
        ) {

            return '—';
        }


        try {

            return (
                new DateTimeImmutable(
                    $value
                )
            )
                ->setTimezone(
                    $viewTimezone
                )
                ->format(
                    'M j, Y · g:i A'
                );

        } catch (Throwable) {

            return $value;
        }
    };


$statusLabel =
    static function (
        mixed $state,
        mixed $cancelled
    ): string {

        if (
            !empty(
                $cancelled
            )
        ) {

            return 'Cancelled';
        }


        $state =
            trim(
                (string)$state
            );


        if (
            $state === ''
        ) {

            return 'Active';
        }


        return ucwords(
            str_replace(
                [
                    '_',
                    '-',
                ],
                ' ',
                $state
            )
        );
    };


/*
|--------------------------------------------------------------------------
| STAFF LOOKUP
|--------------------------------------------------------------------------
*/

$staffLookup = [];


foreach (
    $staff
    as $member
) {

    $staffId =
        trim(
            (string)(
                $member['id']
                ?? ''
            )
        );


    if (
        $staffId === ''
    ) {

        continue;
    }


    $staffLookup[
        $staffId
    ] =
        (string)(
            $member['displayName']
            ??
            $member['name']
            ??
            'Unknown Provider'
        );
}


/*
|--------------------------------------------------------------------------
| SERVICE LOOKUP
|--------------------------------------------------------------------------
*/

$serviceLookup = [];


foreach (
    $services
    as $service
) {

    $serviceId =
        trim(
            (string)(
                $service['id']
                ?? ''
            )
        );


    if (
        $serviceId === ''
    ) {

        continue;
    }


    $serviceLookup[
        $serviceId
    ] =
        (string)(
            $service['name']
            ?? 'Unknown Service'
        );
}

?>


<style>

/*
|--------------------------------------------------------------------------
| BOULEVARD LIVE CONSOLE
|--------------------------------------------------------------------------
|
| Styles are scoped to .bl-live so they do not interfere
| with the rest of Aesthetic Intel.
|
*/

.bl-live {
    display: grid;
    gap: 18px;
}


/*
 * Header
 */

.bl-live-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 20px;
}

.bl-live-head-copy {
    max-width: 760px;
}

.bl-live-head-copy h1 {
    margin-bottom: 8px;
}

.bl-live-head-copy p:last-child {
    margin-bottom: 0;
}

.bl-live-status {
    flex: 0 0 auto;
}


/*
 * Test environment banner
 */

.bl-live-test-banner {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 14px 16px;
    border:
        1px solid
        color-mix(
            in srgb,
            #d98f25 28%,
            var(--border, #dedbd6)
        );
    border-radius: 16px;
    background:
        color-mix(
            in srgb,
            #f5a623 7%,
            var(--surface, #fff)
        );
}

.bl-live-test-icon {
    width: 34px;
    height: 34px;
    flex: 0 0 34px;
    display: grid;
    place-items: center;
    border-radius: 11px;
    font-weight: 800;
    background:
        color-mix(
            in srgb,
            #f5a623 16%,
            transparent
        );
}

.bl-live-test-banner strong {
    display: block;
    margin-bottom: 3px;
}

.bl-live-test-banner p {
    margin: 0;
    color: var(--muted, #6d6870);
    font-size: .9rem;
}


/*
 * Fetch panel
 */

.bl-live-fetch-card {
    overflow: visible;
}

.bl-live-filter {
    display: grid;
    grid-template-columns:
        minmax(190px, 1fr)
        minmax(190px, 1fr)
        auto;
    gap: 14px;
    align-items: end;
}

.bl-live-filter .field {
    margin: 0;
}

.bl-live-fetch-actions {
    display: flex;
    align-items: center;
    min-height: 44px;
}


/*
 * KPI cards
 */

.bl-live-kpi-grid {
    display: grid;
    grid-template-columns:
        repeat(
            4,
            minmax(0, 1fr)
        );
    gap: 14px;
}

.bl-live-kpi {
    min-width: 0;
    padding: 18px;
    border:
        1px solid
        var(
            --ai-line,
            var(--border, #dedbd6)
        );
    border-radius: 19px;
    background:
        color-mix(
            in srgb,
            var(--surface-raised, #fff) 96%,
            transparent
        );
}

.bl-live-kpi-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
}

.bl-live-kpi-icon {
    width: 34px;
    height: 34px;
    display: grid;
    place-items: center;
    border-radius: 11px;
    font-size: .78rem;
    font-weight: 800;
    color: var(--muted, #6d6870);
    background:
        color-mix(
            in srgb,
            var(--surface-soft, #f4f1ee) 86%,
            transparent
        );
}

.bl-live-kpi small {
    display: block;
    color: var(--muted, #6d6870);
    font-size: .72rem;
    font-weight: 700;
    letter-spacing: .025em;
    text-transform: uppercase;
}

.bl-live-kpi strong {
    display: block;
    margin-top: 18px;
    font-size:
        clamp(
            1.6rem,
            2vw,
            2.05rem
        );
    font-weight: 650;
    letter-spacing: -.04em;
    word-break: break-word;
}

.bl-live-kpi p {
    margin:
        6px
        0
        0;
    color: var(--muted, #6d6870);
    font-size: .8rem;
}


/*
 * Source / context grid
 */

.bl-live-context-grid {
    display: grid;
    grid-template-columns:
        repeat(
            3,
            minmax(0, 1fr)
        );
    gap: 12px;
}

.bl-live-context-item {
    padding: 14px;
    border:
        1px solid
        var(
            --ai-line,
            var(--border, #dedbd6)
        );
    border-radius: 15px;
    background:
        color-mix(
            in srgb,
            var(--surface-soft, #f7f4f1) 55%,
            transparent
        );
}

.bl-live-context-item span {
    display: block;
    margin-bottom: 5px;
    color: var(--muted, #6d6870);
    font-size: .72rem;
    font-weight: 700;
    text-transform: uppercase;
}

.bl-live-context-item strong {
    display: block;
    word-break: break-word;
}


/*
 * Section helpers
 */

.bl-live-section {
    overflow: hidden;
}

.bl-live-section .card-head {
    align-items: flex-start;
}

.bl-live-count {
    white-space: nowrap;
}

.bl-live-table-wrap {
    width: 100%;
    overflow-x: auto;
}

.bl-live-table {
    width: 100%;
    border-collapse: collapse;
}

.bl-live-table th,
.bl-live-table td {
    padding: 13px 14px;
    text-align: left;
    vertical-align: top;
    border-bottom:
        1px solid
        var(
            --ai-line,
            var(--border, #ece8e4)
        );
}

.bl-live-table th {
    color: var(--muted, #6d6870);
    font-size: .7rem;
    font-weight: 750;
    text-transform: uppercase;
    letter-spacing: .03em;
}

.bl-live-table td {
    font-size: .88rem;
}

.bl-live-table tbody tr:last-child td {
    border-bottom: 0;
}

.bl-live-table tbody tr:hover {
    background:
        color-mix(
            in srgb,
            var(--surface-soft, #f5f2ef) 48%,
            transparent
        );
}

.bl-live-primary-cell strong {
    display: block;
}

.bl-live-primary-cell small {
    display: block;
    margin-top: 4px;
    color: var(--muted, #6d6870);
}

.bl-live-nowrap {
    white-space: nowrap;
}


/*
 * Status chips
 */

.bl-live-chip {
    display: inline-flex;
    align-items: center;
    min-height: 25px;
    padding: 4px 9px;
    border-radius: 999px;
    font-size: .7rem;
    font-weight: 750;
    background:
        color-mix(
            in srgb,
            var(--surface-soft, #f1efed) 90%,
            transparent
        );
}

.bl-live-chip-success {
    color: #177b4b;
    background: rgba(29, 143, 88, .09);
}

.bl-live-chip-danger {
    color: #b4404c;
    background: rgba(201, 78, 88, .09);
}

.bl-live-chip-neutral {
    color: var(--muted, #6d6870);
}


/*
 * Empty states
 */

.bl-live-empty {
    padding:
        38px
        20px;
    text-align: center;
}

.bl-live-empty-icon {
    width: 48px;
    height: 48px;
    margin:
        0
        auto
        14px;
    display: grid;
    place-items: center;
    border-radius: 15px;
    font-weight: 800;
    background:
        color-mix(
            in srgb,
            var(--surface-soft, #f4f1ee) 95%,
            transparent
        );
}

.bl-live-empty h3 {
    margin:
        0
        0
        6px;
}

.bl-live-empty p {
    max-width: 600px;
    margin:
        0
        auto;
    color: var(--muted, #6d6870);
}


/*
 * Warning stack
 */

.bl-live-warning-stack {
    display: grid;
    gap: 9px;
}


/*
 * Responsive
 */

@media (
    max-width: 1100px
) {

    .bl-live-kpi-grid {
        grid-template-columns:
            repeat(
                2,
                minmax(0, 1fr)
            );
    }

    .bl-live-context-grid {
        grid-template-columns:
            repeat(
                2,
                minmax(0, 1fr)
            );
    }

}


@media (
    max-width: 760px
) {

    .bl-live-head {
        flex-direction: column;
    }

    .bl-live-filter {
        grid-template-columns: 1fr;
    }

    .bl-live-fetch-actions,
    .bl-live-fetch-actions .btn {
        width: 100%;
    }

    .bl-live-kpi-grid,
    .bl-live-context-grid {
        grid-template-columns: 1fr;
    }

}


@media print {

    .bl-live-fetch-card,
    .bl-live-test-banner {
        display: none !important;
    }

}

</style>


<div class="bl-live">


    <!-- =========================================================
         PAGE HEADER
         ========================================================= -->

    <section class="page-head bl-live-head">

        <div class="bl-live-head-copy">

            <p class="eyebrow">
                RUMA Medical · Live API Test
            </p>

            <h1>
                Boulevard Live API Console
            </h1>

            <p>
                Fetch live Boulevard operational data
                directly inside Aesthetic Intel without
                changing the existing Boulevard report
                mapping or upload workflow.
            </p>

        </div>


        <div class="bl-live-status">

            <?php if ($success): ?>

                <span class="status-pill status-success">
                    Connected
                </span>

            <?php elseif ($failed): ?>

                <span class="status-pill status-warning">
                    Fetch Failed
                </span>

            <?php else: ?>

                <span class="status-pill">
                    Ready to Test
                </span>

            <?php endif; ?>

        </div>

    </section>



    <!-- =========================================================
         TEST ENVIRONMENT NOTICE
         ========================================================= -->

    <div class="bl-live-test-banner">

        <div class="bl-live-test-icon">
            API
        </div>

        <div>

            <strong>
                Live Boulevard test environment
            </strong>

            <p>
                This console reads live RUMA data.
                It does not modify Boulevard records
                and does not request patient identity fields.
            </p>

        </div>

    </div>



    <!-- =========================================================
         FETCH FORM
         ========================================================= -->

    <section class="content-card bl-live-fetch-card">

        <div class="card-head">

            <div>

                <p class="eyebrow">
                    Date Range
                </p>

                <h2>
                    Fetch Boulevard Data
                </h2>

                <p>
                    The reporting period is interpreted
                    using the
                    <?= e(
                        $displayTimezone
                    ) ?>
                    location timezone.
                </p>

            </div>

        </div>


        <form
            method="post"
            action="<?= e(
                url(
                    'boulevard-live-console-run'
                )
            ) ?>"
        >

            <?= csrf_field() ?>


            <div class="bl-live-filter">

                <label class="field">

                    <span>
                        Start date
                    </span>

                    <input
                        type="date"
                        name="period_start"
                        value="<?= e(
                            $formStart
                        ) ?>"
                        required
                    >

                    <small>
                        First day included.
                    </small>

                </label>


                <label class="field">

                    <span>
                        End date
                    </span>

                    <input
                        type="date"
                        name="period_end"
                        value="<?= e(
                            $formEnd
                        ) ?>"
                        required
                    >

                    <small>
                        Last day included.
                    </small>

                </label>


                <div class="bl-live-fetch-actions">

                    <button
                        class="btn btn-primary"
                        type="submit"
                    >
                        Fetch Live Data
                    </button>

                </div>

            </div>

        </form>

    </section>



    <!-- =========================================================
         FAILURE
         ========================================================= -->

    <?php if ($failed): ?>

        <div class="alert alert-warning">

            <strong>
                Boulevard API fetch failed.
            </strong>

            <div style="margin-top:5px">

                <?= e(
                    (string)(
                        $testResult[
                            'error'
                        ]
                        ??
                        'The live request could not be completed.'
                    )
                ) ?>

            </div>

        </div>

    <?php endif; ?>



    <!-- =========================================================
         WARNINGS
         ========================================================= -->

    <?php if ($warnings): ?>

        <div class="bl-live-warning-stack">

            <?php foreach (
                $warnings
                as $warning
            ): ?>

                <div class="alert alert-warning">

                    <?= e(
                        (string)$warning
                    ) ?>

                </div>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>



    <!-- =========================================================
         INITIAL EMPTY STATE
         ========================================================= -->

    <?php if (!$testResult): ?>

        <section class="content-card">

            <div class="bl-live-empty">

                <div class="bl-live-empty-icon">
                    ↳
                </div>

                <h3>
                    Ready to fetch RUMA data
                </h3>

                <p>
                    Choose a small date range above and
                    click Fetch Live Data. Aesthetic Intel
                    will verify the RUMA Boulevard account,
                    load Lehi data and display the available
                    operational metrics here.
                </p>

            </div>

        </section>

    <?php endif; ?>



    <?php if ($success): ?>


        <!-- =====================================================
             KPI OVERVIEW
             ===================================================== -->

        <section class="bl-live-kpi-grid">


            <article class="bl-live-kpi">

                <div class="bl-live-kpi-head">

                    <small>
                        Appointments
                    </small>

                    <span class="bl-live-kpi-icon">
                        AP
                    </span>

                </div>

                <strong>
                    <?= e(
                        number_format(
                            (int)(
                                $metrics[
                                    'appointments'
                                ]
                                ?? 0
                            )
                        )
                    ) ?>
                </strong>

                <p>
                    Selected reporting period
                </p>

            </article>



            <article class="bl-live-kpi">

                <div class="bl-live-kpi-head">

                    <small>
                        Completed
                    </small>

                    <span class="bl-live-kpi-icon">
                        ✓
                    </span>

                </div>

                <strong>
                    <?= e(
                        number_format(
                            (int)(
                                $metrics[
                                    'completed'
                                ]
                                ?? 0
                            )
                        )
                    ) ?>
                </strong>

                <p>
                    Completed appointments
                </p>

            </article>



            <article class="bl-live-kpi">

                <div class="bl-live-kpi-head">

                    <small>
                        Cancelled
                    </small>

                    <span class="bl-live-kpi-icon">
                        ×
                    </span>

                </div>

                <strong>
                    <?= e(
                        number_format(
                            (int)(
                                $metrics[
                                    'cancelled'
                                ]
                                ?? 0
                            )
                        )
                    ) ?>
                </strong>

                <p>
                    Cancelled appointments
                </p>

            </article>



            <article class="bl-live-kpi">

                <div class="bl-live-kpi-head">

                    <small>
                        Orders
                    </small>

                    <span class="bl-live-kpi-icon">
                        OR
                    </span>

                </div>

                <strong>
                    <?= e(
                        number_format(
                            (int)(
                                $metrics[
                                    'orders'
                                ]
                                ?? 0
                            )
                        )
                    ) ?>
                </strong>

                <p>
                    Orders closed in period
                </p>

            </article>



            <article class="bl-live-kpi">

                <div class="bl-live-kpi-head">

                    <small>
                        Net Order Total
                    </small>

                    <span class="bl-live-kpi-icon">
                        $
                    </span>

                </div>

                <strong>
                    <?= e(
                        $money(
                            $metrics[
                                'revenue_cents'
                            ]
                            ?? 0
                        )
                    ) ?>
                </strong>

                <p>
                    Current order totals
                </p>

            </article>



            <article class="bl-live-kpi">

                <div class="bl-live-kpi-head">

                    <small>
                        Refunds
                    </small>

                    <span class="bl-live-kpi-icon">
                        ↩
                    </span>

                </div>

                <strong>
                    <?= e(
                        $money(
                            $metrics[
                                'refund_cents'
                            ]
                            ?? 0
                        )
                    ) ?>
                </strong>

                <p>
                    Boulevard refund amount
                </p>

            </article>



            <article class="bl-live-kpi">

                <div class="bl-live-kpi-head">

                    <small>
                        Active Staff
                    </small>

                    <span class="bl-live-kpi-icon">
                        ST
                    </span>

                </div>

                <strong>
                    <?= e(
                        number_format(
                            (int)(
                                $metrics[
                                    'active_staff'
                                ]
                                ?? 0
                            )
                        )
                    ) ?>
                </strong>

                <p>
                    Active Boulevard staff records
                </p>

            </article>



            <article class="bl-live-kpi">

                <div class="bl-live-kpi-head">

                    <small>
                        Active Services
                    </small>

                    <span class="bl-live-kpi-icon">
                        SV
                    </span>

                </div>

                <strong>
                    <?= e(
                        number_format(
                            (int)(
                                $metrics[
                                    'active_services'
                                ]
                                ?? 0
                            )
                        )
                    ) ?>
                </strong>

                <p>
                    Active Boulevard services
                </p>

            </article>


        </section>



        <!-- =====================================================
             CONNECTION INFORMATION
             ===================================================== -->

        <section class="content-card bl-live-section">

            <div class="card-head">

                <div>

                    <p class="eyebrow">
                        Source Verification
                    </p>

                    <h2>
                        Boulevard Connection
                    </h2>

                    <p>
                        Identity and scope returned by the
                        live Boulevard request.
                    </p>

                </div>

                <span class="status-pill status-success">
                    Verified
                </span>

            </div>


            <div class="bl-live-context-grid">


                <div class="bl-live-context-item">

                    <span>
                        Business
                    </span>

                    <strong>
                        <?= e(
                            (string)(
                                $business[
                                    'name'
                                ]
                                ?? 'RUMA Medical'
                            )
                        ) ?>
                    </strong>

                </div>



                <div class="bl-live-context-item">

                    <span>
                        Location
                    </span>

                    <strong>
                        <?= e(
                            $displayLocationName
                        ) ?>
                    </strong>

                </div>



                <div class="bl-live-context-item">

                    <span>
                        Location timezone
                    </span>

                    <strong>
                        <?= e(
                            $displayTimezone
                        ) ?>
                    </strong>

                </div>



                <div class="bl-live-context-item">

                    <span>
                        Period
                    </span>

                    <strong>

                        <?= e(
                            $formStart
                        ) ?>

                        →

                        <?= e(
                            $formEnd
                        ) ?>

                    </strong>

                </div>



                <div class="bl-live-context-item">

                    <span>
                        Fetched
                    </span>

                    <strong>
                        <?= e(
                            (string)(
                                $testResult[
                                    'fetched_at'
                                ]
                                ?? '—'
                            )
                        ) ?>
                    </strong>

                </div>



                <div class="bl-live-context-item">

                    <span>
                        API status
                    </span>

                    <strong>
                        Live connection successful
                    </strong>

                </div>


            </div>


            <div
                class="metric-preview-grid"
                style="margin-top:16px"
            >

                <div class="metric-preview">

                    <small>
                        Locations
                    </small>

                    <strong>
                        <?= e(
                            number_format(
                                (int)(
                                    $counts[
                                        'locations'
                                    ]
                                    ?? 0
                                )
                            )
                        ) ?>
                    </strong>

                </div>


                <div class="metric-preview">

                    <small>
                        Staff
                    </small>

                    <strong>
                        <?= e(
                            number_format(
                                (int)(
                                    $counts[
                                        'staff'
                                    ]
                                    ?? 0
                                )
                            )
                        ) ?>
                    </strong>

                </div>


                <div class="metric-preview">

                    <small>
                        Services
                    </small>

                    <strong>
                        <?= e(
                            number_format(
                                (int)(
                                    $counts[
                                        'services'
                                    ]
                                    ?? 0
                                )
                            )
                        ) ?>
                    </strong>

                </div>


                <div class="metric-preview">

                    <small>
                        Appointments
                    </small>

                    <strong>
                        <?= e(
                            number_format(
                                (int)(
                                    $counts[
                                        'appointments'
                                    ]
                                    ?? 0
                                )
                            )
                        ) ?>
                    </strong>

                </div>


                <div class="metric-preview">

                    <small>
                        Orders
                    </small>

                    <strong>
                        <?= e(
                            number_format(
                                (int)(
                                    $counts[
                                        'orders'
                                    ]
                                    ?? 0
                                )
                            )
                        ) ?>
                    </strong>

                </div>

            </div>

        </section>



        <!-- =====================================================
             PROVIDER ACTIVITY
             ===================================================== -->

        <section class="content-card bl-live-section">

            <div class="card-head">

                <div>

                    <p class="eyebrow">
                        Operations
                    </p>

                    <h2>
                        Provider Activity
                    </h2>

                    <p>
                        Provider activity derived from
                        Boulevard appointment service lines.
                    </p>

                </div>

                <span class="status-pill">

                    <?= e(
                        number_format(
                            count(
                                $providerSummary
                            )
                        )
                    ) ?>

                    providers

                </span>

            </div>


            <?php if ($providerSummary): ?>

                <div class="bl-live-table-wrap">

                    <table class="bl-live-table">

                        <thead>

                            <tr>

                                <th>
                                    Provider
                                </th>

                                <th>
                                    Service Lines
                                </th>

                                <th>
                                    Booked Service Value
                                </th>

                            </tr>

                        </thead>


                        <tbody>

                            <?php foreach (
                                $providerSummary
                                as $provider
                            ): ?>

                                <tr>

                                    <td class="bl-live-primary-cell">

                                        <strong>
                                            <?= e(
                                                (string)(
                                                    $provider[
                                                        'name'
                                                    ]
                                                    ??
                                                    'Unknown Provider'
                                                )
                                            ) ?>
                                        </strong>

                                        <?php if (
                                            !empty(
                                                $provider[
                                                    'staff_id'
                                                ]
                                            )
                                        ): ?>

                                            <small>
                                                <?= e(
                                                    (string)$provider[
                                                        'staff_id'
                                                    ]
                                                ) ?>
                                            </small>

                                        <?php endif; ?>

                                    </td>


                                    <td>

                                        <?= e(
                                            number_format(
                                                (int)(
                                                    $provider[
                                                        'appointment_services'
                                                    ]
                                                    ??
                                                    $provider[
                                                        'appointments'
                                                    ]
                                                    ??
                                                    0
                                                )
                                            )
                                        ) ?>

                                    </td>


                                    <td>

                                        <?= e(
                                            $money(
                                                $provider[
                                                    'booked_value_cents'
                                                ]
                                                ?? 0
                                            )
                                        ) ?>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php else: ?>

                <div class="bl-live-empty">

                    <h3>
                        No provider activity returned
                    </h3>

                    <p>
                        There may be no appointments in this
                        period, or provider/staff data may not
                        have been available from the request.
                    </p>

                </div>

            <?php endif; ?>

        </section>



        <!-- =====================================================
             APPOINTMENTS
             ===================================================== -->

        <section class="content-card bl-live-section">

            <div class="card-head">

                <div>

                    <p class="eyebrow">
                        Live Boulevard Data
                    </p>

                    <h2>
                        Appointments
                    </h2>

                    <p>
                        Patient/client identity is intentionally
                        excluded from this console.
                    </p>

                </div>

                <span class="status-pill">

                    <?= e(
                        number_format(
                            count(
                                $appointments
                            )
                        )
                    ) ?>

                    shown

                </span>

            </div>


            <?php if ($appointments): ?>

                <div class="bl-live-table-wrap">

                    <table class="bl-live-table">

                        <thead>

                            <tr>

                                <th>
                                    Start
                                </th>

                                <th>
                                    Status
                                </th>

                                <th>
                                    Provider
                                </th>

                                <th>
                                    Service
                                </th>

                                <th>
                                    Duration
                                </th>

                                <th>
                                    Booked Value
                                </th>

                            </tr>

                        </thead>


                        <tbody>

                        <?php foreach (
                            $appointments
                            as $appointment
                        ): ?>


                            <?php

                            $appointmentServices =
                                is_array(
                                    $appointment[
                                        'appointmentServices'
                                    ]
                                    ?? null
                                )
                                    ? $appointment[
                                        'appointmentServices'
                                    ]
                                    : [];


                            $appointmentProviderNames =
                                [];


                            $appointmentServiceNames =
                                [];


                            $bookedValueCents =
                                0;


                            foreach (
                                $appointmentServices
                                as $appointmentService
                            ) {

                                $staffId =
                                    (string)(
                                        $appointmentService[
                                            'staffId'
                                        ]
                                        ??
                                        $appointmentService[
                                            'staff'
                                        ][
                                            'id'
                                        ]
                                        ??
                                        ''
                                    );


                                if (
                                    $staffId !== ''
                                ) {

                                    $providerName =
                                        $staffLookup[
                                            $staffId
                                        ]
                                        ??
                                        $appointmentService[
                                            'staff'
                                        ][
                                            'displayName'
                                        ]
                                        ??
                                        $appointmentService[
                                            'staff'
                                        ][
                                            'name'
                                        ]
                                        ??
                                        'Unknown Provider';


                                    $appointmentProviderNames[
                                        $providerName
                                    ] =
                                        true;
                                }


                                $serviceId =
                                    (string)(
                                        $appointmentService[
                                            'serviceId'
                                        ]
                                        ??
                                        $appointmentService[
                                            'service'
                                        ][
                                            'id'
                                        ]
                                        ??
                                        ''
                                    );


                                if (
                                    $serviceId !== ''
                                ) {

                                    $serviceName =
                                        $serviceLookup[
                                            $serviceId
                                        ]
                                        ??
                                        $appointmentService[
                                            'service'
                                        ][
                                            'name'
                                        ]
                                        ??
                                        'Unknown Service';


                                    $appointmentServiceNames[
                                        $serviceName
                                    ] =
                                        true;
                                }


                                $bookedValueCents +=
                                    (int)(
                                        $appointmentService[
                                            'price'
                                        ]
                                        ?? 0
                                    );
                            }


                            $displayProviders =
                                $appointmentProviderNames
                                    ? implode(
                                        ', ',
                                        array_keys(
                                            $appointmentProviderNames
                                        )
                                    )
                                    : '—';


                            $displayServices =
                                $appointmentServiceNames
                                    ? implode(
                                        ', ',
                                        array_keys(
                                            $appointmentServiceNames
                                        )
                                    )
                                    : (
                                        count(
                                            $appointmentServices
                                        )
                                        . ' service line(s)'
                                    );


                            $appointmentStatus =
                                $statusLabel(
                                    $appointment[
                                        'state'
                                    ]
                                    ?? '',
                                    $appointment[
                                        'cancelled'
                                    ]
                                    ?? false
                                );


                            $appointmentCancelled =
                                !empty(
                                    $appointment[
                                        'cancelled'
                                    ]
                                );

                            ?>


                            <tr>

                                <td class="bl-live-nowrap">

                                    <?= e(
                                        $formatDateTime(
                                            $appointment[
                                                'startAt'
                                            ]
                                            ?? null
                                        )
                                    ) ?>

                                </td>


                                <td>

                                    <span
                                        class="bl-live-chip <?= $appointmentCancelled
                                            ? 'bl-live-chip-danger'
                                            : (
                                                strtolower(
                                                    $appointmentStatus
                                                )
                                                ===
                                                'completed'
                                                    ? 'bl-live-chip-success'
                                                    : 'bl-live-chip-neutral'
                                            ) ?>"
                                    >

                                        <?= e(
                                            $appointmentStatus
                                        ) ?>

                                    </span>

                                </td>


                                <td>

                                    <?= e(
                                        $displayProviders
                                    ) ?>

                                </td>


                                <td>

                                    <?= e(
                                        $displayServices
                                    ) ?>

                                </td>


                                <td class="bl-live-nowrap">

                                    <?php if (
                                        isset(
                                            $appointment[
                                                'duration'
                                            ]
                                        )
                                    ): ?>

                                        <?= e(
                                            number_format(
                                                (int)$appointment[
                                                    'duration'
                                                ]
                                            )
                                        ) ?>
                                        min

                                    <?php else: ?>

                                        —

                                    <?php endif; ?>

                                </td>


                                <td class="bl-live-nowrap">

                                    <?= e(
                                        $money(
                                            $bookedValueCents
                                        )
                                    ) ?>

                                </td>

                            </tr>


                        <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php else: ?>

                <div class="bl-live-empty">

                    <h3>
                        No appointments returned
                    </h3>

                    <p>
                        No Boulevard appointments were returned
                        for the selected reporting period, or
                        that API section returned a warning.
                    </p>

                </div>

            <?php endif; ?>

        </section>



        <!-- =====================================================
             ORDERS
             ===================================================== -->

        <section class="content-card bl-live-section">

            <div class="card-head">

                <div>

                    <p class="eyebrow">
                        Revenue Source
                    </p>

                    <h2>
                        Orders
                    </h2>

                    <p>
                        Live order totals returned by Boulevard
                        for the selected Lehi reporting period.
                    </p>

                </div>

                <span class="status-pill">

                    <?= e(
                        number_format(
                            count(
                                $orders
                            )
                        )
                    ) ?>

                    shown

                </span>

            </div>


            <?php if ($orders): ?>

                <div class="bl-live-table-wrap">

                    <table class="bl-live-table">

                        <thead>

                            <tr>

                                <th>
                                    Order
                                </th>

                                <th>
                                    Closed
                                </th>

                                <th>
                                    Subtotal
                                </th>

                                <th>
                                    Discounts
                                </th>

                                <th>
                                    Tax
                                </th>

                                <th>
                                    Gratuity
                                </th>

                                <th>
                                    Fees
                                </th>

                                <th>
                                    Refunds
                                </th>

                                <th>
                                    Current Total
                                </th>

                            </tr>

                        </thead>


                        <tbody>

                        <?php foreach (
                            $orders
                            as $order
                        ): ?>


                            <?php

                            $summary =
                                is_array(
                                    $order[
                                        'summary'
                                    ]
                                    ?? null
                                )
                                    ? $order[
                                        'summary'
                                    ]
                                    : [];

                            ?>


                            <tr>

                                <td class="bl-live-primary-cell">

                                    <strong>

                                        #<?= e(
                                            (string)(
                                                $order[
                                                    'number'
                                                ]
                                                ??
                                                '—'
                                            )
                                        ) ?>

                                    </strong>

                                    <small>

                                        <?= e(
                                            (string)(
                                                $order[
                                                    'id'
                                                ]
                                                ??
                                                ''
                                            )
                                        ) ?>

                                    </small>

                                </td>


                                <td class="bl-live-nowrap">

                                    <?= e(
                                        $formatDateTime(
                                            $order[
                                                'closedAt'
                                            ]
                                            ?? null
                                        )
                                    ) ?>

                                </td>


                                <td>

                                    <?= e(
                                        $money(
                                            $summary[
                                                'currentSubtotal'
                                            ]
                                            ?? 0
                                        )
                                    ) ?>

                                </td>


                                <td>

                                    <?= e(
                                        $money(
                                            $summary[
                                                'currentDiscountAmount'
                                            ]
                                            ?? 0
                                        )
                                    ) ?>

                                </td>


                                <td>

                                    <?= e(
                                        $money(
                                            $summary[
                                                'currentTaxAmount'
                                            ]
                                            ?? 0
                                        )
                                    ) ?>

                                </td>


                                <td>

                                    <?= e(
                                        $money(
                                            $summary[
                                                'currentGratuityAmount'
                                            ]
                                            ?? 0
                                        )
                                    ) ?>

                                </td>


                                <td>

                                    <?= e(
                                        $money(
                                            $summary[
                                                'currentFeeAmount'
                                            ]
                                            ?? 0
                                        )
                                    ) ?>

                                </td>


                                <td>

                                    <?= e(
                                        $money(
                                            $summary[
                                                'refundAmount'
                                            ]
                                            ?? 0
                                        )
                                    ) ?>

                                </td>


                                <td>

                                    <strong>

                                        <?= e(
                                            $money(
                                                $summary[
                                                    'currentTotal'
                                                ]
                                                ?? 0
                                            )
                                        ) ?>

                                    </strong>

                                </td>

                            </tr>


                        <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php else: ?>

                <div class="bl-live-empty">

                    <h3>
                        No orders returned
                    </h3>

                    <p>
                        No closed Boulevard orders were returned
                        for this period, or the orders request
                        needs additional investigation.
                    </p>

                </div>

            <?php endif; ?>

        </section>



        <!-- =====================================================
             STAFF
             ===================================================== -->

        <section class="content-card bl-live-section">

            <div class="card-head">

                <div>

                    <p class="eyebrow">
                        Reference Data
                    </p>

                    <h2>
                        Staff &amp; Providers
                    </h2>

                    <p>
                        Boulevard staff records available to
                        the RUMA account.
                    </p>

                </div>

                <span class="status-pill">

                    <?= e(
                        number_format(
                            count(
                                $staff
                            )
                        )
                    ) ?>

                    records

                </span>

            </div>


            <?php if ($staff): ?>

                <div class="bl-live-table-wrap">

                    <table class="bl-live-table">

                        <thead>

                            <tr>

                                <th>
                                    Name
                                </th>

                                <th>
                                    Role
                                </th>

                                <th>
                                    Locations
                                </th>

                                <th>
                                    Bookable
                                </th>

                                <th>
                                    Status
                                </th>

                            </tr>

                        </thead>


                        <tbody>

                        <?php foreach (
                            $staff
                            as $member
                        ): ?>


                            <?php

                            $memberLocations =
                                [];


                            foreach (
                                (
                                    $member[
                                        'locations'
                                    ]
                                    ?? []
                                )
                                as $memberLocation
                            ) {

                                if (
                                    !empty(
                                        $memberLocation[
                                            'name'
                                        ]
                                    )
                                ) {

                                    $memberLocations[] =
                                        (string)$memberLocation[
                                            'name'
                                        ];
                                }
                            }

                            ?>


                            <tr>

                                <td class="bl-live-primary-cell">

                                    <strong>

                                        <?= e(
                                            (string)(
                                                $member[
                                                    'displayName'
                                                ]
                                                ??
                                                $member[
                                                    'name'
                                                ]
                                                ??
                                                '—'
                                            )
                                        ) ?>

                                    </strong>

                                    <small>

                                        <?= e(
                                            (string)(
                                                $member[
                                                    'id'
                                                ]
                                                ??
                                                ''
                                            )
                                        ) ?>

                                    </small>

                                </td>


                                <td>

                                    <?= e(
                                        (string)(
                                            $member[
                                                'role'
                                            ][
                                                'name'
                                            ]
                                            ??
                                            '—'
                                        )
                                    ) ?>

                                </td>


                                <td>

                                    <?= e(
                                        $memberLocations
                                            ? implode(
                                                ', ',
                                                $memberLocations
                                            )
                                            : '—'
                                    ) ?>

                                </td>


                                <td>

                                    <?= !empty(
                                        $member[
                                            'externallyBookable'
                                        ]
                                    )
                                        ? 'Yes'
                                        : 'No'
                                    ?>

                                </td>


                                <td>

                                    <?php if (
                                        !isset(
                                            $member[
                                                'active'
                                            ]
                                        )
                                        ||
                                        !empty(
                                            $member[
                                                'active'
                                            ]
                                        )
                                    ): ?>

                                        <span class="bl-live-chip bl-live-chip-success">
                                            Active
                                        </span>

                                    <?php else: ?>

                                        <span class="bl-live-chip bl-live-chip-neutral">
                                            Inactive
                                        </span>

                                    <?php endif; ?>

                                </td>

                            </tr>


                        <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php else: ?>

                <div class="bl-live-empty">

                    <h3>
                        Staff data unavailable
                    </h3>

                    <p>
                        Boulevard did not return staff records
                        during this fetch.
                    </p>

                </div>

            <?php endif; ?>

        </section>



        <!-- =====================================================
             SERVICES
             ===================================================== -->

        <section class="content-card bl-live-section">

            <div class="card-head">

                <div>

                    <p class="eyebrow">
                        Reference Data
                    </p>

                    <h2>
                        Services
                    </h2>

                    <p>
                        Services currently returned by
                        Boulevard for RUMA.
                    </p>

                </div>

                <span class="status-pill">

                    <?= e(
                        number_format(
                            count(
                                $services
                            )
                        )
                    ) ?>

                    records

                </span>

            </div>


            <?php if ($services): ?>

                <div class="bl-live-table-wrap">

                    <table class="bl-live-table">

                        <thead>

                            <tr>

                                <th>
                                    Service
                                </th>

                                <th>
                                    Category
                                </th>

                                <th>
                                    Status
                                </th>

                            </tr>

                        </thead>


                        <tbody>

                        <?php foreach (
                            $services
                            as $service
                        ): ?>

                            <tr>

                                <td class="bl-live-primary-cell">

                                    <strong>

                                        <?= e(
                                            (string)(
                                                $service[
                                                    'name'
                                                ]
                                                ?? '—'
                                            )
                                        ) ?>

                                    </strong>

                                    <small>

                                        <?= e(
                                            (string)(
                                                $service[
                                                    'id'
                                                ]
                                                ?? ''
                                            )
                                        ) ?>

                                    </small>

                                </td>


                                <td>

                                    <?= e(
                                        (string)(
                                            $service[
                                                'category'
                                            ][
                                                'name'
                                            ]
                                            ??
                                            '—'
                                        )
                                    ) ?>

                                </td>


                                <td>

                                    <?php if (
                                        !isset(
                                            $service[
                                                'active'
                                            ]
                                        )
                                        ||
                                        !empty(
                                            $service[
                                                'active'
                                            ]
                                        )
                                    ): ?>

                                        <span class="bl-live-chip bl-live-chip-success">
                                            Active
                                        </span>

                                    <?php else: ?>

                                        <span class="bl-live-chip bl-live-chip-neutral">
                                            Inactive
                                        </span>

                                    <?php endif; ?>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php else: ?>

                <div class="bl-live-empty">

                    <h3>
                        Services unavailable
                    </h3>

                    <p>
                        Boulevard did not return service records
                        during this fetch.
                    </p>

                </div>

            <?php endif; ?>

        </section>


    <?php endif; ?>


</div>