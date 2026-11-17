/**
 * Generates a printable client-side summary of data already supplied by the UI.
 */

const HIPDC_PDF = {
  /**
   * Generates and downloads or triggers print-to-PDF for an Exit Gate Log record
   * @param {Object} data 
   */
  generateExitLogPDF(data) {
     const escapeHtml = value => String(value ?? '—').replace(/[&<>"']/g, character => ({
       '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
     })[character]);
    const record = {
      id: data.reference || data.id || 'UNSAVED DRAFT',
      date: data.date || HIPDC_Utils.getTodayString(),
      time: data.time || HIPDC_Utils.getNowTime(),
      officer: data.officer || '—',
      plate: data.plate || '—',
      driver: data.driver || '—',
      phone: data.phone || '—',
      company: data.company || '—',
      model: data.model || '—',
      direction: data.direction || '—',
      loadType: data.loadType || '—',
      destination: data.destination || '—',
      waybill: data.waybill || '—',
      authorizedBy: data.authorizedBy || '—',
      status: data.status || 'Draft / not submitted',
      adminApproval: data.adminApproval || 'Not provided',
      notes: data.notes || 'No notes provided.',
      items: data.items || []
    };

    const printableHtml = `
      <!DOCTYPE html>
      <html lang="en">
      <head>
        <meta charset="UTF-8">
        <title>HIPDC Operational Record - ${escapeHtml(record.plate)} - ${escapeHtml(record.id)}</title>
        <style>
          * { box-sizing: border-box; margin: 0; padding: 0; }
          body {
            font-family: 'Segoe UI', Arial, sans-serif;
            background: #fff;
            color: #111;
            padding: 40px;
            font-size: 12px;
            line-height: 1.5;
          }
          .ticket {
            max-width: 800px;
            margin: 0 auto;
            border: 2px solid #0f2a3f;
            padding: 30px;
            border-radius: 8px;
            position: relative;
          }
          .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 2px solid #0f2a3f;
            padding-bottom: 16px;
            margin-bottom: 20px;
          }
          .brand-title h1 {
            font-size: 20px;
            color: #0f2a3f;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 4px;
          }
          .brand-title p {
            font-size: 11px;
            color: #555;
          }
          .pass-badge {
            text-align: right;
          }
          .pass-badge .num {
            font-size: 16px;
            font-weight: bold;
            color: #1f5f8b;
          }
          .stamp {
            display: inline-block;
            border: 2px solid #1f8a4c;
            color: #1f8a4c;
            font-weight: bold;
            text-transform: uppercase;
            padding: 4px 10px;
            border-radius: 4px;
            margin-top: 6px;
            font-size: 11px;
          }
          .stamp.pending {
            border-color: #b9740a;
            color: #b9740a;
          }
          .section-title {
            background: #f0f4f8;
            padding: 6px 10px;
            font-weight: bold;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #0f2a3f;
            margin: 16px 0 10px 0;
            border-left: 4px solid #1f5f8b;
          }
          .grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
          }
          .field-row {
            display: flex;
            justify-content: space-between;
            border-bottom: 1px dashed #ddd;
            padding: 4px 0;
          }
          .field-label {
            color: #666;
            font-weight: 500;
          }
          .field-val {
            font-weight: 600;
            color: #000;
          }
          table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
          }
          th, td {
            border: 1px solid #ccc;
            padding: 8px 10px;
            text-align: left;
            font-size: 11px;
          }
          th {
            background: #f5f8fb;
            font-weight: bold;
          }
          .signatures {
            margin-top: 40px;
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            text-align: center;
          }
          .sig-line {
            border-top: 1px solid #000;
            margin-top: 40px;
            padding-top: 6px;
            font-size: 10px;
            color: #444;
          }
          .footer {
            margin-top: 30px;
            border-top: 1px solid #eee;
            padding-top: 10px;
            display: flex;
            justify-content: space-between;
            font-size: 10px;
            color: #777;
          }
          .print-actions {
            text-align: center;
            margin-bottom: 20px;
          }
          .print-btn {
            background: #1f5f8b;
            color: #fff;
            border: none;
            padding: 10px 24px;
            border-radius: 6px;
            font-size: 14px;
            font-weight: bold;
            cursor: pointer;
          }
          @media print {
            .print-actions { display: none; }
            body { padding: 0; }
            .ticket { border: 1px solid #000; }
          }
        </style>
      </head>
      <body>
        <div class="print-actions">
          <button class="print-btn" onclick="window.print()">🖨️ Click Here to Print or Save as PDF</button>
        </div>

        <div class="ticket">
          <div class="header">
            <div class="brand-title">
              <h1>Hawassa Industrial Park</h1>
              <p>Industrial Parks Development Corporation (HIPDC) · Operational record summary</p>
              <p>This client-generated summary is not a gate pass or proof of physical entry/exit.</p>
            </div>
            <div class="pass-badge">
              <div class="num">RECORD: ${escapeHtml(record.id)}</div>
              <div class="stamp ${record.status === 'Authorized' ? '' : 'pending'}">${escapeHtml(record.status)}</div>
            </div>
          </div>

          <div class="section-title">1. Vehicle & Driver Identification</div>
          <div class="grid">
            <div>
              <div class="field-row"><span class="field-label">License Plate:</span><span class="field-val">${escapeHtml(record.plate)}</span></div>
              <div class="field-row"><span class="field-label">Vehicle Model:</span><span class="field-val">${escapeHtml(record.model)}</span></div>
              <div class="field-row"><span class="field-label">Tenant / Company:</span><span class="field-val">${escapeHtml(record.company)}</span></div>
            </div>
            <div>
              <div class="field-row"><span class="field-label">Driver Name:</span><span class="field-val">${escapeHtml(record.driver)}</span></div>
              <div class="field-row"><span class="field-label">Phone Number:</span><span class="field-val">${escapeHtml(record.phone)}</span></div>
              <div class="field-row"><span class="field-label">Record Timestamp:</span><span class="field-val">${escapeHtml(record.date)} at ${escapeHtml(record.time)}</span></div>
            </div>
          </div>

          <div class="section-title">2. Consignment & Logistics Clearance</div>
          <div class="grid">
            <div>
              <div class="field-row"><span class="field-label">Gate Direction:</span><span class="field-val">${escapeHtml(record.direction)}</span></div>
              <div class="field-row"><span class="field-label">Load Category:</span><span class="field-val">${escapeHtml(record.loadType)}</span></div>
              <div class="field-row"><span class="field-label">Final Destination:</span><span class="field-val">${escapeHtml(record.destination)}</span></div>
            </div>
            <div>
              <div class="field-row"><span class="field-label">Waybill / Record Ref:</span><span class="field-val">${escapeHtml(record.waybill)}</span></div>
              <div class="field-row"><span class="field-label">Authorized By (Tenant):</span><span class="field-val">${escapeHtml(record.authorizedBy)}</span></div>
              <div class="field-row"><span class="field-label">Security Officer:</span><span class="field-val">${escapeHtml(record.officer)}</span></div>
            </div>
          </div>

          <div class="section-title">3. Itemized Cargo & Seal Manifest</div>
          <table>
            <thead>
              <tr>
                <th>#</th>
                <th>Item Description</th>
                <th>Quantity</th>
                <th>Unit</th>
                <th>Container No.</th>
                <th>Seal No.</th>
              </tr>
            </thead>
            <tbody>
              ${record.items.map((it, idx) => `
                <tr>
                  <td>${idx + 1}</td>
                  <td><b>${escapeHtml(it.description || it.item)}</b></td>
                  <td>${escapeHtml(it.quantity ?? it.qty)}</td>
                  <td>${escapeHtml(it.unit_of_measure || it.unit)}</td>
                  <td>${escapeHtml(it.serial_number || it.container)}</td>
                  <td>${escapeHtml(it.remarks || it.seal)}</td>
                </tr>
              `).join('') || '<tr><td colspan="6">No items provided.</td></tr>'}
            </tbody>
          </table>

          <div class="section-title">4. Security Verification & Inspection Notes</div>
          <p style="padding: 6px 0; color: #222;">${escapeHtml(record.notes)}</p>

          <p>This client-generated document is a convenience summary only. Confirm authoritative status in HIPDC-GMS. It is not proof of physical crossing.</p>

          <div class="footer">
            <span>HIPDC Security Division · Hawassa, Sidama Region, Ethiopia</span>
            <span>Generated on ${escapeHtml(record.date)} · Record ID: ${escapeHtml(record.id)}</span>
          </div>
        </div>

        <script>
          // Automatically prompt print dialog when opened
          window.addEventListener('load', () => {
            setTimeout(() => { window.print(); }, 400);
          });
        </script>
      </body>
      </html>
    `;

    // Open print preview window with direct print prompt
    const printWindow = window.open('', '_blank');
    if (printWindow) {
      printWindow.document.write(printableHtml);
      printWindow.document.close();
      if (window.HIPDC_Notify) {
        window.HIPDC_Notify.show('Opening printable operational record.', 'ok');
      }
    } else {
      // Fallback for pop-up blocked: download as HTML-based PDF document
      const blob = new Blob([printableHtml], { type: 'text/html' });
      const a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = `HIPDC_Operational_Record_${String(record.plate).replace(/[^\w-]+/g, '_')}_${String(record.id).replace(/[^\w-]+/g, '_')}.html`;
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      if (window.HIPDC_Notify) {
        window.HIPDC_Notify.show('Printable record saved as an HTML file.', 'ok');
      }
    }
  }
};
