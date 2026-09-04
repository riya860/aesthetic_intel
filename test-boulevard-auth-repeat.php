<?php

declare(strict_types=1);

$config =
    require __DIR__
    . '/app/private/boulevard-secrets.php';

require_once __DIR__
    . '/app/Services/Boulevard/BoulevardClient.php';

echo "Boulevard Repeated Authentication Test\n";
echo "======================================\n\n";

$query = <<<'GRAPHQL'
query TestBusiness {
    business {
        id
        name
    }
}
GRAPHQL;

for ($i = 1; $i <= 10; $i++) {

    echo "Request {$i}\n";

    echo "Timestamp: "
        . time()
        . "\n";

    echo "UTC: "
        . gmdate('Y-m-d H:i:s')
        . "\n";

    try {

        /*
         * Create a completely new client
         * for each request.
         */
        $client =
            new BoulevardClient($config);

        $data =
            $client->query($query);

        echo "RESULT: SUCCESS\n";

        echo "Business: "
            . (
                $data['business']['name']
                ?? 'Unknown'
            )
            . "\n";

    } catch (Throwable $e) {

        echo "RESULT: FAILED\n";

        echo $e->getMessage()
            . "\n";
    }

    echo "--------------------------------------\n";

    /*
     * Ensure every request definitely
     * receives a different timestamp.
     */
    sleep(2);
}