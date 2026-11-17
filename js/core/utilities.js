/**
 * HIPDC Gate & Logistics Management - Core Utilities & Full i18n Translation Engine
 * Provides instant English / Amharic translation and formatting.
 */

const HIPDC_DICTIONARY = {
  // Navigation & Core Roles
  'Dashboard': 'ዳሽቦርድ',
  'Schedules': 'መርሐ ግብሮች',
  'Reg. Vehicles': 'የተመዘገቡ ተሽከርካሪዎች',
  'Registered Vehicles': 'የተመዘገቡ ተሽከርካሪዎች',
  'Logistics': 'ሎጂስቲክስ',
  'Approvals': 'ፈቃዶች',
  'Visitors': 'ጎብኚዎች',
  'Cameras': 'ካሜራዎች',
  'Users & Roles': 'ተጠቃሚዎች እና ሚናዎች',
  'Users': 'ተጠቃሚዎች',
  'Exit Goods Form': 'የዕቃ መውጫ ቅጽ',
  'Fill Goods Declaration': 'የዕቃ መግለጫ ቅጽ ሙላ',
  'Fill Out Form': 'ቅጽ ሙላ',
  'Sign out': 'ውጣ',
  'Gate Management': 'የበር አስተዳደር',
  'Park Console': 'የፓርክ ኮንሶል',
  'Entry Gate': 'መግቢያ በር',
  'Exit Gate': 'መውጫ በር',
  'Admin': 'አስተዳዳሪ',
  'Admin Console': 'የአስተዳዳሪ ኮንሶል',
  'Head officer': 'ዋና የደህንነት ኃላፊ',
  'Park Administration': 'የፓርክ አስተዳደር',

  // Authentication & Login
  'Welcome back': 'እንኳን ደህና መጡ',
  'Sign in': 'ግባ',
  'Username': 'የተጠቃሚ ስም',
  'Password': 'የይለፍ ቃል',
  'Entry gate': 'መግቢያ በር',
  'Exit gate': 'መውጫ በር',
  'Signing in as': 'የሚገቡት እንደ',
  'Sign in with your officer or admin security credentials': 'በጥበቃ ወይም በአስተዳዳሪ መለያዎ ይግቡ',
  'Gate & Logistics Management': 'የበር እና ሎጂስቲክስ አስተዳደር',

  // Headers, Shifts & System Badges
  'Day shift · 06:00–18:00': 'የቀን ፈረቃ · 06:00–18:00',
  'Lane 1 online': 'መስመር 1 በርቷል',
  'Lane 1 Online': 'መስመር 1 በርቷል',
  'Lane 2 online': 'መስመር 2 በርቷል',
  'Lane 2 Online': 'መስመር 2 በርቷል',
  'Wed 11 Nov 2026': 'ረቡዕ 11 ኅዳር 2026',
  'Entry Gate Dashboard': 'የመግቢያ በር ዳሽቦርድ',
  'Exit Gate Dashboard': 'የመውጫ በር ዳሽቦርድ',
  'Central Operations Dashboard': 'ማዕከላዊ የአስተዳደር ዳሽቦርድ',
  'Gate Approvals & Clearances': 'የበር ፈቃዶች እና ማረጋገጫዎች',
  'Expected Vehicle Schedules': 'የታቀዱ የተሽከርካሪ መርሐ ግብሮች',
  'Expected Departures': 'የሚጠበቁ መውጫዎች',
  'Vehicle Schedules Management': 'የተሽከርካሪ መርሐ ግብር አስተዳደር',
  'Registered Park Fleet & Vehicles': 'የተመዘገቡ የፓርክ ተሽከርካሪዎች',
  'Registered Vehicle Database': 'የተመዘገቡ ተሽከርካሪዎች ማከማቻ',
  'Entry Gate Access Log': 'የመግቢያ በር መዝገብ',
  'Exit Gate Clearance Log & Downloads': 'የመውጫ በር ፈቃድ እና ሎግ ማውረጃ',
  'Consolidated Gate Logistics Log': 'የበር ሎጂስቲክስ መዝገብ',
  'Park CCTV & ANPR Camera Grid': 'የካሜራ እና ANPR እይታ',
  'Security Personnel & User Accounts': 'የጥበቃ አባላት እና የተጠቃሚ መለያዎች',
  'Visitor Clearance & QR Passes': 'የጎብኚዎች ፈቃድ እና QR ፓስ',

  // Metric Cards & Stats
  'Scheduled Vehicles': 'የታቀዱ ተሽከርካሪዎች',
  'Entered Today': 'ዛሬ የገቡ',
  'Exited Today': 'ዛሬ የወጡ',
  'Entered Vehicles': 'የገቡ ተሽከርካሪዎች',
  'Exited Vehicles': 'የወጡ ተሽከርካሪዎች',
  'Vehicles in Park': 'በግቢ ያሉ ተሽከርካሪዎች',
  'Vehicles in Compound': 'በግቢ ያሉ ተሽከርካሪዎች',
  'Visitors Inside': 'በግቢ ያሉ ጎብኚዎች',
  'Visitors in Compound': 'በግቢ ያሉ ጎብኚዎች',
  'Departures Scheduled': 'የታቀዱ መውጫዎች',
  'Cargo Cleared': 'የተፈቀደ ጭነት',
  'Vehicles Inside': 'በግቢ ያሉ ተሽከርካሪዎች',
  'Exit Lane Status': 'የመውጫ መስመር ሁኔታ',
  'Scheduled': 'የታቀደ',
  'Entered': 'የገባ',
  'Exited': 'የወጣ',
  'In Compound': 'በግቢ ውስጥ',
  'Pending Approvals': 'ፈቃድ የሚጠብቁ',
  'Vehicles Let In': 'የገቡ ተሽከርካሪዎች',
  'Vehicles Let Out': 'የወጡ ተሽከርካሪዎች',
  'Scheduled Arrivals': 'የታቀዱ መግቢያዎች',
  'Visitor Passes': 'የጎብኚዎች ፈቃድ',
  'Unscheduled (Admin Approved)': 'ያልታቀደ (የተፈቀደ)',
  'Empty Vehicles': 'ባዶ ተሽከርካሪዎች',
  'Cleared with Goods': 'ከዕቃ ጋር የወጡ',
  'Admin Approved Dispatches': 'በአስተዳዳሪ የተፈቀዱ',

  // Actions & Buttons
  'Open Front Gate': 'የመግቢያ በር ክፈት',
  'Open Exit Gate': 'የመውጫ በር ክፈት',
  'Open Exit Gate Barrier': 'የመውጫ በር ክፈት',
  'Deny Entry': 'መግባት ከልክል',
  'Not Scheduled': 'አልታቀደም',
  'Submit for Admin Approval': 'ለአስተዳዳሪ ፈቃድ ላክ',
  'Submit Declaration for Admin Approval': 'የዕቃ መግለጫውን ለአስተዳዳሪ ፈቃድ ላክ',
  'Search List': 'ዝርዝር ፈልግ',
  'Search Database': 'መረጃ ቋት ፈልግ',
  'Cancel': 'ሰርዝ',
  'Approve': 'ፍቀድ',
  'Approve Clearance': 'ፈቃድ አጽድቅ',
  'Deny': 'ከልክል',
  'Deny Entry/Exit': 'መግባት/መውጣት ከልክል',
  'Call Officer': 'ለጥበቃው ደውል',
  'Vehicle Arrived': 'ተሽከርካሪው ደርሷል',
  'Vehicle Leaving': 'ተሽከርካሪው እየወጣ ነው',
  'Register Entry': 'መግቢያ መዝግብ',
  'Register Exit': 'መውጫ መዝግብ',
  '+ Schedule a Vehicle': '+ ተሽከርካሪ መርሐ ግብር ያዝ',
  '+ Register Visitor': '+ ጎብኚ መዝግብ',
  '+ Register Vehicle': '+ ተሽከርካሪ መዝግብ',
  'Register Vehicle': 'ተሽከርካሪ መዝግብ',
  'Block Access': 'እገዳ ጣል',
  'Unblock': 'እገዳ አንሳ',
  'View QR Pass': 'QR ፓስ ተመልከት',
  'Print Pass': 'ፓስ አትም',
  'Close': 'ዝጋ',
  'Download PDF': 'PDF አውርድ',
  'Download Exit Log (PDF)': 'የመውጫ ሎግ አውርድ (PDF)',
  'Export / Print': 'ላክ / አትም',
  'Return to Dashboard': 'ወደ ዳሽቦርድ ተመለስ',
  'Simulate Scheduled Arrival': 'የታቀደ መድረሻ ሞክር',
  'Simulate Unscheduled (Needs Approval)': 'ያልታቀደ መድረሻ ሞክር (ፈቃድ የሚፈልግ)',
  'Simulate Unknown Plate': 'ያልታወቀ ታርጋ ሞክር',
  'Simulate Empty Vehicle Leaving': 'ባዶ መኪና መውጣቱን ሞክር',
  'Simulate Cargo Leaving (Fill Form)': 'የዕቃ ጭነት መውጣቱን ሞክር (ቅጽ ሙላ)',

  // Statuses & Badges
  'Active': 'ንቁ',
  'Expected': 'ይጠበቃል',
  'Inside': 'በግቢ ውስጥ',
  'Arrived': 'ደርሷል',
  'Approved': 'ተፈቅዷል',
  'Denied': 'ተከልክሏል',
  'Pending': 'በመጠባበቅ ላይ',
  'Pending Approval': 'ፈቃድ በመጠባበቅ ላይ',
  'Pending Clearance': 'ፈቃድ በመጠባበቅ ላይ',
  'Blocked': 'የታገደ',
  'Expiring': 'ሊያበቃ የቀረበ',
  'Left with goods': 'ከዕቃ ጋር የወጣ',
  'Dropped off goods': 'ዕቃ ያወረደ',
  'Left empty': 'ባዶውን የወጣ',
  'Approved by Admin': 'በአስተዳዳሪ የተፈቀደ',
  'Approved by admin': 'በአስተዳዳሪ የተፈቀደ',
  'Requires Admin Approval': 'የአስተዳዳሪ ፈቃድ ይፈልጋል',
  'All History': 'ሙሉ ታሪክ',

  // Table Columns
  'Company': 'ኩባንያ',
  'Tenant / Company': 'ተከራይ / ኩባንያ',
  'Plate': 'ታርጋ',
  'Driver': 'አሽከርካሪ',
  'Car Model': 'የመኪና ሞዴል',
  'Arrival Window': 'የመድረሻ ሰዓት',
  'Departure Window': 'የመውጫ ሰዓት',
  'Window': 'የጊዜ መስኮት',
  'Purpose': 'ዓላማ',
  'Status': 'ሁኔታ',
  'Action': 'እርምጃ',
  'Licence Expiry': 'የመንጃ ፈቃድ ማብቂያ',
  'Security Action': 'የደህንነት እርምጃ',
  'Time': 'ሰዓት',
  'Gate': 'በር',
  'Admin Approval': 'የአስተዳዳሪ ፈቃድ',
  'Details': 'ዝርዝር',
  'Cargo Details': 'የዕቃ ዝርዝር',
  'Cargo': 'ጭነት / ዕቃ',
  'Contains': 'የያዘው ዕቃ',
  'Vehicle Cargo Details': 'የተሽከርካሪው ዕቃ ዝርዝር',
  'Load Sample Schedules': 'የመርሐ ግብር ምሳሌዎችን አሳይ',
  'Load Sample Departures': 'የመውጫ ምሳሌዎችን አሳይ',
  'PDF Pass': 'PDF ፓስ',
  'Official Gate Pass': 'ይፋዊ የበር ፈቃድ',
  'Visitor Name': 'የጎብኚ ስም',
  'Host Tenant': 'አስተናጋጅ ድርጅት',
  'Visit Date': 'የጉብኝት ቀን',
  'Pass Status': 'የፓስ ሁኔታ',
  'Full Name': 'ሙሉ ስም',
  'Username': 'የተጠቃሚ ስም',
  'Security Role': 'የደህንነት ሚና',
  'Assigned Terminal': 'የተመደበበት በር',
  'Working Shift': 'የስራ ፈረቃ',
  'Last Login': 'የመጨረሻ መግቢያ',
  'Account Status': 'የመለያ ሁኔታ',
  'Request Title': 'የፈቃድ ርዕስ',
  'Officer': 'የጥበቃ ኃላፊ',

  // Form Fields & Sections
  'License Plate Number': 'የሰሌዳ ቁጥር',
  'Driver Full Name': 'የአሽከርካሪ ሙሉ ስም',
  'Driver Phone': 'የአሽከርካሪ ስልክ',
  'Direction / Action': 'አቅጣጫ / እርምጃ',
  'Load Category': 'የጭነት ዓይነት',
  'Destination / Port of Exit': 'መዳረሻ / የወደብ ስም',
  'Item Description': 'የዕቃ ዝርዝር',
  'Quantity': 'ብዛት',
  'Unit': 'መለኪያ',
  'Container No.': 'የኮንቴይነር ቁጥር',
  'Seal No.': 'የማሸጊያ (ሲል) ቁጥር',
  'Tenant Gate Pass / Waybill No.': 'የተከራይ ዌይቢል / ጌት ፓስ ቁጥር',
  'Authorized by (Tenant Representative)': 'ያረጋገጠው (የድርጅቱ ተወካይ)',
  'Vehicle Make / Model': 'የተሽከርካሪው ዓይነት',
  'Security Inspection Remarks & Seal Check': 'የደህንነት ምርመራ ማስታወሻ እና የሲል ቁጥር ማረጋገጫ'
};

