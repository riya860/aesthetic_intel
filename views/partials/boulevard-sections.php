<?php
$sectionNo = $sectionNo ?? 1;
$k = $dashboard['kpis'] ?? [];
$uploadedReportCodes = $uploadedReportCodes ?? [];
$hasMetric = static fn(string $key): bool => boulevard_metric_available($key, $uploadedReportCodes, $k[$key] ?? null);
$showComparison = static fn(array $metric): bool => metric_has_previous($metric);

$comparisonKeys = array_values(array_filter(
    ['total_revenue','appointments','new_clients','utilization','retail_revenue','active_mrr'],
    static fn(string $key): bool => $hasMetric($key)
));
$chartKpiKeys = array_values(array_filter(
    ['total_revenue','service_revenue','retail_revenue','membership_revenue'],
    static fn(string $key): bool => $hasMetric($key)
));
?>

<?php if ($comparisonKeys): ?>
<section class="report-section infographic-section">
  <div class="section-title"><b><?= $sectionNo++ ?></b><h2>Performance Comparison</h2></div>
  <div class="report-kpis">
    <?php foreach ($comparisonKeys as $key): $m = $k[$key]; ?>
      <article class="report-kpi">
        <small><?= e($m['label']) ?></small>
        <strong><?= metric_display($m) ?></strong>
        <?php if ($showComparison($m)): ?>
          <span class="trend trend-<?= e($m['sentiment'] ?? 'neutral') ?>"><?= e(change_text($m)) ?></span>
        <?php endif; ?>
      </article>
    <?php endforeach; ?>
  </div>
  <?php if ($chartKpiKeys): ?><div class="chart-frame tall"><canvas id="comparisonChart"></canvas></div><?php endif; ?>
</section>
<?php endif; ?>

<?php
$hasProviderRevenue = boulevard_has_reports($uploadedReportCodes, 'service_commission') && !empty($dashboard['providers']);
$hasProviderUtilization = boulevard_has_reports($uploadedReportCodes, 'appointment_metrics') && !empty($dashboard['providers']);
?>
<?php if ($hasProviderRevenue || $hasProviderUtilization): ?>
<section class="report-section infographic-section">
  <div class="section-title"><b><?= $sectionNo++ ?></b><h2>Provider Performance: Service Revenue &amp; Utilization</h2></div>
  <div class="chart-split">
    <?php if ($hasProviderRevenue): ?><div><h3>Service Revenue</h3><div class="chart-frame"><canvas id="providerRevenueChart"></canvas></div></div><?php endif; ?>
    <?php if ($hasProviderUtilization): ?><div><h3>Utilization Rate</h3><div class="chart-frame"><canvas id="providerUtilizationChart"></canvas></div></div><?php endif; ?>
  </div>
  <?php if ($hasProviderRevenue && !empty($dashboard['providers'][0])): ?>
    <div class="insight-banner">★ <strong><?= e($dashboard['providers'][0]['name']) ?></strong> led service revenue at <?= money($dashboard['providers'][0]['service_revenue']) ?><?php if ($hasMetric('utilization')): ?>. Overall utilization was <?= pct($k['utilization']['value']) ?><?php endif; ?>.</div>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php
$hasRevenuePerHour = boulevard_has_reports($uploadedReportCodes, ['appointment_metrics','service_commission']) && !empty($dashboard['providers']);
$hasRetail = boulevard_has_reports($uploadedReportCodes, 'retail_product_sales');
?>
<?php if ($hasRevenuePerHour || $hasRetail): ?>
<section class="report-section infographic-section">
  <div class="section-title"><b><?= $sectionNo++ ?></b><h2>Revenue per Scheduled Hour &amp; Retail Sales</h2></div>
  <div class="chart-split retail-layout">
    <?php if ($hasRevenuePerHour): ?><div class="chart-frame"><canvas id="revenuePerHourChart"></canvas></div><?php endif; ?>
    <?php if ($hasRetail): ?>
      <div class="retail-summary">
        <div class="retail-big"><span>Retail Revenue</span><strong><?= metric_display($k['retail_revenue']) ?></strong><span><?= metric_display($k['retail_units']) ?> units sold</span></div>
        <?php if (!empty($dashboard['top_retail'])): ?><h3>Top Retail Products</h3><ol><?php foreach (array_slice($dashboard['top_retail'],0,5) as $item): ?><li><span><?= e($item['name']) ?></span><b><?= money($item['value']) ?></b></li><?php endforeach; ?></ol><?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>

