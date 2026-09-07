<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/Services/RumaBoulevardReportEnricher.php';

$unavailable = static fn(string $format): array => [
    'value' => null,
    'format' => $format,
    'available' => false,
    'source' => 'direct unavailable',
    'previous' => null,
    'change' => null,
];

$analytics = [
    'performance' => [
        'total_appointments' => ['value'=>10,'format'=>'number','available'=>true,'source'=>'appointments','previous'=>8,'change'=>.25],
        'new_clients' => $unavailable('number'),
        'blended_utilization' => $unavailable('percent'),
        'current_active_mrr' => $unavailable('currency'),
    ],
    'sales_summary' => [
        'service_revenue' => $unavailable('currency'),
        'product_revenue' => $unavailable('currency'),
        'membership_revenue' => $unavailable('currency'),
        'package_revenue' => $unavailable('currency'),
        'requested_appointments' => $unavailable('number'),
    ],
    'financial' => [
        'net_sales' => $unavailable('currency'),
        'gross_payments' => $unavailable('currency'),
        'refunds' => $unavailable('currency'),
        'gift_cards_sold' => $unavailable('currency'),
        'account_credit_sold' => $unavailable('currency'),
        'tax_collected' => $unavailable('currency'),
        'card_fees' => $unavailable('currency'),
    ],
    'membership' => [
        'current_active_mrr' => $unavailable('currency'),
        'current_arr' => $unavailable('currency'),
        'active_memberships' => $unavailable('number'),
        'trend' => [],
    ],
    'provider_details' => [],
    'provider_performance' => [],
    'revenue_per_scheduled_hour' => [],
    'revenue_mix' => [
        'service'=>0,'product'=>0,'membership'=>0,'package'=>0,'gratuity'=>0,
        'available_types'=>['service'=>false,'product'=>false,'membership'=>false,'package'=>false],
    ],
    'daily_performance' => [],
];

$report = [
    'kpis' => [
        'utilization' => ['value'=>72.5,'format'=>'percent','previous'=>65,'percent_change'=>11.538],
        'active_mrr' => ['value'=>25000,'format'=>'currency','previous'=>23000,'percent_change'=>8.6957],
        'active_arr' => ['value'=>300000,'format'=>'currency'],
        'active_memberships' => ['value'=>130,'format'=>'number'],
        'new_clients' => ['value'=>24,'format'=>'number'],
        'service_revenue' => ['value'=>222057.12,'format'=>'currency'],
        'product_revenue' => ['value'=>12000,'format'=>'currency'],
        'membership_revenue' => ['value'=>18000,'format'=>'currency'],
        'package_revenue' => ['value'=>9000,'format'=>'currency'],
        'requested_appointments' => ['value'=>240,'format'=>'number'],
        'net_sales' => ['value'=>324388.93,'format'=>'currency'],
        'gross_payments' => ['value'=>326257.90,'format'=>'currency'],
        'refunds' => ['value'=>1216.12,'format'=>'currency'],
        'gift_cards_sold' => ['value'=>5000,'format'=>'currency'],
        'account_credit_sold' => ['value'=>1200,'format'=>'currency'],
        'tax_collected' => ['value'=>851.76,'format'=>'currency'],
        'card_fees' => ['value'=>3400,'format'=>'currency'],
    ],
    'providers' => [[
        'name'=>'Shelby Miller','service_revenue'=>90000,'utilization'=>80,'hours_scheduled'=>120,
        'appointments'=>90,'new_clients'=>8,'revenue_per_hour'=>750,'product_revenue'=>3000,
    ]],
    'revenue_categories' => [
        ['label'=>'Services','value'=>222057.12],
        ['label'=>'All Products','value'=>12000],
        ['label'=>'Memberships','value'=>18000],
        ['label'=>'Packages','value'=>9000],
        ['label'=>'Tips','value'=>6144.21],
    ],
    'daily' => [['label'=>'Sep 1 2026','revenue'=>10000,'appointments'=>20]],
];

$result = RumaBoulevardReportEnricher::merge($analytics, $report);

$checks = [
    'utilization' => $result['performance']['blended_utilization']['available'] && abs($result['performance']['blended_utilization']['value'] - .725) < .0001,
    'mrr' => $result['performance']['current_active_mrr']['available'] && abs((float)$result['performance']['current_active_mrr']['value'] - 25000.0) < .0001,
    'sales mix' => $result['sales_summary']['product_revenue']['available'] && $result['sales_summary']['membership_revenue']['available'] && $result['sales_summary']['package_revenue']['available'],
    'financial' => $result['financial']['gift_cards_sold']['available'] && $result['financial']['account_credit_sold']['available'] && $result['financial']['card_fees']['available'],
    'provider' => !empty($result['provider_details']) && $result['provider_details'][0]['utilization_available'] === true,
    'daily' => !empty($result['daily_performance']),
    'coverage' => count(array_filter($result['coverage'], fn($r)=>!empty($r['available']))) === count($result['coverage']),
];

foreach ($checks as $label => $ok) {
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
    echo "PASS: {$label}\n";
}
