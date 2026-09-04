<?php

declare(strict_types=1);

/**
 * ============================================================
 * AESTHETIC INTEL
 * AI Weekly Report - Dedicated OpenAI Provider
 * ============================================================
 *
 * IMPORTANT:
 *
 * This integration does NOT use:
 *
 * - ai_settings.api_key_encrypted
 * - the existing OpenAI project key
 * - the OpenAI Admin API key
 *
 * It uses only:
 *
 * OPENAI_WEEKLY_API_KEY
 *
 * from the server .env file.
 */


/**
 * Read an environment variable safely.
 */
function openai_weekly_env(
    string $key,
    ?string $default = null
): ?string {

    $value =
        $_ENV[$key]
        ?? $_SERVER[$key]
        ?? getenv($key);


    if (
        $value === false
        ||
        $value === null
        ||
        trim((string)$value) === ''
    ) {

        return $default;
    }


    return trim(
        (string)$value
    );
}


/**
 * ============================================================
 * DEDICATED WEEKLY REPORT API KEY
 * ============================================================
 */
function openai_weekly_api_key(): string
{
    $key =
        openai_weekly_env(
            'OPENAI_WEEKLY_API_KEY'
        );


    if (
        !$key
        ||
        trim($key) === ''
    ) {

        throw new RuntimeException(
            'The AI Weekly Report OpenAI API key '
            . 'is not configured. Add '
            . 'OPENAI_WEEKLY_API_KEY to .env.'
        );
    }


    return trim($key);
}


/**
 * Model used only for AI Weekly Reports.
 */
function openai_weekly_model(): string
{
    return
        openai_weekly_env(
            'OPENAI_WEEKLY_MODEL',
            'gpt-5.4-mini'
        )
        ?? 'gpt-5.4-mini';
}


/**
 * Reasoning effort.
 *
 * gpt-5.4-mini supports:
 *
 * none
 * low
 * medium
 * high
 * xhigh
 */
function openai_weekly_reasoning_effort(): string
{
    $effort =
        strtolower(
            openai_weekly_env(
                'OPENAI_WEEKLY_REASONING',
                'high'
            )
            ?? 'high'
        );


    $allowed = [
        'none',
        'low',
        'medium',
        'high',
        'xhigh',
    ];


    if (
        !in_array(
            $effort,
            $allowed,
            true
        )
    ) {

        return 'high';
    }


    return $effort;
}


/**
 * OpenAI API base URL.
 */
function openai_weekly_api_base(): string
{
    return rtrim(
        openai_weekly_env(
            'OPENAI_WEEKLY_API_BASE',
            'https://api.openai.com/v1'
        )
        ?? 'https://api.openai.com/v1',
        '/'
    );
}


/**
 * Request timeout.
 */
function openai_weekly_timeout_seconds(): int
{
    $timeout =
        (int)(
            openai_weekly_env(
                'OPENAI_WEEKLY_TIMEOUT',
                '180'
            )
            ?? '180'
        );


    return max(
        30,
        min(
            300,
            $timeout
        )
    );
}


/**
 * Do not expose an API key through UI.
 */
function openai_weekly_key_is_configured(): bool
{
    return
        trim(
            (string)(
                openai_weekly_env(
                    'OPENAI_WEEKLY_API_KEY',
                    ''
                )
                ?? ''
            )
        ) !== '';
}


/**
 * Convert an OpenAI HTTP error into a safe
 * application-facing message.
 */
