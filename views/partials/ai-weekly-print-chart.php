<?php

$chart = is_array($printChart ?? null) ? $printChart : [];
$type = (string)($chart['type'] ?? 'bar');
$labels = is_array($chart['labels'] ?? null) ? array_values($chart['labels']) : [];
$datasets = is_array($chart['datasets'] ?? null) ? array_values($chart['datasets']) : [];
$format = (string)($chart['value_format'] ?? 'number');

$svgWidth = 900.0;
$svgHeight = 330.0;
$palette = ['#6d5dfc', '#e36f61', '#3ba776', '#d69a45', '#4f88d9'];

$fmt = static function (float $value) use ($format): string {
    if ($format === 'currency') {
        $abs = abs($value);
        if ($abs >= 1000000) {
            return '$' . number_format($value / 1000000, 1) . 'M';
        }
        if ($abs >= 1000) {
            return '$' . number_format($value / 1000, 1) . 'K';
        }
        return '$' . number_format($value, abs($value - round($value)) > 0.001 ? 1 : 0);
    }

    if ($format === 'percent') {
        return number_format($value, abs($value - round($value)) > 0.001 ? 1 : 0) . '%';
    }

    $abs = abs($value);
    if ($abs >= 1000000) {
        return number_format($value / 1000000, 1) . 'M';
    }
    if ($abs >= 1000) {
        return number_format($value / 1000, 1) . 'K';
    }

    return number_format($value, abs($value - round($value)) > 0.001 ? 1 : 0);
};

$short = static function (string $text, int $limit = 17): string {
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    if ($text === '') {
        return '';
    }

    $length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    if ($length <= $limit) {
        return $text;
    }

    $cut = function_exists('mb_substr')
        ? mb_substr($text, 0, max(1, $limit - 1), 'UTF-8')
        : substr($text, 0, max(1, $limit - 1));

    return rtrim($cut) . '…';
};

$allValues = [];
foreach ($datasets as $dataset) {
    foreach ((array)($dataset['values'] ?? []) as $value) {
        if (is_numeric($value)) {
            $allValues[] = (float)$value;
        }
    }
}

if (!$labels || !$datasets || !$allValues): ?>
    <div class="aiw2-print-chart-empty">No chartable values were available for this visual.</div>
<?php return; endif; ?>

<?php if ($type === 'doughnut'): ?>
    <?php
    $values = [];
    foreach ((array)($datasets[0]['values'] ?? []) as $value) {
        $values[] = max(0.0, (float)$value);
    }
    $total = array_sum($values);
    ?>

    <?php if ($total <= 0): ?>
        <div class="aiw2-print-chart-empty">This doughnut chart has no positive values to plot.</div>
    <?php else: ?>
        <?php
        $cx = 245.0;
        $cy = 155.0;
        $radius = 92.0;
        $circumference = 2 * M_PI * $radius;
        $offset = 0.0;
        ?>
        <svg
            class="aiw2-print-chart-svg"
            viewBox="0 0 <?=e((string)$svgWidth)?> <?=e((string)$svgHeight)?>"
            role="img"
            aria-label="<?=e((string)($chart['title'] ?? 'Doughnut chart'))?>"
            xmlns="http://www.w3.org/2000/svg"
        >
            <rect width="900" height="330" fill="#ffffff"/>
            <circle cx="<?=$cx?>" cy="<?=$cy?>" r="<?=$radius?>" fill="none" stroke="#eeeaf1" stroke-width="44"/>

            <?php foreach ($values as $i => $value): ?>
                <?php
                $portion = $value / $total;
                $dash = max(0.0, $circumference * $portion);
                $gap = max(0.0, $circumference - $dash);
                $color = $palette[$i % count($palette)];
                ?>
                <circle
                    cx="<?=$cx?>"
                    cy="<?=$cy?>"
                    r="<?=$radius?>"
                    fill="none"
                    stroke="<?=e($color)?>"
                    stroke-width="44"
                    stroke-linecap="butt"
                    stroke-dasharray="<?=number_format($dash, 3, '.', '')?> <?=number_format($gap, 3, '.', '')?>"
                    stroke-dashoffset="<?=number_format(-$offset, 3, '.', '')?>"
                    transform="rotate(-90 <?=$cx?> <?=$cy?>)"
                />
                <?php $offset += $dash; ?>
            <?php endforeach; ?>

            <text x="<?=$cx?>" y="<?=$cy - 4?>" text-anchor="middle" font-size="28" font-weight="700" fill="#29252d"><?=e($fmt($total))?></text>
            <text x="<?=$cx?>" y="<?=$cy + 22?>" text-anchor="middle" font-size="13" fill="#746d78">total</text>

            <?php foreach ($labels as $i => $label): ?>
                <?php
                $y = 72 + ($i * 42);
                if ($y > 300) { break; }
                $value = (float)($values[$i] ?? 0);
                $color = $palette[$i % count($palette)];
                ?>
                <rect x="500" y="<?=$y - 12?>" width="13" height="13" rx="4" fill="<?=e($color)?>"/>
                <text x="526" y="<?=$y?>" font-size="14" font-weight="650" fill="#29252d"><?=e($short((string)$label, 24))?></text>
                <text x="820" y="<?=$y?>" text-anchor="end" font-size="14" fill="#746d78"><?=e($fmt($value))?></text>
            <?php endforeach; ?>
        </svg>
    <?php endif; ?>

