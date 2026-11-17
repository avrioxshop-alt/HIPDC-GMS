/**
 * HIPDC Gate & Logistics Management - Navigation & Routing Helpers
 */

const HIPDC_Nav = {
  init() {
    this.highlightActivePage();
    this.bindEvents();
  },

  highlightActivePage() {
    const currentPath = window.location.pathname.toLowerCase();
    document.querySelectorAll('aside .nav').forEach(link => {
      const href = link.getAttribute('href');
      if (href) {
        const cleanHref = href.split('?')[0].split('#')[0].toLowerCase();
        if (currentPath.endsWith(cleanHref) || (cleanHref !== '' && currentPath.includes(cleanHref))) {
          link.classList.add('on');
        } else {
          link.classList.remove('on');
        }
      }
    });
  },

  bindEvents() {
    // Language toggle buttons
    document.querySelectorAll('[data-action="toggle-lang"]').forEach(btn => {
      btn.addEventListener('click', (e) => {
        e.preventDefault();
        if (window.HIPDC_I18N) {
          window.HIPDC_I18N.toggle();
        }
      });
    });

    // Theme toggle buttons
    document.querySelectorAll('[data-action="toggle-theme"]').forEach(btn => {
      btn.addEventListener('click', (e) => {
        e.preventDefault();
        if (window.HIPDC_Theme) {
          window.HIPDC_Theme.toggle();
        }
      });
    });

    // Sign out handler
    document.querySelectorAll('[data-action="logout"]').forEach(btn => {
      btn.addEventListener('click', async (e) => {
        e.preventDefault();
        btn.disabled = true;
        try {
          await HIPDC_API.logout();
          const loginUrl = window.location.pathname.includes('/pages/')
            ? '../auth/login.html'
            : 'pages/auth/login.html';
          window.location.replace(loginUrl);
        } catch (error) {
          HIPDC_Notify.show('Sign out failed: ' + error.message, 'bad', 0);
          btn.disabled = false;
        }
      });
    });
  }
};

document.addEventListener('DOMContentLoaded', () => {
  HIPDC_Nav.init();
});
