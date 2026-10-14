document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('[data-confirm]').forEach((el) => {
    el.addEventListener('click', (event) => {
      if (!window.confirm(el.getAttribute('data-confirm') || 'Подтвердить действие?')) event.preventDefault();
    });
  });
  document.querySelectorAll('[data-copy-target]').forEach((button) => {
    button.addEventListener('click', async () => {
      const target = document.querySelector(button.getAttribute('data-copy-target'));
      if (!target) return;
      try { await navigator.clipboard.writeText(target.textContent.trim()); button.textContent = 'Скопировано'; }
      catch { button.textContent = 'Не удалось'; }
    });
  });
});
