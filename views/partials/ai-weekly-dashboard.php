<?php

$dashboard = is_array($dashboard ?? null) ? $dashboard : [];
$printMode = !empty($printMode);
$report = is_array($report ?? null) ? $report : [];
$status = (string)($dashboard['overall_status'] ?? 'insufficient_data');

$statusLabels = [
    'positive' => 'Positive momentum',
    'mixed' => 'Mixed performance',
    'attention' => 'Needs attention',
    'insufficient_data' => 'Limited source data',
];

$statusLabel = $statusLabels[$status] ?? 'Weekly intelligence';
$metrics = is_array($dashboard['metrics'] ?? null) ? $dashboard['metrics'] : [];
$visualizations = is_array($dashboard['visualizations'] ?? null) ? $dashboard['visualizations'] : [];
$recommendations = is_array($dashboard['recommendations'] ?? null) ? $dashboard['recommendations'] : [];
$sourceSummary = function_exists('ai_weekly_report_source_summary')
    ? ai_weekly_report_source_summary($report)
    : [];
$availableSources = count(array_filter($sourceSummary, static fn(array $source): bool => !empty($source['available'])));
$totalSources = count($sourceSummary);

$chartPayload = [
    'charts' => $visualizations,
    'status' => $status,
];
?>

<div
    class="ai-weekly-dashboard ai-weekly-dashboard-v2"
    data-ai-weekly-dashboard
    data-report-business="<?=e($report['business_name'] ?? '')?>"
    data-report-start="<?=e($report['period_start'] ?? '')?>"
    data-report-end="<?=e($report['period_end'] ?? '')?>"
