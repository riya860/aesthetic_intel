<?php

declare(strict_types=1);

/**
 * ============================================================
 * GA4 PDF / API VALIDATION
 * ============================================================
 *
 * Used by the Brospro GA4 Test Console.
 *
 * Important:
 * - Does NOT modify production report data.
 * - Accepts multiple GA4 PDFs.
 * - Extracts only values actually present in the PDFs.
 * - Never invents missing metrics.
 * - Refuses to compare different reporting periods.
 */


/**
 * Convert a multiple-file PHP upload field into
 * a simple list of file arrays.
 */
function ga4_validation_uploaded_files(?array $files): array
{
    if (
        !$files ||
        !isset($files['name'])
    ) {
        return [];
    }

    /*
     * Single file.
     */
    if (!is_array($files['name'])) {
        return [$files];
    }

    $result = [];

    foreach ($files['name'] as $i => $name) {

        if (
            (int)($files['error'][$i] ?? UPLOAD_ERR_NO_FILE)
            === UPLOAD_ERR_NO_FILE
        ) {
            continue;
        }

        $result[] = [
            'name' =>
                (string)$name,

            'type' =>
                (string)($files['type'][$i] ?? ''),

            'tmp_name' =>
                (string)($files['tmp_name'][$i] ?? ''),

            'error' =>
                (int)($files['error'][$i] ?? UPLOAD_ERR_NO_FILE),

            'size' =>
                (int)($files['size'][$i] ?? 0),
        ];
    }

    return $result;
}


/**
 * Validate one uploaded GA4 PDF.
 */
function ga4_validation_assert_pdf(array $file): void
{
    if (
        (int)($file['error'] ?? UPLOAD_ERR_NO_FILE)
        !== UPLOAD_ERR_OK
    ) {
        throw new RuntimeException(
            'One of the GA4 PDF uploads failed.'
        );
    }

    $name = trim(
        (string)($file['name'] ?? '')
    );

    if ($name === '') {
        throw new RuntimeException(
            'A GA4 PDF has no filename.'
        );
    }

    $size =
        (int)($file['size'] ?? 0);

    if ($size <= 0) {
        throw new RuntimeException(
            $name . ' is empty.'
        );
    }

    if ($size > 10 * 1024 * 1024) {
        throw new RuntimeException(
            $name . ' is larger than 10 MB.'
        );
    }

    $tmp =
        (string)($file['tmp_name'] ?? '');

    if (
        $tmp === ''
        ||
        !is_uploaded_file($tmp)
    ) {
        throw new RuntimeException(
            $name . ' could not be verified as an uploaded file.'
        );
    }

    $mime =
        (new finfo(FILEINFO_MIME_TYPE))
            ->file($tmp);

    if (
        !in_array(
            $mime,
            [
                'application/pdf',
                'application/x-pdf',
            ],
            true
        )
    ) {
        throw new RuntimeException(
            $name . ' is not a PDF.'
        );
    }
}


/**
 * Extract text from a PDF.
 */
function ga4_validation_pdf_text(string $path): string
{
    if (
        !class_exists(
            \Smalot\PdfParser\Parser::class
        )
    ) {
        throw new RuntimeException(
            'PDF parser is unavailable. Run composer require smalot/pdfparser.'
        );
    }

    try {

        $parser =
            new \Smalot\PdfParser\Parser();

        $pdf =
            $parser->parseFile($path);

        $text =
            trim(
                (string)$pdf->getText()
            );

    } catch (Throwable $e) {

        throw new RuntimeException(
            'The GA4 PDF could not be read.'
        );
    }

    if ($text === '') {
        throw new RuntimeException(
            'No readable text was found in the GA4 PDF.'
        );
    }

    return $text;
}


/**
 * Flatten PDF text so report-layout whitespace does not
 * break our deterministic matching.
 */
function ga4_validation_flat_text(string $text): string
{
    $text = str_replace(
        [
            "\xC2\xA0",
            '–',
            '—',
        ],
        [
            ' ',
            '-',
            '-',
        ],
        $text
    );

    return trim(
        (string)preg_replace(
            '/\s+/u',
            ' ',
            $text
        )
    );
}


