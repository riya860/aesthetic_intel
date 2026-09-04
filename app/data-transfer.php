<?php

declare(strict_types=1);

const DATA_TRANSFER_PACKAGE_VERSION = '1.0';
const DATA_TRANSFER_MAX_BYTES = 26214400; // 25 MB

function data_transfer_json(array $value): string {
    return json_encode(
        $value,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
    );
}

function data_transfer_business(int $businessId): array {
    $stmt = db()->prepare('SELECT * FROM businesses WHERE id=?');
    $stmt->execute([$businessId]);
    $business = $stmt->fetch();
    if (!$business) throw new RuntimeException('Business not found.');
    return $business;
}

function data_transfer_stats(int $businessId): array {
    $queries = [
        'boulevard' => "SELECT COUNT(*) FROM upload_batches ub JOIN data_sources ds ON ds.id=ub.data_source_id WHERE ub.business_id=? AND ub.status='completed' AND ds.code='boulevard'",
        'gbp' => 'SELECT COUNT(*) FROM gbp_entries WHERE business_id=?',
        'podium' => "SELECT COUNT(*) FROM ai_extractions WHERE business_id=? AND source_code='podium' AND status='confirmed'",
        'growth99' => "SELECT COUNT(*) FROM ai_extractions WHERE business_id=? AND source_code='growth99' AND status='confirmed'",
        'ga4' => "SELECT COUNT(*) FROM ai_extractions WHERE business_id=? AND source_code='ga4' AND status='confirmed'",
    ];
    $out = [];
    foreach ($queries as $key => $sql) {
        $stmt = db()->prepare($sql);
        $stmt->execute([$businessId]);
        $out[$key] = (int)$stmt->fetchColumn();
    }
    $out['total'] = array_sum($out);
    return $out;
}

function data_transfer_record_hash(array $record): string {
    $fields = [
        (string)$record['package_version'],
        (string)$record['package_id'],
        (string)$record['record_type'],
        (string)$record['source_code'],
        (string)$record['period_start'],
        (string)$record['period_end'],
        (string)$record['frequency'],
        (string)$record['record_key'],
        (string)$record['payload_json'],
    ];
    return implode("\x1F", $fields)."\n";
}

