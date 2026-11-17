/**
 * HIPDC Gate & Logistics Management - Theme Controller
 * Seamless instant toggle between Light and Dark themes across all pages.
 */

const HIPDC_Theme = {
  STORAGE_KEY: 'hipdcTheme',

  init() {
    const saved = localStorage.getItem(this.STORAGE_KEY) || 'light';
    this.apply(saved);

    // Ensure all toggle buttons are in sync
    this.updateToggleButtons();

    // Bind click events on any button with data-action="toggle-theme"
    document.addEventListener('click', (e) => {
      const btn = e.target.closest('[data-action="toggle-theme"]');
      if (btn) {
        e.preventDefault();
        this.toggle();
      }
    });
  },

  apply(theme) {
    document.documentElement.setAttribute('data-theme', theme);
    document.documentElement.dataset.theme = theme;
    if (document.body) {
      document.body.setAttribute('data-theme', theme);
    }
    localStorage.setItem(this.STORAGE_KEY, theme);
    this.updateToggleButtons();
    window.dispatchEvent(new CustomEvent('hipdc-theme-changed', { detail: { theme } }));
  },

  toggle() {
    const current = document.documentElement.getAttribute('data-theme') || localStorage.getItem(this.STORAGE_KEY) || 'light';
    const next = current === 'dark' ? 'light' : 'dark';
    this.apply(next);
  },

  updateToggleButtons() {
    const current = document.documentElement.getAttribute('data-theme') || localStorage.getItem(this.STORAGE_KEY) || 'light';
    document.querySelectorAll('[data-action="toggle-theme"]').forEach(btn => {
      btn.textContent = current === 'dark' ? '☀ Light' : '☾ Dark';
      btn.setAttribute('title', current === 'dark' ? 'Switch to Light mode' : 'Switch to Dark mode');
    });
  }
};

// Immediate execution so theme applies before flash of unstyled content
(function() {
  const saved = localStorage.getItem('hipdcTheme') || 'light';
  document.documentElement.setAttribute('data-theme', saved);
  document.documentElement.dataset.theme = saved;
})();

document.addEventListener('DOMContentLoaded', () => {
  HIPDC_Theme.init();
});
