<?php

declare(strict_types=1);

require_once __DIR__ . '/BoulevardClient.php';

/**
 * BoulevardService
 *
 * Responsible for reading RUMA business data from Boulevard.
 *
 * IMPORTANT:
 * - This class does NOT handle authentication directly.
 * - BoulevardClient handles authentication + HTTP/GraphQL requests.
 * - This class does NOT save anything to the database.
 * - Database storage will be handled later by BoulevardRepository.
 * - Patient-identifiable information is deliberately NOT requested.
 */
final class BoulevardService
{
    /**
     * Number of records requested from Boulevard per page.
     *
     * Boulevard uses cursor-based pagination.
     */
    private const PAGE_SIZE = 100;

    /**
     * Safety limit to prevent an accidental infinite pagination loop.
     *
     * 1000 pages × 100 records = 100,000 records maximum
     * in one service call.
     */
    private const MAX_PAGES = 1000;

    /**
     * Boulevard GraphQL client.
     */
    private BoulevardClient $client;


    /**
     * Constructor
     */
    public function __construct(
        BoulevardClient $client
    ) {
        $this->client = $client;
    }


    /*
    |--------------------------------------------------------------------------
    | BUSINESS
    |--------------------------------------------------------------------------
    */


    /**
     * Get the Boulevard business currently authenticated.
     *
     * For the RUMA credentials this should return:
     *
     * name:
     * RUMA Medical
     *
     * id:
     * urn:blvd:Business:64d16bcf-1137-4312-80aa-51c89cea75d4
     */
    public function getBusiness(): array
    {
        $query = <<<'GRAPHQL'
query Business {
    business {
        id
        name
        tz
        website
    }
}
GRAPHQL;

        $data = $this->client->query($query);

        $business = $data['business'] ?? null;

        if (!is_array($business)) {
            throw new RuntimeException(
                'Boulevard did not return business information.'
            );
        }

        if (empty($business['id'])) {
            throw new RuntimeException(
                'Boulevard business response is missing the business ID.'
            );
        }

        return $business;
    }


    /**
     * Verify that the authenticated Boulevard account belongs
     * to the expected business.
     *
     * IMPORTANT:
     *
     * Our secrets file contains:
     *
     * 64d16bcf-1137-4312-80aa-51c89cea75d4
     *
     * Boulevard returns:
     *
     * urn:blvd:Business:64d16bcf-1137-4312-80aa-51c89cea75d4
     *
     * Therefore we normalize the Boulevard URN before comparing.
     *
     * @throws RuntimeException
     */
    public function verifyBusiness(
        string $configuredBusinessId
    ): array {
        $configuredBusinessId =
            trim($configuredBusinessId);

        if ($configuredBusinessId === '') {
            throw new InvalidArgumentException(
                'Configured Boulevard business ID cannot be empty.'
            );
        }

        $business = $this->getBusiness();

        $remoteBusinessId =
            self::extractIdFromUrn(
                $business['id'] ?? '',
                'Business'
            );

        /*
         * Also normalize the configured value in case someone
         * accidentally stores the full Boulevard URN later.
         */
        $expectedBusinessId =
            self::extractIdFromUrn(
                $configuredBusinessId,
                'Business'
            );

        if (
            !hash_equals(
                $expectedBusinessId,
                $remoteBusinessId
            )
        ) {
            throw new RuntimeException(
                'Boulevard Business ID mismatch. '
                . 'Synchronization has been stopped for safety.'
            );
        }

        return $business;
    }


    /*
    |--------------------------------------------------------------------------
    | LOCATIONS
    |--------------------------------------------------------------------------
    */


