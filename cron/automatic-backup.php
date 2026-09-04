<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$restoreLock = $root.'/storage/restore.lock';
if (is_file($restoreLock)) {
    fwrite(STDOUT, "Aesthetic Intel automatic backup skipped: a restore is currently in progress.\n");
    exit(0);
}

$_SERVER['SCRIPT_NAME'] = '/cron/automatic-backup.php';
$_SERVER['REQUEST_METHOD'] = 'CLI';
require $root.'/app/bootstrap.php';

try {
    $result = site_backup_cron_tick();
    if (!empty($result['ran'])) {
        fwrite(STDOUT, 'Aesthetic Intel automatic backup completed and verified. History #'.(int)$result['history_id']."\n");
    } else {
        fwrite(STDOUT, 'Aesthetic Intel automatic backup check: '.(string)($result['reason'] ?? 'not_due')."\n");
    }
    exit(0);
} catch (Throwable $e) {
    error_log('Automatic backup cron error: '.$e->getMessage());
    fwrite(STDERR, "Aesthetic Intel automatic backup failed safely. Check Backup History / server error log.\n");
    exit(1);
}