<?php if (boulevard_has_reports($uploadedReportCodes, 'subscriptions')): ?>
<section class="report-section infographic-section">
  <div class="section-title"><b><?= $sectionNo++ ?></b><h2>Membership &amp; Recurring Revenue</h2></div>
  <div class="membership-layout">
    <div class="chart-frame"><canvas id="mrrChart"></canvas></div>
    <div class="mrr-cards">
      <?php if ($hasMetric('active_mrr')): ?><article><small>Current Active MRR</small><strong><?= metric_display($k['active_mrr']) ?></strong></article><?php endif; ?>
      <?php if ($hasMetric('active_mrr') && $showComparison($k['active_mrr'])): ?><article><small>Change Since Previous Period</small><strong class="trend-<?= e($k['active_mrr']['sentiment'] ?? 'neutral') ?>"><?= e(change_text($k['active_mrr'])) ?></strong></article><?php endif; ?>
      <?php if ($hasMetric('active_arr')): ?><article><small>Current ARR</small><strong><?= metric_display($k['active_arr']) ?></strong></article><?php endif; ?>
      <?php if ($hasMetric('active_memberships')): ?><article><small>Active Memberships</small><strong><?= metric_display($k['active_memberships']) ?></strong></article><?php endif; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php
$weeklySalesKeys = array_values(array_filter(
    ['total_revenue','appointments','requested_appointments','service_revenue','product_revenue','membership_revenue','package_revenue','tips'],
    static fn(string $key): bool => $hasMetric($key)
));
?>
<?php if ($weeklySalesKeys): ?>
<section class="report-section infographic-section">
  <div class="section-title"><b><?= $sectionNo++ ?></b><h2>Boulevard Sales Summary</h2></div>
  <div class="report-kpis financial-kpis">
    <?php foreach ($weeklySalesKeys as $key): $m = $k[$key]; ?>
      <article class="report-kpi"><small><?= e($m['label']) ?></small><strong><?= metric_display($m) ?></strong><?php if ($showComparison($m)): ?><span class="trend trend-<?= e($m['sentiment'] ?? 'neutral') ?>"><?= e(change_text($m)) ?></span><?php endif; ?></article>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<?php
$financialKeys = array_values(array_filter(
    ['net_sales','gross_payments','refunds','gift_cards_sold','account_credit_sold','tax_collected','card_fees','retail_share'],
    static fn(string $key): bool => $hasMetric($key)
));
$hasOperations = boulevard_has_reports($uploadedReportCodes, 'staff_schedule') && (($dashboard['operations']['peak_hour_count'] ?? 0) > 0 || ($dashboard['operations']['peak_day_count'] ?? 0) > 0);
?>
<?php if ($financialKeys || $hasOperations): ?>
<section class="report-section infographic-section">
  <div class="section-title"><b><?= $sectionNo++ ?></b><h2>Financial Detail &amp; Sales Quality</h2></div>
  <?php if ($financialKeys): ?><div class="report-kpis financial-kpis"><?php foreach ($financialKeys as $key): $m = $k[$key]; ?><article class="report-kpi"><small><?= e($m['label']) ?></small><strong><?= metric_display($m) ?></strong><?php if ($showComparison($m)): ?><span class="trend trend-<?= e($m['sentiment'] ?? 'neutral') ?>"><?= e(change_text($m)) ?></span><?php endif; ?></article><?php endforeach; ?></div><?php endif; ?>
  <?php if ($hasOperations): $ops = $dashboard['operations']; ?><div class="insight-banner">◷ Peak scheduled client time: <strong><?= e($ops['peak_hour']) ?></strong> (<?= numfmt($ops['peak_hour_count']) ?> clients). Busiest day: <strong><?= e($ops['peak_day']) ?></strong> (<?= numfmt($ops['peak_day_count']) ?> clients).</div><?php endif; ?>
