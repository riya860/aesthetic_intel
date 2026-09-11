(() => {
  'use strict';

  const root = document.querySelector('[data-live-dashboard]');
  if (!root) return;

  const endpoint = root.getAttribute('data-live-endpoint') || '';
  const csrf = root.getAttribute('data-live-csrf') || '';
  const periodSelect = root.querySelector('[data-live-period-select]');
  let sources = [];

  try {
    sources = JSON.parse(root.getAttribute('data-live-sources') || '[]');
  } catch (_) {
    sources = [];
  }

  const liveData = new Map();
  const liveErrors = new Map();
  let refreshGeneration = 0;
  let currentPeriodKey = periodSelect ? periodSelect.value : 'weekly';

  const sourceLabels = {
    boulevard: 'Boulevard',
    ga4: 'GA4',
    gbp: 'Google Business Profile',
  };

  const periodConfig = {
    weekly: {
      label: 'Weekly',
      short: 'Last 7 completed days',
      revenue: 'Total revenue',
      comparison: 'previous 7 days',
      copy: 'Weekly view',
      basis: 'Weekly revenue is summed across the latest seven completed business days.',
    },
    mtd: {
      label: 'Monthly MTD',
      short: 'Month to date',
      revenue: 'MTD revenue',
      comparison: 'previous month MTD',
      copy: 'Monthly MTD view',
      basis: 'MTD revenue is the sum of business-local daily totals from the 1st of the month through the latest completed day.',
    },
    ytd: {
      label: 'Yearly YTD',
      short: 'Year to date',
      revenue: 'YTD revenue',
      comparison: 'previous year YTD',
      copy: 'Yearly YTD view',
      basis: 'YTD revenue is the sum of business-local daily totals from January 1 through the latest completed day.',
    },
  };

  function selectedConfig() {
    return periodConfig[currentPeriodKey] || periodConfig.weekly;
  }

  function compactNumber(value) {
    const number = Number(value || 0);
    const abs = Math.abs(number);
    if (abs >= 1000000) return `${(number / 1000000).toFixed(1).replace(/\.0$/, '')}M`;
    if (abs >= 1000) return `${(number / 1000).toFixed(1).replace(/\.0$/, '')}K`;
    return new Intl.NumberFormat(undefined, { maximumFractionDigits: 1 }).format(number);
  }

  function formatMetric(metric) {
    if (!metric) return '—';
    const value = Number(metric.value || 0);

    if (metric.format === 'currency') {
      return new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency: 'USD',
        maximumFractionDigits: Math.abs(value) >= 1000 ? 0 : 2,
      }).format(value);
    }

    if (metric.format === 'ratio_percent') {
      return `${(value * 100).toFixed(1)}%`;
    }

    return compactNumber(value);
  }

  function metricByKey(data, key) {
    if (!data || !Array.isArray(data.metrics)) return null;
    return data.metrics.find((metric) => metric && metric.key === key) || null;
  }

  function isInverseMetric(metric) {
    return metric && ['refunds', 'cancellation_rate', 'cancelled_appointments'].includes(metric.key);
  }

  function changeTone(metric) {
    if (!metric || metric.change_percent === null || metric.change_percent === undefined) return 'neutral';
    const change = Number(metric.change_percent || 0);
    if (Math.abs(change) < 0.05) return 'neutral';
    const positiveDirection = change > 0;
    const good = isInverseMetric(metric) ? !positiveDirection : positiveDirection;
    return good ? 'positive' : 'negative';
  }

  function changeText(metric, data) {
    const comparison = (data && data.comparison_label) || selectedConfig().comparison || 'prior period';
    if (!metric || metric.change_percent === null || metric.change_percent === undefined) return `No comparable ${comparison}`;
    const change = Number(metric.change_percent || 0);
    if (Math.abs(change) < 0.05) return `No material change vs ${comparison}`;
    const arrow = change > 0 ? '▲' : '▼';
    return `${arrow} ${Math.abs(change).toFixed(1)}% vs ${comparison}`;
  }

  function parseDateParts(value) {
    const match = String(value || '').match(/^(\d{4})-(\d{2})-(\d{2})$/);
    if (!match) return null;
    return {
      year: Number(match[1]),
      month: Number(match[2]),
      day: Number(match[3]),
    };
  }

  function humanDate(value, includeYear = false) {
    const parts = parseDateParts(value);
    if (!parts) return value || '';
    const date = new Date(Date.UTC(parts.year, parts.month - 1, parts.day, 12, 0, 0));
    const options = { month: 'short', day: 'numeric', timeZone: 'UTC' };
    if (includeYear) options.year = 'numeric';
    return date.toLocaleDateString(undefined, options);
  }

  function humanRange(startValue, endValue, forceYear = false) {
    const startParts = parseDateParts(startValue);
    const endParts = parseDateParts(endValue);
    if (!startParts || !endParts) return 'Live';

    const includeStartYear = forceYear || startParts.year !== endParts.year;
    const start = humanDate(startValue, includeStartYear);
    const end = humanDate(endValue, true);
    return `${start}–${end}`;
  }

  function sourcePeriod(data) {
    if (!data) return 'Live';
    return humanRange(data.period_start, data.period_end, false);
  }

  function comparisonRange(data) {
    if (!data) return '';
    const current = humanRange(data.period_start, data.period_end, false);
    const previous = humanRange(data.previous_period_start, data.previous_period_end, false);
    return current && previous ? `${current} vs ${previous}` : current;
  }

  function updatePeriodLabels(data = null) {
    const config = selectedConfig();
    const label = (data && data.period_label) || config.label;
    const revenueLabel = (data && data.revenue_label) || config.revenue;
    const comparisonLabel = (data && data.comparison_label) || config.comparison;

    root.querySelectorAll('[data-period-revenue-label]').forEach((el) => {
      el.textContent = revenueLabel;
    });

    root.querySelectorAll('[data-live-period-copy]').forEach((el) => {
      el.textContent = `${label} view`;
    });

    root.querySelectorAll('[data-live-comparison-copy]').forEach((el) => {
      el.textContent = `${label.toLowerCase()} vs ${comparisonLabel}`;
    });

    const title = root.querySelector('[data-live-period-title]');
    if (title) title.textContent = `${label} view`;

    const summary = root.querySelector('[data-live-period-summary]');
    if (summary) {
      summary.textContent = data
        ? comparisonRange(data)
        : `${config.short} · fetching fresh data…`;
    }

    const basis = root.querySelector('[data-live-revenue-basis]');
    if (basis) {
      if (data && data.source === 'boulevard' && data.calculation_basis) {
        basis.textContent = data.calculation_basis;
      } else {
        basis.textContent = config.basis;
      }
    }
  }

  function skeletonMarkup(size = 'small') {
    return `<span class="ai-skeleton ai-skeleton-${size}"></span>`;
  }

  function setSourceLoading(source) {
    const config = selectedConfig();
    const state = root.querySelector(`[data-live-source-state="${source}"]`);
    if (state) {
      state.classList.remove('is-live', 'is-error');
      state.classList.add('is-loading');
      const message = state.querySelector('[data-live-source-message]');
      if (message) message.textContent = `Fetching fresh ${config.label} data…`;
      if (!state.querySelector('.ai-live-mini-spinner')) {
        const spinner = document.createElement('span');
        spinner.className = 'ai-live-mini-spinner';
        spinner.setAttribute('aria-hidden', 'true');
        state.appendChild(spinner);
      }
    }

    const card = root.querySelector(`[data-live-source-card="${source}"]`);
    if (card) {
      card.classList.remove('is-live', 'is-error');
      card.classList.add('is-loading');
      const status = card.querySelector('[data-card-status]');
      if (status) {
        status.classList.remove('is-connected', 'is-error');
        status.textContent = 'Loading';
      }
      const note = card.querySelector('[data-source-note]');
      if (note) note.textContent = `Contacting ${sourceLabels[source] || source} for ${config.label}…`;
    }

    root.querySelectorAll(`[data-live-metric^="${source}:"]`).forEach((cardEl) => {
      cardEl.classList.remove('is-live', 'is-error');
      cardEl.classList.add('is-loading');
      const value = cardEl.querySelector('[data-live-value]');
      const change = cardEl.querySelector('[data-live-change]');
      const period = cardEl.querySelector('[data-live-period]');
      if (value) value.innerHTML = skeletonMarkup('value');
      if (change) {
        change.className = 'ai-snapshot-trend is-neutral';
        change.textContent = `Loading ${config.label}…`;
      }
      if (period) period.textContent = config.short;
    });

    root.querySelectorAll(`[data-live-inline^="${source}:"]`).forEach((el) => {
      el.innerHTML = skeletonMarkup('small');
    });

    root.querySelectorAll(`[data-live-row^="${source}:"]`).forEach((row) => {
      const value = row.querySelector('strong');
      const change = row.querySelector('em');
      if (value) value.innerHTML = skeletonMarkup('small');
      if (change) {
        change.className = 'is-neutral';
        change.textContent = 'Loading…';
      }
    });
  }

  function setSourceError(source, message) {
    liveErrors.set(source, message || 'Live refresh failed.');
    liveData.delete(source);

    const state = root.querySelector(`[data-live-source-state="${source}"]`);
    if (state) {
      state.classList.remove('is-loading', 'is-live');
      state.classList.add('is-error');
      const text = state.querySelector('[data-live-source-message]');
      if (text) text.textContent = message || 'Live refresh unavailable';
      const spinner = state.querySelector('.ai-live-mini-spinner');
      if (spinner) spinner.remove();
    }

    const card = root.querySelector(`[data-live-source-card="${source}"]`);
    if (card) {
      card.classList.remove('is-loading', 'is-live');
      card.classList.add('is-error');
      const status = card.querySelector('[data-card-status]');
      if (status) {
        status.classList.remove('is-connected');
        status.classList.add('is-error');
        status.textContent = 'Unavailable';
      }
      const note = card.querySelector('[data-source-note]');
      if (note) note.textContent = message || 'Fresh API data could not be loaded.';
    }

    root.querySelectorAll(`[data-live-metric^="${source}:"]`).forEach((cardEl) => {
      cardEl.classList.remove('is-loading');
      cardEl.classList.add('is-error');
      const value = cardEl.querySelector('[data-live-value]');
      const change = cardEl.querySelector('[data-live-change]');
      if (value) value.textContent = '—';
      if (change) {
        change.className = 'ai-snapshot-trend is-negative';
        change.textContent = 'Fresh API data unavailable';
      }
    });

    root.querySelectorAll(`[data-live-inline^="${source}:"]`).forEach((el) => {
      el.textContent = '—';
    });

    root.querySelectorAll(`[data-live-row^="${source}:"]`).forEach((row) => {
      const value = row.querySelector('strong');
      const change = row.querySelector('em');
      if (value) value.textContent = '—';
      if (change) {
        change.className = 'is-negative';
        change.textContent = 'Unavailable';
      }
    });
  }

  function renderSource(source, data) {
    liveErrors.delete(source);
    liveData.set(source, data);
    updatePeriodLabels(data);

    const period = sourcePeriod(data);
    const state = root.querySelector(`[data-live-source-state="${source}"]`);
    if (state) {
      state.classList.remove('is-loading', 'is-error');
      state.classList.add('is-live');
      const message = state.querySelector('[data-live-source-message]');
      const resource = data.resource_name ? ` · ${data.resource_name}` : '';
      if (message) message.textContent = `Live · ${data.period_label || selectedConfig().label} · ${period}${resource}`;
      const spinner = state.querySelector('.ai-live-mini-spinner');
      if (spinner) spinner.remove();
    }

    const sourceCard = root.querySelector(`[data-live-source-card="${source}"]`);
    if (sourceCard) {
      sourceCard.classList.remove('is-loading', 'is-error');
      sourceCard.classList.add('is-live');
      const status = sourceCard.querySelector('[data-card-status]');
      if (status) {
        status.classList.remove('is-error');
        status.classList.add('is-connected');
        status.textContent = 'Live';
      }
      const note = sourceCard.querySelector('[data-source-note]');
      if (note) {
        const resource = data.resource_name ? ` · ${data.resource_name}` : '';
        note.textContent = `Fresh ${data.period_label || selectedConfig().label} API data for ${period}${resource}.`;
      }
    }

    root.querySelectorAll(`[data-live-metric^="${source}:"]`).forEach((card) => {
      const key = (card.getAttribute('data-live-metric') || '').split(':')[1];
      const metric = metricByKey(data, key);
      if (!metric) return;

      card.classList.remove('is-loading', 'is-error');
      card.classList.add('is-live');
      const value = card.querySelector('[data-live-value]');
      const change = card.querySelector('[data-live-change]');
      const periodEl = card.querySelector('[data-live-period]');
      if (value) value.textContent = formatMetric(metric);
      if (change) {
        change.className = `ai-snapshot-trend is-${changeTone(metric)}`;
        change.textContent = changeText(metric, data);
      }
      if (periodEl) periodEl.textContent = period;
    });

    root.querySelectorAll(`[data-live-inline^="${source}:"]`).forEach((el) => {
      const key = (el.getAttribute('data-live-inline') || '').split(':')[1];
      const metric = metricByKey(data, key);
      if (metric) el.textContent = formatMetric(metric);
    });

    root.querySelectorAll(`[data-live-row^="${source}:"]`).forEach((row) => {
      const key = (row.getAttribute('data-live-row') || '').split(':')[1];
      const metric = metricByKey(data, key);
      if (!metric) return;
      const value = row.querySelector('strong');
      const change = row.querySelector('em');
      if (value) value.textContent = formatMetric(metric);
      if (change) {
        change.className = `is-${changeTone(metric)}`;
        change.textContent = changeText(metric, data);
      }
    });
  }

  async function fetchSource(source, generation, periodKey) {
    setSourceLoading(source);

    const body = new URLSearchParams();
    body.set('_csrf', csrf);
    body.set('source', source);
    body.set('period', periodKey);

    try {
      const response = await fetch(endpoint, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
          'X-Requested-With': 'XMLHttpRequest',
          'Accept': 'application/json',
        },
        credentials: 'same-origin',
        body: body.toString(),
      });

      let payload = null;
      try {
        payload = await response.json();
      } catch (_) {
        throw new Error(`Live ${sourceLabels[source] || source} request returned an invalid response.`);
      }

      if (generation !== refreshGeneration || periodKey !== currentPeriodKey) return;

      if (!response.ok || !payload || payload.ok !== true || !payload.data) {
        throw new Error((payload && payload.message) || `Live ${sourceLabels[source] || source} refresh failed.`);
      }

      renderSource(source, payload.data);
    } catch (error) {
      if (generation !== refreshGeneration || periodKey !== currentPeriodKey) return;
      setSourceError(source, error instanceof Error ? error.message : 'Fresh API data could not be loaded.');
    }
  }

  function sourceMetric(source, key) {
    return metricByKey(liveData.get(source), key);
  }

  function insightCandidate(source, key, title, inverse = false) {
    const data = liveData.get(source);
    const metric = metricByKey(data, key);
    if (!metric || metric.change_percent === null || metric.change_percent === undefined) return null;
    const change = Number(metric.change_percent || 0);
    if (Math.abs(change) < 3) return null;

    const worse = inverse ? change > 0 : change < 0;
    const abs = Math.abs(change).toFixed(1);
    const direction = change > 0 ? 'increased' : 'decreased';
    const comparison = (data && data.comparison_label) || selectedConfig().comparison;

    return {
      priority: worse ? 'high' : 'low',
      score: Math.abs(change) + (worse ? 100 : 0),
      category: sourceLabels[source] || source,
      title,
      detail: `${metric.label} ${direction} ${abs}% compared with ${comparison}.`,
    };
  }

  function renderInsights() {
    const container = root.querySelector('[data-live-insights]');
    if (!container) return;

    const candidates = [
      insightCandidate('boulevard', 'revenue', 'Revenue movement'),
      insightCandidate('boulevard', 'appointments', 'Appointment volume'),
      insightCandidate('boulevard', 'cancellation_rate', 'Cancellation pressure', true),
      insightCandidate('boulevard', 'refunds', 'Refund movement', true),
      insightCandidate('ga4', 'sessions', 'Website demand'),
      insightCandidate('ga4', 'activeUsers', 'Website audience'),
      insightCandidate('gbp', 'actions', 'Local conversion actions'),
      insightCandidate('gbp', 'website_clicks', 'GBP website traffic'),
      insightCandidate('gbp', 'call_clicks', 'GBP call activity'),
    ].filter(Boolean);

    liveErrors.forEach((message, source) => {
      candidates.push({
        priority: 'high',
        score: 1000,
        category: sourceLabels[source] || source,
        title: 'Live source needs attention',
        detail: message,
      });
    });

    candidates.sort((a, b) => b.score - a.score);
    const selected = candidates.slice(0, 4);

    if (!selected.length) {
      const comparison = selectedConfig().comparison;
      container.innerHTML = `<article class="ai-live-insight is-low"><span>✓</span><div><small>Live read</small><strong>No large movement detected</strong><p>The connected live sources did not show a material change versus ${escapeHtml(comparison)} in the primary KPIs.</p></div></article>`;
      return;
    }

    container.innerHTML = selected.map((item, index) => `
      <article class="ai-live-insight is-${item.priority}">
        <span>${index + 1}</span>
        <div>
          <small>${escapeHtml(item.category)}</small>
          <strong>${escapeHtml(item.title)}</strong>
          <p>${escapeHtml(item.detail)}</p>
        </div>
      </article>
    `).join('');
  }

  function escapeHtml(value) {
    return String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  async function refreshAll() {
    currentPeriodKey = periodSelect && periodConfig[periodSelect.value]
      ? periodSelect.value
      : currentPeriodKey;
    updatePeriodLabels();

    if (!endpoint || !csrf || sources.length === 0) {
      renderInsights();
      return;
    }

    refreshGeneration += 1;
    const generation = refreshGeneration;
    const requestedPeriod = currentPeriodKey;
    liveData.clear();
    liveErrors.clear();

    const button = root.querySelector('[data-live-refresh-all]');
    const label = button ? button.querySelector('[data-refresh-label]') : null;
    if (button) {
      button.disabled = true;
      button.classList.add('is-loading');
    }
    if (periodSelect) periodSelect.disabled = true;
    if (label) label.textContent = `Refreshing ${selectedConfig().label}…`;

    const insightContainer = root.querySelector('[data-live-insights]');
    if (insightContainer) {
      insightContainer.innerHTML = `<div class="ai-live-insight-loading"><span class="ai-live-spinner"></span><div><strong>Building ${escapeHtml(selectedConfig().label)} insights</strong><small>Waiting for connected sources to finish.</small></div></div>`;
    }

    await Promise.allSettled(sources.map((source) => fetchSource(source, generation, requestedPeriod)));

    if (generation !== refreshGeneration || requestedPeriod !== currentPeriodKey) return;
    renderInsights();

    if (button) {
      button.disabled = false;
      button.classList.remove('is-loading');
    }
    if (periodSelect) periodSelect.disabled = false;
    if (label) label.textContent = 'Refresh live data';
  }

  const refreshButton = root.querySelector('[data-live-refresh-all]');
  if (refreshButton) refreshButton.addEventListener('click', refreshAll);

  if (periodSelect) {
    periodSelect.addEventListener('change', () => {
      currentPeriodKey = periodSelect.value;
      refreshAll();
    });
  }

  updatePeriodLabels();
  refreshAll();
})();
