(() => {
    'use strict';

    const dataNode = document.getElementById('rumaBoulevardPriorityChartData');
    if (!dataNode) return;

    let payload = {};
    try {
        payload = JSON.parse(dataNode.textContent || '{}');
    } catch (_) {
        return;
    }

    const css = getComputedStyle(document.querySelector('.pi-page') || document.documentElement);
    const palette = [
        '#1f7a4d', '#315f8c', '#9a6517', '#7d5ba6', '#a24747',
        '#3d7a7a', '#8a6d3b', '#5d6d7e', '#8b5e83', '#4c7c59'
    ];
    const textColor = css.getPropertyValue('--pi-text').trim() || '#17171a';
    const mutedColor = css.getPropertyValue('--pi-muted').trim() || '#74747c';
    const borderColor = css.getPropertyValue('--pi-border').trim() || '#e5e5e9';
    const cardColor = css.getPropertyValue('--pi-card').trim() || '#ffffff';
    const accentColor = css.getPropertyValue('--pi-success').trim() || '#1f7a4d';

    const n = (value) => {
        if (value && typeof value === 'object') {
            for (const key of ['value', 'amount', 'total', 'currentTotal', 'count']) {
                if (Object.prototype.hasOwnProperty.call(value, key)) {
                    return n(value[key]);
                }
            }
        }
        const parsed = Number(value);
        return Number.isFinite(parsed) ? parsed : null;
    };

    const money = (value) => {
        const v = n(value);
        if (v === null) return '—';
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: 'USD',
            maximumFractionDigits: 0,
        }).format(v);
    };

    const shortNumber = (value) => {
        const v = n(value);
        if (v === null) return '—';
        return new Intl.NumberFormat('en-US', {
            notation: 'compact',
            maximumFractionDigits: 1,
        }).format(v);
    };

    function prepareCanvas(canvas) {
        const rect = canvas.getBoundingClientRect();
        const dpr = Math.max(1, window.devicePixelRatio || 1);
        const width = Math.max(280, Math.round(rect.width));
        const height = Math.max(220, Math.round(rect.height));
        canvas.width = Math.round(width * dpr);
        canvas.height = Math.round(height * dpr);
        const ctx = canvas.getContext('2d');
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        ctx.clearRect(0, 0, width, height);
        return { ctx, width, height };
    }

    function drawEmpty(canvas, message) {
        const { ctx, width, height } = prepareCanvas(canvas);
        ctx.fillStyle = mutedColor;
        ctx.font = '13px system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillText(message, width / 2, height / 2);
    }

    function drawDonut(canvas, rows) {
        rows = Array.isArray(rows)
            ? rows.filter(row => n(row && row.value) !== null && n(row.value) > 0)
            : [];

        if (!rows.length) {
            drawEmpty(canvas, 'Revenue mix is not available for this period.');
            return;
        }

        const { ctx, width, height } = prepareCanvas(canvas);
        const total = rows.reduce((sum, row) => sum + n(row.value), 0);
        const centerX = Math.min(width * 0.36, 175);
        const centerY = height / 2;
        const radius = Math.min(92, height * 0.34, width * 0.23);
        const inner = radius * 0.62;
        let angle = -Math.PI / 2;

        rows.forEach((row, index) => {
            const value = n(row.value);
            const slice = total > 0 ? (value / total) * Math.PI * 2 : 0;
            ctx.beginPath();
            ctx.arc(centerX, centerY, radius, angle, angle + slice);
            ctx.arc(centerX, centerY, inner, angle + slice, angle, true);
            ctx.closePath();
            ctx.fillStyle = palette[index % palette.length];
            ctx.fill();
            angle += slice;
        });

        ctx.fillStyle = textColor;
        ctx.textAlign = 'center';
        ctx.font = '600 18px system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
        ctx.fillText(money(total), centerX, centerY - 2);
        ctx.fillStyle = mutedColor;
        ctx.font = '12px system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
        ctx.fillText('classified revenue', centerX, centerY + 20);

        const legendX = Math.max(centerX + radius + 35, width * 0.58);
        let y = 34;
        rows.slice(0, 7).forEach((row, index) => {
            const value = n(row.value);
            const pct = total > 0 ? (value / total) * 100 : 0;
            ctx.fillStyle = palette[index % palette.length];
            ctx.fillRect(legendX, y - 8, 10, 10);
            ctx.fillStyle = textColor;
            ctx.textAlign = 'left';
            ctx.font = '600 12px system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
            ctx.fillText(String(row.label || 'Revenue'), legendX + 18, y);
            ctx.fillStyle = mutedColor;
            ctx.font = '11px system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
            ctx.fillText(`${money(value)} · ${pct.toFixed(1)}%`, legendX + 18, y + 17);
            y += 45;
        });
    }

    function drawHorizontalBars(canvas, rows) {
        rows = Array.isArray(rows)
            ? rows.filter(row => n(row && row.value) !== null && n(row.value) >= 0)
            : [];

        if (!rows.length) {
            drawEmpty(canvas, 'Provider appointment data is not available.');
            return;
        }

        const { ctx, width, height } = prepareCanvas(canvas);
        const left = Math.min(170, Math.max(110, width * 0.28));
        const right = 46;
        const top = 18;
        const bottom = 24;
        const chartW = width - left - right;
        const rowH = (height - top - bottom) / rows.length;
        const max = Math.max(...rows.map(row => n(row.value) || 0), 1);

        rows.forEach((row, index) => {
            const value = n(row.value) || 0;
            const y = top + index * rowH + rowH / 2;
            const barH = Math.min(18, rowH * 0.52);
            const barW = chartW * (value / max);

            ctx.fillStyle = mutedColor;
            ctx.textAlign = 'right';
            ctx.textBaseline = 'middle';
            ctx.font = '11px system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
            const label = String(row.label || 'Provider');
            const clipped = label.length > 21 ? label.slice(0, 20) + '…' : label;
            ctx.fillText(clipped, left - 12, y);

            ctx.fillStyle = borderColor;
            ctx.fillRect(left, y - barH / 2, chartW, barH);
            ctx.fillStyle = palette[index % palette.length];
            ctx.fillRect(left, y - barH / 2, Math.max(2, barW), barH);

            ctx.fillStyle = textColor;
            ctx.textAlign = 'left';
            ctx.font = '600 11px system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
            ctx.fillText(String(Math.round(value)), Math.min(width - 30, left + barW + 8), y);
        });
    }

    function dailyValue(row, candidates) {
        for (const key of candidates) {
            if (row && Object.prototype.hasOwnProperty.call(row, key)) {
                const value = n(row[key]);
                if (value !== null) return value;
            }
        }
        return null;
    }

    function dailyLabel(row, index) {
        for (const key of ['date', 'day', 'period', 'label', 'period_start']) {
            if (row && row[key]) {
                const raw = String(row[key]);
                const dt = new Date(raw);
                if (!Number.isNaN(dt.getTime())) {
                    return dt.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
                }
                return raw;
            }
        }
        return String(index + 1);
    }

    function drawDaily(canvas, rawRows) {
        const rows = Array.isArray(rawRows) ? rawRows.filter(row => row && typeof row === 'object') : [];
        if (!rows.length) {
            drawEmpty(canvas, 'Daily trend becomes available when daily report data exists.');
            return;
        }

        const normalized = rows.map((row, index) => ({
            label: dailyLabel(row, index),
            revenue: dailyValue(row, ['revenue', 'total_revenue', 'totalRevenue', 'sales', 'service_revenue']),
            appointments: dailyValue(row, ['appointments', 'appointment_count', 'total_appointments', 'appointmentCount', 'count']),
        }));

        const hasRevenue = normalized.some(row => row.revenue !== null);
        const hasAppointments = normalized.some(row => row.appointments !== null);
        if (!hasRevenue && !hasAppointments) {
            drawEmpty(canvas, 'Daily rows exist, but no chartable revenue or appointment fields were found.');
            return;
        }

        const { ctx, width, height } = prepareCanvas(canvas);
        const left = 54;
        const right = 42;
        const top = 28;
        const bottom = 48;
        const chartW = width - left - right;
        const chartH = height - top - bottom;
        const revMax = Math.max(...normalized.map(row => row.revenue || 0), 1);
        const apptMax = Math.max(...normalized.map(row => row.appointments || 0), 1);
        const stepX = normalized.length > 1 ? chartW / (normalized.length - 1) : chartW;

        ctx.strokeStyle = borderColor;
        ctx.lineWidth = 1;
        for (let i = 0; i <= 4; i++) {
            const y = top + (chartH / 4) * i;
            ctx.beginPath();
            ctx.moveTo(left, y);
            ctx.lineTo(width - right, y);
            ctx.stroke();
        }

        ctx.fillStyle = mutedColor;
        ctx.textAlign = 'right';
        ctx.font = '10px system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
        for (let i = 0; i <= 4; i++) {
            const value = revMax * (1 - i / 4);
            ctx.fillText(shortNumber(value), left - 8, top + (chartH / 4) * i + 3);
        }

        if (hasAppointments) {
            normalized.forEach((row, index) => {
                if (row.appointments === null) return;
                const x = normalized.length > 1 ? left + index * stepX : left + chartW / 2;
                const barW = Math.max(5, Math.min(22, chartW / Math.max(normalized.length * 2, 1)));
                const barH = chartH * (row.appointments / apptMax);
                ctx.globalAlpha = 0.18;
                ctx.fillStyle = '#315f8c';
                ctx.fillRect(x - barW / 2, top + chartH - barH, barW, barH);
                ctx.globalAlpha = 1;
            });
        }

        if (hasRevenue) {
            ctx.strokeStyle = accentColor;
            ctx.lineWidth = 2.5;
            ctx.beginPath();
            let started = false;
            normalized.forEach((row, index) => {
                if (row.revenue === null) return;
                const x = normalized.length > 1 ? left + index * stepX : left + chartW / 2;
                const y = top + chartH - chartH * (row.revenue / revMax);
                if (!started) {
                    ctx.moveTo(x, y);
                    started = true;
                } else {
                    ctx.lineTo(x, y);
                }
            });
            ctx.stroke();

            normalized.forEach((row, index) => {
                if (row.revenue === null) return;
                const x = normalized.length > 1 ? left + index * stepX : left + chartW / 2;
                const y = top + chartH - chartH * (row.revenue / revMax);
                ctx.beginPath();
                ctx.arc(x, y, 3, 0, Math.PI * 2);
                ctx.fillStyle = accentColor;
                ctx.fill();
            });
        }

        const labelEvery = Math.max(1, Math.ceil(normalized.length / 8));
        ctx.fillStyle = mutedColor;
        ctx.textAlign = 'center';
        ctx.font = '10px system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
        normalized.forEach((row, index) => {
            if (index % labelEvery !== 0 && index !== normalized.length - 1) return;
            const x = normalized.length > 1 ? left + index * stepX : left + chartW / 2;
            ctx.fillText(row.label, x, height - 20);
        });

        ctx.textAlign = 'left';
        ctx.fillStyle = accentColor;
        ctx.fillRect(left, 8, 16, 3);
        ctx.fillStyle = textColor;
        ctx.font = '11px system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
        ctx.fillText('Revenue', left + 23, 12);
        if (hasAppointments) {
            ctx.globalAlpha = 0.35;
            ctx.fillStyle = '#315f8c';
            ctx.fillRect(left + 88, 6, 10, 8);
            ctx.globalAlpha = 1;
            ctx.fillStyle = textColor;
            ctx.fillText('Appointments', left + 105, 12);
        }
    }

    const render = () => {
        const revenueCanvas = document.getElementById('piRevenueMixChart');
        if (revenueCanvas) drawDonut(revenueCanvas, payload.revenueMix || []);

        const providerCanvas = document.getElementById('piProviderChart');
        if (providerCanvas) drawHorizontalBars(providerCanvas, payload.providers || []);

        const dailyCanvas = document.getElementById('piDailyChart');
        if (dailyCanvas) drawDaily(dailyCanvas, payload.daily || []);
    };

    let resizeTimer = null;
    window.addEventListener('resize', () => {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(render, 120);
    });

    render();
})();
