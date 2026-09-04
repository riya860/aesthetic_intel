<?php

/** @var array|null $testResult */

?>

<div class="page-header">

    <div>

        <h1>
            OpenAI Weekly Report Test
        </h1>

        <p class="muted">
            Test the dedicated OpenAI API key
            used only by AI Weekly Reports.
        </p>

    </div>

</div>


<div class="card aiw-test-card">

    <dl class="aiw-runtime-grid">


        <div>

            <dt>
                Provider
            </dt>

            <dd>
                OpenAI
            </dd>

        </div>


        <div>

            <dt>
                Model
            </dt>

            <dd>
                <?=e(
                    openai_weekly_model()
                )?>
            </dd>

        </div>


        <div>

            <dt>
                Reasoning
            </dt>

            <dd>
                <?=e(
                    strtoupper(
                        openai_weekly_reasoning_effort()
                    )
                )?>
            </dd>

        </div>


        <div>

            <dt>
                Dedicated API Key
            </dt>

            <dd>

                <?php if(
                    openai_weekly_key_is_configured()
                ):?>

                    Configured

                <?php else:?>

                    Missing

                <?php endif;?>

            </dd>

        </div>


        <div>

            <dt>
                Endpoint
            </dt>

            <dd>
                OpenAI Responses API
            </dd>

        </div>

    </dl>


    <div class="alert alert-info">

        This test uses
        <strong>OPENAI_WEEKLY_API_KEY</strong>
        from the server environment.

        It does not use the OpenAI API key
        configured in the existing
        AI Integration screen.

    </div>


    <form
        method="post"
        action="<?=e(
            url(
                'admin-openai-weekly-test'
            )
        )?>"
    >

        <?=csrf_field()?>


        <button
            class="btn btn-primary"
            type="submit"
        >
            Run OpenAI Weekly Test
        </button>

    </form>

</div>


<?php if(
    is_array(
        $testResult
    )
):?>

<div
    class="card aiw-test-result"
    style="margin-top:18px"
>

    <h2>

        <?=!empty(
            $testResult['ok']
        )
            ? 'Connection successful'
            : 'Connection failed'?>

    </h2>


    <p>

        <?=e(
            (string)(
                $testResult['message']
                ?? ''
            )
        )?>

    </p>


    <?php if(
        !empty(
            $testResult['model']
        )
    ):?>

        <p class="muted">

            Provider:
            OpenAI

            · Model:

            <?=e(
                (string)
                $testResult['model']
            )?>

            · Reasoning:

            <?=e(
                strtoupper(
                    (string)(
                        $testResult[
                            'reasoning_effort'
                        ]
                        ?? ''
                    )
                )
            )?>

        </p>


        <p class="muted">

            Input tokens:

            <?=e(
                (string)(
                    $testResult[
                        'usage'
                    ]['input_tokens']
                    ?? 0
                )
            )?>

            · Output tokens:

            <?=e(
                (string)(
                    $testResult[
                        'usage'
                    ]['output_tokens']
                    ?? 0
                )
            )?>

            · Reasoning tokens:

            <?=e(
                (string)(
                    $testResult[
                        'usage'
                    ]['thought_tokens']
                    ?? 0
                )
            )?>

            · Total:

            <?=e(
                (string)(
                    $testResult[
                        'usage'
                    ]['total_tokens']
                    ?? 0
                )
            )?>

        </p>

    <?php endif;?>

</div>

<?php endif;?>