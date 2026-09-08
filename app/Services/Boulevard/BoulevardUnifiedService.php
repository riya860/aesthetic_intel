<?php

declare(strict_types=1);

require_once ROOT_PATH . '/app/boulevard-api.php';

/**
 * BoulevardService
 *
 * Responsible for reading RUMA business data from Boulevard.
 *
 * IMPORTANT:
 * - This class does NOT handle authentication directly.
 * - app/boulevard-api.php handles the shared authentication + HTTP/GraphQL transport.
 * - This class does NOT save anything to the database.
 * - Database storage will be handled later by BoulevardRepository.
 * - Patient-identifiable information is deliberately NOT requested.
 */
final class BoulevardUnifiedService
{
    /**
     * Number of records requested from Boulevard per page.
     *
     * Boulevard uses cursor-based pagination.
     */
    private const PAGE_SIZE = 100;

    /**
     * Database-backed Boulevard connection credentials.
     */
    private string $apiKey;
    private string $apiSecret;
    private string $businessId;
    private array $connectionRow;

    /**
     * Records which optional analytics sources were actually accepted by the
     * Boulevard schema/application during this request. This lets the UI
     * distinguish a real zero from "source not available".
     */
    private array $analyticsCapabilities = [
        'appointment_client_metadata' => false,
        'requested_staff' => false,
        'order_payments' => false,
        'order_service_lines' => false,
        'order_provider_attribution' => false,
        'order_retail_lines' => false,
        'shifts' => false,
        'memberships' => false,
        'packages' => false,
        'package_product_mapping' => false,
        'membership_plans' => false,
        'products' => false,
        'permissions' => false,
    ];


    /**
     * Constructor
     */
    public function __construct(
        string $apiKey,
        string $apiSecret,
        string $businessId,
        array $connectionRow = []
    ) {
        $this->apiKey = trim($apiKey);
        $this->apiSecret = trim($apiSecret);
        $this->businessId = boulevard_normalize_business_id($businessId);
        $this->connectionRow = $connectionRow;

        if ($this->apiKey === '' || $this->apiSecret === '') {
            throw new InvalidArgumentException(
                'Boulevard API credentials are incomplete.'
            );
        }
    }

    /**
     * Build the service from Aesthetic Intel's single Boulevard connection
     * record. This is the SAME credential store and transport used by the
     * existing Boulevard Report Export integration.
     */
    public static function forAestheticBusiness(int $aestheticBusinessId): self
    {
        if ($aestheticBusinessId < 1) {
            throw new InvalidArgumentException(
                'A valid Aesthetic Intel business ID is required.'
            );
        }

        [$apiKey, $apiSecret, $businessId, $row] =
            boulevard_connection_credentials($aestheticBusinessId);

        return new self(
            (string)$apiKey,
            (string)$apiSecret,
            (string)$businessId,
            is_array($row) ? $row : []
        );
    }

    /**
     * Resolve RUMA from the same database-backed connection used by the
     * report-export feature. No private Boulevard secrets file is consulted.
     */
    public static function resolveRumaAestheticBusinessId(): int
    {
        $stmt = db()->query(
            "SELECT b.id
             FROM businesses b
             LEFT JOIN boulevard_connections bc
               ON bc.business_id = b.id
             WHERE LOWER(TRIM(b.name)) IN ('ruma aesthetics','ruma','ruma medical')
                OR LOWER(TRIM(COALESCE(bc.connected_business_name,''))) LIKE 'ruma%'
             ORDER BY
                 CASE WHEN bc.status = 'connected' THEN 0 ELSE 1 END,
                 b.id
             LIMIT 1"
        );

        $id = (int)($stmt->fetchColumn() ?: 0);

        if ($id < 1) {
            throw new RuntimeException(
                'RUMA could not be resolved in Aesthetic Intel.'
            );
        }

        return $id;
    }

