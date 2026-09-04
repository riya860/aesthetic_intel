<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| AESTHETIC INTEL - BOULEVARD API TOOL
|--------------------------------------------------------------------------
|
| Business:
| RUMA Medical
|
| Purpose:
| Display live Boulevard operational data inside Aesthetic Intel.
|
| No patient/client-identifiable data is retrieved.
|
|--------------------------------------------------------------------------
*/


/*
|--------------------------------------------------------------------------
| LOAD BOULEVARD CONFIGURATION
|--------------------------------------------------------------------------
*/

$config =
    require __DIR__
    . '/app/private/boulevard-secrets.php';


/*
|--------------------------------------------------------------------------
| LOAD BOULEVARD SERVICES
|--------------------------------------------------------------------------
*/

require_once __DIR__
    . '/app/Services/Boulevard/BoulevardClient.php';

require_once __DIR__
    . '/app/Services/Boulevard/BoulevardService.php';


/*
|--------------------------------------------------------------------------
| HELPERS
|--------------------------------------------------------------------------
*/

function blvd_h(
    mixed $value
): string {

    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}


function blvd_money(
    int|float|string|null $cents
): string {

    $value =
        (int) ($cents ?? 0);

    return '$'
        . number_format(
            $value / 100,
            2
        );
}


function blvd_format_datetime(
    ?string $value,
    DateTimeZone $timezone
): string {

    if (!$value) {
        return '—';
    }

    try {

        $date =
            new DateTimeImmutable(
                $value
            );

        $date =
            $date->setTimezone(
                $timezone
            );

        return $date->format(
            'M j, Y g:i A'
        );

    } catch (Throwable) {

        return $value;
    }
}


/*
|--------------------------------------------------------------------------
| DEFAULT STATE
|--------------------------------------------------------------------------
*/

$fetchRequested =
    isset($_GET['fetch'])
    && $_GET['fetch'] === '1';


$timezoneName =
    $config['location_timezone']
    ?? 'America/Denver';


try {

    $locationTimezone =
        new DateTimeZone(
            $timezoneName
        );

} catch (Throwable) {

    $locationTimezone =
        new DateTimeZone(
            'America/Denver'
        );
}


/*
|--------------------------------------------------------------------------
| DEFAULT DATE RANGE
|--------------------------------------------------------------------------
|
| Default:
| Last seven calendar days including today.
|
*/

$today =
    new DateTimeImmutable(
        'today',
        $locationTimezone
    );


$defaultFrom =
    $today
        ->modify('-6 days')
        ->format('Y-m-d');


$defaultTo =
    $today
        ->format('Y-m-d');


$fromInput =
    isset($_GET['from'])
        ? trim((string) $_GET['from'])
        : $defaultFrom;


$toInput =
    isset($_GET['to'])
        ? trim((string) $_GET['to'])
        : $defaultTo;


/*
|--------------------------------------------------------------------------
| RESULT STATE
|--------------------------------------------------------------------------
*/

$business = null;

$locations = [];

$staff = [];

$services = [];

$appointments = [];

$orders = [];

$warnings = [];

$fatalError = null;

$connectionVerified = false;


/*
|--------------------------------------------------------------------------
| METRICS
|--------------------------------------------------------------------------
*/

$metrics = [

    'appointments' => 0,

    'cancelled' => 0,

    'completed' => 0,

    'active_services' => 0,

    'active_staff' => 0,

    'orders' => 0,

    'revenue_cents' => 0,

    'refund_cents' => 0,
];


$providerSummary = [];


/*
|--------------------------------------------------------------------------
| LIVE BOULEVARD FETCH
|--------------------------------------------------------------------------
*/