const HIPDC_ICONS = {
  dash: '<rect x="3" y="3" width="7" height="7" rx="2"/><rect x="14" y="3" width="7" height="7" rx="2"/><rect x="3" y="14" width="7" height="7" rx="2"/><rect x="14" y="14" width="7" height="7" rx="2"/>',
  cal: '<rect x="3" y="5" width="18" height="16" rx="3"/><path d="M3 10h18M8 3v4M16 3v4"/>',
  car: '<path d="M5 17h14M4 17v-4l2-5h12l2 5v4M7 17v2M17 17v2"/>',
  list: '<path d="M9 6h12M9 12h12M9 18h12M4 6h.01M4 12h.01M4 18h.01"/>',
  chk: '<path d="M5 12l5 5L20 7"/>',
  usr: '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/>',
  qr: '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><path d="M14 14h3v3M21 14v7h-4"/>',
  cam: '<path d="M3 8h13v9H3zM16 11l5-3v9l-5-3"/>',
  grp: '<circle cx="9" cy="8" r="3.5"/><circle cx="17" cy="9" r="2.5"/><path d="M2 20c0-4 3-6 7-6s7 2 7 6M17 14c3 0 5 2 5 5"/>',
  key: '<rect x="4" y="10" width="16" height="11" rx="3"/><path d="M8 10V7a4 4 0 018 0v3"/>',
  srch: '<circle cx="11" cy="11" r="7"/><path d="M21 21l-5-5"/>',
  form: '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/>',
  pdf: '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><path d="M9 15v-4h2a1.5 1.5 0 0 1 0 3H9"/><path d="M14 11v4"/><path d="M17 11h-3v4"/>',
  close: '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
  empty: '<circle cx="12" cy="12" r="10"/><line x1="8" y1="12" x2="16" y2="12"/>',
  download: '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>'
};