/**
 * Convert:
 *
 * 17s
 * 18 s
 * 2m 41s
 *
 * into seconds.
 */
function ga4_validation_duration_seconds(string $value): float
{
    $value =
        strtolower(
            trim($value)
        );

    $minutes = 0.0;
    $seconds = 0.0;

    if (
        preg_match(
            '/(\d+(?:\.\d+)?)\s*m/',
            $value,
            $m
        )
    ) {
        $minutes =
            (float)$m[1];
    }

    if (
        preg_match(
            '/(\d+(?:\.\d+)?)\s*s/',
            $value,
            $m
        )
    ) {
        $seconds =
            (float)$m[1];
    }

    return ($minutes * 60) + $seconds;
}


/**
 * Convert number text such as:
 *
 * 1,234
 * 0.32
 */
function ga4_validation_number(string $value): float
{
    return (float)str_replace(
        [',', ' '],
        '',
        trim($value)
    );
}


/**
 * Find the report date range in a GA4 exported PDF.
 *
 * Example:
 *
 * Last 28 days 2 Aug - 29 Aug 2026
 */
function ga4_validation_extract_period(string $text): ?array
{
    $patterns = [

        '/Last\s+\d+\s+days\s+'
        . '(\d{1,2})\s+([A-Za-z]{3,9})'
        . '\s*-\s*'
        . '(\d{1,2})\s+([A-Za-z]{3,9})'
        . '\s+(\d{4})/iu',

        '/(\d{1,2})\s+([A-Za-z]{3,9})'
        . '\s*-\s*'
        . '(\d{1,2})\s+([A-Za-z]{3,9})'
        . '\s+(\d{4})/iu',
    ];

    $match = null;

    foreach ($patterns as $pattern) {

        if (
            preg_match(
                $pattern,
                $text,
                $candidate
            )
        ) {
            $match = $candidate;
            break;
        }
    }

    if (!$match) {
        return null;
    }

    $year =
        (int)$match[5];

    $startMonth =
        ucfirst(
            strtolower(
                substr(
                    (string)$match[2],
                    0,
                    3
                )
            )
        );

    $endMonth =
        ucfirst(
            strtolower(
                substr(
                    (string)$match[4],
                    0,
                    3
                )
            )
        );

    $start =
        DateTimeImmutable::createFromFormat(
            '!j M Y',
            $match[1]
            . ' '
            . $startMonth
            . ' '
            . $year
        );

    $end =
        DateTimeImmutable::createFromFormat(
            '!j M Y',
            $match[3]
            . ' '
            . $endMonth
            . ' '
            . $year
        );

    if (!$start || !$end) {
        return null;
    }

    return [
        'start' =>
            $start->format('Y-m-d'),

        'end' =>
            $end->format('Y-m-d'),
    ];
}


/**
 * ============================================================
 * LANDING PAGE REPORT
 * ============================================================
 *
 * The supplied Brospro report contains:
 *
 * Sessions                            240
 * Active users                        226
 * New users                           223
 * Average engagement time/session     17s
 */
function ga4_validation_parse_landing_page(
    string $text
): array {

    $metrics = [];

    /*
     * Google places the metric headers together,
     * followed by their totals.
     */
    $pattern =
        '/Sessions\s+'
        . 'Active\s+users\s+'
        . 'New\s+users\s+'
        . 'Average\s+engagement\s+time\s+per\s+session'
        . '.*?'
        . '\b([\d,]+)\s+100%\s+of\s+total'
        . '\s+'
        . '([\d,]+)\s+100%\s+of\s+total'
        . '\s+'
        . '([\d,]+)\s+100%\s+of\s+total'
        . '\s+'
        . '((?:\d+\s*m\s*)?\d+(?:\.\d+)?\s*s)'
        . '/iu';

    if (
        preg_match(
            $pattern,
            $text,
            $m
        )
    ) {

        $metrics['sessions'] =
            ga4_validation_number(
                $m[1]
            );

        $metrics['active_users'] =
            ga4_validation_number(
                $m[2]
            );

        $metrics['new_users'] =
            ga4_validation_number(
                $m[3]
            );

        $metrics[
            'average_engagement_time_per_session'
        ] =
            ga4_validation_duration_seconds(
                $m[4]
            );
    }

    return $metrics;
}


