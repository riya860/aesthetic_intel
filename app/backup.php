<?php

declare(strict_types=1);

const SITE_BACKUP_FORMAT = 'aesthetic-intel-full-backup';
const SITE_BACKUP_FORMAT_VERSION = '1.0';
const SITE_BACKUP_STAGE_TTL = 1800;
const SITE_BACKUP_CHUNK_BYTES = 1048576;
const SITE_BACKUP_MAX_IMPORT_BYTES = 268435456; // 256 MB application limit; server limits may be lower.

function site_backup_dir(): string {
    return STORAGE_PATH.'/backups';
}

function site_backup_stage_dir(): string {
    return STORAGE_PATH.'/restore-staging';
}

function site_backup_automatic_dir(): string {
    return site_backup_dir().'/automatic';
}

function site_backup_automatic_lock_file(): string {
    return STORAGE_PATH.'/automatic-backup.lock';
}

function site_backup_lock_file(): string {
    return STORAGE_PATH.'/restore.lock';
}

function site_backup_ensure_directories(): void {
    foreach ([site_backup_dir(), site_backup_stage_dir(), site_backup_automatic_dir()] as $dir) {
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create the protected backup workspace.');
        }
    }
}

function site_backup_remove_tree(string $path): void {
    if (!file_exists($path)) return;
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($path);
}

function site_backup_cleanup_stale(): void {
    site_backup_ensure_directories();
    $cutoffs = [site_backup_dir()=>time()-86400, site_backup_stage_dir()=>time()-3600];
    foreach ($cutoffs as $base=>$cutoff) {
        foreach (glob($base.'/*') ?: [] as $path) {
            // Retained automatic backups are managed only by the verified-backup retention policy.
            if ($base === site_backup_dir() && realpath($path) === realpath(site_backup_automatic_dir())) continue;
            if (@filemtime($path) !== false && (int)@filemtime($path) < $cutoff) site_backup_remove_tree($path);
        }
    }
}

function site_backup_ini_bytes(string $value): int {
    $value = trim($value);
    if ($value === '') return 0;
    $last = strtolower(substr($value, -1));
    $number = (float)$value;
    return match ($last) {
        'g' => (int)round($number * 1073741824),
        'm' => (int)round($number * 1048576),
        'k' => (int)round($number * 1024),
        default => (int)$number,
    };
}

function site_backup_upload_limit(): int {
    $limits = array_filter([
        site_backup_ini_bytes((string)ini_get('upload_max_filesize')),
        site_backup_ini_bytes((string)ini_get('post_max_size')),
        SITE_BACKUP_MAX_IMPORT_BYTES,
    ], static fn(int $v): bool => $v > 0);
    return $limits ? min($limits) : SITE_BACKUP_MAX_IMPORT_BYTES;
}

function site_backup_human_bytes(int|float $bytes): string {
    $bytes = max(0, (float)$bytes);
    $units = ['B','KB','MB','GB','TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units)-1) { $bytes /= 1024; $i++; }
    return number_format($bytes, $i === 0 ? 0 : 1).' '.$units[$i];
}

function site_backup_capabilities(): array {
    $storageWritable = is_dir(STORAGE_PATH) && is_writable(STORAGE_PATH);
    return [
        'zip' => class_exists('ZipArchive'),
        'sodium' => function_exists('sodium_crypto_secretstream_xchacha20poly1305_init_push') && function_exists('sodium_crypto_pwhash'),
        'fileinfo' => class_exists('finfo'),
        'storage_writable' => $storageWritable,
        'upload_limit' => site_backup_upload_limit(),
    ];
}

function site_backup_assert_capabilities(): void {
    $caps = site_backup_capabilities();
    $missing = [];
    if (!$caps['zip']) $missing[] = 'PHP ZipArchive';
    if (!$caps['sodium']) $missing[] = 'PHP Sodium';
    if (!$caps['fileinfo']) $missing[] = 'PHP Fileinfo';
    if (!$caps['storage_writable']) $missing[] = 'writable storage folder';
    if ($missing) throw new RuntimeException('Backup cannot run until these server requirements are available: '.implode(', ', $missing).'.');
}

function site_backup_normalize_relative(string $path): string {
    $path = str_replace('\\', '/', $path);
    $path = preg_replace('#/+#', '/', $path) ?? $path;
    return ltrim($path, '/');
}

function site_backup_is_excluded(string $relative): bool {
    $relative = site_backup_normalize_relative($relative);
    $exact = [
        'config/database.php',
        'storage/install.lock',
        'storage/restore.lock',
        'storage/automatic-backup.lock',
    ];
    if (in_array($relative, $exact, true)) return true;
    foreach (['storage/backups/', 'storage/restore-staging/', 'storage/logs/'] as $prefix) {
        if ($relative === rtrim($prefix, '/') || str_starts_with($relative, $prefix)) return true;
    }
    if (str_contains($relative, "\0")) return true;
    return false;
}

function site_backup_scan_site_files(): array {
    $files = [];
    $root = realpath(ROOT_PATH);
    if ($root === false) throw new RuntimeException('Could not resolve the application directory.');
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO)
    );
    foreach ($iterator as $item) {
        if ($item->isLink() || !$item->isFile()) continue;
        $absolute = $item->getPathname();
        $relative = site_backup_normalize_relative(substr($absolute, strlen($root) + 1));
        if ($relative === '' || site_backup_is_excluded($relative)) continue;
        $files[$relative] = $absolute;
    }
    ksort($files, SORT_STRING);
    return $files;
}

function site_backup_mkdir_for_file(string $file): void {
    $dir = dirname($file);
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create directory: '.$dir);
    }
}

function site_backup_copy_file(string $source, string $target): void {
    site_backup_mkdir_for_file($target);
    $in = fopen($source, 'rb');
    if (!$in) throw new RuntimeException('Could not read backup source file.');
    $temp = $target.'.tmp-'.bin2hex(random_bytes(4));
    $out = fopen($temp, 'wb');
    if (!$out) { fclose($in); throw new RuntimeException('Could not write restored file.'); }
    try {
        while (!feof($in)) {
            $chunk = fread($in, SITE_BACKUP_CHUNK_BYTES);
            if ($chunk === false) throw new RuntimeException('Could not read file data.');
            if ($chunk !== '' && fwrite($out, $chunk) !== strlen($chunk)) throw new RuntimeException('Could not write complete file data.');
        }
        fflush($out);
    } finally {
        fclose($in); fclose($out);
    }
    @chmod($temp, 0640);
    if (!@rename($temp, $target)) { @unlink($temp); throw new RuntimeException('Could not replace restored file: '.$target); }
}

function site_backup_encode_cell(mixed $value): mixed {
    if ($value === null) return null;
    $value = (string)$value;
    if (preg_match('//u', $value) === 1) return ['t'=>'s','v'=>$value];
    return ['t'=>'b','v'=>base64_encode($value)];
}

function site_backup_decode_cell(mixed $encoded): mixed {
    if ($encoded === null) return null;
    if (!is_array($encoded) || !isset($encoded['t']) || !array_key_exists('v', $encoded)) {
        throw new RuntimeException('The database backup contains an invalid cell value.');
    }
    if ($encoded['t'] === 's') return (string)$encoded['v'];
    if ($encoded['t'] === 'b') {
        $decoded = base64_decode((string)$encoded['v'], true);
        if ($decoded === false) throw new RuntimeException('The database backup contains invalid binary data.');
        return $decoded;
    }
    throw new RuntimeException('The database backup contains an unsupported cell type.');
}

function site_backup_canonical_create_sql(string $sql): string {
    $sql = preg_replace('/AUTO_INCREMENT=\d+\s*/i', '', $sql) ?? $sql;
    $sql = preg_replace('/\s+/', ' ', trim($sql)) ?? trim($sql);
    return $sql;
}

function site_backup_db_connection(bool $buffered = true): PDO {
    $cfg = require ROOT_PATH.'/config/database.php';
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $cfg['host'], $cfg['port'] ?? '3306', $cfg['name'], $cfg['charset'] ?? 'utf8mb4');
    return new PDO($dsn, $cfg['user'], $cfg['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => $buffered,
    ]);
}

function site_backup_table_names(PDO $pdo): array {
    $rows = $pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
    $tables = [];
    foreach ($rows as $row) {
        $name = (string)($row[0] ?? '');
        if ($name !== '' && preg_match('/^[A-Za-z0-9_]+$/', $name)) $tables[] = $name;
    }
    sort($tables, SORT_STRING);
    return $tables;
}

