(function () {
  'use strict';

  function parseJson(id) {
    var node = document.getElementById(id);
    if (!node) return null;
    try { return JSON.parse(node.textContent || '{}'); } catch (error) { return null; }
  }

  function cssVar(name, fallback) {
    var value = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
    return value || fallback;
  }

  function formatValue(value, format) {
    if (value === null || typeof value === 'undefined') return '—';
    var number = Number(value);
    if (!Number.isFinite(number)) return '—';
    if (format === 'currency') return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD', maximumFractionDigits: 2 }).format(number);
    if (format === 'percent') return number.toFixed(1) + '%';
    if (format === 'hours') return number.toFixed(1) + ' hrs';
    return new Intl.NumberFormat('en-US', { maximumFractionDigits: 2 }).format(number);
  }

  function chartOptions(format) {
    return {
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { display: false },
        tooltip: { callbacks: { label: function (context) { return formatValue(context.parsed.y, format); } } }
      },
      scales: {
        x: { grid: { display: false }, ticks: { color: cssVar('--muted', '#6b7280') } },
        y: {
          beginAtZero: false,
          grid: { color: cssVar('--border', '#e5e7eb') },
          ticks: { color: cssVar('--muted', '#6b7280'), callback: function (value) { return formatValue(value, format); } }
        }
      }
    };
  }

  function renderProviderTrend() {
    var payload = parseJson('providerKpiTrendData');
    var canvas = document.getElementById('providerTrendChart');
    if (!payload || !canvas || typeof Chart === 'undefined') return;
    var metricSelect = document.querySelector('[data-provider-trend-metric]');
    var periodButtons = Array.prototype.slice.call(document.querySelectorAll('[data-provider-trend-period]'));
    var metric = metricSelect && metricSelect.value ? metricSelect.value : Object.keys(payload.series || {})[0];
    var period = 12;
    var chart = null;

    function draw() {
      var entry = payload.series[metric];
      if (!entry) return;
      var labels = (payload.labels || []).slice(-period);
      var values = (entry.values || []).slice(-period);
      if (chart) chart.destroy();
      chart = new Chart(canvas, {
        type: 'line',
        data: {
          labels: labels,
          datasets: [{
            label: entry.label,
            data: values,
            borderColor: cssVar('--accent', '#4f46e5'),
            backgroundColor: 'transparent',
            borderWidth: 3,
            tension: 0.28,
            pointRadius: 4,
            pointHoverRadius: 6,
            spanGaps: true
          }]
        },
        options: chartOptions(entry.format)
      });
    }

    if (metricSelect) metricSelect.addEventListener('change', function () { metric = metricSelect.value; draw(); });
    periodButtons.forEach(function (button) {
      button.addEventListener('click', function () {
        period = Math.max(3, Math.min(12, Number(button.getAttribute('data-provider-trend-period')) || 12));
        periodButtons.forEach(function (item) { item.classList.toggle('active', item === button); });
        draw();
      });
    });
    draw();
    document.addEventListener('aesthetic-theme-change', draw);
  }

  function renderDrilldownTrend() {
    var payload = parseJson('providerDrilldownTrendData');
    var canvas = document.getElementById('providerDrilldownTrendChart');
    if (!payload || !canvas || typeof Chart === 'undefined') return;
    var key = Object.keys(payload.series || {})[0];
    var entry = key ? payload.series[key] : null;
    if (!entry) return;
    new Chart(canvas, {
      type: 'line',
      data: {
        labels: payload.labels || [],
        datasets: [{
          label: entry.label,
          data: entry.values || [],
          borderColor: cssVar('--accent', '#4f46e5'),
          backgroundColor: 'transparent',
          borderWidth: 3,
          tension: 0.28,
          pointRadius: 4,
          pointHoverRadius: 6,
          spanGaps: true
        }]
      },
      options: chartOptions(entry.format)
    });
  }

  renderProviderTrend();
  renderDrilldownTrend();
})();
