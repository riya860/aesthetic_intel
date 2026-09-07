<?php

declare(strict_types=1);

final class RumaBoulevardAnalytics
{
    private array $appointments = [];
    private array $orders = [];
    private array $staff = [];
    private array $services = [];
    private array $shifts = [];
    private array $memberships = [];
    private array $previousAppointments = [];
    private array $previousOrders = [];
    private array $previousMemberships = [];
    private array $previousShifts = [];
    private array $staffNameById = [];
    private array $serviceNameById = [];
    private array $membershipProductIds = [];
    private array $membershipNames = [];
    private array $packageProductIds = [];
    private array $packageNames = [];
    private array $priorityConfig = [];
    private array $capabilities = [];
    private float $moneyDivisor;
    private ?string $periodStart;
    private ?string $periodEnd;
    private ?string $previousPeriodStart;
    private ?string $previousPeriodEnd;

    public function __construct(array $source, array $options = [])
    {
        $this->moneyDivisor = max(1.0, (float)($options['money_divisor'] ?? 100));
        $this->periodStart = $source['period_start'] ?? null;
        $this->periodEnd = $source['period_end'] ?? null;
        $this->previousPeriodStart = $source['previous_period_start'] ?? null;
        $this->previousPeriodEnd = $source['previous_period_end'] ?? null;
        $this->capabilities = is_array($source['capabilities'] ?? null)
            ? $source['capabilities']
            : [];

        $priorityFile = $options['priority_config'] ?? __DIR__ . '/../../config/ruma-boulevard-priority.php';
        if (is_file($priorityFile)) {
            $loaded = require $priorityFile;
            if (is_array($loaded)) {
                $this->priorityConfig = $loaded;
            }
        }

        $rawStaff = self::rows($source['staff'] ?? []);
        $rawServices = self::rows($source['services'] ?? []);
        $rawMemberships = self::rows($source['memberships'] ?? []);
        $rawMembershipPlans = self::rows($source['membership_plans'] ?? []);
        $rawPackages = self::rows($source['packages'] ?? []);
        $rawProducts = self::rows($source['products'] ?? []);

        foreach ($rawMemberships as $row) {
            $productId = trim((string)self::getAny($row, ['productId', 'product.id'], ''));
            $name = self::key((string)self::getAny($row, ['name'], ''));
            if ($productId !== '') {
                $this->membershipProductIds[$productId] = true;
            }
            if ($name !== '') {
                $this->membershipNames[$name] = true;
            }
        }

        foreach ($rawPackages as $row) {
            $productId = trim((string)self::getAny($row, ['productId', 'product.id'], ''));
            $name = self::key((string)self::getAny($row, ['name', 'product.name'], ''));
            if ($productId !== '') {
                $this->packageProductIds[$productId] = true;
            }
            if ($name !== '') {
                $this->packageNames[$name] = true;
            }
        }

        /* Membership plans and packages are catalogue entities. Boulevard does
         * not expose a productId on every catalogue shape, so names are kept as
         * a deliberate secondary classifier for OrderProductLine rows. */
        foreach ($rawMembershipPlans as $row) {
            $name = self::key((string)self::getAny($row, ['name'], ''));
            if ($name !== '') {
                $this->membershipNames[$name] = true;
            }
        }

        /* Product categories can also identify plan/package products in
         * accounts where Boulevard includes them in the products connection. */
        foreach ($rawProducts as $row) {
            $productId = trim((string)self::getAny($row, ['id'], ''));
            if ($productId === '') {
                continue;
            }

            $category = strtolower(trim((string)self::getAny($row, ['category.name'], '')));
            $name = self::key((string)self::getAny($row, ['name'], ''));

            if (str_contains($category, 'membership') || str_contains($category, 'subscription')) {
                $this->membershipProductIds[$productId] = true;
                if ($name !== '') {
                    $this->membershipNames[$name] = true;
                }
            }

            if (str_contains($category, 'package')) {
                $this->packageProductIds[$productId] = true;
                if ($name !== '') {
                    $this->packageNames[$name] = true;
                }
            }
        }

        foreach ($rawStaff as $row) {
            $id = (string)self::getAny($row, ['id'], '');
            $name = (string)self::getAny($row, ['displayName', 'name'], '');
            if ($id !== '' && $name !== '') {
                $this->staffNameById[$id] = $name;
            }
        }
        foreach ($rawServices as $row) {
            $id = (string)self::getAny($row, ['id'], '');
            $name = (string)self::getAny($row, ['name'], '');
            if ($id !== '' && $name !== '') {
                $this->serviceNameById[$id] = $name;
            }
        }

        $this->staff = array_map(fn(array $r): array => $this->normalizeStaff($r), $rawStaff);
        $this->services = array_map(fn(array $r): array => $this->normalizeService($r), $rawServices);
        $this->appointments = array_map(fn(array $r): array => $this->normalizeAppointment($r), self::rows($source['appointments'] ?? []));
        $this->orders = array_map(fn(array $r): array => $this->normalizeOrder($r), self::rows($source['orders'] ?? []));
        $this->shifts = array_map(fn(array $r): array => $this->normalizeShift($r), self::rows($source['shifts'] ?? []));
        $this->memberships = array_map(fn(array $r): array => $this->normalizeMembership($r), $rawMemberships);

        $this->previousAppointments = array_map(fn(array $r): array => $this->normalizeAppointment($r), self::rows($source['previous_appointments'] ?? []));
        $this->previousOrders = array_map(fn(array $r): array => $this->normalizeOrder($r), self::rows($source['previous_orders'] ?? []));
        $this->previousMemberships = array_map(fn(array $r): array => $this->normalizeMembership($r), self::rows($source['previous_memberships'] ?? []));
        $this->previousShifts = array_map(fn(array $r): array => $this->normalizeShift($r), self::rows($source['previous_shifts'] ?? []));
    }

