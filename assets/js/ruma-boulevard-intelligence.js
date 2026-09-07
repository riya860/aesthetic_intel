(() => {
    'use strict';

    const payloadNode = document.getElementById('rumaBoulevardChartData');
    if (!payloadNode) return;

    let payload = {};

    try {
        payload = JSON.parse(payloadNode.textContent || '{}');
    } catch (error) {
        console.error('Unable to parse RUMA Boulevard chart data.', error);
        return;
    }

    const money = (value, digits = 0) =>
        new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: 'USD',
            maximumFractionDigits: digits
        }).format(Number(value || 0));

    const number = (value, digits = 1) =>
        new Intl.NumberFormat('en-US', {
            maximumFractionDigits: digits
        }).format(Number(value || 0));

    const compactMoney = (value) =>
        new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: 'USD',
            notation: 'compact',
            maximumFractionDigits: 1
        }).format(Number(value || 0));

    function themeTokens() {
        const style = getComputedStyle(document.documentElement);

        return {
            text:
                style.getPropertyValue('--chart-text').trim() ||
                '#344054',

            muted:
                style.getPropertyValue('--muted').trim() ||
                '#667085',

            grid:
                style.getPropertyValue('--chart-grid').trim() ||
                'rgba(15,23,42,.10)',

            surface:
                style.getPropertyValue('--surface-raised').trim() ||
                '#ffffff',

            primary:
                style.getPropertyValue('--brand-primary').trim() ||
                '#d96a77',

            accent:
                style.getPropertyValue('--brand-accent').trim() ||
                '#7f6df2',

            secondary:
                style.getPropertyValue('--chart-secondary').trim() ||
                '#98a2b3',

            orange:
                style.getPropertyValue('--chart-orange').trim() ||
                '#e5a44b',

            purple:
                style.getPropertyValue('--chart-purple').trim() ||
                '#7666d7',

            red:
                style.getPropertyValue('--danger').trim() ||
                '#c43d3d',

            green:
                style.getPropertyValue('--success').trim() ||
                '#24815d'
        };
    }

    function alpha(hex, opacity) {
        if (!hex || hex[0] !== '#') {
            return hex;
        }

        let raw = hex.slice(1);

        if (raw.length === 3) {
            raw = raw
                .split('')
                .map((c) => c + c)
                .join('');
        }

        if (raw.length !== 6) {
            return hex;
        }

        const r = parseInt(raw.slice(0, 2), 16);
        const g = parseInt(raw.slice(2, 4), 16);
        const b = parseInt(raw.slice(4, 6), 16);

        return `rgba(${r},${g},${b},${opacity})`;
    }

    const charts = [];

    function destroyCharts() {
        while (charts.length) {
            const chart = charts.pop();

            try {
                chart.destroy();
            } catch (_) {
                // Ignore chart destroy errors.
            }
        }
    }

    function commonPlugins({ legend = true } = {}) {
        const t = themeTokens();

        return {
            legend: {
                display: legend,
                position: 'top',
                align: 'start',

                labels: {
                    color: t.text,
                    usePointStyle: true,
                    pointStyle: 'circle',
                    boxWidth: 8,
                    boxHeight: 8,
                    padding: 18,

                    font: {
                        size: 11,
                        weight: 600
                    }
                }
            },

            tooltip: {
                backgroundColor: '#111827',
                titleColor: '#ffffff',
                bodyColor: '#ffffff',
                padding: 12,
                displayColors: true,
                cornerRadius: 10
            }
        };
    }

    function baseScale(title, callback) {
        const t = themeTokens();

        return {
            beginAtZero: true,

            grid: {
                color: t.grid,
                drawBorder: false
            },

            border: {
                color: t.grid
            },

            title: {
                display: true,
                text: title,
                color: t.muted,

                font: {
                    size: 11,
                    weight: 600
                }
            },

            ticks: {
                color: t.muted,
                callback,

                font: {
                    size: 10
                }
            }
        };
    }

    function addChart(id, config) {
        if (typeof window.Chart === 'undefined') {
            return;
        }

        const canvas = document.getElementById(id);

        if (!canvas) {
            return;
        }

        charts.push(
            new window.Chart(
                canvas.getContext('2d'),
                config
            )
        );
    }

    /**
     * Allows the dashboard to show all providers / dates
     * rather than truncating the graph.
     *
     * Large graphs become horizontally scrollable.
     */
    function prepareScrollableCanvas() {
        const providers =
            Array.isArray(payload.providers)
                ? payload.providers
                : [];

        document
            .querySelectorAll('[data-provider-chart-width]')
            .forEach((node) => {
                node.style.width =
                    `${Math.max(
                        820,
                        providers.length * 105
                    )}px`;

                node.style.height = '390px';
            });

        document
            .querySelectorAll('[data-provider-chart-height]')
            .forEach((node) => {
                node.style.height =
                    `${Math.max(
                        340,
                        providers.length * 45
                    )}px`;

                node.style.minWidth = '720px';
            });

        const daily =
            Array.isArray(payload.daily)
                ? payload.daily
                : [];

        document
            .querySelectorAll('[data-daily-chart-width]')
            .forEach((node) => {
                node.style.width =
                    `${Math.max(
                        720,
                        daily.length * 64
                    )}px`;

                node.style.height = '340px';
            });
    }

    /**
     * ------------------------------------------------------------
     * PERFORMANCE COMPARISON
     * ------------------------------------------------------------
     *
     * Shows % change versus previous equivalent period.
     */
    function renderPerformanceChart() {
        const t = themeTokens();

        const performance =
            Array.isArray(payload.performance)
                ? payload.performance
                : [];

        const rows =
            performance
                .filter((row) =>
                    row &&
                    row.metric &&
                    row.metric.available &&
                    Number.isFinite(
                        Number(
                            row.metric.change
                        )
                    )
                )
                .map((row) => ({
                    label:
                        row.label,

                    value:
                        Number(
                            row.metric.change
                        ) * 100
                }));

        addChart(
            'rumaPerformanceChart',
            {
                type: 'bar',

                data: {
                    labels:
                        rows.map(
                            (row) => row.label
                        ),

                    datasets: [
                        {
                            label:
                                'Change vs previous period',

                            data:
                                rows.map(
                                    (row) => row.value
                                ),

                            backgroundColor:
                                rows.map((row) =>
                                    row.value >= 0
                                        ? alpha(t.green, 0.78)
                                        : alpha(t.red, 0.78)
                                ),

                            borderColor:
                                rows.map((row) =>
                                    row.value >= 0
                                        ? t.green
                                        : t.red
                                ),

                            borderWidth: 1,
                            borderRadius: 8,
                            maxBarThickness: 58
                        }
                    ]
                },

                options: {
                    responsive: true,
                    maintainAspectRatio: false,

                    animation: {
                        duration: 550
                    },

                    plugins: {
                        ...commonPlugins({
                            legend: false
                        }),

                        tooltip: {
                            ...commonPlugins({
                                legend: false
                            }).tooltip,

                            callbacks: {
                                label: (ctx) =>
                                    `${ctx.parsed.y >= 0 ? '+' : ''}` +
                                    `${number(
                                        ctx.parsed.y,
                                        1
                                    )}% vs previous period`
                            }
                        }
                    },

                    scales: {
                        x: {
                            grid: {
                                display: false
                            },

                            border: {
                                color: t.grid
                            },

                            title: {
                                display: true,
                                text: 'Business metric',
                                color: t.muted,

                                font: {
                                    size: 11,
                                    weight: 600
                                }
                            },

                            ticks: {
                                color: t.text,
                                autoSkip: false,

                                font: {
                                    size: 10,
                                    weight: 600
                                }
                            }
                        },

                        y: {
                            ...baseScale(
                                'Change vs previous period (%)',
                                (value) => `${value}%`
                            ),

                            beginAtZero: false
                        }
                    }
                }
            }
        );
    }

    /**
     * ------------------------------------------------------------
     * PROVIDER PERFORMANCE
     * ------------------------------------------------------------
     *
     * Bar = actual service revenue when available,
     * otherwise booked service value.
     *
     * Line = provider utilization percentage.
     */
    function renderProviderPerformanceChart() {
        const t = themeTokens();

        const providers =
            Array.isArray(payload.providers)
                ? payload.providers
                : [];

        const labels =
            providers.map(
                (provider) =>
                    provider.provider ||
                    'Unknown'
            );

        const revenue =
            providers.map((provider) =>
                provider.service_revenue_available
                    ? Number(
                        provider.service_revenue || 0
                    )
                    : Number(
                        provider.booked_service_value || 0
                    )
            );

        const utilization =
            providers.map((provider) =>
                provider.utilization_available
                    ? Number(
                        provider.utilization || 0
                    ) * 100
                    : null
            );

        addChart(
            'rumaProviderPerformanceChart',
            {
                type: 'bar',

                data: {
                    labels,

                    datasets: [
                        {
                            type: 'bar',

                            label:
                                'Service revenue / booked value',

                            data:
                                revenue,

                            yAxisID:
                                'yRevenue',

                            backgroundColor:
                                alpha(
                                    t.primary,
                                    0.74
                                ),

                            borderColor:
                                t.primary,

                            borderWidth: 1,
                            borderRadius: 7,
                            maxBarThickness: 48,
                            order: 2
                        },

                        {
                            type: 'line',

                            label:
                                'Utilization',

                            data:
                                utilization,

                            yAxisID:
                                'yUtilization',

                            borderColor:
                                t.accent,

                            backgroundColor:
                                t.accent,

                            pointBackgroundColor:
                                t.accent,

                            pointBorderColor:
                                t.surface,

                            pointBorderWidth: 2,
                            pointRadius: 4,
                            pointHoverRadius: 6,
                            tension: 0.28,
                            spanGaps: false,
                            order: 1
                        }
                    ]
                },

                options: {
                    responsive: true,
                    maintainAspectRatio: false,

                    interaction: {
                        mode: 'index',
                        intersect: false
                    },

                    plugins: {
                        ...commonPlugins({
                            legend: true
                        }),

                        tooltip: {
                            ...commonPlugins({
                                legend: true
                            }).tooltip,

                            callbacks: {
                                label: (ctx) => {
                                    if (
                                        ctx.dataset.yAxisID
                                        ===
                                        'yUtilization'
                                    ) {
                                        return (
                                            `${ctx.dataset.label}: ` +
                                            `${number(
                                                ctx.parsed.y,
                                                1
                                            )}%`
                                        );
                                    }

                                    return (
                                        `${ctx.dataset.label}: ` +
                                        `${money(
                                            ctx.parsed.y,
                                            2
                                        )}`
                                    );
                                }
                            }
                        }
                    },

                    scales: {
                        x: {
                            grid: {
                                display: false
                            },

                            border: {
                                color: t.grid
                            },

                            title: {
                                display: true,
                                text: 'Provider',
                                color: t.muted,

                                font: {
                                    size: 11,
                                    weight: 600
                                }
                            },

                            ticks: {
                                color: t.text,
                                autoSkip: false,
                                maxRotation: 48,
                                minRotation: 32,

                                font: {
                                    size: 10
                                }
                            }
                        },

                        yRevenue: {
                            ...baseScale(
                                'Service revenue / booked value (USD)',
                                (value) =>
                                    compactMoney(value)
                            ),

                            position: 'left'
                        },

                        yUtilization: {
                            ...baseScale(
                                'Utilization (%)',
                                (value) =>
                                    `${value}%`
                            ),

                            position: 'right',

                            grid: {
                                drawOnChartArea: false
                            },

                            suggestedMax:
                                Math.max(
                                    100,
                                    ...utilization
                                        .filter(
                                            (value) =>
                                                value !== null
                                        )
                                        .map(Number)
                                )
                        }
                    }
                }
            }
        );
    }

    /**
     * ------------------------------------------------------------
     * PROVIDER PRODUCTIVITY
     * ------------------------------------------------------------
     *
     * Horizontal chart:
     * - Revenue per scheduled hour
     * - Retail sales
     */
    function renderProviderProductivityChart() {
        const t = themeTokens();

        const providers =
            Array.isArray(payload.providers)
                ? payload.providers
                : [];

        addChart(
            'rumaProviderProductivityChart',
            {
                type: 'bar',

                data: {
                    labels:
                        providers.map(
                            (provider) =>
                                provider.provider ||
                                'Unknown'
                        ),

                    datasets: [
                        {
                            label:
                                'Revenue per scheduled hour',

                            data:
                                providers.map(
                                    (provider) =>
                                        provider.revenue_per_hour_available
                                            ? Number(
                                                provider.revenue_per_hour || 0
                                            )
                                            : null
                                ),

                            backgroundColor:
                                alpha(
                                    t.purple,
                                    0.76
                                ),

                            borderColor:
                                t.purple,

                            borderWidth: 1,
                            borderRadius: 7
                        },

                        {
                            label:
                                'Retail sales',

                            data:
                                providers.map(
                                    (provider) =>
                                        Number(
                                            provider.retail_sales || 0
                                        )
                                ),

                            backgroundColor:
                                alpha(
                                    t.orange,
                                    0.76
                                ),

                            borderColor:
                                t.orange,

                            borderWidth: 1,
                            borderRadius: 7
                        }
                    ]
                },

                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    indexAxis: 'y',

                    plugins: {
                        ...commonPlugins({
                            legend: true
                        }),

                        tooltip: {
                            ...commonPlugins({
                                legend: true
                            }).tooltip,

                            callbacks: {
                                label: (ctx) =>
                                    `${ctx.dataset.label}: ` +
                                    `${money(
                                        ctx.parsed.x,
                                        2
                                    )}`
                            }
                        }
                    },

                    scales: {
                        x: {
                            ...baseScale(
                                'USD',
                                (value) =>
                                    compactMoney(value)
                            )
                        },

                        y: {
                            grid: {
                                display: false
                            },

                            border: {
                                color: t.grid
                            },

                            title: {
                                display: true,
                                text: 'Provider',
                                color: t.muted,

                                font: {
                                    size: 11,
                                    weight: 600
                                }
                            },

                            ticks: {
                                color: t.text,
                                autoSkip: false,

                                font: {
                                    size: 10,
                                    weight: 600
                                }
                            }
                        }
                    }
                }
            }
        );
    }

    /**
     * ------------------------------------------------------------
     * MEMBERSHIP / MRR TREND
     * ------------------------------------------------------------
     */
    function renderMrrChart() {
        const t = themeTokens();

        const trend =
            Array.isArray(
                payload.membershipTrend
            )
                ? payload.membershipTrend
                : [];

        const rows =
            trend.filter(
                (row) =>
                    Number.isFinite(
                        Number(
                            row.mrr
                        )
                    )
            );

        addChart(
            'rumaMrrChart',
            {
                type: 'line',

                data: {
                    labels:
                        rows.map(
                            (row) =>
                                row.date ||
                                row.label ||
                                'Period'
                        ),

                    datasets: [
                        {
                            label:
                                'Active MRR',

                            data:
                                rows.map(
                                    (row) =>
                                        Number(
                                            row.mrr || 0
                                        )
                                ),

                            borderColor:
                                t.orange,

                            backgroundColor:
                                alpha(
                                    t.orange,
                                    0.13
                                ),

                            fill: true,
                            tension: 0.3,
                            pointRadius: 4,
                            pointHoverRadius: 6,

                            pointBackgroundColor:
                                t.orange,

                            pointBorderColor:
                                t.surface,

                            pointBorderWidth: 2
                        }
                    ]
                },

                options: {
                    responsive: true,
                    maintainAspectRatio: false,

                    plugins: {
                        ...commonPlugins({
                            legend: true
                        }),

                        tooltip: {
                            ...commonPlugins({
                                legend: true
                            }).tooltip,

                            callbacks: {
                                label: (ctx) =>
                                    `Active MRR: ` +
                                    `${money(
                                        ctx.parsed.y,
                                        2
                                    )}`
                            }
                        }
                    },

                    scales: {
                        x: {
                            grid: {
                                display: false
                            },

                            border: {
                                color: t.grid
                            },

                            title: {
                                display: true,
                                text:
                                    'Reporting period / date',
                                color: t.muted,

                                font: {
                                    size: 11,
                                    weight: 600
                                }
                            },

                            ticks: {
                                color: t.text,
                                autoSkip: false,
                                maxRotation: 35,
                                minRotation: 0,

                                font: {
                                    size: 10
                                }
                            }
                        },

                        y: baseScale(
                            'Monthly recurring revenue (USD)',
                            (value) =>
                                compactMoney(value)
                        )
                    }
                }
            }
        );
    }

    /**
     * ------------------------------------------------------------
     * REVENUE MIX
     * ------------------------------------------------------------
     */
    function renderRevenueMixChart() {
        const t = themeTokens();

        const mix =
            Array.isArray(
                payload.revenueMix
            )
                ? payload.revenueMix.filter(
                    (row) =>
                        row.available &&
                        Number(row.value) !== 0
                )
                : [];

        const values =
            mix.map(
                (row) =>
                    Number(
                        row.value || 0
                    )
            );

        const total =
            values.reduce(
                (sum, value) =>
                    sum + value,
                0
            );

        addChart(
            'rumaRevenueMixChart',
            {
                type: 'doughnut',

                data: {
                    labels:
                        mix.map(
                            (row) =>
                                row.label
                        ),

                    datasets: [
                        {
                            data:
                                values,

                            backgroundColor: [
                                t.primary,
                                t.accent,
                                t.orange,
                                t.purple,
                                t.secondary
                            ],

                            borderColor:
                                t.surface,

                            borderWidth: 3,
                            hoverOffset: 10
                        }
                    ]
                },

                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '62%',

                    plugins: {
                        legend: {
                            position: 'bottom',

                            labels: {
                                color: t.text,
                                usePointStyle: true,
                                padding: 18,

                                font: {
                                    size: 10,
                                    weight: 600
                                },

                                generateLabels(chart) {
                                    const data =
                                        chart.data;

                                    return (
                                        data.labels || []
                                    ).map(
                                        (label, index) => {
                                            const value =
                                                Number(
                                                    data.datasets[0]
                                                        .data[index]
                                                    || 0
                                                );

                                            const pct =
                                                total > 0
                                                    ? (
                                                        value /
                                                        total
                                                    ) * 100
                                                    : 0;

                                            return {
                                                text:
                                                    `${label} · ` +
                                                    `${number(
                                                        pct,
                                                        1
                                                    )}%`,

                                                fillStyle:
                                                    data.datasets[0]
                                                        .backgroundColor[index],

                                                strokeStyle:
                                                    data.datasets[0]
                                                        .backgroundColor[index],

                                                hidden: false,

                                                index
                                            };
                                        }
                                    );
                                }
                            }
                        },

                        tooltip: {
                            ...commonPlugins({
                                legend: true
                            }).tooltip,

                            callbacks: {
                                label: (ctx) => {
                                    const value =
                                        Number(
                                            ctx.raw || 0
                                        );

                                    const pct =
                                        total > 0
                                            ? (
                                                value /
                                                total
                                            ) * 100
                                            : 0;

                                    return (
                                        `${ctx.label}: ` +
                                        `${money(
                                            value,
                                            2
                                        )} ` +
                                        `(${number(
                                            pct,
                                            1
                                        )}%)`
                                    );
                                }
                            }
                        }
                    }
                }
            }
        );
    }

    /**
     * ------------------------------------------------------------
     * DAILY REVENUE + APPOINTMENTS
     * ------------------------------------------------------------
     */
    function renderDailyChart() {
        const t = themeTokens();

        const daily =
            Array.isArray(
                payload.daily
            )
                ? payload.daily
                : [];

        addChart(
            'rumaDailyPerformanceChart',
            {
                type: 'line',

                data: {
                    labels:
                        daily.map(
                            (row) =>
                                row.date || ''
                        ),

                    datasets: [
                        {
                            label:
                                'Daily revenue',

                            data:
                                daily.map(
                                    (row) =>
                                        Number(
                                            row.revenue || 0
                                        )
                                ),

                            yAxisID:
                                'yRevenue',

                            borderColor:
                                t.primary,

                            backgroundColor:
                                alpha(
                                    t.primary,
                                    0.12
                                ),

                            fill: true,
                            tension: 0.3,
                            pointRadius: 3,
                            pointHoverRadius: 5
                        },

                        {
                            label:
                                'Appointments',

                            data:
                                daily.map(
                                    (row) =>
                                        Number(
                                            row.appointments || 0
                                        )
                                ),

                            yAxisID:
                                'yAppointments',

                            borderColor:
                                t.accent,

                            backgroundColor:
                                t.accent,

                            fill: false,
                            tension: 0.25,
                            pointRadius: 3,
                            pointHoverRadius: 5
                        }
                    ]
                },

                options: {
                    responsive: true,
                    maintainAspectRatio: false,

                    interaction: {
                        mode: 'index',
                        intersect: false
                    },

                    plugins: {
                        ...commonPlugins({
                            legend: true
                        }),

                        tooltip: {
                            ...commonPlugins({
                                legend: true
                            }).tooltip,

                            callbacks: {
                                label: (ctx) => {
                                    if (
                                        ctx.dataset.yAxisID
                                        ===
                                        'yRevenue'
                                    ) {
                                        return (
                                            `Revenue: ` +
                                            `${money(
                                                ctx.parsed.y,
                                                2
                                            )}`
                                        );
                                    }

                                    return (
                                        `Appointments: ` +
                                        `${number(
                                            ctx.parsed.y,
                                            0
                                        )}`
                                    );
                                }
                            }
                        }
                    },

                    scales: {
                        x: {
                            grid: {
                                display: false
                            },

                            border: {
                                color: t.grid
                            },

                            title: {
                                display: true,
                                text: 'Date',
                                color: t.muted,

                                font: {
                                    size: 11,
                                    weight: 600
                                }
                            },

                            ticks: {
                                color: t.text,
                                autoSkip: false,
                                maxRotation: 45,
                                minRotation: 28,

                                font: {
                                    size: 10
                                }
                            }
                        },

                        yRevenue: {
                            ...baseScale(
                                'Daily revenue (USD)',
                                (value) =>
                                    compactMoney(value)
                            ),

                            position: 'left'
                        },

                        yAppointments: {
                            ...baseScale(
                                'Appointments',
                                (value) =>
                                    number(
                                        value,
                                        0
                                    )
                            ),

                            position: 'right',

                            grid: {
                                drawOnChartArea: false
                            }
                        }
                    }
                }
            }
        );
    }

    /**
     * Render every Priority Intelligence chart.
     */
    function renderCharts() {
        if (
            typeof window.Chart
            ===
            'undefined'
        ) {
            console.warn(
                'Chart.js is not loaded. ' +
                'RUMA Boulevard charts cannot be rendered.'
            );

            return;
        }

        destroyCharts();

        prepareScrollableCanvas();

        renderPerformanceChart();

        renderProviderPerformanceChart();

        renderProviderProductivityChart();

        renderMrrChart();

        renderRevenueMixChart();

        renderDailyChart();
    }

    /**
     * ------------------------------------------------------------
     * INTERACTIVE KPI CARDS
     * ------------------------------------------------------------
     *
     * Clicking a KPI card expands source / comparison
     * information contained in .ruma-kpi-details.
     */
    document
        .querySelectorAll(
            '[data-ruma-kpi]'
        )
        .forEach((card) => {
            const toggle = () => {
                const details =
                    card.querySelector(
                        '.ruma-kpi-details'
                    );

                if (!details) {
                    return;
                }

                const expanded =
                    card.getAttribute(
                        'aria-expanded'
                    )
                    ===
                    'true';

                card.setAttribute(
                    'aria-expanded',
                    expanded
                        ? 'false'
                        : 'true'
                );

                details.hidden =
                    expanded;

                card.classList.toggle(
                    'is-expanded',
                    !expanded
                );
            };

            card.addEventListener(
                'click',
                toggle
            );

            card.addEventListener(
                'keydown',
                (event) => {
                    if (
                        event.key === 'Enter'
                        ||
                        event.key === ' '
                    ) {
                        event.preventDefault();

                        toggle();
                    }
                }
            );
        });

    /**
     * ------------------------------------------------------------
     * API VS PDF UPLOAD COMPARISON PANEL
     * ------------------------------------------------------------
     */
    const compareToggle =
        document.getElementById(
            'boulevardCompareToggle'
        );

    const comparePanel =
        document.getElementById(
            'boulevardPdfComparePanel'
        );

    const compareClose =
        document.getElementById(
            'boulevardCompareClose'
        );

    if (
        compareToggle
        &&
        comparePanel
    ) {
        compareToggle.addEventListener(
            'click',
            () => {
                comparePanel.hidden = false;

                comparePanel.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
            }
        );
    }

    if (
        compareClose
        &&
        comparePanel
    ) {
        compareClose.addEventListener(
            'click',
            () => {
                comparePanel.hidden = true;
            }
        );
    }

    /**
     * Initial chart render.
     */
    renderCharts();

    /**
     * Re-render graphs if Aesthetic Intel changes theme.
     */
    window.addEventListener(
        'aestheticintel:themechange',
        () => {
            window.setTimeout(
                renderCharts,
                40
            );
        }
    );
})();