/**
 * ============================================================
 * ENGAGEMENT OVERVIEW REPORT
 * ============================================================
 */
function ga4_validation_parse_engagement_overview(
    string $text
): array {

    $metrics = [];


    /*
     * Average engagement time per active user.
     */
    if (
        preg_match(
            '/Average\s+engagement\s*time\s+'
            . 'per\s+active\s+user'
            . '.{0,80}?'
            . '((?:\d+\s*m\s*)?\d+(?:\.\d+)?\s*s)/iu',
            $text,
            $m
        )
    ) {

        $metrics[
            'average_engagement_time_per_active_user'
        ] =
            ga4_validation_duration_seconds(
                $m[1]
            );
    }


    /*
     * GA PDF extraction may truncate the label as:
     *
     * "Engaged sessions per ac"
     */
    if (
        preg_match(
            '/Engaged\s+sessions\s+per\s+'
            . '(?:active\s+user|ac)'
            . '.{0,50}?'
            . '(\d+(?:\.\d+)?)/iu',
            $text,
            $m
        )
    ) {

        $metrics[
            'engaged_sessions_per_active_user'
        ] =
            ga4_validation_number(
                $m[1]
            );
    }


    /*
     * Views.
     */
    if (
        preg_match(
            '/\bViews\s+help_outline\s+([\d,]+)\b/iu',
            $text,
            $m
        )
    ) {

        $metrics['page_views'] =
            ga4_validation_number(
                $m[1]
            );
    }


    /*
     * Event Count.
     */
    if (
        preg_match(
            '/\bEvent\s+count\s+help_outline\s+([\d,]+)\b/iu',
            $text,
            $m
        )
    ) {

        $metrics['event_count'] =
            ga4_validation_number(
                $m[1]
            );
    }


    return $metrics;
}


/**
 * Determine the known GA4 report type.
 */
function ga4_validation_report_type(string $text): string
{
    if (
        stripos(
            $text,
            'Landing page: Landing page'
        ) !== false
        ||
        stripos(
            $text,
            'Sessions by Landing page'
        ) !== false
    ) {
        return 'landing_page';
    }

    if (
        stripos(
            $text,
            'Engagement overview'
        ) !== false
    ) {
        return 'engagement_overview';
    }

    return 'other_ga4_report';
}


/**
 * Parse one uploaded GA4 report.
 */
function ga4_validation_parse_pdf(array $file): array
{
    ga4_validation_assert_pdf(
        $file
    );

    $rawText =
        ga4_validation_pdf_text(
            (string)$file['tmp_name']
        );

    $text =
        ga4_validation_flat_text(
            $rawText
        );

    /*
     * Basic GA4 validation.
     */
    if (
        stripos(
            $text,
            'Analytics'
        ) === false
    ) {
        throw new RuntimeException(
            basename(
                (string)$file['name']
            )
            . ' does not appear to be a Google Analytics report.'
        );
    }

    $period =
        ga4_validation_extract_period(
            $text
        );

    $reportType =
        ga4_validation_report_type(
            $text
        );

    $metrics = [];

    if (
        $reportType ===
        'landing_page'
    ) {

        $metrics =
            ga4_validation_parse_landing_page(
                $text
            );

    } elseif (
        $reportType ===
        'engagement_overview'
    ) {

        $metrics =
            ga4_validation_parse_engagement_overview(
                $text
            );
    }


    return [

        'file_name' =>
            basename(
                (string)$file['name']
            ),

        'report_type' =>
            $reportType,

        'period_start' =>
            $period['start']
            ?? null,

        'period_end' =>
            $period['end']
            ?? null,

        'metrics' =>
            $metrics,

        'checksum' =>
            hash_file(
                'sha256',
                (string)$file['tmp_name']
            ),
    ];
}


/**
 * Upload and merge every GA4 PDF into a single
 * normalized PDF source set.
 */
