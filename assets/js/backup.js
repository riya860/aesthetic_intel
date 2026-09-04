(() => {
  const overlay = document.querySelector('[data-backup-overlay]');
  const title = document.querySelector('[data-backup-title]');
  document.querySelectorAll('form[data-backup-submit]').forEach((form) => {
    form.addEventListener('submit', () => {
      if (!form.checkValidity()) return;
      if (title) title.textContent = form.dataset.backupSubmit || 'Working…';
      if (overlay) {
        overlay.classList.add('is-visible');
        overlay.setAttribute('aria-hidden', 'false');
      }
      document.body.classList.add('ai-uploading');
      form.querySelectorAll('button, input, select').forEach((el) => {
        if (el.type !== 'hidden') el.setAttribute('readonly', 'readonly');
      });
    });
  });

  // A normal file download does not navigate the page. Release the overlay after the server has had time to start it.
  const createForm = document.querySelector('form input[value="create"]')?.form;
  if (createForm) {
    createForm.addEventListener('submit', () => {
      window.setTimeout(() => {
        overlay?.classList.remove('is-visible');
        overlay?.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('ai-uploading');
        createForm.querySelectorAll('[readonly]').forEach((el) => el.removeAttribute('readonly'));
      }, 12000);
    });
  }
})();