function site_backup_schema_snapshot(PDO $pdo): array {
    $tables = [];
    foreach (site_backup_table_names($pdo) as $table) {
        $stmt = $pdo->query('SHOW CREATE TABLE `'.$table.'`');
        $row = $stmt->fetch(PDO::FETCH_NUM);
        $createSql = (string)($row[1] ?? '');
        if ($createSql === '') throw new RuntimeException('Could not inspect database table '.$table.'.');
        $statusStmt = $pdo->query("SHOW TABLE STATUS LIKE ".$pdo->quote($table));
        $status = $statusStmt->fetch() ?: [];
        $tables[$table] = [
            'name' => $table,
            'create_sql' => $createSql,
            'schema_sha256' => hash('sha256', site_backup_canonical_create_sql($createSql)),
            'auto_increment' => isset($status['Auto_increment']) ? (int)$status['Auto_increment'] : null,
        ];
    }
    return $tables;
}

function site_backup_snapshot_database(string $destination): array {
    $dataDir = $destination.'/database/data';
    if (!is_dir($dataDir) && !mkdir($dataDir, 0750, true) && !is_dir($dataDir)) {
        throw new RuntimeException('Could not create the database backup folder.');
    }
    $schema = site_backup_schema_snapshot(db());
    $readPdo = site_backup_db_connection(false);
    $tablesOut = [];
    foreach ($schema as $table => $meta) {
        $dataFile = $dataDir.'/'.$table.'.jsonl';
        $handle = fopen($dataFile, 'wb');
        if (!$handle) throw new RuntimeException('Could not create database backup data for '.$table.'.');
        $count = 0;
        try {
            $stmt = $readPdo->query('SELECT * FROM `'.$table.'`');
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $encoded = [];
                foreach ($row as $column => $value) $encoded[$column] = site_backup_encode_cell($value);
                $line = json_encode($encoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
                if (fwrite($handle, $line) !== strlen($line)) throw new RuntimeException('Could not write complete database backup data.');
                $count++;
            }
        } finally {
            fclose($handle);
        }
        $tablesOut[$table] = $meta + [
            'row_count' => $count,
            'data_file' => 'database/data/'.$table.'.jsonl',
            'data_sha256' => hash_file('sha256', $dataFile),
            'data_size' => filesize($dataFile) ?: 0,
        ];
    }
    $schemaFile = $destination.'/database/schema.json';
    site_backup_mkdir_for_file($schemaFile);
    file_put_contents($schemaFile, json_encode(['tables'=>$tablesOut], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), LOCK_EX);
    return $tablesOut;
}

function site_backup_snapshot_site(string $destination): array {
    $manifest = [];
    foreach (site_backup_scan_site_files() as $relative => $absolute) {
        $target = $destination.'/site/'.$relative;
        site_backup_copy_file($absolute, $target);
        $manifest[$relative] = [
            'path' => $relative,
            'size' => filesize($target) ?: 0,
            'sha256' => hash_file('sha256', $target),
            'mode' => fileperms($absolute) & 0777,
        ];
    }
    return $manifest;
}

function site_backup_zip_directory(string $source, string $zipPath): void {
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Could not create the backup archive.');
    }
    try {
        $base = rtrim($source, '/');
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $item) {
            if (!$item->isFile() || $item->isLink()) continue;
            $relative = site_backup_normalize_relative(substr($item->getPathname(), strlen($base) + 1));
            if (!$zip->addFile($item->getPathname(), $relative)) throw new RuntimeException('Could not add a file to the backup archive.');
        }
    } finally {
        $zip->close();
    }
}

function site_backup_encrypt_file(string $input, string $output, string $password): array {
    $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
    $key = sodium_crypto_pwhash(
        SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES,
        $password,
        $salt,
        SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
        SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE,
        SODIUM_CRYPTO_PWHASH_ALG_DEFAULT
    );
    [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
    $in = fopen($input, 'rb');
    $out = fopen($output, 'wb');
    if (!$in || !$out) throw new RuntimeException('Could not open the encrypted backup payload.');
    try {
        while (!feof($in)) {
            $chunk = fread($in, SITE_BACKUP_CHUNK_BYTES);
            if ($chunk === false) throw new RuntimeException('Could not read backup payload.');
            $isFinal = feof($in);
            $cipher = sodium_crypto_secretstream_xchacha20poly1305_push(
                $state,
                $chunk,
                '',
                $isFinal ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE
            );
            $frame = pack('N', strlen($cipher)).$cipher;
            if (fwrite($out, $frame) !== strlen($frame)) throw new RuntimeException('Could not write encrypted backup payload.');
            if ($isFinal) break;
        }
    } finally {
        fclose($in); fclose($out); sodium_memzero($key);
    }
    return [
        'algorithm' => 'sodium-secretstream-xchacha20poly1305',
        'kdf' => 'sodium-pwhash-interactive',
        'salt' => base64_encode($salt),
        'header' => base64_encode($header),
        'chunk_bytes' => SITE_BACKUP_CHUNK_BYTES,
        'encrypted_sha256' => hash_file('sha256', $output),
        'encrypted_size' => filesize($output) ?: 0,
    ];
}

function site_backup_decrypt_file(string $input, string $output, string $password, array $crypto): void {
    if (($crypto['algorithm'] ?? '') !== 'sodium-secretstream-xchacha20poly1305') {
        throw new RuntimeException('This backup uses an unsupported encryption method.');
    }
    $salt = base64_decode((string)($crypto['salt'] ?? ''), true);
    $header = base64_decode((string)($crypto['header'] ?? ''), true);
    if ($salt === false || strlen($salt) !== SODIUM_CRYPTO_PWHASH_SALTBYTES || $header === false || strlen($header) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES) {
        throw new RuntimeException('The backup encryption metadata is invalid.');
    }
    if (!hash_equals((string)($crypto['encrypted_sha256'] ?? ''), hash_file('sha256', $input))) {
        throw new RuntimeException('The encrypted backup payload is corrupted.');
    }
    $key = sodium_crypto_pwhash(
        SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES,
        $password,
        $salt,
        SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
        SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE,
        SODIUM_CRYPTO_PWHASH_ALG_DEFAULT
    );
    $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);
    $in = fopen($input, 'rb');
    $out = fopen($output, 'wb');
    if (!$in || !$out) throw new RuntimeException('Could not open the backup payload for decryption.');
    $sawFinal = false;
    try {
        while (!feof($in)) {
            $lengthBytes = fread($in, 4);
            if ($lengthBytes === '') break;
            if ($lengthBytes === false || strlen($lengthBytes) !== 4) throw new RuntimeException('The encrypted backup payload is truncated.');
            $length = unpack('Nlength', $lengthBytes)['length'] ?? 0;
            if ($length < SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES || $length > SITE_BACKUP_CHUNK_BYTES + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES) {
                throw new RuntimeException('The encrypted backup payload has an invalid frame.');
            }
            $cipher = '';
            while (strlen($cipher) < $length && !feof($in)) {
                $piece = fread($in, $length - strlen($cipher));
                if ($piece === false) throw new RuntimeException('Could not read encrypted backup data.');
                $cipher .= $piece;
            }
            if (strlen($cipher) !== $length) throw new RuntimeException('The encrypted backup payload is incomplete.');
            $pulled = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $cipher);
            if ($pulled === false) throw new RuntimeException('The backup password is incorrect or the backup file is corrupted.');
            [$plain, $tag] = $pulled;
            if ($plain !== '' && fwrite($out, $plain) !== strlen($plain)) throw new RuntimeException('Could not write decrypted backup data.');
            if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) { $sawFinal = true; break; }
        }
    } finally {
        fclose($in); fclose($out); sodium_memzero($key);
    }
    if (!$sawFinal) { @unlink($output); throw new RuntimeException('The encrypted backup did not finish correctly.'); }
}

function site_backup_safe_zip_name(string $name): bool {
    $name = str_replace('\\', '/', $name);
    if ($name === '' || str_contains($name, "\0") || str_starts_with($name, '/') || preg_match('/^[A-Za-z]:\//', $name)) return false;
    foreach (explode('/', $name) as $part) if ($part === '' || $part === '.' || $part === '..') return false;
    return true;
}