    public function build(): array
    {
        $current = $this->computePeriod(
            $this->appointments,
            $this->orders,
            $this->memberships,
            $this->shifts,
            $this->periodStart,
            $this->periodEnd,
            (bool)($this->capabilities['memberships'] ?? false),
            false
        );

        $previousAvailable = !empty($this->previousAppointments)
            || !empty($this->previousOrders)
            || !empty($this->previousMemberships)
            || !empty($this->previousShifts);

        $previous = $previousAvailable
            ? $this->computePeriod(
                $this->previousAppointments,
                $this->previousOrders,
                $this->previousMemberships,
                $this->previousShifts,
                $this->previousPeriodStart,
                $this->previousPeriodEnd,
                (bool)($this->capabilities['memberships'] ?? false),
                true
            )
            : null;

        $providerDetails = $this->providerDetails($this->appointments, $this->orders, $this->shifts);
        $revenueMix = $this->revenueMix($this->orders);
        $daily = $this->dailyPerformance($this->orders, $this->appointments);
        $membershipTrend = $this->membershipTrend($this->memberships);

        $performance = [
            'total_revenue' => $this->metric(
                $current['total_revenue'],
                'currency',
                true,
                'orders.summary.currentTotal',
                $previous ? $previous['total_revenue'] : null
            ),
            'total_appointments' => $this->metric(
                $current['total_appointments'],
                'number',
                true,
                'appointments',
                $previous ? $previous['total_appointments'] : null
            ),
            'new_clients' => $this->metric(
                $current['new_clients'],
                'number',
                $current['new_clients_available'],
                'appointment.client.appointmentCount / client.createdAt',
                $previous && $previous['new_clients_available'] ? $previous['new_clients'] : null
            ),
            'blended_utilization' => $this->metric(
                $current['utilization'],
                'percent',
                $current['utilization_available'],
                'booked appointment minutes / scheduled shift minutes',
                $previous && $previous['utilization_available'] ? $previous['utilization'] : null
            ),
            'current_active_mrr' => $this->metric(
                $current['mrr'],
                'currency',
                $current['membership_available'],
                'active memberships normalized to monthly value',
                $previous && $previous['membership_available'] ? $previous['mrr'] : null
            ),
        ];

        $salesSummary = [
            'total_revenue' => $this->metric($current['total_revenue'], 'currency', true, 'orders.summary.currentTotal'),
            'total_appointments' => $this->metric($current['total_appointments'], 'number', true, 'appointments'),
            'requested_appointments' => $this->metric(
                $current['requested_appointments'],
                'number',
                $current['requested_available'],
                'appointmentServices.staffRequested'
            ),
            'service_revenue' => $this->metric(
                $revenueMix['service'],
                'currency',
                $revenueMix['available_types']['service'],
                'OrderServiceLine.currentSubtotal'
            ),
            'product_revenue' => $this->metric(
                $revenueMix['product'],
                'currency',
                $revenueMix['available_types']['product'],
                'OrderProductLine.currentSubtotal'
            ),
            'membership_revenue' => $this->metric(
                $revenueMix['membership'],
                'currency',
                $revenueMix['available_types']['membership'],
                'membership/order line classification'
            ),
            'package_revenue' => $this->metric(
                $revenueMix['package'],
                'currency',
                $revenueMix['available_types']['package'],
                'package/order line classification'
            ),
            'tips' => $this->metric($current['gratuity'], 'currency', true, 'orders.summary.currentGratuityAmount'),
        ];

        $financial = [
            'net_sales' => $this->metric($current['subtotal'], 'currency', true, 'orders.summary.currentSubtotal'),
            'gross_payments' => $this->metric(
                $current['gross_payments'],
                'currency',
                $current['gross_payments_available'],
                'orders.paymentGroups.totalPaid'
            ),
            'refunds' => $this->metric($current['refunds'], 'currency', true, 'orders.summary.refundAmount'),
            'gift_cards_sold' => $this->metric(
                $revenueMix['gift_card'],
                'currency',
                $revenueMix['available_types']['gift_card'],
                'OrderGiftCardLine.currentSubtotal'
            ),
            'account_credit_sold' => $this->metric(
                $revenueMix['account_credit'],
                'currency',
                $revenueMix['available_types']['account_credit'],
                'OrderAccountCreditLine.currentSubtotal'
            ),
            'tax_collected' => $this->metric($current['tax'], 'currency', true, 'orders.summary.currentTaxAmount'),
            'card_fees' => $this->metric(
                $current['payment_fees'],
                'currency',
                $current['payment_fees_available'],
                'orders.paymentGroups.totalFees'
            ),
        ];

        $membership = [
            'current_active_mrr' => $performance['current_active_mrr'],
            'change_since_previous_period' => $this->metric(
                ($previous && $previous['membership_available'] && $current['membership_available'])
                    ? $this->pctChange($current['mrr'], $previous['mrr'])
                    : null,
                'percent',
                (bool)($previous && $previous['membership_available'] && $current['membership_available']),
                'current MRR vs previous-period MRR'
            ),
            'current_arr' => $this->metric(
                $current['membership_available'] ? $current['mrr'] * 12 : null,
                'currency',
                $current['membership_available'],
                'MRR × 12'
            ),
            'active_memberships' => $this->metric(
                $current['active_memberships'],
                'number',
                $current['membership_available'],
                'memberships.status = ACTIVE'
            ),
            'trend' => $membershipTrend,
        ];

        $providerPerformance = [];
        foreach ($providerDetails as $provider) {
            $providerPerformance[] = [
                'provider' => $provider['provider'],
                'service_revenue' => $provider['service_revenue'],
                'service_revenue_available' => $provider['service_revenue_available'],
                'booked_service_value' => $provider['booked_service_value'],
                'utilization' => $provider['utilization'],
                'utilization_available' => $provider['utilization_available'],
                'scheduled_hours' => $provider['scheduled_hours'],
                'appointments' => $provider['appointments'],
                'new_clients' => $provider['new_clients'],
                'new_clients_available' => $provider['new_clients_available'],
                'revenue_per_hour' => $provider['revenue_per_hour'],
                'revenue_per_hour_available' => $provider['revenue_per_hour_available'],
                'retail_sales' => $provider['retail_sales'],
            ];
        }

        return [
            'period' => [
                'start' => $this->periodStart,
                'end' => $this->periodEnd,
            ],
            'performance' => $performance,
            'provider_performance' => $providerPerformance,
            'provider_details' => $providerDetails,
            'revenue_per_scheduled_hour' => $providerPerformance,
            'membership' => $membership,
            'sales_summary' => $salesSummary,
            'financial' => $financial,
            'revenue_mix' => $revenueMix,
            'daily_performance' => $daily,
            'insights' => $this->insights($current, $previous, $providerDetails, $revenueMix),
            'coverage' => $this->coverage($current, $revenueMix),
            'reference' => [
                'staff' => $this->staff,
                'services' => $this->services,
            ],
        ];
    }

