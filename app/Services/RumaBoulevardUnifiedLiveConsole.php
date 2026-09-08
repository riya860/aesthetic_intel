<?php

declare(strict_types=1);

require_once ROOT_PATH . '/app/boulevard-api.php';
require_once ROOT_PATH . '/app/Services/Boulevard/BoulevardUnifiedService.php';
require_once ROOT_PATH . '/app/Services/Boulevard/BoulevardLiveResultStore.php';
require_once ROOT_PATH . '/app/Repositories/BoulevardRepository.php';
require_once ROOT_PATH . '/app/Services/RumaBoulevardAnalytics.php';

/**
 * RumaBoulevardUnifiedLiveConsole
 *
 * Single-gateway live fetch for RUMA.
 *
 * IMPORTANT:
 * - Uses boulevard_connections from the Aesthetic Intel database.
 * - Uses app/boulevard-api.php for authentication/HTTP/GraphQL.
 * - Does NOT load app/private/boulevard-secrets.php.
 * - Does NOT use BoulevardAuth.php / BoulevardClient.php.
 * - The same credential source is therefore used by Live Console and
 *   Boulevard Report Export / V2 comparison.
 */
final class RumaBoulevardUnifiedLiveConsole
{
    private const MAX_RANGE_DAYS = 90;
    private const DEFAULT_LOCATION_NAME = 'Lehi';
    private const DEFAULT_TIMEZONE = 'America/Denver';

