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
        /*
         * The unified service does not own Boulevard's access token; the
         * shared transport in app/boulevard-api.php does.  Therefore we must
         * not query GraphQL `permissions` and we must not assume the token is
         * exposed as a property on this class.
         *
         * If the connection table contains a persisted OAuth scope value, use
         * it.  Otherwise return an empty list. Optional analytics methods below
         * probe their own endpoint once and quietly treat an explicit
         * missing-scope response as "not available".
         */
        foreach ([
            'granted_scopes',
            'oauth_scopes',
            'token_scope',
            'scopes',
            'scope',
        ] as $key) {
            if (!array_key_exists($key, $this->connectionRow)) {
                continue;
            }

            $raw = $this->connectionRow[$key];

            if (is_array($raw)) {
                $scopes = $raw;
            } else {
                $text = trim((string)$raw);
                if ($text === '') {
                    continue;
                }

                $decoded = json_decode($text, true);
                if (is_array($decoded)) {
                    $scopes = $decoded;
                } else {
                    $scopes = preg_split('/[\\s,]+/', $text) ?: [];
                }
            }

            $scopes = array_values(array_unique(array_filter(array_map(
                static fn($scope): string => trim((string)$scope),
                $scopes
            ))));

            sort($scopes);
            return $scopes;
        }

        return [];
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->getPermissions(), true);
    }

    public function missingScopes(array $requiredScopes): array
    {
        $granted = $this->getPermissions();
        if ($granted === []) {
            return [];
        }
        return array_values(array_diff($requiredScopes, $granted));
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

        /*
         * Keep the base appointment query completely inside appointment:read.
         * clientId is allowed with appointment:read; the nested client object
         * is deliberately fetched separately because it needs client:read.
         *
         * `first` is a literal here. This fixes the previous
         * "Variable first: Expected non-null, found null" error.
         */
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
                clientId
                locationId
                startAt
                endAt
                createdAt
                duration
                cancelled
                state
                orderId

                appointmentServices {
                    id
                    serviceId
                    staffId
                    price
                    startAt
                    endAt
                    duration
                    totalDuration
                    staffRequested
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

        $this->analyticsCapabilities['requested_staff'] = true;

        /* Optional client enrichment. */
        $clientIds = [];
        foreach ($rows as $row) {
            if (is_array($row) && !empty($row['clientId'])) {
                $clientIds[] = (string)$row['clientId'];
            }
        }

        $clientsById = $this->getClientsByIds($clientIds);
        if ($clientsById !== []) {
            foreach ($rows as &$row) {
                if (!is_array($row)) {
                    continue;
                }
                $clientId = (string)($row['clientId'] ?? '');
                if ($clientId !== '' && isset($clientsById[$clientId])) {
                    $row['client'] = $clientsById[$clientId];
                }
            }
            unset($row);
            $this->analyticsCapabilities['appointment_client_metadata'] = true;
        }

        return $rows;
    }

public function getClientsByIds(array $clientIds): array
    {
        $clientIds = array_values(array_unique(array_filter(array_map(
            static fn($id): string => trim((string)$id),
            $clientIds
        ))));

        if ($clientIds === []) {
            return [];
        }

        $results = [];

        foreach (array_chunk($clientIds, 100) as $idChunk) {
            $after = null;

            do {
                $query = <<<'GRAPHQL'
query ClientAnalytics(
    $clientIds: [ID!],
    $after: String
) {
    clients(
        clientIds: $clientIds,
        first: 100,
        after: $after
    ) {
        edges {
            node {
                id
                active
                createdAt
                appointmentCount
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
                    $data = $this->query($query, [
                        'clientIds' => $idChunk,
                        'after' => $after,
                    ]);
                } catch (Throwable $e) {
                    if ($this->isExpectedOptionalAccessFailure($e)) {
                        return [];
                    }
                    throw $e;
                }

                $connection = $data['clients'] ?? [];
                if (!is_array($connection)) {
                    return [];
                }

                foreach ((array)($connection['edges'] ?? []) as $edge) {
                    $node = is_array($edge) ? ($edge['node'] ?? null) : null;
                    if (is_array($node) && !empty($node['id'])) {
                        $results[(string)$node['id']] = $node;
                    }
                }

                $pageInfo = is_array($connection['pageInfo'] ?? null)
                    ? $connection['pageInfo']
                    : [];
                $hasNext = !empty($pageInfo['hasNextPage']);
                $after = $pageInfo['endCursor'] ?? null;
            } while ($hasNext && is_string($after) && $after !== '');
        }

        return $results;
    }

    public function getOrdersAnalytics(
        string $locationId,
        DateTimeInterface $from,
        DateTimeInterface $to
    ): array {
        $this->validateDateRange($from, $to);
        $locationId = self::normalizeResourceUrn($locationId, 'Location');
        $filter = $this->buildDateRangeFilter('closedAt', $from, $to);

        /*
         * Use only fields that are stable on historical 2026-06 orders.
         *
         * Do NOT query the deprecated 2020-01 fields:
         *   appointmentServiceId, service, providers
         *
         * Also do not query initialStaffId/serviceId here. Boulevard marks
         * those non-null in the schema, but historical data can contain nulls,
         * which makes the whole GraphQL field fail with
         * "Cannot return null for non-nullable field".
         *
         * This shape still gives us:
         *   - exact order totals / refunds
         *   - payment totals
         *   - service revenue from OrderServiceLine currentSubtotal
         *   - retail/product revenue from OrderRetailLineGroup
         *
         * OrderAppointmentLineGroup.appointmentId is intentionally NOT queried:
         * the 2026-06 Admin API schema used by this connection does not expose it.
         * Appointment-to-order linkage is reconstructed from Appointment.orderId.
         *
         * Provider revenue attribution remains unavailable rather than being
         * displayed as a misleading $0.00.
         */
        $query = <<<'GRAPHQL'
query OrderAnalyticsSafe(
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
            query: $query,
            variables: [
                'locationId' => $locationId,
                'filter' => $filter,
            ],
            connectionName: 'orders'
        );

        $this->analyticsCapabilities['order_payments'] = true;
        $this->analyticsCapabilities['order_service_lines'] = true;
        $this->analyticsCapabilities['order_retail_lines'] = true;
        $this->analyticsCapabilities['order_provider_attribution'] = false;

        return $rows;
    }

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

        /* Current 2026-06 StaffShift shape. Do not query the deprecated
         * id/date/startTime/endTime fields. */
        $query = <<<'GRAPHQL'
query StaffShifts(
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
            resourceId
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

        try {
            $data = $this->query($query, [
                'locationId' => $locationId,
                'start' => $fromDate,
                'end' => $toDate,
            ]);
        } catch (Throwable $e) {
            if ($this->isExpectedOptionalAccessFailure($e)) {
                return [];
            }
            throw $e;
        }

        $rows = $data['shifts']['shifts'] ?? [];
        if (!is_array($rows)) {
            return [];
        }

        $expanded = $this->expandRecurringStaffShifts(
            array_values(array_filter($rows, 'is_array')),
            DateTimeImmutable::createFromInterface($from),
            DateTimeImmutable::createFromInterface($to)
        );

        $this->analyticsCapabilities['shifts'] = true;
        return $expanded;
    }

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
        pageInfo { hasNextPage endCursor }
    }
}
GRAPHQL;
        try {
            $rows = $this->collectConnection($query, [], 'memberships');
        } catch (Throwable $e) {
            if ($this->isExpectedOptionalAccessFailure($e)) return [];
            throw $e;
        }
        $this->analyticsCapabilities['memberships'] = true;
        return $rows;
    }

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
                category { id name }
            }
        }
        pageInfo { hasNextPage endCursor }
    }
}
GRAPHQL;
        try {
            $rows = $this->collectConnection($query, [], 'packages');
        } catch (Throwable $e) {
            if ($this->isExpectedOptionalAccessFailure($e)) return [];
            throw $e;
        }
        $this->analyticsCapabilities['packages'] = true;
        return $rows;
    }

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
                category { id name }
            }
        }
        pageInfo { hasNextPage endCursor }
    }
}
GRAPHQL;
        try {
            $rows = $this->collectConnection($query, [], 'membershipPlans');
        } catch (Throwable $e) {
            if ($this->isExpectedOptionalAccessFailure($e)) return [];
            throw $e;
        }
        $this->analyticsCapabilities['membership_plans'] = true;
        return $rows;
    }

    public function getProductsAnalytics(): array
    {
        $query = <<<'GRAPHQL'
query ProductsAnalytics($after: String) {
    products(first: 100, after: $after, includePackages: true, includePlans: true) {
        edges {
            node {
                id
                name
                active
                externalId
                categoryId
                category { id name }
                brandName
                unitPrice
            }
        }
        pageInfo { hasNextPage endCursor }
    }
}
GRAPHQL;
        try {
            $rows = $this->collectConnection($query, [], 'products');
        } catch (Throwable $e) {
            if ($this->isExpectedOptionalAccessFailure($e)) return [];
            throw $e;
        }
        $this->analyticsCapabilities['products'] = true;
        return $rows;
    }



    /**
     * Optional analytics scopes are progressive in Boulevard. A missing scope
     * should make one KPI unavailable, never fail the whole live-console run.
     */
    private function isExpectedOptionalAccessFailure(Throwable $e): bool
    {
        $message = strtolower(trim($e->getMessage()));

        foreach ([
            'missing required scope',
            'insufficient scope',
            'permission denied',
            'not permitted',
            'forbidden',
        ] as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }


    private function collectConnection(
        string $query,
        array $variables,
        string $connectionName
    ): array {
        $results = [];
        $after = null;
        $seenCursors = [];

        /* Defensive compatibility: if a document declares $first, always send
         * it even if a caller forgot. This prevents the exact null-variable
         * failure seen in AppointmentAnalytics/Previous appointments. */
        if (
            preg_match('/\\$first\\s*:/', $query)
            && !array_key_exists('first', $variables)
        ) {
            $variables['first'] = self::PAGE_SIZE;
        }

        do {
            $variables['after'] = $after;
            $data = $this->query($query, $variables);

            if (!array_key_exists($connectionName, $data)) {
                throw new RuntimeException(sprintf(
                    'Boulevard response is missing the "%s" connection.',
                    $connectionName
                ));
            }

            $connection = $data[$connectionName];
            if (!is_array($connection)) {
                throw new RuntimeException(sprintf(
                    'Boulevard returned an invalid "%s" connection.',
                    $connectionName
                ));
            }

            $edges = $connection['edges'] ?? [];
            if (!is_array($edges)) {
                throw new RuntimeException(sprintf(
                    'Boulevard returned invalid edges for "%s".',
                    $connectionName
                ));
            }

            foreach ($edges as $edge) {
                if (is_array($edge) && is_array($edge['node'] ?? null)) {
                    $results[] = $edge['node'];
                }
            }

            $pageInfo = is_array($connection['pageInfo'] ?? null)
                ? $connection['pageInfo']
                : [];
            $hasNextPage = !empty($pageInfo['hasNextPage']);
            $nextCursor = $pageInfo['endCursor'] ?? null;

            if ($hasNextPage && (!is_string($nextCursor) || $nextCursor === '')) {
                throw new RuntimeException(sprintf(
                    'Boulevard pagination for "%s" reported another page but returned no cursor.',
                    $connectionName
                ));
            }

            if ($hasNextPage && is_string($nextCursor)) {
                if (isset($seenCursors[$nextCursor])) {
                    throw new RuntimeException(sprintf(
                        'Boulevard repeated a pagination cursor for "%s"; refusing to return partial data.',
                        $connectionName
                    ));
                }
                $seenCursors[$nextCursor] = true;
            }

            $after = $nextCursor;
        } while ($hasNextPage);

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