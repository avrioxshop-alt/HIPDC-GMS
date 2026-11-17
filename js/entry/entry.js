/**
 * HIPDC Gate & Logistics Management - Entry Gate Controller
 * Handles vehicle arrival verification, ANPR detection, unscheduled approval requests,
 * and live gate barrier controls. Fully localized for English & Amharic.
 */

const HIPDC_Entry = {
  currentArrival: null,
  gateLocations: [],

  init() {
    this.bindEvents();
    this.renderPageData();
    window.addEventListener('hipdc-authenticated', () => this.loadGateLocations());

    // Listen for cross-tab admin approval updates
    window.addEventListener('hipdc-db-updated', () => {
      this.renderPageData();
      this.checkCurrentApprovalStatus();
    });
    window.addEventListener('storage', () => {
      this.renderPageData();
      this.checkCurrentApprovalStatus();
    });
  },

  renderPageData() {
    this.populateGateSelectors();
    const scheduleSelect = document.getElementById('entry_schedule_id');
    if (scheduleSelect) {
      const selected = scheduleSelect.value;
      const options = HIPDC_DB.data.schedules
        .filter(schedule => schedule.direction === 'entry' && schedule.status === 'Expected')
        .map(schedule => `<option value="${Number(schedule.id)}">${HIPDC_Utils.esc(schedule.plate)} · ${HIPDC_Utils.esc(schedule.company)} · ${HIPDC_Utils.esc(schedule.win)}</option>`)
        .join('');
      scheduleSelect.innerHTML = '<option value="">No schedule selected</option>' + options;
      if (selected) scheduleSelect.value = selected;
    }

    const schedulesTbody = document.getElementById('entry_schedules_tbody');
    if (schedulesTbody) {
      this.renderSchedulesTable();
    }

    const vehiclesTbody = document.getElementById('entry_vehicles_tbody');
    if (vehiclesTbody) {
      this.renderVehiclesTable();
    }

    const logisticsTbody = document.getElementById('entry_logistics_tbody');
    if (logisticsTbody) {
      this.renderLogisticsTable();
    }
  },

  async loadGateLocations() {
    try {
      this.gateLocations = (await HIPDC_API.currentGateLocations())
        .filter(location => location.operation_direction === 'entry');
      this.populateGateSelectors();
    } catch (error) {
      HIPDC_Notify.show(error.message, 'bad', 0);
    }
  },

  populateGateSelectors() {
    const gateIds = ['entry_gate_id', 'entry_request_gate_id', 'entry_visitor_gate_id', 'entry_validate_gate_id'];
    const gates = [...new Map(this.gateLocations.map(location => [location.gate_id, location])).values()];
    const gateOptions = gates.map(gate =>
      `<option value="${Number(gate.gate_id)}">${HIPDC_Utils.esc(gate.gate_name)}</option>`
    ).join('');
    gateIds.forEach(id => {
      const select = document.getElementById(id);
      if (select) {
        const selected = select.value;
        select.innerHTML = gateOptions || '<option value="">No assigned entry gate</option>';
        if (selected) select.value = selected;
      }
    });
    const laneGatePairs = [
      ['entry_gate_id', 'entry_lane_id'],
      ['entry_request_gate_id', 'entry_request_lane_id'],
      ['entry_visitor_gate_id', 'entry_visitor_lane_id']
    ];
    laneGatePairs.forEach(([gateSelectId, laneSelectId]) => {
      const gateSelect = document.getElementById(gateSelectId);
      const laneSelect = document.getElementById(laneSelectId);
      if (!gateSelect || !laneSelect) return;
      const lanes = this.gateLocations.filter(location =>
        Number(location.gate_id) === Number(gateSelect.value) && location.lane_id
      );
      laneSelect.innerHTML = '<option value="">Any assigned lane</option>' + lanes.map(location =>
        `<option value="${Number(location.lane_id)}">${HIPDC_Utils.esc(location.lane_name)}</option>`
      ).join('');
    });
  },

  simulateArrival() {
    document.getElementById('entry_observed_plate')?.focus();
  },

  showArrivalModal(arrival) {
    const isUnknown = arrival.type === 'Unknown';

    const pendingApproval = HIPDC_DB.getApprovals('All').find(a => 
      a.plate === arrival.plate && a.gate === 'Entry'
    );

    let approvalNotice = '';
    let openBtnState = '';
    let notSchedBtnState = '';

    if (pendingApproval) {
      if (pendingApproval.status === 'Pending') {
        approvalNotice = `
          <div class="note c-warn">
            <b>⏳ ${HIPDC_Utils.t('Pending Admin Approval')}</b><br>
            ${HIPDC_Utils.t('Request submitted at')} ${pendingApproval.time}. Server-side access remains subject to approval.
          </div>
        `;
        openBtnState = 'disabled';
        notSchedBtnState = 'disabled';
      } else if (pendingApproval.status === 'Approved') {
        approvalNotice = `
          <div class="note c-ok">
            <b>✔ ${HIPDC_Utils.t('Approved by Admin')}</b><br>
            ${HIPDC_Utils.t('Approval granted. Submit the observed plate for server-side authorization.')}
          </div>
        `;
        openBtnState = '';
        notSchedBtnState = 'disabled';
      } else if (pendingApproval.status === 'Denied' || pendingApproval.status === 'Rejected') {
        approvalNotice = `
          <div class="note c-bad">
            <b>✖ ${HIPDC_Utils.t('Denied')}</b><br>
            ${pendingApproval.note || HIPDC_Utils.t('Entry prohibited by park security administrator.')}
          </div>
        `;
        openBtnState = 'disabled';
        notSchedBtnState = 'disabled';
      }
    }

    const modalContent = `
      <div class="modal">
        <div class="mh">
          <div>
            <h3>${HIPDC_Utils.t('Vehicle at Front Gate')} · ${HIPDC_Utils.getNowTime()}</h3>
            <span class="muted">Manual officer observation · no camera or barrier integration</span>
          </div>
          <button type="button" class="modal-close-btn" data-close-modal aria-label="Close dialog">&times;</button>
        </div>

        <div class="row">
          <div class="photo">${isUnknown ? '?' : '👤'}</div>
          <div class="kv">
            <div><span>${HIPDC_Utils.t('Company')}</span><b>${HIPDC_Utils.esc(arrival.company)}</b></div>
            <div><span>${HIPDC_Utils.t('Driver')}</span><b>${HIPDC_Utils.esc(arrival.driver)}</b></div>
            <div><span>${HIPDC_Utils.t('Car Model')}</span><b>${HIPDC_Utils.esc(arrival.model)}</b></div>
            <div><span>${HIPDC_Utils.t('Driver Phone')}</span><b>${HIPDC_Utils.esc(arrival.phone)}</b></div>
          </div>
        </div>

        <div class="bigplate">${arrival.plate}</div>

        ${approvalNotice}

        <div class="field">
          <label>${HIPDC_Utils.t('Security Inspection Remarks & Seal Check')}</label>
          <textarea id="entry_arrival_note" placeholder="${HIPDC_Utils.t('Add inspection notes or reason if unscheduled...')}"></textarea>
        </div>

        <div class="acts">
          ${isUnknown ? `
            <button class="btn soft" data-close-modal>${HIPDC_Utils.t('Search Database')}</button>
            <button class="btn bad" id="btn_deny_entry">${HIPDC_Utils.t('Deny Entry')}</button>
          ` : `
            <button class="btn warn" id="btn_req_approval" ${notSchedBtnState}>${HIPDC_Utils.t('Submit for Admin Approval')}</button>
            <button class="btn" id="btn_open_gate">Record observed entry attempt</button>
          `}
        </div>
      </div>
    `;

    HIPDC_Modal.open(modalContent);

    const openGateBtn = document.getElementById('btn_open_gate');
    if (openGateBtn) {
      openGateBtn.addEventListener('click', () => {
        HIPDC_GateControls.processVehicle('entry', arrival.plate, {
          driverId: arrival.driver_id || null,
          scheduleId: arrival.schedule_id || null
        }).catch(error => HIPDC_Notify.show(error.message, 'bad', 0));
        HIPDC_Modal.close();
      });
    }

    const reqApprovalBtn = document.getElementById('btn_req_approval');
    if (reqApprovalBtn) {
      reqApprovalBtn.addEventListener('click', () => {
        const noteEl = document.getElementById('entry_arrival_note');
        const officerNote = noteEl ? noteEl.value.trim() : 'Unscheduled vehicle arrival requiring gate pass.';
        
        HIPDC_DB.submitApprovalRequest({
          title: `Not Scheduled · ${arrival.company}`,
          gate: 'Entry',
          plate: arrival.plate,
          direction: 'entry',
          gate_id: Number(document.getElementById('entry_request_gate_id')?.value),
          lane_id: document.getElementById('entry_request_lane_id')?.value || null,
          schedule_id: arrival.schedule_id,
          note: officerNote
        }).then(() => HIPDC_Notify.show('Approval request submitted. Entry remains unapproved until a reviewer decides.', 'ok'))
          .catch(error => HIPDC_Notify.show(error.message, 'bad', 0));
      });
    }

    const denyBtn = document.getElementById('btn_deny_entry');
    if (denyBtn) {
      denyBtn.addEventListener('click', () => {
        HIPDC_Notify.show('No access event was recorded.', 'warn');
        HIPDC_Modal.close();
      });
    }
  },

  checkCurrentApprovalStatus() {
    if (this.currentArrival && HIPDC_Modal.activeModal) {
      this.showArrivalModal(this.currentArrival);
    }
  },

  /* ---------- Schedules Table Rendering ---------- */
  renderSchedulesTable() {
    const tbody = document.getElementById('entry_schedules_tbody');
    if (!tbody) return;

    const searchInput = document.getElementById('schedule_search');
    const q = searchInput ? searchInput.value.trim() : '';
    const schedules = HIPDC_DB.getSchedules(q);
    HIPDC_ScheduleFilters.populate(HIPDC_DB.data.schedules);
    const list = HIPDC_ScheduleFilters.filter(schedules).filter(item => item.direction === 'entry');

    if (list.length === 0) {
      tbody.innerHTML = `
        <tr>
          <td colspan="8" class="table-empty">
            ${HIPDC_Utils.renderEmptyState({
              icon: 'cal',
              title: 'No Scheduled Vehicles Today',
              desc: 'There are currently no scheduled vehicle arrivals registered in the database.',
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
        <td><span class="muted">${HIPDC_Utils.esc(s.cargo || s.details || '—')}</span></td>
        <td><span class="chip ${s.status === 'Arrived' || s.status === 'Inside' ? 'c-ok' : 'c-warn'}">${HIPDC_Utils.t(s.status)}</span></td>
        <td>
          <button class="btn sm ${s.status === 'Expected' ? '' : 'soft'}" ${s.status !== 'Expected' ? 'disabled' : ''} onclick="HIPDC_Entry.processScheduleArrival(${Number(s.id)})">
            ${HIPDC_Utils.t('Vehicle Arrived')}
          </button>
        </td>
      </tr>
    `).join('');
  },

  processScheduleArrival(scheduleId) {
    const schedule = HIPDC_DB.data.schedules.find(s =>
      Number(s.id) === Number(scheduleId) && s.direction === 'entry');
    if (schedule) {
      this.currentArrival = {
        id: schedule.vehicle_id,
        driver_id: schedule.driver_id,
        schedule_id: schedule.id,
        plate: schedule.plate,
        company: schedule.company,
        driver: schedule.driver,
        model: schedule.model,
        phone: schedule.phone,
        type: 'Scheduled'
      };
      this.showArrivalModal(this.currentArrival);
    }
  },

  /* ---------- Registered Vehicles Table Rendering ---------- */
  renderVehiclesTable() {
    const tbody = document.getElementById('entry_vehicles_tbody');
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
              desc: 'The database does not contain any registered tenant vehicles yet.',
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
          <button class="btn sm soft" ${v.status === 'Blocked' ? 'disabled' : ''} onclick="HIPDC_Entry.processVehicleDirectEntry('${v.plate}')">
            ${HIPDC_Utils.t('Register Entry')}
          </button>
        </td>
      </tr>
    `).join('');
  },

  processVehicleDirectEntry(plate) {
    const vehicle = HIPDC_DB.getVehicles().find(v => v.plate === plate);
    if (vehicle) {
      this.currentArrival = {
        id: vehicle.id,
        driver_id: vehicle.assigned_driver_id,
        plate: vehicle.plate,
        company: vehicle.company,
        driver: vehicle.driver,
        model: vehicle.model,
        phone: vehicle.phone,
        type: 'Registered'
      };
      this.showArrivalModal(this.currentArrival);
    }
  },

  /* ---------- Logistics Table Rendering ---------- */
  renderLogisticsTable() {
    const tbody = document.getElementById('entry_logistics_tbody');
    if (!tbody) return;

    const searchInput = document.getElementById('logistics_search');
    const q = searchInput ? searchInput.value.trim() : '';
    const list = HIPDC_DB.getLogs('entry', q);

    if (list.length === 0) {
      tbody.innerHTML = `
        <tr>
          <td colspan="7" class="table-empty">
            ${HIPDC_Utils.renderEmptyState({
              icon: 'list',
              title: 'No Entry Gate Logs Recorded',
              desc: 'No vehicles have passed through the entry gate yet during this shift.',
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
        <td><span class="chip ${l.adminApproval.includes('Approved') ? 'c-ok' : ''}">${HIPDC_Utils.t(l.adminApproval)}</span></td>
        <td><span class="muted">${HIPDC_Utils.esc(l.details)}</span></td>
      </tr>
    `).join('');
  },

  bindEvents() {
    HIPDC_LogFilters?.bind(() => 'entry');
    HIPDC_ScheduleFilters?.bind(() => this.renderPageData());
    HIPDC_VehicleFilters?.bind(() => this.renderVehiclesTable(), 'Active');

    ['schedule_search', 'vehicle_search', 'logistics_search'].forEach(id => {
      const el = document.getElementById(id);
      if (el) {
        el.addEventListener('input', () => this.renderPageData());
      }
    });

    const accessForm = document.getElementById('entry_access_form');
    if (accessForm) accessForm.addEventListener('submit', async event => {
      event.preventDefault();
      const form = new FormData(accessForm);
      const plate = String(form.get('plate') || '').trim();
      const scheduleId = form.get('schedule_id') ? Number(form.get('schedule_id')) : null;
      const schedule = HIPDC_DB.data.schedules.find(item => Number(item.id) === scheduleId);
      try {
        await HIPDC_GateControls.processVehicle('entry', plate, {
          gateId: Number(form.get('gate_id')),
          laneId: form.get('lane_id') ? Number(form.get('lane_id')) : null,
          scheduleId,
          driverId: schedule?.driver_id || null
        });
      } catch (error) {
        HIPDC_Notify.show(error.message, 'bad', 0);
      }
    });

    const approvalForm = document.getElementById('entry_approval_form');
    if (approvalForm) approvalForm.addEventListener('submit', async event => {
      event.preventDefault();
      const form = new FormData(approvalForm);
      try {
        await HIPDC_DB.submitApprovalRequest({
          title: 'Entry access · ' + String(form.get('plate')).trim(),
          plate: form.get('plate'), direction: 'entry', note: form.get('note'),
          gate_id: Number(form.get('gate_id')),
          lane_id: form.get('lane_id') ? Number(form.get('lane_id')) : null
        });
        HIPDC_Notify.show('Access approval submitted. It is not approved until reviewed.', 'ok');
        approvalForm.reset();
      } catch (error) {
        HIPDC_Notify.show(error.message, 'bad', 0);
      }
    });

    const visitorForm = document.getElementById('entry_visitor_form');
    if (visitorForm) visitorForm.addEventListener('submit', async event => {
      event.preventDefault();
      const token = String(new FormData(visitorForm).get('qr_token') || '').trim();
      const formData = new FormData(visitorForm);
      try {
        await HIPDC_GateControls.processVisitor('entry', token, Number(formData.get('gate_id')),
          formData.get('lane_id') ? Number(formData.get('lane_id')) : null);
        visitorForm.reset();
      } catch (error) {
        HIPDC_Notify.show(error.message, 'bad', 0);
      }
    });

    document.getElementById('entry_visitor_validate_form')?.addEventListener('submit', async event => {
      event.preventDefault();
      const form = new FormData(event.currentTarget);
      try {
        await HIPDC_GateControls.validateVisitor('entry', String(form.get('qr_token') || '').trim(),
          Number(form.get('gate_id')));
      } catch (error) {
        HIPDC_Notify.show(error.message, 'bad', 0);
      }
    });

    ['entry_gate_id', 'entry_request_gate_id', 'entry_visitor_gate_id'].forEach(id => {
      document.getElementById(id)?.addEventListener('change', () => this.populateGateSelectors());
    });
  }
};

document.addEventListener('DOMContentLoaded', () => {
  HIPDC_Entry.init();
});