    private function computePeriod(
        array $appointments,
        array $orders,
        array $memberships,
        array $shifts,
        ?string $periodStart,
        ?string $periodEnd,
        bool $membershipSourceAvailable,
        bool $historicalMembershipState
    ): array
    {
        $totalRevenue = 0.0;
        $subtotal = 0.0;
        $discounts = 0.0;
        $tax = 0.0;
        $gratuity = 0.0;
        $fees = 0.0;
        $refunds = 0.0;
        $grossPayments = 0.0;
        $paymentFees = 0.0;
        $grossPaymentsAvailable = false;
        $paymentFeesAvailable = false;

        foreach ($orders as $order) {
            $totalRevenue += $order['current_total'];
            $subtotal += $order['current_subtotal'];
            $discounts += $order['current_discount'];
            $tax += $order['current_tax'];
            $gratuity += $order['current_gratuity'];
            $fees += $order['current_fee'];
            $refunds += $order['refund_amount'];

            if ($order['payment_total_available']) {
                $grossPayments += $order['payment_total'];
                $grossPaymentsAvailable = true;
            }
            if ($order['payment_fees_available']) {
                $paymentFees += $order['payment_fees'];
                $paymentFeesAvailable = true;
            }
        }

        $appointmentIds = [];
        $completed = 0;
        $cancelled = 0;
        $requested = 0;
        $requestedAvailable = false;
        $bookedMinutes = 0.0;
        $newClientIds = [];
        $newClientAvailability = false;

        foreach ($appointments as $a) {
            $id = (string)($a['id'] ?: spl_object_id((object)$a));
            if (isset($appointmentIds[$id])) {
                continue;
            }
            $appointmentIds[$id] = true;

            if ($a['cancelled']) {
                $cancelled++;
            }
            if ($a['completed']) {
                $completed++;
            }
            if ($a['staff_requested'] !== null) {
                $requestedAvailable = true;
                if ($a['staff_requested']) {
                    $requested++;
                }
            }
            if (!$a['cancelled']) {
                $bookedMinutes += $a['duration_minutes'];
            }

            if ($a['client_appointment_count'] !== null || $a['client_created_at'] !== null) {
                $newClientAvailability = true;
                $isNew = $a['client_appointment_count'] === 1;
                if (!$isNew && $a['client_created_at'] && $periodStart && $periodEnd) {
                    $created = strtotime($a['client_created_at']);
                    $start = strtotime($periodStart);
                    $end = strtotime($periodEnd . ' 23:59:59');
                    $isNew = $created !== false && $start !== false && $end !== false && $created >= $start && $created <= $end;
                }
                if ($isNew) {
                    $newClientIds[$a['client_id'] ?: $id] = true;
                }
            }
        }

        $scheduledMinutes = 0.0;
        foreach ($shifts as $shift) {
            if (($shift['available'] ?? true) !== true) {
                continue;
            }
            $scheduledMinutes += $shift['minutes'];
        }
        $utilizationAvailable = $scheduledMinutes > 0;
        $utilization = $utilizationAvailable ? min(1.0, $bookedMinutes / $scheduledMinutes) : null;

        /*
         * A successful memberships query can legitimately return zero rows.
         * Treat that as an available $0 result instead of displaying a dash.
         * For comparisons, evaluate membership lifecycle as-of the period end
         * so one complete membership collection can power both periods.
         */
        $membershipAvailable = $membershipSourceAvailable;
        $mrr = 0.0;
        $activeMemberships = 0;
        foreach ($memberships as $membership) {
            $membershipIsActive = $historicalMembershipState
                ? $this->membershipActiveAt($membership, $periodEnd)
                : (bool)($membership['active'] ?? false);

            if ($membershipIsActive) {
                $activeMemberships++;
                $mrr += $membership['monthly_value'];
            }
        }

        return [
            'total_revenue' => $totalRevenue,
            'subtotal' => $subtotal,
            'discounts' => $discounts,
            'tax' => $tax,
            'gratuity' => $gratuity,
            'fees' => $fees,
            'refunds' => $refunds,
            'gross_payments' => $grossPayments,
            'gross_payments_available' => $grossPaymentsAvailable,
            'payment_fees' => $paymentFees,
            'payment_fees_available' => $paymentFeesAvailable,
            'total_appointments' => count($appointmentIds),
            'completed' => $completed,
            'cancelled' => $cancelled,
            'requested_appointments' => $requested,
            'requested_available' => $requestedAvailable,
            'new_clients' => count($newClientIds),
            'new_clients_available' => $newClientAvailability,
            'booked_minutes' => $bookedMinutes,
            'scheduled_minutes' => $scheduledMinutes,
            'utilization' => $utilization,
            'utilization_available' => $utilizationAvailable,
            'mrr' => $mrr,
            'active_memberships' => $activeMemberships,
            'membership_available' => $membershipAvailable,
        ];
    }

