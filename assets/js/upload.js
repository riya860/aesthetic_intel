(() => {
  'use strict';
  const form = document.querySelector('[data-upload-form]');
  if (!form) return;
  const cards = [...form.querySelectorAll('[data-upload-card]')];
  const counter = document.querySelector('[data-upload-count]');
  const submit = form.querySelector('[data-submit-upload]');
  function update() {
    let selected = 0;
    cards.forEach((card) => {
      const input = card.querySelector('input[type="file"]');
      const fileName = card.querySelector('[data-file-name]');
      const status = card.querySelector('[data-upload-status]');
      const file = input && input.files ? input.files[0] : null;
      card.classList.remove('selected', 'invalid');
      if (!file) { if (fileName) fileName.textContent = 'Choose CSV file (optional)'; if (status) status.textContent = '○'; return; }
      if (!/\.csv$/i.test(file.name)) { card.classList.add('invalid'); if (fileName) fileName.textContent = 'CSV files only'; if (status) status.textContent = '!'; return; }
      selected += 1; card.classList.add('selected'); if (fileName) fileName.textContent = file.name; if (status) status.textContent = '✓';
    });
    if (counter) counter.textContent = `${selected} / ${cards.length}`;
    if (submit) submit.disabled = selected < 1 || cards.some((c) => c.classList.contains('invalid'));
  }
  form.addEventListener('change', update);
  form.addEventListener('submit', () => { if (!submit) return; submit.disabled = true; submit.textContent = 'Validating & Generating…'; document.body.classList.add('is-processing'); });
  update();
})();