<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/google-business-platform.php';

$rows = db()->query(
    "SELECT DISTINCT business_id, service
     FROM google_connections
     WHERE status='connected'
       AND selected_resource_id IS NOT NULL
       AND selected_resource_id<>''
     ORDER BY business_id, service"
)->fetchAll();

foreach ($rows as $row) {
    $businessId = (int)$row['business_id'];
    $service = (string)$row['service'];

    try {
        $saved = googlehub_sync_service($businessId, $service, 90);
        echo '[' . date('c') . "] business={$businessId} service={$service} rows={$saved}\n";
    } catch (Throwable $e) {
        error_log("[Google cron] business={$businessId} service={$service}: " . $e->getMessage());
        echo '[' . date('c') . "] ERROR business={$businessId} service={$service}: " . $e->getMessage() . "\n";
    }
}
