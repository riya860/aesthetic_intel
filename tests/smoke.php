<?php
require __DIR__ . '/../app/services/RumaBoulevardAnalytics.php';

$source = [
    'period_start' => '2026-09-01',
    'period_end' => '2026-09-07',
    'appointments' => [
        [
            'id' => 'a1', 'startAt' => '2026-09-01T10:00:00Z', 'status' => 'COMPLETED',
            'client' => ['id' => 'c1', 'appointmentCount' => 1, 'createdAt' => '2026-09-01T08:00:00Z'],
            'appointmentServices' => [[
                'duration' => 60, 'price' => 25000, 'staffRequested' => true,
                'staff' => ['name' => 'Alex'], 'service' => ['name' => 'Facial']
            ]]
        ],
        [
            'id' => 'a2', 'startAt' => '2026-09-02T12:00:00Z', 'cancelled' => true, 'status' => 'CANCELLED',
            'client' => ['id' => 'c2', 'appointmentCount' => 3],
            'appointmentServices' => [[
                'duration' => 30, 'price' => 12000, 'staffRequested' => false,
                'staff' => ['name' => 'Alex'], 'service' => ['name' => 'Peel']
            ]]
        ],
    ],
    'orders' => [[
        'id' => 'o1', 'number' => '1001', 'closedAt' => '2026-09-01T11:00:00Z',
        'summary' => [
            'currentSubtotal' => 30000,
            'currentDiscountAmount' => 1000,
            'currentTaxAmount' => 2400,
            'currentGratuityAmount' => 5000,
            'currentFeeAmount' => 300,
            'refundAmount' => 2000,
            'currentTotal' => 35700,
            'initialTotal' => 37700,
        ],
        'paymentGroups' => [['totalPaid' => 35700, 'totalFees' => 300]],
        'lineGroups' => [[
            'lines' => [
                ['__typename' => 'OrderServiceLine', 'currentSubtotal' => 25000, 'staff' => ['name' => 'Alex'], 'name' => 'Facial'],
                ['__typename' => 'OrderProductLine', 'currentSubtotal' => 5000, 'seller' => ['name' => 'Alex'], 'name' => 'Serum'],
            ]
        ]]
    ]],
    'shifts' => [[
        'staff' => ['name' => 'Alex'], 'startAt' => '2026-09-01T09:00:00Z', 'endAt' => '2026-09-01T17:00:00Z'
    ]],
    'memberships' => [[
        'id' => 'm1', 'status' => 'ACTIVE', 'unitPrice' => 19900, 'interval' => 'MONTH', 'startOn' => '2026-01-01', 'endOn' => '2026-12-31'
    ]],
];

$service = new RumaBoulevardAnalytics($source, ['money_divisor' => 100]);
$result = $service->build();

assert(abs($result['performance']['total_revenue']['value'] - 357.00) < 0.001);
assert($result['performance']['total_appointments']['value'] === 2);
assert($result['performance']['new_clients']['value'] === 1);
assert(abs($result['sales_summary']['service_revenue']['value'] - 250.00) < 0.001);
assert(abs($result['sales_summary']['product_revenue']['value'] - 50.00) < 0.001);
assert(abs($result['membership']['current_active_mrr']['value'] - 199.00) < 0.001);
assert($result['provider_details'][0]['provider'] === 'Alex');

echo "Smoke test passed\n";
