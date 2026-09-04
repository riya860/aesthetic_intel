<?php

$dashboard = ai_weekly_report_decode($report);
$printMode = true;
$autoPrint = !empty($autoPrint);
$backUrl = (string)($backUrl ?? url('home'));

if (!$dashboard) {
    throw new RuntimeException('This AI Weekly Report does not contain a generated dashboard.');
}

$printTitle = trim((string)($report['business_name'] ?? 'Business'))
    . ' - AI Weekly Report - '
    . trim((string)($report['period_start'] ?? ''))
    . ' to '
    . trim((string)($report['period_end'] ?? ''));
?>

<div class="ai-weekly-print-document">

    <div class="ai-weekly-print-toolbar no-print">
        <div>
            <strong>PDF-ready report</strong>
            <span>
                This page is isolated from the dashboard shell so the PDF cannot be hidden by sidebar or application print styles.
            </span>
        </div>

        <div class="button-row">
            <a class="btn btn-secondary" href="<?=e($backUrl)?>">Back</a>
            <button class="btn btn-primary" type="button" onclick="window.print()">Print / Save PDF</button>
        </div>
    </div>

    <main class="ai-weekly-print-paper" id="ai-weekly-print-paper">
        <?php require VIEW_PATH . '/partials/ai-weekly-dashboard.php'; ?>
    </main>

</div>

<script>
(function () {
    'use strict';

    var originalTitle = document.title;
    var printTitle = <?=json_encode($printTitle, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)?>;
    var shouldAutoPrint = <?= $autoPrint ? 'true' : 'false' ?>;

    function safeTitle(value) {
        return String(value || 'AI Weekly Report')
            .replace(/[\\/:*?"<>|]+/g, '-')
            .replace(/\s+/g, ' ')
            .trim()
            .slice(0, 140);
    }

    function setPrintTitle() {
        document.title = safeTitle(printTitle);
    }

    window.addEventListener('beforeprint', setPrintTitle);

    window.addEventListener('afterprint', function () {
        document.title = originalTitle;
    });

    if (shouldAutoPrint) {
        window.addEventListener('load', function () {
            setTimeout(function () {
                setPrintTitle();
                window.print();
            }, 450);
        });
    }
})();
</script>
