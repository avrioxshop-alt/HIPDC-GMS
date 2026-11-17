/**
 * HIPDC Gate & Logistics Management - Approval Badge Component
 * Localized with English & Amharic translations.
 */

const HIPDC_ApprovalBadge = {
  render(status) {
    const s = String(status || 'Pending').toLowerCase();
    if (s === 'approved') {
      return `<span class="chip c-ok"><i class="dot"></i> ${HIPDC_Utils.t('Approved')}</span>`;
    }
    if (s === 'denied' || s === 'rejected') {
      return `<span class="chip c-bad"><i class="dot"></i> ${HIPDC_Utils.t('Denied')}</span>`;
    }
    return `<span class="chip c-warn"><i class="dot"></i> ${HIPDC_Utils.t('Pending Approval')}</span>`;
  }
};
