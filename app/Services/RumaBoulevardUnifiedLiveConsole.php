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

        /*
         * The analytics builder intentionally remains generic.  For the RUMA
         * 2026-06 integration we can safely improve provider attribution using
         * fields already fetched above:
         *
         * Appointment.orderId
         * AppointmentService.staffId / serviceId / price / staffRequested
         * Order lineGroups -> OrderServiceLine.currentSubtotal / name
         *
         * This is especially important because OrderAppointmentLineGroup does
         * not expose appointmentId in the Admin schema used by this account.
         * We therefore join Order -> Appointment via Appointment.orderId.
         */
        $directIntelligence = self::enrichDirectIntelligence(
            $directIntelligence,
            $analyticsAppointments,
            $analyticsOrders,
            $staff,
            $services,
            $capabilities,
            $periodStart,
            $periodEnd,
            $timezoneName
        );

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



    /**
     * Improve the generic analytics result with RUMA-specific joins that are
     * possible from the 2026-06 objects already returned by Boulevard.
     *
     * No patient-identifying fields are requested or emitted.
     */
    private static function enrichDirectIntelligence(
        array $intelligence,
        array $appointments,
        array $orders,
        array $staff,
        array $services,
        array $capabilities,
        string $periodStart,
        string $periodEnd,
        string $timezoneName
    ): array {
        $staffNames = [];
        foreach ($staff as $member) {
            if (!is_array($member)) {
                continue;
            }
            $id = trim((string)($member['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $staffNames[$id] = trim((string)(
                $member['displayName']
                ?? $member['name']
                ?? 'Unknown Provider'
            ));
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
            $serviceNames[$id] = trim((string)($service['name'] ?? ''));
        }

        $providerBase = [];
        foreach ((array)($intelligence['provider_details'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $staffId = trim((string)(
                $row['staff_id']
                ?? $row['staffId']
                ?? ''
            ));
            $name = trim((string)(
                $row['provider']
                ?? $row['name']
                ?? ''
            ));

            if ($staffId !== '') {
                $providerBase['id:' . $staffId] = $row;
            }
            if ($name !== '') {
                $providerBase['name:' . self::providerKey($name)] = $row;
            }
        }

        $providers = [];
        $appointmentsByOrderId = [];
        $daily = [];

        try {
            $timezone = new DateTimeZone($timezoneName);
        } catch (Throwable) {
            $timezone = new DateTimeZone(self::DEFAULT_TIMEZONE);
        }

        $periodStartDate = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $periodStart,
            $timezone
        );
        $periodEndDate = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $periodEnd,
            $timezone
        );

        if ($periodStartDate && $periodEndDate) {
            for (
                $cursor = $periodStartDate;
                $cursor <= $periodEndDate;
                $cursor = $cursor->modify('+1 day')
            ) {
                $day = $cursor->format('Y-m-d');
                $daily[$day] = [
                    'date' => $day,
                    'revenue' => 0.0,
                    'appointments' => 0,
                ];
            }
        }

        $clientMetadataAvailable =
            !empty($capabilities['appointment_client_metadata']);

        foreach ($appointments as $appointment) {
            if (!is_array($appointment)) {
                continue;
            }

            $appointmentId = trim((string)($appointment['id'] ?? ''));
            $orderId = trim((string)($appointment['orderId'] ?? ''));
            if ($orderId !== '') {
                $appointmentsByOrderId[$orderId][] = $appointment;
            }

            $day = self::dateInTimezone(
                (string)($appointment['startAt'] ?? ''),
                $timezone
            );
            if ($day !== null && isset($daily[$day])) {
                $daily[$day]['appointments']++;
            }

            $clientId = trim((string)($appointment['clientId'] ?? ''));
            $client = is_array($appointment['client'] ?? null)
                ? $appointment['client']
                : null;

            $isNewClient = false;
            if ($clientMetadataAvailable && $client && !empty($client['createdAt'])) {
                $clientCreatedDay = self::dateInTimezone(
                    (string)$client['createdAt'],
                    $timezone
                );
                if (
                    $clientCreatedDay !== null
                    && $clientCreatedDay >= $periodStart
                    && $clientCreatedDay <= $periodEnd
                ) {
                    $isNewClient = true;
                }
            }

            $seenStaffForAppointment = [];
            foreach ((array)($appointment['appointmentServices'] ?? []) as $service) {
                if (!is_array($service)) {
                    continue;
                }

                $staffId = trim((string)($service['staffId'] ?? ''));
                if ($staffId === '') {
                    continue;
                }

                if (!isset($providers[$staffId])) {
                    $existing = $providerBase['id:' . $staffId]
                        ?? $providerBase[
                            'name:' . self::providerKey($staffNames[$staffId] ?? '')
                        ]
                        ?? [];

                    $providers[$staffId] = [
                        'staff_id' => $staffId,
                        'provider' => $staffNames[$staffId]
                            ?? (string)($existing['provider'] ?? 'Unknown Provider'),
                        'name' => $staffNames[$staffId]
                            ?? (string)($existing['provider'] ?? 'Unknown Provider'),
                        'appointments' => 0,
                        'requested' => 0,
                        'requested_appointments' => 0,
                        'requested_available' => !empty($capabilities['requested_staff']),
                        'new_clients' => $clientMetadataAvailable ? 0 : null,
                        'new_clients_available' => $clientMetadataAvailable,
                        'service_revenue' => 0.0,
                        'service_revenue_available' => false,
                        'retail_sales' => null,
                        'retail_sales_available' => false,
                        'utilization' => $existing['utilization'] ?? null,
                        'utilization_available' => !empty($existing['utilization_available']),
                        'scheduled_hours' => $existing['scheduled_hours'] ?? null,
                        'revenue_per_hour' => $existing['revenue_per_hour'] ?? null,
                        'revenue_per_hour_available' => !empty($existing['revenue_per_hour_available']),
                        '_appointment_ids' => [],
                        '_new_client_ids' => [],
                    ];
                }

                if (
                    $appointmentId !== ''
                    && !isset($providers[$staffId]['_appointment_ids'][$appointmentId])
                ) {
                    $providers[$staffId]['_appointment_ids'][$appointmentId] = true;
                    $providers[$staffId]['appointments']++;
                } elseif ($appointmentId === '' && !isset($seenStaffForAppointment[$staffId])) {
                    $providers[$staffId]['appointments']++;
                }

                $seenStaffForAppointment[$staffId] = true;

                if (
                    !empty($capabilities['requested_staff'])
                    && !empty($service['staffRequested'])
                ) {
                    $providers[$staffId]['requested']++;
                    $providers[$staffId]['requested_appointments']++;
                }

                if (
                    $clientMetadataAvailable
                    && $isNewClient
                    && $clientId !== ''
                    && !isset($providers[$staffId]['_new_client_ids'][$clientId])
                ) {
                    $providers[$staffId]['_new_client_ids'][$clientId] = true;
                    $providers[$staffId]['new_clients']++;
                }
            }
        }

        $serviceRevenueCents = 0;
        $productRevenueCents = 0;
        $unassignedServiceRevenueCents = 0;
        $providerAttributionObserved = false;

        foreach ($orders as $order) {
            if (!is_array($order)) {
                continue;
            }

            $orderId = trim((string)($order['id'] ?? ''));
            $orderAppointments = $orderId !== ''
                ? (array)($appointmentsByOrderId[$orderId] ?? [])
                : [];

            $day = self::dateInTimezone(
                (string)($order['closedAt'] ?? $order['createdAt'] ?? ''),
                $timezone
            );
            if ($day !== null && isset($daily[$day])) {
                $summary = is_array($order['summary'] ?? null)
                    ? $order['summary']
                    : [];
                $daily[$day]['revenue'] +=
                    ((float)($summary['currentTotal'] ?? 0)) / 100.0;
            }

            foreach ((array)($order['lineGroups'] ?? []) as $group) {
                if (!is_array($group)) {
                    continue;
                }

                $groupType = (string)($group['__typename'] ?? '');

                foreach ((array)($group['lines'] ?? []) as $line) {
                    if (!is_array($line)) {
                        continue;
                    }

                    $lineType = (string)($line['__typename'] ?? '');
                    $lineSubtotal = (int)round((float)($line['currentSubtotal'] ?? 0));

                    if ($lineType === 'OrderServiceLine') {
                        $serviceRevenueCents += $lineSubtotal;

                        $candidateServices = [];
                        $lineName = self::providerKey((string)($line['name'] ?? ''));

                        foreach ($orderAppointments as $appointment) {
                            if (!is_array($appointment)) {
                                continue;
                            }

                            foreach ((array)($appointment['appointmentServices'] ?? []) as $appointmentService) {
                                if (!is_array($appointmentService)) {
                                    continue;
                                }

                                $staffId = trim((string)($appointmentService['staffId'] ?? ''));
                                if ($staffId === '') {
                                    continue;
                                }

                                $serviceId = trim((string)($appointmentService['serviceId'] ?? ''));
                                $appointmentServiceName = self::providerKey(
                                    $serviceNames[$serviceId] ?? ''
                                );

                                $candidateServices[] = [
                                    'staff_id' => $staffId,
                                    'name_match' => (
                                        $lineName !== ''
                                        && $appointmentServiceName !== ''
                                        && $lineName === $appointmentServiceName
                                    ),
                                    'weight' => max(
                                        0,
                                        (int)round((float)($appointmentService['price'] ?? 0))
                                    ),
                                ];
                            }
                        }

                        $matching = array_values(array_filter(
                            $candidateServices,
                            static fn(array $candidate): bool =>
                                !empty($candidate['name_match'])
                        ));

                        $allocationCandidates = $matching ?: $candidateServices;

                        if (!$allocationCandidates) {
                            $unassignedServiceRevenueCents += $lineSubtotal;
                            continue;
                        }

                        $byStaff = [];
                        foreach ($allocationCandidates as $candidate) {
                            $staffId = (string)$candidate['staff_id'];
                            if (!isset($byStaff[$staffId])) {
                                $byStaff[$staffId] = 0;
                            }
                            $byStaff[$staffId] += (int)$candidate['weight'];
                        }

                        if (!$byStaff) {
                            $unassignedServiceRevenueCents += $lineSubtotal;
                            continue;
                        }

                        $providerAttributionObserved = true;
                        $totalWeight = array_sum($byStaff);
                        if ($totalWeight <= 0) {
                            foreach ($byStaff as $staffId => $_) {
                                $byStaff[$staffId] = 1;
                            }
                            $totalWeight = count($byStaff);
                        }

                        $remaining = $lineSubtotal;
                        $staffIds = array_keys($byStaff);
                        $lastIndex = count($staffIds) - 1;

                        foreach ($staffIds as $index => $staffId) {
                            $allocated = $index === $lastIndex
                                ? $remaining
                                : (int)round(
                                    $lineSubtotal
                                    * ((int)$byStaff[$staffId] / $totalWeight)
                                );

                            $remaining -= $allocated;

                            if (!isset($providers[$staffId])) {
                                $providers[$staffId] = [
                                    'staff_id' => $staffId,
                                    'provider' => $staffNames[$staffId] ?? 'Unknown Provider',
                                    'name' => $staffNames[$staffId] ?? 'Unknown Provider',
                                    'appointments' => 0,
                                    'requested' => null,
                                    'requested_appointments' => null,
                                    'requested_available' => false,
                                    'new_clients' => $clientMetadataAvailable ? 0 : null,
                                    'new_clients_available' => $clientMetadataAvailable,
                                    'service_revenue' => 0.0,
                                    'service_revenue_available' => true,
                                    'retail_sales' => null,
                                    'retail_sales_available' => false,
                                    'utilization' => null,
                                    'utilization_available' => false,
                                    'scheduled_hours' => null,
                                    'revenue_per_hour' => null,
                                    'revenue_per_hour_available' => false,
                                    '_appointment_ids' => [],
                                    '_new_client_ids' => [],
                                ];
                            }

                            $providers[$staffId]['service_revenue'] +=
                                $allocated / 100.0;
                            $providers[$staffId]['service_revenue_available'] = true;
                        }
                    } elseif (
                        $lineType === 'OrderProductLine'
                        || $groupType === 'OrderRetailLineGroup'
                    ) {
                        $productRevenueCents += $lineSubtotal;
                    }
                }
            }
        }

        /*
         * If line-group data were returned, zero is a real provider value.
         * Mark every observed provider as attributable so the UI does not hide
         * valid zeros.
         */
        if ($providerAttributionObserved) {
            foreach ($providers as &$provider) {
                if (is_array($provider)) {
                    $provider['service_revenue_available'] = true;
                }
            }
            unset($provider);
        }

        if ($unassignedServiceRevenueCents !== 0) {
            $providers['__unassigned__'] = [
                'staff_id' => null,
                'provider' => 'Unassigned',
                'name' => 'Unassigned',
                'appointments' => 0,
                'requested' => null,
                'requested_appointments' => null,
                'requested_available' => false,
                'new_clients' => null,
                'new_clients_available' => false,
                'service_revenue' => $unassignedServiceRevenueCents / 100.0,
                'service_revenue_available' => true,
                'retail_sales' => null,
                'retail_sales_available' => false,
                'utilization' => null,
                'utilization_available' => false,
                'scheduled_hours' => null,
                'revenue_per_hour' => null,
                'revenue_per_hour_available' => false,
                '_appointment_ids' => [],
                '_new_client_ids' => [],
            ];
        }

        foreach ($providers as &$provider) {
            if (!is_array($provider)) {
                continue;
            }

            unset(
                $provider['_appointment_ids'],
                $provider['_new_client_ids']
            );

            if (
                !empty($provider['utilization_available'])
                && is_numeric($provider['scheduled_hours'] ?? null)
                && (float)$provider['scheduled_hours'] > 0
                && !empty($provider['service_revenue_available'])
                && is_numeric($provider['service_revenue'] ?? null)
            ) {
                $provider['revenue_per_hour'] =
                    (float)$provider['service_revenue']
                    / (float)$provider['scheduled_hours'];
                $provider['revenue_per_hour_available'] = true;
            }
        }
        unset($provider);

        $providerRows = array_values($providers);
        usort($providerRows, static function (array $a, array $b): int {
            if (($a['provider'] ?? '') === 'Unassigned') {
                return 1;
            }
            if (($b['provider'] ?? '') === 'Unassigned') {
                return -1;
            }

            $appointmentsCompare =
                ((int)($b['appointments'] ?? 0))
                <=>
                ((int)($a['appointments'] ?? 0));

            return $appointmentsCompare !== 0
                ? $appointmentsCompare
                : strcasecmp(
                    (string)($a['provider'] ?? ''),
                    (string)($b['provider'] ?? '')
                );
        });

        $intelligence['provider_details'] = $providerRows;
        $intelligence['providers'] = $providerRows;
        $intelligence['provider_performance'] = $providerRows;

        if ($serviceRevenueCents > 0) {
            $intelligence['sales_summary']['service_revenue'] = [
                'available' => true,
                'value' => $serviceRevenueCents / 100.0,
                'format' => 'currency',
                'source' => 'orders.lineGroups.OrderServiceLine.currentSubtotal',
                'definition' =>
                    'Closed-order service-line current subtotal for the selected period.',
            ];
        }

        if ($productRevenueCents > 0) {
            $intelligence['sales_summary']['product_revenue'] = [
                'available' => true,
                'value' => $productRevenueCents / 100.0,
                'format' => 'currency',
                'source' => 'orders.lineGroups.OrderRetailLineGroup',
                'definition' =>
                    'Closed-order retail/product-line current subtotal for the selected period.',
            ];
        }

        $requestedTotal = 0;
        $requestedAvailable = !empty($capabilities['requested_staff']);
        if ($requestedAvailable) {
            foreach ($providerRows as $provider) {
                if (is_numeric($provider['requested'] ?? null)) {
                    $requestedTotal += (int)$provider['requested'];
                }
            }

            $intelligence['sales_summary']['requested_appointments'] = [
                'available' => true,
                'value' => $requestedTotal,
                'format' => 'number',
                'source' => 'appointmentServices.staffRequested',
                'definition' =>
                    'Appointment services where the provider was specifically requested.',
            ];
        }

        if ($clientMetadataAvailable) {
            $newClientIds = [];
            foreach ($appointments as $appointment) {
                if (!is_array($appointment)) {
                    continue;
                }

                $clientId = trim((string)($appointment['clientId'] ?? ''));
                $client = is_array($appointment['client'] ?? null)
                    ? $appointment['client']
                    : null;

                if ($clientId === '' || !$client || empty($client['createdAt'])) {
                    continue;
                }

                $createdDay = self::dateInTimezone(
                    (string)$client['createdAt'],
                    $timezone
                );

                if (
                    $createdDay !== null
                    && $createdDay >= $periodStart
                    && $createdDay <= $periodEnd
                ) {
                    $newClientIds[$clientId] = true;
                }
            }

            $intelligence['performance']['new_clients'] = [
                'available' => true,
                'value' => count($newClientIds),
                'format' => 'number',
                'source' => 'clients.createdAt + appointments.clientId',
                'definition' =>
                    'Unique clients created during the selected reporting period.',
            ];
        } else {
            /*
             * Never expose a fabricated zero when client:read is unavailable.
             */
            $intelligence['performance']['new_clients'] = [
                'available' => false,
                'value' => null,
                'format' => 'number',
                'source' => 'client:read',
                'definition' =>
                    'Requires Boulevard client:read access.',
            ];
        }

        if ($daily !== []) {
            $intelligence['daily'] = array_values($daily);
        }

        return $intelligence;
    }

    private static function providerKey(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/i', ' ', $value) ?? $value;
        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }

    private static function dateInTimezone(
        string $value,
        DateTimeZone $timezone
    ): ?string {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($value))
                ->setTimezone($timezone)
                ->format('Y-m-d');
        } catch (Throwable) {
            return null;
        }
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