    public function getConfiguredBusinessId(): string
    {
        return $this->businessId;
    }

    public function getConnectionRow(): array
    {
        return $this->connectionRow;
    }

    /**
     * Single transport for all Boulevard GraphQL calls.
     *
     * Both the Live Console and Report Export now ultimately use
     * app/boulevard-api.php -> boulevard_graphql(), eliminating the duplicate
     * BoulevardAuth/BoulevardClient credential path.
     */
    private function query(string $query, array $variables = []): array
    {
        return boulevard_graphql(
            $this->apiKey,
            $this->apiSecret,
            $this->businessId,
            $query,
            $variables
        );
    }


    /**
     * Optional-source capability report for the intelligence layer.
     */
    public function getAnalyticsCapabilities(): array
    {
        return $this->analyticsCapabilities;
    }


    /**
     * Return the permissions/scopes granted to the current Boulevard app.
     * This query does not require an extra read scope and is used to explain
     * why an optional analytics source may be unavailable.
     */
    public function getPermissions(): array
    {
        $query = <<<'GRAPHQL'
query Permissions {
    permissions
}
GRAPHQL;

        $data = $this->query($query);
        $permissions = $data['permissions'] ?? [];
        if (!is_array($permissions)) {
            $permissions = [];
        }

        $this->analyticsCapabilities['permissions'] = true;

        return array_values(array_unique(array_map('strval', $permissions)));
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

        $data = $this->query($query);

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
        /*
         * Staff is the only core section that can legitimately fail while the
         * rest of the unified gateway remains healthy because Boulevard treats
         * staff as its own permissioned resource.
         *
         * We therefore try progressively smaller, schema-safe datasets. A
         * single optional/nested field must never make the whole staff catalogue
         * disappear.
         */
        $queries = [
            <<<'GRAPHQL'
query StaffRich($after: String) {
    staff(first: 100, after: $after) {
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
                }
            }
        }
        pageInfo {
            hasNextPage
            endCursor
        }
    }
}
GRAPHQL,
            <<<'GRAPHQL'