>

    <header class="aiw2-hero aiw2-reveal">
        <div class="aiw2-hero-copy">
            <p class="aiw2-kicker">AI Weekly Intelligence</p>

            <h2><?=e($dashboard['report_title'] ?? 'AI Weekly Report')?></h2>

            <p class="aiw2-summary">
                <?=e($dashboard['executive_summary'] ?? '')?>
            </p>

            <div class="aiw2-hero-meta">
                <span><?=e($report['business_name'] ?? '')?></span>
                <span><?=e($report['period_start'] ?? '')?> → <?=e($report['period_end'] ?? '')?></span>
                <?php if (!empty($report['current_version'])): ?>
                    <span>Version <?=e((string)$report['current_version'])?></span>
                <?php endif; ?>
            </div>
        </div>

        <div class="aiw2-hero-side">
            <span class="aiw2-status aiw2-status-<?=e($status)?>">
                <span class="aiw2-status-dot"></span>
                <?=e($statusLabel)?>
            </span>

            <?php if ($totalSources > 0): ?>
                <div class="aiw2-coverage">
                    <strong><?=$availableSources?>/<?=$totalSources?></strong>
                    <span>sources available</span>
                </div>
            <?php endif; ?>
        </div>

        <span class="aiw2-orb aiw2-orb-one" aria-hidden="true"></span>
        <span class="aiw2-orb aiw2-orb-two" aria-hidden="true"></span>
    </header>

    <?php if ($metrics): ?>
        <section class="aiw2-metric-grid" aria-label="Key weekly metrics">
            <?php foreach ($metrics as $index => $metric): ?>
                <?php
                $direction = (string)($metric['direction'] ?? 'unknown');
                $directionIcon = match ($direction) {
                    'up' => '↗',
                    'down' => '↘',
                    'flat' => '→',
                    default => '•',
                };
                ?>
                <article class="aiw2-metric aiw2-reveal" style="--aiw-delay: <?=number_format($index * 0.05, 2)?>s">
                    <div class="aiw2-metric-top">
                        <span><?=e($metric['label'] ?? 'Metric')?></span>
                        <span class="aiw2-direction aiw2-direction-<?=e($direction)?>"><?=$directionIcon?></span>
                    </div>

                    <strong class="aiw2-metric-value"><?=e($metric['value'] ?? '—')?></strong>

                    <?php if (!empty($metric['context'])): ?>
                        <p><?=e($metric['context'])?></p>
                    <?php endif; ?>

                    <?php if (!empty($metric['source_evidence'])): ?>
                        <small><?=e($metric['source_evidence'])?></small>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>

    <?php if ($visualizations): ?>
        <section class="aiw2-chart-section">
            <div class="aiw2-section-head aiw2-reveal">
                <div>
                    <span>Visual analysis</span>
                    <h3>Performance charts</h3>
                </div>
                <p>Only source-supported values are plotted.</p>
            </div>

            <div class="aiw2-chart-grid">
                <?php foreach ($visualizations as $index => $chart): ?>
                    <article class="aiw2-chart-card aiw2-reveal <?=$index === 0 ? 'aiw2-chart-card-featured' : ''?>" style="--aiw-delay: <?=number_format($index * 0.07, 2)?>s">
                        <div class="aiw2-chart-head">
                            <div>
                                <span><?=e(ucfirst((string)($chart['type'] ?? 'chart')))?></span>
                                <h4><?=e($chart['title'] ?? 'Performance chart')?></h4>
                                <?php if (!empty($chart['subtitle'])): ?>
                                    <p><?=e($chart['subtitle'])?></p>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="aiw2-chart-canvas-wrap <?=$printMode ? 'aiw2-chart-print-wrap' : ''?>">
                            <?php if ($printMode): ?>
                                <?php
                                $printChart = $chart;
                                $printChartIndex = $index;
                                require VIEW_PATH . '/partials/ai-weekly-print-chart.php';
                                ?>
                            <?php else: ?>
                                <canvas data-ai-weekly-chart-index="<?=$index?>" aria-label="<?=e($chart['title'] ?? 'Weekly performance chart')?>"></canvas>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($chart['source_evidence'])): ?>
                            <small class="aiw2-chart-source">Source: <?=e($chart['source_evidence'])?></small>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <section class="aiw2-intelligence-grid">
        <?php
        $sections = [
            'wins' => ['label' => 'Wins', 'icon' => '↗', 'tone' => 'positive'],
            'risks' => ['label' => 'Risks', 'icon' => '!', 'tone' => 'risk'],
            'opportunities' => ['label' => 'Opportunities', 'icon' => '✦', 'tone' => 'opportunity'],
        ];
        ?>

        <?php foreach ($sections as $key => $meta): ?>
            <?php $items = is_array($dashboard[$key] ?? null) ? $dashboard[$key] : []; ?>
            <article class="aiw2-intelligence-card aiw2-intelligence-<?=e($meta['tone'])?> aiw2-reveal">
                <header>
                    <span class="aiw2-intelligence-icon"><?=e($meta['icon'])?></span>
                    <div>
                        <span>Weekly intelligence</span>
                        <h3><?=e($meta['label'])?></h3>
                    </div>
                    <span class="aiw2-count-badge"><?=count($items)?></span>
                </header>

                <div class="aiw2-intelligence-items">
                    <?php if (!$items): ?>
                        <p class="muted-text">No source-supported items were identified.</p>
                    <?php else: ?>
                        <?php foreach ($items as $item): ?>
                            <div class="aiw2-intelligence-item">
                                <strong><?=e($item['title'] ?? '')?></strong>
                                <p><?=e($item['detail'] ?? '')?></p>
                                <?php if (!empty($item['evidence'])): ?>
                                    <small><?=e($item['evidence'])?></small>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </section>

    <?php if ($recommendations): ?>
        <section class="aiw2-recommendations aiw2-reveal">
            <div class="aiw2-section-head">
                <div>
                    <span>Next best actions</span>
                    <h3>Recommended priorities</h3>
                </div>
                <span class="aiw2-action-total"><?=count($recommendations)?> actions</span>
            </div>

            <div class="aiw2-action-list">
                <?php foreach ($recommendations as $index => $item): ?>
                    <?php $priority = in_array(($item['priority'] ?? ''), ['high','medium','low'], true) ? (string)$item['priority'] : 'medium'; ?>
                    <article class="aiw2-action-row">
                        <span class="aiw2-action-number"><?=str_pad((string)($index + 1), 2, '0', STR_PAD_LEFT)?></span>
                        <div class="aiw2-action-copy">
                            <div class="aiw2-action-title-row">
                                <strong><?=e($item['title'] ?? '')?></strong>
                                <span class="aiw2-priority aiw2-priority-<?=e($priority)?>"><?=e(ucfirst($priority))?></span>
                            </div>
                            <p><?=e($item['action'] ?? '')?></p>
                            <?php if (!empty($item['rationale'])): ?>
                                <?php if ($printMode): ?>
                                    <div class="aiw2-rationale aiw2-rationale-print">
                                        <strong>Why this matters</strong>
                                        <p><?=e($item['rationale'])?></p>
                                    </div>
                                <?php else: ?>
                                    <details class="aiw2-rationale">
                                        <summary>Why this matters</summary>
                                        <p><?=e($item['rationale'])?></p>
                                    </details>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($sourceSummary): ?>
        <section class="aiw2-sources aiw2-reveal">
            <div class="aiw2-section-head">
                <div>
                    <span>Source confidence</span>
                    <h3>Reporting coverage</h3>
                </div>
                <span class="aiw2-action-total"><?=$availableSources?> of <?=$totalSources?> available</span>
            </div>

            <div class="aiw2-source-grid">
                <?php foreach ($sourceSummary as $source): ?>
                    <?php
                    $available = !empty($source['available']);
                    $sourceStatus = strtolower(str_replace(' ', '-', (string)($source['status'] ?? 'unknown')));
                    ?>
                    <div class="aiw2-source-card <?=$available ? 'is-available' : 'is-unavailable'?>">
                        <span class="aiw2-source-dot"></span>
                        <div>
                            <strong><?=e($source['name'] ?? '')?></strong>
                            <small><?=e($source['status'] ?? 'Unknown')?></small>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if (!empty($dashboard['data_quality_notes'])): ?>
        <section class="aiw2-quality aiw2-reveal">
            <div class="aiw2-quality-icon">i</div>
            <div>
                <strong>Data quality notes</strong>
                <ul>
                    <?php foreach ($dashboard['data_quality_notes'] as $note): ?>
                        <li><?=e($note)?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </section>
    <?php endif; ?>

    <footer class="aiw2-print-footer" aria-hidden="true">
        <span>Aesthetic Intel · AI Weekly Intelligence</span>
        <span>
            <?=e($report['business_name'] ?? '')?>
            · <?=e($report['period_start'] ?? '')?> → <?=e($report['period_end'] ?? '')?>
            <?php if (!empty($report['current_version'])): ?>
                · Version <?=e((string)$report['current_version'])?>
            <?php endif; ?>
        </span>
    </footer>

    <?php if (!$printMode): ?>
        <script type="application/json" data-ai-weekly-chart-data><?=json_encode($chartPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)?></script>
    <?php endif; ?>
</div>