    private function providerDetails(array $appointments, array $orders, array $shifts): array
    {
        $providers = [];

        $ensure = static function (array &$providers, string $name): void {
            $key = trim($name) !== '' ? trim($name) : 'Unassigned';
            if (!isset($providers[$key])) {
                $providers[$key] = [
                    'provider' => $key,
                    'service_lines' => 0,
                    'booked_service_value' => 0.0,
                    'service_revenue' => 0.0,
                    'service_revenue_available' => false,
                    'scheduled_hours' => 0.0,
                    'appointments' => 0,
                    'new_clients' => 0,
                    'new_clients_available' => false,
                    'retail_sales' => 0.0,
                    'utilization' => null,
                    'utilization_available' => false,
                    'revenue_per_hour' => null,
                    'revenue_per_hour_available' => false,
                    '_booked_minutes' => 0.0,
                    '_appointment_ids' => [],
                    '_new_client_ids' => [],
                ];
            }
        };

        foreach ($appointments as $a) {
            foreach ($a['service_lines'] as $line) {
                $provider = $line['provider'] ?: $a['provider'] ?: 'Unassigned';
                $ensure($providers, $provider);
                $providers[$provider]['service_lines']++;
                $providers[$provider]['booked_service_value'] += $line['booked_value'];
                if (!$a['cancelled']) {
                    $providers[$provider]['_booked_minutes'] += $line['duration_minutes'];
                }
                $providers[$provider]['_appointment_ids'][(string)$a['id']] = true;

                if ($a['client_appointment_count'] !== null) {
                    $providers[$provider]['new_clients_available'] = true;
                    if ($a['client_appointment_count'] === 1) {
                        $providers[$provider]['_new_client_ids'][$a['client_id'] ?: (string)$a['id']] = true;
                    }
                }
            }
        }

        foreach ($orders as $order) {
            foreach ($order['lines'] as $line) {
                $provider = $line['provider'] ?: 'Unassigned';
                $type = $line['type'];
                if ($type === 'service') {
                    $ensure($providers, $provider);
                    $providers[$provider]['service_revenue'] += $line['subtotal'];
                    $providers[$provider]['service_revenue_available'] = true;
                } elseif ($type === 'product') {
                    $ensure($providers, $provider);
                    $providers[$provider]['retail_sales'] += $line['subtotal'];
                }
            }
        }

        foreach ($shifts as $shift) {
            if (($shift['available'] ?? true) !== true) {
                continue;
            }
            $provider = $shift['provider'] ?: 'Unassigned';
            $ensure($providers, $provider);
            $providers[$provider]['scheduled_hours'] += $shift['minutes'] / 60.0;
        }

        foreach ($providers as &$p) {
            $p['appointments'] = count($p['_appointment_ids']);
            $p['new_clients'] = count($p['_new_client_ids']);
            if ($p['scheduled_hours'] > 0) {
                $p['utilization_available'] = true;
                $p['utilization'] = min(1.0, ($p['_booked_minutes'] / 60.0) / $p['scheduled_hours']);
                if ($p['service_revenue_available']) {
                    $p['revenue_per_hour_available'] = true;
                    $p['revenue_per_hour'] = $p['service_revenue'] / $p['scheduled_hours'];
                }
            }
            unset($p['_booked_minutes'], $p['_appointment_ids'], $p['_new_client_ids']);
        }
        unset($p);

        usort($providers, static fn(array $a, array $b): int => ($b['service_revenue'] <=> $a['service_revenue']) ?: ($b['booked_service_value'] <=> $a['booked_service_value']));
        return array_values($providers);
    }

    private function revenueMix(array $orders): array
    {
        $mix = [
            'service' => 0.0,
            'product' => 0.0,
            'membership' => 0.0,
            'package' => 0.0,
            'gift_card' => 0.0,
            'account_credit' => 0.0,
            'gratuity' => 0.0,
            'other' => 0.0,
            'available_types' => [
                /*
                 * When Boulevard accepted the detailed source, a missing line
                 * means a real $0 for that category, not an unknown value.
                 */
                'service' => (bool)($this->capabilities['order_service_lines'] ?? false),
                'product' => (bool)($this->capabilities['order_retail_lines'] ?? false),
                'membership' => (bool)($this->capabilities['order_retail_lines'] ?? false),
                'package' => (bool)($this->capabilities['order_retail_lines'] ?? false),
                'gift_card' => (bool)($this->capabilities['order_retail_lines'] ?? false),
                'account_credit' => (bool)($this->capabilities['order_retail_lines'] ?? false),
            ],
        ];

        foreach ($orders as $order) {
            $mix['gratuity'] += $order['current_gratuity'];
            foreach ($order['lines'] as $line) {
                $type = $line['type'];
                if (array_key_exists($type, $mix)) {
                    $mix[$type] += $line['subtotal'];
                    if (isset($mix['available_types'][$type])) {
                        $mix['available_types'][$type] = true;
                    }
                } else {
                    $mix['other'] += $line['subtotal'];
                }
            }
        }

        return $mix;
    }

    private function dailyPerformance(array $orders, array $appointments): array
    {
        $days = [];
        foreach ($orders as $order) {
            if (!$order['closed_at']) {
                continue;
            }
            $day = substr($order['closed_at'], 0, 10);
            $days[$day] ??= ['date' => $day, 'revenue' => 0.0, 'appointments' => 0, 'cancelled' => 0];
            $days[$day]['revenue'] += $order['current_total'];
        }
        foreach ($appointments as $a) {
            if (!$a['start_at']) {
                continue;
            }
            $day = substr($a['start_at'], 0, 10);
            $days[$day] ??= ['date' => $day, 'revenue' => 0.0, 'appointments' => 0, 'cancelled' => 0];
            $days[$day]['appointments']++;
            if ($a['cancelled']) {
                $days[$day]['cancelled']++;
            }
        }
        ksort($days);
        return array_values($days);
    }

    private function membershipTrend(array $memberships): array
    {
        if (empty($memberships) || !$this->periodStart || !$this->periodEnd) {
            return [];
        }

        $start = new DateTimeImmutable($this->periodStart);
        $end = new DateTimeImmutable($this->periodEnd);
        $days = max(1, (int)$start->diff($end)->format('%a'));
        $step = $days > 120 ? new DateInterval('P1M') : new DateInterval('P1D');

        $trend = [];
        for ($d = $start; $d <= $end; $d = $d->add($step)) {
            $date = $d->format('Y-m-d');
            $mrr = 0.0;
            foreach ($memberships as $m) {
                $starts = !$m['start_on'] || $m['start_on'] <= $date;
                $ends = !$m['end_on'] || $m['end_on'] >= $date;
                if ($starts && $ends) {
                    $mrr += $m['monthly_value'];
                }
            }
            $trend[] = ['date' => $date, 'mrr' => $mrr];
        }
        return $trend;
    }

