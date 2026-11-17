/**
 * HIPDC Gate & Logistics Management - Exit Gate Controller
 * Localized for English & Amharic.
 */

const HIPDC_Exit = {
  currentExitVehicle: null,
  submittedExitRecord: null,
  goodsDraft: null,
  gateLocations: [],

  init() {
    this.bindEvents();
    this.renderPageData();
    this.initGoodsForm();
    window.addEventListener('hipdc-authenticated', () => {
      this.loadGateLocations();
      this.prefillGoodsVehicle();
      this.restoreGoodsDraft();
    });

    window.addEventListener('hipdc-db-updated', () => {
      this.renderPageData();
      this.checkExitApprovalStatus();
    });
    window.addEventListener('storage', () => {
      this.renderPageData();
      this.checkExitApprovalStatus();
    });
  },

  renderPageData() {
    this.populateGateSelectors();
    const schedulesTbody = document.getElementById('exit_schedules_tbody');
    if (schedulesTbody) {
      this.renderSchedulesTable();
    }

    const vehiclesTbody = document.getElementById('exit_vehicles_tbody');
    if (vehiclesTbody) {
      this.renderVehiclesTable();
    }

    const logisticsTbody = document.getElementById('exit_logistics_tbody');
    if (logisticsTbody) {
      this.renderLogisticsTable();
    }
  },

  async loadGateLocations() {
    try {
      this.gateLocations = (await HIPDC_API.currentGateLocations())
        .filter(location => location.operation_direction === 'exit');
      this.populateGateSelectors();
    } catch (error) {
      HIPDC_Notify.show(error.message, 'bad', 0);
    }
  },

  populateGateSelectors() {
    const gateIds = ['exit_gate_id', 'exit_goods_gate_id', 'form_gate_id'];
    const gates = [...new Map(this.gateLocations.map(location => [location.gate_id, location])).values()];
    const options = gates.map(gate =>
      `<option value="${Number(gate.gate_id)}">${HIPDC_Utils.esc(gate.gate_name)}</option>`
    ).join('');
    gateIds.forEach(id => {
      const select = document.getElementById(id);
      if (select) {
        const selected = select.value;
        select.innerHTML = options || '<option value="">No assigned exit gate</option>';
        if (selected) select.value = selected;
      }
    });
    [['exit_gate_id','exit_lane_id'],['exit_goods_gate_id','exit_goods_lane_id'],['form_gate_id','form_lane_id']]
      .forEach(([gateId, laneId]) => {
        const gate = document.getElementById(gateId);
        const lane = document.getElementById(laneId);
        if (!gate || !lane) return;
        const lanes = this.gateLocations.filter(location =>
          Number(location.gate_id) === Number(gate.value) && location.lane_id
        );
        lane.innerHTML = '<option value="">Any assigned lane</option>' + lanes.map(location =>
          `<option value="${Number(location.lane_id)}">${HIPDC_Utils.esc(location.lane_name)}</option>`
        ).join('');
      });
  },

  simulateDeparture(type) {
    document.getElementById('exit_observed_plate')?.focus();
  },

  showExitModal(veh) {
    const pendingApproval = HIPDC_DB.getApprovals('All').find(a => 
      a.plate === veh.plate && a.gate === 'Exit'
    );

    let approvalNotice = '';
    let openBtnState = '';

    if (pendingApproval) {
      if (pendingApproval.status === 'Pending') {
        approvalNotice = `
          <div class="note c-warn">
            <b>⏳ ${HIPDC_Utils.t('Pending Admin Approval')}</b><br>
            ${HIPDC_Utils.t('Goods declaration submitted at')} ${pendingApproval.time}. ${HIPDC_Utils.t('Exit barrier locked until approved.')}
          </div>
        `;
        openBtnState = 'disabled';
      } else if (pendingApproval.status === 'Approved') {
        approvalNotice = `
          <div class="note c-ok">
            <b>✔ ${HIPDC_Utils.t('Approved by Admin')}</b><br>
            ${HIPDC_Utils.t('Clearance approved. Submit the observed event for server-side authorization.')}
          </div>
        `;
        openBtnState = '';
      } else if (pendingApproval.status === 'Denied' || pendingApproval.status === 'Rejected') {
        approvalNotice = `
          <div class="note c-bad">
            <b>✖ ${HIPDC_Utils.t('Denied')}</b><br>
            ${pendingApproval.note || HIPDC_Utils.t('Cargo exit prohibited by park administration.')}
          </div>
        `;
        openBtnState = 'disabled';
      }
    }

    const modalContent = `
      <div class="modal">
        <div class="mh">
          <div>
            <h3>${HIPDC_Utils.t('Vehicle at Exit Gate')} · ${HIPDC_Utils.getNowTime()}</h3>
            <span class="muted">Manual officer observation · no camera or barrier integration</span>
          </div>
          <button type="button" class="modal-close-btn" data-close-modal aria-label="Close dialog">&times;</button>
        </div>

        <div class="row">
          <div class="photo">👤</div>
          <div class="kv">
            <div><span>${HIPDC_Utils.t('Company')}</span><b>${HIPDC_Utils.esc(veh.company)}</b></div>
            <div><span>${HIPDC_Utils.t('Driver')}</span><b>${HIPDC_Utils.esc(veh.driver)}</b></div>
            <div><span>${HIPDC_Utils.t('Car Model')}</span><b>${HIPDC_Utils.esc(veh.model)}</b></div>
            <div><span>${HIPDC_Utils.t('Presence')}</span><b>Check event history</b></div>
          </div>
        </div>

        <div class="bigplate">${veh.plate}</div>

        ${approvalNotice}

        <div class="acts">
          <a href="fill-form.html?plate=${encodeURIComponent(veh.plate)}" class="btn soft" style="text-align:center">
            ${HIPDC_Utils.icon('form', 16)} ${HIPDC_Utils.t('Fill Goods Declaration')}
          </a>
          <button class="btn" id="btn_open_exit_gate">Record observed exit attempt</button>
        </div>
      </div>
    `;

    HIPDC_Modal.open(modalContent);

    const openGateBtn = document.getElementById('btn_open_exit_gate');
    if (openGateBtn) {
      openGateBtn.addEventListener('click', () => {
        HIPDC_GateControls.processVehicle('exit', veh.plate, {
          driverId: veh.driver_id || null,
          scheduleId: veh.schedule_id || null,
          gateId: veh.gate_id || null,
          laneId: veh.lane_id || null
        }).catch(error => HIPDC_Notify.show(error.message, 'bad', 0));
        HIPDC_Modal.close();
      });
    }
  },

  normalizeGoodsDeclarationStatus(status) {
    return String(status || '').trim().toLowerCase();
  },

  async checkExitApprovalStatus() {
    if (this.currentExitVehicle && HIPDC_Modal.activeModal) {
      this.showExitModal(this.currentExitVehicle);
    }

    const statusBanner = document.getElementById('form_approval_status_banner');
    if (statusBanner && this.submittedExitRecord?.id) {
      try {
        const data = await HIPDC_API.get('goods/view.php', { id: this.submittedExitRecord.id });
        const record = data.declaration || {};
        const normalizedStatus = this.normalizeGoodsDeclarationStatus(record.status || this.submittedExitRecord.status);
        this.goodsDraft = { ...(this.goodsDraft || {}), ...record };
        this.submittedExitRecord = {
          ...(this.submittedExitRecord || {}),
          id: record.id || this.submittedExitRecord.id,
          reference: record.reference || this.submittedExitRecord.reference,
          status: normalizedStatus || this.submittedExitRecord.status
        };
        if (!['draft', 'pending'].includes(normalizedStatus)) {
          sessionStorage.removeItem('hipdc-goods-draft-id');
        }
        this.renderFormStatusBanner(record);
      } catch (error) {
        HIPDC_Notify.show('Could not refresh declaration status: ' + error.message, 'bad', 0);
      }
    }
  },

  initGoodsForm() {
    const exitForm = document.getElementById('exitGoodsForm');
    if (!exitForm) return;

    const params = new URLSearchParams(window.location.search);
    const passedPlate = params.get('plate');
    if (passedPlate) {
      const plateInput = document.getElementById('form_plate');
      if (plateInput) plateInput.value = passedPlate;
      this.prefillGoodsVehicle();
    }

    const addItemBtn = document.getElementById('btn_add_item_row');
    if (addItemBtn) {
      addItemBtn.addEventListener('click', (e) => {
        e.preventDefault();
        this.addItemRow();
      });
    }

    exitForm.addEventListener('submit', (e) => {
      e.preventDefault();
      this.handleGoodsFormSubmit();
    });

    const pdfBtn = document.getElementById('btn_download_form_pdf');
    if (pdfBtn) {
      pdfBtn.addEventListener('click', () => {
        if (this.submittedExitRecord) {
          HIPDC_PDF.generateExitLogPDF(this.submittedExitRecord);
        } else {
          const formData = this.gatherFormData();
          HIPDC_PDF.generateExitLogPDF(formData);
        }
      });
    }
    document.getElementById('btn_retry_declaration')?.addEventListener('click', () =>
      this.submitGoodsDraft());
    document.getElementById('btn_cancel_declaration')?.addEventListener('click', () =>
      this.cancelGoodsDeclaration());
  },

  populateDraftForm(declaration) {
    if (!declaration || !document.getElementById('exitGoodsForm')) return;

    const fillValue = (selector, value) => {
      const element = document.querySelector(selector);
      if (element && value !== null && value !== undefined && value !== '') {
        element.value = value;
      }
    };

    fillValue('#form_plate', declaration.plate_number || declaration.vehicle_plate || '');
    fillValue('#form_driver', declaration.driver_name || '');
    fillValue('#form_company', declaration.company_name || '');
    fillValue('#form_gate_id', declaration.gate_id || '');
    fillValue('#form_lane_id', declaration.lane_id || '');

    const purpose = String(declaration.purpose || '');
    if (purpose) {
      const normalized = purpose.toLowerCase();
      if (normalized.includes('empty') || normalized.includes('return')) {
        fillValue('#form_direction', 'Empty Return');
      } else if (normalized.includes('drop') || normalized.includes('delivery')) {
        fillValue('#form_direction', 'Dropped off goods');
      } else {
        fillValue('#form_direction', 'Left with goods');
      }
      if (normalized.includes('raw') || normalized.includes('chemical')) {
        fillValue('#form_load_type', 'Raw Material');
      } else if (normalized.includes('machine') || normalized.includes('equipment')) {
        fillValue('#form_load_type', 'Machinery');
      } else if (normalized.includes('waste') || normalized.includes('scrap')) {
        fillValue('#form_load_type', 'Waste / Scrap');
      } else {
        fillValue('#form_load_type', 'Finished Goods');
      }
    }

    const itemsTbody = document.getElementById('exit_items_tbody');
    if (itemsTbody) {
      itemsTbody.innerHTML = '';
      const items = Array.isArray(declaration.items) ? declaration.items : [];
      if (!items.length) {
        this.addItemRow();
      } else {
        items.forEach(item => {
          const row = document.createElement('tr');
          const sealMatch = String(item.remarks || '').match(/Seal:\s*([^·]+)/i);
          const noteMatch = String(item.remarks || '').match(/(?:\bDestination\b|\bWaybill\b|\bContact\b|\bVehicle model\b|\bPhone\b):\s*([^·]+)/i);
          row.innerHTML = `
            <td><input class="inp" name="item_name[]" value="${HIPDC_Utils.esc(item.description || '')}" required></td>
            <td><input class="inp" name="item_qty[]" type="number" value="${HIPDC_Utils.esc(item.quantity || '1')}" style="width: 90px;" required></td>
            <td>
              <select class="inp" name="item_unit[]" style="width: 110px;">
                ${['Cartons', 'Rolls', 'Pallets', 'Bags', 'Pieces', 'Kg'].map(unit => `
                  <option value="${unit}" ${String(item.unit || 'Cartons') === unit ? 'selected' : ''}>${unit}</option>
                `).join('')}
              </select>
            </td>
            <td><input class="inp" name="container_no[]" value="${HIPDC_Utils.esc(item.serial_number || '')}"></td>
            <td><input class="inp" name="seal_no[]" value="${HIPDC_Utils.esc(sealMatch ? sealMatch[1].trim() : '')}"></td>
            <td style="text-align: center;">
              <button type="button" class="btn-close" onclick="this.closest('tr').remove()" title="${HIPDC_Utils.t('Close')}">&times;</button>
            </td>
          `;
          itemsTbody.appendChild(row);
        });
      }
    }
  },

  async restoreGoodsDraft() {
    const storedId = sessionStorage.getItem('hipdc-goods-draft-id');
    if (!storedId || !document.getElementById('exitGoodsForm')) return;
    try {
      const { declaration } = await HIPDC_API.get('goods/view.php', { id: storedId });
      const normalizedStatus = this.normalizeGoodsDeclarationStatus(declaration.status);
      this.goodsDraft = declaration;
      this.submittedExitRecord = {
        id: declaration.id,
        reference: declaration.reference,
        status: normalizedStatus
      };
      this.populateDraftForm(declaration);
      document.getElementById('form_input_panel').style.display = 'none';
      document.getElementById('form_completion_card').style.display = 'flex';
      this.syncCompletionReference(declaration.reference);
      this.renderFormStatusBanner(declaration);
      if (!['draft', 'pending'].includes(normalizedStatus)) {
        sessionStorage.removeItem('hipdc-goods-draft-id');
      }
    } catch (error) {
      sessionStorage.removeItem('hipdc-goods-draft-id');
      HIPDC_Notify.show('Could not restore the saved goods declaration: ' + error.message, 'bad', 0);
    }
  },

  prefillGoodsVehicle() {
    const plate = new URLSearchParams(window.location.search).get('plate');
    const matched = plate && HIPDC_DB.getVehicles().find(vehicle => vehicle.plate === plate);
    if (!matched) return;
    document.getElementById('form_driver').value = matched.driver === '—' ? '' : matched.driver;
    document.getElementById('form_company').value = matched.company === '—' ? '' : matched.company;
    document.getElementById('form_phone').value = matched.phone === '—' ? '' : matched.phone;
    document.getElementById('form_model').value = matched.model === '—' ? '' : matched.model;
  },

  addItemRow() {
    const itemsTbody = document.getElementById('exit_items_tbody');
    if (!itemsTbody) return;

    const row = document.createElement('tr');
    row.innerHTML = `
      <td><input class="inp" name="item_name[]" placeholder="${HIPDC_Utils.t('Item Description')}" required></td>
      <td><input class="inp" name="item_qty[]" type="number" placeholder="0" style="width: 90px;" required></td>
      <td>
        <select class="inp" name="item_unit[]" style="width: 110px;">
          <option>Cartons</option>
          <option>Rolls</option>
          <option>Pallets</option>
          <option>Bags</option>
          <option>Pieces</option>
          <option>Kg</option>
        </select>
      </td>
      <td><input class="inp" name="container_no[]" placeholder="e.g. MSKU 482011"></td>
      <td><input class="inp" name="seal_no[]" placeholder="e.g. S-77120"></td>
      <td style="text-align: center;">
        <button type="button" class="btn-close" onclick="this.closest('tr').remove()" title="${HIPDC_Utils.t('Close')}">&times;</button>
      </td>
    `;
    itemsTbody.appendChild(row);
  },

  gatherFormData() {
    const plate = document.getElementById('form_plate')?.value.trim() || '';
    const driver = document.getElementById('form_driver')?.value.trim() || '';
    const company = document.getElementById('form_company')?.value.trim() || '';
    const model = document.getElementById('form_model')?.value.trim() || '';
    const phone = document.getElementById('form_phone')?.value.trim() || '';
    const direction = document.getElementById('form_direction')?.value || 'Left with goods';
    const loadType = document.getElementById('form_load_type')?.value || '';
    const destination = document.getElementById('form_destination')?.value.trim() || '';
    const waybill = document.getElementById('form_waybill')?.value.trim() || '';
    const authorizedBy = document.getElementById('form_authorized_by')?.value.trim() || '';
    const notes = document.getElementById('form_notes')?.value.trim() || '';
    const gateId = document.getElementById('form_gate_id')?.value || '';
    const laneId = document.getElementById('form_lane_id')?.value || '';

    const items = [];
    const itemRows = document.querySelectorAll('#exit_items_tbody tr');
    itemRows.forEach(tr => {
      const nameInput = tr.querySelector('[name="item_name[]"]');
      const qtyInput = tr.querySelector('[name="item_qty[]"]');
      const unitInput = tr.querySelector('[name="item_unit[]"]');
      const containerInput = tr.querySelector('[name="container_no[]"]');
      const sealInput = tr.querySelector('[name="seal_no[]"]');

      if (nameInput && nameInput.value.trim()) {
        items.push({
          item: nameInput.value.trim(),
          qty: qtyInput ? qtyInput.value : '1',
          unit: unitInput ? unitInput.value : 'Cartons',
          container: containerInput ? containerInput.value.trim() : '',
          seal: sealInput ? sealInput.value.trim() : ''
        });
      }
    });

    return {
      plate,
      driver,
      company,
      model,
      phone,
      direction,
      loadType,
      destination,
      waybill,
      authorizedBy,
      notes,
      gateId: gateId ? Number(gateId) : null,
      laneId: laneId ? Number(laneId) : null,
      items
    };
  },

  async handleGoodsFormSubmit() {
    const formData = this.gatherFormData();
    const submitButton = document.getElementById('btn_submit_declaration');
    if (submitButton) submitButton.disabled = true;
    try {
      const vehicle = await HIPDC_DB.resolveVehicle(formData.plate);
      if (!vehicle.company_id) throw new Error('The vehicle must be associated with an active company.');
      const assignedDriver = HIPDC_DB.data.drivers.find(driver =>
        Number(driver.id) === Number(vehicle.assigned_driver_id)
        || driver.full_name.toLowerCase() === formData.driver.toLowerCase());
      const locations = await HIPDC_API.currentGateLocations();
      const location = locations.find(item => item.operation_direction === 'exit'
        && Number(item.gate_id) === Number(formData.gateId));
      if (!location) throw new Error('No assigned exit gate is available for this account.');

      const remarks = [
        formData.notes,
        formData.destination && 'Destination: ' + formData.destination,
        formData.waybill && 'Waybill: ' + formData.waybill,
        formData.authorizedBy && 'Contact: ' + formData.authorizedBy,
        formData.model && 'Vehicle model: ' + formData.model,
        formData.phone && 'Phone: ' + formData.phone
      ].filter(Boolean).join(' · ');
      const result = await HIPDC_API.post('goods/index.php', {
        direction: 'exit', company_id: vehicle.company_id, vehicle_id: vehicle.id,
        driver_id: assignedDriver?.id || vehicle.assigned_driver_id || null, gate_id: location.gate_id,
        lane_id: formData.laneId || location.lane_id || null,
        purpose: [formData.direction, formData.loadType].filter(Boolean).join(' · ') || 'Goods exit',
        items: formData.items.map(item => ({
          description: item.item, quantity: item.qty, unit: item.unit,
          serial_number: item.container || null,
          remarks: [item.seal && 'Seal: ' + item.seal, remarks].filter(Boolean).join(' · ') || null
        }))
      });
      this.goodsDraft = result.declaration;
      sessionStorage.setItem('hipdc-goods-draft-id', String(result.declaration.id));
      this.submittedExitRecord = {
        ...formData, id: result.declaration.id,
        reference: result.declaration.reference,
        status: 'draft', vehicleId: vehicle.id
      };
      document.getElementById('form_input_panel').style.display = 'none';
      document.getElementById('form_completion_card').style.display = 'flex';
      this.syncCompletionReference(this.submittedExitRecord.reference);
      this.renderFormStatusBanner(this.goodsDraft);
      await this.submitGoodsDraft();
    } catch (error) {
      HIPDC_Notify.show(error.message, 'bad', 0);
      if (submitButton && document.getElementById('form_input_panel')?.style.display !== 'none') {
        submitButton.disabled = false;
      }
    }
  },

  async submitGoodsDraft() {
    if (!this.goodsDraft?.id) return;
    const retryButton = document.getElementById('btn_retry_declaration');
    if (retryButton) retryButton.disabled = true;
    try {
      const result = await HIPDC_API.post('goods/submit.php', {}, { id: this.goodsDraft.id });
      this.goodsDraft = result.declaration;
      this.submittedExitRecord = {
        ...(this.submittedExitRecord || {}),
        id: result.declaration.id,
        reference: result.declaration.reference,
        status: result.declaration.status
      };
      this.renderFormStatusBanner(result.declaration);
      HIPDC_Notify.show('Goods declaration submitted for approval. No physical exit event was recorded.', 'ok');
    } catch (error) {
      try {
        const { declaration } = await HIPDC_API.get('goods/view.php', { id: this.goodsDraft.id });
        this.goodsDraft = declaration;
        this.submittedExitRecord = {
          ...(this.submittedExitRecord || {}),
          id: declaration.id,
          reference: declaration.reference,
          status: declaration.status
        };
        this.renderFormStatusBanner(declaration);
        if (String(declaration.status || '').toLowerCase() !== 'draft') {
          HIPDC_Notify.show('The declaration was already submitted; its current status has been refreshed.', 'ok');
          return;
        }
      } catch (refreshError) {
        HIPDC_Notify.show(
          'Submission status could not be confirmed. Retry safely using the saved draft. '
          + error.message + ' ' + refreshError.message,
          'bad',
          0
        );
        return;
      }
      this.renderFormStatusBanner(this.goodsDraft);
      HIPDC_Notify.show('The draft is saved and can be retried without creating a duplicate. ' + error.message, 'bad', 0);
    } finally {
      if (retryButton && String(this.goodsDraft?.status || '').toLowerCase() === 'draft') retryButton.disabled = false;
    }
  },

  async cancelGoodsDeclaration() {
    const declarationId = this.submittedExitRecord?.id || this.goodsDraft?.id;
    if (!declarationId || !window.confirm('Cancel this goods declaration?')) return;
    const cancelButton = document.getElementById('btn_cancel_declaration');
    if (cancelButton) cancelButton.disabled = true;
    try {
      const result = await HIPDC_API.post('goods/cancel.php', {}, { id: declarationId });
      this.goodsDraft = result.declaration;
      this.submittedExitRecord = { ...this.submittedExitRecord, ...result.declaration };
      sessionStorage.removeItem('hipdc-goods-draft-id');
      this.renderFormStatusBanner(result.declaration);
      HIPDC_Notify.show('Goods declaration cancelled. No physical exit event was recorded.', 'ok');
    } catch (error) {
      HIPDC_Notify.show(error.message, 'bad', 0);
      if (cancelButton) cancelButton.disabled = false;
    }
  },

  syncCompletionReference(reference = this.submittedExitRecord?.reference || this.goodsDraft?.reference || '') {
    const value = reference || '—';
    const labels = [
      document.getElementById('completed_ref_number'),
      document.querySelector('#form_completion_card [id="completed_ref_number"]'),
    ].filter(Boolean);

    labels.forEach(refEl => {
      refEl.textContent = value;
    });

    const completionHeading = document.querySelector('#form_completion_card .muted');
    if (completionHeading) {
      const text = completionHeading.textContent || '';
      if (text.includes('Declaration reference:')) {
        completionHeading.innerHTML = `Declaration reference: <b id="completed_ref_number" style="color: var(--primary);">${HIPDC_Utils.esc(value)}</b>`;
      }
    }
  },

  renderFormStatusBanner(approval) {
    const banner = document.getElementById('form_approval_status_banner');
    const unlockBtn = document.getElementById('btn_form_open_gate');
    const cancelBtn = document.getElementById('btn_cancel_declaration');
    const retryBtn = document.getElementById('btn_retry_declaration');
    if (!banner) return;

    const status = this.normalizeGoodsDeclarationStatus(approval?.status || this.submittedExitRecord?.status || this.goodsDraft?.status);
    const reference = approval?.reference || this.submittedExitRecord?.reference || this.goodsDraft?.reference || '';
    const reason = approval?.approval?.decision_reason || approval?.decision_reason || approval?.status || '';
    this.syncCompletionReference(reference);

    if (cancelBtn) cancelBtn.style.display = ['draft', 'pending'].includes(status) ? '' : 'none';
    if (retryBtn) retryBtn.style.display = status === 'draft' ? '' : 'none';

    if (!approval || !status) {
      banner.className = 'note c-warn';
      banner.textContent = 'Declaration status is not available yet. Refresh and try again.';
      if (unlockBtn) unlockBtn.setAttribute('disabled', 'true');
      return;
    }

    if (status === 'draft') {
      banner.className = 'note c-warn';
      banner.textContent = 'Draft saved, but not submitted. Retry submission or cancel and correct the declaration. No approval or physical exit event exists.';
      if (unlockBtn) unlockBtn.setAttribute('disabled', 'true');
    } else if (status === 'approved') {
      banner.className = 'note c-ok';
      banner.innerHTML = `
        <div style="display:flex;justify-content:space-between;align-items:center;">
          <div>
            <b>✔ ${HIPDC_Utils.t('Approved by Admin')}</b><br>
            Declaration ${HIPDC_Utils.esc(reference)} is approved. Record the observed cargo movement separately.
          </div>
          <span class="chip c-ok">${HIPDC_Utils.t('Approved')}</span>
        </div>
      `;
      if (unlockBtn) {
        unlockBtn.removeAttribute('disabled');
        unlockBtn.onclick = async () => {
          try {
            const declarationRef = this.submittedExitRecord?.reference || reference;
            await HIPDC_GateControls.processGoods('exit', declarationRef);
          } catch (error) {
            HIPDC_Notify.show(error.message, 'bad', 0);
          }
        };
      }
    } else if (status === 'cancelled') {
      banner.className = 'note c-bad';
      banner.innerHTML = `
        <div style="display:flex;justify-content:space-between;align-items:center;">
          <div>
            <b>✖ ${HIPDC_Utils.t('Cancelled')}</b><br>
            ${HIPDC_Utils.esc(reason || 'Goods declaration was cancelled before the review decision was completed.')}
          </div>
          <span class="chip c-bad">${HIPDC_Utils.t('Cancelled')}</span>
        </div>
      `;
      if (unlockBtn) unlockBtn.setAttribute('disabled', 'true');
    } else if (['denied', 'rejected'].includes(status)) {
      banner.className = 'note c-bad';
      banner.innerHTML = `
        <div style="display:flex;justify-content:space-between;align-items:center;">
          <div>
            <b>✖ ${HIPDC_Utils.t('Denied')}</b><br>
            ${HIPDC_Utils.esc(reason || status)}
          </div>
          <span class="chip c-bad">${HIPDC_Utils.t('Denied')}</span>
        </div>
      `;
      if (unlockBtn) unlockBtn.setAttribute('disabled', 'true');
    } else {
      banner.className = 'note c-warn';
      banner.innerHTML = `
        <div style="display:flex;justify-content:space-between;align-items:center;">
          <div>
            <b>⏳ ${HIPDC_Utils.t('Pending Admin Approval')}</b><br>
            Declaration ${HIPDC_Utils.esc(reference)} is awaiting review. No physical exit event has been recorded.
          </div>
          <span class="chip c-warn">${HIPDC_Utils.t('Pending Approval')}</span>
        </div>
      `;
      if (unlockBtn) unlockBtn.setAttribute('disabled', 'true');
    }
  },

  renderSchedulesTable() {
    const tbody = document.getElementById('exit_schedules_tbody');
    if (!tbody) return;

    const searchInput = document.getElementById('schedule_search');
    const q = searchInput ? searchInput.value.trim() : '';
    const schedules = HIPDC_DB.getSchedules(q);
    HIPDC_ScheduleFilters.populate(HIPDC_DB.data.schedules);
    const list = HIPDC_ScheduleFilters.filter(schedules).filter(item => item.direction === 'exit');

    if (list.length === 0) {
      tbody.innerHTML = `
        <tr>
          <td colspan="8" class="table-empty">
            ${HIPDC_Utils.renderEmptyState({
              icon: 'cal',
              title: 'No Scheduled Departures',
              desc: 'There are no scheduled vehicle departures for today in the database.',
              actionHtml: ''
            })}
          </td>
        </tr>
      `;
      return;
    }

    tbody.innerHTML = list.map(s => `
      <tr>
        <td><b>${HIPDC_Utils.esc(s.company)}</b></td>
        <td class="col-plate">${s.plate}</td>
        <td>${HIPDC_Utils.esc(s.driver)}</td>
        <td>${HIPDC_Utils.esc(s.model)}</td>
        <td>${s.win}</td>
        <td><span class="muted">${HIPDC_Utils.esc(s.cargo || s.details || (s.purpose === 'Pickup' ? 'Finished goods for export' : 'Empty container return'))}</span></td>
        <td><span class="chip ${s.status === 'Inside' ? 'c-info' : ''}">${HIPDC_Utils.t(s.status)}</span></td>
        <td>
          <button class="btn sm ${s.status === 'Expected' ? '' : 'soft'}" ${s.status !== 'Expected' ? 'disabled' : ''} onclick="HIPDC_Exit.processScheduleDeparture(${Number(s.id)})">
            ${HIPDC_Utils.t('Vehicle Leaving')}
          </button>
        </td>
      </tr>
    `).join('');
  },

  processScheduleDeparture(scheduleId) {
    const schedule = HIPDC_DB.data.schedules.find(s =>
      Number(s.id) === Number(scheduleId) && s.direction === 'exit');
    if (schedule) {
      this.currentExitVehicle = {
        id: schedule.vehicle_id,
        driver_id: schedule.driver_id,
        schedule_id: schedule.id,
        gate_id: schedule.gate_id,
        lane_id: schedule.lane_id,
        plate: schedule.plate,
        company: schedule.company,
        driver: schedule.driver,
        model: schedule.model,
        phone: schedule.phone,
        type: 'Exiting'
      };
      this.showExitModal(this.currentExitVehicle);
    }
  },

  renderVehiclesTable() {
    const tbody = document.getElementById('exit_vehicles_tbody');
    if (!tbody) return;

    const searchInput = document.getElementById('vehicle_search');
    const q = searchInput ? searchInput.value.trim() : '';
    const list = HIPDC_DB.getVehicles(q);
    HIPDC_VehicleFilters.populate(HIPDC_DB.data.vehicles);
    const filteredList = HIPDC_VehicleFilters.filter(list);

    if (filteredList.length === 0) {
      tbody.innerHTML = `
        <tr>
          <td colspan="7" class="table-empty">
            ${HIPDC_Utils.renderEmptyState({
              icon: 'car',
              title: 'No Registered Vehicles Found',
              desc: 'No company vehicles registered in the database.',
              actionHtml: ''
            })}
          </td>
        </tr>
      `;
      return;
    }

    tbody.innerHTML = filteredList.map(v => `
      <tr>
        <td><b>${HIPDC_Utils.esc(v.company)}</b></td>
        <td class="col-plate">${v.plate}</td>
        <td>${HIPDC_Utils.esc(v.driver)}</td>
        <td>${HIPDC_Utils.esc(v.model)}</td>
        <td>${v.expiry}</td>
        <td><span class="chip ${v.status === 'Active' ? 'c-ok' : (v.status === 'Blocked' ? 'c-bad' : 'c-warn')}">${HIPDC_Utils.t(v.status)}</span></td>
        <td>
          <button class="btn sm soft" ${v.status === 'Blocked' ? 'disabled' : ''} onclick="HIPDC_Exit.processVehicleDirectExit('${v.plate}')">
            ${HIPDC_Utils.t('Register Exit')}
          </button>
        </td>
      </tr>
    `).join('');
  },

  processVehicleDirectExit(plate) {
    const vehicle = HIPDC_DB.getVehicles().find(v => v.plate === plate);
    if (vehicle) {
      this.currentExitVehicle = {
        id: vehicle.id,
        driver_id: vehicle.assigned_driver_id,
        plate: vehicle.plate,
        company: vehicle.company,
        driver: vehicle.driver,
        model: vehicle.model,
        phone: vehicle.phone,
        type: 'Registered'
      };
      this.showExitModal(this.currentExitVehicle);
    }
  },

  renderLogisticsTable() {
    const tbody = document.getElementById('exit_logistics_tbody');
    if (!tbody) return;

    const searchInput = document.getElementById('logistics_search');
    const q = searchInput ? searchInput.value.trim() : '';
    const list = HIPDC_DB.getLogs('exit', q);

    if (list.length === 0) {
      tbody.innerHTML = `
        <tr>
          <td colspan="8" class="table-empty">
            ${HIPDC_Utils.renderEmptyState({
              icon: 'list',
              title: 'No Exit Gate Logs Recorded',
              desc: 'No departure records have been registered at the exit gate during this shift yet.',
              actionHtml: ''
            })}
          </td>
        </tr>
      `;
      return;
    }

    tbody.innerHTML = list.map(l => `
      <tr>
        <td class="col-time">${l.time}</td>
        <td class="col-plate">${l.plate}</td>
        <td>${HIPDC_Utils.esc(l.driver)}</td>
        <td>${HIPDC_Utils.esc(l.company)}</td>
        <td><span class="chip c-info">${HIPDC_Utils.t(l.type)}</span></td>
        <td><span class="chip ${l.adminApproval.includes('Approved') ? 'c-ok' : (l.adminApproval.includes('Pending') ? 'c-warn' : '')}">${HIPDC_Utils.t(l.adminApproval)}</span></td>
        <td><span class="muted">${HIPDC_Utils.esc(l.details)}</span></td>
        <td>
          <button class="btn sm pdf" onclick='HIPDC_Exit.downloadLogPDF(${JSON.stringify(l).replace(/'/g, "&apos;")})' title="${HIPDC_Utils.t('Download PDF')}">
            ${HIPDC_Utils.icon('pdf', 14)} PDF
          </button>
        </td>
      </tr>
    `).join('');
  },

  downloadLogPDF(log) {
    HIPDC_PDF.generateExitLogPDF({
      id: log.reference || log.id || '—',
      plate: log.plate,
      driver: log.driver,
      company: log.company,
      time: log.time,
      direction: log.type,
      status: log.access_result === 'granted' ? 'Authorized' : 'Denied',
      notes: log.details,
      items: log.items || []
    });
  },

  bindEvents() {
    HIPDC_LogFilters?.bind(() => 'exit');
    HIPDC_ScheduleFilters?.bind(() => this.renderPageData());
    HIPDC_VehicleFilters?.bind(() => this.renderVehiclesTable(), 'Active');

    ['schedule_search', 'vehicle_search', 'logistics_search'].forEach(id => {
      const el = document.getElementById(id);
      if (el) {
        el.addEventListener('input', () => this.renderPageData());
      }
    });

    const vehicleAccessForm = document.getElementById('exit_access_form');
    if (vehicleAccessForm) vehicleAccessForm.addEventListener('submit', async event => {
      event.preventDefault();
      const form = new FormData(vehicleAccessForm);
      const plate = String(form.get('plate') || '').trim();
      try {
        await HIPDC_GateControls.processVehicle('exit', plate, {
          gateId: Number(form.get('gate_id')),
          laneId: form.get('lane_id') ? Number(form.get('lane_id')) : null
        });
        vehicleAccessForm.reset();
      } catch (error) {
        HIPDC_Notify.show(error.message, 'bad', 0);
      }
    });

    const goodsAccessForm = document.getElementById('exit_goods_access_form');
    if (goodsAccessForm) goodsAccessForm.addEventListener('submit', async event => {
      event.preventDefault();
      const reference = String(new FormData(goodsAccessForm).get('goods_reference') || '').trim();
      const form = new FormData(goodsAccessForm);
      try {
        await HIPDC_GateControls.processGoods('exit', reference, Number(form.get('gate_id')),
          form.get('lane_id') ? Number(form.get('lane_id')) : null);
        goodsAccessForm.reset();
      } catch (error) {
        HIPDC_Notify.show(error.message, 'bad', 0);
      }
    });

    ['exit_gate_id','exit_goods_gate_id','form_gate_id'].forEach(id => {
      document.getElementById(id)?.addEventListener('change', () => this.populateGateSelectors());
    });
  }
};

document.addEventListener('DOMContentLoaded', () => {
  HIPDC_Exit.init();
});