    /**
     * Fetch every Boulevard location for the business.
     *
     * Returns:
     *
     * [
     *     [
     *         'id' => 'urn:blvd:Location:...',
     *         'name' => '...',
     *         'tz' => 'America/Los_Angeles',
     *         'isRemote' => false,
     *         'website' => '...'
     *     ]
     * ]
     */
    public function getLocations(): array
    {
        $query = <<<'GRAPHQL'
query Locations(
    $after: String
) {
    locations(
        first: 100,
        after: $after
    ) {
        edges {
            node {
                id
                name
                tz
                isRemote
                website
            }
        }

        pageInfo {
            hasNextPage
            endCursor
        }
    }
}
GRAPHQL;

        return $this->collectConnection(
            query: $query,
            variables: [],
            connectionName: 'locations'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | STAFF / PROVIDERS
    |--------------------------------------------------------------------------
    */


    /**
     * Fetch all Boulevard staff members.
     *
     * We deliberately do NOT fetch:
     *
     * - email
     * - mobile phone
     * - private notes
     *
     * because Aesthetic Intel currently only needs
     * provider identity and operational information.
     *
     * IMPORTANT:
     * Always use Boulevard staff ID for mapping.
     * Do not permanently map providers by name.
     */
    public function getStaff(): array
    {
        $query = <<<'GRAPHQL'
query Staff(
    $after: String
) {
    staff(
        first: 100,
        after: $after
    ) {
        edges {
            node {
                id
                name
                displayName
                active
                externallyBookable

                role {
                    id
                    name
                }

                locations {
                    id
                    name
                    tz
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

        return $this->collectConnection(
            query: $query,
            variables: [],
            connectionName: 'staff'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | SERVICES
    |--------------------------------------------------------------------------
    */


    /**
     * Fetch all services configured for the business.
     *
     * Service prices here should not automatically be treated
     * as actual realized revenue.
     *
     * Actual revenue should later come from Boulevard orders.
     */
    public function getServices(): array
    {
        $query = <<<'GRAPHQL'
query Services(
    $after: String
) {
    services(
        first: 100,
        after: $after
    ) {
        edges {
            node {
                id
                name
                active
                defaultDuration
                defaultPrice

                category {
                    id
                    name
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

        return $this->collectConnection(
            query: $query,
            variables: [],
            connectionName: 'services'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | APPOINTMENTS
    |--------------------------------------------------------------------------
    */


    /**
     * Fetch appointments for one Boulevard location
     * within a specific date range.
     *
     * $from is inclusive.
     * $to is exclusive.
     *
     * Example:
     *
     * $from = 2026-08-01 00:00:00
     * $to   = 2026-09-01 00:00:00
     *
     * retrieves all August appointments.
     *
     * IMPORTANT:
     * The supplied dates can be in RUMA's timezone.
     * They are converted to UTC before building the API query.
     *
     * No client/patient information is requested.
     */
    public function getAppointments(
        string $locationId,
        DateTimeInterface $from,
        DateTimeInterface $to
    ): array {
        $this->validateDateRange(
            $from,
            $to
        );

        /*
         * Boulevard expects an ID.
         *
         * If a UUID is supplied instead of a full URN,
         * automatically convert it into:
         *
         * urn:blvd:Location:UUID
         */
        $locationId =
            self::normalizeResourceUrn(
                $locationId,
                'Location'
            );

        $filter =
            $this->buildDateRangeFilter(
                field: 'startAt',
                from: $from,
                to: $to
            );

        $query = <<<'GRAPHQL'
query Appointments(
    $locationId: ID!,
    $after: String,
    $filter: QueryString
) {
    appointments(
        locationId: $locationId,
        first: 100,
        after: $after,
        query: $filter
    ) {
        edges {
            node {
                id
                locationId
                startAt
                endAt
                duration
                cancelled
                state
                orderId

                appointmentServices {
                    id
                    serviceId
                    staffId
                    price
                    duration
                    startAt
                    endAt
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

        return $this->collectConnection(
            query: $query,

            variables: [
                'locationId' =>
                    $locationId,

                'filter' =>
                    $filter,
            ],

            connectionName:
                'appointments'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | ORDERS / REVENUE
    |--------------------------------------------------------------------------
    */


    /**
     * Fetch closed Boulevard orders for one location
     * within a specific time range.
     *
     * This is the primary source we will eventually use
     * for revenue KPIs.
     *
     * IMPORTANT:
     * Boulevard Money fields are returned in the smallest
     * currency unit.
     *
     * For USD:
     *
     * 100   = $1.00
     * 1500  = $15.00
     * 12599 = $125.99
     *
     * Keep these values as integer cents in the database.
     */
    public function getOrders(
        string $locationId,
        DateTimeInterface $from,
        DateTimeInterface $to
    ): array {
        $this->validateDateRange(
            $from,
            $to
        );

        $locationId =
            self::normalizeResourceUrn(
                $locationId,
                'Location'
            );

        $filter =
            $this->buildDateRangeFilter(
                field: 'closedAt',
                from: $from,
                to: $to
            );

        $query = <<<'GRAPHQL'
query Orders(
    $locationId: ID!,
    $after: String,
    $filter: QueryString
) {
    orders(
        locationId: $locationId,
        first: 100,
        after: $after,
        query: $filter
    ) {
        edges {
            node {
                id
                locationId
                number
                createdAt
                closedAt
                updatedAt

                summary {
                    currentSubtotal
                    currentDiscountAmount
                    currentTaxAmount
                    currentGratuityAmount
                    currentFeeAmount
                    currentTotal

                    initialSubtotal
                    initialDiscountAmount
                    initialTaxAmount
                    initialGratuityAmount
                    initialFeeAmount
                    initialTotal

                    refundAmount
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

        return $this->collectConnection(
            query: $query,

            variables: [
                'locationId' =>
                    $locationId,

                'filter' =>
                    $filter,
            ],

            connectionName:
                'orders'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | PAGINATION
    |--------------------------------------------------------------------------
    */


    /**
     * Generic Boulevard connection pagination.
     *
     * Boulevard GraphQL connections use:
     *
     * edges
     * pageInfo.hasNextPage
     * pageInfo.endCursor
     *
     * This method prevents us from duplicating pagination logic
     * in locations, staff, services, appointments and orders.
     */
    private function collectConnection(
        string $query,
        array $variables,
        string $connectionName
    ): array {
        $results = [];

        $after = null;

        $page = 0;

        do {
            $page++;

            if ($page > self::MAX_PAGES) {
                throw new RuntimeException(
                    'Boulevard pagination safety limit exceeded for '
                    . $connectionName
                    . '.'
                );
            }

            /*
             * Every paginated query defined in this class
             * contains an optional $after variable.
             */
            $variables['after'] = $after;

            $data =
                $this->client->query(
                    $query,
                    $variables
                );

            if (
                !array_key_exists(
                    $connectionName,
                    $data
                )
            ) {
                throw new RuntimeException(
                    sprintf(
                        'Boulevard response is missing the "%s" connection.',
                        $connectionName
                    )
                );
            }

            $connection =
                $data[$connectionName];

            if (!is_array($connection)) {
                throw new RuntimeException(
                    sprintf(
                        'Boulevard returned an invalid "%s" connection.',
                        $connectionName
                    )
                );
            }

            $edges =
                $connection['edges'] ?? [];

            if (!is_array($edges)) {
                throw new RuntimeException(
                    sprintf(
                        'Boulevard returned invalid edges for "%s".',
                        $connectionName
                    )
                );
            }

            foreach ($edges as $edge) {
                if (
                    is_array($edge) &&
                    isset($edge['node']) &&
                    is_array($edge['node'])
                ) {
                    $results[] =
                        $edge['node'];
                }
            }

            $pageInfo =
                $connection['pageInfo']
                ?? [];

            $hasNextPage =
                !empty(
                    $pageInfo['hasNextPage']
                );

            $nextCursor =
                $pageInfo['endCursor']
                ?? null;

            /*
             * Protect against Boulevard unexpectedly saying
             * there is another page without returning a cursor.
             */
            if (
                $hasNextPage &&
                (
                    !is_string($nextCursor) ||
                    $nextCursor === ''
                )
            ) {
                throw new RuntimeException(
                    sprintf(
                        'Boulevard pagination for "%s" reported another page but returned no cursor.',
                        $connectionName
                    )
                );
            }

            /*
             * Protect against an accidental repeated cursor,
             * which could otherwise cause an infinite loop.
             */
            if (
                $hasNextPage &&
                $nextCursor === $after
            ) {
                throw new RuntimeException(
                    sprintf(
                        'Boulevard returned the same pagination cursor twice for "%s".',
                        $connectionName
                    )
                );
            }

            $after =
                $nextCursor;

        } while (
            $hasNextPage
        );

        return $results;
    }


    /*
    |--------------------------------------------------------------------------
    | DATE / TIME HELPERS
    |--------------------------------------------------------------------------
    */


    /**
     * Validate date range.
     */
    private function validateDateRange(
        DateTimeInterface $from,
        DateTimeInterface $to
    ): void {
        if (
            $from->getTimestamp() >=
            $to->getTimestamp()
        ) {
            throw new InvalidArgumentException(
                'Boulevard date range is invalid. '
                . '"from" must be earlier than "to".'
            );
        }
    }


    /**
     * Convert any DateTimeInterface object to UTC.
     *
     * Example:
     *
     * RUMA:
     * 2026-09-01 00:00 America/Los_Angeles
     *
     * becomes the corresponding UTC instant
     * before being sent to Boulevard.
     */
    private function toUtc(
        DateTimeInterface $date
    ): DateTimeImmutable {
        return DateTimeImmutable::createFromInterface(
            $date
        )->setTimezone(
            new DateTimeZone('UTC')
        );
    }


    /**
     * Build a Boulevard QueryString date-range condition.
     *
     * Uses:
     *
     * field >= FROM
     * AND
     * field < TO
     *
     * The upper boundary is intentionally exclusive.
     */
    private function buildDateRangeFilter(
        string $field,
        DateTimeInterface $from,
        DateTimeInterface $to
    ): string {
        /*
         * Protect against accidentally inserting an uncontrolled
         * GraphQL field name into the QueryString.
         */
        $allowedFields = [
            'startAt',
            'createdAt',
            'updatedAt',
            'closedAt',
        ];

        if (
            !in_array(
                $field,
                $allowedFields,
                true
            )
        ) {
            throw new InvalidArgumentException(
                'Unsupported Boulevard date filter field.'
            );
        }

        $fromUtc =
            $this->toUtc($from);

        $toUtc =
            $this->toUtc($to);

        return sprintf(
            "%s >= '%s' AND %s < '%s'",
            $field,
            $fromUtc->format(
                'Y-m-d\TH:i:s\Z'
            ),
            $field,
            $toUtc->format(
                'Y-m-d\TH:i:s\Z'
            )
        );
    }


    /*
    |--------------------------------------------------------------------------
    | BOULEVARD ID / URN HELPERS
    |--------------------------------------------------------------------------
    */


    /**
     * Normalize a Boulevard resource ID.
     *
     * Accepts:
     *
     * 1234-uuid
     *
     * OR
     *
     * urn:blvd:Location:1234-uuid
     *
     * Returns:
     *
     * urn:blvd:Location:1234-uuid
     */
    private static function normalizeResourceUrn(
        string $id,
        string $resourceType
    ): string {
        $id =
            trim($id);

        if ($id === '') {
            throw new InvalidArgumentException(
                sprintf(
                    'Boulevard %s ID cannot be empty.',
                    $resourceType
                )
            );
        }

        $prefix =
            'urn:blvd:'
            . $resourceType
            . ':';

        if (
            str_starts_with(
                $id,
                $prefix
            )
        ) {
            return $id;
        }

        /*
         * If another Boulevard URN type was accidentally
         * supplied, reject it rather than creating an
         * invalid double URN.
         */
        if (
            str_starts_with(
                $id,
                'urn:blvd:'
            )
        ) {
            throw new InvalidArgumentException(
                sprintf(
                    'Expected a Boulevard %s ID but received a different Boulevard resource type.',
                    $resourceType
                )
            );
        }

        return $prefix . $id;
    }


    /**
     * Remove a Boulevard resource URN prefix.
     *
     * Example:
     *
     * urn:blvd:Business:64d16bcf-...
     *
     * becomes:
     *
     * 64d16bcf-...
     */
    private static function extractIdFromUrn(
        string $id,
        string $resourceType
    ): string {
        $id =
            trim($id);

        if ($id === '') {
            return '';
        }

        $prefix =
            'urn:blvd:'
            . $resourceType
            . ':';

        if (
            str_starts_with(
                $id,
                $prefix
            )
        ) {
            return substr(
                $id,
                strlen($prefix)
            );
        }

        return $id;
    }
}