    private function insights(array $current, ?array $previous, array $providers, array $mix): array
    {
        $thresholds = $this->priorityConfig['thresholds'] ?? [];
        $insights = [];

        $add = static function (array &$insights, string $priority, string $method, string $title, string $observation): void {
            $insights[] = compact('priority', 'method', 'title', 'observation');
        };

        if ($previous) {
            $revenueChange = $this->pctChange($current['total_revenue'], $previous['total_revenue']);
            if ($revenueChange !== null) {
                $priority = $revenueChange <= (float)($thresholds['revenue_drop_high'] ?? -0.10) ? 'High' : ($revenueChange < 0 ? 'Medium' : 'Low');
                $add($insights, $priority, 'Revenue optimization', sprintf('Revenue %s by %.1f%%', $revenueChange >= 0 ? 'increased' : 'decreased', abs($revenueChange * 100)), $revenueChange >= 0 ? 'Revenue momentum is positive versus the previous period.' : 'Revenue is below the previous period and deserves review.');
            }

            $apptChange = $this->pctChange((float)$current['total_appointments'], (float)$previous['total_appointments']);
            if ($apptChange !== null) {
                $priority = $apptChange <= (float)($thresholds['appointment_drop_high'] ?? -0.10) ? 'High' : ($apptChange < 0 ? 'Medium' : 'Low');
                $add($insights, $priority, 'Demand / bookings', sprintf('Appointments %s by %.1f%%', $apptChange >= 0 ? 'increased' : 'decreased', abs($apptChange * 100)), $apptChange >= 0 ? 'Booking volume improved versus the previous period.' : 'Booking volume softened versus the previous period.');
            }
        }

        if ($current['total_appointments'] > 0) {
            $cancelRate = $current['cancelled'] / $current['total_appointments'];
            $priority = $cancelRate >= (float)($thresholds['cancellation_rate_high'] ?? 0.15) ? 'High' : ($cancelRate >= 0.08 ? 'Medium' : 'Low');
            $add($insights, $priority, 'Operational efficiency', sprintf('Cancellation rate is %.1f%%', $cancelRate * 100), $priority === 'High' ? 'Cancellations are materially reducing booked capacity.' : 'Cancellation pressure is currently manageable.');
        }

        if ($current['utilization_available']) {
            $priority = $current['utilization'] < (float)($thresholds['utilization_low'] ?? 0.60) ? 'High' : ($current['utilization'] < 0.75 ? 'Medium' : 'Low');
            $add($insights, $priority, 'Provider utilization', sprintf('Blended utilization is %.1f%%', $current['utilization'] * 100), $priority === 'High' ? 'Scheduled capacity is underused and provider schedules should be reviewed.' : 'Utilization is within a healthier operating range.');
        }

        if ($current['total_revenue'] > 0) {
            $refundRate = $current['refunds'] / max($current['total_revenue'] + $current['refunds'], 0.01);
            $priority = $refundRate >= (float)($thresholds['refund_rate_high'] ?? 0.08) ? 'High' : ($refundRate >= 0.04 ? 'Medium' : 'Low');
            $add($insights, $priority, 'Sales quality', sprintf('Refund rate is %.1f%%', $refundRate * 100), $priority === 'High' ? 'Refund activity is high enough to investigate by service, provider or product.' : 'Refund activity is not currently a major revenue-quality concern.');
        }

        if ($current['membership_available']) {
            $add($insights, 'Medium', 'Membership growth', sprintf('%d active memberships generate approximately $%s MRR', $current['active_memberships'], number_format($current['mrr'], 2)), 'Recurring revenue should be monitored alongside service revenue for stability.');
        }

        if ($mix['available_types']['product']) {
            $productShare = $current['total_revenue'] > 0 ? $mix['product'] / $current['total_revenue'] : 0;
            $add($insights, $productShare < 0.05 ? 'Medium' : 'Low', 'Retail optimization', sprintf('Retail product sales represent %.1f%% of current revenue', $productShare * 100), $productShare < 0.05 ? 'Retail attachment may be an opportunity if product sales are strategically important.' : 'Retail contribution is meaningful in the current mix.');
        }

        $rank = ['High' => 3, 'Medium' => 2, 'Low' => 1];
        $businessWeights = $this->priorityConfig['priority_order'] ?? [];
        $methodWeight = static function (string $method) use ($businessWeights): int {
            $method = strtolower($method);
            $key = match (true) {
                str_contains($method, 'revenue') => 'revenue',
                str_contains($method, 'demand') || str_contains($method, 'booking') => 'appointments',
                str_contains($method, 'provider') => 'providers',
                str_contains($method, 'utilization') => 'utilization',
                str_contains($method, 'membership') => 'memberships',
                str_contains($method, 'client') => 'clients',
                str_contains($method, 'retail') => 'retail',
                default => 'reference',
            };
            return (int)($businessWeights[$key] ?? 0);
        };

        usort(
            $insights,
            static function (array $a, array $b) use ($rank, $methodWeight): int {
                $severity = ($rank[$b['priority']] ?? 0) <=> ($rank[$a['priority']] ?? 0);
                if ($severity !== 0) {
                    return $severity;
                }
                return $methodWeight((string)$b['method']) <=> $methodWeight((string)$a['method']);
            }
        );
        return $insights;
    }

    private function coverage(array $current, array $mix): array
    {
        return [
            ['area' => 'Orders / revenue totals', 'available' => true, 'note' => 'Directly derived from order summaries.'],
            ['area' => 'Appointments', 'available' => true, 'note' => 'Directly derived from appointment records.'],
            ['area' => 'Requested appointments', 'available' => $current['requested_available'], 'note' => 'Requires appointmentServices.staffRequested.'],
            ['area' => 'New clients', 'available' => $current['new_clients_available'], 'note' => 'Requires client appointmentCount or createdAt in appointment payload.'],
            ['area' => 'Provider utilization / scheduled hours', 'available' => $current['utilization_available'], 'note' => 'Requires Boulevard shift/schedule data.'],
            ['area' => 'Membership MRR / ARR', 'available' => $current['membership_available'], 'note' => 'Requires memberships query/report data.'],
            ['area' => 'Service revenue by provider', 'available' => $mix['available_types']['service'], 'note' => 'Requires typed OrderServiceLine data.'],
            ['area' => 'Product revenue', 'available' => $mix['available_types']['product'], 'note' => 'Requires typed OrderProductLine data.'],
            ['area' => 'Gift cards / account credit', 'available' => $mix['available_types']['gift_card'] || $mix['available_types']['account_credit'], 'note' => 'Requires typed order-line data.'],
            ['area' => 'Membership/package sales', 'available' => $mix['available_types']['membership'] || $mix['available_types']['package'], 'note' => 'Requires membership/package line classification or Boulevard report export.'],
        ];
    }