function ga4_validation_build_pdf_bundle(
    ?array $upload,
    string $apiStart,
    string $apiEnd
): array {

    $files =
        ga4_validation_uploaded_files(
            $upload
        );

    if (!$files) {
        throw new RuntimeException(
            'Select at least one Google Analytics PDF.'
        );
    }

    if (count($files) > 20) {
        throw new RuntimeException(
            'Upload no more than 20 GA4 PDFs at once.'
        );
    }

    $parsedFiles = [];

    $merged = [];

    $metricSources = [];

    $conflicts = [];


    foreach ($files as $file) {

        $parsed =
            ga4_validation_parse_pdf(
                $file
            );


        /*
         * Any report that contributes metrics MUST have
         * the exact same period as the API result.
         */
        if (
            !empty($parsed['metrics'])
        ) {

            if (
                empty($parsed['period_start'])
                ||
                empty($parsed['period_end'])
            ) {

                throw new RuntimeException(
                    $parsed['file_name']
                    . ' contains comparable GA4 metrics, but its reporting period could not be detected.'
                );
            }


            if (
                $parsed['period_start']
                    !== $apiStart
                ||
                $parsed['period_end']
                    !== $apiEnd
            ) {

                throw new RuntimeException(
                    $parsed['file_name']
                    . ' is for '
                    . $parsed['period_start']
                    . ' to '
                    . $parsed['period_end']
                    . ', but your current API result is for '
                    . $apiStart
                    . ' to '
                    . $apiEnd
                    . '. Fetch the API using the same PDF dates first.'
                );
            }
        }


        foreach (
            $parsed['metrics']
            as $metric => $value
        ) {

            if (
                isset($merged[$metric])
            ) {

                /*
                 * Never silently choose between
                 * conflicting source PDFs.
                 */
                if (
                    abs(
                        (float)$merged[$metric]
                        -
                        (float)$value
                    ) > 0.000001
                ) {

                    $conflicts[$metric] = [
                        'existing' =>
                            $merged[$metric],

                        'new' =>
                            $value,

                        'files' =>
                            array_values(
                                array_unique(
                                    array_merge(
                                        $metricSources[$metric]
                                            ?? [],
                                        [
                                            $parsed['file_name']
                                        ]
                                    )
                                )
                            ),
                    ];

                    continue;
                }

            } else {

                $merged[$metric] =
                    $value;
            }


            $metricSources[$metric][] =
                $parsed['file_name'];

            $metricSources[$metric] =
                array_values(
                    array_unique(
                        $metricSources[$metric]
                    )
                );
        }


        $parsed['status'] =
            !empty($parsed['metrics'])
                ? 'used'
                : 'uploaded_not_comparable';

        $parsedFiles[] =
            $parsed;
    }


    if (!$merged) {

        throw new RuntimeException(
            'The PDFs were uploaded successfully, but none contained metrics currently supported by the comparison engine.'
        );
    }


    return [

        'period_start' =>
            $apiStart,

        'period_end' =>
            $apiEnd,

        'metrics' =>
            $merged,

        'metric_sources' =>
            $metricSources,

        'conflicts' =>
            $conflicts,

        'files' =>
            $parsedFiles,
    ];
}


/**
 * Metric definitions used by the comparison screen.
 */
function ga4_validation_metric_definitions(): array
{
    return [

        'active_users' => [
            'label' =>
                'Active Users',

            'format' =>
                'integer',

            'precision' =>
                0,
        ],

        'sessions' => [
            'label' =>
                'Sessions',

            'format' =>
                'integer',

            'precision' =>
                0,
        ],

        'new_users' => [
            'label' =>
                'New Users',

            'format' =>
                'integer',

            'precision' =>
                0,
        ],

        'engaged_sessions' => [
            'label' =>
                'Engaged Sessions',

            'format' =>
                'integer',

            'precision' =>
                0,
        ],

        'page_views' => [
            'label' =>
                'Views',

            'format' =>
                'integer',

            'precision' =>
                0,
        ],

        'event_count' => [
            'label' =>
                'Event Count',

            'format' =>
                'integer',

            'precision' =>
                0,
        ],

        'average_engagement_time_per_session' => [
            'label' =>
                'Average Engagement Time / Session',

            'format' =>
                'seconds',

            /*
             * GA4 PDF displays whole seconds.
             */
            'precision' =>
                0,
        ],

        'average_engagement_time_per_active_user' => [
            'label' =>
                'Average Engagement Time / Active User',

            'format' =>
                'seconds',

            'precision' =>
                0,
        ],

        'engaged_sessions_per_active_user' => [
            'label' =>
                'Engaged Sessions / Active User',

            'format' =>
                'decimal',

            /*
             * Engagement Overview displays 0.32.
             */
            'precision' =>
                2,
        ],
    ];
}


