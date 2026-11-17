# HIPDC Gate & Logistics Management — Design Specification

## 1. Overview & Brand Identity
The **Hawassa Industrial Park Development Corporation (HIPDC)** Gate Management System frontend provides security and administrative personnel with an intuitive, high-contrast, and responsive interface for compound vehicle control, live ANPR camera monitoring, and cargo exit clearance.

### Visual Foundations
* **Corporate Primary**: `#1f5f8b` (Deep HIPDC Industrial Blue)
* **Accent Sky**: `#9cd3ff` (ANPR detection highlight & active link indicator)
* **Dark Background**: `#0b141c` (CCTV feed night mode & camera contrast)
* **Light Background**: `#eef2f5` (Daytime outdoor sunlight readability)
* **Ethiopian Plate Highlighting**: High-contrast `#ffffff` plates with bold `#0e1b26` typography and 4px letter-spacing.

---

## 2. Accessibility & Modal Close Pattern (X Button)

To guarantee that security personnel working under fast-paced gate conditions can quickly dismiss any dialog, modal, or toast alert:

### Modal Architecture (`.modal`, `.ov`)
1. **Prominent Top-Right `✕` Button**:
   * Class: `.modal-close-btn`
   * Target Size: Minimum 32×32px clickable hit target.
   * Visual feedback: Circular hover highlight with smooth scaling.
   * ARIA attribute: `aria-label="Close dialog"`.
2. **Overlay Click-Outside**:
   * Clicking anywhere on `.ov` (backdrop with `backdrop-filter: blur(4px)`) safely dismisses the modal.
3. **Keyboard `Escape` Trigger**:
   * Global event listener in `modal.js` traps `Escape` key events and closes the currently active modal.

### Toast Notifications (`#toast`)
1. **Interactive Close `✕` Button**:
   * Positioned on the right side of the toast pill.
   * Instant dismissal on tap/click without waiting for the 4-second timeout.

---

## 3. Exit Goods Declaration & PDF Download Specification

### Dedicated Left Navigation Tab (Not a Modal)
* In `pages/exit/`, the **Exit Goods Form** (`fill-form.html`) is structured as a first-class navigation item on the left sidebar.
* Avoids accidental dismissal or scroll trapping common in heavy modal dialogs.
* Supports multi-item consignment tables, container numbers, seal verification, and photo uploads.

### Official PDF Gate Pass Document
* **Layout**: Standard ISO A4 format, styled specifically for `@media print`.
* **Letterhead**: Official Hawassa Industrial Park Development Corporation emblem, gate pass reference code, and date.
* **Sections**:
  1. Vehicle & Driver Identification (Plate, Model, Driver, Phone, Company)
  2. Logistics Clearance (Direction, Cargo category, Final destination port, Waybill)
  3. Consignment & Container Seals Manifest (Tabular view with quantities and seal numbers)
  4. Tripartite Signature Block (Security Officer, Park Administration, Transport Driver)
* **Instant Action**: Triggered via `HIPDC_PDF.generateExitLogPDF()`, opening a print/PDF dialog with fallback HTML document download.

---

## 4. Empty State UX Specification

When connecting to an empty database or prior to backend data sync:
* Never display broken tables or blank screens.
* Render the `.empty-state` component:
  * 56px circular muted icon
  * Clear title (e.g., "No Scheduled Arrivals Today")
  * Descriptive text explaining why no data is shown and how it will appear once recorded
  * Optional call to action button (e.g., "Schedule a Vehicle", "Add Vehicle")
