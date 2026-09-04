<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| AESTHETIC INTEL - BOULEVARD SYNC
|--------------------------------------------------------------------------
|
| This script synchronizes Boulevard reference data for RUMA
| into the Aesthetic Intel database.
|
| Current sync flow:
|
| 1. Load Aesthetic Intel
| 2. Load Boulevard credentials
| 3. Connect to Boulevard
| 4. Verify that the credentials belong to RUMA
| 5. Create database repository
| 6. Synchronize Boulevard reference data
| 7. Return synchronization statistics
|
|--------------------------------------------------------------------------
*/


/*
|--------------------------------------------------------------------------
| LOAD AESTHETIC INTEL
|--------------------------------------------------------------------------
|
| The existing bootstrap file should create the application's
| database connection ($pdo) and other required configuration.
|
*/

require_once __DIR__
    . '/../app/bootstrap.php';


/*
|--------------------------------------------------------------------------
| LOAD BOULEVARD CONFIGURATION
|--------------------------------------------------------------------------
|
| This file contains:
|
| - api_key
| - secret_key
| - business_id
|
| It should never be publicly accessible.
|
*/

$config = require __DIR__
    . '/../app/private/boulevard-secrets.php';


/*
|--------------------------------------------------------------------------
| LOAD BOULEVARD CLASSES
|--------------------------------------------------------------------------
*/

require_once __DIR__
    . '/../app/Services/Boulevard/BoulevardClient.php';

require_once __DIR__
    . '/../app/Services/Boulevard/BoulevardService.php';

require_once __DIR__
    . '/../app/Repositories/BoulevardRepository.php';

require_once __DIR__
    . '/../app/Services/Boulevard/BoulevardSyncService.php';


/*
|--------------------------------------------------------------------------
| RUMA AESTHETIC INTEL BUSINESS ID
|--------------------------------------------------------------------------
|
| IMPORTANT:
|
| This is the internal Aesthetic Intel businesses.id value.
|
| It is NOT the Boulevard Business UUID.
|
| Example:
|
| Aesthetic Intel:
| businesses.id = 1
|
| Boulevard:
| 64d16bcf-1137-4312-80aa-51c89cea75d4
|
| Verify that "1" is actually RUMA's businesses.id
| before production deployment.
|
*/

$rumaBusinessId = 1;