if ($fetchRequested) {

    try {

        /*
        |--------------------------------------------------------------------------
        | VALIDATE DATE RANGE
        |--------------------------------------------------------------------------
        */

        $from =
            DateTimeImmutable::createFromFormat(
                '!Y-m-d',
                $fromInput,
                $locationTimezone
            );


        $to =
            DateTimeImmutable::createFromFormat(
                '!Y-m-d',
                $toInput,
                $locationTimezone
            );


        if (
            !$from ||
            !$to
        ) {

            throw new RuntimeException(
                'Please select a valid date range.'
            );
        }


        if (
            $from->getTimestamp()
            >
            $to->getTimestamp()
        ) {

            throw new RuntimeException(
                'The From date cannot be later than the To date.'
            );
        }


        /*
         * Protect the live tool from accidentally requesting
         * very large periods.
         */
        $days =
            (int) $from
                ->diff($to)
                ->format('%a');


        if ($days > 90) {

            throw new RuntimeException(
                'Please select a date range of 90 days or less.'
            );
        }


        /*
         * Boulevard filter uses an exclusive upper boundary.
         *
         * If user selects:
         *
         * Sep 1 → Sep 7
         *
         * API receives:
         *
         * >= Sep 1 00:00
         * <  Sep 8 00:00
         */
        $toExclusive =
            $to->modify(
                '+1 day'
            );


        /*
        |--------------------------------------------------------------------------
        | CREATE CLIENT
        |--------------------------------------------------------------------------
        */

        $client =
            new BoulevardClient(
                $config
            );


        /*
        |--------------------------------------------------------------------------
        | CREATE DATA SERVICE
        |--------------------------------------------------------------------------
        */

        $boulevard =
            new BoulevardService(
                $client
            );


        /*
        |--------------------------------------------------------------------------
        | VERIFY BUSINESS
        |--------------------------------------------------------------------------
        |
        | This ensures these credentials actually belong
        | to the RUMA Boulevard business.
        |
        */

        $business =
            $boulevard->verifyBusiness(
                $config['business_id']
            );


        $connectionVerified =
            true;


        /*
        |--------------------------------------------------------------------------
        | LOCATIONS
        |--------------------------------------------------------------------------
        */

        try {

            $locations =
                $boulevard
                    ->getLocations();

        } catch (Throwable $e) {

            $warnings[] =
                'Location information could not be refreshed.';

            error_log(
                '[Boulevard Tool / Locations] '
                . $e->getMessage()
            );
        }


        /*
        |--------------------------------------------------------------------------
        | STAFF
        |--------------------------------------------------------------------------
        */

        try {

            $staff =
                $boulevard
                    ->getStaff();


            foreach ($staff as $member) {

                if (
                    !isset(
                        $member['active']
                    )
                    ||
                    $member['active'] === true
                ) {

                    $metrics['active_staff']++;
                }
            }

        } catch (Throwable $e) {

            $warnings[] =
                'Provider/staff data is temporarily unavailable.';

            error_log(
                '[Boulevard Tool / Staff] '
                . $e->getMessage()
            );
        }


        /*
        |--------------------------------------------------------------------------
        | SERVICES
        |--------------------------------------------------------------------------
        */

        try {

            $services =
                $boulevard
                    ->getServices();


            foreach ($services as $service) {

                if (
                    !isset(
                        $service['active']
                    )
                    ||
                    $service['active'] === true
                ) {

                    $metrics[
                        'active_services'
                    ]++;
                }
            }

        } catch (Throwable $e) {

            $warnings[] =
                'Services are temporarily unavailable.';

            error_log(
                '[Boulevard Tool / Services] '
                . $e->getMessage()
            );
        }


        /*
        |--------------------------------------------------------------------------
        | CREATE LOOKUP MAPS
        |--------------------------------------------------------------------------
        */

        $staffNames = [];

        foreach ($staff as $member) {

            $id =
                $member['id']
                ?? null;

            if (!$id) {
                continue;
            }

            $staffNames[$id] =
                $member['displayName']
                ??
                $member['name']
                ??
                'Unknown Provider';
        }


        $serviceNames = [];

        foreach ($services as $service) {

            $id =
                $service['id']
                ?? null;

            if (!$id) {
                continue;
            }

            $serviceNames[$id] =
                $service['name']
                ?? 'Unknown Service';
        }


        /*
        |--------------------------------------------------------------------------
        | APPOINTMENTS
        |--------------------------------------------------------------------------
        */

        try {

            $appointments =
                $boulevard
                    ->getAppointments(

                        $config[
                            'location_id'
                        ],

                        $from,

                        $toExclusive
                    );


            $metrics['appointments'] =
                count(
                    $appointments
                );


            foreach (
                $appointments
                as $appointment
            ) {

                /*
                 * Cancellation.
                 */
                if (
                    !empty(
                        $appointment[
                            'cancelled'
                        ]
                    )
                ) {

                    $metrics[
                        'cancelled'
                    ]++;
                }


                /*
                 * Completed.
                 */
                $state =
                    strtolower(
                        (string) (
                            $appointment[
                                'state'
                            ]
                            ?? ''
                        )
                    );


                if (
                    $state ===
                    'completed'
                ) {

                    $metrics[
                        'completed'
                    ]++;
                }


                /*
                 * Build provider summary using
                 * appointment services.
                 */
                foreach (
                    $appointment[
                        'appointmentServices'
                    ]
                    ?? []
                    as $appointmentService
                ) {

                    $staffId =
                        $appointmentService[
                            'staffId'
                        ]
                        ?? null;


                    if (!$staffId) {
                        continue;
                    }


                    if (
                        !isset(
                            $providerSummary[
                                $staffId
                            ]
                        )
                    ) {

                        $providerSummary[
                            $staffId
                        ] = [

                            'name' =>
                                $staffNames[
                                    $staffId
                                ]
                                ??
                                'Unknown Provider',

                            'appointments' =>
                                0,

                            /*
                             * This is booked service value,
                             * NOT finalized revenue.
                             */
                            'booked_value_cents' =>
                                0,
                        ];
                    }


                    $providerSummary[
                        $staffId
                    ][
                        'appointments'
                    ]++;


                    $providerSummary[
                        $staffId
                    ][
                        'booked_value_cents'
                    ] +=
                        (int) (
                            $appointmentService[
                                'price'
                            ]
                            ?? 0
                        );
                }
            }

        } catch (Throwable $e) {

            $warnings[] =
                'Appointment data is temporarily unavailable.';

            error_log(
                '[Boulevard Tool / Appointments] '
                . $e->getMessage()
            );
        }


        /*
        |--------------------------------------------------------------------------
        | ORDERS / REVENUE
        |--------------------------------------------------------------------------
        */

        try {

            $orders =
                $boulevard
                    ->getOrders(

                        $config[
                            'location_id'
                        ],

                        $from,

                        $toExclusive
                    );


            $metrics['orders'] =
                count(
                    $orders
                );


            foreach (
                $orders
                as $order
            ) {

                $summary =
                    $order[
                        'summary'
                    ]
                    ?? [];


                /*
                 * Boulevard currentTotal represents
                 * the order total after refunds.
                 */
                $metrics[
                    'revenue_cents'
                ] +=
                    (int) (
                        $summary[
                            'currentTotal'
                        ]
                        ?? 0
                    );


                $metrics[
                    'refund_cents'
                ] +=
                    (int) (
                        $summary[
                            'refundAmount'
                        ]
                        ?? 0
                    );
            }

        } catch (Throwable $e) {

            $warnings[] =
                'Order/revenue data is temporarily unavailable.';

            error_log(
                '[Boulevard Tool / Orders] '
                . $e->getMessage()
            );
        }


        /*
         * Sort providers by appointment count.
         */
        uasort(
            $providerSummary,

            static function (
                array $a,
                array $b
            ): int {

                return
                    $b['appointments']
                    <=>
                    $a['appointments'];
            }
        );


    } catch (Throwable $e) {

        $fatalError =
            $e->getMessage();


        error_log(
            '[Boulevard Tool] '
            . $e->getMessage()
        );
    }
}