    public static function fetch(
        string $periodStart,
        string $periodEnd
    ): array {
        self::validateDateStrings($periodStart, $periodEnd);

        $aestheticBusinessId =
            BoulevardUnifiedService::resolveRumaAestheticBusinessId();

        $boulevard =
            BoulevardUnifiedService::forAestheticBusiness(
                $aestheticBusinessId
            );

        /*
         * One authoritative auth/connection path.
         * verifyBusiness() itself performs the small business GraphQL request.
         */
        $business = $boulevard->verifyBusiness(
            $boulevard->getConfiguredBusinessId()
        );

        /*
         * Location is core because appointments/orders are location-scoped.
         * Do not continue with a guessed location ID when the API cannot return
         * the location catalogue.
         */
        $locations = $boulevard->getLocations();

        if (!$locations) {
            throw new RuntimeException(
                'Boulevard returned no locations for RUMA.'
            );
        }

        $location = self::chooseLocation($locations);
        $locationId = trim((string)($location['id'] ?? ''));

        if ($locationId === '') {
            throw new RuntimeException(
                'The selected Boulevard location is missing its ID.'
            );
        }

        $timezoneName = trim((string)(
            $location['tz']
            ?? $business['tz']
            ?? self::DEFAULT_TIMEZONE
        ));

        try {
            $timezone = new DateTimeZone($timezoneName);
        } catch (Throwable) {
            $timezoneName = self::DEFAULT_TIMEZONE;
            $timezone = new DateTimeZone($timezoneName);
        }

        [$from, $toExclusive, $rangeDays] =
            self::buildRange(
                $periodStart,
                $periodEnd,
                $timezone
            );

        if ($rangeDays > self::MAX_RANGE_DAYS) {
            throw new RuntimeException(
                'Choose a Boulevard test period of 90 days or less.'
            );
        }

        $warnings = [];

        /*
         * Fetch the operational datasets that are independently healthy first.
         * Staff is resolved afterwards through a live-first/cache-second
         * strategy so a staff-specific permission/schema issue cannot mark the
         * complete Boulevard gateway as partially failed.
         */
        $services = self::safeFetch(
            static fn(): array => $boulevard->getServices(),
            'Boulevard service data could not be loaded.',
            '[Boulevard Unified / Services]',
            $warnings
        );

        $appointments = self::safeFetch(
            static fn(): array => $boulevard->getAppointments(
                $locationId,
                $from,
                $toExclusive
            ),
            'Boulevard appointment data could not be loaded.',
            '[Boulevard Unified / Appointments]',
            $warnings
        );

        $orders = self::safeFetch(
            static fn(): array => $boulevard->getOrders(
                $locationId,
                $from,
                $toExclusive
            ),
            'Boulevard order/revenue data could not be loaded.',
            '[Boulevard Unified / Orders]',
            $warnings
        );

        $staffDataset = self::fetchStaffDataset(
            $boulevard,
            $aestheticBusinessId,
            $locations,
            $appointments
        );

        $staff = $staffDataset['rows'];

        /*
         * Build the direct 2026-06 Admin API intelligence dataset used for
         * API-vs-upload comparison. Every source below is optional: a missing
         * app scope should reduce KPI coverage, not break the operational live
         * console.
         */
        $analyticsWarnings = [];

        $analyticsAppointments = self::safeFetch(
            static fn(): array => $boulevard->getAppointmentsAnalytics(
                $locationId,
                $from,
                $toExclusive
            ),
            'Detailed appointment analytics are unavailable; some new-client/requested-provider KPIs may be unavailable.',
            '[Boulevard 2026-06 / Appointment analytics]',
            $analyticsWarnings
        );
        if (!$analyticsAppointments) {
            $analyticsAppointments = $appointments;
        }

        $analyticsOrders = self::safeFetch(
            static fn(): array => $boulevard->getOrdersAnalytics(
                $locationId,
                $from,
                $toExclusive
            ),
            'Detailed order analytics are unavailable; revenue-mix/provider revenue KPIs may have reduced coverage.',
            '[Boulevard 2026-06 / Order analytics]',
            $analyticsWarnings
        );
        if (!$analyticsOrders) {
            $analyticsOrders = $orders;
        }

        $shifts = self::safeFetch(
            static fn(): array => $boulevard->getShiftsAnalytics(
                $locationId,
                $from,
                $toExclusive
            ),
            'Staff shift data are unavailable; utilization and revenue-per-scheduled-hour cannot be calculated exactly.',
            '[Boulevard 2026-06 / Shifts]',
            $analyticsWarnings
        );

        $memberships = self::safeFetch(
            static fn(): array => $boulevard->getMembershipsAnalytics(),
            'Membership data are unavailable; active MRR/ARR and membership counts may be unavailable.',
            '[Boulevard 2026-06 / Memberships]',
            $analyticsWarnings
        );

        $membershipPlans = self::safeFetch(
            static fn(): array => $boulevard->getMembershipPlansAnalytics(),
            'Membership-plan catalogue data are unavailable; membership sales classification may be reduced.',
            '[Boulevard 2026-06 / Membership plans]',
            $analyticsWarnings
        );

        $packages = self::safeFetch(
            static fn(): array => $boulevard->getPackagesAnalytics(),
            'Package catalogue data are unavailable; package sales classification may be reduced.',
            '[Boulevard 2026-06 / Packages]',
            $analyticsWarnings
        );

        $products = self::safeFetch(
            static fn(): array => $boulevard->getProductsAnalytics(),
            'Product catalogue data are unavailable; retail/package/membership classification may be reduced.',
            '[Boulevard 2026-06 / Products]',
            $analyticsWarnings
        );

        $permissions = self::safeFetch(
            static fn(): array => $boulevard->getPermissions(),
            'Boulevard app permissions could not be listed.',
            '[Boulevard 2026-06 / Permissions]',
            $analyticsWarnings
        );

        /* Previous period = same inclusive number of calendar days directly
         * before the selected period. This supports the existing trend engine
         * without changing PDF/report definitions. */
        $periodDays = $rangeDays + 1;
        $previousFrom = $from->modify('-' . $periodDays . ' days');
        $previousToExclusive = $from;
        $previousPeriodStart = $previousFrom->format('Y-m-d');
        $previousPeriodEnd = $from->modify('-1 day')->format('Y-m-d');

        $previousAppointments = self::safeFetch(
            static fn(): array => $boulevard->getAppointmentsAnalytics(
                $locationId,
                $previousFrom,
                $previousToExclusive
            ),
            'Previous-period appointment data are unavailable; some trend comparisons will be unavailable.',
            '[Boulevard 2026-06 / Previous appointments]',
            $analyticsWarnings
        );

        $previousOrders = self::safeFetch(
            static fn(): array => $boulevard->getOrdersAnalytics(
                $locationId,
                $previousFrom,
                $previousToExclusive
            ),
            'Previous-period order data are unavailable; some revenue trends will be unavailable.',
            '[Boulevard 2026-06 / Previous orders]',
            $analyticsWarnings
        );

        $previousShifts = self::safeFetch(
            static fn(): array => $boulevard->getShiftsAnalytics(
                $locationId,
                $previousFrom,
                $previousToExclusive
            ),
            'Previous-period shift data are unavailable; utilization trends will be unavailable.',
            '[Boulevard 2026-06 / Previous shifts]',
            $analyticsWarnings
        );

        $capabilities = $boulevard->getAnalyticsCapabilities();

        $directIntelligence = (new RumaBoulevardAnalytics([
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'previous_period_start' => $previousPeriodStart,
            'previous_period_end' => $previousPeriodEnd,
            'capabilities' => $capabilities,
            'staff' => $staff,
            'services' => $services,
            'appointments' => $analyticsAppointments,
            'orders' => $analyticsOrders,
            'shifts' => $shifts,
            'memberships' => $memberships,
            'membership_plans' => $membershipPlans,
            'packages' => $packages,
            'products' => $products,
            'previous_appointments' => $previousAppointments,
            'previous_orders' => $previousOrders,
            /* Memberships are a current snapshot, not a historical snapshot.
             * Do not fabricate previous MRR from today's membership state. */
            'previous_memberships' => [],
            'previous_shifts' => $previousShifts,
        ]))->build();

        $directIntelligence['meta'] = [
            'aesthetic_business_id' => $aestheticBusinessId,
            'business_name' => (string)($business['name'] ?? 'RUMA'),
            'location_name' => (string)($location['name'] ?? self::DEFAULT_LOCATION_NAME),
            'timezone' => $timezoneName,
            'mode' => 'direct_admin_graphql_2026_06',
            'endpoint' => boulevard_api_endpoint(),
            'permissions' => $permissions,
            'capabilities' => $capabilities,
            'previous_period_start' => $previousPeriodStart,
            'previous_period_end' => $previousPeriodEnd,
        ];

        $staffNames = [];
        foreach ($staff as $member) {
            if (!is_array($member)) {
                continue;
            }

            $id = trim((string)($member['id'] ?? ''));
            if ($id === '') {
                continue;
            }

            $staffNames[$id] = (string)(
                $member['displayName']
                ?? $member['name']
                ?? 'Unknown Provider'
            );
        }

        $serviceNames = [];
        foreach ($services as $service) {
            if (!is_array($service)) {
                continue;
            }

            $id = trim((string)($service['id'] ?? ''));
            if ($id === '') {
                continue;
            }

            $serviceNames[$id] =
                (string)($service['name'] ?? 'Unknown Service');
        }

        $metrics = [
            'appointments' => count($appointments),
            'cancelled' => 0,
            'completed' => 0,
            'orders' => count($orders),
            'revenue_cents' => 0,
            'refund_cents' => 0,
            'active_staff' => 0,
            'active_services' => 0,
        ];

        foreach ($staff as $member) {
            if (
                is_array($member)
                && (!array_key_exists('active', $member) || $member['active'] === true)
            ) {
                $metrics['active_staff']++;
            }
        }

        foreach ($services as $service) {
            if (
                is_array($service)
                && (!array_key_exists('active', $service) || $service['active'] === true)
            ) {
                $metrics['active_services']++;
            }
        }

        $providerSummary = [];

        foreach ($appointments as $appointment) {
            if (!is_array($appointment)) {
                continue;
            }

            if (!empty($appointment['cancelled'])) {
                $metrics['cancelled']++;
            }

            $state = strtolower(trim((string)($appointment['state'] ?? '')));
            if ($state === 'completed') {
                $metrics['completed']++;
            }

            $appointmentServices =
                is_array($appointment['appointmentServices'] ?? null)
                    ? $appointment['appointmentServices']
                    : [];

            foreach ($appointmentServices as $appointmentService) {
                if (!is_array($appointmentService)) {
                    continue;
                }

                $staffId = trim((string)($appointmentService['staffId'] ?? ''));
                if ($staffId === '') {
                    continue;
                }

                if (!isset($providerSummary[$staffId])) {
                    $providerSummary[$staffId] = [
                        'staff_id' => $staffId,
                        'name' => $staffNames[$staffId] ?? 'Unknown Provider',
                        'appointment_services' => 0,
                        'booked_value_cents' => 0,
                    ];
                }

                $providerSummary[$staffId]['appointment_services']++;
                $providerSummary[$staffId]['booked_value_cents'] +=
                    (int)($appointmentService['price'] ?? 0);
            }
        }

        foreach ($orders as $order) {
            if (!is_array($order)) {
                continue;
            }

            $summary =
                is_array($order['summary'] ?? null)
                    ? $order['summary']
                    : [];

            $metrics['revenue_cents'] +=
                (int)($summary['currentTotal'] ?? 0);

            $metrics['refund_cents'] +=
                (int)($summary['refundAmount'] ?? 0);
        }

        uasort(
            $providerSummary,
            static fn(array $a, array $b): int =>
                ((int)$b['appointment_services'])
                <=>
                ((int)$a['appointment_services'])
        );

        $previousKey = (string)(
            $_SESSION['_boulevard_live_console_result']['full_result_key']
            ?? ''
        );

        $fullResultKey = BoulevardLiveResultStore::save([
            'fetched_at' => date('Y-m-d H:i:s'),
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'locations' => $locations,
            'staff' => $staff,
            'staff_dataset' => $staffDataset,
            'services' => $services,
            'appointments' => $appointments,
            'orders' => $orders,
            'analytics' => [
                'appointments' => $analyticsAppointments,
                'orders' => $analyticsOrders,
                'shifts' => $shifts,
                'memberships' => $memberships,
                'membership_plans' => $membershipPlans,
                'packages' => $packages,
                'products' => $products,
                'permissions' => $permissions,
                'capabilities' => $capabilities,
                'previous_appointments' => $previousAppointments,
                'previous_orders' => $previousOrders,
                'previous_shifts' => $previousShifts,
            ],
            'priority_intelligence_direct' => $directIntelligence,
        ]);

        if ($previousKey !== '' && $previousKey !== $fullResultKey) {
            BoulevardLiveResultStore::delete($previousKey);
        }

        /*
         * priority_intelligence is intentionally only a routing marker now.
         * Actual reporting intelligence comes from the SAME Boulevard gateway
         * through the Report Export pipeline on the V2 page.
         */
        $priorityMarker = [
            'meta' => [
                'aesthetic_business_id' => $aestheticBusinessId,
                'business_name' => (string)($business['name'] ?? 'RUMA'),
                'location_name' => (string)($location['name'] ?? self::DEFAULT_LOCATION_NAME),
                'timezone' => $timezoneName,
                'mode' => 'single_gateway_report_v2',
            ],
        ];

        return [
            'success' => true,
            'fetched_at' => date('Y-m-d H:i:s'),
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'business' => $business,
            'location' => $location,
            'metrics' => $metrics,
            'provider_summary' => array_values($providerSummary),
            'priority_intelligence' => $priorityMarker,
            'priority_intelligence_direct' => $directIntelligence,
            'priority_intelligence_warnings' => array_values(array_unique($analyticsWarnings)),
            'full_result_key' => $fullResultKey,
            'full_fetch' => [
                'complete' => true,
                'page_size' => 100,
                'pagination_mode' => 'cursor',
                'note' =>
                    'All Boulevard cursor pages were collected through the shared database-backed Boulevard gateway before UI pagination.',
            ],
            'staff' => [],
            'services' => [],
            'appointments' => [],
            'orders' => [],
            'staff_dataset' => [
                'source' => (string)($staffDataset['source'] ?? 'unknown'),
                'synced_at' => $staffDataset['synced_at'] ?? null,
                'live_staff_available' => !empty($staffDataset['live_staff_available']),
            ],
            'counts' => [
                'locations' => count($locations),
                'staff' => count($staff),
                'services' => count($services),
                'appointments' => count($appointments),
                'orders' => count($orders),
            ],
            'warnings' => array_values(array_unique($warnings)),
            'connection_source' => 'boulevard_connections',
        ];
    }