function data_transfer_export_records(int $businessId, string $packageId): array {
    $business = data_transfer_business($businessId);
    $records = [];
    $add = static function (
        string $type,
        string $source,
        string $start,
        string $end,
        string $frequency,
        string $key,
        array $payload
    ) use (&$records, $packageId): void {
        $records[] = [
            'package_version' => DATA_TRANSFER_PACKAGE_VERSION,
            'package_id' => $packageId,
            'record_type' => $type,
            'source_code' => $source,
            'period_start' => $start,
            'period_end' => $end,
            'frequency' => $frequency,
            'record_key' => $key,
            'payload_json' => data_transfer_json($payload),
        ];
    };

    $add('business_profile', 'platform', '', '', '', 'profile', [
        'source_business_name' => (string)$business['name'],
        'timezone' => (string)$business['timezone'],
        'primary_color' => (string)$business['primary_color'],
        'accent_color' => (string)$business['accent_color'],
        'exported_at' => date('c'),
    ]);

    $stmt = db()->prepare(
        "SELECT ub.*
         FROM upload_batches ub
         JOIN data_sources ds ON ds.id=ub.data_source_id
         WHERE ub.business_id=? AND ub.status='completed' AND ds.code='boulevard'
         ORDER BY ub.period_end ASC,ub.period_start ASC,ub.id ASC"
    );
    $stmt->execute([$businessId]);
    foreach ($stmt->fetchAll() as $batch) {
        $batchId = (int)$batch['id'];
        $codesStmt = db()->prepare(
            "SELECT DISTINCT rt.code
             FROM uploaded_files uf
             JOIN report_types rt ON rt.id=uf.report_type_id
             WHERE uf.batch_id=? AND uf.status='validated'
             ORDER BY rt.sort_order,rt.code"
        );
        $codesStmt->execute([$batchId]);
        $reportCodes = array_values(array_map('strval', array_column($codesStmt->fetchAll(), 'code')));
        $add('boulevard_batch', 'boulevard', (string)$batch['period_start'], (string)$batch['period_end'], (string)$batch['frequency'], (string)$batchId, [
            'source_batch_id' => $batchId,
            'completeness_score' => (float)$batch['completeness_score'],
            'warning_count' => (int)$batch['warning_count'],
            'dashboard_json' => json_decode((string)$batch['dashboard_json'], true) ?: [],
            'insights_json' => json_decode((string)$batch['insights_json'], true) ?: [],
            'report_codes' => $reportCodes,
            'created_at' => (string)$batch['created_at'],
            'completed_at' => (string)($batch['completed_at'] ?: $batch['created_at']),
            'validation_status'=>(string)($batch['validation_status']??'validated'),
            'validation_score'=>$batch['validation_score']===null?null:(int)$batch['validation_score'],
            'validation_json'=>report_validation_decoded($batch),
        ]);

        $metricStmt = db()->prepare('SELECT * FROM metrics WHERE batch_id=? ORDER BY metric_key');
        $metricStmt->execute([$batchId]);
        foreach ($metricStmt->fetchAll() as $metric) {
            $add('boulevard_metric', 'boulevard', (string)$batch['period_start'], (string)$batch['period_end'], (string)$batch['frequency'], $batchId.':'.(string)$metric['metric_key'], [
                'source_batch_id' => $batchId,
                'metric_key' => (string)$metric['metric_key'],
                'metric_value' => $metric['metric_value'] === null ? null : (string)$metric['metric_value'],
                'metric_format' => (string)$metric['metric_format'],
                'metric_json' => value_available($metric['metric_json'] ?? null) ? (json_decode((string)$metric['metric_json'], true) ?: null) : null,
            ]);
        }
    }

    $stmt = db()->prepare('SELECT * FROM gbp_entries WHERE business_id=? ORDER BY period_end ASC,period_start ASC,id ASC');
    $stmt->execute([$businessId]);
    foreach ($stmt->fetchAll() as $entry) {
        $add('gbp_entry', 'gbp', (string)$entry['period_start'], (string)$entry['period_end'], (string)$entry['frequency'], (string)$entry['id'], [
            'interactions' => $entry['interactions'] === null ? null : (int)$entry['interactions'],
            'calls' => $entry['calls'] === null ? null : (int)$entry['calls'],
            'directions' => $entry['directions'] === null ? null : (int)$entry['directions'],
            'website_clicks' => $entry['website_clicks'] === null ? null : (int)$entry['website_clicks'],
            'total_reviews' => $entry['total_reviews'] === null ? null : (int)$entry['total_reviews'],
            'new_reviews_manual' => $entry['new_reviews_manual'] === null ? null : (int)$entry['new_reviews_manual'],
            'average_rating' => $entry['average_rating'] === null ? null : (string)$entry['average_rating'],
            'unanswered_reviews' => $entry['unanswered_reviews'] === null ? null : (int)$entry['unanswered_reviews'],
            'notes' => (string)($entry['notes'] ?? ''),
            'created_at' => (string)$entry['created_at'],
            'updated_at' => (string)$entry['updated_at'],
            'validation_status'=>(string)($entry['validation_status']??'validated'),
            'validation_score'=>$entry['validation_score']===null?null:(int)$entry['validation_score'],
            'validation_json'=>report_validation_decoded($entry),
        ]);
    }

    $stmt = db()->prepare("SELECT * FROM ai_extractions WHERE business_id=? AND status='confirmed' ORDER BY period_end ASC,period_start ASC,source_code ASC,id ASC");
    $stmt->execute([$businessId]);
    foreach ($stmt->fetchAll() as $entry) {
        $add('ai_extraction', (string)$entry['source_code'], (string)$entry['period_start'], (string)$entry['period_end'], (string)$entry['frequency'], (string)$entry['id'], [
            'values' => json_decode((string)$entry['extracted_json'], true) ?: [],
            'notes' => (string)($entry['notes'] ?? ''),
            'created_at' => (string)$entry['created_at'],
            'updated_at' => (string)$entry['updated_at'],
            'validation_status'=>(string)($entry['validation_status']??'validated'),
            'validation_score'=>$entry['validation_score']===null?null:(int)$entry['validation_score'],
            'validation_json'=>report_validation_decoded($entry),
        ]);
    }

    return $records;
}