/*
|--------------------------------------------------------------------------
| UI
|--------------------------------------------------------------------------
*/
?>

<style>

.blvd-tool {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.blvd-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 20px;
    flex-wrap: wrap;
}

.blvd-title {
    margin: 0;
    font-size: 28px;
    font-weight: 700;
}

.blvd-subtitle {
    margin: 6px 0 0;
    color: #6b7280;
}

.blvd-status {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 8px 12px;
    border-radius: 999px;
    font-size: 13px;
    font-weight: 600;
    background: #f3f4f6;
}

.blvd-status.success {
    background: #ecfdf5;
    color: #047857;
}

.blvd-status.error {
    background: #fef2f2;
    color: #b91c1c;
}

.blvd-filter {
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 16px;
    padding: 18px;
}

.blvd-filter-form {
    display: flex;
    align-items: flex-end;
    flex-wrap: wrap;
    gap: 14px;
}

.blvd-field {
    min-width: 180px;
}

.blvd-field label {
    display: block;
    margin-bottom: 6px;
    font-size: 12px;
    font-weight: 600;
    color: #6b7280;
}

.blvd-field input {
    width: 100%;
    box-sizing: border-box;
    padding: 10px 12px;
    border: 1px solid #d1d5db;
    border-radius: 10px;
    background: #ffffff;
}

