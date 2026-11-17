/**
 * HIPDC Gate & Logistics Management - Notifications & Toast System
 * Provides pop-up alerts with an explicit X (Close) button for instant dismissal.
 */

const HIPDC_Notify = {
  timer: null,

  init() {
    // Ensure the toast container exists
    let toast = document.getElementById('toast');
    if (!toast) {
      toast = document.createElement('div');
      toast.id = 'toast';
      document.body.appendChild(toast);
    }

    // Global listener for closing toasts via X button
    document.addEventListener('click', (e) => {
      if (e.target.closest('[data-close-toast]')) {
        HIPDC_Notify.hide();
      }
      if (e.target.closest('[data-close-alert]')) {
        const alertBox = e.target.closest('.alert-banner, .note');
        if (alertBox) {
          alertBox.style.display = 'none';
        }
      }
    });
  },

  /**
   * Shows a toast notification with text and an X close button
   * @param {string} message 
   * @param {string} type 'info' | 'ok' | 'warn' | 'bad'
   * @param {number} duration Duration in ms (default: 4000)
   */
  show(message, type = 'info', duration = 4000) {
    let toast = document.getElementById('toast');
    if (!toast) {
      this.init();
      toast = document.getElementById('toast');
    }

    clearTimeout(this.timer);

    toast.className = `show toast-${type}`;
    toast.innerHTML = `
      <span class="toast-msg">${HIPDC_Utils.esc(message)}</span>
      <button type="button" class="toast-close-btn" data-close-toast aria-label="Close notification">&times;</button>
    `;

    if (duration > 0) {
      this.timer = setTimeout(() => {
        this.hide();
      }, duration);
    }
  },

  hide() {
    clearTimeout(this.timer);
    const toast = document.getElementById('toast');
    if (toast) {
      toast.className = '';
    }
  }
};

document.addEventListener('DOMContentLoaded', () => {
  HIPDC_Notify.init();
});