    /**
     * Resolve the staff/provider reference dataset without allowing a
     * staff-specific Boulevard permission/schema issue to break the Live API
     * Console.
     *
     * Resolution order:
     * 1. Live Boulevard staff connection.
     * 2. Last successfully synchronized Boulevard staff rows already stored in
     *    Aesthetic Intel's boulevard_staff table.
     * 3. Provider IDs observed in the selected period's appointments.
     *
     * The second source is not fabricated data: boulevard_staff is the cache
     * written by the existing BoulevardRepository from earlier successful live
     * Boulevard staff synchronizations.
     */
    private static function fetchStaffDataset(
        BoulevardUnifiedService $boulevard,
        int $aestheticBusinessId,
        array $locations,
        array $appointments
    ): array {
        try {
            $rows = $boulevard->getStaff();

            if ($rows) {
                self::persistStaffCache(
                    $aestheticBusinessId,
                    $rows
                );

                return [
                    'rows' => $rows,
                    'source' => 'live_api',
                    'synced_at' => date('Y-m-d H:i:s'),
                    'live_staff_available' => true,
                ];
            }
        } catch (Throwable $e) {
            error_log(
                '[Boulevard Unified / Staff live unavailable] '
                . $e->getMessage()
            );
        }

        $cached = self::loadCachedStaff(
            $aestheticBusinessId,
            $locations
        );

        if ($cached['rows']) {
            error_log(
                '[Boulevard Unified / Staff] Using cached boulevard_staff dataset; last sync: '
                . (string)($cached['synced_at'] ?? 'unknown')
            );

            return [
                'rows' => $cached['rows'],
                'source' => 'boulevard_staff_cache',
                'synced_at' => $cached['synced_at'],
                'live_staff_available' => false,
            ];
        }

        $derived = self::deriveStaffFromAppointments(
            $appointments,
            $locations
        );

        if ($derived) {
            error_log(
                '[Boulevard Unified / Staff] No live/cached staff catalogue was available; '
                . 'provider IDs were derived from appointment services for this period.'
            );

            return [
                'rows' => $derived,
                'source' => 'appointment_staff_ids',
                'synced_at' => null,
                'live_staff_available' => false,
            ];
        }

        return [
            'rows' => [],
            'source' => 'unavailable',
            'synced_at' => null,
            'live_staff_available' => false,
        ];
    }