.blvd-button {
    border: 0;
    border-radius: 10px;
    padding: 11px 18px;
    font-weight: 600;
    cursor: pointer;
    background: #111827;
    color: #ffffff;
}

.blvd-grid {
    display: grid;
    grid-template-columns:
        repeat(
            auto-fit,
            minmax(180px, 1fr)
        );
    gap: 14px;
}

.blvd-card {
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 16px;
    padding: 18px;
}

.blvd-label {
    margin-bottom: 8px;
    color: #6b7280;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .04em;
}

.blvd-value {
    font-size: 26px;
    font-weight: 700;
}

.blvd-meta {
    margin-top: 6px;
    color: #9ca3af;
    font-size: 12px;
}

.blvd-section {
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 16px;
    overflow: hidden;
}

.blvd-section-header {
    display: flex;
    justify-content: space-between;
    gap: 10px;
    padding: 16px 18px;
    border-bottom: 1px solid #e5e7eb;
}

.blvd-section-title {
    margin: 0;
    font-size: 16px;
}

.blvd-table-wrap {
    overflow-x: auto;
}

.blvd-table {
    width: 100%;
    border-collapse: collapse;
}

.blvd-table th,
.blvd-table td {
    padding: 12px 16px;
    text-align: left;
    border-bottom: 1px solid #f3f4f6;
    white-space: nowrap;
}

.blvd-table th {
    color: #6b7280;
    font-size: 12px;
    font-weight: 600;
}

.blvd-table td {
    font-size: 14px;
}

.blvd-alert {
    padding: 14px 16px;
    border-radius: 12px;
}

.blvd-alert.error {
    background: #fef2f2;
    color: #991b1b;
}

.blvd-alert.warning {
    background: #fffbeb;
    color: #92400e;
}

.blvd-empty {
    padding: 30px;
    text-align: center;
    color: #6b7280;
}

</style>


