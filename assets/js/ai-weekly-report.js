(function () {
    'use strict';

    const CHART_JS_URL = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js';
    const chartInstances = [];

    let activePrintButton = null;
    let originalDocumentTitle = '';
    let printPrepared = false;
    let printCleanupTimer = null;

    document.addEventListener('DOMContentLoaded', function () {
        const dashboards = Array.from(
            document.querySelectorAll('[data-ai-weekly-dashboard]')
        );

        dashboards.forEach(initDashboard);
        initPrintExport(dashboards);
    });

    function initDashboard(root) {
        initRevealAnimations(root);
        initMetricTilt(root);

        const payloadNode = root.querySelector('[data-ai-weekly-chart-data]');

        if (!payloadNode) {
            root.__aiWeeklyChartsReady = Promise.resolve();
            return;
        }

        let payload;

        try {
            payload = JSON.parse(payloadNode.textContent || '{}');
        } catch (error) {
            console.error('AI Weekly Report chart payload is invalid.', error);
            root.__aiWeeklyChartsReady = Promise.resolve();
            return;
        }

        if (!Array.isArray(payload.charts) || !payload.charts.length) {
            root.__aiWeeklyChartsReady = Promise.resolve();
            return;
        }

        root.__aiWeeklyChartsReady = ensureChartJs()
            .then(function () {
                renderCharts(root, payload.charts);
                watchThemeChanges(root, payload.charts);
            })
            .catch(function (error) {
                console.error('Chart.js could not be loaded.', error);

                root.querySelectorAll('.aiw2-chart-canvas-wrap').forEach(function (wrap) {
                    wrap.innerHTML = '<p class="aiw2-chart-fallback">Chart unavailable. The source-supported insights remain available below.</p>';
                });
            });
    }

    function ensureChartJs() {
        if (window.Chart) {
            return Promise.resolve();
        }

        return new Promise(function (resolve, reject) {
            const existing = document.querySelector('script[data-aiw-chartjs]');

            if (existing) {
                if (existing.dataset.loaded === '1') {
                    resolve();
                    return;
                }

                existing.addEventListener('load', resolve, { once: true });
                existing.addEventListener('error', reject, { once: true });
                return;
            }

            const script = document.createElement('script');
            script.src = CHART_JS_URL;
            script.async = true;
            script.dataset.aiwChartjs = '1';
            script.onload = function () {
                script.dataset.loaded = '1';
                resolve();
            };
            script.onerror = reject;
            document.head.appendChild(script);
        });
    }

    function initRevealAnimations(root) {
        const elements = root.querySelectorAll('.aiw2-reveal');
        const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        if (reducedMotion || !('IntersectionObserver' in window)) {
            elements.forEach(function (element) {
                element.classList.add('is-visible');
            });
            return;
        }

        const observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) {
                    return;
                }

                entry.target.classList.add('is-visible');
                observer.unobserve(entry.target);
            });
        }, {
            threshold: 0.12,
            rootMargin: '0px 0px -40px 0px'
        });

        elements.forEach(function (element) {
            observer.observe(element);
        });
    }

    function initMetricTilt(root) {
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            return;
        }

        if (!window.matchMedia('(pointer: fine)').matches) {
            return;
        }

        root.querySelectorAll('.aiw2-metric').forEach(function (card) {
            card.addEventListener('pointermove', function (event) {
                const rect = card.getBoundingClientRect();
                const x = (event.clientX - rect.left) / rect.width - 0.5;
                const y = (event.clientY - rect.top) / rect.height - 0.5;

                card.style.setProperty('--tilt-x', (y * -2.2).toFixed(2) + 'deg');
                card.style.setProperty('--tilt-y', (x * 2.2).toFixed(2) + 'deg');
            });

            card.addEventListener('pointerleave', function () {
                card.style.removeProperty('--tilt-x');
                card.style.removeProperty('--tilt-y');
            });
        });
    }

    function palette(root) {
        const styles = getComputedStyle(root);
        const dark = document.documentElement.dataset.theme === 'dark';

        return {
            purple: styles.getPropertyValue('--aiw-purple').trim() || '#6d5dfc',
            coral: styles.getPropertyValue('--aiw-coral').trim() || '#e36f61',
            green: styles.getPropertyValue('--aiw-green').trim() || '#3ba776',
            amber: styles.getPropertyValue('--aiw-amber').trim() || '#d69a45',
            blue: styles.getPropertyValue('--aiw-blue').trim() || '#4f88d9',
            text: dark ? '#e9e6ed' : '#353039',
            muted: dark ? '#aaa4b0' : '#77717d',
            grid: dark ? 'rgba(255,255,255,.08)' : 'rgba(50,40,55,.08)',
            tooltipBg: dark ? '#242127' : '#17151a'
        };
    }

    function renderCharts(root, charts) {
        destroyCharts();

        const colors = palette(root);
        const seriesColors = [
            colors.purple,
            colors.coral,
            colors.green,
            colors.amber,
            colors.blue
        ];

        charts.forEach(function (chart, index) {
            const canvas = root.querySelector(
                '[data-ai-weekly-chart-index="' + index + '"]'
            );

            if (!canvas) {
                return;
            }

            const datasets = (chart.datasets || []).map(function (dataset, datasetIndex) {
                const color = seriesColors[datasetIndex % seriesColors.length];
                const base = {
                    label: dataset.label || 'Value',
                    data: dataset.values || [],
                    borderColor: color,
                    backgroundColor: hexToRgba(
                        color,
                        chart.type === 'line' ? 0.16 : 0.72
                    ),
                    hoverBackgroundColor: hexToRgba(color, 0.9),
                    borderWidth: chart.type === 'line' ? 2.5 : 1,
                    borderRadius: chart.type === 'bar' ? 9 : 0,
                    tension: 0.36,
                    fill: chart.type === 'line',
                    pointRadius: chart.type === 'line' ? 3 : 0,
                    pointHoverRadius: chart.type === 'line' ? 6 : 0
                };

                if (chart.type === 'doughnut') {
                    base.backgroundColor = (dataset.values || []).map(function (_, valueIndex) {
                        return seriesColors[valueIndex % seriesColors.length];
                    });
                    base.hoverBackgroundColor = base.backgroundColor;
                    base.borderWidth = 0;
                }

                return base;
            });

            const options = chartOptions(chart, colors);
            const instance = new window.Chart(canvas.getContext('2d'), {
                type: chart.type,
                data: {
                    labels: chart.labels || [],
                    datasets: datasets
                },
                options: options
            });

            chartInstances.push(instance);
        });
    }

    function chartOptions(chart, colors) {
        const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        const formatValue = valueFormatter(chart.value_format || 'number');

        const options = {
            responsive: true,
            maintainAspectRatio: false,
            animation: reducedMotion ? false : {
                duration: 900,
                easing: 'easeOutQuart'
            },
            interaction: {
                mode: chart.type === 'doughnut' ? 'nearest' : 'index',
                intersect: false
            },
            plugins: {
                legend: {
                    display: true,
                    position: chart.type === 'doughnut' ? 'bottom' : 'top',
                    align: 'start',
                    labels: {
                        color: colors.muted,
                        usePointStyle: true,
                        pointStyle: 'circle',
                        padding: 16,
                        boxWidth: 8,
                        boxHeight: 8,
                        font: {
                            size: 11,
                            weight: '600'
                        }
                    }
                },
                tooltip: {
                    backgroundColor: colors.tooltipBg,
                    titleColor: '#fff',
                    bodyColor: '#fff',
                    padding: 12,
                    cornerRadius: 10,
                    displayColors: true,
                    callbacks: {
                        label: function (context) {
                            const label = context.dataset.label
                                ? context.dataset.label + ': '
                                : '';

                            return label + formatValue(context.raw);
                        }
                    }
                }
            }
        };

        if (chart.type === 'doughnut') {
            options.cutout = '68%';
            return options;
        }

        options.scales = {
            x: {
                grid: {
                    display: false
                },
                border: {
                    display: false
                },
                ticks: {
                    color: colors.muted,
                    maxRotation: 0,
                    autoSkip: false,
                    font: {
                        size: 10
                    }
                }
            },
            y: {
                beginAtZero: true,
                grid: {
                    color: colors.grid
                },
                border: {
                    display: false
                },
                ticks: {
                    color: colors.muted,
                    callback: function (value) {
                        return formatValue(value);
                    },
                    font: {
                        size: 10
                    }
                }
            }
        };

        return options;
    }

    function valueFormatter(format) {
        return function (value) {
            const numeric = Number(value);

            if (!Number.isFinite(numeric)) {
                return String(value);
            }

            if (format === 'currency') {
                return new Intl.NumberFormat('en-US', {
                    style: 'currency',
                    currency: 'USD',
                    maximumFractionDigits: numeric >= 1000 ? 0 : 2,
                    notation: Math.abs(numeric) >= 1000000 ? 'compact' : 'standard'
                }).format(numeric);
            }

            if (format === 'percent') {
                return new Intl.NumberFormat('en-US', {
                    maximumFractionDigits: 1
                }).format(numeric) + '%';
            }

            return new Intl.NumberFormat('en-US', {
                maximumFractionDigits: 1,
                notation: Math.abs(numeric) >= 1000000 ? 'compact' : 'standard'
            }).format(numeric);
        };
    }

    function hexToRgba(hex, alpha) {
        const clean = String(hex).replace('#', '');

        if (!/^[0-9a-f]{6}$/i.test(clean)) {
            return hex;
        }

        const number = parseInt(clean, 16);
        const red = (number >> 16) & 255;
        const green = (number >> 8) & 255;
        const blue = number & 255;

        return 'rgba(' + red + ',' + green + ',' + blue + ',' + alpha + ')';
    }

    function destroyCharts() {
        while (chartInstances.length) {
            const chart = chartInstances.pop();

            if (chart && typeof chart.destroy === 'function') {
                chart.destroy();
            }
        }
    }

    function watchThemeChanges(root, charts) {
        if (!window.MutationObserver) {
            return;
        }

        let timer = null;

        const observer = new MutationObserver(function () {
            clearTimeout(timer);

            timer = setTimeout(function () {
                if (!document.body.classList.contains('ai-weekly-printing')) {
                    renderCharts(root, charts);
                }
            }, 80);
        });

        observer.observe(document.documentElement, {
            attributes: true,
            attributeFilter: ['data-theme']
        });
    }

    /* =========================================================
       PRINT / SAVE PDF
       ========================================================= */

    function initPrintExport(dashboards) {
        const buttons = Array.from(
            document.querySelectorAll('[data-ai-weekly-print]')
        );

        buttons.forEach(function (button) {
            button.addEventListener('click', function () {
                startPrint(button, dashboards);
            });
        });

        window.addEventListener('beforeprint', function () {
            preparePrintState(activePrintButton, dashboards);
        });

        window.addEventListener('afterprint', function () {
            cleanupPrintState();
        });
    }

    async function startPrint(button, dashboards) {
        if (button.disabled) {
            return;
        }

        const originalText = button.textContent;
        activePrintButton = button;
        button.disabled = true;
        button.dataset.aiWeeklyOriginalText = originalText;
        button.textContent = 'Preparing PDF…';

        try {
            await Promise.all(
                dashboards.map(function (root) {
                    return root.__aiWeeklyChartsReady || Promise.resolve();
                })
            );

            preparePrintState(button, dashboards);

            await nextPaint();
            await nextPaint();

            button.textContent = 'Opening print dialog…';

            window.print();

            clearTimeout(printCleanupTimer);
            printCleanupTimer = setTimeout(function () {
                cleanupPrintState();
            }, 800);
        } catch (error) {
            console.error('Unable to prepare the weekly report for printing.', error);
            cleanupPrintState();
            window.alert('The report could not be prepared for printing. Please try again.');
        }
    }

    function preparePrintState(button, dashboards) {
        if (printPrepared) {
            return;
        }

        printPrepared = true;
        document.body.classList.add('ai-weekly-printing');

        dashboards.forEach(function (root) {
            root.querySelectorAll('.aiw2-reveal').forEach(function (element) {
                element.classList.add('is-visible');
            });

            root.querySelectorAll('.aiw2-rationale').forEach(function (details) {
                if (!details.open) {
                    details.dataset.aiWeeklyPrintOpened = '1';
                    details.open = true;
                }
            });
        });

        freezeChartsForPrint();
        setPrintDocumentTitle(button, dashboards);
    }

    function freezeChartsForPrint() {
        chartInstances.forEach(function (chart) {
            try {
                if (typeof chart.stop === 'function') {
                    chart.stop();
                }

                if (typeof chart.resize === 'function') {
                    chart.resize();
                }

                if (typeof chart.update === 'function') {
                    chart.update('none');
                }
            } catch (error) {
                console.warn('A weekly report chart could not be frozen for print.', error);
            }
        });

        document.querySelectorAll('[data-ai-weekly-dashboard] canvas[data-ai-weekly-chart-index]')
            .forEach(function (canvas) {
                const wrap = canvas.closest('.aiw2-chart-canvas-wrap');

                if (!wrap || wrap.querySelector('.aiw2-print-chart-image')) {
                    return;
                }

                try {
                    const exportCanvas = document.createElement('canvas');
                    exportCanvas.width = canvas.width;
                    exportCanvas.height = canvas.height;

                    const exportContext = exportCanvas.getContext('2d');

                    if (!exportContext) {
                        return;
                    }

                    exportContext.fillStyle = '#ffffff';
                    exportContext.fillRect(0, 0, exportCanvas.width, exportCanvas.height);
                    exportContext.drawImage(canvas, 0, 0);

                    const image = document.createElement('img');
                    image.className = 'aiw2-print-chart-image';
                    image.alt = canvas.getAttribute('aria-label') || 'Weekly performance chart';
                    image.src = exportCanvas.toDataURL('image/png', 1.0);

                    wrap.appendChild(image);
                    wrap.classList.add('has-print-image');
                } catch (error) {
                    console.warn('A weekly report chart could not be converted for print.', error);
                }
            });
    }

    function setPrintDocumentTitle(button, dashboards) {
        if (!originalDocumentTitle) {
            originalDocumentTitle = document.title;
        }

        const root = dashboards[0] || null;
        const business = (
            (button && button.dataset.reportBusiness)
            || (root && root.dataset.reportBusiness)
            || 'Business'
        );
        const start = (
            (button && button.dataset.reportStart)
            || (root && root.dataset.reportStart)
            || ''
        );
        const end = (
            (button && button.dataset.reportEnd)
            || (root && root.dataset.reportEnd)
            || ''
        );

        const parts = [
            business,
            'AI Weekly Report'
        ];

        if (start && end) {
            parts.push(start + ' to ' + end);
        }

        document.title = sanitizeFilename(parts.join(' - '));
    }

    function sanitizeFilename(value) {
        return String(value)
            .replace(/[\\/:*?"<>|]+/g, '-')
            .replace(/\s+/g, ' ')
            .trim()
            .slice(0, 140);
    }

    function cleanupPrintState() {
        clearTimeout(printCleanupTimer);
        printCleanupTimer = null;

        document.body.classList.remove('ai-weekly-printing');

        document.querySelectorAll('.aiw2-print-chart-image').forEach(function (image) {
            const wrap = image.closest('.aiw2-chart-canvas-wrap');
            image.remove();

            if (wrap) {
                wrap.classList.remove('has-print-image');
            }
        });

        document.querySelectorAll('.aiw2-rationale[data-ai-weekly-print-opened="1"]')
            .forEach(function (details) {
                details.open = false;
                delete details.dataset.aiWeeklyPrintOpened;
            });

        if (originalDocumentTitle) {
            document.title = originalDocumentTitle;
            originalDocumentTitle = '';
        }

        if (activePrintButton) {
            activePrintButton.disabled = false;
            activePrintButton.textContent =
                activePrintButton.dataset.aiWeeklyOriginalText || 'Print / Save PDF';
            delete activePrintButton.dataset.aiWeeklyOriginalText;
        }

        activePrintButton = null;
        printPrepared = false;
    }

    function nextPaint() {
        return new Promise(function (resolve) {
            window.requestAnimationFrame(function () {
                resolve();
            });
        });
    }
})();
