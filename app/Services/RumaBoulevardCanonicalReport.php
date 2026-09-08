<?php

declare(strict_types=1);

require_once ROOT_PATH . '/app/parsers.php';

/**
 * Builds one canonical, report-aligned RUMA Boulevard dashboard directly from
 * the raw CSV files stored for an upload_batches row.
 *
 * Why this exists:
 * - the live GraphQL console is an operational/object view;
 * - Boulevard CSV reports are semantic/reporting views;
 * - comparing reconstructed GraphQL KPIs directly with report KPIs creates
 *   false mismatches when Boulevard uses different reporting definitions.
 *
 * Both API Report Export batches and manual upload batches are parsed through
 * this SAME class, so their definitions are identical before comparison.
 */
final class RumaBoulevardCanonicalReport
{
    public static function loadBatch(int $businessId, int $batchId): array
    {
        if ($businessId < 1 || $batchId < 1) {
            throw new InvalidArgumentException('A valid business and batch are required.');
        }

        $stmt = db()->prepare(
            "SELECT id,business_id,period_start,period_end,frequency,status,
                    completeness_score,warning_count,created_at,completed_at
             FROM upload_batches
             WHERE id=? AND business_id=? AND status='completed'
             LIMIT 1"
        );
        $stmt->execute([$batchId, $businessId]);
        $batch = $stmt->fetch();

        if (!$batch) {
            throw new RuntimeException('Boulevard batch was not found or is not completed.');
        }

        $fileStmt = db()->prepare(
            "SELECT uf.id,uf.relative_path,uf.original_name,uf.status,
                    rt.code,rt.name,rt.expected_headers_json
             FROM uploaded_files uf
             JOIN report_types rt ON rt.id=uf.report_type_id
             WHERE uf.batch_id=? AND uf.status IN ('validated','uploaded')
             ORDER BY rt.sort_order,rt.id"
        );
        $fileStmt->execute([$batchId]);
        $files = $fileStmt->fetchAll() ?: [];

        if (!$files) {
            throw new RuntimeException('This Boulevard batch has no stored report files.');
        }

        $reports = [];
        $warnings = [];
        $loadedFiles = [];

        foreach ($files as $file) {
            $code = trim((string)$file['code']);
            if ($code === '') {
                continue;
            }

            $path = ROOT_PATH . '/' . ltrim((string)$file['relative_path'], '/');
            if (!is_file($path)) {
                $warnings[] = $file['name'] . ': stored CSV is missing on this server.';
                continue;
            }

            try {
                $expected = json_decode((string)($file['expected_headers_json'] ?? ''), true);
                $parsed = parser_for($code)->parse($path, [
                    'period_start' => (string)$batch['period_start'],
                    'period_end' => (string)$batch['period_end'],
                    'expected_headers' => is_array($expected) ? $expected : [],
                ]);

                $reports[$code] = is_array($parsed['data'] ?? null)
                    ? $parsed['data']
                    : [];

                foreach ((array)($parsed['warnings'] ?? []) as $warning) {
                    $warnings[] = $file['name'] . ': ' . (string)$warning;
                }

                $loadedFiles[$code] = [
                    'name' => (string)$file['name'],
                    'original_name' => (string)$file['original_name'],
                    'path' => (string)$file['relative_path'],
                    'row_count' => (int)($parsed['row_count'] ?? 0),
                ];
            } catch (Throwable $e) {
                $warnings[] = $file['name'] . ': ' . $e->getMessage();
            }
        }

        $dashboard = self::build(
            $reports,
            (string)$batch['period_start'],
            (string)$batch['period_end']
        );

        $dashboard['meta'] += [
            'batch_id' => (int)$batch['id'],
            'business_id' => (int)$batch['business_id'],
            'frequency' => (string)$batch['frequency'],
            'completeness_score' => (float)$batch['completeness_score'],
            'stored_warning_count' => (int)$batch['warning_count'],
            'batch_created_at' => (string)$batch['created_at'],
            'batch_completed_at' => (string)($batch['completed_at'] ?? ''),
            'loaded_files' => $loadedFiles,
            'parse_warnings' => $warnings,
        ];

        return $dashboard;
    }

