<?php

declare(strict_types=1);

require __DIR__ . '/../app/Services/Boulevard/BoulevardLiveResultStore.php';

function check(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL - {$message}\n");
        exit(1);
    }
    echo "PASS - {$message}\n";
}

$appointments = [];
for ($i = 1; $i <= 317; $i++) {
    $appointments[] = ['id' => 'a' . $i, 'startAt' => '2026-09-01T10:00:00Z'];
}
$orders = [];
for ($i = 1; $i <= 426; $i++) {
    $orders[] = ['id' => 'o' . $i, 'number' => (string)$i];
}
$staff = [];
for ($i = 1; $i <= 205; $i++) {
    $staff[] = ['id' => 's' . $i, 'displayName' => 'Staff ' . $i];
}
$services = [];
for ($i = 1; $i <= 235; $i++) {
    $services[] = ['id' => 'svc' . $i, 'name' => 'Service ' . $i];
}

$key = BoulevardLiveResultStore::save([
    'appointments' => $appointments,
    'orders' => $orders,
    'staff' => $staff,
    'services' => $services,
]);

$loaded = BoulevardLiveResultStore::load($key);
check(is_array($loaded), 'complete result can be reloaded');
check(count($loaded['appointments'] ?? []) === 317, 'all 317 appointments are preserved');
check(count($loaded['orders'] ?? []) === 426, 'all 426 orders are preserved');
check(count($loaded['staff'] ?? []) === 205, 'all 205 staff rows are preserved');
check(count($loaded['services'] ?? []) === 235, 'all 235 service rows are preserved');

$appointmentPage4 = BoulevardLiveResultStore::page($loaded['appointments'], 4, 100);
check(count($appointmentPage4['rows']) === 17, 'appointments page 4 contains rows 301-317');
check(($appointmentPage4['meta']['total'] ?? 0) === 317, 'appointments pager reports complete total');
check(($appointmentPage4['meta']['from'] ?? 0) === 301, 'appointments page 4 starts at row 301');
check(($appointmentPage4['meta']['to'] ?? 0) === 317, 'appointments page 4 ends at row 317');

$orderPage5 = BoulevardLiveResultStore::page($loaded['orders'], 5, 100);
check(count($orderPage5['rows']) === 26, 'orders page 5 contains rows 401-426');
check(($orderPage5['meta']['total'] ?? 0) === 426, 'orders pager reports complete total');

/*
 * Static guard for the actual API service implementation: page size is 100,
 * but there must be no fixed MAX_PAGES/array_slice cap in collectConnection.
 */
$serviceCode = file_get_contents(__DIR__ . '/../app/Services/Boulevard/BoulevardService.php');
check(is_string($serviceCode), 'BoulevardService source is readable');
check(str_contains($serviceCode, 'hasNextPage'), 'BoulevardService follows pageInfo.hasNextPage');
check(str_contains($serviceCode, 'endCursor'), 'BoulevardService follows pageInfo.endCursor');
check(!str_contains($serviceCode, 'MAX_PAGES'), 'BoulevardService has no fixed total-page cap');

BoulevardLiveResultStore::delete($key);

echo "Complete-fetch smoke test passed.\n";