function data_transfer_stream_export(int $businessId): never {
    $business = data_transfer_business($businessId);
    $packageId = 'ai-'.date('Ymd-His').'-'.bin2hex(random_bytes(5));
    $records = data_transfer_export_records($businessId, $packageId);
    $hash = hash_init('sha256');
    $counts = [];
    foreach ($records as $record) {
        hash_update($hash, data_transfer_record_hash($record));
        $counts[$record['record_type']] = ($counts[$record['record_type']] ?? 0) + 1;
    }
    $manifestPayload = [
        'format' => 'Aesthetic Intel Portable Business Data',
        'package_version' => DATA_TRANSFER_PACKAGE_VERSION,
        'package_id' => $packageId,
        'source_business_name' => (string)$business['name'],
        'source_business_timezone' => (string)$business['timezone'],
        'exported_at' => date('c'),
        'data_record_count' => count($records),
        'record_counts' => $counts,
        'sha256' => hash_final($hash),
        'excludes' => ['users', 'passwords', 'sessions', 'API keys', 'raw screenshots', 'raw PDFs', 'raw Boulevard CSV files', 'business logo'],
    ];
    $manifest = [
        'package_version' => DATA_TRANSFER_PACKAGE_VERSION,
        'package_id' => $packageId,
        'record_type' => 'manifest',
        'source_code' => 'platform',
        'period_start' => '',
        'period_end' => '',
        'frequency' => '',
        'record_key' => 'manifest',
        'payload_json' => data_transfer_json($manifestPayload),
    ];

    $slug = preg_replace('/[^a-z0-9]+/i', '-', strtolower((string)$business['name']));
    $filename = trim((string)$slug, '-').'-aesthetic-intel-data-'.date('Y-m-d').'.csv';
    if ($filename === '-aesthetic-intel-data-'.date('Y-m-d').'.csv') $filename = 'aesthetic-intel-business-data-'.date('Y-m-d').'.csv';

    audit('business_data_exported', ['package_id' => $packageId, 'records' => count($records)], $businessId);
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'wb');
    if ($out === false) throw new RuntimeException('Could not open the export stream.');
    $header = ['package_version','package_id','record_type','source_code','period_start','period_end','frequency','record_key','payload_json'];
    fputcsv($out, $header, ',', '"', '');
    foreach (array_merge([$manifest], $records) as $record) {
        fputcsv($out, array_values($record), ',', '"', '');
    }
    fclose($out);
    exit;
}

function data_transfer_valid_date(string $date): bool {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return false;
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $d !== false && $d->format('Y-m-d') === $date;
}

function data_transfer_valid_datetime(?string $value): string {
    $value = trim((string)$value);
    if ($value === '' || strtotime($value) === false) return date('Y-m-d H:i:s');
    return date('Y-m-d H:i:s', strtotime($value));
}