    /**
     * Persist a successful live staff response into the project's existing
     * boulevard_staff reference table. Cache persistence is best-effort and
     * must never break the live fetch.
     */
    private static function persistStaffCache(
        int $aestheticBusinessId,
        array $rows
    ): void {
        try {
            $repository = new BoulevardRepository(db());

            foreach ($rows as $row) {
                if (!is_array($row) || empty($row['id'])) {
                    continue;
                }

                $repository->saveStaff(
                    $aestheticBusinessId,
                    $row
                );
            }
        } catch (Throwable $e) {
            error_log(
                '[Boulevard Unified / Staff cache write] '
                . $e->getMessage()
            );
        }
    }


    /**
     * Load the last known-good Boulevard staff catalogue from Aesthetic Intel.
     */
    private static function loadCachedStaff(
        int $aestheticBusinessId,
        array $locations
    ): array {
        try {
            $stmt = db()->prepare(
                "SELECT
                    boulevard_id,
                    name,
                    display_name,
                    role_name,
                    active,
                    externally_bookable,
                    synced_at
                 FROM boulevard_staff
                 WHERE business_id = ?
                 ORDER BY active DESC,
                          COALESCE(NULLIF(display_name, ''), name) ASC"
            );

            $stmt->execute([
                $aestheticBusinessId,
            ]);

            $dbRows = $stmt->fetchAll();
        } catch (Throwable $e) {
            error_log(
                '[Boulevard Unified / Staff cache read] '
                . $e->getMessage()
            );

            return [
                'rows' => [],
                'synced_at' => null,
            ];
        }

        if (!is_array($dbRows) || !$dbRows) {
            return [
                'rows' => [],
                'synced_at' => null,
            ];
        }

        $singleLocation = null;
        $usableLocations = array_values(
            array_filter(
                $locations,
                'is_array'
            )
        );

        if (count($usableLocations) === 1) {
            $singleLocation = $usableLocations[0];
        }

        $rows = [];
        $latestSync = null;

        foreach ($dbRows as $dbRow) {
            if (!is_array($dbRow)) {
                continue;
            }

            $id = trim((string)($dbRow['boulevard_id'] ?? ''));
            if ($id === '') {
                continue;
            }

            $syncedAt = trim((string)($dbRow['synced_at'] ?? ''));
            if ($syncedAt !== '' && ($latestSync === null || $syncedAt > $latestSync)) {
                $latestSync = $syncedAt;
            }

            $roleName = trim((string)($dbRow['role_name'] ?? ''));

            $staffLocations = [];
            if (is_array($singleLocation)) {
                $staffLocations[] = [
                    'id' => $singleLocation['id'] ?? null,
                    'name' => $singleLocation['name'] ?? null,
                    'tz' => $singleLocation['tz'] ?? null,
                ];
            }

            $rows[] = [
                'id' => $id,
                'name' => (string)($dbRow['name'] ?? ''),
                'displayName' => (string)(
                    $dbRow['display_name']
                    ?? $dbRow['name']
                    ?? ''
                ),
                'active' => (bool)($dbRow['active'] ?? false),
                'externallyBookable' =>
                    $dbRow['externally_bookable'] === null
                        ? null
                        : (bool)$dbRow['externally_bookable'],
                'role' => [
                    'id' => null,
                    'name' => $roleName !== '' ? $roleName : null,
                ],
                'locations' => $staffLocations,
                '_dataset_source' => 'boulevard_staff_cache',
                '_synced_at' => $syncedAt !== '' ? $syncedAt : null,
            ];
        }

        return [
            'rows' => $rows,
            'synced_at' => $latestSync,
        ];
    }


