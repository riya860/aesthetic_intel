(() => {
  'use strict';

  const dataElement = document.getElementById('dashboardData');
  if (!dataElement || typeof Chart === 'undefined') return;

  let payload;
  try {
    payload = JSON.parse(dataElement.textContent || '{}');
  } catch (error) {
    console.error('Unable to read dashboard data.', error);
    return;
  }

  const dashboard = payload.dashboard || {};
  const kpis = dashboard.kpis || {};
  const providers = dashboard.providers || [];
  const visibleKpis = new Set(Array.isArray(payload.visibleKpis) ? payload.visibleKpis : Object.keys(kpis));
  const charts = [];

  const money = (value) => new Intl.NumberFormat('en-US', {
    style: 'currency', currency: 'USD', maximumFractionDigits: 0
  }).format(Number(value || 0));

  const number = (value) => new Intl.NumberFormat('en-US', { maximumFractionDigits: 1 }).format(Number(value || 0));

  function themeTokens() {
    const style = getComputedStyle(document.documentElement);
    return {
      text: style.getPropertyValue('--chart-text').trim() || '#344054',
      grid: style.getPropertyValue('--chart-grid').trim() || 'rgba(15, 23, 42, .10)',
      primary: style.getPropertyValue('--brand-primary').trim() || '#12336b',
      accent: style.getPropertyValue('--brand-accent').trim() || '#0f766e',
      secondary: style.getPropertyValue('--chart-secondary').trim() || '#a9b5c7',
      orange: style.getPropertyValue('--chart-orange').trim() || '#e67e22',
      purple: style.getPropertyValue('--chart-purple').trim() || '#6d4aa2',
      red: style.getPropertyValue('--danger').trim() || '#c43d3d'
    };
  }

  function baseOptions({ horizontal = false, currency = false, percent = false, legend = false } = {}) {
    const t = themeTokens();
    return {
      responsive: true,
      maintainAspectRatio: false,
      animation: { duration: 600 },
      indexAxis: horizontal ? 'y' : 'x',
      plugins: {
        legend: { display: legend, labels: { color: t.text, boxWidth: 12, usePointStyle: true } },
        tooltip: {
          backgroundColor: '#111827',
          titleColor: '#fff',
          bodyColor: '#fff',
          padding: 10,
          callbacks: {
            label: (context) => {
              const value = horizontal ? context.parsed.x : context.parsed.y;
              if (currency) return `${context.dataset.label ? context.dataset.label + ': ' : ''}${money(value)}`;
              if (percent) return `${context.dataset.label ? context.dataset.label + ': ' : ''}${number(value)}%`;
              return `${context.dataset.label ? context.dataset.label + ': ' : ''}${number(value)}`;
            }
          }
        }
      },
      scales: {
        x: {
          grid: { color: horizontal ? 'transparent' : t.grid },
          ticks: {
            color: t.text,
            callback: (value) => currency ? money(value) : percent ? `${value}%` : number(value)
          },
          border: { color: t.grid }
        },
        y: {
          beginAtZero: true,
          grid: { color: horizontal ? t.grid : 'transparent' },
          ticks: {
            color: t.text,
            callback: (value) => !horizontal && currency ? money(value) : !horizontal && percent ? `${value}%` : value
          },
          border: { color: t.grid }
        }
      }
    };
  }

  function addChart(id, config) {
    const canvas = document.getElementById(id);
    if (!canvas) return;
    charts.push(new Chart(canvas, config));
  }

  function currentPrevious(keys) {
    const filtered = keys.filter((key) => visibleKpis.has(key) && kpis[key] && kpis[key].value !== null && kpis[key].value !== '');
    return {
      keys: filtered,
      labels: filtered.map((key) => kpis[key]?.label || key),
      current: filtered.map((key) => Number(kpis[key]?.value || 0)),
      previous: filtered.map((key) => kpis[key]?.previous === null || kpis[key]?.previous === undefined ? null : Number(kpis[key].previous))
    };
  }

  function renderCharts() {
    const t = themeTokens();
    const comparison = currentPrevious(['total_revenue', 'service_revenue', 'retail_revenue', 'membership_revenue']);
    const comparisonDatasets = [
      { label: 'Current Period', data: comparison.current, backgroundColor: t.primary, borderRadius: 7 }
    ];
    if (comparison.previous.some((value) => value !== null)) {
      comparisonDatasets.push({ label: 'Previous Period', data: comparison.previous, backgroundColor: t.secondary, borderRadius: 7 });
    }
    addChart('comparisonChart', {
      type: 'bar',
      data: { labels: comparison.labels, datasets: comparisonDatasets },
      options: baseOptions({ currency: true, legend: comparisonDatasets.length > 1 })
    });

    const topProviders = providers.slice(0, 8);
    addChart('providerRevenueChart', {
      type: 'bar',
      data: { labels: topProviders.map((p) => p.name), datasets: [{ label: 'Service Revenue', data: topProviders.map((p) => p.service_revenue), backgroundColor: t.primary, borderRadius: 6 }] },
      options: baseOptions({ horizontal: true, currency: true })
    });

    const utilizationProviders = [...providers].sort((a, b) => Number(b.utilization) - Number(a.utilization)).slice(0, 8);
    addChart('providerUtilizationChart', {
      type: 'bar',
      data: { labels: utilizationProviders.map((p) => p.name), datasets: [{ label: 'Utilization', data: utilizationProviders.map((p) => p.utilization), backgroundColor: t.accent, borderRadius: 6 }] },
      options: { ...baseOptions({ horizontal: true, percent: true }), scales: { ...baseOptions({ horizontal: true, percent: true }).scales, x: { ...baseOptions({ horizontal: true, percent: true }).scales.x, suggestedMax: 100 } } }
    });

    const revHourProviders = [...providers].sort((a, b) => Number(b.revenue_per_hour) - Number(a.revenue_per_hour)).slice(0, 10);
    addChart('revenuePerHourChart', {
      type: 'bar',
      data: { labels: revHourProviders.map((p) => p.name), datasets: [{ label: 'Revenue / Hour', data: revHourProviders.map((p) => p.revenue_per_hour), backgroundColor: t.purple, borderRadius: 6 }] },
      options: baseOptions({ horizontal: true, currency: true })
    });

    const mrrHistory = Array.isArray(payload.mrrHistory) ? payload.mrrHistory : [];
    const mrrLabels = mrrHistory.length ? mrrHistory.map((row) => row.label || row.period || '') : ['Current'];
    const mrrValues = mrrHistory.length ? mrrHistory.map((row) => Number(row.value ?? row.mrr ?? 0)) : [Number(kpis.active_mrr?.value || 0)];
    addChart('mrrChart', {
      type: 'line',
      data: {
        labels: mrrLabels,
        datasets: [{ label: 'Active MRR', data: mrrValues, borderColor: t.orange, backgroundColor: `${t.orange}22`, fill: true, tension: .28, pointRadius: 4, pointBackgroundColor: t.orange }]
      },
      options: baseOptions({ currency: true, legend: true })
    });

    const revenueCategories = (dashboard.revenue_categories || []).filter((row) => Number(row.value) !== 0);
    addChart('revenueMixChart', {
      type: 'doughnut',
      data: {
        labels: revenueCategories.map((row) => row.label),
        datasets: [{
          data: revenueCategories.map((row) => row.value),
          backgroundColor: [t.primary, t.accent, t.orange, t.purple, t.secondary],
          borderWidth: 0,
          hoverOffset: 8
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '62%',
        plugins: {
          legend: { position: 'bottom', labels: { color: t.text, usePointStyle: true, padding: 16 } },
          tooltip: { callbacks: { label: (context) => `${context.label}: ${money(context.raw)}` } }
        }
      }
    });

    const daily = dashboard.daily || [];
    addChart('dailyRevenueChart', {
      type: 'line',
      data: {
        labels: daily.map((row) => row.label),
        datasets: [{ label: 'Daily Revenue', data: daily.map((row) => row.revenue), borderColor: t.primary, backgroundColor: `${t.primary}1f`, fill: true, tension: .32, pointRadius: 3 }]
      },
      options: baseOptions({ currency: true, legend: true })
    });
  }

  renderCharts();

  window.addEventListener('aestheticintel:themechange', () => {
    charts.splice(0).forEach((chart) => chart.destroy());
    renderCharts();
  });

})();
