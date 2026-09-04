<?php

declare(strict_types=1);


/*
 * ============================================================
 * CORE PATHS
 * ============================================================
 */

define(
    'ROOT_PATH',
    dirname(__DIR__)
);

define(
    'APP_PATH',
    ROOT_PATH . '/app'
);

define(
    'VIEW_PATH',
    ROOT_PATH . '/views'
);

define(
    'STORAGE_PATH',
    ROOT_PATH . '/storage'
);


/*
 * ============================================================
 * COMPOSER AUTOLOADER
 * ============================================================
 *
 * Must be loaded before Dotenv.
 */

$autoloadFile =
    ROOT_PATH . '/vendor/autoload.php';

if (!is_file($autoloadFile)) {

    http_response_code(500);

    exit(
        'Composer dependencies are missing. '
        . 'Run composer install.'
    );
}

require_once $autoloadFile;


/*
 * ============================================================
 * LOAD .ENV
 * ============================================================
 *
 * IMPORTANT:
 *
 * This MUST happen before:
 *
 * - config/app.php
 * - config/database.php
 * - PDO connection
 * - Google configuration
 * - any integration configuration
 */

$envFile =
    ROOT_PATH . '/.env';

if (
    class_exists(\Dotenv\Dotenv::class)
    &&
    is_file($envFile)
) {

    $dotenv =
        \Dotenv\Dotenv::createImmutable(
            ROOT_PATH
        );

    $dotenv->safeLoad();
}


/*
 * ============================================================
 * APPLICATION CONFIG
 * ============================================================
 */

$GLOBALS['app_config'] =
    require ROOT_PATH . '/config/app.php';


/*
 * ============================================================
 * RESTORE / MAINTENANCE LOCK
 * ============================================================
 */

$restoreLock =
    STORAGE_PATH . '/restore.lock';


if (is_file($restoreLock)) {

    $age =
        time()
        -
        (int)(
            filemtime($restoreLock)
            ?: time()
        );


    /*
     * Automatically remove stale restore locks
     * older than one hour.
     */
    if ($age > 3600) {

        @unlink(
            $restoreLock
        );

    } else {

        http_response_code(503);

        header(
            'Retry-After: 120'
        );

        header(
            'Content-Type: text/html; charset=utf-8'
        );


        exit(
            '<!doctype html>
            <html>
            <head>
                <meta charset="utf-8">
                <meta
                    name="viewport"
                    content="width=device-width,initial-scale=1"
                >
                <title>
                    Aesthetic Intel maintenance
                </title>
            </head>

            <body
                style="
                    font-family:system-ui;
                    background:#f4f7fb;
                    color:#10213d;
                    display:grid;
                    place-items:center;
                    min-height:100vh;
                    margin:0
                "
            >

                <main
                    style="
                        max-width:560px;
                        padding:32px;
                        background:white;
                        border-radius:18px;
                        box-shadow:
                            0 20px 60px
                            rgba(15,35,70,.12);
                        text-align:center
                    "
                >

                    <h1>
                        Restoring Aesthetic Intel
                    </h1>

                    <p>
                        The administrator is restoring
                        a verified backup. Please wait
                        a few minutes and refresh this page.
                    </p>

                </main>

            </body>
            </html>'
        );
    }
}


/*
 * ============================================================
 * TIMEZONE
 * ============================================================
 */

date_default_timezone_set(
    (string)(
        $GLOBALS['app_config']['timezone']
        ?? $_ENV['APP_TIMEZONE']
        ?? 'UTC'
    )
);


/*
 * ============================================================
 * APPLICATION MODULES
 * ============================================================
 */


/*
 * Google Analytics 4
 */
require_once
    APP_PATH
    . '/integrations/ga4-auth.php';

require_once
    APP_PATH
    . '/integrations/ga4-api.php';

require_once
    APP_PATH
    . '/integrations/ga4-mapper.php';

require_once
    APP_PATH
    . '/integrations/ga4-validation.php';


/*
 * Core modules
 */
require_once
    APP_PATH
    . '/helpers.php';

require_once
    APP_PATH
    . '/reporting-period.php';

require_once
    APP_PATH
    . '/auth.php';

require_once
    APP_PATH
    . '/parsers.php';

require_once
    APP_PATH
    . '/analytics.php';

require_once
    APP_PATH
    . '/upload.php';

require_once
    APP_PATH
    . '/gbp.php';

require_once
    APP_PATH
    . '/openai.php';

require_once
    APP_PATH
    . '/boulevard-api.php';

require_once
    APP_PATH
    . '/ai-extraction.php';

require_once
    APP_PATH
    . '/report-validation.php';

require_once
    APP_PATH
    . '/unified-report.php';

require_once
    APP_PATH
    . '/data-transfer.php';

require_once
    APP_PATH
    . '/backup.php';

require_once
    APP_PATH
    . '/business-delete.php';

require_once
    APP_PATH
    . '/features.php';

require_once
    APP_PATH
    . '/provider-kpi.php';

require_once
    APP_PATH
    . '/documentation.php';

require_once
    APP_PATH
    . '/smart-search.php';

require_once
    APP_PATH
    . '/ai-report-review.php';

    require_once APP_PATH . '/openai-weekly.php';
require_once APP_PATH . '/ai-weekly-report.php';
require_once
    APP_PATH
    . '/migrations.php';

