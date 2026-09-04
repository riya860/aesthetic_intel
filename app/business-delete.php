<?php

declare(strict_types=1);

/**
 * Super Admin-only destructive business removal helpers.
 *
 * Business deletion is deliberately isolated from ordinary business editing.
 * The caller must verify the currently authenticated Super Admin password and
 * obtain an explicit destructive-action confirmation before calling the
 * deletion routine.
 */

function admin_business_delete_target(int $businessId): ?array {
    if ($businessId <= 0) return null;
    $stmt = db()->prepare('SELECT * FROM businesses WHERE id=? LIMIT 1');
    $stmt->execute([$businessId]);
    $business = $stmt->fetch();
    return $business ?: null;
}

function admin_business_delete_summary(int $businessId): array {
    $counts = [
        'users' => 0,
        'reports' => 0,
        'gbp_entries' => 0,
        'ai_extractions' => 0,
        'providers' => 0,
        'provider_imports' => 0,
        'provider_values' => 0,
        'provider_goals' => 0,
        'provider_reviews' => 0,
        'provider_actions' => 0,
    ];
    if ($businessId <= 0) return $counts;

    $queries = [
        'users' => 'SELECT COUNT(*) FROM users WHERE business_id=?',
        'reports' => 'SELECT COUNT(*) FROM upload_batches WHERE business_id=?',
        'gbp_entries' => 'SELECT COUNT(*) FROM gbp_entries WHERE business_id=?',
        'ai_extractions' => 'SELECT COUNT(*) FROM ai_extractions WHERE business_id=?',
        'providers' => 'SELECT COUNT(*) FROM provider_profiles WHERE business_id=?',
        'provider_imports' => 'SELECT COUNT(*) FROM provider_kpi_imports WHERE business_id=?',
        'provider_values' => 'SELECT COUNT(*) FROM provider_kpi_values WHERE business_id=?',
        'provider_goals' => 'SELECT COUNT(*) FROM provider_kpi_goals WHERE business_id=?',
        'provider_reviews' => 'SELECT COUNT(*) FROM provider_kpi_reviews WHERE business_id=?',
        'provider_actions' => 'SELECT COUNT(*) FROM provider_kpi_actions WHERE business_id=?',
    ];

    foreach ($queries as $key => $sql) {
        try {
            $stmt = db()->prepare($sql);
            $stmt->execute([$businessId]);
            $counts[$key] = (int)$stmt->fetchColumn();
        } catch (Throwable) {
            // Additive migrations create all of these tables. If an older
            // environment is mid-upgrade, keep the confirmation page usable
            // and let the transactional delete surface any real constraint.
            $counts[$key] = 0;
        }
    }

    return $counts;
}

function admin_current_password_is_valid(string $password): bool {
    if (!auth_is_admin() || auth_id() === null || $password === '') return false;
    $stmt = db()->prepare("SELECT password_hash FROM users WHERE id=? AND role='super_admin' AND status='active' LIMIT 1");
    $stmt->execute([auth_id()]);
    $hash = (string)($stmt->fetchColumn() ?: '');
    return $hash !== '' && password_verify($password, $hash);
}

function admin_business_delete_recursive(string $path): bool {
    if ($path === '' || !file_exists($path)) return true;
    if (is_link($path) || is_file($path)) return @unlink($path);
    if (!is_dir($path)) return false;

    $items = @scandir($path);
    if ($items === false) return false;
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        if (!admin_business_delete_recursive($path . DIRECTORY_SEPARATOR . $item)) return false;
    }
    return @rmdir($path);
}