function site_backup_extract_zip_safely(string $zipPath, string $destination): void {
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) throw new RuntimeException('The backup payload is not a valid ZIP archive.');
    if (!is_dir($destination) && !mkdir($destination, 0750, true) && !is_dir($destination)) {
        $zip->close(); throw new RuntimeException('Could not create the restore staging folder.');
    }
    try {
        for ($i=0; $i<$zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = (string)($stat['name'] ?? '');
            $isDirectory = str_ends_with($name, '/');
            $checkName = $isDirectory ? rtrim($name, '/') : $name;
            if (!site_backup_safe_zip_name($checkName)) throw new RuntimeException('The backup contains an unsafe file path.');
            if ($isDirectory) continue;
            $stream = $zip->getStream($name);
            if (!$stream) throw new RuntimeException('Could not read a file from the backup archive.');
            $target = $destination.'/'.$name;
            site_backup_mkdir_for_file($target);
            $out = fopen($target, 'wb');
            if (!$out) { fclose($stream); throw new RuntimeException('Could not extract the backup archive.'); }
            try {
                while (!feof($stream)) {
                    $chunk = fread($stream, SITE_BACKUP_CHUNK_BYTES);
                    if ($chunk === false) throw new RuntimeException('Could not read the backup archive.');
                    if ($chunk !== '' && fwrite($out, $chunk) !== strlen($chunk)) throw new RuntimeException('Could not fully extract the backup archive.');
                }
            } finally { fclose($stream); fclose($out); }
        }
    } finally { $zip->close(); }
}

function site_backup_create_download(string $password): never {
    site_backup_assert_capabilities();
    if (strlen($password) < 10) throw new RuntimeException('Use a backup password with at least 10 characters.');
    site_backup_cleanup_stale();
    @set_time_limit(0);
    ignore_user_abort(true);
    $id = date('Ymd-His').'-'.bin2hex(random_bytes(5));
    $work = site_backup_dir().'/export-'.$id;
    if (!mkdir($work, 0750, true)) throw new RuntimeException('Could not create the backup workspace.');
    try {
        $payloadDir = $work.'/payload';
        mkdir($payloadDir, 0750, true);
        $tables = site_backup_snapshot_database($payloadDir);
        $files = site_backup_snapshot_site($payloadDir);
        $innerManifest = [
            'format' => SITE_BACKUP_FORMAT,
            'format_version' => SITE_BACKUP_FORMAT_VERSION,
            'app_version' => (string)app_config('version'),
            'created_at' => gmdate('c'),
            'created_by' => (string)(auth_user()['email'] ?? ''),
            'source_host' => (string)($_SERVER['HTTP_HOST'] ?? ''),
            'database' => ['table_count'=>count($tables), 'tables'=>$tables],
            'site' => ['file_count'=>count($files), 'files'=>$files],
            'exclusions' => ['config/database.php','storage/install.lock','storage/backups','storage/restore-staging','storage/logs'],
        ];
        file_put_contents($payloadDir.'/manifest.json', json_encode($innerManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), LOCK_EX);
        $innerZip = $work.'/payload.zip';
        site_backup_zip_directory($payloadDir, $innerZip);
        $encrypted = $work.'/payload.enc';
        $crypto = site_backup_encrypt_file($innerZip, $encrypted, $password);
        $outerManifest = [
            'format' => SITE_BACKUP_FORMAT,
            'format_version' => SITE_BACKUP_FORMAT_VERSION,
            'app_version' => (string)app_config('version'),
            'created_at' => gmdate('c'),
            'encrypted' => true,
            'crypto' => $crypto,
        ];
        $outerZipPath = $work.'/aesthetic-intel-backup-'.$id.'.zip';
        $zip = new ZipArchive();
        if ($zip->open($outerZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Could not create the downloadable backup file.');
        $zip->addFromString('aesthetic-intel-backup.json', json_encode($outerManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $zip->addFile($encrypted, 'payload.enc');
        $zip->addFromString('README.txt', "Aesthetic Intel encrypted full backup\n\nRestore this file only through Super Admin > Backup & Restore.\nThe backup password is required.\n");
        $zip->close();
        $filename = basename($outerZipPath);
        if (ob_get_level()) while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="'.$filename.'"');
        header('Content-Length: '.filesize($outerZipPath));
        header('Cache-Control: no-store, no-cache, must-revalidate');
        $stream = fopen($outerZipPath, 'rb');
        if (!$stream) throw new RuntimeException('Could not stream the backup file.');
        while (!feof($stream)) { $chunk=fread($stream,SITE_BACKUP_CHUNK_BYTES); if($chunk===false)break; echo $chunk; flush(); }
        fclose($stream);
    } finally {
        site_backup_remove_tree($work);
    }
    exit;
}

function site_backup_read_outer_manifest(ZipArchive $zip): array {
    $raw = $zip->getFromName('aesthetic-intel-backup.json');
    if ($raw === false || strlen($raw) > 1048576) throw new RuntimeException('The selected file is not an Aesthetic Intel full backup.');
    $manifest = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($manifest) || ($manifest['format'] ?? '') !== SITE_BACKUP_FORMAT || ($manifest['format_version'] ?? '') !== SITE_BACKUP_FORMAT_VERSION) {
        throw new RuntimeException('This backup format is not supported.');
    }
    return $manifest;
}

function site_backup_validate_inner_manifest(string $extractedDir): array {
    $file = $extractedDir.'/manifest.json';
    if (!is_file($file)) throw new RuntimeException('The backup payload manifest is missing.');
    $manifest = json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($manifest) || ($manifest['format'] ?? '') !== SITE_BACKUP_FORMAT || ($manifest['format_version'] ?? '') !== SITE_BACKUP_FORMAT_VERSION) {
        throw new RuntimeException('The backup payload format is invalid.');
    }
    $files = $manifest['site']['files'] ?? null;
    $tables = $manifest['database']['tables'] ?? null;
    if (!is_array($files) || !is_array($tables)) throw new RuntimeException('The backup manifest is incomplete.');
    foreach ($files as $relative => $meta) {
        if (!is_string($relative) || !site_backup_safe_zip_name($relative) || site_backup_is_excluded($relative)) throw new RuntimeException('The backup contains an invalid site file entry.');
        $path = $extractedDir.'/site/'.$relative;
        if (!is_file($path) || !hash_equals((string)($meta['sha256'] ?? ''), hash_file('sha256', $path))) {
            throw new RuntimeException('Backup integrity check failed for site file: '.$relative);
        }
    }
    foreach ($tables as $table => $meta) {
        if (!preg_match('/^[A-Za-z0-9_]+$/', (string)$table)) throw new RuntimeException('The backup contains an invalid database table name.');
        $path = $extractedDir.'/'.(string)($meta['data_file'] ?? '');
        if (!is_file($path) || !hash_equals((string)($meta['data_sha256'] ?? ''), hash_file('sha256', $path))) {
            throw new RuntimeException('Backup integrity check failed for database table: '.$table);
        }
    }
    $currentSchema = site_backup_schema_snapshot(db());
    if (array_keys($currentSchema) !== array_keys($tables)) {
        throw new RuntimeException('The backup database structure does not match this installation. No changes were made. Install a compatible Aesthetic Intel package before restoring this backup.');
    }
    foreach ($tables as $table => $meta) {
        if (!hash_equals((string)$currentSchema[$table]['schema_sha256'], (string)($meta['schema_sha256'] ?? ''))) {
            throw new RuntimeException('Database structure mismatch detected for table '.$table.'. Restore was stopped before making changes.');
        }
    }
    return $manifest;
}

function site_backup_inspect_upload(array $file, string $password): array {
    site_backup_assert_capabilities();
    site_backup_cleanup_stale();
    if (strlen($password) < 10) throw new RuntimeException('Enter the backup password used when the file was created.');
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $code = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE) throw new RuntimeException('The backup exceeds the server upload limit of '.site_backup_human_bytes(site_backup_upload_limit()).'.');
        throw new RuntimeException('The backup upload did not complete.');
    }
    $size = (int)($file['size'] ?? 0);
    if ($size < 100 || $size > site_backup_upload_limit()) throw new RuntimeException('Choose a valid backup ZIP within the '.site_backup_human_bytes(site_backup_upload_limit()).' upload limit.');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string)$file['tmp_name']);
    if (!in_array($mime, ['application/zip','application/x-zip','application/x-zip-compressed','application/octet-stream'], true)) {
        throw new RuntimeException('Choose an Aesthetic Intel ZIP backup file.');
    }
    $token = bin2hex(random_bytes(20));
    $stage = site_backup_stage_dir().'/'.$token;
    if (!mkdir($stage, 0750, true)) throw new RuntimeException('Could not create the restore staging area.');
    try {
        $outerZip = $stage.'/backup.zip';
        if (!move_uploaded_file((string)$file['tmp_name'], $outerZip)) throw new RuntimeException('Could not store the uploaded backup safely.');
        $zip = new ZipArchive();
        if ($zip->open($outerZip) !== true) throw new RuntimeException('The selected file is not a valid ZIP backup.');
        try {
            $outerManifest = site_backup_read_outer_manifest($zip);
            $payloadStream = $zip->getStream('payload.enc');
            if (!$payloadStream) throw new RuntimeException('The encrypted backup payload is missing.');
            $payloadEnc = $stage.'/payload.enc';
            $out = fopen($payloadEnc, 'wb');
            if (!$out) { fclose($payloadStream); throw new RuntimeException('Could not stage the encrypted backup payload.'); }
            try {
                while (!feof($payloadStream)) {
                    $chunk = fread($payloadStream, SITE_BACKUP_CHUNK_BYTES);
                    if ($chunk === false) throw new RuntimeException('Could not read the encrypted backup payload.');
                    if ($chunk !== '' && fwrite($out, $chunk) !== strlen($chunk)) throw new RuntimeException('Could not stage the complete backup payload.');
                }
            } finally { fclose($payloadStream); fclose($out); }
        } finally { $zip->close(); }
        $innerZip = $stage.'/payload.zip';
        site_backup_decrypt_file($payloadEnc, $innerZip, $password, (array)($outerManifest['crypto'] ?? []));
        $extracted = $stage.'/extracted';
        site_backup_extract_zip_safely($innerZip, $extracted);
        $manifest = site_backup_validate_inner_manifest($extracted);
        @unlink($payloadEnc); @unlink($innerZip); @unlink($outerZip);
        $summary = [
            'token' => $token,
            'path' => $stage,
            'expires_at' => time() + SITE_BACKUP_STAGE_TTL,
            'created_at' => (string)$manifest['created_at'],
            'source_host' => (string)($manifest['source_host'] ?? ''),
            'app_version' => (string)$manifest['app_version'],
            'table_count' => (int)($manifest['database']['table_count'] ?? count($manifest['database']['tables'] ?? [])),
            'file_count' => (int)($manifest['site']['file_count'] ?? count($manifest['site']['files'] ?? [])),
            'business_count' => 0,
            'user_count' => 0,
        ];
        foreach (['businesses'=>'business_count','users'=>'user_count'] as $table=>$key) {
            if (isset($manifest['database']['tables'][$table]['row_count'])) $summary[$key]=(int)$manifest['database']['tables'][$table]['row_count'];
        }
        $_SESSION['_site_restore'] = $summary;
        return $summary;
    } catch (Throwable $e) {
        site_backup_remove_tree($stage);
        throw $e;
    }
}