query StaffCompat($after: String) {
    staff(first: 100, after: $after) {
        edges {
            node {
                id
                name
                active
                role {
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
GRAPHQL,
            <<<'GRAPHQL'
query StaffMinimal($after: String) {
    staff(first: 100, after: $after) {
        edges {
            node {
                id
                name
                active
            }
        }
        pageInfo {
            hasNextPage
            endCursor
        }
    }
}
GRAPHQL,
        ];

        $errors = [];

        foreach ($queries as $index => $query) {
            try {
                $rows = $this->collectConnection(
                    query: $query,
                    variables: [],
                    connectionName: 'staff'
                );

                return $this->normalizeStaffRows($rows);
            } catch (Throwable $e) {
                $message = trim($e->getMessage());
                $errors[] = $message;

                error_log(
                    '[Boulevard Unified / Staff query variant '
                    . ($index + 1)
                    . '] '
                    . $message
                );

                /*
                 * Do not issue more schema variants for transport/auth failures.
                 * The Live Console will then move to its local Boulevard staff
                 * cache instead of creating a request storm.
                 */
                if ($this->isNonSchemaFailure($message)) {
                    break;
                }
            }
        }

        throw new RuntimeException(
            'Boulevard staff catalogue is unavailable through the live staff connection.'
            . ($errors ? ' Last error: ' . end($errors) : '')
        );
    }


    /**
     * Keep a stable staff row shape regardless of which query variant worked.
     * The existing Live Console can therefore render Name, Role, Locations,
     * Bookable and Status without special-case view code.
     */
    private function normalizeStaffRows(array $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $id = trim((string)($row['id'] ?? ''));
            if ($id === '') {
                continue;
            }

            $name = trim((string)($row['name'] ?? ''));
            $displayName = trim((string)($row['displayName'] ?? ''));

            if ($displayName === '') {
                $displayName = $name;
            }

            $row['name'] = $name !== '' ? $name : $displayName;
            $row['displayName'] = $displayName !== '' ? $displayName : $row['name'];

            if (!array_key_exists('active', $row)) {
                $row['active'] = true;
            }

            if (!array_key_exists('externallyBookable', $row)) {
                $row['externallyBookable'] = null;
            }

            if (!isset($row['role']) || !is_array($row['role'])) {
                $row['role'] = [
                    'id' => null,
                    'name' => null,
                ];
            }

            if (!isset($row['locations']) || !is_array($row['locations'])) {
                $row['locations'] = [];
            }

            $out[] = $row;
        }

        return $out;
    }


    /**
     * Return true when retrying a smaller GraphQL selection set cannot help.
     */
    private function isNonSchemaFailure(string $message): bool
    {
        $message = strtolower($message);

        foreach ([
            'unauthorized',
            'forbidden',
            'http 401',
            'http 403',
            'rate limit',
            'timed out',
            'timeout',
            'connection failed',
            'could not resolve host',
            'ssl',
        ] as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
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


    /**
     * Fetch appointment data needed only by the RUMA intelligence dashboard.
     *
     * This is intentionally separate from getAppointments() so the proven
     * live-console query remains untouched. Only opaque/minimal client metadata
     * is requested; names, email, phone, notes and other identifiable fields are
     * not requested.
     */
    public function getAppointmentsAnalytics(
        string $locationId,
        DateTimeInterface $from,
        DateTimeInterface $to
    ): array {
        $this->validateDateRange($from, $to);
        $locationId = self::normalizeResourceUrn($locationId, 'Location');
        $filter = $this->buildDateRangeFilter('startAt', $from, $to);

        $query = <<<'GRAPHQL'
query AppointmentAnalytics(
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
                clientId

                client {
                    id
                    appointmentCount
                    createdAt
                }

                appointmentServices {
                    id
                    serviceId
                    staffId
                    staffRequested
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

        $rows = $this->collectConnection(
            query: $query,
            variables: [
                'locationId' => $locationId,
                'filter' => $filter,
            ],
            connectionName: 'appointments'
        );

        $this->analyticsCapabilities['appointment_client_metadata'] = true;
        $this->analyticsCapabilities['requested_staff'] = true;

        return $rows;
    }


    /**
     * Fetch detailed order information for revenue-mix/provider analytics.
     *
     * Separate from getOrders() to preserve the existing live console even if a
     * Boulevard account lacks one of the optional detailed-order fields/scopes.
     */
    public function getOrdersAnalytics(
        string $locationId,
        DateTimeInterface $from,
        DateTimeInterface $to
    ): array {
        $this->validateDateRange($from, $to);
        $locationId = self::normalizeResourceUrn($locationId, 'Location');
        $filter = $this->buildDateRangeFilter('closedAt', $from, $to);

        /*
         * Prefer the richest read-only shape.  It adds:
         * - retail order-line groups
         * - productId for catalog classification
         * - provider attribution on service lines
         * - payment totals/fees
         *
         * If RUMA's current Boulevard schema/application does not expose one
         * of these optional fields, we fall back to the already-proven query
         * instead of breaking the Live API Console.
         */
        $richQuery = <<<'GRAPHQL'
query OrderAnalyticsFull(
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

                paymentGroups {
                    totalPaid
                    totalFees
                }

                lineGroups {
                    __typename

                    ... on OrderAppointmentLineGroup {
                        lines {
                            __typename
                            id
                            currentSubtotal
                            quantity

                            ... on OrderServiceLine {
                                appointmentServiceId
                                service {
                                    id
                                    name
                                }
                                providers {
                                    id
                                    name
                                    selected
                                    staff {
                                        id
                                        name
                                        displayName
                                    }
                                }
                            }

                            ... on OrderProductLine {
                                name
                                productId
                                seller {
                                    id
                                    name
                                    displayName
                                }
                            }
                        }
                    }

                    ... on OrderRetailLineGroup {
                        lines {
                            __typename
                            id
                            currentSubtotal
                            quantity

                            ... on OrderProductLine {
                                name
                                productId
                                seller {
                                    id
                                    name
                                    displayName
                                }
                            }
                        }
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

        try {
            $rows = $this->collectConnection(
                query: $richQuery,
                variables: [
                    'locationId' => $locationId,
                    'filter' => $filter,
                ],
                connectionName: 'orders'
            );

            $this->analyticsCapabilities['order_payments'] = true;
            $this->analyticsCapabilities['order_service_lines'] = true;
            $this->analyticsCapabilities['order_provider_attribution'] = true;
            $this->analyticsCapabilities['order_retail_lines'] = true;

            return $rows;
        } catch (Throwable $richError) {
            error_log(
                '[Boulevard / Order analytics rich shape unavailable] '
                . $richError->getMessage()
            );
        }

        $compatQuery = <<<'GRAPHQL'
query OrderAnalyticsCompat(
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

                paymentGroups {
                    totalPaid
                    totalFees
                }

                lineGroups {
                    __typename
                    ... on OrderAppointmentLineGroup {
                        lines {
                            __typename
                            id
                            currentSubtotal
                            quantity
                            ... on OrderServiceLine {
                                name
                            }
                            ... on OrderProductLine {
                                name
                                seller {
                                    id
                                    name
                                    displayName
                                }
                            }
                        }
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

        $rows = $this->collectConnection(
            query: $compatQuery,
            variables: [
                'locationId' => $locationId,
                'filter' => $filter,
            ],
            connectionName: 'orders'
        );

        /* The compatibility shape still includes payment totals + service lines. */
        $this->analyticsCapabilities['order_payments'] = true;
        $this->analyticsCapabilities['order_service_lines'] = true;

        return $rows;
    }


    /**
     * Fetch provider schedules for utilization/revenue-per-scheduled-hour.
     *
     * This query is intentionally independent of the existing console fetch.
     * It returns only operational schedule data and no client information.
     */
    public function getShiftsAnalytics(
        string $locationId,
        DateTimeInterface $from,
        DateTimeInterface $to
    ): array {
        $this->validateDateRange($from, $to);
        $locationId = self::normalizeResourceUrn($locationId, 'Location');

        $fromDate = DateTimeImmutable::createFromInterface($from)->format('Y-m-d');
        $toDate = DateTimeImmutable::createFromInterface($to)
            ->modify('-1 day')
            ->format('Y-m-d');

        /*
         * Boulevard has exposed two staff-shift shapes across API revisions.
         * Try the concrete occurrence shape first, then the recurring
         * StaffShift shape documented by the current Admin API.
         */
        $occurrenceQuery = <<<'GRAPHQL'
query StaffShiftsConcrete(
    $locationId: ID!,
    $start: Date!,
    $end: Date!
) {
    shifts(
        locationId: $locationId,
        startIso8601: $start,
        endIso8601: $end
    ) {
        shifts {
            id
            date
            startTime
            endTime
            available
            unavailableReason
            staffId
        }
    }
}
GRAPHQL;

        try {
            $data = $this->query(
                $occurrenceQuery,
                [
                    'locationId' => $locationId,
                    'start' => $fromDate,
                    'end' => $toDate,
                ]
            );

            $rows = $data['shifts']['shifts'] ?? [];
            if (!is_array($rows)) {
                throw new RuntimeException('Boulevard returned an invalid shift payload.');
            }

            $this->analyticsCapabilities['shifts'] = true;

            return array_values(array_filter($rows, 'is_array'));
        } catch (Throwable $occurrenceError) {
            error_log('[Boulevard / Concrete shift shape unavailable] ' . $occurrenceError->getMessage());
        }

        $recurringQuery = <<<'GRAPHQL'
query StaffShiftsRecurring(
    $locationId: ID!,
    $start: Date!,
    $end: Date!
) {
    shifts(
        locationId: $locationId,
        startIso8601: $start,
        endIso8601: $end
    ) {
        shifts {
            available
            bookingInterval
            clockIn
            clockOut
            day
            locationId
            recurrence
            recurrenceStart
            recurrenceEnd
            recurrenceInterval
            staffId
            unavailableReason
        }
    }
}
GRAPHQL;

        $data = $this->query(
            $recurringQuery,
            [
                'locationId' => $locationId,
                'start' => $fromDate,
                'end' => $toDate,
            ]
        );

        $rows = $data['shifts']['shifts'] ?? [];
        if (!is_array($rows)) {
            throw new RuntimeException('Boulevard returned an invalid recurring shift payload.');
        }

        $expanded = $this->expandRecurringStaffShifts(
            array_values(array_filter($rows, 'is_array')),
            DateTimeImmutable::createFromInterface($from),
            DateTimeImmutable::createFromInterface($to)
        );

        $this->analyticsCapabilities['shifts'] = true;

        return $expanded;
    }


    /**
     * Expand Boulevard StaffShift recurrence rows into concrete daily
     * occurrences so utilization can be calculated exactly for the selected
     * period. Boulevard documents day as 0=Sunday ... 6=Saturday.
     */
    private function expandRecurringStaffShifts(
        array $rows,
        DateTimeImmutable $from,
        DateTimeImmutable $toExclusive
    ): array {
        $out = [];
        $periodStart = $from->setTime(0, 0);
        $periodEnd = $toExclusive->setTime(0, 0);

        foreach ($rows as $row) {
            $clockIn = trim((string)($row['clockIn'] ?? ''));
            $clockOut = trim((string)($row['clockOut'] ?? ''));
            $staffId = trim((string)($row['staffId'] ?? ''));
            $available = array_key_exists('available', $row) ? (bool)$row['available'] : true;

            if ($clockIn === '' || $clockOut === '' || $staffId === '') {
                continue;
            }

            $day = isset($row['day']) ? (int)$row['day'] : null;
            $recurrenceStart = !empty($row['recurrenceStart'])
                ? new DateTimeImmutable((string)$row['recurrenceStart'])
                : $periodStart;
            $recurrenceEnd = !empty($row['recurrenceEnd'])
                ? (new DateTimeImmutable((string)$row['recurrenceEnd']))->modify('+1 day')
                : $periodEnd;

            $effectiveStart = $recurrenceStart > $periodStart ? $recurrenceStart : $periodStart;
            $effectiveEnd = $recurrenceEnd < $periodEnd ? $recurrenceEnd : $periodEnd;

            if ($effectiveStart >= $effectiveEnd) {
                continue;
            }

            $intervalWeeks = max(1, (int)($row['recurrenceInterval'] ?? 1));
            $recurrence = strtoupper(trim((string)($row['recurrence'] ?? '')));

            for ($cursor = $effectiveStart; $cursor < $effectiveEnd; $cursor = $cursor->modify('+1 day')) {
                $weekday = (int)$cursor->format('w');
                if ($day !== null && $weekday !== $day) {
                    continue;
                }

                if ($recurrence !== '' && $recurrence !== 'NONE' && $intervalWeeks > 1) {
                    $anchor = $recurrenceStart->setTime(0, 0);
                    $daysSince = (int)$anchor->diff($cursor->setTime(0, 0))->format('%r%a');
                    if ($daysSince < 0 || intdiv($daysSince, 7) % $intervalWeeks !== 0) {
                        continue;
                    }
                }

                $out[] = [
                    'date' => $cursor->format('Y-m-d'),
                    'startTime' => $clockIn,
                    'endTime' => $clockOut,
                    'available' => $available,
                    'unavailableReason' => $row['unavailableReason'] ?? null,
                    'staffId' => $staffId,
                    'locationId' => $row['locationId'] ?? null,
                ];
            }
        }

        return $out;
    }


    /**
     * Fetch all business memberships so the dashboard can calculate current
     * active MRR/ARR without using client names, email, phone or notes.
     */
    public function getMembershipsAnalytics(): array
    {
        $query = <<<'GRAPHQL'
query MembershipsAnalytics($after: String) {
    memberships(first: 100, after: $after) {
        edges {
            node {
                id
                locationId
                name
                productId
                startOn
                endOn
                cancelOn
                nextChargeDate
                interval
                status
                termNumber
                unitPrice
            }
        }
        pageInfo {
            hasNextPage
            endCursor
        }
    }
}
GRAPHQL;

        $rows = $this->collectConnection(
            query: $query,
            variables: [],
            connectionName: 'memberships'
        );

        $this->analyticsCapabilities['memberships'] = true;

        return $rows;
    }


    /**
     * Package catalogue is optional.  Where Boulevard exposes a backing
     * product ID we use it to classify OrderProductLine rows as package sales.
     */
    public function getPackagesAnalytics(): array
    {
        $query = <<<'GRAPHQL'
query PackagesAnalytics($after: String) {
    packages(first: 100, after: $after) {
        edges {
            node {
                id
                name
                active
                unitPrice
                externalId
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

        $rows = $this->collectConnection(
            query: $query,
            variables: [],
            connectionName: 'packages'
        );

        $this->analyticsCapabilities['packages'] = true;

        return $rows;
    }


    /**
     * Fetch membership-plan catalogue metadata. This is distinct from sold
     * memberships and helps classify order product lines even when a plan has
     * no currently active subscriber.
     */
    public function getMembershipPlansAnalytics(): array
    {
        $query = <<<'GRAPHQL'
query MembershipPlansAnalytics($after: String) {
    membershipPlans(first: 100, after: $after) {
        edges {
            node {
                id
                name
                active
                interval
                unitPrice
                externalId
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

        $rows = $this->collectConnection(
            query: $query,
            variables: [],
            connectionName: 'membershipPlans'
        );

        $this->analyticsCapabilities['membership_plans'] = true;

        return $rows;
    }


    /**
     * Fetch the complete product catalogue, including package and membership
     * plan products where Boulevard permits it. Product IDs from order lines
     * can then be classified against the catalogue.
     */
    public function getProductsAnalytics(): array
    {
        $query = <<<'GRAPHQL'
query ProductsAnalytics($after: String) {
    products(
        first: 100,
        after: $after,
        includePackages: true,
        includePlans: true
    ) {
        edges {
            node {
                id
                name
                active
                externalId
                categoryId
                category {
                    id
                    name
                }
                brandName
                unitPrice
            }
        }
        pageInfo {
            hasNextPage
            endCursor
        }
    }
}
GRAPHQL;

        $rows = $this->collectConnection(
            query: $query,
            variables: [],
            connectionName: 'products'
        );

        $this->analyticsCapabilities['products'] = true;

        return $rows;
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
        $seenCursors = [];

        do {
            /*
             * `first: 100` in the GraphQL documents is the page size, not a
             * total-record limit. Keep following the cursor until Boulevard
             * explicitly reports hasNextPage=false.
             */
            $variables['after'] = $after;

            $data =
                $this->query(
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
             * Infinite-loop protection without imposing an artificial maximum
             * number of pages. A repeated cursor means the provider response is
             * inconsistent, so fail rather than returning partial data.
             */
            if ($hasNextPage && is_string($nextCursor)) {
                if (isset($seenCursors[$nextCursor])) {
                    throw new RuntimeException(
                        sprintf(
                            'Boulevard repeated a pagination cursor for "%s"; refusing to return partial data.',
                            $connectionName
                        )
                    );
                }
                $seenCursors[$nextCursor] = true;
            }

            $after = $nextCursor;

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