function openai_weekly_error_message(
    int $httpStatus,
    string $body
): string {

    $message = '';


    try {

        $decoded =
            json_decode(
                $body,
                true,
                512,
                JSON_THROW_ON_ERROR
            );


        $message =
            trim(
                (string)(
                    $decoded['error']['message']
                    ?? ''
                )
            );


    } catch (Throwable) {

        // Ignore invalid error JSON.
    }


    if ($httpStatus === 401) {

        return
            'OpenAI rejected the AI Weekly Report '
            . 'API key. Verify OPENAI_WEEKLY_API_KEY.';
    }


    if ($httpStatus === 403) {

        return
            'The OpenAI project used by the '
            . 'AI Weekly Report key does not have '
            . 'permission to perform this request.'
            . (
                $message !== ''
                    ? ' '
                        . substr(
                            $message,
                            0,
                            500
                        )
                    : ''
            );
    }


    if ($httpStatus === 404) {

        return
            'The configured AI Weekly Report '
            . 'OpenAI model or endpoint was not found.'
            . (
                $message !== ''
                    ? ' '
                        . substr(
                            $message,
                            0,
                            500
                        )
                    : ''
            );
    }


    if ($httpStatus === 429) {

        return
            'The AI Weekly Report OpenAI project '
            . 'reached a quota, rate, or billing limit.'
            . (
                $message !== ''
                    ? ' '
                        . substr(
                            $message,
                            0,
                            500
                        )
                    : ''
            );
    }


    if ($message !== '') {

        return
            'OpenAI API error ('
            . $httpStatus
            . '): '
            . substr(
                $message,
                0,
                700
            );
    }


    return
        'OpenAI API request failed with HTTP status '
        . $httpStatus
        . '.';
}


/**
 * ============================================================
 * EXTRACT RESPONSE TEXT
 * ============================================================
 */
function openai_weekly_output_text(
    array $response
): string {

    /*
     * Some Responses API representations expose
     * convenience output_text directly.
     */
    $direct =
        trim(
            (string)(
                $response['output_text']
                ?? ''
            )
        );


    if ($direct !== '') {

        return $direct;
    }


    $output =
        is_array(
            $response['output']
            ?? null
        )
            ? $response['output']
            : [];


    $texts = [];


    foreach (
        $output
        as $item
    ) {

        if (!is_array($item)) {
            continue;
        }


        if (
            (
                $item['type']
                ?? ''
            )
            !== 'message'
        ) {

            continue;
        }


        $content =
            is_array(
                $item['content']
                ?? null
            )
                ? $item['content']
                : [];


        foreach (
            $content
            as $part
        ) {

            if (!is_array($part)) {
                continue;
            }


            $type =
                (string)(
                    $part['type']
                    ?? ''
                );


            if (
                $type === 'refusal'
            ) {

                throw new RuntimeException(
                    'OpenAI declined to generate '
                    . 'this weekly report.'
                );
            }


            if (
                $type === 'output_text'
                &&
                isset(
                    $part['text']
                )
            ) {

                $texts[] =
                    (string)$part['text'];
            }
        }
    }


    $text =
        trim(
            implode(
                "\n",
                $texts
            )
        );


    if ($text === '') {

        throw new RuntimeException(
            'OpenAI returned no readable '
            . 'weekly report output.'
        );
    }


    return $text;
}


/**
 * ============================================================
 * SEND STRUCTURED OPENAI REQUEST
 * ============================================================
 *
 * Uses:
 *
 * POST /v1/responses
 *
 * Structured Outputs:
 *
 * text.format.type = json_schema
 *
 * This function intentionally does not enable:
 *
 * - web search
 * - file search
 * - external tools
 *
 * The model may only analyze the supplied weekly report.
 */