try {

    /*
    |--------------------------------------------------------------------------
    | VALIDATE DATABASE CONNECTION
    |--------------------------------------------------------------------------
    */

    if (
        !isset($pdo) ||
        !($pdo instanceof PDO)
    ) {
        throw new RuntimeException(
            'Aesthetic Intel database connection is unavailable.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | START SYNC
    |--------------------------------------------------------------------------
    */

    echo "Starting Boulevard synchronization..."
        . PHP_EOL
        . PHP_EOL;


    /*
    |--------------------------------------------------------------------------
    | CREATE BOULEVARD CLIENT
    |--------------------------------------------------------------------------
    |
    | BoulevardClient handles:
    |
    | - Authentication
    | - HTTP requests
    | - GraphQL requests
    | - Retry handling
    | - HTTP errors
    |
    */

    $client =
        new BoulevardClient(
            $config
        );


    /*
    |--------------------------------------------------------------------------
    | CREATE BOULEVARD SERVICE
    |--------------------------------------------------------------------------
    |
    | BoulevardService handles Boulevard business data such as:
    |
    | - Business
    | - Locations
    | - Staff
    | - Services
    | - Appointments
    | - Orders
    |
    */

    $boulevard =
        new BoulevardService(
            $client
        );


    /*
    |--------------------------------------------------------------------------
    | VERIFY RUMA BUSINESS
    |--------------------------------------------------------------------------
    |
    | IMPORTANT:
    |
    | boulevard-secrets.php contains:
    |
    | 64d16bcf-1137-4312-80aa-51c89cea75d4
    |
    | But Boulevard returns:
    |
    | urn:blvd:Business:
    | 64d16bcf-1137-4312-80aa-51c89cea75d4
    |
    | BoulevardService::verifyBusiness()
    | already normalizes these values before comparing them.
    |
    | Therefore DO NOT manually compare:
    |
    | $remoteBusiness['id']
    | against
    | $config['business_id']
    |
    */

    $remoteBusiness =
        $boulevard->verifyBusiness(
            $config['business_id']
        );


    echo "Boulevard connection verified."
        . PHP_EOL;

    echo "Business: "
        . (
            $remoteBusiness['name']
            ?? 'Unknown'
        )
        . PHP_EOL;

    echo "Boulevard Business ID: "
        . (
            $remoteBusiness['id']
            ?? 'Unknown'
        )
        . PHP_EOL;

    echo "Business Timezone: "
        . (
            $remoteBusiness['tz']
            ?? 'Unknown'
        )
        . PHP_EOL
        . PHP_EOL;


    /*
    |--------------------------------------------------------------------------
    | CREATE DATABASE REPOSITORY
    |--------------------------------------------------------------------------
    |
    | BoulevardRepository is responsible for saving/updating
    | Boulevard records inside Aesthetic Intel.
    |
    */

    $repository =
        new BoulevardRepository(
            $pdo
        );


    /*
    |--------------------------------------------------------------------------
    | CREATE SYNC SERVICE
    |--------------------------------------------------------------------------
    |
    | BoulevardSyncService coordinates:
    |
    | Boulevard API
    |       ↓
    | BoulevardRepository
    |       ↓
    | Aesthetic Intel database
    |
    */

    $sync =
        new BoulevardSyncService(
            $boulevard,
            $repository
        );


    /*
    |--------------------------------------------------------------------------
    | SYNCHRONIZE REFERENCE DATA
    |--------------------------------------------------------------------------
    |
    | At this stage we are synchronizing:
    |
    | - Locations
    | - Staff
    | - Services
    |
    | Appointments and orders will be synchronized separately
    | once those API responses are validated.
    |
    */

    $stats =
        $sync->syncReferenceData(
            $rumaBusinessId
        );


    /*
    |--------------------------------------------------------------------------
    | SUCCESS
    |--------------------------------------------------------------------------
    */

    echo PHP_EOL;
    echo "Boulevard synchronization completed successfully."
        . PHP_EOL
        . PHP_EOL;


    echo json_encode(
        [
            'success' => true,

            'business' => [
                'name' =>
                    $remoteBusiness['name']
                    ?? null,

                'boulevard_id' =>
                    $remoteBusiness['id']
                    ?? null,

                'timezone' =>
                    $remoteBusiness['tz']
                    ?? null,
            ],

            'stats' =>
                $stats,
        ],

        JSON_PRETTY_PRINT |
        JSON_UNESCAPED_SLASHES
    );

    echo PHP_EOL;


} catch (Throwable $e) {

    /*
    |--------------------------------------------------------------------------
    | FAILURE LOG
    |--------------------------------------------------------------------------
    |
    | Log the technical error for developers.
    |
    | IMPORTANT:
    | Do not log Boulevard API keys, secret keys,
    | authorization headers or signed tokens.
    |
    */

    error_log(
        '[Boulevard Sync] '
        . $e->getMessage()
    );


    /*
    |--------------------------------------------------------------------------
    | CLI ERROR OUTPUT
    |--------------------------------------------------------------------------
    */

    echo PHP_EOL;

    echo "Boulevard synchronization failed."
        . PHP_EOL;

    echo "Error: "
        . $e->getMessage()
        . PHP_EOL;


    echo json_encode(
        [
            'success' => false,

            'message' =>
                'Boulevard synchronization failed.',

            'error' =>
                $e->getMessage(),
        ],

        JSON_PRETTY_PRINT |
        JSON_UNESCAPED_SLASHES
    );

    echo PHP_EOL;


    exit(1);
}