    /**
     * Final no-crash fallback. AppointmentService.staffId does not require
     * patient identity data and lets the provider activity table remain keyed
     * correctly even when the staff catalogue itself is unavailable.
     */
    private static function deriveStaffFromAppointments(
        array $appointments,
        array $locations
    ): array {
        $singleLocation = null;
        $usableLocations = array_values(
            array_filter(
                $locations,
                'is_array'
            )
        );

        if (count($usableLocations) === 1) {
            $singleLocation = $usableLocations[0];
        }

        $rows = [];

        foreach ($appointments as $appointment) {
            if (!is_array($appointment)) {
                continue;
            }

            $appointmentServices =
                is_array($appointment['appointmentServices'] ?? null)
                    ? $appointment['appointmentServices']
                    : [];

            foreach ($appointmentServices as $service) {
                if (!is_array($service)) {
                    continue;
                }

                $staffId = trim((string)($service['staffId'] ?? ''));
                if ($staffId === '' || isset($rows[$staffId])) {
                    continue;
                }

                $shortId = $staffId;
                if (str_contains($shortId, ':')) {
                    $shortId = substr($shortId, strrpos($shortId, ':') + 1);
                }
                $shortId = substr($shortId, 0, 8);

                $staffLocations = [];
                if (is_array($singleLocation)) {
                    $staffLocations[] = [
                        'id' => $singleLocation['id'] ?? null,
                        'name' => $singleLocation['name'] ?? null,
                        'tz' => $singleLocation['tz'] ?? null,
                    ];
                }

                $rows[$staffId] = [
                    'id' => $staffId,
                    'name' => 'Provider ' . $shortId,
                    'displayName' => 'Provider ' . $shortId,
                    'active' => true,
                    'externallyBookable' => null,
                    'role' => [
                        'id' => null,
                        'name' => 'Provider',
                    ],
                    'locations' => $staffLocations,
                    '_dataset_source' => 'appointment_staff_ids',
                    '_synced_at' => null,
                ];
            }
        }

        return array_values($rows);
    }


