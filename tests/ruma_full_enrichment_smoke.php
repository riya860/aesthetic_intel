<?php

declare(strict_types=1);

require __DIR__ . '/../app/Services/RumaBoulevardAnalytics.php';

function check(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL - {$message}\n");
        exit(1);
    }
    echo "PASS - {$message}\n";
}

$staff = [
    ['id' => 'urn:blvd:Staff:1', 'displayName' => 'Shelby Miller', 'active' => true],
    ['id' => 'urn:blvd:Staff:2', 'displayName' => 'Tessa Brown', 'active' => true],
];
$services = [
    ['id' => 'urn:blvd:Service:1', 'name' => 'Injectable', 'active' => true],
];
$memberships = [
    [
        'id' => 'm1', 'locationId' => 'loc', 'name' => 'Glow Club',
        'productId' => 'prod-membership', 'startOn' => '2026-01-01',
        'endOn' => '2026-10-01', 'cancelOn' => null,
        'interval' => 'P1M', 'status' => 'ACTIVE', 'unitPrice' => 19900,
    ],
    [
        'id' => 'm2', 'locationId' => 'loc', 'name' => 'Premium Club',
        'productId' => 'prod-membership-2', 'startOn' => '2026-09-01',
        'endOn' => '2027-09-01', 'cancelOn' => null,
        'interval' => 'P1Y', 'status' => 'ACTIVE', 'unitPrice' => 120000,
    ],
];
$packages = [
    ['id' => 'pkg1', 'name' => 'Laser Package', 'product' => ['id' => 'prod-package', 'name' => 'Laser Package']],
];
$appointments = [
    [
        'id' => 'a1', 'startAt' => '2026-09-01T10:00:00Z',
        'cancelled' => false, 'state' => 'COMPLETED', 'clientId' => 'c1',
        'client' => ['appointmentCount' => 1, 'createdAt' => '2026-09-01T09:00:00Z'],
        'appointmentServices' => [[
            'serviceId' => 'urn:blvd:Service:1', 'staffId' => 'urn:blvd:Staff:1',
            'staffRequested' => true, 'price' => 50000, 'duration' => 60,
        ]],
    ],
    [
        'id' => 'a2', 'startAt' => '2026-09-01T12:00:00Z',
        'cancelled' => false, 'state' => 'COMPLETED', 'clientId' => 'c2',
        'client' => ['appointmentCount' => 5, 'createdAt' => '2025-01-01T09:00:00Z'],
        'appointmentServices' => [[
            'serviceId' => 'urn:blvd:Service:1', 'staffId' => 'urn:blvd:Staff:2',
            'staffRequested' => false, 'price' => 30000, 'duration' => 60,
        ]],
    ],
];
$previousAppointments = [[
    'id' => 'pa1', 'startAt' => '2026-08-26T10:00:00Z',
    'cancelled' => false, 'state' => 'COMPLETED', 'clientId' => 'pc1',
    'client' => ['appointmentCount' => 2, 'createdAt' => '2026-01-01T09:00:00Z'],
    'appointmentServices' => [[
        'serviceId' => 'urn:blvd:Service:1', 'staffId' => 'urn:blvd:Staff:1',
        'staffRequested' => false, 'price' => 25000, 'duration' => 60,
    ]],
]];
$orders = [[
    'id' => 'o1', 'number' => '1001', 'closedAt' => '2026-09-01T13:00:00Z',
    'summary' => [
        'currentSubtotal' => 83000, 'currentDiscountAmount' => 0,
        'currentTaxAmount' => 1000, 'currentGratuityAmount' => 5000,
        'currentFeeAmount' => 0, 'currentTotal' => 89000, 'refundAmount' => 0,
    ],
    'paymentGroups' => [['totalPaid' => 89000, 'totalFees' => 250]],
    'lineGroups' => [
        ['__typename' => 'OrderAppointmentLineGroup', 'lines' => [[
            '__typename' => 'OrderServiceLine', 'id' => 'l1',
            'currentSubtotal' => 50000, 'quantity' => 1,
            'service' => ['id' => 'urn:blvd:Service:1', 'name' => 'Injectable'],
            'providers' => [[
                'selected' => true,
                'staff' => ['id' => 'urn:blvd:Staff:1', 'displayName' => 'Shelby Miller'],
            ]],
        ]]],
        ['__typename' => 'OrderRetailLineGroup', 'lines' => [
            ['__typename' => 'OrderProductLine', 'id' => 'l2', 'currentSubtotal' => 10000, 'quantity' => 1, 'name' => 'Retail Serum', 'productId' => 'retail-1', 'seller' => ['displayName' => 'Shelby Miller']],
            ['__typename' => 'OrderProductLine', 'id' => 'l3', 'currentSubtotal' => 12000, 'quantity' => 1, 'name' => 'Glow Club', 'productId' => 'prod-membership', 'seller' => ['displayName' => 'Shelby Miller']],
            ['__typename' => 'OrderProductLine', 'id' => 'l4', 'currentSubtotal' => 8000, 'quantity' => 1, 'name' => 'Laser Package', 'productId' => 'prod-package', 'seller' => ['displayName' => 'Shelby Miller']],
            ['__typename' => 'OrderGiftCardLine', 'id' => 'l5', 'currentSubtotal' => 2000, 'quantity' => 1],
            ['__typename' => 'OrderAccountCreditLine', 'id' => 'l6', 'currentSubtotal' => 1000, 'quantity' => 1],
        ]],
    ],
]];
$previousOrders = [[
    'id' => 'po1', 'number' => '900', 'closedAt' => '2026-08-26T13:00:00Z',
    'summary' => [
        'currentSubtotal' => 40000, 'currentDiscountAmount' => 0,
        'currentTaxAmount' => 0, 'currentGratuityAmount' => 0,
        'currentFeeAmount' => 0, 'currentTotal' => 40000, 'refundAmount' => 0,
    ],
    'paymentGroups' => [['totalPaid' => 40000, 'totalFees' => 0]],
    'lineGroups' => [],
]];
$shifts = [
    ['id' => 's1', 'date' => '2026-09-01', 'startTime' => '09:00:00', 'endTime' => '17:00:00', 'available' => true, 'staffId' => 'urn:blvd:Staff:1', 'staff' => ['displayName' => 'Shelby Miller']],
    ['id' => 's2', 'date' => '2026-09-01', 'startTime' => '09:00:00', 'endTime' => '17:00:00', 'available' => true, 'staffId' => 'urn:blvd:Staff:2', 'staff' => ['displayName' => 'Tessa Brown']],
];
$previousShifts = [[
    'id' => 'ps1', 'date' => '2026-08-26', 'startTime' => '09:00:00', 'endTime' => '17:00:00',
    'available' => true, 'staffId' => 'urn:blvd:Staff:1', 'staff' => ['displayName' => 'Shelby Miller'],
]];

