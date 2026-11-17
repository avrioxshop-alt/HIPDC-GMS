/**
 * HIPDC Gate & Logistics Management - Modal System
 * Guarantees every modal has an easy-to-use X (Close) button, Escape key support,
 * and backdrop-click close functionality.
 */

const HIPDC_Modal = {
  activeModal: null,

  init() {
    // Global listener for Escape key to close any active modal
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && this.activeModal) {
        this.close();
      }
    });

    // Global listener for clicking backdrop or close buttons
    document.addEventListener('click', (e) => {
      // Clicked close button
      if (e.target.closest('[data-close-modal]')) {
        e.preventDefault();
        this.close();
        return;
      }

      // Clicked overlay outside modal content
      if (e.target.classList && e.target.classList.contains('ov')) {
        this.close();
      }
    });
  },

  /**
   * Opens a modal element or renders modal content
   * Ensures an X (Close) button is present in the header.
   */
  open(contentOrElement) {
    this.close(); // Close existing if any

    let overlay;
    if (typeof contentOrElement === 'string') {
      overlay = document.createElement('div');
      overlay.className = 'ov';
      overlay.innerHTML = contentOrElement;
      document.body.appendChild(overlay);
    } else {
      overlay = contentOrElement;
      overlay.style.display = 'grid';
    }

    // Ensure there is an X button inside .modal if not already present
    const modalBox = overlay.querySelector('.modal');
    if (modalBox && !modalBox.querySelector('[data-close-modal]')) {
      const header = modalBox.querySelector('.mh');
      const closeBtn = document.createElement('button');
      closeBtn.type = 'button';
      closeBtn.className = 'modal-close-btn';
      closeBtn.setAttribute('data-close-modal', 'true');
      closeBtn.setAttribute('aria-label', 'Close dialog');
      closeBtn.innerHTML = '&times;';

      if (header) {
        header.appendChild(closeBtn);
      } else {
        closeBtn.style.position = 'absolute';
        closeBtn.style.top = '16px';
        closeBtn.style.right = '16px';
        modalBox.insertBefore(closeBtn, modalBox.firstChild);
      }
    }

    this.activeModal = overlay;
    document.body.style.overflow = 'hidden'; // prevent background scrolling
    return overlay;
  },

  close() {
    if (!this.activeModal) return;

    if (this.activeModal.dataset && this.activeModal.dataset.static === 'true') {
      this.activeModal.style.display = 'none';
    } else if (this.activeModal.parentNode) {
      this.activeModal.parentNode.removeChild(this.activeModal);
    }

    this.activeModal = null;
    document.body.style.overflow = '';
  }
};

document.addEventListener('DOMContentLoaded', () => {
  HIPDC_Modal.init();
});