function site_backup_restore_files_from_snapshot(string $snapshotDir, array $manifest): void {
    $files = $manifest['site']['files'] ?? [];
    if (!is_array($files)) throw new RuntimeException('The site file manifest is invalid.');
    $expected = [];
    foreach ($files as $relative => $meta) {
        $relative = site_backup_normalize_relative((string)$relative);
        if (!site_backup_safe_zip_name($relative) || site_backup_is_excluded($relative)) throw new RuntimeException('Unsafe restore file path detected.');
        $source = $snapshotDir.'/site/'.$relative;
        if (!is_file($source)) throw new RuntimeException('A required restored file is missing: '.$relative);
        site_backup_copy_file($source, ROOT_PATH.'/'.$relative);
        @chmod(ROOT_PATH.'/'.$relative, max(0600, min(0755, (int)($meta['mode'] ?? 0640))));
        $expected[$relative] = true;
    }
    foreach (site_backup_scan_site_files() as $relative => $absolute) {
        if (!isset($expected[$relative]) && !site_backup_is_excluded($relative)) @unlink($absolute);
    }
}

function site_backup_restore_database_from_snapshot(string $snapshotDir, array $manifest): void {
    $tables = $manifest['database']['tables'] ?? [];
    if (!is_array($tables)) throw new RuntimeException('The database restore manifest is invalid.');
    $pdo = db_reconnect();
    $currentSchema = site_backup_schema_snapshot($pdo);
    if (array_keys($currentSchema) !== array_keys($tables)) throw new RuntimeException('Database tables changed before restore could begin.');
    foreach ($tables as $table=>$meta) {
        if (!hash_equals((string)$currentSchema[$table]['schema_sha256'], (string)($meta['schema_sha256']??''))) throw new RuntimeException('Database table structure changed before restore: '.$table);
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    try {
        $pdo->beginTransaction();
        foreach (array_reverse(array_keys($tables)) as $table) $pdo->exec('DELETE FROM `'.$table.'`');
        foreach ($tables as $table => $meta) {
            $dataFile = $snapshotDir.'/'.(string)$meta['data_file'];
            $handle = fopen($dataFile, 'rb');
            if (!$handle) throw new RuntimeException('Could not read database restore data for '.$table.'.');
            $statement = null;
            $columns = [];
            $count = 0;
            try {
                while (($line = fgets($handle)) !== false) {
                    $line = trim($line);
                    if ($line === '') continue;
                    $encoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                    if (!is_array($encoded)) throw new RuntimeException('Invalid database row in table '.$table.'.');
                    if ($statement === null) {
                        $columns = array_keys($encoded);
                        foreach ($columns as $column) if (!preg_match('/^[A-Za-z0-9_]+$/', (string)$column)) throw new RuntimeException('Invalid database column in backup.');
                        $sql = 'INSERT INTO `'.$table.'` (`'.implode('`,`', $columns).'`) VALUES ('.implode(',', array_fill(0,count($columns),'?')).')';
                        $statement = $pdo->prepare($sql);
                    }
                    $values = [];
                    foreach ($columns as $column) $values[] = site_backup_decode_cell($encoded[$column] ?? null);
                    $statement->execute($values);
                    $count++;
                }
            } finally { fclose($handle); }
            if ($count !== (int)($meta['row_count'] ?? -1)) throw new RuntimeException('Database row-count validation failed for '.$table.'.');
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    } finally {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }
    foreach ($tables as $table => $meta) {
        $auto = (int)($meta['auto_increment'] ?? 0);
        if ($auto > 0) { try { $pdo->exec('ALTER TABLE `'.$table.'` AUTO_INCREMENT='.$auto); } catch (Throwable) {} }
    }
}

function site_backup_create_raw_snapshot(string $directory): array {
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) throw new RuntimeException('Could not create the rollback snapshot.');
    $tables = site_backup_snapshot_database($directory);
    $files = site_backup_snapshot_site($directory);
    $manifest = [
        'format'=>SITE_BACKUP_FORMAT,
        'format_version'=>SITE_BACKUP_FORMAT_VERSION,
        'app_version'=>(string)app_config('version'),
        'created_at'=>gmdate('c'),
        'database'=>['table_count'=>count($tables),'tables'=>$tables],
        'site'=>['file_count'=>count($files),'files'=>$files],
    ];
    file_put_contents($directory.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR), LOCK_EX);
    return $manifest;
}

function site_backup_restore_staged(string $token, string $confirmation): void {
    site_backup_assert_capabilities();
    $preview = $_SESSION['_site_restore'] ?? null;
    if (!is_array($preview) || !hash_equals((string)($preview['token'] ?? ''), $token) || (int)($preview['expires_at'] ?? 0) < time()) {
        throw new RuntimeException('The validated backup session expired. Upload and validate the backup again.');
    }
    if (strtoupper(trim($confirmation)) !== 'RESTORE') throw new RuntimeException('Type RESTORE exactly to confirm the full-site replacement.');
    $stage = (string)$preview['path'];
    $extracted = $stage.'/extracted';
    $manifest = site_backup_validate_inner_manifest($extracted);
    $lock = site_backup_lock_file();
    if (is_file($lock)) throw new RuntimeException('Another restore is already running.');
    file_put_contents($lock, json_encode(['started_at'=>gmdate('c'),'user_id'=>auth_id(),'token'=>$token]), LOCK_EX);
    @set_time_limit(0);
    ignore_user_abort(true);
    $rollbackDir = site_backup_dir().'/rollback-'.date('Ymd-His').'-'.bin2hex(random_bytes(5));
    $rollbackManifest = null;
    try {
        $rollbackManifest = site_backup_create_raw_snapshot($rollbackDir);
        site_backup_restore_files_from_snapshot($extracted, $manifest);
        site_backup_restore_database_from_snapshot($extracted, $manifest);
        db_reconnect();
        audit('site_backup_restored', ['backup_created_at'=>$manifest['created_at']??null,'source_host'=>$manifest['source_host']??null]);
        unset($_SESSION['_site_restore']);
        site_backup_remove_tree($stage);
        site_backup_remove_tree($rollbackDir);
        @unlink($lock);
    } catch (Throwable $restoreError) {
        $rollbackError = null;
        if (is_array($rollbackManifest)) {
            try {
                site_backup_restore_files_from_snapshot($rollbackDir, $rollbackManifest);
                site_backup_restore_database_from_snapshot($rollbackDir, $rollbackManifest);
            } catch (Throwable $e) { $rollbackError = $e; }
        }
        @unlink($lock);
        site_backup_remove_tree($stage);
        site_backup_remove_tree($rollbackDir);
        unset($_SESSION['_site_restore']);
        error_log('Full restore failed: '.$restoreError->getMessage().($rollbackError?' | Rollback failed: '.$rollbackError->getMessage():''));
        if ($rollbackError) throw new RuntimeException('Restore failed, and automatic rollback also needs attention. Check the Hostinger error log before using the site.');
        throw new RuntimeException('Restore failed and Aesthetic Intel automatically returned to the previous working state. No imported changes were kept.');
    }
}