$capabilities = [
    'appointment_client_metadata' => true,
    'requested_staff' => true,
    'order_payments' => true,
    'order_service_lines' => true,
    'order_provider_attribution' => true,
    'order_retail_lines' => true,
    'shifts' => true,
    'memberships' => true,
    'packages' => true,
    'package_product_mapping' => true,
];

$analytics = (new RumaBoulevardAnalytics([
    'period_start' => '2026-09-01',
    'period_end' => '2026-09-07',
    'previous_period_start' => '2026-08-25',
    'previous_period_end' => '2026-08-31',
    'capabilities' => $capabilities,
    'appointments' => $appointments,
    'orders' => $orders,
    'staff' => $staff,
    'services' => $services,
    'shifts' => $shifts,
    'memberships' => $memberships,
    'packages' => $packages,
    'previous_appointments' => $previousAppointments,
    'previous_orders' => $previousOrders,
    'previous_shifts' => $previousShifts,
    'previous_memberships' => $memberships,
], ['money_divisor' => 100]))->build();

check($analytics['performance']['blended_utilization']['available'] === true, 'utilization is available from shifts');
check(abs(($analytics['performance']['blended_utilization']['value'] ?? 0) - 0.125) < 0.0001, 'blended utilization uses booked/scheduled minutes');
check($analytics['performance']['current_active_mrr']['available'] === true, 'MRR is available from memberships');
check(abs(($analytics['performance']['current_active_mrr']['value'] ?? 0) - 299.00) < 0.001, 'monthly/yearly membership intervals normalize to MRR');
check(abs(($analytics['performance']['current_active_mrr']['previous'] ?? 0) - 199.00) < 0.001, 'previous MRR is evaluated from membership lifecycle');
check(abs(($analytics['sales_summary']['service_revenue']['value'] ?? 0) - 500.00) < 0.001, 'service revenue is classified');
check(abs(($analytics['sales_summary']['product_revenue']['value'] ?? 0) - 100.00) < 0.001, 'retail product revenue is classified');
check(abs(($analytics['sales_summary']['membership_revenue']['value'] ?? 0) - 120.00) < 0.001, 'membership sale revenue is classified');
check(abs(($analytics['sales_summary']['package_revenue']['value'] ?? 0) - 80.00) < 0.001, 'package sale revenue is classified');
check(abs(($analytics['financial']['gift_cards_sold']['value'] ?? 0) - 20.00) < 0.001, 'gift card sale lines are classified');
check(abs(($analytics['financial']['account_credit_sold']['value'] ?? 0) - 10.00) < 0.001, 'account credit sale lines are classified');
check(abs(($analytics['financial']['gross_payments']['value'] ?? 0) - 890.00) < 0.001, 'gross payments come from paymentGroups.totalPaid');
check(abs(($analytics['financial']['card_fees']['value'] ?? 0) - 2.50) < 0.001, 'payment fees come from paymentGroups.totalFees');

$providerRows = $analytics['provider_details'] ?? [];
$shelby = null;
foreach ($providerRows as $row) {
    if (($row['provider'] ?? '') === 'Shelby Miller') {
        $shelby = $row;
        break;
    }
}
check(is_array($shelby), 'provider attribution resolves Shelby Miller');
check(abs(($shelby['service_revenue'] ?? 0) - 500.00) < 0.001, 'provider receives realized service revenue');
check(($shelby['utilization_available'] ?? false) === true, 'provider utilization is available');
check(($analytics['sales_summary']['requested_appointments']['value'] ?? null) === 1, 'requested appointments are counted from staffRequested');
check(($analytics['performance']['new_clients']['value'] ?? null) === 1, 'new clients are counted from minimal client metadata');

echo "Full-enrichment smoke test passed.\n";
