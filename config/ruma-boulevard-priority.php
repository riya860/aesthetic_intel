<?php

return [
    // Scores are deliberately business-facing rather than API-facing.
    'priority_order' => [
        'revenue'       => 100,
        'appointments'  => 95,
        'providers'     => 90,
        'utilization'   => 88,
        'memberships'   => 84,
        'clients'       => 80,
        'retail'        => 70,
        'reference'     => 20,
    ],

    'thresholds' => [
        'cancellation_rate_high' => 0.15,
        'utilization_low'        => 0.60,
        'refund_rate_high'       => 0.08,
        'revenue_drop_high'      => -0.10,
        'appointment_drop_high'  => -0.10,
        'mrr_drop_high'          => -0.05,
    ],
];
