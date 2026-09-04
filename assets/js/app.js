(() => {
  'use strict';

  const root = document.documentElement;
  const savedTheme = localStorage.getItem('aesthetic-intel-theme');
  const preferredTheme = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  const initialTheme = savedTheme || 'light';

  function applyTheme(theme) {
    const safeTheme = theme === 'dark' ? 'dark' : 'light';
    root.dataset.theme = safeTheme;
    root.style.colorScheme = safeTheme;
    document.querySelectorAll('[data-theme-toggle]').forEach((button) => {
      const label = button.querySelector('.theme-label');
      if (label) label.textContent = safeTheme === 'dark' ? 'Light mode' : 'Dark mode';
      button.setAttribute('aria-label', safeTheme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode');
      button.setAttribute('title', safeTheme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode');
    });
    window.dispatchEvent(new CustomEvent('aestheticintel:themechange', { detail: { theme: safeTheme } }));
  }

  applyTheme(initialTheme || preferredTheme);

  document.addEventListener('click', (event) => {
    const themeButton = event.target.closest('[data-theme-toggle]');
    if (themeButton) {
      const next = root.dataset.theme === 'dark' ? 'light' : 'dark';
      localStorage.setItem('aesthetic-intel-theme', next);
      applyTheme(next);
    }

    const sidebarButton = event.target.closest('[data-sidebar-toggle]');
    if (sidebarButton) document.body.classList.toggle('sidebar-open');
  });

  document.querySelectorAll('.nav-link, .nav-sublink').forEach((link) => {
    link.addEventListener('click', () => document.body.classList.remove('sidebar-open'));
  });

  document.querySelectorAll('[data-nav-group-toggle]').forEach((button) => {
    button.addEventListener('click', () => {
      const group = button.closest('[data-nav-group]');
      if (!group) return;
      const open = group.classList.toggle('open');
      button.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  });

  document.querySelectorAll('[data-sidebar-scrim]').forEach((scrim) => {
    scrim.addEventListener('click', () => document.body.classList.remove('sidebar-open'));
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') document.body.classList.remove('sidebar-open');
  });

  const roleSelect = document.querySelector('[data-role-select]');
  const businessField = document.querySelector('[data-business-field]');
  if (roleSelect && businessField) {
    const syncRole = () => {
      const isAdmin = roleSelect.value === 'super_admin';
      businessField.hidden = isAdmin;
      const select = businessField.querySelector('select');
      if (select) select.required = !isAdmin;
    };
    roleSelect.addEventListener('change', syncRole);
    syncRole();
  }

  document.querySelectorAll('[data-period-form]').forEach((form) => {
    const frequency = form.querySelector('[data-frequency]');
    const startInput = form.querySelector('[data-period-start]');
    const endInput = form.querySelector('[data-period-end]');
    if (!frequency || !startInput || !endInput) return;

    const parseIso = (value) => {
      const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value || '');
      return match ? new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]), 12) : null;
    };
    const isoLocal = (date) => `${date.getFullYear()}-${String(date.getMonth()+1).padStart(2,'0')}-${String(date.getDate()).padStart(2,'0')}`;
    const subtractMonths = (date, months) => {
      const target = new Date(date.getFullYear(), date.getMonth() - months, 1, 12);
      const lastDay = new Date(target.getFullYear(), target.getMonth() + 1, 0, 12).getDate();
      target.setDate(Math.min(date.getDate(), lastDay));
      return target;
    };
    const subtractYear = (date) => {
      const target = new Date(date.getFullYear() - 1, date.getMonth(), 1, 12);
      const lastDay = new Date(target.getFullYear(), target.getMonth() + 1, 0, 12).getDate();
      target.setDate(Math.min(date.getDate(), lastDay));
      return target;
    };
    const todayIso = () => {
      const businessToday = form.dataset.businessToday || '';
      if (/^\d{4}-\d{2}-\d{2}$/.test(businessToday)) return businessToday;
      const now = new Date();
      return isoLocal(new Date(now.getFullYear(), now.getMonth(), now.getDate(), 12));
    };
    const syncDates = () => {
      const custom = frequency.value === 'custom';
      startInput.readOnly = !custom;
      endInput.readOnly = !custom;
      startInput.classList.toggle('is-readonly', !custom);
      endInput.classList.toggle('is-readonly', !custom);
      if (custom) {
        startInput.removeAttribute('tabindex');
        endInput.removeAttribute('tabindex');
        return;
      }
      endInput.value = todayIso();
      const endDate = parseIso(endInput.value) || new Date();
      let startDate = new Date(endDate);
      if (frequency.value === 'weekly') startDate.setDate(endDate.getDate() - 7);
      if (frequency.value === 'monthly') startDate = new Date(endDate.getFullYear(), endDate.getMonth(), 1, 12);
      if (frequency.value === 'quarterly') startDate = subtractMonths(endDate, 3);
      if (frequency.value === 'yearly') startDate = subtractYear(endDate);
      startInput.value = isoLocal(startDate);
    };

    frequency.addEventListener('change', syncDates);
    form.addEventListener('submit', (event) => {
      if (!form.checkValidity()) {
        event.preventDefault();
        form.reportValidity();
      }
    });
    syncDates();
  });

  const createUploadOverlay = () => {
    let overlay = document.querySelector('[data-ai-upload-overlay]');
    if (overlay) return overlay;
    overlay = document.createElement('div');
    overlay.className = 'ai-upload-overlay';
    overlay.setAttribute('data-ai-upload-overlay', '');
    overlay.innerHTML = `
      <div class="ai-upload-dialog" role="status" aria-live="polite">
        <div class="ai-loader-ring"></div>
        <h2 data-upload-title>Uploading report…</h2>
        <p data-upload-message>Please keep this page open.</p>
        <div class="ai-progress-track"><span data-upload-progress></span></div>
        <strong data-upload-percent>0%</strong>
      </div>`;
    document.body.appendChild(overlay);
    return overlay;
  };

  document.querySelectorAll('[data-boulevard-sync-launch]').forEach((form) => {
    form.addEventListener('submit', () => {
      if (!form.checkValidity()) return;
      const overlay = createUploadOverlay();
      const title = overlay.querySelector('[data-upload-title]');
      const message = overlay.querySelector('[data-upload-message]');
      const bar = overlay.querySelector('[data-upload-progress]');
      const percent = overlay.querySelector('[data-upload-percent]');
      overlay.classList.add('is-visible');
      document.body.classList.add('ai-uploading');
      if (title) title.textContent = 'Requesting Boulevard reports…';
      if (message) message.textContent = 'Aesthetic Intel is creating one-time exports for every mapped report. Please keep this page open.';
      if (bar) { bar.style.width = '100%'; bar.classList.add('is-indeterminate'); }
      if (percent) percent.textContent = 'Connecting';
      const submit = form.querySelector('button[type="submit"]');
      if (submit) { submit.disabled = true; submit.textContent = 'Starting Sync…'; }
    });
  });

  document.querySelectorAll('[data-ai-upload-form]').forEach((form) => {
    const fileInputs = [...form.querySelectorAll('[data-auto-upload]')];
    if (!fileInputs.length) return;
    form.querySelectorAll('.js-upload-fallback').forEach((node) => { node.hidden = true; });
    const submitFile = (input) => {
      if (!input.files || !input.files[0]) return;
      if (!form.checkValidity()) { form.reportValidity(); input.value = ''; return; }
      fileInputs.forEach((other) => { if (other !== input) other.value = ''; });
      const nameTarget = input.closest('.tool-upload-card')?.querySelector('[data-file-name]');
      if (nameTarget) nameTarget.textContent = input.files[0].name;
      const overlay = createUploadOverlay();
      const title = overlay.querySelector('[data-upload-title]');
      const message = overlay.querySelector('[data-upload-message]');
      const bar = overlay.querySelector('[data-upload-progress]');
      const percent = overlay.querySelector('[data-upload-percent]');
      overlay.classList.add('is-visible');
      document.body.classList.add('ai-uploading');
      [...form.elements].forEach((element) => { element.disabled = true; });
      title.textContent = 'Uploading report…'; message.textContent = 'Please keep this page open.'; bar.style.width = '0%'; percent.textContent = '0%';
      const xhr = new XMLHttpRequest();
      const endpoint = form.getAttribute('action') || (window.location.pathname + window.location.search);
      xhr.open('POST', new URL(endpoint, window.location.href).toString(), true);
      xhr.timeout = 300000;
      xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
      xhr.setRequestHeader('Accept', 'application/json');
      xhr.upload.addEventListener('progress', (event) => {
        if (!event.lengthComputable) return;
        const value = Math.min(100, Math.round((event.loaded / event.total) * 100));
        bar.style.width = `${value}%`; percent.textContent = `${value}%`;
        if (value === 100) { title.textContent = 'Reading report with AI…'; message.textContent = 'Extracting metrics and saving the results.'; percent.textContent = 'Processing'; }
      });
      xhr.addEventListener('load', () => {
        let result = null;
        try { result = JSON.parse(xhr.responseText); } catch (_) {}
        if (xhr.status >= 200 && xhr.status < 300 && result?.ok) {
          title.textContent = 'Report saved'; message.textContent = result.message || 'The extracted metrics were saved.'; bar.style.width = '100%'; percent.textContent = 'Done';
          setTimeout(() => { window.location.href = result.reload || window.location.href; }, 700);
          return;
        }
        overlay.classList.remove('is-visible');
        document.body.classList.remove('ai-uploading');
        [...form.elements].forEach((element) => { element.disabled = false; });
        const raw = (xhr.responseText || '').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
        const fallback = raw ? `Server returned HTTP ${xhr.status}: ${raw.slice(0, 260)}` : `Server returned HTTP ${xhr.status || 'unknown'}.`;
        window.alert(result?.message || fallback);
        input.value = '';
      });
      xhr.addEventListener('error', () => { overlay.classList.remove('is-visible'); document.body.classList.remove('ai-uploading'); [...form.elements].forEach((element) => { element.disabled = false; }); window.alert('The upload connection failed before the server returned a response.'); input.value = ''; });
      xhr.addEventListener('timeout', () => { overlay.classList.remove('is-visible'); document.body.classList.remove('ai-uploading'); [...form.elements].forEach((element) => { element.disabled = false; }); window.alert('The report took longer than 5 minutes to process. Please check storage/logs/ai-extraction.log.'); input.value = ''; });
      // Disabled controls are omitted from FormData, so re-enable briefly while building the payload.
      [...form.elements].forEach((element) => { element.disabled = false; });
      const payload = new FormData(form);
      [...form.elements].forEach((element) => { element.disabled = true; });
      xhr.send(payload);
    };
    fileInputs.forEach((input) => input.addEventListener('change', () => submitFile(input)));
  });

  document.querySelectorAll('.report-canvas table, table[data-sortable-table]').forEach((table) => {
    const body = table.tBodies[0];
    if (!body || !table.tHead) return;
    [...table.tHead.rows[0].cells].forEach((header, index) => {
      if (header.dataset.noSort !== undefined || /action/i.test(header.textContent || '')) return;
      header.classList.add('sortable-header');
      header.tabIndex = 0;
      header.setAttribute('role', 'button');
      const sort = () => {
        const ascending = header.dataset.sortDirection !== 'asc';
        [...table.tHead.querySelectorAll('th')].forEach((th) => { delete th.dataset.sortDirection; th.removeAttribute('aria-sort'); });
        header.dataset.sortDirection = ascending ? 'asc' : 'desc';
        header.setAttribute('aria-sort', ascending ? 'ascending' : 'descending');
        const parse = (cell) => {
          const text=(cell.textContent||'').trim();
          const cleaned=text.replace(/[$,%]/g,'').replace(/,/g,'').replace(/\/hr$/i,'').trim();
          if(cleaned!=='' && !Number.isNaN(Number(cleaned))) return {type:'number',value:Number(cleaned)};
          const date=Date.parse(text); if(!Number.isNaN(date) && /\d{4}|[A-Za-z]{3}/.test(text)) return {type:'number',value:date};
          return {type:'text',value:text.toLowerCase()};
        };
        const rows=[...body.rows];
        rows.sort((a,b)=>{const av=parse(a.cells[index]||a),bv=parse(b.cells[index]||b);let cmp=av.type==='number'&&bv.type==='number'?av.value-bv.value:String(av.value).localeCompare(String(bv.value),undefined,{numeric:true});return ascending?cmp:-cmp;});
        rows.forEach((row)=>body.appendChild(row));
      };
      header.addEventListener('click', sort);
      header.addEventListener('keydown', (event)=>{if(event.key==='Enter'||event.key===' '){event.preventDefault();sort();}});
    });
  });

  const secureRandomInt = (max) => {
    const limit = Math.floor(0x100000000 / max) * max;
    const values = new Uint32Array(1);
    do { window.crypto.getRandomValues(values); } while (values[0] >= limit);
    return values[0] % max;
  };

  const shuffleSecurely = (characters) => {
    const list = [...characters];
    for (let index = list.length - 1; index > 0; index -= 1) {
      const swap = secureRandomInt(index + 1);
      [list[index], list[swap]] = [list[swap], list[index]];
    }
    return list.join('');
  };

  document.querySelectorAll('[data-password-reset-form]').forEach((form) => {
    const password = form.querySelector('[data-new-password]');
    const confirmation = form.querySelector('[data-confirm-password]');
    const generator = form.querySelector('[data-generate-password]');
    if (!password || !confirmation || !generator || !window.crypto?.getRandomValues) return;
    generator.addEventListener('click', () => {
      const upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
      const lower = 'abcdefghijkmnopqrstuvwxyz';
      const numbers = '23456789';
      const symbols = '!@#$%*+-_?';
      const all = upper + lower + numbers + symbols;
      let value = upper[secureRandomInt(upper.length)] + lower[secureRandomInt(lower.length)] + numbers[secureRandomInt(numbers.length)] + symbols[secureRandomInt(symbols.length)];
      while (value.length < 16) value += all[secureRandomInt(all.length)];
      value = shuffleSecurely(value);
      password.value = value;
      confirmation.value = value;
      password.focus();
      password.select();
    });
  });

  document.querySelectorAll('[data-copy-generated-password]').forEach((button) => {
    button.addEventListener('click', async () => {
      const value = button.closest('[data-password-result]')?.querySelector('[data-generated-password]')?.textContent || '';
      if (!value) return;
      try {
        await navigator.clipboard.writeText(value);
        const original = button.textContent;
        button.textContent = 'Copied';
        setTimeout(() => { button.textContent = original; }, 1600);
      } catch (_) {
        window.prompt('Copy this password:', value);
      }
    });
  });

  document.querySelectorAll('[data-confirm]').forEach((element) => {
    element.addEventListener('click', (event) => {
      const message = element.getAttribute('data-confirm') || 'Are you sure?';
      if (!window.confirm(message)) event.preventDefault();
    });
  });


  document.querySelectorAll('[data-mapping-row]').forEach((row) => {
    const search = row.querySelector('[data-report-search]');
    const select = row.querySelector('[data-report-select]');
    const manual = row.querySelector('[data-manual-report-id]');
    const count = row.querySelector('[data-search-result-count]');
    const summary = row.querySelector('[data-selected-report-summary]');
    const summaryName = row.querySelector('[data-selected-report-name]');
    const summaryId = row.querySelector('[data-selected-report-id]');
    const approvedId = row.querySelector('[data-approved-report-id]');
    const approvedName = row.querySelector('[data-approved-report-name]');
    if (!search || !select) return;

    const original = [...select.options].map((option) => ({
      value: option.value,
      text: option.textContent || '',
      reportName: option.dataset.reportName || option.textContent || '',
      search: option.dataset.search || (option.textContent || '').toLowerCase(),
    }));

    const updateSummary = () => {
      const manualId = manual ? manual.value.trim() : '';
      const selected = select.options[select.selectedIndex];
      const selectedId = select.value;
      let reportId = manualId || selectedId || (approvedId ? approvedId.value.trim() : '');
      let reportName = '';
      if (manualId) reportName = 'Manual Boulevard report';
      else if (selectedId && selected) reportName = selected.dataset.reportName || selected.textContent || 'Selected Boulevard report';
      else if (approvedName) reportName = approvedName.value.trim();

      if (reportId) {
        if (!reportName) reportName = 'Selected Boulevard report';
        if (approvedId) approvedId.value = reportId;
        if (approvedName) approvedName.value = reportName;
        if (summaryName) summaryName.textContent = reportName;
        if (summaryId) summaryId.textContent = reportId;
        if (summary) summary.classList.add('has-selection');
      } else {
        if (approvedId) approvedId.value = '';
        if (approvedName) approvedName.value = '';
        if (summaryName) summaryName.textContent = 'Not selected yet';
        if (summaryId) summaryId.textContent = 'Apply the AI suggestion, search the catalogue, or enter a manual ID.';
        if (summary) summary.classList.remove('has-selection');
      }
    };

    const renderOptions = (term = '') => {
      const normalized = term.trim().toLowerCase();
      const current = select.value;
      const matches = original.filter((item, index) => index === 0 || !normalized || item.search.includes(normalized));
      select.replaceChildren(...matches.map((item) => {
        const option = document.createElement('option');
        option.value = item.value;
        option.textContent = item.text;
        option.dataset.reportName = item.reportName;
        if (item.search) option.dataset.search = item.search;
        return option;
      }));
      if ([...select.options].some((option) => option.value === current)) select.value = current;
      else select.value = '';
      if (count) count.textContent = `${Math.max(0, matches.length - 1)} matching report${matches.length === 2 ? '' : 's'}`;
      updateSummary();
    };

    search.addEventListener('input', () => renderOptions(search.value));
    select.addEventListener('change', () => {
      if (select.value && manual) manual.value = '';
      updateSummary();
    });
    if (manual) {
      manual.addEventListener('input', () => {
        if (manual.value.trim()) select.value = '';
        updateSummary();
      });
    }

    row.querySelectorAll('[data-use-boulevard-suggestion]').forEach((button) => {
      button.addEventListener('click', () => {
        search.value = '';
        renderOptions('');
        const reportId = button.getAttribute('data-use-boulevard-suggestion') || '';
        if (approvedId) approvedId.value = reportId;
        if (approvedName) approvedName.value = button.getAttribute('data-suggestion-name') || 'Boulevard report';
        const suggestionExists = [...select.options].some((option) => option.value === reportId);
        select.value = suggestionExists ? reportId : '';
        if (manual) manual.value = '';
        updateSummary();
        row.classList.add('suggestion-applied');
        if (summary) {
          summary.classList.add('selection-highlight');
          window.setTimeout(() => summary.classList.remove('selection-highlight'), 1600);
        }
        const originalText = button.textContent || 'Use this suggestion';
        button.textContent = 'Suggestion selected ✓';
        window.setTimeout(() => { button.textContent = originalText; }, 1800);
      });
    });
    updateSummary();
  });

  document.querySelectorAll('[data-boulevard-mapping-form]').forEach((form) => {
    form.addEventListener('submit', (event) => {
      const submitter = event.submitter;
      if (submitter && submitter.matches('button[name="apply_suggestion"]')) {
        const fallback = document.createElement('input');
        fallback.type = 'hidden';
        fallback.name = 'apply_suggestion';
        fallback.value = submitter.value;
        form.appendChild(fallback);
        submitter.disabled = true;
        submitter.textContent = 'Saving suggestion…';
        return;
      }

      let selectedCount = 0;
      form.querySelectorAll('[data-mapping-row]').forEach((row) => {
        const enabled = row.querySelector('input[type="checkbox"][name^="mapping_enabled"]');
        const select = row.querySelector('[data-report-select]');
        const manual = row.querySelector('[data-manual-report-id]');
        const approvedId = row.querySelector('[data-approved-report-id]');
        const approvedName = row.querySelector('[data-approved-report-name]');
        const manualValue = manual ? manual.value.trim() : '';
        const selectedValue = select ? select.value.trim() : '';
        const reportId = manualValue || selectedValue || (approvedId ? approvedId.value.trim() : '');
        let reportName = '';
        if (manualValue) reportName = 'Manual Boulevard report';
        else if (select && select.selectedIndex >= 0) reportName = select.options[select.selectedIndex]?.dataset.reportName || '';
        if (!reportName && approvedName) reportName = approvedName.value.trim();
        if (approvedId) approvedId.value = reportId;
        if (approvedName) approvedName.value = reportName;
        if (enabled?.checked && reportId) selectedCount += 1;
      });

      if (selectedCount === 0) {
        event.preventDefault();
        window.alert('No Boulevard report has been selected. Click “Use this suggestion” or choose a fetched report before saving.');
        return;
      }

      const button = form.querySelector('button[type="submit"]');
      if (button) {
        button.disabled = true;
        button.textContent = `Saving ${selectedCount} mapping${selectedCount === 1 ? '' : 's'}…`;
      }
    });
  });

  document.querySelectorAll('[data-provider-import-form]').forEach((form) => {
    form.addEventListener('submit', () => {
      if (!form.checkValidity()) return;
      const overlay = createUploadOverlay();
      const title = overlay.querySelector('[data-upload-title]');
      const message = overlay.querySelector('[data-upload-message]');
      const bar = overlay.querySelector('[data-upload-progress]');
      const percent = overlay.querySelector('[data-upload-percent]');
      overlay.classList.add('is-visible');
      document.body.classList.add('ai-uploading');
      if (title) title.textContent = 'Validating provider KPI data…';
      if (message) message.textContent = 'Aesthetic Intel is checking providers, KPI columns, duplicates, and numeric values before showing the preview.';
      if (bar) { bar.style.width = '100%'; bar.classList.add('is-indeterminate'); }
      if (percent) percent.textContent = 'Reviewing CSV';
      const button = form.querySelector('button[type="submit"]');
      window.setTimeout(() => { if (button) { button.disabled = true; button.textContent = 'Validating…'; } }, 0);
    });
  });

  document.querySelectorAll('[data-ai-review-form]').forEach((form) => {
    form.addEventListener('submit', (event) => {
      if (event.defaultPrevented || !form.checkValidity()) return;
      const overlay = createUploadOverlay();
      const title = overlay.querySelector('[data-upload-title]');
      const message = overlay.querySelector('[data-upload-message]');
      const bar = overlay.querySelector('[data-upload-progress]');
      const percent = overlay.querySelector('[data-upload-percent]');
      overlay.classList.add('is-visible');
      document.body.classList.add('ai-uploading');
      if (title) title.textContent = 'Reviewing report with AI…';
      if (message) message.textContent = 'Aesthetic Intel is reviewing the available report sections. Please keep this page open.';
      if (bar) { bar.style.width = '100%'; bar.classList.add('is-indeterminate'); }
      if (percent) percent.textContent = 'Analyzing';
      const button = event.submitter || form.querySelector('button[type="submit"]');
      window.setTimeout(() => { if (button) { button.disabled = true; button.textContent = 'Reviewing…'; } }, 0);
    });
  });

  document.querySelectorAll('[data-boulevard-ai-form]').forEach((form) => {
    form.addEventListener('submit', (event) => {
      if (form.dataset.submitting === '1') {
        event.preventDefault();
        return;
      }
      form.dataset.submitting = '1';

      let action = form.querySelector('input[name="action"]');
      if (!action) {
        action = document.createElement('input');
        action.type = 'hidden';
        action.name = 'action';
        form.appendChild(action);
      }
      action.value = 'ai_match_reports';

      const button = form.querySelector('button[type="submit"]');
      if (button) {
        button.dataset.originalText = button.textContent || '';
        button.textContent = 'Analyzing report catalogue…';
      }

      const overlay = createUploadOverlay();
      const title = overlay.querySelector('[data-upload-title]');
      const message = overlay.querySelector('[data-upload-message]');
      const bar = overlay.querySelector('[data-upload-progress]');
      const percent = overlay.querySelector('[data-upload-percent]');
      title.textContent = 'Analyzing Boulevard reports…';
      message.textContent = 'OpenAI is reviewing the fetched report catalogue. This may take up to a minute.';
      bar.style.width = '100%';
      percent.textContent = 'Processing';
      overlay.classList.add('is-visible');
      document.body.classList.add('ai-uploading');

      // Delay disabling until after the browser has captured the form payload.
      window.setTimeout(() => {
        if (button) button.disabled = true;
      }, 0);
    });
  });

  // v1.6.0 — Business Feature Controls.
  document.querySelectorAll('[data-feature-controls-form]').forEach((form) => {
    const cards = [...form.querySelectorAll('[data-feature-card]')];
    const toggles = [...form.querySelectorAll('[data-feature-toggle]')];
    const count = form.querySelector('[data-feature-enabled-count]');

    const refresh = () => {
      const byCode = {};
      cards.forEach((card) => {
        const code = card.dataset.featureCode || '';
        const input = card.querySelector('[data-feature-toggle]');
        if (code && input) byCode[code] = input;
      });

      cards.forEach((card) => {
        const input = card.querySelector('[data-feature-toggle]');
        if (!input) return;
        const dependency = card.dataset.featureDepends || '';
        const dependencyOn = !dependency || !!byCode[dependency]?.checked;
        if (!dependencyOn) input.checked = false;
        input.disabled = !dependencyOn;
        card.classList.toggle('is-dependent-disabled', !dependencyOn);
        card.classList.toggle('is-enabled', input.checked && dependencyOn);
        card.classList.toggle('is-disabled', !input.checked || !dependencyOn);
        const badge = card.querySelector('[data-feature-state]');
        if (badge) badge.textContent = input.checked && dependencyOn ? 'Enabled' : 'Disabled';
      });

      if (count) count.textContent = String(toggles.filter((input) => input.checked && !input.disabled).length);
    };

    toggles.forEach((input) => input.addEventListener('change', refresh));
    form.querySelector('[data-feature-enable-all]')?.addEventListener('click', () => {
      toggles.forEach((input) => { input.checked = true; });
      refresh();
    });
    form.querySelector('[data-feature-disable-all]')?.addEventListener('click', () => {
      toggles.forEach((input) => { input.checked = false; });
      refresh();
    });
    refresh();
  });

})();