function openai_weekly_structured_response(
    string $systemInstruction,
    string $input,
    array $schema,
    int $maxOutputTokens = 12000
): array {
    /*
 * AI Weekly Report generation can take longer than
 * normal page requests because it analyzes a complete
 * normalized weekly business snapshot.
 */
$apiTimeout =
    openai_weekly_timeout_seconds();

$phpExecutionBudget =
    max(
        120,
        min(
            300,
            $apiTimeout + 60
        )
    );

if (
    function_exists(
        'set_time_limit'
    )
) {
    @set_time_limit(
        $phpExecutionBudget
    );
}

    $apiKey =
        openai_weekly_api_key();


    $model =
        openai_weekly_model();


    $payload = [

        'model' =>
            $model,


        /*
         * Highest-priority instructions.
         */
        'instructions' =>
            $systemInstruction,


        /*
         * Weekly report text.
         */
        'input' =>
            $input,


        /*
         * Reasoning configuration.
         */
        'reasoning' => [

            'effort' =>
                openai_weekly_reasoning_effort(),
        ],


        /*
         * Strict structured JSON.
         */
        'text' => [

            'format' => [

                'type' =>
                    'json_schema',

                'name' =>
                    'aesthetic_intel_weekly_report',

                'strict' =>
                    true,

                'schema' =>
                    $schema,
            ],
        ],


        /*
         * Reasoning tokens are included in the
         * max output budget, so keep enough room.
         */
        'max_output_tokens' =>
            max(
                4000,
                min(
                    20000,
                    $maxOutputTokens
                )
            ),


        /*
         * Do not request API-side response storage.
         */
        'store' =>
            false,
    ];


    try {

        $jsonPayload =
            json_encode(
                $payload,
                JSON_UNESCAPED_SLASHES
                |
                JSON_UNESCAPED_UNICODE
                |
                JSON_THROW_ON_ERROR
            );


    } catch (Throwable $e) {

        throw new RuntimeException(
            'Unable to prepare the OpenAI '
            . 'Weekly Report request.',
            0,
            $e
        );
    }


    $url =
        openai_weekly_api_base()
        . '/responses';


    $ch =
        curl_init(
            $url
        );


    if ($ch === false) {

        throw new RuntimeException(
            'Unable to initialize '
            . 'the OpenAI API request.'
        );
    }


    curl_setopt_array(
        $ch,
        [

            CURLOPT_POST =>
                true,


            CURLOPT_RETURNTRANSFER =>
                true,


            CURLOPT_CONNECTTIMEOUT =>
                20,


            CURLOPT_TIMEOUT =>
                openai_weekly_timeout_seconds(),


            CURLOPT_HTTPHEADER => [

                'Content-Type: application/json',

                'Accept: application/json',

                'Authorization: Bearer '
                    . $apiKey,
            ],


            CURLOPT_POSTFIELDS =>
                $jsonPayload,
        ]
    );


    $body =
        curl_exec(
            $ch
        );


    $curlError =
        curl_error(
            $ch
        );


    $httpStatus =
        (int)curl_getinfo(
            $ch,
            CURLINFO_RESPONSE_CODE
        );


    curl_close(
        $ch
    );


    if ($body === false) {

        throw new RuntimeException(
            'Could not reach the OpenAI API. '
            . (
                $curlError !== ''
                    ? substr(
                        $curlError,
                        0,
                        400
                    )
                    : 'Network request failed.'
            )
        );
    }


    if (
        $httpStatus < 200
        ||
        $httpStatus >= 300
    ) {

        throw new RuntimeException(
            openai_weekly_error_message(
                $httpStatus,
                (string)$body
            )
        );
    }


    try {

        $response =
            json_decode(
                (string)$body,
                true,
                512,
                JSON_THROW_ON_ERROR
            );


    } catch (Throwable $e) {

        throw new RuntimeException(
            'OpenAI returned an unreadable '
            . 'API response.',
            0,
            $e
        );
    }


    if (!is_array($response)) {

        throw new RuntimeException(
            'OpenAI returned an invalid response.'
        );
    }


    /*
     * API-level error contained in a response.
     */
    if (
        !empty(
            $response['error']
        )
    ) {

        $apiMessage =
            (string)(
                $response['error']['message']
                ?? 'Unknown OpenAI API error.'
            );


        throw new RuntimeException(
            'OpenAI generation failed: '
            . substr(
                $apiMessage,
                0,
                700
            )
        );
    }


    $status =
        (string)(
            $response['status']
            ?? ''
        );


    if (
        $status !== 'completed'
    ) {

        $reason =
            trim(
                (string)(
                    $response[
                        'incomplete_details'
                    ]['reason']
                    ?? ''
                )
            );


        throw new RuntimeException(
            'OpenAI did not complete the '
            . 'weekly report generation.'
            . (
                $reason !== ''
                    ? ' Reason: '
                        . $reason
                    : ''
            )
        );
    }


    $text =
        openai_weekly_output_text(
            $response
        );


    try {

        $data =
            json_decode(
                $text,
                true,
                512,
                JSON_THROW_ON_ERROR
            );


    } catch (Throwable $e) {

        throw new RuntimeException(
            'OpenAI returned weekly report output '
            . 'that was not valid JSON.',
            0,
            $e
        );
    }


    if (!is_array($data)) {

        throw new RuntimeException(
            'OpenAI returned an invalid '
            . 'structured weekly report.'
        );
    }


    /*
     * ========================================================
     * TOKEN USAGE
     * ========================================================
     */

    $usage =
        is_array(
            $response['usage']
            ?? null
        )
            ? $response['usage']
            : [];


    $outputDetails =
        is_array(
            $usage[
                'output_tokens_details'
            ]
            ?? null
        )
            ? $usage[
                'output_tokens_details'
            ]
            : [];


    $reasoningTokens =
        (int)(
            $outputDetails[
                'reasoning_tokens'
            ]
            ?? 0
        );


    return [

        'data' =>
            $data,


        'provider' =>
            'openai',


        'response_id' =>
            (string)(
                $response['id']
                ?? ''
            ),


        /*
         * Kept for compatibility with the
         * existing version-history column.
         */
        'interaction_id' =>
            (string)(
                $response['id']
                ?? ''
            ),


        'model' =>
            (string)(
                $response['model']
                ?? $model
            ),


        'reasoning_effort' =>
            openai_weekly_reasoning_effort(),


        'usage' => [

            'input_tokens' =>
                (int)(
                    $usage[
                        'input_tokens'
                    ]
                    ?? 0
                ),


            'output_tokens' =>
                (int)(
                    $usage[
                        'output_tokens'
                    ]
                    ?? 0
                ),


            /*
             * Existing DB column is called
             * thought_tokens.
             *
             * We use it for OpenAI reasoning
             * tokens without changing schema.
             */
            'thought_tokens' =>
                $reasoningTokens,


            'total_tokens' =>
                (int)(
                    $usage[
                        'total_tokens'
                    ]
                    ?? 0
                ),
        ],
    ];
}