const HIPDC_I18N = {
  STORAGE_KEY: 'hipdcLang',
  dictionary: HIPDC_DICTIONARY,

  getLang() {
    return localStorage.getItem(this.STORAGE_KEY) || 'en';
  },

  init() {
    const lang = this.getLang();
    this.apply(lang, false);

    // Global listener for language toggle button
    document.addEventListener('click', (e) => {
      const btn = e.target.closest('[data-action="toggle-lang"]');
      if (btn) {
        e.preventDefault();
        this.toggle();
      }
    });
  },

  toggle() {
    const current = this.getLang();
    const next = current === 'en' ? 'am' : 'en';
    this.apply(next, true);
  },

  apply(lang, notify = true) {
    localStorage.setItem(this.STORAGE_KEY, lang);
    document.documentElement.lang = lang === 'am' ? 'am' : 'en';
    if (document.body) {
      if (lang === 'am') {
        document.body.classList.add('lang-am');
      } else {
        document.body.classList.remove('lang-am');
      }
    }

    // Update all language toggle buttons
    document.querySelectorAll('[data-action="toggle-lang"]').forEach(btn => {
      btn.textContent = lang === 'am' ? 'አማ | EN' : 'EN | አማ';
      btn.setAttribute('title', lang === 'am' ? 'Switch to English' : 'ወደ አማርኛ ቀይር');
    });

    // Translate DOM elements
    this.translateDOM(lang);

    // Re-render role tables so dynamic contents match language
    if (window.HIPDC_Entry && typeof window.HIPDC_Entry.renderPageData === 'function') {
      window.HIPDC_Entry.renderPageData();
    }
    if (window.HIPDC_Exit && typeof window.HIPDC_Exit.renderPageData === 'function') {
      window.HIPDC_Exit.renderPageData();
    }
    if (window.HIPDC_Admin && typeof window.HIPDC_Admin.renderPageData === 'function') {
      window.HIPDC_Admin.renderPageData();
    }

    if (notify && window.HIPDC_Notify) {
      window.HIPDC_Notify.show(lang === 'am' ? 'ቋንቋው ወደ አማርኛ ተቀይሯል' : 'Language switched to English', 'ok', 2000);
    }
  },

  translateDOM(lang) {
    const isAmharic = lang === 'am';

    // 1. Walk elements that have text
    const selectors = 'h1, h2, h3, h4, h5, h6, th, td, label, .lbl, .sec, .pill, .btn, .nav, .stat span, .chip, b, p, small, .brand p, .brand small';
    document.querySelectorAll(selectors).forEach(el => {
      // Don't translate inputs, SVGs, or language toggle itself
      if (el.matches('[data-action="toggle-lang"]') || el.matches('[data-action="toggle-theme"]') || el.querySelector('input, select, textarea, table')) {
        return;
      }

      // Check child nodes: if only 1 text node or pure text
      const trimmed = el.textContent.trim();
      if (!trimmed || trimmed.length > 120) return;

      // Initialize original English text
      if (!el.dataset.i18nEn) {
        el.dataset.i18nEn = trimmed;
      }

      const original = el.dataset.i18nEn;

      if (isAmharic) {
        if (this.dictionary[original]) {
          // If element has SVG icon inside (like .nav or .btn), preserve the icon
          const svg = el.querySelector('svg');
          if (svg) {
            const svgHtml = svg.outerHTML;
            el.innerHTML = svgHtml + ' ' + this.dictionary[original];
          } else {
            el.textContent = this.dictionary[original];
          }
        }
      } else {
        // Restore English
        const svg = el.querySelector('svg');
        if (svg) {
          const svgHtml = svg.outerHTML;
          el.innerHTML = svgHtml + ' ' + original;
        } else {
          el.textContent = original;
        }
      }
    });

    // 2. Translate Input Placeholders
    document.querySelectorAll('input[placeholder], textarea[placeholder]').forEach(input => {
      if (!input.dataset.i18nPlaceholderEn) {
        input.dataset.i18nPlaceholderEn = input.placeholder;
      }
      const originalPh = input.dataset.i18nPlaceholderEn;

      if (isAmharic) {
        if (this.dictionary[originalPh]) {
          input.placeholder = this.dictionary[originalPh];
        } else if (originalPh.toLowerCase().includes('search')) {
          input.placeholder = 'በዚህ ፈልግ...';
        }
      } else {
        input.placeholder = originalPh;
      }
    });
  }
};