function data_transfer_parse_file(array $file): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('Choose an Aesthetic Intel business-data CSV file.');
    $size = (int)($file['size'] ?? 0);
    if ($size <= 0 || $size > DATA_TRANSFER_MAX_BYTES) throw new RuntimeException('The import file must be non-empty and no larger than 25 MB.');
    if (strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION)) !== 'csv') throw new RuntimeException('Only the CSV exported by Aesthetic Intel can be imported.');

    $handle = fopen((string)$file['tmp_name'], 'rb');
    if ($handle === false) throw new RuntimeException('Could not read the selected import file.');
    try {
        $header = fgetcsv($handle, 0, ',', '"', '');
        if (!is_array($header)) throw new RuntimeException('The import file is empty.');
        if (isset($header[0])) $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
        $expected = ['package_version','package_id','record_type','source_code','period_start','period_end','frequency','record_key','payload_json'];
        if ($header !== $expected) throw new RuntimeException('This is not a compatible Aesthetic Intel business-data CSV.');

        $rows = [];
        $line = 1;
        while (($values = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $line++;
            if (count($values) === 1 && trim((string)$values[0]) === '') continue;
            if (count($values) !== count($expected)) throw new RuntimeException('Import row '.$line.' has an invalid column count.');
            $row = array_combine($expected, $values);
            if (!is_array($row)) throw new RuntimeException('Import row '.$line.' could not be read.');
            $row['_line'] = $line;
            $rows[] = $row;
            if (count($rows) > 100000) throw new RuntimeException('The import contains too many records.');
        }
    } finally {
        fclose($handle);
    }
    if (!$rows || ($rows[0]['record_type'] ?? '') !== 'manifest') throw new RuntimeException('The import manifest is missing.');

    $manifestRow = array_shift($rows);
    $manifest = json_decode((string)$manifestRow['payload_json'], true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($manifest)) throw new RuntimeException('The import manifest is invalid.');
    if ((string)$manifestRow['package_version'] !== DATA_TRANSFER_PACKAGE_VERSION || (string)($manifest['package_version'] ?? '') !== DATA_TRANSFER_PACKAGE_VERSION) {
        throw new RuntimeException('This data package version is not supported by the current Aesthetic Intel installation.');
    }
    $packageId = (string)$manifestRow['package_id'];
    if ($packageId === '' || $packageId !== (string)($manifest['package_id'] ?? '')) throw new RuntimeException('The data-package identifier is invalid.');

    $hash = hash_init('sha256');
    $counts = [];
    $allowedTypes = ['business_profile','boulevard_batch','boulevard_metric','gbp_entry','ai_extraction'];
    $allowedFrequencies = reporting_frequencies();
    $allowedAiSources = array_keys(ai_extraction_sources());
    foreach ($rows as &$row) {
        if ((string)$row['package_version'] !== DATA_TRANSFER_PACKAGE_VERSION || (string)$row['package_id'] !== $packageId) {
            throw new RuntimeException('Import row '.(int)$row['_line'].' belongs to a different or unsupported package.');
        }
        if (!in_array((string)$row['record_type'], $allowedTypes, true)) throw new RuntimeException('Import row '.(int)$row['_line'].' contains an unsupported record type.');
        $expectedSource = match((string)$row['record_type']) {
            'business_profile' => 'platform',
            'boulevard_batch', 'boulevard_metric' => 'boulevard',
            'gbp_entry' => 'gbp',
            default => (string)$row['source_code'],
        };
        if ((string)$row['source_code'] !== $expectedSource) throw new RuntimeException('Import row '.(int)$row['_line'].' contains an invalid source mapping.');
        if ($row['record_type'] !== 'business_profile') {
            if (!data_transfer_valid_date((string)$row['period_start']) || !data_transfer_valid_date((string)$row['period_end']) || (string)$row['period_start'] > (string)$row['period_end']) {
                throw new RuntimeException('Import row '.(int)$row['_line'].' contains an invalid reporting period.');
            }
            if (!in_array((string)$row['frequency'], $allowedFrequencies, true)) throw new RuntimeException('Import row '.(int)$row['_line'].' contains an invalid frequency.');
        }
        if ($row['record_type'] === 'ai_extraction' && !in_array((string)$row['source_code'], $allowedAiSources, true)) {
            throw new RuntimeException('The package contains an AI tool that this installation does not support: '.(string)$row['source_code'].'.');
        }
        try {
            $payload = json_decode((string)$row['payload_json'], true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new RuntimeException('Import row '.(int)$row['_line'].' contains invalid structured data.');
        }
        if (!is_array($payload)) throw new RuntimeException('Import row '.(int)$row['_line'].' contains invalid structured data.');
        $row['_payload'] = $payload;
        hash_update($hash, data_transfer_record_hash($row));
        $counts[$row['record_type']] = ($counts[$row['record_type']] ?? 0) + 1;
    }
    unset($row);
    $actualHash = hash_final($hash);
    if (!hash_equals((string)($manifest['sha256'] ?? ''), $actualHash)) throw new RuntimeException('The import file failed its integrity check. Export it again and do not edit it manually.');
    if ((int)($manifest['data_record_count'] ?? -1) !== count($rows)) throw new RuntimeException('The import file is incomplete. Its record count does not match the manifest.');
    $expectedCounts = $manifest['record_counts'] ?? [];
    if (!is_array($expectedCounts) || $expectedCounts != $counts) throw new RuntimeException('The import file is incomplete. Its source counts do not match the manifest.');

    return ['manifest' => $manifest, 'package_id' => $packageId, 'rows' => $rows, 'counts' => $counts];
}

function data_transfer_source_id(string $code): int {
    $stmt = db()->prepare('SELECT id FROM data_sources WHERE code=? LIMIT 1');
    $stmt->execute([$code]);
    $id = (int)$stmt->fetchColumn();
    if (!$id) throw new RuntimeException('Required data source is missing: '.$code.'.');
    return $id;
}

function data_transfer_import(int $businessId, int $userId, array $file, string $mode, bool $applyBusinessSettings): array {
    if (!in_array($mode, ['replace_matching','skip_existing'], true)) $mode = 'replace_matching';
    $package = data_transfer_parse_file($file);
    $rows = $package['rows'];
    $business = data_transfer_business($businessId);

    $profiles = [];
    $batches = [];
    $metricsBySourceBatch = [];
    $gbp = [];
    $ai = [];
    foreach ($rows as $row) {
        $payload = $row['_payload'];
        switch ($row['record_type']) {
            case 'business_profile':
                $profiles[] = $payload;
                break;
            case 'boulevard_batch':
                $sourceBatchId = (string)($payload['source_batch_id'] ?? $row['record_key']);
                if ($sourceBatchId === '') throw new RuntimeException('A Boulevard batch is missing its source identifier.');
                if (isset($batches[$sourceBatchId])) throw new RuntimeException('The package contains a duplicate Boulevard batch identifier.');
                $batches[$sourceBatchId] = ['row' => $row, 'payload' => $payload];
                break;
            case 'boulevard_metric':
                $sourceBatchId = (string)($payload['source_batch_id'] ?? '');
                if ($sourceBatchId === '') throw new RuntimeException('A Boulevard metric is missing its batch identifier.');
                $metricsBySourceBatch[$sourceBatchId][] = $payload;
                break;
            case 'gbp_entry':
                $gbp[] = ['row' => $row, 'payload' => $payload];
                break;
            case 'ai_extraction':
                $ai[] = ['row' => $row, 'payload' => $payload];
                break;
        }
    }
    if (count($profiles) !== 1) throw new RuntimeException('The package must contain exactly one business profile record.');
    foreach (array_keys($metricsBySourceBatch) as $sourceBatchId) {
        if (!isset($batches[$sourceBatchId])) throw new RuntimeException('The package contains Boulevard metrics without their matching report batch.');
    }

    $boulevardSourceId = data_transfer_source_id('boulevard');
    $reportTypes = [];
    $stmt = db()->prepare("SELECT rt.id,rt.code FROM report_types rt JOIN data_sources ds ON ds.id=rt.data_source_id WHERE ds.code='boulevard'");
    $stmt->execute();
    foreach ($stmt->fetchAll() as $type) $reportTypes[(string)$type['code']] = (int)$type['id'];

    $summary = ['boulevard_imported'=>0,'boulevard_skipped'=>0,'gbp_imported'=>0,'gbp_skipped'=>0,'ai_imported'=>0,'ai_skipped'=>0,'metrics_imported'=>0];
    $filesToDelete = [];
    db()->beginTransaction();
    try {
        if ($applyBusinessSettings && $profiles) {
            $profile = $profiles[0];
            $timezone = (string)($profile['timezone'] ?? $business['timezone']);
            if (!in_array($timezone, timezone_identifiers_list(), true)) throw new RuntimeException('The source business contains an invalid timezone.');
            $primary = preg_match('/^#[0-9a-f]{6}$/i', (string)($profile['primary_color'] ?? '')) ? (string)$profile['primary_color'] : (string)$business['primary_color'];
            $accent = preg_match('/^#[0-9a-f]{6}$/i', (string)($profile['accent_color'] ?? '')) ? (string)$profile['accent_color'] : (string)$business['accent_color'];
            db()->prepare('UPDATE businesses SET timezone=?,primary_color=?,accent_color=? WHERE id=?')->execute([$timezone,$primary,$accent,$businessId]);
        }

        $handledBoulevardPeriods = [];
        $skippedBoulevardPeriods = [];
        foreach ($batches as $sourceBatchId => $bundle) {
            $row = $bundle['row'];
            $payload = $bundle['payload'];
            $periodKey = (string)$row['period_start'].'|'.(string)$row['period_end'];
            if (!isset($handledBoulevardPeriods[$periodKey])) {
                $existingStmt = db()->prepare("SELECT ub.id FROM upload_batches ub JOIN data_sources ds ON ds.id=ub.data_source_id WHERE ub.business_id=? AND ds.code='boulevard' AND ub.period_start=? AND ub.period_end=?");
                $existingStmt->execute([$businessId,$row['period_start'],$row['period_end']]);
                $existingIds = array_map('intval', array_column($existingStmt->fetchAll(), 'id'));
                if ($existingIds && $mode === 'skip_existing') {
                    $skippedBoulevardPeriods[$periodKey] = true;
                } elseif ($existingIds) {
                    $placeholders = implode(',', array_fill(0, count($existingIds), '?'));
                    $fileStmt = db()->prepare("SELECT relative_path FROM uploaded_files WHERE batch_id IN ({$placeholders})");
                    $fileStmt->execute($existingIds);
                    foreach ($fileStmt->fetchAll() as $fileRow) $filesToDelete[] = (string)$fileRow['relative_path'];
                    db()->prepare("DELETE FROM upload_batches WHERE business_id=? AND id IN ({$placeholders})")->execute(array_merge([$businessId],$existingIds));
                }
                $handledBoulevardPeriods[$periodKey] = true;
            }
            if (isset($skippedBoulevardPeriods[$periodKey])) {
                $summary['boulevard_skipped']++;
                continue;
            }

            $dashboard = $payload['dashboard_json'] ?? [];
            $insights = $payload['insights_json'] ?? [];
            if (!is_array($dashboard) || !is_array($insights)) throw new RuntimeException('A Boulevard report contains invalid dashboard data.');
            $insert = db()->prepare("INSERT INTO upload_batches(business_id,data_source_id,uploaded_by,period_start,period_end,frequency,status,completeness_score,warning_count,error_message,dashboard_json,insights_json,validation_status,validation_score,validation_json,validated_at,created_at,completed_at) VALUES(?,?,?,?,?,?,'completed',?,?,NULL,?,?,?,?,?,?,?,?)");
            $insert->execute([
                $businessId,$boulevardSourceId,$userId,$row['period_start'],$row['period_end'],$row['frequency'],
                max(0,min(100,(float)($payload['completeness_score'] ?? 0))),max(0,(int)($payload['warning_count'] ?? 0)),
                data_transfer_json($dashboard),data_transfer_json($insights),
                in_array((string)($payload['validation_status']??'validated'),['validated','warning','review_required','approved'],true)?(string)($payload['validation_status']??'validated'):'review_required',
                isset($payload['validation_score'])?max(0,min(100,(int)$payload['validation_score'])):null,
                data_transfer_json(is_array($payload['validation_json']??null)?$payload['validation_json']:[]),data_transfer_valid_datetime($payload['completed_at'] ?? null),
                data_transfer_valid_datetime($payload['created_at'] ?? null),data_transfer_valid_datetime($payload['completed_at'] ?? null),
            ]);
            $newBatchId = (int)db()->lastInsertId();

            $codes = $payload['report_codes'] ?? [];
            if (!is_array($codes)) throw new RuntimeException('A Boulevard report contains invalid report-type data.');
            foreach (array_values(array_unique(array_map('strval',$codes))) as $code) {
                if (!isset($reportTypes[$code])) throw new RuntimeException('The target installation is missing Boulevard report type: '.$code.'.');
                $checksum = hash('sha256',$package['package_id'].'|'.$sourceBatchId.'|'.$code);
                $relative = 'storage/imported/business_'.$businessId.'/batch_'.$newBatchId.'/'.$code.'.csv';
                $fileInsert = db()->prepare("INSERT INTO uploaded_files(batch_id,report_type_id,original_name,stored_name,relative_path,file_size,mime_type,checksum_sha256,row_count,status,warnings_json,error_message) VALUES(?,?,?,?,?,0,'text/csv',?,0,'validated',?,NULL)");
                $fileInsert->execute([$newBatchId,$reportTypes[$code],'Imported '.$code.'.csv','imported-'.$code.'.csv',$relative,$checksum,data_transfer_json(['Imported from a portable Aesthetic Intel data package. Original CSV was intentionally not included.'])]);
            }

            foreach ($metricsBySourceBatch[$sourceBatchId] ?? [] as $metric) {
                $key = trim((string)($metric['metric_key'] ?? ''));
                $format = (string)($metric['metric_format'] ?? 'number');
                if ($key === '' || !in_array($format,['number','currency','percent','text','json'],true)) throw new RuntimeException('A Boulevard metric contains invalid metadata.');
                $metricValue = $metric['metric_value'] ?? null;
                if ($metricValue !== null && $metricValue !== '' && !is_numeric($metricValue)) throw new RuntimeException('A Boulevard metric contains a non-numeric metric value.');
                $metricJson = $metric['metric_json'] ?? null;
                $metricInsert = db()->prepare('INSERT INTO metrics(batch_id,metric_key,metric_value,metric_format,metric_json) VALUES(?,?,?,?,?)');
                $metricInsert->execute([$newBatchId,$key,$metricValue === '' ? null : $metricValue,$format,$metricJson === null ? null : data_transfer_json((array)$metricJson)]);
                $summary['metrics_imported']++;
            }
            $summary['boulevard_imported']++;
        }

        foreach ($gbp as $bundle) {
            $row = $bundle['row'];
            $payload = $bundle['payload'];
            $existing = db()->prepare('SELECT id FROM gbp_entries WHERE business_id=? AND period_start=? AND period_end=?');
            $existing->execute([$businessId,$row['period_start'],$row['period_end']]);
            $existingId = (int)$existing->fetchColumn();
            if ($existingId && $mode === 'skip_existing') {
                $summary['gbp_skipped']++;
                continue;
            }
            if ($existingId) db()->prepare('DELETE FROM gbp_entries WHERE id=? AND business_id=?')->execute([$existingId,$businessId]);
            $insert = db()->prepare('INSERT INTO gbp_entries(business_id,entered_by,period_start,period_end,frequency,interactions,calls,directions,website_clicks,total_reviews,new_reviews_manual,average_rating,unanswered_reviews,notes,validation_status,validation_score,validation_json,validated_at,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $insert->execute([
                $businessId,$userId,$row['period_start'],$row['period_end'],$row['frequency'],
                $payload['interactions'] ?? null,$payload['calls'] ?? null,$payload['directions'] ?? null,$payload['website_clicks'] ?? null,
                $payload['total_reviews'] ?? null,$payload['new_reviews_manual'] ?? null,$payload['average_rating'] ?? null,$payload['unanswered_reviews'] ?? null,
                (string)($payload['notes'] ?? ''),in_array((string)($payload['validation_status']??'validated'),['validated','warning','review_required','approved'],true)?(string)($payload['validation_status']??'validated'):'review_required',isset($payload['validation_score'])?max(0,min(100,(int)$payload['validation_score'])):null,data_transfer_json(is_array($payload['validation_json']??null)?$payload['validation_json']:[]),data_transfer_valid_datetime($payload['updated_at'] ?? null),data_transfer_valid_datetime($payload['created_at'] ?? null),data_transfer_valid_datetime($payload['updated_at'] ?? null),
            ]);
            $summary['gbp_imported']++;
        }

        $handledAiPeriods = [];
        $skippedAiPeriods = [];
        foreach ($ai as $bundle) {
            $row = $bundle['row'];
            $payload = $bundle['payload'];
            $sourceCode = (string)$row['source_code'];
            $periodKey = $sourceCode.'|'.(string)$row['period_start'].'|'.(string)$row['period_end'];
            if (!isset($handledAiPeriods[$periodKey])) {
                $existing = db()->prepare("SELECT id FROM ai_extractions WHERE business_id=? AND source_code=? AND period_start=? AND period_end=? AND status='confirmed'");
                $existing->execute([$businessId,$sourceCode,$row['period_start'],$row['period_end']]);
                $existingIds = array_map('intval', array_column($existing->fetchAll(), 'id'));
                if ($existingIds && $mode === 'skip_existing') {
                    $skippedAiPeriods[$periodKey] = true;
                } elseif ($existingIds) {
                    $placeholders = implode(',', array_fill(0,count($existingIds),'?'));
                    db()->prepare("DELETE FROM ai_extractions WHERE business_id=? AND id IN ({$placeholders})")->execute(array_merge([$businessId],$existingIds));
                }
                $handledAiPeriods[$periodKey] = true;
            }
            if (isset($skippedAiPeriods[$periodKey])) {
                $summary['ai_skipped']++;
                continue;
            }
            $values = $payload['values'] ?? [];
            if (!is_array($values)) throw new RuntimeException('An AI extraction contains invalid metric data.');
            $insert = db()->prepare("INSERT INTO ai_extractions(business_id,source_code,period_start,period_end,frequency,extracted_json,notes,status,validation_status,validation_score,validation_json,validated_at,created_by,created_at,updated_at) VALUES(?,?,?,?,?,?,?,'confirmed',?,?,?, ?,?,?,?)");
            $insert->execute([$businessId,$sourceCode,$row['period_start'],$row['period_end'],$row['frequency'],data_transfer_json($values),(string)($payload['notes'] ?? ''),in_array((string)($payload['validation_status']??'validated'),['validated','warning','review_required','approved'],true)?(string)($payload['validation_status']??'validated'):'review_required',isset($payload['validation_score'])?max(0,min(100,(int)$payload['validation_score'])):null,data_transfer_json(is_array($payload['validation_json']??null)?$payload['validation_json']:[]),data_transfer_valid_datetime($payload['updated_at'] ?? null),$userId,data_transfer_valid_datetime($payload['created_at'] ?? null),data_transfer_valid_datetime($payload['updated_at'] ?? null)]);
            $summary['ai_imported']++;
        }

        db()->commit();
        foreach (array_values(array_unique($filesToDelete)) as $relativePath) {
            $absolutePath = ROOT_PATH.'/'.ltrim($relativePath,'/');
            if (is_file($absolutePath)) @unlink($absolutePath);
        }
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        throw $e;
    }

    $summary['source_business_name'] = (string)($package['manifest']['source_business_name'] ?? 'Unknown business');
    $summary['package_id'] = (string)$package['package_id'];
    return $summary;
}