    private static function safeFetch(
        callable $callback,
        string $userWarning,
        string $logPrefix,
        array &$warnings
    ): array {
        try {
            $value = $callback();
            return is_array($value) ? $value : [];
        } catch (Throwable $e) {
            $warnings[] = $userWarning;
            error_log($logPrefix . ' ' . $e->getMessage());
            return [];
        }
    }

    private static function validateDateStrings(
        string $periodStart,
        string $periodEnd
    ): void {
        if (
            !preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodStart)
            || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodEnd)
        ) {
            throw new RuntimeException(
                'Choose a valid Boulevard date range.'
            );
        }

        if ($periodStart > $periodEnd) {
            throw new RuntimeException(
                'The start date cannot be after the end date.'
            );
        }
    }

    private static function buildRange(
        string $periodStart,
        string $periodEnd,
        DateTimeZone $timezone
    ): array {
        $from = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $periodStart,
            $timezone
        );

        $to = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $periodEnd,
            $timezone
        );

        if (!$from || !$to) {
            throw new RuntimeException(
                'Could not create the Boulevard reporting period.'
            );
        }

        $rangeDays = (int)$from->diff($to)->format('%a');

        return [
            $from,
            $to->modify('+1 day'),
            $rangeDays,
        ];
    }

    private static function chooseLocation(array $locations): array
    {
        $firstNonRemote = null;
        $first = null;

        foreach ($locations as $location) {
            if (!is_array($location)) {
                continue;
            }

            $first ??= $location;

            if (empty($location['isRemote']) && $firstNonRemote === null) {
                $firstNonRemote = $location;
            }

            if (
                strcasecmp(
                    trim((string)($location['name'] ?? '')),
                    self::DEFAULT_LOCATION_NAME
                ) === 0
            ) {
                return $location;
            }
        }

        if (is_array($firstNonRemote)) {
            return $firstNonRemote;
        }

        if (is_array($first)) {
            return $first;
        }

        throw new RuntimeException(
            'Boulevard returned no usable RUMA location.'
        );
    }
}