    private function normalizeAppointment(array $r): array
    {
        $services = self::rows(self::getAny($r, ['appointmentServices', 'appointment_services', 'services'], []));
        $serviceLines = [];
        $requested = null;
        foreach ($services as $s) {
            $staffId = (string)self::getAny($s, ['staffId', 'staff_id'], '');
            $serviceId = (string)self::getAny($s, ['serviceId', 'service_id'], '');
            $provider = (string)self::getAny($s, ['staff.displayName', 'staff.name', 'provider.name', 'provider', 'staffName'], '');
            if ($provider === '' && $staffId !== '') {
                $provider = $this->staffNameById[$staffId] ?? $staffId;
            }
            $service = (string)self::getAny($s, ['service.name', 'service', 'name'], '');
            if ($service === '' && $serviceId !== '') {
                $service = $this->serviceNameById[$serviceId] ?? $serviceId;
            }
            $duration = (float)self::getAny($s, ['duration', 'totalDuration', 'duration_minutes'], 0);
            $bookedValue = $this->money(self::getAny($s, ['price', 'bookedValue', 'booked_value'], 0));
            $staffRequested = self::getAny($s, ['staffRequested', 'staff_requested'], null);
            if ($staffRequested !== null) {
                $requested = (bool)$staffRequested || ($requested === true);
            }
            $serviceLines[] = [
                'provider' => $provider,
                'service' => $service,
                'duration_minutes' => $duration,
                'booked_value' => $bookedValue,
            ];
        }

        if (empty($serviceLines)) {
            $serviceLines[] = [
                'provider' => (string)self::getAny($r, ['provider', 'staff.name', 'staff.displayName'], ''),
                'service' => (string)self::getAny($r, ['service', 'service.name'], ''),
                'duration_minutes' => (float)self::getAny($r, ['duration', 'duration_minutes', 'totalDuration'], 0),
                'booked_value' => $this->money(self::getAny($r, ['booked_value', 'bookedValue', 'price'], 0)),
            ];
        }

        $status = strtoupper((string)self::getAny($r, ['status', 'state'], ''));
        $cancelledRaw = self::getAny($r, ['cancelled'], null);
        $cancelled = $cancelledRaw !== null ? (bool)$cancelledRaw : str_contains($status, 'CANCEL');
        $completed = str_contains($status, 'COMPLETE') || str_contains($status, 'FINISH') || str_contains($status, 'CLOSED');

        return [
            'id' => (string)self::getAny($r, ['id', 'appointmentId', 'appointment_id'], ''),
            'start_at' => (string)self::getAny($r, ['startAt', 'start', 'start_at'], ''),
            'status' => $status,
            'cancelled' => $cancelled,
            'completed' => $completed,
            'provider' => (string)self::getAny($r, ['provider', 'staff.name', 'staff.displayName'], $serviceLines[0]['provider'] ?? ''),
            'duration_minutes' => array_sum(array_column($serviceLines, 'duration_minutes')),
            'staff_requested' => $requested ?? self::nullableBool(self::getAny($r, ['staffRequested', 'staff_requested'], null)),
            'client_id' => (string)self::getAny($r, ['client.id', 'clientId', 'client_id'], ''),
            'client_appointment_count' => self::nullableInt(self::getAny($r, ['client.appointmentCount', 'client_appointment_count'], null)),
            'client_created_at' => self::nullableString(self::getAny($r, ['client.createdAt', 'client_created_at'], null)),
            'service_lines' => $serviceLines,
        ];
    }