require_once
    APP_PATH
    . '/feature-availability.php';


/*
 * ============================================================
 * SESSION
 * ============================================================
 */

if (
    session_status()
    !== PHP_SESSION_ACTIVE
) {

    session_name(
        (string)app_config(
            'session_name',
            'aesthetic_intel_session'
        )
    );


    /*
     * On Hostinger HTTPS this becomes true.
     *
     * Local HTTP development remains usable.
     */
    $https =
        !empty($_SERVER['HTTPS'])
        &&
        $_SERVER['HTTPS'] !== 'off';


    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);


    session_start();
}


/*
 * ============================================================
 * SECURITY HEADERS
 * ============================================================
 */

header(
    'X-Content-Type-Options: nosniff'
);

header(
    'X-Frame-Options: SAMEORIGIN'
);

header(
    'Referrer-Policy: strict-origin-when-cross-origin'
);


/*
 * ============================================================
 * DATABASE CONFIGURATION
 * ============================================================
 */

$dbFile =
    ROOT_PATH
    . '/config/database.php';


if (!is_file($dbFile)) {

    /*
     * Do not redirect production users to localhost.
     */
    if (
        basename(
            $_SERVER['SCRIPT_NAME']
            ?? ''
        )
        !== 'install.php'
    ) {

        http_response_code(500);

        exit(
            'Database configuration is missing.'
        );
    }


    return;
}


/*
 * database.php now reads values loaded from .env.
 */
$dbConfig =
    require $dbFile;


/*
 * Validate required configuration before PDO.
 */

$requiredDbKeys = [
    'host',
    'name',
    'user',
    'password',
];


foreach (
    $requiredDbKeys
    as $requiredKey
) {

    if (
        !array_key_exists(
            $requiredKey,
            $dbConfig
        )
        ||
        (
            $requiredKey !== 'password'
            &&
            trim(
                (string)$dbConfig[
                    $requiredKey
                ]
            ) === ''
        )
    ) {

        throw new RuntimeException(
            'Database configuration is incomplete: '
            . $requiredKey
        );
    }
}


/*
 * ============================================================
 * PDO DATABASE CONNECTION
 * ============================================================
 */

$dsn =
    sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',

        $dbConfig['host'],

        $dbConfig['port']
            ?? '3306',

        $dbConfig['name'],

        $dbConfig['charset']
            ?? 'utf8mb4'
    );


try {

    $pdo =
        new PDO(
            $dsn,

            $dbConfig['user'],

            $dbConfig['password'],

            [
                PDO::ATTR_ERRMODE =>
                    PDO::ERRMODE_EXCEPTION,

                PDO::ATTR_DEFAULT_FETCH_MODE =>
                    PDO::FETCH_ASSOC,

                PDO::ATTR_EMULATE_PREPARES =>
                    false,
            ]
        );


} catch (PDOException $e) {

    /*
     * Don't expose database credentials or
     * full infrastructure details in production.
     */

    error_log(
        'Database connection failed: '
        . $e->getMessage()
    );


    $isProduction =
        (
            $_ENV['APP_ENV']
            ?? 'production'
        )
        === 'production';


    if ($isProduction) {

        http_response_code(500);

        exit(
            '<!doctype html>
            <html>
            <head>
                <meta charset="utf-8">
                <meta
                    name="viewport"
                    content="width=device-width,initial-scale=1"
                >
                <title>
                    Aesthetic Intel
                </title>
            </head>

            <body
                style="
                    font-family:system-ui;
                    background:#f5f5f7;
                    color:#202124;
                    display:grid;
                    place-items:center;
                    min-height:100vh;
                    margin:0;
                "
            >

                <main
                    style="
                        width:min(90%,520px);
                        padding:32px;
                        background:#fff;
                        border-radius:20px;
                        box-shadow:
                            0 20px 60px
                            rgba(0,0,0,.08);
                        text-align:center;
                    "
                >

                    <h1>
                        Aesthetic Intel
                    </h1>

                    <p>
                        We could not connect to the
                        application database.
                        Please contact the administrator.
                    </p>

                </main>

            </body>
            </html>'
        );
    }


    /*
     * Local development can still show the
     * actual database exception.
     */
    throw $e;
}


/*
 * Make PDO available through the application's
 * existing db() helper.
 */
$GLOBALS['pdo'] =
    $pdo;


/*
 * ============================================================
 * DATABASE MIGRATIONS
 * ============================================================
 */

run_app_migrations();


/*
 * ============================================================
 * AUTH SECURITY STATE
 * ============================================================
 */

if (auth_check()) {

    auth_sync_security_state();
}


/*
 * ============================================================
 * SESSION TIMEOUT
 * ============================================================
 */

if (auth_check()) {

    $last =
        (int)(
            $_SESSION['last_activity']
            ?? time()
        );


    $timeout =
        (int)app_config(
            'session_timeout',
            7200
        );


    if (
        time()
        -
        $last
        >
        $timeout
    ) {

        auth_logout();


        if (
            session_status()
            !== PHP_SESSION_ACTIVE
        ) {

            session_start();
        }


        flash(
            'warning',
            'Your session expired. Please sign in again.'
        );


        header(
            'Location: '
            . url('login')
        );


        exit;
    }


    $_SESSION['last_activity'] =
        time();
}