/**
 * ============================================================
 * CONNECTION TEST
 * ============================================================
 */
function openai_weekly_test_connection(): array
{
    $schema = [

        'type' =>
            'object',


        'properties' => [

            'ok' => [

                'type' =>
                    'boolean',
            ],


            'message' => [

                'type' =>
                    'string',
            ],
        ],


        'required' => [

            'ok',
            'message',
        ],


        'additionalProperties' =>
            false,
    ];


    $result =
        openai_weekly_structured_response(

            'You are a connectivity test for '
            . 'Aesthetic Intel. '
            . 'Return only the requested '
            . 'structured JSON result.',

            'Confirm that the dedicated '
            . 'AI Weekly Report OpenAI API '
            . 'connection works. '
            . 'Return ok=true and a short message.',

            $schema,

            4000
        );


    return [

        'ok' =>
            !empty(
                $result['data']['ok']
            ),


        'message' =>
            (string)(
                $result['data']['message']
                ?? ''
            ),


        'provider' =>
            'openai',


        'model' =>
            (string)(
                $result['model']
                ?? openai_weekly_model()
            ),


        'reasoning_effort' =>
            openai_weekly_reasoning_effort(),


        'response_id' =>
            (string)(
                $result['response_id']
                ?? ''
            ),


        'usage' =>
            is_array(
                $result['usage']
                ?? null
            )
                ? $result['usage']
                : [],
    ];
}