const HIPDC_Utils = {
  esc(str) {
    return String(str ?? '').replace(/[&<>"]/g, c => ({
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '"': '&quot;'
    }[c]));
  },

  t(str) {
    if (!str) return '';
    const lang = HIPDC_I18N.getLang();
    if (lang === 'am' && HIPDC_DICTIONARY[str]) {
      return HIPDC_DICTIONARY[str];
    }
    return str;
  },

  icon(name, size = 18) {
    const d = HIPDC_ICONS[name] || HIPDC_ICONS.dash;
    return `<svg width="${size}" height="${size}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">${d}</svg>`;
  },

  getNowTime() {
    const now = new Date();
    const hours = String(now.getHours()).padStart(2, '0');
    const mins = String(now.getMinutes()).padStart(2, '0');
    return `${hours}:${mins}`;
  },

  getTodayString() {
    const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    const now = new Date();
    return `${now.getDate()} ${months[now.getMonth()]} ${now.getFullYear()}`;
  },

  renderEmptyState({ icon = 'empty', title = 'No records found', desc = 'No dynamic data available.', actionHtml = '' }) {
    return `
      <div class="empty-state">
        <div class="empty-icon">${HIPDC_Utils.icon(icon, 28)}</div>
        <h3 class="empty-title">${HIPDC_Utils.t(title)}</h3>
        <p class="empty-desc">${HIPDC_Utils.t(desc)}</p>
        ${actionHtml ? `<div class="empty-actions">${actionHtml}</div>` : ''}
      </div>
    `;
  }
};

// Initialize i18n on DOM ready
document.addEventListener('DOMContentLoaded', () => {
  HIPDC_I18N.init();
});