    private function normalizeOrder(array $r): array
    {
        $summary = (array)self::getAny($r, ['summary'], []);
        $groups = self::rows(self::getAny($r, ['lineGroups', 'line_groups'], []));
        $rawLines = self::rows(self::getAny($r, ['lines', 'line_items'], []));
        foreach ($groups as $group) {
            foreach (self::rows(self::getAny($group, ['lines'], [])) as $line) {
                $rawLines[] = $line;
            }
        }

        $lines = [];
        foreach ($rawLines as $line) {
            $typeName = strtolower((string)self::getAny($line, ['__typename', 'type', 'lineType', 'line_type'], 'other'));
            $productId = trim((string)self::getAny($line, ['productId', 'product.id'], ''));
            $lineName = (string)self::getAny($line, ['name', 'service.name', 'product.name'], '');
            $nameKey = self::key($lineName);

            $type = match (true) {
                str_contains($typeName, 'service') => 'service',
                str_contains($typeName, 'gift') => 'gift_card',
                str_contains($typeName, 'accountcredit') || str_contains($typeName, 'account_credit') => 'account_credit',
                str_contains($typeName, 'gratuity') || str_contains($typeName, 'tip') => 'gratuity',
                str_contains($typeName, 'product') && (
                    ($productId !== '' && isset($this->membershipProductIds[$productId]))
                    || ($nameKey !== '' && isset($this->membershipNames[$nameKey]))
                ) => 'membership',
                str_contains($typeName, 'product') && (
                    ($productId !== '' && isset($this->packageProductIds[$productId]))
                    || ($nameKey !== '' && isset($this->packageNames[$nameKey]))
                ) => 'package',
                str_contains($typeName, 'product') && !str_contains($typeName, 'card') => 'product',
                str_contains($typeName, 'membership') => 'membership',
                str_contains($typeName, 'package') => 'package',
                default => 'other',
            };

            $subtotal = $this->money(self::getAny($line, ['currentSubtotal', 'current_subtotal', 'subtotal'], 0));

            $providerNames = [];
            foreach (self::rows(self::getAny($line, ['providers'], [])) as $attribution) {
                $selected = self::getAny($attribution, ['selected'], null);
                if ($selected === false) {
                    continue;
                }
                $provider = trim((string)self::getAny(
                    $attribution,
                    ['staff.displayName', 'staff.name', 'name'],
                    ''
                ));
                if ($provider !== '') {
                    $providerNames[$provider] = true;
                }
            }

            if (!$providerNames) {
                $provider = trim((string)self::getAny(
                    $line,
                    ['staff.name', 'staff.displayName', 'seller.name', 'seller.displayName', 'provider', 'seller'],
                    ''
                ));
                if ($provider !== '') {
                    $providerNames[$provider] = true;
                }
            }

            /*
             * Boulevard can attribute one service line to more than one
             * provider. Split the monetary subtotal so provider totals never
             * double-count the order while the overall revenue mix remains exact.
             */
            if ($type === 'service' && count($providerNames) > 1) {
                $share = $subtotal / count($providerNames);
                foreach (array_keys($providerNames) as $provider) {
                    $lines[] = [
                        'type' => $type,
                        'subtotal' => $share,
                        'provider' => $provider,
                        'name' => $lineName,
                        'product_id' => $productId,
                    ];
                }
                continue;
            }

            $lines[] = [
                'type' => $type,
                'subtotal' => $subtotal,
                'provider' => $providerNames ? (string)array_key_first($providerNames) : '',
                'name' => $lineName,
                'product_id' => $productId,
            ];
        }

        $paymentGroups = self::rows(self::getAny($r, ['paymentGroups', 'payment_groups'], []));
        $paymentTotal = 0.0;
        $paymentFees = 0.0;
        $paymentTotalAvailable = false;
        $paymentFeesAvailable = false;
        foreach ($paymentGroups as $pg) {
            $paid = self::getAny($pg, ['totalPaid', 'total_paid'], null);
            if ($paid !== null) {
                $paymentTotal += $this->money($paid);
                $paymentTotalAvailable = true;
            }
            $fee = self::getAny($pg, ['totalFees', 'total_fees'], null);
            if ($fee !== null) {
                $paymentFees += $this->money($fee);
                $paymentFeesAvailable = true;
            }
        }

        return [
            'id' => (string)self::getAny($r, ['id'], ''),
            'number' => (string)self::getAny($r, ['number', 'order', 'orderNumber'], ''),
            'closed_at' => (string)self::getAny($r, ['closedAt', 'closed', 'closed_at'], ''),
            'current_subtotal' => $this->money(self::getAny($summary, ['currentSubtotal', 'current_subtotal'], self::getAny($r, ['subtotal'], 0))),
            'current_discount' => $this->money(self::getAny($summary, ['currentDiscountAmount', 'current_discount_amount'], self::getAny($r, ['discounts'], 0))),
            'current_tax' => $this->money(self::getAny($summary, ['currentTaxAmount', 'current_tax_amount'], self::getAny($r, ['tax'], 0))),
            'current_gratuity' => $this->money(self::getAny($summary, ['currentGratuityAmount', 'current_gratuity_amount'], self::getAny($r, ['gratuity', 'tips'], 0))),
            'current_fee' => $this->money(self::getAny($summary, ['currentFeeAmount', 'current_fee_amount'], self::getAny($r, ['fees'], 0))),
            'refund_amount' => $this->money(self::getAny($summary, ['refundAmount', 'refund_amount'], self::getAny($r, ['refunds'], 0))),
            'current_total' => $this->money(self::getAny($summary, ['currentTotal', 'current_total'], self::getAny($r, ['current_total', 'total'], 0))),
            'initial_total' => $this->money(self::getAny($summary, ['initialTotal', 'initial_total'], 0)),
            'payment_total' => $paymentTotal,
            'payment_total_available' => $paymentTotalAvailable,
            'payment_fees' => $paymentFees,
            'payment_fees_available' => $paymentFeesAvailable,
            'lines' => $lines,
        ];
    }


    private function normalizeStaff(array $r): array
    {
        return [
            'id' => (string)self::getAny($r, ['id'], ''),
            'name' => (string)self::getAny($r, ['displayName', 'name', 'fullName'], ''),
            'role' => (string)self::getAny($r, ['role.name', 'role'], ''),
            'locations' => self::getAny($r, ['locations'], []),
            'bookable' => self::nullableBool(self::getAny($r, ['externallyBookable', 'bookable'], null)),
            'status' => (bool)self::getAny($r, ['active'], true) ? 'Active' : 'Inactive',
        ];
    }

    private function normalizeService(array $r): array
    {
        return [
            'id' => (string)self::getAny($r, ['id'], ''),
            'service' => (string)self::getAny($r, ['name', 'service'], ''),
            'category' => (string)self::getAny($r, ['category.name', 'category'], ''),
            'status' => (bool)self::getAny($r, ['active'], true) ? 'Active' : 'Inactive',
        ];
    }

    private function normalizeShift(array $r): array
    {
        $availableRaw = self::getAny($r, ['available'], null);
        $available = $availableRaw === null ? true : (bool)$availableRaw;

        $staffId = (string)self::getAny($r, ['staffId', 'staff_id', 'staff.id'], '');
        $provider = (string)self::getAny($r, ['staff.displayName', 'staff.name', 'provider', 'staffName'], '');
        if ($provider === '' && $staffId !== '') {
            $provider = $this->staffNameById[$staffId] ?? $staffId;
        }

        $minutes = (float)self::getAny($r, ['duration', 'duration_minutes', 'minutes'], 0);
        $date = (string)self::getAny($r, ['date'], '');
        $startTime = (string)self::getAny($r, ['startTime', 'start_time', 'clockIn'], '');
        $endTime = (string)self::getAny($r, ['endTime', 'end_time', 'clockOut'], '');
        $start = (string)self::getAny($r, ['startAt', 'start', 'start_at'], '');
        $end = (string)self::getAny($r, ['endAt', 'end', 'end_at'], '');

        if ($minutes <= 0 && $date !== '' && $startTime !== '' && $endTime !== '') {
            $s = strtotime($date . ' ' . $startTime);
            $e = strtotime($date . ' ' . $endTime);
            if ($s !== false && $e !== false) {
                if ($e < $s) {
                    $e += 86400;
                }
                $minutes = max(0, ($e - $s) / 60);
            }
        }

        if ($minutes <= 0 && $startTime !== '' && $endTime !== '') {
            $s = strtotime('2000-01-01 ' . $startTime);
            $e = strtotime('2000-01-01 ' . $endTime);
            if ($s !== false && $e !== false) {
                if ($e < $s) {
                    $e += 86400;
                }
                $minutes = max(0, ($e - $s) / 60);
            }
        }

        if ($minutes <= 0 && $start && $end) {
            $s = strtotime($start);
            $e = strtotime($end);
            if ($s !== false && $e !== false && $e > $s) {
                $minutes = ($e - $s) / 60;
            }
        }

        return [
            'provider' => $provider,
            'minutes' => $minutes,
            'available' => $available,
            'date' => $date,
        ];
    }