function site_backup_cancel_staged(): void {
    $preview = $_SESSION['_site_restore'] ?? null;
    if (is_array($preview) && !empty($preview['path'])) site_backup_remove_tree((string)$preview['path']);
    unset($_SESSION['_site_restore']);
}

// -----------------------------------------------------------------------------
// Aesthetic Intel v1.5.8 — Automatic Daily Full-System Backups
// -----------------------------------------------------------------------------

function site_backup_automatic_settings(): array {
    $row = db()->query('SELECT * FROM automatic_backup_settings WHERE id=1')->fetch();
    return $row ?: [
        'id'=>1,'enabled'=>0,'backup_time'=>'03:00:00','timezone'=>'UTC','retention_days'=>14,
        'password_encrypted'=>null,'last_run_at'=>null,'last_success_at'=>null,'last_status'=>null,
        'last_message'=>null,'updated_by'=>null,'updated_at'=>null,
    ];
}

function site_backup_automatic_password(array $settings): string {
    $password = ai_decrypt_secret((string)($settings['password_encrypted'] ?? ''));
    if (!$password || strlen($password) < 10) {
        throw new RuntimeException('Automatic backup encryption password is not configured.');
    }
    return $password;
}

function site_backup_validate_timezone_name(string $timezone): string {
    $timezone = trim($timezone);
    if ($timezone === '') throw new RuntimeException('Choose a timezone for automatic backups.');
    try { new DateTimeZone($timezone); }
    catch (Throwable) { throw new RuntimeException('Enter a valid PHP/IANA timezone such as America/Denver, America/Chicago, or UTC.'); }
    return $timezone;
}

function site_backup_validate_time_value(string $time): string {
    $time = trim($time);
    if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $time)) {
        throw new RuntimeException('Choose a valid automatic backup time.');
    }
    $parts = explode(':', $time);
    return sprintf('%02d:%02d:00', (int)$parts[0], (int)$parts[1]);
}

function site_backup_save_automatic_settings(array $input, ?int $userId): array {
    $current = site_backup_automatic_settings();
    $enabled = !empty($input['enabled']) ? 1 : 0;
    $backupTime = site_backup_validate_time_value((string)($input['backup_time'] ?? '03:00'));
    $timezone = site_backup_validate_timezone_name((string)($input['timezone'] ?? 'UTC'));
    $retention = (int)($input['retention_days'] ?? 14);
    if (!in_array($retention, [7,14,30], true)) throw new RuntimeException('Backup retention must be 7, 14, or 30 days.');

    $newPassword = (string)($input['backup_password'] ?? '');
    $confirm = (string)($input['backup_password_confirm'] ?? '');
    $encrypted = (string)($current['password_encrypted'] ?? '');
    if ($newPassword !== '' || $confirm !== '') {
        if (strlen($newPassword) < 10) throw new RuntimeException('Use an automatic backup password with at least 10 characters.');
        if (!hash_equals($newPassword, $confirm)) throw new RuntimeException('The automatic backup passwords do not match.');
        $encrypted = ai_encrypt_secret($newPassword);
    }
    if ($enabled && $encrypted === '') throw new RuntimeException('Set an encryption password before enabling automatic daily backups.');

    $stmt = db()->prepare('UPDATE automatic_backup_settings SET enabled=?,backup_time=?,timezone=?,retention_days=?,password_encrypted=?,updated_by=? WHERE id=1');
    $stmt->execute([$enabled,$backupTime,$timezone,$retention,$encrypted ?: null,$userId]);
    return site_backup_automatic_settings();
}

function site_backup_automatic_history(int $limit=50): array {
    $limit = max(1, min(200, $limit));
    return db()->query('SELECT h.*,u.name created_by_name FROM automatic_backup_history h LEFT JOIN users u ON u.id=h.created_by ORDER BY h.id DESC LIMIT '.$limit)->fetchAll();
}

