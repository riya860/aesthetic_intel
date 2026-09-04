<?php

declare(strict_types=1);

$config =
    require __DIR__
    . '/app/private/boulevard-secrets.php';

require_once __DIR__
    . '/app/Services/Boulevard/BoulevardClient.php';

try {

    echo "Boulevard Appointments Test\n";
    echo "===========================\n\n";

    $client =
        new BoulevardClient($config);

    $locationId =
        'urn:blvd:Location:dfc655a0-3a0c-4361-8d3a-68b8284e793f';

    $query = <<<'GRAPHQL'
query TestAppointments(
    $locationId: ID!
) {
    appointments(
        locationId: $locationId,
        first: 20,
        query: "startAt >= '2026-08-24T00:00:00Z' AND startAt < '2026-09-01T00:00:00Z'"
    ) {
        edges {
            node {
                id
                startAt
                endAt
                cancelled
                state

                appointmentServices {
                    id
                    price
                    duration

                    service {
                        id
                        name
                    }

                    staff {
                        id
                        name
                        displayName
                    }
                }
            }
        }

        pageInfo {
            hasNextPage
            endCursor
        }
    }
}
GRAPHQL;

    $data =
        $client->query(
            $query,
            [
                'locationId' =>
                    $locationId,
            ]
        );

    echo "SUCCESS\n\n";

    echo json_encode(
        $data,
        JSON_PRETTY_PRINT |
        JSON_UNESCAPED_SLASHES
    );

    echo PHP_EOL;

} catch (Throwable $e) {

    echo "FAILED\n";
    echo "------\n";
    echo $e->getMessage();
    echo PHP_EOL;
}