    public static function build(array $reports, string $periodStart, string $periodEnd): array
    {
        $daily = (array)($reports['daily_summary']['days'] ?? []);
        $sales = (array)($reports['sales_summary']['categories'] ?? []);
        $salesTotals = (array)($reports['sales_summary']['totals']['categories'] ?? []);
        $paymentProcessing = (array)($reports['sales_summary']['payment_processing'] ?? []);
        $appointmentProviders = (array)($reports['appointment_metrics']['providers'] ?? []);
        $serviceProviders = (array)($reports['service_commission']['providers'] ?? []);
        $productProviders = (array)($reports['product_commission']['providers'] ?? []);
        $membershipProviders = (array)($reports['membership_commission']['providers'] ?? []);
        $membershipPlans = (array)($reports['membership_sales']['plans'] ?? []);
        $retailProducts = (array)($reports['retail_product_sales']['products'] ?? []);
        $allProducts = (array)($reports['product_sales']['products'] ?? []);
        $subscriptions = (array)($reports['subscriptions']['subscriptions'] ?? []);
        $activeSubscriptions = (array)($reports['subscriptions']['active'] ?? []);
        $activeMrr = self::num($reports['subscriptions']['active_mrr'] ?? null);

        $sumDaily = static function (array $rows, string $key): float {
            $sum = 0.0;
            foreach ($rows as $row) {
                if (is_array($row)) {
                    $sum += (float)($row[$key] ?? 0);
                }
            }
            return $sum;
        };

        // Canonical totals: preserve the semantics of the exact Boulevard report.
        $dailyTotalRevenue = $sumDaily($daily, 'total_revenue');
        $appointments = $sumDaily($daily, 'appointments');
        $requestedAppointments = $sumDaily($daily, 'requested_appointments');
        $serviceRevenue = $sumDaily($daily, 'service_revenue');
        $productRevenue = $sumDaily($daily, 'product_revenue');
        $membershipRevenue = $sumDaily($daily, 'membership_revenue');
        $packageRevenue = $sumDaily($daily, 'package_revenue');
        $tips = $sumDaily($daily, 'tip_revenue');

        $newClients = 0.0;
        $scheduledHours = 0.0;
        $weightedUtilizationNumerator = 0.0;

        foreach ($appointmentProviders as $provider) {
            if (!is_array($provider)) {
                continue;
            }
            $hours = (float)($provider['hours_scheduled'] ?? 0);
            $utilPercent = (float)($provider['utilization'] ?? 0);
            $newClients += (float)($provider['new_clients'] ?? 0);
            $scheduledHours += $hours;
            $weightedUtilizationNumerator += $utilPercent * $hours;
        }

        /*
         * IMPORTANT: use the utilization Boulevard reported for each provider,
         * then weight it by Hours Scheduled. Do NOT recompute it as
         * SUM(Hours Booked)/SUM(Hours Scheduled): the attached RUMA report proves
         * those can differ for several providers because Boulevard's utilization
         * model contains additional scheduling semantics.
         */
        $blendedUtilization = $scheduledHours > 0
            ? $weightedUtilizationNumerator / $scheduledHours
            : null;

        $netSales = self::nullableNum($salesTotals['total'] ?? null);
        $grossPayments = self::nullableNum($salesTotals['payments'] ?? null);
        $refunds = self::nullableNum($salesTotals['refunds'] ?? null);
        if ($refunds !== null) {
            $refunds = abs($refunds);
        }

        $accountCreditSold = self::namedSalesValue($sales, ['Account Credit']);
        $taxCollected = self::namedSalesValue($sales, ['Tax']);
        $voucherServiceRevenue = self::namedSalesValue(
            $sales,
            ['Services Redeemed with Vouchers']
        );
        $cardFees = self::namedSalesValue($paymentProcessing, ['Card Fees']);
        if ($cardFees !== null) {
            $cardFees = abs($cardFees);
        }

        /*
         * Do NOT map Payment Method "Gift card" to Gift Cards Sold.
         * That row is a payment/redemption method, not necessarily gift-card sales.
         * Gift-card sales stay unavailable unless a true sales source is present.
         */
        $giftCardsSold = self::namedSalesValue(
            $sales,
            ['Gift Cards Sold', 'Gift Card Sales', 'Gift Cards']
        );

        $activeMemberships = count($activeSubscriptions);

        $retailRevenue = 0.0;
        $retailUnits = 0.0;
        foreach ($retailProducts as $product) {
            if (!is_array($product)) continue;
            $retailRevenue += (float)($product['subtotal'] ?? 0);
            $retailUnits += (float)($product['quantity'] ?? 0);
        }

        $membershipsSold = 0.0;
        foreach ($membershipPlans as $plan) {
            if (is_array($plan)) {
                $membershipsSold += (float)($plan['quantity'] ?? 0);
            }
        }

        $providers = self::buildProviders(
            $appointmentProviders,
            $serviceProviders,
            $productProviders,
            $membershipProviders
        );

        $dailySeries = [];
        foreach ($daily as $row) {
            if (!is_array($row) || empty($row['date'])) continue;
            $dailySeries[] = [
                'date' => (string)$row['date'],
                'appointments' => (float)($row['appointments'] ?? 0),
                'requested_appointments' => (float)($row['requested_appointments'] ?? 0),
                'service_revenue' => (float)($row['service_revenue'] ?? 0),
                'product_revenue' => (float)($row['product_revenue'] ?? 0),
                'membership_revenue' => (float)($row['membership_revenue'] ?? 0),
                'package_revenue' => (float)($row['package_revenue'] ?? 0),
                'tips' => (float)($row['tip_revenue'] ?? 0),
                'revenue' => (float)($row['total_revenue'] ?? 0),
            ];
        }

        $revenueMix = [
            ['key' => 'services', 'label' => 'Services', 'value' => $serviceRevenue],
            ['key' => 'products', 'label' => 'Products', 'value' => $productRevenue],
            ['key' => 'memberships', 'label' => 'Memberships', 'value' => $membershipRevenue],
            ['key' => 'packages', 'label' => 'Packages', 'value' => $packageRevenue],
            ['key' => 'tips', 'label' => 'Tips', 'value' => $tips],
        ];

        $kpis = [
            'total_revenue' => self::metric(
                $daily ? $dailyTotalRevenue : null,
                'currency',
                'Daily Summary → Total Revenue',
                'Boulevard Daily Summary reporting definition.'
            ),
            'appointments' => self::metric(
                $daily ? $appointments : null,
                'number',
                'Daily Summary → Appointments',
                'Distinct report-level appointment count for the period.'
            ),
            'requested_appointments' => self::metric(
                $daily ? $requestedAppointments : null,
                'number',
                'Daily Summary → Requested Appointments',
                'Report-level requested appointment count; avoids double-counting provider/service rows.'
            ),
            'new_clients' => self::metric(
                $appointmentProviders ? $newClients : null,
                'number',
                'Appointment Metrics → New Clients',
                'Sum of provider-level New Clients exactly as reported by Boulevard.'
            ),
            'utilization' => self::metric(
                $appointmentProviders ? $blendedUtilization : null,
                'percent',
                'Appointment Metrics → Utilization',
                'Hours-Scheduled-weighted average of Boulevard-reported provider utilization.'
            ),
            'active_mrr' => self::metric(
                $activeMrr,
                'currency',
                'Subscriptions → All → Subscription MRR',
                'Boulevard active subscription MRR snapshot.'
            ),
            'active_arr' => self::metric(
                $activeMrr !== null ? $activeMrr * 12 : null,
                'currency',
                'Subscriptions → Active MRR × 12',
                'Annualized current active MRR.'
            ),
            'active_memberships' => self::metric(
                isset($reports['subscriptions']) ? $activeMemberships : null,
                'number',
                'Subscriptions → Active rows',
                'Count of individual Active subscriptions; excludes the synthetic All summary row.'
            ),
            'service_revenue' => self::metric(
                $daily ? $serviceRevenue : null,
                'currency',
                'Daily Summary → Service Revenue',
                'Includes Boulevard service-report semantics such as voucher-redemption treatment.'
            ),
            'product_revenue' => self::metric(
                $daily ? $productRevenue : null,
                'currency',
                'Daily Summary → Product Revenue',
                'All product revenue from Daily Summary.'
            ),
            'membership_revenue' => self::metric(
                $daily ? $membershipRevenue : null,
                'currency',
                'Daily Summary → Membership Revenue',
                'Membership revenue for the selected report period.'
            ),
            'package_revenue' => self::metric(
                $daily ? $packageRevenue : null,
                'currency',
                'Daily Summary → Package Revenue',
                'Package revenue for the selected report period.'
            ),
            'tips' => self::metric(
                $daily ? $tips : null,
                'currency',
                'Daily Summary → Tip Revenue',
                'Tip revenue for the selected report period.'
            ),
            'net_sales' => self::metric(
                $netSales,
                'currency',
                'Sales Summary → Sales Category → Total',
                'Net total from the Sales Category section after report-level refunds.'
            ),
            'gross_payments' => self::metric(
                $grossPayments,
                'currency',
                'Sales Summary → Sales Category → Payments',
                'Gross Sales Category payments before report-level refunds.'
            ),
            'refunds' => self::metric(
                $refunds,
                'currency',
                'Sales Summary → Sales Category → Refunds',
                'Absolute value of report-level refunds.'
            ),
            'account_credit_sold' => self::metric(
                $accountCreditSold,
                'currency',
                'Sales Summary → Sales Category → Account Credit',
                'Account credit sales, not Account Credit used as a payment method.'
            ),
            'gift_cards_sold' => self::metric(
                $giftCardsSold,
                'currency',
                'Sales Summary → Gift Card Sales category',
                'Unavailable when only Gift card payment/redemption rows exist.'
            ),
            'tax_collected' => self::metric(
                $taxCollected,
                'currency',
                'Sales Summary → Sales Category → Tax',
                'Net report-level tax.'
            ),
            'card_fees' => self::metric(
                $cardFees,
                'currency',
                'Sales Summary → Payment Processing → Card Fees',
                'Payment-processing card fees from the Boulevard report.'
            ),
            'voucher_service_revenue' => self::metric(
                $voucherServiceRevenue,
                'currency',
                'Sales Summary → Services Redeemed with Vouchers',
                'Service value redeemed through vouchers.'
            ),
            'retail_revenue' => self::metric(
                isset($reports['retail_product_sales']) ? $retailRevenue : null,
                'currency',
                'Retail Product Sales → Subtotal',
                'Retail-only product revenue when the optional retail report is present.'
            ),
            'retail_units' => self::metric(
                isset($reports['retail_product_sales']) ? $retailUnits : null,
                'number',
                'Retail Product Sales → Quantity',
                'Retail-only units when the optional retail report is present.'
            ),
            'memberships_sold' => self::metric(
                isset($reports['membership_sales']) ? $membershipsSold : null,
                'number',
                'Membership Sales → Quantity',
                'Membership-plan units sold during the report period.'
            ),
        ];

        return [
            'meta' => [
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'canonical_version' => '2.0',
                'generated_at' => date('c'),
                'reports_present' => array_values(array_keys($reports)),
            ],
            'kpis' => $kpis,
            'providers' => $providers,
            'daily' => $dailySeries,
            'revenue_mix' => $revenueMix,
            'raw_report_summary' => [
                'sales_categories' => $sales,
                'sales_totals' => $salesTotals,
                'payment_processing' => $paymentProcessing,
                'membership_plans' => $membershipPlans,
                'product_count' => count($allProducts),
                'subscription_count' => count($subscriptions),
            ],
        ];
    }