<div class="blvd-tool">

    <div class="blvd-header">

        <div>

            <h1 class="blvd-title">
                Boulevard API
            </h1>

            <p class="blvd-subtitle">
                Live operational data for RUMA Medical
                · Lehi
            </p>

        </div>


        <?php if ($connectionVerified): ?>

            <div class="blvd-status success">
                ● Connected to Boulevard
            </div>

        <?php elseif ($fatalError): ?>

            <div class="blvd-status error">
                ● Connection Error
            </div>

        <?php else: ?>

            <div class="blvd-status">
                ● Ready
            </div>

        <?php endif; ?>

    </div>


    <!-- FILTER -->

    <div class="blvd-filter">

        <form
            method="get"
            class="blvd-filter-form"
        >

            <!--
                Keep the existing Aesthetic Intel
                router on this page.
            -->
            <input
                type="hidden"
                name="page"
                value="boulevard-api"
            >

            <input
                type="hidden"
                name="fetch"
                value="1"
            >


            <div class="blvd-field">

                <label>
                    From
                </label>

                <input
                    type="date"
                    name="from"
                    value="<?= blvd_h(
                        $fromInput
                    ) ?>"
                    required
                >

            </div>


            <div class="blvd-field">

                <label>
                    To
                </label>

                <input
                    type="date"
                    name="to"
                    value="<?= blvd_h(
                        $toInput
                    ) ?>"
                    required
                >

            </div>


            <button
                type="submit"
                class="blvd-button"
            >
                Fetch Boulevard Data
            </button>

        </form>

    </div>


    <?php if ($fatalError): ?>

        <div class="blvd-alert error">

            Boulevard data could not be loaded.

            <?php
            /*
             * During local development you may temporarily
             * display $fatalError if required.
             *
             * Do NOT show raw API errors in production.
             */
            ?>

        </div>

    <?php endif; ?>


    <?php foreach ($warnings as $warning): ?>

        <div class="blvd-alert warning">
            <?= blvd_h(
                $warning
            ) ?>
        </div>

    <?php endforeach; ?>


    <?php if (!$fetchRequested): ?>

        <div class="blvd-empty">

            Select a date range and click
            <strong>
                Fetch Boulevard Data
            </strong>
            to retrieve live RUMA metrics.

        </div>

    <?php elseif (
        $connectionVerified
    ): ?>


        <!-- KPI CARDS -->

        <div class="blvd-grid">


            <div class="blvd-card">

                <div class="blvd-label">
                    Appointments
                </div>

                <div class="blvd-value">

                    <?= number_format(
                        $metrics[
                            'appointments'
                        ]
                    ) ?>

                </div>

                <div class="blvd-meta">
                    Selected period
                </div>

            </div>


            <div class="blvd-card">

                <div class="blvd-label">
                    Completed
                </div>

                <div class="blvd-value">

                    <?= number_format(
                        $metrics[
                            'completed'
                        ]
                    ) ?>

                </div>

            </div>


            <div class="blvd-card">

                <div class="blvd-label">
                    Cancelled
                </div>

                <div class="blvd-value">

                    <?= number_format(
                        $metrics[
                            'cancelled'
                        ]
                    ) ?>

                </div>

            </div>


            <div class="blvd-card">

                <div class="blvd-label">
                    Net Order Total
                </div>

                <div class="blvd-value">

                    <?= blvd_money(
                        $metrics[
                            'revenue_cents'
                        ]
                    ) ?>

                </div>

                <div class="blvd-meta">
                    After refunds
                </div>

            </div>


            <div class="blvd-card">

                <div class="blvd-label">
                    Refunds
                </div>

                <div class="blvd-value">

                    <?= blvd_money(
                        $metrics[
                            'refund_cents'
                        ]
                    ) ?>

                </div>

            </div>


            <div class="blvd-card">

                <div class="blvd-label">
                    Orders
                </div>

                <div class="blvd-value">

                    <?= number_format(
                        $metrics[
                            'orders'
                        ]
                    ) ?>

                </div>

            </div>


            <div class="blvd-card">

                <div class="blvd-label">
                    Active Providers
                </div>

                <div class="blvd-value">

                    <?= number_format(
                        $metrics[
                            'active_staff'
                        ]
                    ) ?>

                </div>

            </div>


            <div class="blvd-card">

                <div class="blvd-label">
                    Active Services
                </div>

                <div class="blvd-value">

                    <?= number_format(
                        $metrics[
                            'active_services'
                        ]
                    ) ?>

                </div>

            </div>

        </div>


        <!-- CONNECTION DETAILS -->

        <div class="blvd-section">

            <div class="blvd-section-header">

                <h2 class="blvd-section-title">
                    Boulevard Connection
                </h2>

            </div>


            <div class="blvd-table-wrap">

                <table class="blvd-table">

                    <tbody>

                        <tr>

                            <th>
                                Business
                            </th>

                            <td>
                                <?= blvd_h(
                                    $business[
                                        'name'
                                    ]
                                    ?? 'RUMA Medical'
                                ) ?>
                            </td>

                        </tr>


                        <tr>

                            <th>
                                Location
                            </th>

                            <td>
                                <?= blvd_h(
                                    $config[
                                        'location_name'
                                    ]
                                    ?? 'Lehi'
                                ) ?>
                            </td>

                        </tr>


                        <tr>

                            <th>
                                Location Timezone
                            </th>

                            <td>
                                <?= blvd_h(
                                    $timezoneName
                                ) ?>
                            </td>

                        </tr>


                        <tr>

                            <th>
                                Date Range
                            </th>

                            <td>
                                <?= blvd_h(
                                    $fromInput
                                ) ?>

                                →

                                <?= blvd_h(
                                    $toInput
                                ) ?>
                            </td>

                        </tr>

                    </tbody>

                </table>

            </div>

        </div>


        <!-- PROVIDER PERFORMANCE -->

        <div class="blvd-section">

            <div class="blvd-section-header">

                <h2 class="blvd-section-title">
                    Provider Activity
                </h2>

                <span>
                    <?= count(
                        $providerSummary
                    ) ?>
                    providers
                </span>

            </div>


            <?php if (
                !empty(
                    $providerSummary
                )
            ): ?>

                <div class="blvd-table-wrap">

                    <table class="blvd-table">

                        <thead>

                            <tr>

                                <th>
                                    Provider
                                </th>

                                <th>
                                    Services / Appointments
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

                                <td>
                                    <?= blvd_h(
                                        $provider[
                                            'name'
                                        ]
                                    ) ?>
                                </td>

                                <td>
                                    <?= number_format(
                                        $provider[
                                            'appointments'
                                        ]
                                    ) ?>
                                </td>

                                <td>
                                    <?= blvd_money(
                                        $provider[
                                            'booked_value_cents'
                                        ]
                                    ) ?>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php else: ?>

                <div class="blvd-empty">
                    No provider activity returned
                    for this period.
                </div>

            <?php endif; ?>

        </div>


        <!-- APPOINTMENTS -->

        <div class="blvd-section">

            <div class="blvd-section-header">

                <h2 class="blvd-section-title">
                    Recent Appointments
                </h2>

                <span>
                    <?= count(
                        $appointments
                    ) ?>
                    records
                </span>

            </div>


            <?php if (
                !empty(
                    $appointments
                )
            ): ?>

                <div class="blvd-table-wrap">

                    <table class="blvd-table">

                        <thead>

                            <tr>

                                <th>
                                    Date
                                </th>

                                <th>
                                    Status
                                </th>

                                <th>
                                    Services
                                </th>

                            </tr>

                        </thead>


                        <tbody>

                        <?php foreach (
                            array_slice(
                                $appointments,
                                0,
                                50
                            )
                            as $appointment
                        ): ?>

                            <tr>

                                <td>

                                    <?= blvd_h(
                                        blvd_format_datetime(
                                            $appointment[
                                                'startAt'
                                            ]
                                            ?? null,
                                            $locationTimezone
                                        )
                                    ) ?>

                                </td>


                                <td>

                                    <?php if (
                                        !empty(
                                            $appointment[
                                                'cancelled'
                                            ]
                                        )
                                    ): ?>

                                        Cancelled

                                    <?php else: ?>

                                        <?= blvd_h(
                                            $appointment[
                                                'state'
                                            ]
                                            ?? 'Active'
                                        ) ?>

                                    <?php endif; ?>

                                </td>


                                <td>

                                    <?= count(
                                        $appointment[
                                            'appointmentServices'
                                        ]
                                        ?? []
                                    ) ?>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php else: ?>

                <div class="blvd-empty">
                    No appointments returned for this period.
                </div>

            <?php endif; ?>

        </div>


        <!-- SERVICES -->

        <div class="blvd-section">

            <div class="blvd-section-header">

                <h2 class="blvd-section-title">
                    Services
                </h2>

                <span>
                    <?= count(
                        $services
                    ) ?>
                    records
                </span>

            </div>


            <?php if (
                !empty(
                    $services
                )
            ): ?>

                <div class="blvd-table-wrap">

                    <table class="blvd-table">

                        <thead>

                            <tr>

                                <th>
                                    Service
                                </th>

                                <th>
                                    Category
                                </th>

                                <th>
                                    Default Price
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

                                <td>
                                    <?= blvd_h(
                                        $service[
                                            'name'
                                        ]
                                        ?? '—'
                                    ) ?>
                                </td>


                                <td>
                                    <?= blvd_h(
                                        $service[
                                            'category'
                                        ][
                                            'name'
                                        ]
                                        ?? '—'
                                    ) ?>
                                </td>


                                <td>
                                    <?= blvd_money(
                                        $service[
                                            'defaultPrice'
                                        ]
                                        ?? 0
                                    ) ?>
                                </td>


                                <td>

                                    <?= !empty(
                                        $service[
                                            'active'
                                        ]
                                    )
                                        ? 'Active'
                                        : 'Inactive'
                                    ?>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php else: ?>

                <div class="blvd-empty">
                    Services were not returned.
                </div>

            <?php endif; ?>

        </div>


    <?php endif; ?>

</div>