<?php else: ?>
    <?php
    $plotLeft = 82.0;
    $plotRight = 26.0;
    $plotTop = 45.0;
    $plotBottom = 62.0;
    $plotWidth = $svgWidth - $plotLeft - $plotRight;
    $plotHeight = $svgHeight - $plotTop - $plotBottom;

    $minValue = min(0.0, min($allValues));
    $maxValue = max(0.0, max($allValues));
    if (abs($maxValue - $minValue) < 0.000001) {
        $maxValue = $minValue + 1.0;
    }
    $range = $maxValue - $minValue;

    $yFor = static function (float $value) use ($plotTop, $plotHeight, $minValue, $range): float {
        return $plotTop + (($max = ($minValue + $range)) - $value) / $range * $plotHeight;
    };

    $zeroY = $yFor(0.0);
    $countLabels = max(1, count($labels));
    ?>

    <svg
        class="aiw2-print-chart-svg"
        viewBox="0 0 <?=e((string)$svgWidth)?> <?=e((string)$svgHeight)?>"
        role="img"
        aria-label="<?=e((string)($chart['title'] ?? 'Performance chart'))?>"
        xmlns="http://www.w3.org/2000/svg"
    >
        <rect width="900" height="330" fill="#ffffff"/>

        <?php for ($tick = 0; $tick <= 4; $tick++): ?>
            <?php
            $ratio = $tick / 4;
            $value = $maxValue - ($range * $ratio);
            $y = $plotTop + ($plotHeight * $ratio);
            ?>
            <line x1="<?=$plotLeft?>" y1="<?=$y?>" x2="<?=$plotLeft + $plotWidth?>" y2="<?=$y?>" stroke="#ece8ef" stroke-width="1"/>
            <text x="<?=$plotLeft - 12?>" y="<?=$y + 5?>" text-anchor="end" font-size="11" fill="#817a85"><?=e($fmt($value))?></text>
        <?php endfor; ?>

        <?php if ($type === 'bar'): ?>
            <?php
            $datasetCount = max(1, count($datasets));
            $slot = $plotWidth / $countLabels;
            $groupWidth = min($slot * 0.72, 90.0);
            $barGap = 5.0;
            $barWidth = max(5.0, ($groupWidth - (($datasetCount - 1) * $barGap)) / $datasetCount);
            ?>
            <?php foreach ($labels as $labelIndex => $label): ?>
                <?php
                $centerX = $plotLeft + ($slot * $labelIndex) + ($slot / 2);
                $groupStart = $centerX - ($groupWidth / 2);
                ?>
                <?php foreach ($datasets as $datasetIndex => $dataset): ?>
                    <?php
                    $value = (float)($dataset['values'][$labelIndex] ?? 0);
                    $valueY = $yFor($value);
                    $topY = min($zeroY, $valueY);
                    $height = max(1.0, abs($zeroY - $valueY));
                    $x = $groupStart + ($datasetIndex * ($barWidth + $barGap));
                    $color = $palette[$datasetIndex % count($palette)];
                    ?>
                    <rect x="<?=$x?>" y="<?=$topY?>" width="<?=$barWidth?>" height="<?=$height?>" rx="6" fill="<?=e($color)?>" fill-opacity="0.88"/>
                <?php endforeach; ?>
                <text x="<?=$centerX?>" y="<?=$plotTop + $plotHeight + 28?>" text-anchor="middle" font-size="11" fill="#746d78"><?=e($short((string)$label, 14))?></text>
            <?php endforeach; ?>
        <?php else: ?>
            <?php
            $denominator = max(1, count($labels) - 1);
            ?>
            <?php foreach ($datasets as $datasetIndex => $dataset): ?>
                <?php
                $points = [];
                foreach ($labels as $labelIndex => $label) {
                    $x = $plotLeft + ($plotWidth * ($labelIndex / $denominator));
                    $y = $yFor((float)($dataset['values'][$labelIndex] ?? 0));
                    $points[] = number_format($x, 2, '.', '') . ',' . number_format($y, 2, '.', '');
                }
                $color = $palette[$datasetIndex % count($palette)];
                ?>
                <polyline points="<?=e(implode(' ', $points))?>" fill="none" stroke="<?=e($color)?>" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/>
                <?php foreach ($labels as $labelIndex => $label): ?>
                    <?php
                    $x = $plotLeft + ($plotWidth * ($labelIndex / $denominator));
                    $y = $yFor((float)($dataset['values'][$labelIndex] ?? 0));
                    ?>
                    <circle cx="<?=$x?>" cy="<?=$y?>" r="5" fill="#fff" stroke="<?=e($color)?>" stroke-width="3"/>
                <?php endforeach; ?>
            <?php endforeach; ?>

            <?php foreach ($labels as $labelIndex => $label): ?>
                <?php $x = $plotLeft + ($plotWidth * ($labelIndex / $denominator)); ?>
                <text x="<?=$x?>" y="<?=$plotTop + $plotHeight + 28?>" text-anchor="middle" font-size="11" fill="#746d78"><?=e($short((string)$label, 14))?></text>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php foreach ($datasets as $datasetIndex => $dataset): ?>
            <?php
            $legendX = $plotLeft + ($datasetIndex * 210);
            $color = $palette[$datasetIndex % count($palette)];
            ?>
            <rect x="<?=$legendX?>" y="14" width="11" height="11" rx="3" fill="<?=e($color)?>"/>
            <text x="<?=$legendX + 18?>" y="24" font-size="11" font-weight="600" fill="#5d5661"><?=e($short((string)($dataset['label'] ?? 'Value'), 22))?></text>
        <?php endforeach; ?>
    </svg>
<?php endif; ?>

<div class="aiw2-print-chart-table-wrap">
    <table class="aiw2-print-chart-table">
        <thead>
            <tr>
                <th>Period / segment</th>
                <?php foreach ($datasets as $dataset): ?>
                    <th><?=e((string)($dataset['label'] ?? 'Value'))?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($labels as $labelIndex => $label): ?>
                <tr>
                    <td><?=e((string)$label)?></td>
                    <?php foreach ($datasets as $dataset): ?>
                        <td><?=e($fmt((float)($dataset['values'][$labelIndex] ?? 0)))?></td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