function site_backup_automatic_history_get(int $id): ?array {
    if ($id < 1) return null;
    $stmt = db()->prepare('SELECT h.*,u.name created_by_name FROM automatic_backup_history h LEFT JOIN users u ON u.id=h.created_by WHERE h.id=? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function site_backup_automatic_file_path(array $history): string {
    $filename = (string)($history['filename'] ?? '');
    if ($filename === '' || basename($filename) !== $filename || !preg_match('/^[A-Za-z0-9._-]+\.zip$/', $filename)) {
        throw new RuntimeException('The stored backup filename is invalid.');
    }
    return site_backup_automatic_dir().'/'.$filename;
}

function site_backup_build_archive_file(string $password, string $outerZipPath, string $createdBy='Automatic Daily Backup', ?string $sourceHost=null): array {
    site_backup_assert_capabilities();
    if (strlen($password) < 10) throw new RuntimeException('Use a backup password with at least 10 characters.');
    site_backup_ensure_directories();
    @set_time_limit(0);
    ignore_user_abort(true);

    $id = date('Ymd-His').'-'.bin2hex(random_bytes(5));
    $work = site_backup_dir().'/build-'.$id;
    if (!mkdir($work, 0750, true)) throw new RuntimeException('Could not create the automatic backup workspace.');
    try {
        $payloadDir = $work.'/payload';
        if (!mkdir($payloadDir, 0750, true)) throw new RuntimeException('Could not create the automatic backup payload workspace.');
        $tables = site_backup_snapshot_database($payloadDir);
        $files = site_backup_snapshot_site($payloadDir);
        if (count($tables) < 1 || count($files) < 1) throw new RuntimeException('Backup snapshot was unexpectedly empty.');
        $createdAt = gmdate('c');
        $innerManifest = [
            'format'=>SITE_BACKUP_FORMAT,
            'format_version'=>SITE_BACKUP_FORMAT_VERSION,
            'app_version'=>(string)app_config('version'),
            'created_at'=>$createdAt,
            'created_by'=>$createdBy,
            'source_host'=>$sourceHost ?? (string)($_SERVER['HTTP_HOST'] ?? php_uname('n')),
            'database'=>['table_count'=>count($tables),'tables'=>$tables],
            'site'=>['file_count'=>count($files),'files'=>$files],
            'exclusions'=>['config/database.php','storage/install.lock','storage/restore.lock','storage/automatic-backup.lock','storage/backups','storage/restore-staging','storage/logs'],
        ];
        file_put_contents($payloadDir.'/manifest.json', json_encode($innerManifest, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR), LOCK_EX);
        $innerZip = $work.'/payload.zip';
        site_backup_zip_directory($payloadDir, $innerZip);
        $encrypted = $work.'/payload.enc';
        $crypto = site_backup_encrypt_file($innerZip, $encrypted, $password);
        $outerManifest = [
            'format'=>SITE_BACKUP_FORMAT,
            'format_version'=>SITE_BACKUP_FORMAT_VERSION,
            'app_version'=>(string)app_config('version'),
            'created_at'=>$createdAt,
            'encrypted'=>true,
            'crypto'=>$crypto,
            'summary'=>['table_count'=>count($tables),'file_count'=>count($files)],
        ];
        site_backup_mkdir_for_file($outerZipPath);
        $tempOutput = $outerZipPath.'.partial-'.bin2hex(random_bytes(4));
        try {
            $zip = new ZipArchive();
            if ($zip->open($tempOutput, ZipArchive::CREATE|ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Could not create the retained backup ZIP.');
            try {
                if (!$zip->addFromString('aesthetic-intel-backup.json', json_encode($outerManifest, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))) throw new RuntimeException('Could not write backup metadata.');
                if (!$zip->addFile($encrypted, 'payload.enc')) throw new RuntimeException('Could not write encrypted backup payload.');
                if (!$zip->addFromString('README.txt', "Aesthetic Intel encrypted full backup\n\nRestore this file only through Super Admin > Backup & Restore.\nThe backup password is required.\n")) throw new RuntimeException('Could not write backup README.');
            } finally { $zip->close(); }
            @chmod($tempOutput, 0640);
            if (!@rename($tempOutput, $outerZipPath)) throw new RuntimeException('Could not finalize the retained backup file.');
        } catch (Throwable $e) {
            @unlink($tempOutput);
            throw $e;
        }
        @chmod($outerZipPath, 0640);
        $businessCount = isset($tables['businesses']['row_count']) ? (int)$tables['businesses']['row_count'] : 0;
        $userCount = isset($tables['users']['row_count']) ? (int)$tables['users']['row_count'] : 0;
        return [
            'path'=>$outerZipPath,
            'filename'=>basename($outerZipPath),
            'created_at'=>$createdAt,
            'app_version'=>(string)app_config('version'),
            'size_bytes'=>(int)(filesize($outerZipPath) ?: 0),
            'sha256'=>hash_file('sha256', $outerZipPath),
            'table_count'=>count($tables),
            'file_count'=>count($files),
            'business_count'=>$businessCount,
            'user_count'=>$userCount,
        ];
    } finally {
        site_backup_remove_tree($work);
    }
}

function site_backup_verify_archive_file(string $archivePath, string $password): array {
    site_backup_assert_capabilities();
    if (!is_file($archivePath) || filesize($archivePath) < 100) throw new RuntimeException('The retained backup file is missing or empty.');
    $id = 'verify-'.date('Ymd-His').'-'.bin2hex(random_bytes(5));
    $stage = site_backup_stage_dir().'/'.$id;
    if (!mkdir($stage, 0750, true)) throw new RuntimeException('Could not create the backup verification workspace.');
    try {
        $zip = new ZipArchive();
        if ($zip->open($archivePath, ZipArchive::CHECKCONS) !== true) throw new RuntimeException('The retained backup ZIP failed its archive integrity check.');
        try {
            $outerManifest = site_backup_read_outer_manifest($zip);
            if (empty($outerManifest['encrypted']) || empty($outerManifest['crypto'])) throw new RuntimeException('The retained backup encryption metadata is missing.');
            $payloadStream = $zip->getStream('payload.enc');
            if (!$payloadStream) throw new RuntimeException('The retained backup encrypted payload is missing.');
            $payloadEnc = $stage.'/payload.enc';
            $out = fopen($payloadEnc, 'wb');
            if (!$out) { fclose($payloadStream); throw new RuntimeException('Could not stage the retained backup payload.'); }
            try {
                while (!feof($payloadStream)) {
                    $chunk = fread($payloadStream, SITE_BACKUP_CHUNK_BYTES);
                    if ($chunk === false) throw new RuntimeException('Could not read the retained backup payload.');
                    if ($chunk !== '' && fwrite($out, $chunk) !== strlen($chunk)) throw new RuntimeException('Could not stage the complete retained backup payload.');
                }
            } finally { fclose($payloadStream); fclose($out); }
        } finally { $zip->close(); }
        $innerZip = $stage.'/payload.zip';
        site_backup_decrypt_file($payloadEnc, $innerZip, $password, (array)$outerManifest['crypto']);
        $extracted = $stage.'/extracted';
        site_backup_extract_zip_safely($innerZip, $extracted);
        $manifest = site_backup_validate_inner_manifest($extracted);
        $tables = $manifest['database']['tables'] ?? [];
        $files = $manifest['site']['files'] ?? [];
        $declaredTables = (int)($manifest['database']['table_count'] ?? -1);
        $declaredFiles = (int)($manifest['site']['file_count'] ?? -1);
        if ($declaredTables < 1 || $declaredFiles < 1 || $declaredTables !== count($tables) || $declaredFiles !== count($files)) {
            throw new RuntimeException('Backup manifest counts are incomplete or inconsistent.');
        }
        return [
            'verified'=>true,
            'created_at'=>(string)($manifest['created_at'] ?? ''),
            'app_version'=>(string)($manifest['app_version'] ?? ''),
            'table_count'=>$declaredTables,
            'file_count'=>$declaredFiles,
            'business_count'=>(int)($tables['businesses']['row_count'] ?? 0),
            'user_count'=>(int)($tables['users']['row_count'] ?? 0),
            'size_bytes'=>(int)(filesize($archivePath) ?: 0),
            'sha256'=>hash_file('sha256', $archivePath),
        ];
    } finally {
        site_backup_remove_tree($stage);
    }
}

function site_backup_record_automatic_result(string $runType, ?string $scheduledDate, string $status, array $meta, ?int $userId, string $timezone, ?string $error=null): array {
    if (!in_array($runType, ['automatic','manual_test'], true)) throw new RuntimeException('Invalid retained backup run type.');
    if (!in_array($status, ['verified','failed'], true)) throw new RuntimeException('Invalid retained backup result status.');
    $existing = null;
    if ($runType === 'automatic' && $scheduledDate) {
        $stmt = db()->prepare("SELECT * FROM automatic_backup_history WHERE run_type='automatic' AND scheduled_local_date=? LIMIT 1");
        $stmt->execute([$scheduledDate]);
        $existing = $stmt->fetch() ?: null;
    }
    $filename = (string)($meta['filename'] ?? '');
    $passwordEncrypted = (string)($meta['password_encrypted'] ?? '');
    $validationStatus = $status === 'verified' ? 'verified' : 'failed';
    $validationMessage = $status === 'verified' ? 'Backup Verified: ZIP, encryption metadata, database snapshot, manifest, file/table counts, and SHA-256 checks passed.' : ($error ?: 'Backup verification failed.');
    if ($existing) {
        $stmt = db()->prepare('UPDATE automatic_backup_history SET status=?,filename=?,password_encrypted=?,size_bytes=?,sha256=?,table_count=?,file_count=?,business_count=?,user_count=?,app_version=?,backup_timezone=?,validation_status=?,validated_at=?,validation_message=?,error_message=?,attempt_count=attempt_count+1,last_started_at=UTC_TIMESTAMP(),completed_at=UTC_TIMESTAMP(),created_by=? WHERE id=?');
        $stmt->execute([$status,$filename ?: null,$passwordEncrypted ?: null,(int)($meta['size_bytes']??0),($meta['sha256']??null),(int)($meta['table_count']??0),(int)($meta['file_count']??0),(int)($meta['business_count']??0),(int)($meta['user_count']??0),(string)($meta['app_version']??app_config('version')),$timezone,$validationStatus,$status==='verified'?gmdate('Y-m-d H:i:s'):null,$validationMessage,$error,$userId,(int)$existing['id']]);
        return site_backup_automatic_history_get((int)$existing['id']) ?? [];
    }
    $stmt = db()->prepare('INSERT INTO automatic_backup_history(run_type,scheduled_local_date,status,filename,password_encrypted,size_bytes,sha256,table_count,file_count,business_count,user_count,app_version,backup_timezone,validation_status,validated_at,validation_message,error_message,attempt_count,last_started_at,completed_at,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,UTC_TIMESTAMP(),UTC_TIMESTAMP(),?)');
    $stmt->execute([$runType,$scheduledDate,$status,$filename ?: null,$passwordEncrypted ?: null,(int)($meta['size_bytes']??0),($meta['sha256']??null),(int)($meta['table_count']??0),(int)($meta['file_count']??0),(int)($meta['business_count']??0),(int)($meta['user_count']??0),(string)($meta['app_version']??app_config('version')),$timezone,$validationStatus,$status==='verified'?gmdate('Y-m-d H:i:s'):null,$validationMessage,$error,$userId]);
    return site_backup_automatic_history_get((int)db()->lastInsertId()) ?? [];
}

function site_backup_update_automatic_status(string $status, string $message, bool $success): void {
    $sql = 'UPDATE automatic_backup_settings SET last_run_at=UTC_TIMESTAMP(),last_status=?,last_message=?'.($success?',last_success_at=UTC_TIMESTAMP()':'').' WHERE id=1';
    $stmt = db()->prepare($sql);
    $stmt->execute([$status, substr($message,0,500)]);
}

function site_backup_apply_automatic_retention(int $keep): void {
    $keep = in_array($keep,[7,14,30],true) ? $keep : 14;
    $rows = db()->query("SELECT * FROM automatic_backup_history WHERE run_type='automatic' AND status='verified' AND filename IS NOT NULL ORDER BY completed_at DESC,id DESC")->fetchAll();
    if (count($rows) <= $keep) return;
    foreach (array_slice($rows,$keep) as $row) {
        try {
            $path = site_backup_automatic_file_path($row);
            if (is_file($path) && !@unlink($path)) continue;
            $stmt = db()->prepare("UPDATE automatic_backup_history SET status='deleted',password_encrypted=NULL,deleted_reason='retention',deleted_at=UTC_TIMESTAMP() WHERE id=?");
            $stmt->execute([(int)$row['id']]);
        } catch (Throwable $e) {
            error_log('Automatic backup retention warning: '.$e->getMessage());
        }
    }
}

function site_backup_run_retained(string $runType='automatic', ?int $userId=null, ?string $scheduledDate=null): array {
    $settings = site_backup_automatic_settings();
    $password = site_backup_automatic_password($settings);
    $timezone = site_backup_validate_timezone_name((string)($settings['timezone'] ?? 'UTC'));
    site_backup_ensure_directories();

    $lockHandle = fopen(site_backup_automatic_lock_file(), 'c+');
    if (!$lockHandle) throw new RuntimeException('Could not open the automatic backup lock.');
    if (!flock($lockHandle, LOCK_EX|LOCK_NB)) { fclose($lockHandle); throw new RuntimeException('Another automatic backup is already running.'); }
    @ftruncate($lockHandle,0);
    @fwrite($lockHandle,json_encode(['started_at'=>gmdate('c'),'run_type'=>$runType]));
    @fflush($lockHandle);

    $localNow = new DateTimeImmutable('now', new DateTimeZone($timezone));
    if ($runType === 'automatic' && !$scheduledDate) $scheduledDate = $localNow->format('Y-m-d');
    $prefix = $runType === 'automatic' ? 'auto' : 'test';
    $filename = 'aesthetic-intel-'.$prefix.'-'.$localNow->format('Ymd-His').'-'.bin2hex(random_bytes(3)).'.zip';
    $finalPath = site_backup_automatic_dir().'/'.$filename;
    $tempPath = site_backup_automatic_dir().'/.creating-'.$filename;
    $meta = ['filename'=>$filename,'app_version'=>(string)app_config('version'),'password_encrypted'=>(string)($settings['password_encrypted']??'')];
    try {
        $creator = $runType === 'automatic' ? 'Automatic Daily Backup' : (string)(auth_user()['email'] ?? 'Super Admin test backup');
        $meta = site_backup_build_archive_file($password, $tempPath, $creator);
        $verified = site_backup_verify_archive_file($tempPath, $password);
        if (!hash_equals((string)$meta['sha256'], (string)$verified['sha256'])) throw new RuntimeException('Final archive SHA-256 changed during verification.');
        if (!@rename($tempPath,$finalPath)) throw new RuntimeException('Could not move the verified backup into protected retained storage.');
        @chmod($finalPath,0640);
        $meta = $verified + ['filename'=>$filename,'path'=>$finalPath,'password_encrypted'=>(string)($settings['password_encrypted']??'')];
        $row = site_backup_record_automatic_result($runType,$scheduledDate,'verified',$meta,$userId,$timezone,null);
        site_backup_update_automatic_status('verified','Backup Verified.',true);
        if ($runType === 'automatic') site_backup_apply_automatic_retention((int)$settings['retention_days']);
        try { audit('automatic_backup_verified',['run_type'=>$runType,'history_id'=>$row['id']??null,'scheduled_local_date'=>$scheduledDate,'sha256'=>$meta['sha256']??null]); } catch (Throwable) {}
        return $row;
    } catch (Throwable $e) {
        @unlink($tempPath); @unlink($finalPath);
        $safeError = substr($e->getMessage(),0,1000);
        $row = site_backup_record_automatic_result($runType,$scheduledDate,'failed',$meta,$userId,$timezone,$safeError);
        site_backup_update_automatic_status('failed','Automatic backup failed. Check Backup History for details.',false);
        error_log('Automatic backup failed: '.$e->getMessage());
        try { audit('automatic_backup_failed',['run_type'=>$runType,'history_id'=>$row['id']??null,'scheduled_local_date'=>$scheduledDate]); } catch (Throwable) {}
        throw new RuntimeException('Automatic backup failed safely. Existing verified backups were not deleted.');
    } finally {
        @ftruncate($lockHandle,0);
        flock($lockHandle,LOCK_UN);
        fclose($lockHandle);
        @unlink(site_backup_automatic_lock_file());
    }
}

function site_backup_automatic_due(array $settings, ?DateTimeImmutable $utcNow=null): array {
    if (empty($settings['enabled'])) return ['due'=>false,'reason'=>'disabled'];
    try { site_backup_automatic_password($settings); } catch (Throwable) { return ['due'=>false,'reason'=>'password_missing']; }
    $timezone = site_backup_validate_timezone_name((string)($settings['timezone'] ?? 'UTC'));
    $tz = new DateTimeZone($timezone);
    $utcNow = $utcNow ?: new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $localNow = $utcNow->setTimezone($tz);
    $date = $localNow->format('Y-m-d');
    [$hour,$minute] = array_map('intval', array_slice(explode(':',(string)$settings['backup_time']),0,2));
    $scheduled = $localNow->setTime($hour,$minute,0);
    if ($localNow < $scheduled) return ['due'=>false,'reason'=>'before_time','scheduled_local_date'=>$date,'scheduled_at'=>$scheduled];

    $stmt = db()->prepare("SELECT * FROM automatic_backup_history WHERE run_type='automatic' AND scheduled_local_date=? LIMIT 1");
    $stmt->execute([$date]);
    $row = $stmt->fetch() ?: null;
    if (!$row) return ['due'=>true,'reason'=>'scheduled','scheduled_local_date'=>$date,'scheduled_at'=>$scheduled];
    if (in_array((string)$row['status'],['verified','deleted'],true)) return ['due'=>false,'reason'=>'already_completed','scheduled_local_date'=>$date,'history'=>$row];
    if ((int)($row['attempt_count']??0) >= 3) return ['due'=>false,'reason'=>'retry_limit','scheduled_local_date'=>$date,'history'=>$row];
    $last = !empty($row['last_started_at']) ? new DateTimeImmutable((string)$row['last_started_at'],new DateTimeZone('UTC')) : null;
    if ($last && ($utcNow->getTimestamp()-$last->getTimestamp()) < 1800) return ['due'=>false,'reason'=>'retry_wait','scheduled_local_date'=>$date,'history'=>$row];
    return ['due'=>true,'reason'=>'retry','scheduled_local_date'=>$date,'scheduled_at'=>$scheduled,'history'=>$row];
}

function site_backup_automatic_retry_state(?array $settings=null): array {
    $settings = $settings ?: site_backup_automatic_settings();
    $timezone = site_backup_validate_timezone_name((string)($settings['timezone'] ?? 'UTC'));
    $localNow = new DateTimeImmutable('now', new DateTimeZone($timezone));
    $date = $localNow->format('Y-m-d');
    $stmt = db()->prepare("SELECT * FROM automatic_backup_history WHERE run_type='automatic' AND scheduled_local_date=? LIMIT 1");
    $stmt->execute([$date]);
    $row = $stmt->fetch() ?: null;
    $attempts = $row ? (int)($row['attempt_count'] ?? 0) : 0;
    $status = $row ? (string)($row['status'] ?? '') : '';
    return [
        'scheduled_local_date'=>$date,
        'timezone'=>$timezone,
        'history'=>$row,
        'attempt_count'=>$attempts,
        'can_reset'=>(bool)($row && $status === 'failed' && $attempts >= 3),
    ];
}

function site_backup_reset_today_retry(?int $userId=null): array {
    $state = site_backup_automatic_retry_state();
    $row = $state['history'] ?? null;
    if (!$row) throw new RuntimeException('There is no automatic backup attempt to reset for today.');
    $status = (string)($row['status'] ?? '');
    if ($status === 'verified' || $status === 'deleted') throw new RuntimeException('Today\'s automatic backup is already complete and cannot be reset.');
    if ($status === 'running') throw new RuntimeException('An automatic backup is currently running. Wait for it to finish before resetting retries.');
    if ($status !== 'failed') throw new RuntimeException('Only a failed automatic backup retry can be reset.');

    $previousAttempts = (int)($row['attempt_count'] ?? 0);
    if ($previousAttempts < 3) throw new RuntimeException('Today\'s automatic backup has not reached the retry limit. Let the normal retry protection continue.');

    // Preserve the failed status/error text for audit visibility. Only the retry
    // counter and cooldown timestamp are cleared so the next cron tick can retry.
    $stmt = db()->prepare("UPDATE automatic_backup_history SET attempt_count=0,last_started_at=NULL WHERE id=? AND run_type='automatic' AND status='failed'");
    $stmt->execute([(int)$row['id']]);
    if ($stmt->rowCount() < 1) throw new RuntimeException('The retry state changed before it could be reset. Refresh and try again.');

    return [
        'history_id'=>(int)$row['id'],
        'scheduled_local_date'=>(string)$state['scheduled_local_date'],
        'previous_attempt_count'=>$previousAttempts,
        'already_reset'=>false,
        'reset_by'=>$userId,
    ];
}

function site_backup_automatic_next_run(array $settings): array {
    $timezone = site_backup_validate_timezone_name((string)($settings['timezone'] ?? 'UTC'));
    $tz = new DateTimeZone($timezone);
    $now = new DateTimeImmutable('now',$tz);
    [$hour,$minute] = array_map('intval', array_slice(explode(':',(string)($settings['backup_time']??'03:00:00')),0,2));
    $candidate = $now->setTime($hour,$minute,0);
    $due = site_backup_automatic_due($settings,new DateTimeImmutable('now',new DateTimeZone('UTC')));
    if (!empty($due['due'])) return ['label'=>'Due now','at'=>$candidate,'due'=>true];
    if ($candidate <= $now || ($due['reason']??'') === 'already_completed') $candidate = $candidate->modify('+1 day');
    return ['label'=>$candidate->format('m-d-Y g:i A T'),'at'=>$candidate,'due'=>false];
}

function site_backup_cron_tick(): array {
    $settings = site_backup_automatic_settings();
    $due = site_backup_automatic_due($settings);
    if (empty($due['due'])) return ['ran'=>false,'reason'=>$due['reason']??'not_due'];
    $row = site_backup_run_retained('automatic',null,(string)$due['scheduled_local_date']);
    return ['ran'=>true,'history_id'=>(int)($row['id']??0),'status'=>(string)($row['status']??'verified')];
}

function site_backup_stage_existing_archive(string $archivePath, string $password): array {
    site_backup_assert_capabilities();
    site_backup_cleanup_stale();
    if (!is_file($archivePath)) throw new RuntimeException('The retained backup file could not be found.');
    $token = bin2hex(random_bytes(20));
    $stage = site_backup_stage_dir().'/'.$token;
    if (!mkdir($stage,0750,true)) throw new RuntimeException('Could not create the restore staging area.');
    try {
        $outerZip = $stage.'/backup.zip';
        if (!copy($archivePath,$outerZip)) throw new RuntimeException('Could not stage the retained backup safely.');
        $zip = new ZipArchive();
        if ($zip->open($outerZip,ZipArchive::CHECKCONS)!==true) throw new RuntimeException('The retained backup is not a valid ZIP archive.');
        try {
            $outerManifest = site_backup_read_outer_manifest($zip);
            $payloadStream = $zip->getStream('payload.enc');
            if (!$payloadStream) throw new RuntimeException('The encrypted backup payload is missing.');
            $payloadEnc = $stage.'/payload.enc';
            $out = fopen($payloadEnc,'wb');
            if (!$out) { fclose($payloadStream); throw new RuntimeException('Could not stage the encrypted backup payload.'); }
            try {
                while (!feof($payloadStream)) {
                    $chunk=fread($payloadStream,SITE_BACKUP_CHUNK_BYTES);
                    if ($chunk===false) throw new RuntimeException('Could not read retained backup payload.');
                    if ($chunk!==''&&fwrite($out,$chunk)!==strlen($chunk)) throw new RuntimeException('Could not stage the complete retained backup payload.');
                }
            } finally { fclose($payloadStream); fclose($out); }
        } finally { $zip->close(); }
        $innerZip=$stage.'/payload.zip';
        site_backup_decrypt_file($payloadEnc,$innerZip,$password,(array)($outerManifest['crypto']??[]));
        $extracted=$stage.'/extracted';
        site_backup_extract_zip_safely($innerZip,$extracted);
        $manifest=site_backup_validate_inner_manifest($extracted);
        @unlink($payloadEnc);@unlink($innerZip);@unlink($outerZip);
        $summary=[
            'token'=>$token,'path'=>$stage,'expires_at'=>time()+SITE_BACKUP_STAGE_TTL,
            'created_at'=>(string)$manifest['created_at'],'source_host'=>(string)($manifest['source_host']??''),
            'app_version'=>(string)$manifest['app_version'],
            'table_count'=>(int)($manifest['database']['table_count']??count($manifest['database']['tables']??[])),
            'file_count'=>(int)($manifest['site']['file_count']??count($manifest['site']['files']??[])),
            'business_count'=>(int)($manifest['database']['tables']['businesses']['row_count']??0),
            'user_count'=>(int)($manifest['database']['tables']['users']['row_count']??0),
        ];
        $_SESSION['_site_restore']=$summary;
        return $summary;
    } catch (Throwable $e) {
        site_backup_remove_tree($stage);
        throw $e;
    }
}

function site_backup_history_password(array $history): string {
    $encrypted=(string)($history['password_encrypted']??'');
    $password=ai_decrypt_secret($encrypted);
    if($password&&strlen($password)>=10)return $password;
    return site_backup_automatic_password(site_backup_automatic_settings());
}

function site_backup_validate_retained(int $id): array {
    $history = site_backup_automatic_history_get($id);
    if (!$history || (string)$history['status'] !== 'verified') throw new RuntimeException('Choose an available verified retained backup.');
    $path = site_backup_automatic_file_path($history);
    $password = site_backup_history_password($history);
    try {
        $verified = site_backup_verify_archive_file($path,$password);
        $stmt=db()->prepare("UPDATE automatic_backup_history SET validation_status='verified',validated_at=UTC_TIMESTAMP(),validation_message=? WHERE id=?");
        $stmt->execute(['Backup Verified: integrity and current restore compatibility checks passed.',$id]);
        return $verified;
    } catch (Throwable $e) {
        $stmt=db()->prepare("UPDATE automatic_backup_history SET validation_status='failed',validated_at=UTC_TIMESTAMP(),validation_message=? WHERE id=?");
        $stmt->execute([substr($e->getMessage(),0,500),$id]);
        throw $e;
    }
}

function site_backup_prepare_retained_restore(int $id): array {
    $history = site_backup_automatic_history_get($id);
    if (!$history || (string)$history['status'] !== 'verified') throw new RuntimeException('Choose an available verified retained backup.');
    $password = site_backup_history_password($history);
    return site_backup_stage_existing_archive(site_backup_automatic_file_path($history),$password);
}

function site_backup_delete_retained(int $id): void {
    $history = site_backup_automatic_history_get($id);
    if (!$history) throw new RuntimeException('Backup history entry not found.');
    if ((string)$history['status'] === 'deleted') return;
    $filename=(string)($history['filename']??'');
    if ($filename!=='') {
        $path=site_backup_automatic_file_path($history);
        if (is_file($path)&&!@unlink($path)) throw new RuntimeException('Could not remove the retained backup file from protected storage.');
    }
    $stmt=db()->prepare("UPDATE automatic_backup_history SET status='deleted',password_encrypted=NULL,deleted_reason='manual',deleted_at=UTC_TIMESTAMP() WHERE id=?");
    $stmt->execute([$id]);
}

function site_backup_download_retained(int $id): never {
    $history=site_backup_automatic_history_get($id);
    if(!$history||(string)$history['status']!=='verified')throw new RuntimeException('Choose an available verified retained backup.');
    $path=site_backup_automatic_file_path($history);
    if(!is_file($path))throw new RuntimeException('The retained backup file is missing.');
    $filename=basename($path);
    if(ob_get_level())while(ob_get_level())ob_end_clean();
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    header('Content-Length: '.filesize($path));
    header('Cache-Control: no-store, no-cache, must-revalidate');
    $stream=fopen($path,'rb');
    if(!$stream)throw new RuntimeException('Could not stream the retained backup.');
    while(!feof($stream)){ $chunk=fread($stream,SITE_BACKUP_CHUNK_BYTES); if($chunk===false)break; echo $chunk; flush(); }
    fclose($stream);
    exit;
}
