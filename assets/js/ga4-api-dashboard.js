(function () {
    'use strict';

    function showAllRows() {
        document
            .querySelectorAll('[data-ga4-show-more]')
            .forEach(function (button) {
                button.addEventListener('click', function () {
                    var wrap = button.closest('[data-ga4-table-wrap]');

                    if (!wrap) return;

                    wrap
                        .querySelectorAll('.ga4-extra-row')
                        .forEach(function (row) {
                            row.hidden = false;
                        });

                    button.remove();
                });
            });
    }

    function fieldSearch() {
        document
            .querySelectorAll('[data-field-search]')
            .forEach(function (input) {
                input.addEventListener('input', function () {
                    var type =
                        input.getAttribute('data-field-search');

                    var list = document.querySelector(
                        '[data-field-list="' + type + '"]'
                    );

                    if (!list) return;

                    var query =
                        (input.value || '')
                            .trim()
                            .toLowerCase();

                    list
                        .querySelectorAll('[data-field-option]')
                        .forEach(function (option) {
                            var haystack =
                                (
                                    option.getAttribute('data-search')
                                    || ''
                                ).toLowerCase();

                            option.style.display =
                                !query
                                || haystack.indexOf(query) !== -1
                                    ? ''
                                    : 'none';
                        });
                });
            });
    }

    function selectionLimits() {
        var dimensions =
            Array.prototype.slice.call(
                document.querySelectorAll(
                    '[data-ga4-dimension]'
                )
            );

        var metrics =
            Array.prototype.slice.call(
                document.querySelectorAll(
                    '[data-ga4-metric]'
                )
            );

        var dimensionCount =
            document.querySelector(
                '[data-dimension-count]'
            );

        var metricCount =
            document.querySelector(
                '[data-metric-count]'
            );

        function update() {
            var selectedDimensions =
                dimensions.filter(function (input) {
                    return input.checked;
                });

            var selectedMetrics =
                metrics.filter(function (input) {
                    return input.checked;
                });

            if (dimensionCount) {
                dimensionCount.textContent =
                    selectedDimensions.length
                    + ' selected';
            }

            if (metricCount) {
                metricCount.textContent =
                    selectedMetrics.length
                    + ' selected';
            }

            dimensions.forEach(function (input) {
                input.disabled =
                    !input.checked
                    && selectedDimensions.length >= 9;
            });

            metrics.forEach(function (input) {
                input.disabled =
                    !input.checked
                    && selectedMetrics.length >= 10;
            });
        }

        dimensions.concat(metrics).forEach(function (input) {
            input.addEventListener('change', update);
        });

        update();
    }


    function fetchSuccessToast() {
        var source =
            document.querySelector(
                '[data-ga4-fetch-toast]'
            );

        if (!source) return;

        var toast =
            document.createElement('div');

        toast.className =
            'ga4-api-toast';

        toast.textContent =
            'GA4 API data fetched successfully.';

        document.body.appendChild(toast);

        window.requestAnimationFrame(function () {
            toast.classList.add('is-visible');
        });

        window.setTimeout(function () {
            toast.classList.remove('is-visible');

            window.setTimeout(function () {
                toast.remove();
            }, 220);
        }, 3200);
    }

    function drawChart() {
        var canvas =
            document.getElementById(
                'ga4-performance-chart'
            );

        var dataNode =
            document.getElementById(
                'ga4-daily-chart-data'
            );

        if (!canvas || !dataNode) return;

        var rows = [];

        try {
            rows = JSON.parse(
                dataNode.textContent || '[]'
            );
        } catch (error) {
            return;
        }

        if (!Array.isArray(rows) || !rows.length) {
            return;
        }

        var context =
            canvas.getContext('2d');

        if (!context) return;

        function render() {
            var rect =
                canvas.getBoundingClientRect();

            var ratio =
                window.devicePixelRatio || 1;

            var width =
                Math.max(
                    300,
                    Math.floor(rect.width)
                );

            var height =
                Math.max(
                    200,
                    Math.floor(rect.height)
                );

            canvas.width =
                Math.floor(width * ratio);

            canvas.height =
                Math.floor(height * ratio);

            context.setTransform(
                ratio,
                0,
                0,
                ratio,
                0,
                0
            );

            context.clearRect(
                0,
                0,
                width,
                height
            );

            var padding = {
                top: 18,
                right: 14,
                bottom: 34,
                left: 48
            };

            var chartWidth =
                width
                - padding.left
                - padding.right;

            var chartHeight =
                height
                - padding.top
                - padding.bottom;

            var maxValue = Math.max.apply(
                null,
                rows.map(function (row) {
                    return Math.max(
                        Number(row.sessions || 0),
                        Number(row.users || 0)
                    );
                }).concat([1])
            );

            context.strokeStyle =
                'rgba(90,91,100,.16)';

            context.lineWidth = 1;

            context.fillStyle =
                '#7a7b83';

            context.font =
                '11px system-ui, sans-serif';

            for (var i = 0; i <= 4; i++) {
                var y =
                    padding.top
                    + (
                        chartHeight
                        * i / 4
                    );

                context.beginPath();
                context.moveTo(
                    padding.left,
                    y
                );
                context.lineTo(
                    width - padding.right,
                    y
                );
                context.stroke();

                var label =
                    Math.round(
                        maxValue
                        * (1 - i / 4)
                    );

                context.fillText(
                    label.toLocaleString(),
                    2,
                    y + 4
                );
            }

            function plot(key, lineWidth) {
                context.beginPath();
                context.lineWidth =
                    lineWidth;

                context.strokeStyle =
                    key === 'sessions'
                        ? '#18181c'
                        : '#8d8e96';

                rows.forEach(function (row, index) {
                    var x =
                        padding.left
                        + (
                            rows.length === 1
                                ? chartWidth / 2
                                : (
                                    chartWidth
                                    * index
                                    / (rows.length - 1)
                                )
                        );

                    var value =
                        Number(row[key] || 0);

                    var y =
                        padding.top
                        + chartHeight
                        - (
                            value
                            / maxValue
                            * chartHeight
                        );

                    if (index === 0) {
                        context.moveTo(x, y);
                    } else {
                        context.lineTo(x, y);
                    }
                });

                context.stroke();
            }

            plot('sessions', 2.3);
            plot('users', 1.7);

            var labelsToShow =
                Math.min(
                    6,
                    rows.length
                );

            for (
                var labelIndex = 0;
                labelIndex < labelsToShow;
                labelIndex++
            ) {
                var rowIndex =
                    Math.round(
                        labelIndex
                        * (rows.length - 1)
                        / Math.max(
                            1,
                            labelsToShow - 1
                        )
                    );

                var row =
                    rows[rowIndex];

                var x =
                    padding.left
                    + (
                        rows.length === 1
                            ? chartWidth / 2
                            : (
                                chartWidth
                                * rowIndex
                                / (rows.length - 1)
                            )
                    );

                var text =
                    String(row.date || '')
                        .slice(5);

                var measured =
                    context.measureText(text);

                context.fillText(
                    text,
                    Math.max(
                        padding.left,
                        Math.min(
                            width
                                - padding.right
                                - measured.width,
                            x
                                - measured.width
                                / 2
                        )
                    ),
                    height - 10
                );
            }

            context.fillStyle = '#18181c';
            context.fillRect(
                padding.left,
                1,
                18,
                2
            );
            context.fillText(
                'Sessions',
                padding.left + 24,
                7
            );

            context.fillStyle = '#8d8e96';
            context.fillRect(
                padding.left + 92,
                1,
                18,
                2
            );
            context.fillText(
                'Active users',
                padding.left + 116,
                7
            );
        }

        render();

        var timeout = null;

        window.addEventListener(
            'resize',
            function () {
                window.clearTimeout(timeout);
                timeout =
                    window.setTimeout(
                        render,
                        120
                    );
            }
        );
    }

    document.addEventListener(
        'DOMContentLoaded',
        function () {
            showAllRows();
            fieldSearch();
            selectionLimits();
            fetchSuccessToast();
            drawChart();
        }
    );
})();
