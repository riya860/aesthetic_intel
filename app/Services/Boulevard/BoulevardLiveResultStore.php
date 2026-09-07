<?php

declare(strict_types=1);

/**
 * Stores the complete privacy-safe Boulevard Live Console payload outside the
 * PHP session. This lets the API fetch every cursor page while the UI still
 * renders small pages without exhausting session memory.
 *
 * The storage directory is already protected by Aesthetic Intel's storage
 * .htaccess. Only the base operational datasets are persisted here; analytics
 * enrichment that contains opaque client IDs is calculated in-memory and only
 * its derived KPI output is kept in the session.
 */
final class BoulevardLiveResultStore
{
    private const DIRECTORY = __DIR__ . '/../../../storage/boulevard-live-console';
    private const TTL_SECONDS = 86400; // 24 hours

    public static function save(array $payload): string
    {
        self::ensureDirectory();
        self::cleanup();

        $key = bin2hex(random_bytes(20));
        $path = self::path($key);
        $tmp = $path . '.tmp-' . bin2hex(random_bytes(6));

        $json = json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        if (file_put_contents($tmp, $json, LOCK_EX) === false) {
            @unlink($tmp);
            throw new RuntimeException('Could not persist the complete Boulevard fetch result.');
        }

        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Could not finalize the Boulevard fetch cache.');
        }

        return $key;
    }

    public static function load(string $key): ?array
    {
        if (!preg_match('/^[a-f0-9]{40}$/', $key)) {
            return null;
        }

        $path = self::path($key);
        if (!is_file($path)) {
            return null;
        }

        $mtime = @filemtime($path);
        if ($mtime !== false && $mtime < time() - self::TTL_SECONDS) {
            @unlink($path);
            return null;
        }

        $json = @file_get_contents($path);
        if ($json === false || $json === '') {
            return null;
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    public static function delete(?string $key): void
    {
        $key = trim((string)$key);
        if (!preg_match('/^[a-f0-9]{40}$/', $key)) {
            return;
        }
        @unlink(self::path($key));
    }

    public static function page(array $rows, int $page, int $perPage = 100): array
    {
        $perPage = max(10, min(250, $perPage));
        $total = count($rows);
        $pages = max(1, (int)ceil($total / $perPage));
        $page = max(1, min($pages, $page));
        $offset = ($page - 1) * $perPage;

        return [
            'rows' => array_slice($rows, $offset, $perPage),
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'pages' => $pages,
                'from' => $total === 0 ? 0 : $offset + 1,
                'to' => min($total, $offset + $perPage),
            ],
        ];
    }

    private static function ensureDirectory(): void
    {
        if (is_dir(self::DIRECTORY)) {
            return;
        }

        if (!@mkdir(self::DIRECTORY, 0775, true) && !is_dir(self::DIRECTORY)) {
            throw new RuntimeException('Could not create Boulevard live-result storage directory.');
        }
    }

    private static function cleanup(): void
    {
        if (!is_dir(self::DIRECTORY)) {
            return;
        }

        $threshold = time() - self::TTL_SECONDS;
        foreach (glob(self::DIRECTORY . '/*.json') ?: [] as $file) {
            $mtime = @filemtime($file);
            if ($mtime !== false && $mtime < $threshold) {
                @unlink($file);
            }
        }
    }

    private static function path(string $key): string
    {
        return self::DIRECTORY . '/' . $key . '.json';
    }
}