    private function normalizeMembership(array $r): array
    {
        $status = strtoupper((string)self::getAny($r, ['status'], ''));
        $unit = $this->money(self::getAny($r, ['unitPrice', 'unit_price', 'price'], 0));
        $intervalRaw = (string)self::getAny($r, ['interval.unit', 'interval', 'billingInterval', 'billing_interval'], 'P1M');
        $interval = strtoupper(trim($intervalRaw));

        $monthly = $unit;
        if (preg_match('/^P(?:(\\d+)Y)?(?:(\\d+)M)?(?:(\\d+)W)?(?:(\\d+)D)?$/', $interval, $m)) {
            $years = (int)($m[1] ?? 0);
            $months = (int)($m[2] ?? 0);
            $weeks = (int)($m[3] ?? 0);
            $days = (int)($m[4] ?? 0);

            if ($years > 0) {
                $monthly = $unit / max(1, $years * 12);
            } elseif ($months > 0) {
                $monthly = $unit / max(1, $months);
            } elseif ($weeks > 0) {
                $monthly = $unit * (52 / 12) / $weeks;
            } elseif ($days > 0) {
                $monthly = $unit * (365 / 12) / $days;
            }
        } else {
            $lower = strtolower($intervalRaw);
            $count = max(1.0, (float)self::getAny($r, ['interval.count', 'intervalCount', 'interval_count'], 1));
            $monthly = match (true) {
                str_contains($lower, 'year') => $unit / (12 * $count),
                str_contains($lower, 'week') => $unit * (52 / 12) / $count,
                str_contains($lower, 'day') => $unit * (365 / 12) / $count,
                default => $unit / $count,
            };
        }

        return [
            'id' => (string)self::getAny($r, ['id'], ''),
            'name' => (string)self::getAny($r, ['name'], ''),
            'product_id' => (string)self::getAny($r, ['productId', 'product.id'], ''),
            'status' => $status,
            'active' => in_array($status, ['ACTIVE', 'PAST_DUE'], true),
            'monthly_value' => $monthly,
            'start_on' => self::nullableString(self::getAny($r, ['startOn', 'start_on'], null)),
            'end_on' => self::nullableString(self::getAny($r, ['endOn', 'end_on'], null)),
            'cancel_on' => self::nullableString(self::getAny($r, ['cancelOn', 'cancel_on'], null)),
            'next_charge_date' => self::nullableString(self::getAny($r, ['nextChargeDate', 'next_charge_date'], null)),
        ];
    }


    /**
     * Determine whether a membership was active at a point in time using its
     * lifecycle dates. Current status is used only when historical dates do not
     * provide enough information.
     */
    private function membershipActiveAt(array $membership, ?string $asOfDate): bool
    {
        if (!$asOfDate) {
            return (bool)($membership['active'] ?? false);
        }

        $asOf = strtotime($asOfDate . ' 23:59:59');
        if ($asOf === false) {
            return (bool)($membership['active'] ?? false);
        }

        $start = !empty($membership['start_on'])
            ? strtotime((string)$membership['start_on'] . ' 00:00:00')
            : false;
        $cancel = !empty($membership['cancel_on'])
            ? strtotime((string)$membership['cancel_on'] . ' 00:00:00')
            : false;

        if ($start !== false && $start > $asOf) {
            return false;
        }

        /*
         * Do not use endOn as a historical cancellation boundary. Boulevard
         * can expose an endOn for the current recurring term while the
         * membership itself remains active. cancelOn is the cancellation
         * boundary we can safely apply to a previous-period comparison.
         */
        if ($cancel !== false && $cancel <= $asOf) {
            return false;
        }

        if ($start !== false || $cancel !== false) {
            return true;
        }

        return (bool)($membership['active'] ?? false);
    }


    private function metric(mixed $value, string $format, bool $available, string $source, mixed $previous = null): array
    {
        return [
            'value' => $available ? $value : null,
            'format' => $format,
            'available' => $available,
            'source' => $source,
            'previous' => $previous,
            'change' => ($available && $previous !== null && is_numeric($value) && is_numeric($previous))
                ? $this->pctChange((float)$value, (float)$previous)
                : null,
        ];
    }

    private function money(mixed $value): float
    {
        if (is_array($value)) {
            $value = self::getAny($value, ['amount', 'value', 'cents'], 0);
        }
        return is_numeric($value) ? ((float)$value / $this->moneyDivisor) : 0.0;
    }

    private function pctChange(float $current, float $previous): ?float
    {
        if (abs($previous) < 0.000001) {
            return abs($current) < 0.000001 ? 0.0 : null;
        }
        return ($current - $previous) / abs($previous);
    }

    private static function rows(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        if (isset($value['edges']) && is_array($value['edges'])) {
            $out = [];
            foreach ($value['edges'] as $edge) {
                if (is_array($edge) && isset($edge['node']) && is_array($edge['node'])) {
                    $out[] = $edge['node'];
                }
            }
            return $out;
        }
        if (isset($value['nodes']) && is_array($value['nodes'])) {
            return array_values(array_filter($value['nodes'], 'is_array'));
        }
        if (array_is_list($value)) {
            return array_values(array_filter($value, 'is_array'));
        }
        return [$value];
    }

    private static function getAny(array $row, array $paths, mixed $default = null): mixed
    {
        foreach ($paths as $path) {
            $value = $row;
            $found = true;
            foreach (explode('.', $path) as $part) {
                if (is_array($value) && array_key_exists($part, $value)) {
                    $value = $value[$part];
                } else {
                    $found = false;
                    break;
                }
            }
            if ($found && $value !== null) {
                return $value;
            }
        }
        return $default;
    }

    private static function key(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value;
        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }

    private static function nullableBool(mixed $value): ?bool
    {
        return $value === null ? null : (bool)$value;
    }

    private static function nullableInt(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int)$value;
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string)$value;
    }
}
