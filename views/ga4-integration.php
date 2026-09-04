<?php

$connected =
    $connection &&
    ($connection['status'] ?? '') === 'connected';

$summary =
    $testResult['data']['summary']
    ?? [];

$channels =
    $testResult['data']['channels']
    ?? [];
?>

<section class="page-head">
    <div>
        <p class="eyebrow">Integration</p>

        <h1>Google Analytics 4</h1>

        <p>
            Connect <?= e($business['name']) ?>
            to its Google Analytics property.
        </p>
    </div>
</section>


<div class="card">

    <h2>Connection</h2>

    <?php if (!$connected): ?>

        <p>
            Google Analytics is not currently connected
            for this business.
        </p>

        <form
            method="post"
            action="<?= e(url('business-ga4-connect')) ?>"
        >

            <?= csrf_field() ?>

            <div class="form-group">
                <label for="property_id">
                    GA4 Property ID
                </label>

                <input
                    id="property_id"
                    name="property_id"
                    type="text"
                    inputmode="numeric"
                    required
                    placeholder="123456789"
                >

                <small>
                    Enter the numeric GA4 Property ID,
                    not the G-XXXXXXXX Measurement ID.
                </small>
            </div>


            <div class="form-group">
                <label for="property_name">
                    Property Name
                </label>

                <input
                    id="property_name"
                    name="property_name"
                    type="text"
                    value="Brospro Official Website"
                    placeholder="Brospro Official Website"
                >
            </div>


            <button
                type="submit"
                class="btn btn-primary"
            >
                Connect Google Analytics
            </button>

        </form>

    <?php else: ?>

        <div class="integration-status">

            <p>
                <strong>Status:</strong>
                Connected
            </p>

            <p>
                <strong>GA4 Property ID:</strong>
                <?= e($connection['property_id']) ?>
            </p>

            <?php if (!empty($connection['property_name'])): ?>
                <p>
                    <strong>Property:</strong>
                    <?= e($connection['property_name']) ?>
                </p>
            <?php endif; ?>

            <?php if (!empty($connection['connected_at'])): ?>
                <p>
                    <strong>Connected:</strong>
                    <?= e($connection['connected_at']) ?>
                </p>
            <?php endif; ?>

            <?php if (!empty($connection['last_sync_at'])): ?>
                <p>
                    <strong>Last API fetch:</strong>
                    <?= e($connection['last_sync_at']) ?>
                </p>
            <?php endif; ?>

        </div>

    <?php endif; ?>

</div>


<?php if ($connected): ?>

<div class="card">

    <h2>Test GA4 Data API</h2>

    <p>
        Fetch a small report and compare it with the
        Brospro Google Analytics dashboard for exactly
        the same dates.
    </p>

    <form
        method="post"
        action="<?= e(url('business-ga4-test')) ?>"
    >

        <?= csrf_field() ?>

        <div class="form-row">

            <div class="form-group">
                <label for="period_start">
                    Start Date
                </label>

                <input
                    id="period_start"
                    name="period_start"
                    type="date"
                    required
                    value="<?= e($defaultStart) ?>"
                >
            </div>


            <div class="form-group">
                <label for="period_end">
                    End Date
                </label>

                <input
                    id="period_end"
                    name="period_end"
                    type="date"
                    required
                    value="<?= e($defaultEnd) ?>"
                >
            </div>

        </div>


        <button
            type="submit"
            class="btn btn-primary"
        >
            Fetch Test Data
        </button>

    </form>

</div>


<?php if ($testResult): ?>

<div class="card">

    <h2>API Test Result</h2>

    <p>
        Property:
        <strong>
            <?= e($testResult['property_id'] ?? '') ?>
        </strong>
    </p>

    <p>
        Period:
        <strong>
            <?= e($testResult['period_start'] ?? '') ?>
            →
            <?= e($testResult['period_end'] ?? '') ?>
        </strong>
    </p>


    <h3>Summary</h3>

    <div class="table-wrap">

        <table>

            <thead>
                <tr>
                    <th>Metric</th>
                    <th>GA4 API Value</th>
                </tr>
            </thead>

            <tbody>

                <tr>
                    <td>Active Users</td>
                    <td>
                        <?= e(
                            $summary['active_users']
                            ?? 0
                        ) ?>
                    </td>
                </tr>

                <tr>
                    <td>New Users</td>
                    <td>
                        <?= e(
                            $summary['new_users']
                            ?? 0
                        ) ?>
                    </td>
                </tr>

                <tr>
                    <td>Sessions</td>
                    <td>
                        <?= e(
                            $summary['sessions']
                            ?? 0
                        ) ?>
                    </td>
                </tr>

                <tr>
                    <td>Engaged Sessions</td>
                    <td>
                        <?= e(
                            $summary['engaged_sessions']
                            ?? 0
                        ) ?>
                    </td>
                </tr>

                <tr>
                    <td>Engagement Rate</td>
                    <td>
                        <?php
                        $rate = (float) (
                            $summary['engagement_rate']
                            ?? 0
                        );

                        echo e(
                            number_format(
                                $rate * 100,
                                2
                            )
                            . '%'
                        );
                        ?>
                    </td>
                </tr>

                <tr>
                    <td>Page Views</td>
                    <td>
                        <?= e(
                            $summary['page_views']
                            ?? 0
                        ) ?>
                    </td>
                </tr>

            </tbody>

        </table>

    </div>


    <h3>Traffic Channels</h3>

    <?php if (!$channels): ?>

        <p>
            No channel data was returned for this period.
        </p>

    <?php else: ?>

        <div class="table-wrap">

            <table>

                <thead>
                    <tr>
                        <th>Channel</th>
                        <th>Sessions</th>
                        <th>Users</th>
                        <th>Engaged Sessions</th>
                    </tr>
                </thead>

                <tbody>

                    <?php foreach ($channels as $row): ?>

                        <tr>

                            <td>
                                <?= e(
                                    $row['channel']
                                    ?? ''
                                ) ?>
                            </td>

                            <td>
                                <?= e(
                                    $row['sessions']
                                    ?? 0
                                ) ?>
                            </td>

                            <td>
                                <?= e(
                                    $row['users']
                                    ?? 0
                                ) ?>
                            </td>

                            <td>
                                <?= e(
                                    $row['engaged_sessions']
                                    ?? 0
                                ) ?>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                </tbody>

            </table>

        </div>

    <?php endif; ?>

</div>

<?php endif; ?>


<div class="card">

    <h2>Disconnect</h2>

    <p>
        Disconnecting removes the saved OAuth connection.
        It does not delete previously imported analytics data.
    </p>

    <form
        method="post"
        action="<?= e(url('business-ga4-disconnect')) ?>"
    >

        <?= csrf_field() ?>

        <label>
            <input
                type="checkbox"
                name="confirm_disconnect"
                value="1"
                required
            >

            I understand that Aesthetic Intel will lose
            API access until Google Analytics is connected again.
        </label>

        <div style="margin-top:16px">

            <button
                type="submit"
                class="btn btn-danger"
            >
                Disconnect Google Analytics
            </button>

        </div>

    </form>

</div>

<?php endif; ?>