    private static function buildProviders(
        array $appointmentProviders,
        array $serviceProviders,
        array $productProviders,
        array $membershipProviders
    ): array {
        $keys = array_values(array_unique(array_merge(
            array_keys($appointmentProviders),
            array_keys($serviceProviders),
            array_keys($productProviders),
            array_keys($membershipProviders)
        )));

        $rows = [];
        foreach ($keys as $name) {
            $a = is_array($appointmentProviders[$name] ?? null)
                ? $appointmentProviders[$name]
                : [];
            $s = is_array($serviceProviders[$name] ?? null)
                ? $serviceProviders[$name]
                : [];
            $p = is_array($productProviders[$name] ?? null)
                ? $productProviders[$name]
                : [];
            $m = is_array($membershipProviders[$name] ?? null)
                ? $membershipProviders[$name]
                : [];

            $scheduled = (float)($a['hours_scheduled'] ?? 0);
            $serviceRevenue = (float)($s['revenue'] ?? 0);

            $rows[] = [
                'name' => (string)$name,
                'service_revenue' => $serviceRevenue,
                'utilization' => isset($a['utilization']) ? (float)$a['utilization'] : null,
                'hours_scheduled' => isset($a['hours_scheduled']) ? $scheduled : null,
                'hours_booked' => isset($a['hours_booked']) ? (float)$a['hours_booked'] : null,
                'appointments' => isset($a['appointments']) ? (float)$a['appointments'] : null,
                'requested' => isset($a['staff_requested']) ? (float)$a['staff_requested'] : null,
                'new_clients' => isset($a['new_clients']) ? (float)$a['new_clients'] : null,
                'revenue_per_hour' => $scheduled > 0 ? $serviceRevenue / $scheduled : null,
                'product_revenue' => isset($p['revenue']) ? (float)$p['revenue'] : null,
                'membership_revenue' => isset($m['revenue']) ? (float)$m['revenue'] : null,
                'service_commission' => isset($s['commission']) ? (float)$s['commission'] : null,
                'product_commission' => isset($p['commission']) ? (float)$p['commission'] : null,
                'membership_commission' => isset($m['commission']) ? (float)$m['commission'] : null,
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            return ($b['service_revenue'] ?? 0) <=> ($a['service_revenue'] ?? 0);
        });

        return $rows;
    }

    private static function metric(
        ?float $value,
        string $format,
        string $source,
        string $definition
    ): array {
        return [
            'available' => $value !== null,
            'value' => $value,
            'format' => $format,
            'source' => $source,
            'definition' => $definition,
        ];
    }

    private static function namedSalesValue(array $section, array $names): ?float
    {
        foreach ($names as $wanted) {
            foreach ($section as $name => $values) {
                if (strcasecmp(trim((string)$name), trim($wanted)) === 0) {
                    return self::nullableNum(is_array($values) ? ($values['total'] ?? null) : null);
                }
            }
        }
        return null;
    }

    private static function nullableNum(mixed $value): ?float
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }
        return (float)$value;
    }

    private static function num(mixed $value): ?float
    {
        return self::nullableNum($value);
    }
}