/**
 * Compare the combined PDF source set to API metrics.
 */
function ga4_validation_compare(
    array $pdfBundle,
    array $apiSummary
): array {

    $definitions =
        ga4_validation_metric_definitions();

    $rows = [];

    $matched = 0;

    $review = 0;

    $conflicted = 0;

    $comparable = 0;


    foreach (
        $definitions
        as $metric => $definition
    ) {

        $pdfValue =
            $pdfBundle['metrics'][$metric]
            ?? null;

        $apiValue =
            $apiSummary[$metric]
            ?? null;

        $sources =
            $pdfBundle[
                'metric_sources'
            ][$metric]
            ?? [];

        $conflict =
            $pdfBundle[
                'conflicts'
            ][$metric]
            ?? null;


        if ($conflict) {

            $status =
                'conflict';

            $difference =
                null;

            $percentDifference =
                null;

            $conflicted++;

        } elseif (
            $pdfValue === null
        ) {

            /*
             * The API may contain a metric that the uploaded
             * PDFs simply do not display.
             */
            $status =
                'not_in_pdf';

            $difference =
                null;

            $percentDifference =
                null;

        } elseif (
            $apiValue === null
        ) {

            $status =
                'api_missing';

            $difference =
                null;

            $percentDifference =
                null;

            $review++;

        } else {

            $comparable++;

            $pdfNumber =
                (float)$pdfValue;

            $apiNumber =
                (float)$apiValue;

            $precision =
                (int)$definition[
                    'precision'
                ];


            /*
             * Compare using the same display precision
             * as Google's exported PDF.
             */
            $matches =
                round(
                    $pdfNumber,
                    $precision
                )
                ===
                round(
                    $apiNumber,
                    $precision
                );


            $difference =
                $apiNumber
                -
                $pdfNumber;


            if ($pdfNumber == 0.0) {

                $percentDifference =
                    $apiNumber == 0.0
                        ? 0.0
                        : null;

            } else {

                $percentDifference =
                    (
                        $difference
                        /
                        $pdfNumber
                    )
                    * 100;
            }


            if ($matches) {

                $status =
                    'match';

                $matched++;

            } else {

                $status =
                    'review';

                $review++;
            }
        }


        $rows[] = [

            'metric' =>
                $metric,

            'label' =>
                $definition['label'],

            'format' =>
                $definition['format'],

            'precision' =>
                $definition['precision'],

            'pdf_value' =>
                $pdfValue,

            'api_value' =>
                $apiValue,

            'difference' =>
                $difference,

            'percent_difference' =>
                $percentDifference,

            'sources' =>
                $sources,

            'status' =>
                $status,

            'conflict' =>
                $conflict,
        ];
    }


    if (
        $comparable > 0
        &&
        $review === 0
        &&
        $conflicted === 0
    ) {

        $overallStatus =
            'verified';

    } elseif (
        $review > 0
        ||
        $conflicted > 0
    ) {

        $overallStatus =
            'review';

    } else {

        $overallStatus =
            'incomplete';
    }


    return [

        'overall_status' =>
            $overallStatus,

        'comparable_metrics' =>
            $comparable,

        'matched_metrics' =>
            $matched,

        'review_metrics' =>
            $review,

        'conflict_metrics' =>
            $conflicted,

        'match_percent' =>
            $comparable > 0
                ? round(
                    (
                        $matched
                        /
                        $comparable
                    )
                    * 100,
                    1
                )
                : 0,

        'rows' =>
            $rows,
    ];
}