function admin_business_delete_stage_paths(array $business): array {
    $businessId = (int)($business['id'] ?? 0);
    if ($businessId <= 0) throw new RuntimeException('Business not found.');

    $sources = [
        STORAGE_PATH . '/uploads/business_' . $businessId,
        STORAGE_PATH . '/boulevard-sync/business_' . $businessId,
    ];

    $logoPath = trim((string)($business['logo_path'] ?? ''));
    if ($logoPath !== '') {
        $candidate = ROOT_PATH . '/' . ltrim(str_replace('\\', '/', $logoPath), '/');
        $logoRoot = realpath(ROOT_PATH . '/assets/uploads/logos');
        $candidateReal = is_file($candidate) ? realpath($candidate) : false;
        if ($logoRoot && $candidateReal && str_starts_with($candidateReal, $logoRoot . DIRECTORY_SEPARATOR)) {
            $sources[] = $candidateReal;
        }
    }

    $sources = array_values(array_unique(array_filter($sources, static fn(string $path): bool => file_exists($path))));
    if (!$sources) return ['root' => null, 'moves' => []];

    $stageBase = STORAGE_PATH . '/delete-staging';
    if (!is_dir($stageBase) && !@mkdir($stageBase, 0755, true) && !is_dir($stageBase)) {
        throw new RuntimeException('Could not prepare secure deletion staging. No business data was deleted.');
    }

    $stageRoot = $stageBase . '/business_' . $businessId . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4));
    if (!@mkdir($stageRoot, 0755, true) && !is_dir($stageRoot)) {
        throw new RuntimeException('Could not prepare secure deletion staging. No business data was deleted.');
    }

    $moves = [];
    try {
        foreach ($sources as $index => $source) {
            $dest = $stageRoot . '/' . str_pad((string)($index + 1), 2, '0', STR_PAD_LEFT) . '-' . basename($source);
            if (!@rename($source, $dest)) {
                throw new RuntimeException('Could not safely stage all business files for deletion. No business data was deleted.');
            }
            $moves[] = ['source' => $source, 'staged' => $dest];
        }
    } catch (Throwable $e) {
        for ($i = count($moves) - 1; $i >= 0; $i--) {
            $move = $moves[$i];
            $parent = dirname($move['source']);
            if (!is_dir($parent)) @mkdir($parent, 0755, true);
            if (file_exists($move['staged']) && !file_exists($move['source'])) @rename($move['staged'], $move['source']);
        }
        admin_business_delete_recursive($stageRoot);
        throw $e;
    }

    return ['root' => $stageRoot, 'moves' => $moves];
}

function admin_business_delete_restore_staged(array $staged): void {
    $moves = $staged['moves'] ?? [];
    for ($i = count($moves) - 1; $i >= 0; $i--) {
        $move = $moves[$i];
        $source = (string)($move['source'] ?? '');
        $temp = (string)($move['staged'] ?? '');
        if ($source === '' || $temp === '' || !file_exists($temp)) continue;
        $parent = dirname($source);
        if (!is_dir($parent)) @mkdir($parent, 0755, true);
        if (!file_exists($source)) @rename($temp, $source);
    }
    $root = (string)($staged['root'] ?? '');
    if ($root !== '') admin_business_delete_recursive($root);
}

function admin_business_delete_permanently(int $businessId): array {
    if (!auth_is_admin()) throw new RuntimeException('Super Admin access is required.');

    $business = admin_business_delete_target($businessId);
    if (!$business) throw new RuntimeException('Business not found or already deleted.');

    // Move uploaded files and logo into protected staging first. If the DB
    // transaction fails, they are moved back to their original locations.
    $staged = admin_business_delete_stage_paths($business);
    $pdo = db();

    try {
        $pdo->beginTransaction();

        // These business-owned rows reference business users with RESTRICT.
        // Remove them first so the final business cascade can safely remove
        // the users and all remaining business-owned rows.
        foreach ([
            'DELETE FROM ai_extractions WHERE business_id=?',
            'DELETE FROM provider_kpi_imports WHERE business_id=?',
            'DELETE FROM gbp_entries WHERE business_id=?',
            'DELETE FROM upload_batches WHERE business_id=?',
        ] as $sql) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$businessId]);
        }

        $stmt = $pdo->prepare('DELETE FROM businesses WHERE id=? LIMIT 1');
        $stmt->execute([$businessId]);
        if ($stmt->rowCount() !== 1) throw new RuntimeException('Business deletion did not complete.');

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        admin_business_delete_restore_staged($staged);
        throw $e;
    }

    $cleanupOk = true;
    $stageRoot = (string)($staged['root'] ?? '');
    if ($stageRoot !== '' && file_exists($stageRoot)) {
        $cleanupOk = admin_business_delete_recursive($stageRoot);
    }

    return [
        'id' => $businessId,
        'name' => (string)$business['name'],
        'files_removed' => $cleanupOk,
    ];
}