</section>
<?php endif; ?>

<?php if (boulevard_has_reports($uploadedReportCodes, 'daily_summary')): ?>
<section class="report-section infographic-section">
  <div class="section-title"><b><?= $sectionNo++ ?></b><h2>Revenue Mix &amp; Daily Performance</h2></div>
  <div class="chart-split"><div class="chart-frame"><canvas id="revenueMixChart"></canvas></div><div class="chart-frame"><canvas id="dailyRevenueChart"></canvas></div></div>
</section>
<?php endif; ?>

<?php
$showServiceRevenueColumn = boulevard_has_reports($uploadedReportCodes, 'service_commission');
$showAppointmentColumns = boulevard_has_reports($uploadedReportCodes, 'appointment_metrics');
$showRevenueHourColumn = $showServiceRevenueColumn && $showAppointmentColumns;
?>
<?php if (!empty($dashboard['providers']) && ($showServiceRevenueColumn || $showAppointmentColumns)): ?>
<section class="report-section infographic-section">
  <div class="section-title"><b><?= $sectionNo++ ?></b><h2>Provider Detail</h2></div>
  <div class="table-wrap"><table class="compact" data-sortable-table><thead><tr><th>Provider</th><?php if ($showServiceRevenueColumn): ?><th>Service Revenue</th><?php endif; ?><?php if ($showAppointmentColumns): ?><th>Utilization</th><th>Scheduled Hours</th><th>Appointments</th><th>New Clients</th><?php endif; ?><?php if ($showRevenueHourColumn): ?><th>Revenue / Hour</th><?php endif; ?></tr></thead><tbody>
  <?php foreach ($dashboard['providers'] as $p): ?><tr><td><strong><?= e($p['name']) ?></strong></td><?php if ($showServiceRevenueColumn): ?><td data-sort-value="<?= e($p['service_revenue']) ?>"><?= money($p['service_revenue']) ?></td><?php endif; ?><?php if ($showAppointmentColumns): ?><td data-sort-value="<?= e($p['utilization']) ?>"><?= pct($p['utilization']) ?></td><td data-sort-value="<?= e($p['hours_scheduled']) ?>"><?= numfmt($p['hours_scheduled'],1) ?></td><td data-sort-value="<?= e($p['appointments']) ?>"><?= numfmt($p['appointments']) ?></td><td data-sort-value="<?= e($p['new_clients']) ?>"><?= numfmt($p['new_clients']) ?></td><?php endif; ?><?php if ($showRevenueHourColumn): ?><td data-sort-value="<?= e($p['revenue_per_hour']) ?>"><?= money($p['revenue_per_hour']) ?></td><?php endif; ?></tr><?php endforeach; ?>
  </tbody></table></div>
</section>
<?php endif; ?>

<?php if (!empty($insights)): ?>
<section class="report-section infographic-section">
  <div class="section-title"><b><?= $sectionNo++ ?></b><h2>Business Insights</h2></div>
  <div class="insight-grid"><?php foreach ($insights as $i): ?><article class="insight-card"><div><span class="priority priority-<?= e($i['priority']) ?>"><?= e(strtoupper($i['priority'])) ?> PRIORITY</span><span class="category-chip"><?= e($i['category']) ?></span></div><h3><?= e($i['title']) ?></h3><small>OBSERVATION</small><p><?= e($i['observation']) ?></p></article><?php endforeach; ?></div>
</section>
<?php endif; ?>
