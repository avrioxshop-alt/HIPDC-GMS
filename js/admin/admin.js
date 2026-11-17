/**
 * HIPDC Gate & Logistics Management - Administrator Controller
 * Localized for English & Amharic.
 */

const HIPDC_Admin = {
  activeTab: 'Pending',
  selectedApprovalId: null,
  approvalDetailId: null,
  approvalDetail: null,
  userOptions: { roles: [], gates: [] },
  userOptionsLoaded: false,

  init() {
    this.bindEvents();
    this.renderPageData();
    if (document.getElementById('admin_users_tbody')) {
      this.loadUserOptions().catch(error => HIPDC_Notify.show(error.message, 'bad', 0));
    }

    window.addEventListener('hipdc-db-updated', () => {
      this.renderPageData();
      this.updateApprovalBadges();
    });
    window.addEventListener('storage', () => {
      this.renderPageData();
      this.updateApprovalBadges();
    });

    this.updateApprovalBadges();
  },

  async loadUserOptions() {
    this.userOptions = await HIPDC_API.get('users/options.php');
    this.userOptionsLoaded = true;
  },

  async refreshUsers() {
    await HIPDC_DB.refresh();
    this.renderUsersTable();
  },

  updateApprovalBadges() {
    const pendingCount = HIPDC_DB.getPendingApprovalsCount();
    document.querySelectorAll('[data-pending-badge]').forEach(el => {
      if (pendingCount > 0) {
        el.textContent = pendingCount;
        el.style.display = 'inline-block';
      } else {
        el.style.display = 'none';
      }
    });
  },

  renderPageData() {
    if (document.querySelector('[data-dashboard]')) this.renderDashboardSummary();
    if (document.getElementById('camera_metadata_list')) this.renderCameraInventory();
    if (document.getElementById('admin_dashboard_needs_approval')) {
      this.renderDashboardApprovals();
    }

    if (document.getElementById('admin_approvals_tbody')) {
      this.renderApprovalsPage();
    }

    if (document.getElementById('admin_schedules_tbody')) {
      this.renderSchedulesTable();
    }

    if (document.getElementById('admin_visitors_tbody')) {
      this.renderVisitorsTable();
    }

    if (document.getElementById('admin_vehicles_tbody')) {
      this.renderVehiclesTable();
    }

    if (document.getElementById('admin_logistics_tbody')) {
      this.renderLogisticsTable();
    }

    if (document.getElementById('admin_users_tbody')) {
      this.renderUsersTable();
    }
  },

  renderDashboardSummary() {
    const summary = HIPDC_DB.data.dashboard;
    if (!summary) return;
    const values = {
      expected_schedules_today: summary.expected_schedules_today,
      vehicle_entries: summary.vehicle_activity.entries,
      vehicle_exits: summary.vehicle_activity.exits,
      vehicle_presence: summary.vehicle_activity.current_presence,
      visitor_presence: summary.visitor_activity.current_presence,
      pending_approvals: summary.pending_approvals
    };
    Object.entries(values).forEach(([key, value]) => {
      const element = document.querySelector(`[data-dashboard="${key}"]`);
      if (element) element.textContent = String(value ?? 0);
    });

    const recent = document.getElementById('admin_recent_events');
    if (recent) {
      const events = [...HIPDC_DB.data.logs.entry, ...HIPDC_DB.data.logs.exit]
        .sort((a, b) => String(b.event_at).localeCompare(String(a.event_at))).slice(0, 8);
      recent.innerHTML = events.length ? events.map(event => `
        <tr><td><b>${HIPDC_Utils.esc(event.time)}</b></td><td>${HIPDC_Utils.esc(event.type)} · ${HIPDC_Utils.esc(event.plate)} · ${HIPDC_Utils.esc(event.company)}</td></tr>
      `).join('') : '<tr><td colspan="2" class="muted">No gate events recorded.</td></tr>';
    }
    const recentApprovals = document.getElementById('admin_recent_approvals');
    if (recentApprovals) {
      const resolved = HIPDC_DB.data.approvals.filter(item =>
        ['Approved', 'Denied', 'Cancelled'].includes(item.status)).slice(0, 5);
      recentApprovals.innerHTML = resolved.length ? resolved.map(item => `
        <tr><td>${HIPDC_Utils.esc(item.reference)}</td>
          <td><span class="chip ${item.status === 'Approved' ? 'c-ok' : 'c-bad'}">${item.status}</span></td>
          <td>${HIPDC_Utils.esc(item.time)}</td></tr>`).join('')
        : '<tr><td colspan="3" class="muted">No resolved approvals.</td></tr>';
    }

    const cameraSummary = document.getElementById('admin_camera_summary');
    if (cameraSummary) {
      const inventory = summary.camera_inventory;
      cameraSummary.innerHTML = `<div><b>Camera metadata inventory</b></div>
        <div>${inventory.total} registered · ${inventory.active} active · ${inventory.offline} marked offline</div>
        <div class="muted">Monitoring metadata only; no live feeds or ANPR hardware are connected.</div>`;
    }
  },

  renderCameraInventory() {
    const target = document.getElementById('camera_metadata_list');
    if (!target) return;
    const cameras = HIPDC_DB.data.cameras || [];
    target.innerHTML = cameras.length ? cameras.map(camera => `
      <tr>
        <td>${HIPDC_Utils.esc(camera.name)}</td><td>${HIPDC_Utils.esc(camera.gate_name)}</td>
        <td>${HIPDC_Utils.esc(camera.lane_name || '—')}</td><td>${HIPDC_Utils.esc(camera.model || '—')}</td>
        <td>${HIPDC_Utils.esc(camera.status)}</td><td>${HIPDC_Utils.esc(camera.monitoring_status)}</td>
        <td>
          <button class="btn sm soft" onclick="HIPDC_Admin.openCameraModal(${Number(camera.id)})">Edit metadata</button>
          <button class="btn sm soft" onclick="HIPDC_Admin.setCameraStatus(${Number(camera.id)}, '${camera.status === 'active' ? 'inactive' : 'active'}')">${camera.status === 'active' ? 'Deactivate' : 'Activate'}</button>
          <button class="btn sm soft" onclick="HIPDC_Admin.updateCameraMonitoring(${Number(camera.id)}, '${camera.monitoring_status === 'offline' ? 'unknown' : 'offline'}')">Mark ${camera.monitoring_status === 'offline' ? 'Unknown' : 'Offline'}</button>
        </td>
      </tr>`).join('') : '<tr><td colspan="7" class="table-empty">No camera metadata registered.</td></tr>';
  },

  openCameraModal(cameraId = null) {
    const camera = cameraId === null
      ? null
      : HIPDC_DB.data.cameras.find(item => Number(item.id) === Number(cameraId));
    const gates = HIPDC_DB.data.gates.filter(gate => gate.status === 'active')
      .map(gate => `<option value="${Number(gate.id)}" ${Number(gate.id) === Number(camera?.gate_id) ? 'selected' : ''}>${HIPDC_Utils.esc(gate.name)}</option>`).join('');
    if (!gates) {
      HIPDC_Notify.show('Create an active gate before registering camera metadata.', 'warn');
      return;
    }
    const lanes = HIPDC_DB.data.lanes.filter(lane =>
      lane.status === 'active' && Number(lane.gate_id) === Number(camera?.gate_id)
    ).map(lane => `<option value="${Number(lane.id)}" ${Number(lane.id) === Number(camera?.lane_id) ? 'selected' : ''}>${HIPDC_Utils.esc(lane.name)}</option>`).join('');
    const config = camera?.configuration || {};
    HIPDC_Modal.open(`
      <div class="modal">
        <div class="mh"><h3>${camera ? 'Edit camera metadata' : 'Register camera metadata'}</h3><button class="modal-close-btn" data-close-modal>&times;</button></div>
        <form onsubmit="HIPDC_Admin.saveCamera(event)">
          <input type="hidden" name="id" value="${camera ? Number(camera.id) : ''}">
          <div class="field"><label>Name</label><input class="inp" name="name" required maxlength="120" value="${HIPDC_Utils.esc(camera?.name || '')}"></div>
          <div class="field"><label>Model</label><input class="inp" name="model" maxlength="120" value="${HIPDC_Utils.esc(camera?.model || '')}"></div>
          <div class="field"><label>Serial reference</label><input class="inp" name="serial_reference" maxlength="120" value="${HIPDC_Utils.esc(camera?.serial_reference || '')}"></div>
          <div class="field"><label>Location</label><input class="inp" name="location" maxlength="160" value="${HIPDC_Utils.esc(camera?.location || '')}"></div>
          <div class="field"><label>Gate</label><select class="inp" name="gate_id" id="camera_gate_id" required>${gates}</select></div>
          <div class="field"><label>Lane</label><select class="inp" name="lane_id" id="camera_lane_id"><option value="">No specific lane</option>${lanes}</select></div>
          <div class="field"><label>Resolution metadata</label><input class="inp" name="resolution" placeholder="1920x1080" value="${HIPDC_Utils.esc(config.resolution || '')}"></div>
          <div class="field"><label>Frame rate metadata</label><input class="inp" type="number" min="1" max="120" name="frame_rate" value="${HIPDC_Utils.esc(config.frame_rate || '')}"></div>
          <div class="field"><label>View description</label><input class="inp" name="view_description" maxlength="160" value="${HIPDC_Utils.esc(config.view_description || '')}"></div>
          <div class="acts"><button type="button" class="btn soft" data-close-modal>Cancel</button><button class="btn" type="submit">${camera ? 'Save metadata' : 'Register metadata'}</button></div>
          <p class="muted">This records configuration metadata only; it does not connect to a camera.</p>
        </form>
      </div>`);
    document.getElementById('camera_gate_id').addEventListener('change', () => {
      const selectedGateId = Number(document.getElementById('camera_gate_id').value);
      const laneSelect = document.getElementById('camera_lane_id');
      laneSelect.innerHTML = '<option value="">No specific lane</option>' +
        HIPDC_DB.data.lanes.filter(lane => lane.status === 'active' && Number(lane.gate_id) === selectedGateId)
          .map(lane => `<option value="${Number(lane.id)}">${HIPDC_Utils.esc(lane.name)}</option>`).join('');
    });
  },

  async saveCamera(event) {
    event.preventDefault();
    const form = new FormData(event.target);
    try {
      const id = form.get('id');
      const configuration = {};
      if (form.get('resolution')) configuration.resolution = form.get('resolution');
      if (form.get('frame_rate')) configuration.frame_rate = Number(form.get('frame_rate'));
      if (form.get('view_description')) configuration.view_description = form.get('view_description');
      const payload = {
        name: form.get('name'), model: form.get('model') || null,
        serial_reference: form.get('serial_reference') || null,
        location: form.get('location') || null, gate_id: Number(form.get('gate_id')),
        lane_id: form.get('lane_id') ? Number(form.get('lane_id')) : null,
        configuration: Object.keys(configuration).length ? configuration : null
      };
      if (id) payload.id = Number(id);
      await HIPDC_API.post(id ? 'cameras/update.php' : 'cameras/index.php', payload);
      HIPDC_Modal.close();
      await HIPDC_DB.refresh();
      HIPDC_Notify.show(id ? 'Camera metadata updated.' : 'Camera metadata registered.', 'ok');
    } catch (error) {
      HIPDC_Notify.show(error.message, 'bad', 0);
    }
  },

  async setCameraStatus(id, status) {
    try {
      await HIPDC_API.post('cameras/status.php', { id, status });
      await HIPDC_DB.refresh();
      HIPDC_Notify.show('Camera status metadata updated. No device connection was made.', 'ok');
    } catch (error) {
      HIPDC_Notify.show(error.message, 'bad', 0);
    }
  },

  async updateCameraMonitoring(id, monitoringStatus) {
    try {
      await HIPDC_API.post('cameras/monitoring.php', {
        id, monitoring_status: monitoringStatus, monitoring_notes: 'Updated from the metadata management interface.'
      });
      await HIPDC_DB.refresh();
      HIPDC_Notify.show('Camera monitoring metadata updated. No device connection was made.', 'ok');
    } catch (error) {
      HIPDC_Notify.show(error.message, 'bad', 0);
    }
  },

  renderDashboardApprovals() {
    const container = document.getElementById('admin_dashboard_needs_approval');
    if (!container) return;

    const pending = HIPDC_DB.getApprovals('Pending');

    if (pending.length === 0) {
      container.innerHTML = `
        <div class="empty-state" style="padding: 24px 16px;">
          <div class="empty-icon" style="width: 42px; height: 42px;">${HIPDC_Utils.icon('chk', 20)}</div>
          <h4 class="empty-title">All Caught Up!</h4>
          <p class="empty-desc" style="margin-bottom:0">No pending approval requests waiting from entry or exit gates.</p>
        </div>
      `;
      return;
    }

    container.innerHTML = pending.map(a => `
      <div class="req">
        <div>
          <b style="font-weight: 600; font-size: 14px;">${HIPDC_Utils.esc(a.company)} · ${HIPDC_Utils.esc(a.plate)}</b>
          <span class="chip c-warn" style="margin-left: 8px;">${HIPDC_Utils.t('Pending')}</span>
          <p class="muted" style="margin-top: 4px; font-size: 12px;">
            ${HIPDC_Utils.t(a.gate === 'Entry' ? 'Entry gate' : 'Exit gate')} · ${HIPDC_Utils.esc(a.title)} · ${HIPDC_Utils.esc(a.officer)} · ${HIPDC_Utils.esc(a.time)}
          </p>
          ${a.note ? `<p style="margin-top: 4px; font-size: 12px; color: var(--ink);">Note: ${HIPDC_Utils.esc(a.note)}</p>` : ''}
        </div>
        <div class="tr">
          <button class="btn sm bad" onclick="HIPDC_Admin.handleDecisionPrompt(${a.id}, 'Denied')">${HIPDC_Utils.t('Deny')}</button>
          <button class="btn sm soft" onclick="HIPDC_Admin.callOfficer()">${HIPDC_Utils.t('Call Officer')}</button>
          <button class="btn sm" onclick="HIPDC_Admin.handleDecision(${a.id}, 'Approved')">${HIPDC_Utils.t('Approve')}</button>
        </div>
      </div>
    `).join('');
  },

  renderApprovalsPage() {
    const tbody = document.getElementById('admin_approvals_tbody');
    const detailsContainer = document.getElementById('admin_approval_details');
    if (!tbody) return;

    const searchInput = document.getElementById('approval_search');
    const q = searchInput ? searchInput.value.trim() : '';

    const gateSelect = document.getElementById('approval_gate_filter');
    if (gateSelect) {
      const selectedGate = gateSelect.value;
      gateSelect.innerHTML = '<option value="">All gates</option>' + HIPDC_DB.data.gates.map(gate =>
        `<option value="${Number(gate.id)}">${HIPDC_Utils.esc(gate.name)}</option>`
      ).join('');
      if (selectedGate) gateSelect.value = selectedGate;
    }
    const gateId = gateSelect?.value || '';
    const list = HIPDC_DB.getApprovals(this.activeTab, q).filter(request =>
      !gateId || String(request.gate_id || '') === gateId);

    if (list.length === 0) {
      tbody.innerHTML = `
        <tr>
          <td colspan="6" class="table-empty">
            ${HIPDC_Utils.renderEmptyState({
              icon: 'chk',
              title: `No ${this.activeTab} Approval Requests`,
              desc: `There are currently no ${this.activeTab.toLowerCase()} clearance requests from gate officers in the database.`,
              actionHtml: ''
            })}
          </td>
        </tr>
      `;
      if (detailsContainer) {
        detailsContainer.innerHTML = `<p class="muted" style="text-align: center; padding: 40px 10px;">Select a request to inspect details</p>`;
      }
      return;
    }

    if (!this.selectedApprovalId || !list.find(x => x.id === this.selectedApprovalId)) {
      this.selectedApprovalId = list[0].id;
    }

    tbody.innerHTML = list.map(a => `
      <tr class="click ${a.id === this.selectedApprovalId ? 'sel' : ''}" onclick="HIPDC_Admin.selectApproval(${a.id})">
        <td><b>${HIPDC_Utils.esc(a.title)}</b></td>
        <td><span class="pill">${HIPDC_Utils.t(a.gate === 'Entry' ? 'Entry gate' : 'Exit gate')}</span></td>
        <td class="col-plate">${HIPDC_Utils.esc(a.plate)}</td>
        <td>${HIPDC_Utils.esc(a.officer)}</td>
        <td class="col-time">${HIPDC_Utils.esc(a.time)}</td>
        <td>${HIPDC_ApprovalBadge.render(a.status)}</td>
      </tr>
    `).join('');

    const activeReq = list.find(x => x.id === this.selectedApprovalId);
    if (detailsContainer && activeReq) {
      this.renderApprovalDetailPanel(activeReq, detailsContainer);
      if (Number(this.approvalDetailId) !== Number(activeReq.id)) {
        this.loadApprovalDetails(activeReq.id);
      }
    }
  },

  selectApproval(id) {
    this.selectedApprovalId = id;
    this.approvalDetailId = null;
    this.approvalDetail = null;
    this.renderApprovalsPage();
  },

  async loadApprovalDetails(id) {
    this.approvalDetailId = id;
    try {
      const { request } = await HIPDC_API.get('approvals/view.php', { id });
      let related = null;
      if (request.goods_declaration_id) {
        related = (await HIPDC_API.get('goods/view.php', { id: request.goods_declaration_id })).declaration;
      } else if (request.visitor_pass_id) {
        related = (await HIPDC_API.get('visitors/passes/view.php', { id: request.visitor_pass_id })).pass;
      }
      if (Number(this.selectedApprovalId) !== Number(id)) return;
      this.approvalDetail = { request, related };
      this.renderApprovalsPage();
    } catch (error) {
      if (Number(this.selectedApprovalId) === Number(id)) {
        this.approvalDetail = { error: error.message };
        this.renderApprovalsPage();
      }
    }
  },

  renderApprovalDetailPanel(req, container) {
    const isPending = req.status === 'Pending';
    const detail = Number(this.approvalDetailId) === Number(req.id) ? this.approvalDetail : null;
    const request = detail?.request || null;
    const related = detail?.related || null;
    const goodsItems = related?.items || [];
    const history = request?.history || [];

    container.innerHTML = `
      <div style="display:flex; justify-content:space-between; align-items:center;">
        <h3>${HIPDC_Utils.t('Details')}</h3>
        ${HIPDC_ApprovalBadge.render(req.status)}
      </div>

      <div class="kv">
        <div><span>${HIPDC_Utils.t('Gate')}</span><b>${req.gate} Gate</b></div>
        <div><span>${HIPDC_Utils.t('Plate')}</span><b class="col-plate">${HIPDC_Utils.esc(req.plate)}</b></div>
        <div><span>${HIPDC_Utils.t('Tenant / Company')}</span><b>${HIPDC_Utils.esc(req.company)}</b></div>
        <div><span>${HIPDC_Utils.t('Driver')}</span><b>${HIPDC_Utils.esc(req.driver)}</b></div>
        <div><span>${HIPDC_Utils.t('Officer')}</span><b>${HIPDC_Utils.esc(request?.submitted_by_username || req.officer)}</b></div>
        <div><span>${HIPDC_Utils.t('Time')}</span><b>${HIPDC_Utils.esc(req.time)}</b></div>
        <div><span>Request type</span><b>${HIPDC_Utils.esc(req.requestType || 'access')}</b></div>
        ${request?.submitted_by_username ? `<div><span>Submitted by</span><b>${HIPDC_Utils.esc(request.submitted_by_username)}</b></div>` : ''}
        ${request?.assigned_reviewer_username ? `<div><span>Assigned reviewer</span><b>${HIPDC_Utils.esc(request.assigned_reviewer_username)}</b></div>` : ''}
        ${related?.reference ? `<div><span>Related record</span><b>${HIPDC_Utils.esc(related.reference)}</b></div>` : ''}
        ${related?.purpose ? `<div><span>Purpose</span><b>${HIPDC_Utils.esc(related.purpose)}</b></div>` : ''}
        ${related?.valid_from ? `<div><span>Pass validity</span><b>${HIPDC_Utils.esc(related.valid_from)} – ${HIPDC_Utils.esc(related.expires_at)}</b></div>` : ''}
        ${req.destination ? `<div><span>${HIPDC_Utils.t('Destination / Port of Exit')}</span><b>${HIPDC_Utils.esc(req.destination)}</b></div>` : ''}
        ${req.waybill ? `<div><span>${HIPDC_Utils.t('Tenant Gate Pass / Waybill No.')}</span><b>${HIPDC_Utils.esc(req.waybill)}</b></div>` : ''}
      </div>

      ${detail?.error ? `<div class="note c-warn">Related record details could not be loaded: ${HIPDC_Utils.esc(detail.error)}</div>` : ''}
      ${goodsItems.length > 0 ? `
        <div class="sec">${HIPDC_Utils.t('Item Description')}</div>
        <div class="scroll" style="max-height: 140px; border: 1px solid var(--line); border-radius: 8px;">
          <table>
            <thead><tr><th>${HIPDC_Utils.t('Item Description')}</th><th>${HIPDC_Utils.t('Quantity')}</th><th>${HIPDC_Utils.t('Unit')}</th><th>Reference</th><th>Remarks</th></tr></thead>
            <tbody>
              ${goodsItems.map(it => `
                <tr>
                  <td>${HIPDC_Utils.esc(it.description)}</td>
                  <td>${HIPDC_Utils.esc(it.quantity)}</td>
                  <td>${HIPDC_Utils.esc(it.unit)}</td>
                  <td>${HIPDC_Utils.esc(it.item_code || it.serial_number || '—')}</td>
                  <td>${HIPDC_Utils.esc(it.remarks || '—')}</td>
                </tr>
              `).join('')}
            </tbody>
          </table>
        </div>
      ` : ''}

      <div class="note c-info">
        <small style="font-weight: 600; text-transform: uppercase;">${HIPDC_Utils.t('Security Inspection Remarks & Seal Check')}</small><br>
        ${HIPDC_Utils.esc(request?.decision_reason || req.note) || '—'}
      </div>
      ${history.length ? `<div class="sec">Decision history</div><div class="scroll"><table>
        <thead><tr><th>Time</th><th>Actor</th><th>Transition</th><th>Reason</th></tr></thead>
        <tbody>${history.map(item => `<tr><td>${HIPDC_Utils.esc(item.created_at)}</td>
          <td>${HIPDC_Utils.esc(item.actor_username)}</td>
          <td>${HIPDC_Utils.esc(item.previous_status || '—')} → ${HIPDC_Utils.esc(item.new_status)}</td>
          <td>${HIPDC_Utils.esc(item.reason || '—')}</td></tr>`).join('')}</tbody></table></div>` : ''}

      ${isPending ? `
        <div class="field">
          <label>Denial Reason (Required if rejecting request)</label>
          <input id="admin_denial_reason" class="inp" placeholder="e.g. Missing tenant waybill authorization">
        </div>

        <div class="acts">
          <button class="btn ok" onclick="HIPDC_Admin.handleDecision(${req.id}, 'Approved')">${HIPDC_Utils.t('Approve Clearance')}</button>
          <button class="btn soft" onclick="HIPDC_Admin.callOfficer()">${HIPDC_Utils.t('Call Officer')}</button>
          <button class="btn bad" onclick="HIPDC_Admin.handleDecisionPrompt(${req.id}, 'Denied')">${HIPDC_Utils.t('Deny Entry/Exit')}</button>
        </div>
      ` : `
        <div class="note ${req.status === 'Approved' ? 'c-ok' : 'c-bad'}">
          <b>Resolution: ${req.status}</b><br>
          Clearance request was resolved on ${req.reviewedAt || 'Today'}.
        </div>
      `}
    `;
  },

  handleDecisionPrompt(id, decision) {
    const reasonInput = document.getElementById('admin_denial_reason');
    const reason = reasonInput
      ? reasonInput.value.trim()
      : (window.prompt('Provide a reason for denying this request:') || '').trim();
    if (!reason) {
      if (window.HIPDC_Notify) {
        window.HIPDC_Notify.show('Please provide a reason for denying the request.', 'warn');
      }
      if (reasonInput) reasonInput.focus();
      return;
    }
    this.handleDecision(id, decision, reason);
  },

  async handleDecision(id, decision, reason = '') {
    try {
      const updated = await HIPDC_DB.updateApprovalStatus(id, decision, reason);
      if (HIPDC_DB.lastIssuedQrToken) {
        this.showIssuedVisitorToken(updated, HIPDC_DB.lastIssuedQrToken);
        HIPDC_DB.lastIssuedQrToken = null;
      } else {
        HIPDC_Notify.show(`Request ${updated.reference} was ${decision.toLowerCase()}.`, decision === 'Approved' ? 'ok' : 'bad');
      }
      this.renderPageData();
      this.updateApprovalBadges();
    } catch (error) {
      HIPDC_Notify.show(error.message, 'bad', 0);
    }
  },

  callOfficer() {
    HIPDC_Notify.show('No radio or intercom integration is configured.', 'info');
  },

  renderSchedulesTable() {
    const tbody = document.getElementById('admin_schedules_tbody');
    if (!tbody) return;

    const searchInput = document.getElementById('schedule_search');
    const q = searchInput ? searchInput.value.trim() : '';
    const schedules = HIPDC_DB.getSchedules(q);
    HIPDC_ScheduleFilters.populate(HIPDC_DB.data.schedules);
    const list = HIPDC_ScheduleFilters.filter(schedules);

    if (list.length === 0) {
      tbody.innerHTML = `
        <tr>
          <td colspan="9" class="table-empty">
            ${HIPDC_Utils.renderEmptyState({
              icon: 'cal',
              title: 'No Vehicle Schedules in Database',
              desc: 'No upcoming arrivals or departures are planned for today.',
              actionHtml: `<button class="btn sm" onclick="HIPDC_Admin.openScheduleModal()">${HIPDC_Utils.t('+ Schedule a Vehicle')}</button>`
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
        <td>${s.purpose}</td>
        <td><span class="chip ${s.status === 'Inside' ? 'c-ok' : 'c-warn'}">${HIPDC_Utils.t(s.status)}</span></td>
        <td>
          ${s.status === 'Expected' ? `<button class="btn sm soft" onclick="HIPDC_Admin.openScheduleModal(${Number(s.id)})">Edit</button>
          <button class="btn sm bad" onclick="HIPDC_Admin.cancelSchedule(${Number(s.id)})">Cancel</button>` : '<span class="muted">No actions</span>'}
        </td>
      </tr>
    `).join('');
  },

  openScheduleModal(editScheduleId = null) {
    const existing = editScheduleId
      ? HIPDC_DB.data.schedules.find(schedule => Number(schedule.id) === Number(editScheduleId))
      : null;
    const vehicles = HIPDC_DB.data.vehicles.filter(vehicle => vehicle.status === 'Active');
    const drivers = HIPDC_DB.data.drivers.filter(driver => driver.status === 'active');
    const vehicleOptions = vehicles.map(vehicle =>
      `<option value="${HIPDC_Utils.esc(vehicle.plate)}">${HIPDC_Utils.esc(vehicle.plate)} · ${HIPDC_Utils.esc(vehicle.company)}</option>`
    ).join('');
    const driverOptions = drivers.map(driver =>
      `<option value="${HIPDC_Utils.esc(driver.full_name)}">${HIPDC_Utils.esc(driver.full_name)}</option>`
    ).join('');
    const modalHtml = `
      <div class="modal wide">
        <div class="mh">
          <div>
            <h3>${existing ? 'Edit Schedule' : HIPDC_Utils.t('+ Schedule a Vehicle')}</h3>
            <span class="muted">Creates an expected schedule, not an access event</span>
          </div>
          <button type="button" class="modal-close-btn" data-close-modal aria-label="Close dialog">&times;</button>
        </div>

        <form id="scheduleVehicleForm" onsubmit="HIPDC_Admin.saveSchedule(event)">
          <div class="grid" style="--n: 3;">
            <div class="field">
              <label>${HIPDC_Utils.t('Tenant / Company')}</label>
              <input class="inp" name="company" placeholder="From the registered vehicle" value="${HIPDC_Utils.esc(existing?.company || '')}" readonly>
            </div>
            <div class="field">
              <label>${HIPDC_Utils.t('Purpose')}</label>
              <select class="inp" name="purpose">
                <option ${existing?.purpose === 'Delivery' ? 'selected' : ''}>Delivery</option>
                <option ${existing?.purpose === 'Pickup' ? 'selected' : ''}>Pickup</option>
                <option ${existing?.purpose === 'Maintenance' ? 'selected' : ''}>Maintenance</option>
              </select>
            </div>
            <div class="field">
              <label>${HIPDC_Utils.t('Plate')}</label>
              <select class="inp" name="plate" required><option value="">Select registered vehicle</option>${vehicleOptions}</select>
            </div>
            <div class="field">
              <label>${HIPDC_Utils.t('Car Model')}</label>
              <input class="inp" name="model" placeholder="e.g. Isuzu FSR Heavy" required>
            </div>
            <div class="field">
              <label>${HIPDC_Utils.t('Driver Full Name')}</label>
              <select class="inp" name="driver" required><option value="">Select registered driver</option>${driverOptions}</select>
            </div>
            <div class="field">
              <label>${HIPDC_Utils.t('Driver Phone')}</label>
              <input class="inp" name="phone" placeholder="+251 9..." required>
            </div>
            <div class="field">
              <label>${HIPDC_Utils.t('Visit Date')}</label>
              <input class="inp" type="date" name="date" value="${existing?.scheduled_at?.slice(0,10) || new Date().toISOString().slice(0, 10)}" required>
            </div>
            <div class="field">
              <label>${HIPDC_Utils.t('Arrival Window')}</label>
              <input class="inp" type="time" name="time" value="${existing?.scheduled_at?.slice(11,16) || '09:00'}" required>
            </div>
            <div class="field"><label>Direction</label><select class="inp" name="direction"><option value="entry" ${existing?.direction === 'entry' ? 'selected' : ''}>Entry</option><option value="exit" ${existing?.direction === 'exit' ? 'selected' : ''}>Exit</option></select></div>
            <div class="field">
              <label>Expected Cargo</label>
              <input class="inp" name="cargo" placeholder="e.g. Raw cotton bales" value="${HIPDC_Utils.esc(existing?.cargo || '')}">
            </div>
          </div>

          <div class="field" style="margin-top: 12px;">
            <label>${HIPDC_Utils.t('Security Inspection Remarks & Seal Check')}</label>
            <textarea name="notes" placeholder="Any specific requirements..."></textarea>
          </div>

          <div class="acts" style="justify-content: flex-end; margin-top: 16px;">
            <button type="button" class="btn soft" data-close-modal>${HIPDC_Utils.t('Cancel')}</button>
            <button type="submit" class="btn">Confirm Schedule</button>
          </div>
        </form>
      </div>
    `;

    HIPDC_Modal.open(modalHtml);
    const form = document.getElementById('scheduleVehicleForm');
    if (existing && form) {
      form.elements.plate.value = existing.plate;
      form.elements.driver.value = existing.driver;
      form.dataset.scheduleId = String(existing.id);
    }
  },

  async saveSchedule(e) {
    e.preventDefault();
    const form = e.target;
    const formData = new FormData(form);
    try {
      await HIPDC_DB.addSchedule({
        id: form.dataset.scheduleId ? Number(form.dataset.scheduleId) : null,
        company: formData.get('company'),
        purpose: formData.get('purpose'),
        plate: formData.get('plate'),
        model: formData.get('model'),
        driver: formData.get('driver'),
        date: formData.get('date'),
        time: formData.get('time'),
        direction: formData.get('direction'),
        cargo: formData.get('cargo')
      });
      HIPDC_Modal.close();
      HIPDC_Notify.show('Schedule saved. It is an expected visit, not a gate event.', 'ok');
    } catch (error) {
      HIPDC_Notify.show(error.message, 'bad', 0);
    }
  },

  async cancelSchedule(id) {
    if (!window.confirm('Cancel this schedule? It will remain in history and will not be a gate event.')) return;
    try {
      await HIPDC_API.post('schedules/status.php', { status: 'cancelled' }, { id });
      await HIPDC_DB.refresh();
      HIPDC_Notify.show('Schedule cancelled.', 'ok');
    } catch (error) {
      HIPDC_Notify.show(error.message, 'bad', 0);
    }
  },

  renderVisitorsTable() {
    const tbody = document.getElementById('admin_visitors_tbody');
    if (!tbody) return;
    const permissions = new Set(HIPDC_API.currentUser?.permissions || []);

    const searchInput = document.getElementById('visitor_search');
    const q = searchInput ? searchInput.value.trim() : '';
    const dateFilter = document.getElementById('visitor_date_filter')?.value || '';
    const passFilter = document.getElementById('visitor_pass_filter')?.value || '';
    const list = HIPDC_DB.getVisitors(q).filter(visitor => {
      const passStatus = this.latestVisitorPass(visitor.id)?.status || 'none';
      return (!dateFilter || String(visitor.expected_arrival_at || '').slice(0, 10) === dateFilter)
        && (!passFilter || passStatus === passFilter);
    });

    if (list.length === 0) {
      tbody.innerHTML = `
        <tr>
          <td colspan="7" class="table-empty">
            ${HIPDC_Utils.renderEmptyState({
              icon: 'qr',
              title: 'No Registered Visitors in Database',
              desc: 'No visitor gate passes have been registered yet.',
              actionHtml: `<button class="btn sm" onclick="HIPDC_Admin.openVisitorModal()">${HIPDC_Utils.t('+ Register Visitor')}</button>`
            })}
          </td>
        </tr>
      `;
      return;
    }

    tbody.innerHTML = list.map(v => `
      <tr>
        <td><b>${HIPDC_Utils.esc(v.name)}</b></td>
        <td>${HIPDC_Utils.esc(v.company)}</td>
        <td class="col-plate">${v.plate}</td>
        <td>${v.date}</td>
        <td>${HIPDC_Utils.esc(v.purpose)}</td>
        <td><span class="chip ${v.status === 'Active' ? 'c-ok' : ''}">${HIPDC_Utils.t(v.status)}
          · ${HIPDC_Utils.esc(this.latestVisitorPass(v.id)?.status || 'no pass')}</span></td>
        <td>
          ${permissions.has('visitors.view') ? `<button class="btn sm soft" onclick="HIPDC_Admin.openVisitorHistory(${Number(v.id)})">History</button>` : ''}
          ${permissions.has('visitors.update') ? `<button class="btn sm soft" onclick="HIPDC_Admin.openVisitorModal(${Number(v.id)})">Edit</button>` : ''}
          ${permissions.has('visitors.manage_status') ? `<button class="btn sm soft" onclick="HIPDC_Admin.toggleVisitorStatus(${Number(v.id)}, '${v.status === 'Active' ? 'inactive' : 'active'}')">
            ${v.status === 'Active' ? 'Deactivate' : 'Activate'}
          </button>` : ''}
          ${permissions.has('visitors.passes.issue') ? `<button class="btn sm soft" onclick="HIPDC_Admin.issueVisitorPass(${Number(v.id)})" ${v.status !== 'Active' || ['active','pending_approval'].includes(this.latestVisitorPass(v.id)?.status) ? 'disabled' : ''}>
            Request Gate Pass
          </button>` : ''}
          ${permissions.has('visitors.passes.revoke') && this.latestVisitorPass(v.id)?.status === 'active' ? `<button class="btn sm bad" onclick="HIPDC_Admin.revokeVisitorPass(${Number(this.latestVisitorPass(v.id).id)})">Revoke</button>` : ''}
        </td>
      </tr>
    `).join('');
  },

  async openVisitorHistory(id) {
    try {
      const history = await HIPDC_API.get('visitors/history.php', { id });
      const events = (history.events || []).map(event => `
        <tr><td>${HIPDC_Utils.esc(event.created_at)}</td>
          <td>${HIPDC_Utils.esc(event.action)}</td>
          <td>${HIPDC_Utils.esc(event.actor_username || '—')}</td></tr>`).join('');
      const passes = (history.passes || []).map(item => `
        <tr><td>${HIPDC_Utils.esc(item.pass.reference)}</td>
          <td>${HIPDC_Utils.esc(item.pass.status)}</td>
          <td>${HIPDC_Utils.esc(item.pass.valid_from)} – ${HIPDC_Utils.esc(item.pass.expires_at)}</td>
          <td>${HIPDC_Utils.esc(item.approval?.status || 'No approval')}</td></tr>`).join('');
      HIPDC_Modal.open(`<div class="modal wide">
        <div class="mh"><h3>Visitor history</h3><button class="modal-close-btn" data-close-modal>&times;</button></div>
        <h4>Activity</h4><div class="scroll"><table><thead><tr><th>Time</th><th>Action</th><th>Actor</th></tr></thead>
          <tbody>${events || '<tr><td colspan="3" class="muted">No activity recorded.</td></tr>'}</tbody></table></div>
        <h4>Passes</h4><div class="scroll"><table><thead><tr><th>Reference</th><th>Status</th><th>Validity</th><th>Approval</th></tr></thead>
          <tbody>${passes || '<tr><td colspan="4" class="muted">No passes issued.</td></tr>'}</tbody></table></div>
      </div>`);
    } catch (error) {
      HIPDC_Notify.show(error.message, 'bad', 0);
    }
  },

  async toggleVisitorStatus(id, status) {
    try {
      await HIPDC_DB.setVisitorStatus(id, status);
      HIPDC_Notify.show(`Visitor ${status === 'active' ? 'activated' : 'deactivated'}.`, 'ok');
      this.renderVisitorsTable();
    } catch (error) {
      HIPDC_Notify.show(error.message, 'bad', 0);
    }
  },

  async openVisitorModal(visitorId = null) {
    let visitor = null;
    if (visitorId !== null) {
      try {
        visitor = (await HIPDC_API.get('visitors/view.php', { id: visitorId })).visitor;
      } catch (error) {
        HIPDC_Notify.show(error.message, 'bad', 0);
        return;
      }
    }
    const companies = HIPDC_DB.data.companies
      .filter(company => company.status === 'active' || Number(company.id) === Number(visitor?.company_id))
      .map(company => `<option value="${HIPDC_Utils.esc(company.name)}" ${Number(company.id) === Number(visitor?.company_id) ? 'selected' : ''}>${HIPDC_Utils.esc(company.name)}</option>`)
      .join('');
    const schedules = HIPDC_DB.data.schedules
      .filter(schedule => schedule.status === 'Expected' || Number(schedule.id) === Number(visitor?.schedule_id))
      .map(schedule => `<option value="${Number(schedule.id)}" ${Number(schedule.id) === Number(visitor?.schedule_id) ? 'selected' : ''}>${HIPDC_Utils.esc(schedule.plate)} · ${HIPDC_Utils.esc(schedule.win)}</option>`)
      .join('');
    const dateTimeLocal = value => String(value || '').replace(' ', 'T').slice(0, 16);
    const isEditing = visitor !== null;
    const modalHtml = `
      <div class="modal wide">
        <div class="mh">
          <div>
            <h3>${isEditing ? 'Edit visitor' : HIPDC_Utils.t('+ Register Visitor')}</h3>
            <span class="muted">Register a visitor first. Passes require separate gate-specific approval.</span>
          </div>
          <button type="button" class="modal-close-btn" data-close-modal aria-label="Close dialog">&times;</button>
        </div>

        <form id="visitorRegistrationForm" onsubmit="HIPDC_Admin.saveVisitor(event)">
          <div class="grid" style="--n: 2;">
            <div class="field">
              <label>${HIPDC_Utils.t('Visitor Name')}</label>
              <input class="inp" name="name" placeholder="Full name" value="${HIPDC_Utils.esc(visitor?.full_name || '')}" required>
            </div>
            <div class="field">
              <label>Fayda ID / Passport Number</label>
              <input class="inp" name="id_num" placeholder="Identification reference (optional)" value="${HIPDC_Utils.esc(visitor?.document_reference || '')}">
            </div>
            <div class="field">
              <label>${HIPDC_Utils.t('Driver Phone')}</label>
              <input class="inp" name="phone" placeholder="+251 9... (optional)" value="${HIPDC_Utils.esc(visitor?.phone || '')}">
            </div>
            <div class="field"><label>Email</label><input class="inp" type="email" name="email" value="${HIPDC_Utils.esc(visitor?.email || '')}"></div>
            <div class="field"><label>Organization</label><input class="inp" name="organization" value="${HIPDC_Utils.esc(visitor?.organization || '')}"></div>
            <div class="field">
              <label>${HIPDC_Utils.t('Host Tenant')}</label>
              <select class="inp" name="company"><option value="">No host company</option>${companies}</select>
            </div>
            <div class="field">
              <label>Host/contact person</label>
              <input class="inp" name="host_person" value="${HIPDC_Utils.esc(visitor?.host_person || '')}">
            </div>
            <div class="field">
              <label>${HIPDC_Utils.t('Purpose')}</label>
              <input class="inp" name="purpose" placeholder="e.g. Factory Audit" value="${HIPDC_Utils.esc(visitor?.purpose || '')}" required>
            </div>
            <div class="field"><label>Expected arrival</label><input class="inp" type="datetime-local" name="arrival" value="${dateTimeLocal(visitor?.expected_arrival_at)}" required></div>
            <div class="field"><label>Expected departure</label><input class="inp" type="datetime-local" name="departure" value="${dateTimeLocal(visitor?.expected_departure_at)}"></div>
            <div class="field"><label>Related schedule (optional)</label><select class="inp" name="schedule_id"><option value="">No schedule</option>${schedules}</select></div>
          </div>

          <div class="acts" style="justify-content: flex-end; margin-top: 18px;">
            <button type="button" class="btn soft" data-close-modal>${HIPDC_Utils.t('Cancel')}</button>
            <button type="submit" class="btn">${isEditing ? 'Save visitor' : 'Register Visitor'}</button>
          </div>
        </form>
      </div>
    `;

    HIPDC_Modal.open(modalHtml);
    if (visitorId !== null) {
      const form = document.getElementById('visitorRegistrationForm');
      if (form) form.dataset.visitorId = String(visitorId);
    }
  },

  async saveVisitor(e) {
    e.preventDefault();
    const form = e.target;
    const formData = new FormData(form);
    try {
      const input = {
        name: formData.get('name'),
        id_num: formData.get('id_num'),
        phone: formData.get('phone'),
        email: formData.get('email'),
        organization: formData.get('organization'),
        company: formData.get('company'),
        host_person: formData.get('host_person'),
        purpose: formData.get('purpose'),
        expected_arrival_at: formData.get('arrival'),
        expected_departure_at: formData.get('departure'),
        schedule_id: formData.get('schedule_id') || null
      };
      if (form.dataset.visitorId) {
        await HIPDC_DB.updateVisitor(Number(form.dataset.visitorId), input);
      } else {
        await HIPDC_DB.addVisitor(input);
      }
      HIPDC_Modal.close();
      HIPDC_Notify.show(form.dataset.visitorId
        ? 'Visitor updated. Existing pass decisions remain separate.'
        : 'Visitor registered. No pass or QR credential has been issued.', 'ok');
    } catch (error) {
      HIPDC_Notify.show(error.message, 'bad', 0);
    }
  },

  async issueVisitorPass(visitorId) {
    try {
      const visitor = HIPDC_DB.data.visitors.find(item => Number(item.id) === Number(visitorId));
      if (!visitor) throw new Error('Visitor record is unavailable. Refresh and retry.');
      const locations = await HIPDC_API.currentGateLocations();
      const gates = [...new Map(locations.map(item => [Number(item.gate_id), {
        id: Number(item.gate_id),
        name: item.gate_name,
        directions: [...new Set(locations
          .filter(location => Number(location.gate_id) === Number(item.gate_id))
          .map(location => location.operation_direction))]
      }])).values()];
      const gateOptions = gates.map(gate =>
        `<option value="${gate.id}">${HIPDC_Utils.esc(gate.name)}</option>`
      ).join('');
      if (!gateOptions) throw new Error('No assigned gate is available for pass issuance.');
      HIPDC_Modal.open(`
        <div class="modal">
          <div class="mh"><h3>Request a visitor gate pass</h3><button type="button" class="modal-close-btn" data-close-modal>&times;</button></div>
          <p>${HIPDC_Utils.esc(visitor.name)} · ${HIPDC_Utils.esc(visitor.company)}</p>
          <p class="muted">A pass request requires approval. This does not create an entry or exit event.</p>
          <form onsubmit="HIPDC_Admin.submitVisitorPass(event, ${Number(visitorId)})">
            <div class="field"><label>Gate</label><select class="inp" name="gate_id" id="visitor_pass_gate" required>${gateOptions}</select></div>
            <div class="field"><label>Direction</label><select class="inp" name="direction" id="visitor_pass_direction" required></select></div>
            <div class="field"><label>Valid from</label><input class="inp" type="datetime-local" name="valid_from" required></div>
            <div class="field"><label>Expires at</label><input class="inp" type="datetime-local" name="expires_at" required></div>
            <div class="acts"><button type="button" class="btn soft" data-close-modal>Cancel</button><button type="submit" class="btn">Submit for approval</button></div>
          </form>
        </div>`);
      const gateSelect = document.getElementById('visitor_pass_gate');
      const directionSelect = document.getElementById('visitor_pass_direction');
      const updateDirections = () => {
        const selectedGate = gates.find(gate => gate.id === Number(gateSelect.value));
        directionSelect.innerHTML = (selectedGate?.directions || []).map(direction =>
          `<option value="${HIPDC_Utils.esc(direction)}">${direction === 'entry' ? 'Entry' : 'Exit'}</option>`
        ).join('');
      };
      gateSelect.addEventListener('change', updateDirections);
      updateDirections();
    } catch (error) {
      HIPDC_Notify.show(error.message, 'bad', 0);
    }
  },

  latestVisitorPass(visitorId) {
    return HIPDC_DB.data.passes
      .filter(pass => Number(pass.visitor_id) === Number(visitorId))
      .sort((a, b) => String(b.created_at).localeCompare(String(a.created_at)))[0] || null;
  },

  revokeVisitorPass(passId) {
    HIPDC_Modal.open(`<div class="modal"><div class="mh"><h3>Revoke visitor pass</h3><button class="modal-close-btn" data-close-modal>&times;</button></div>
      <form onsubmit="HIPDC_Admin.submitPassRevocation(event, ${Number(passId)})">
        <div class="field"><label>Reason</label><textarea class="inp" name="reason" required maxlength="1000"></textarea></div>
        <div class="acts"><button type="button" class="btn soft" data-close-modal>Cancel</button><button class="btn bad">Revoke pass</button></div>
      </form></div>`);
  },

  async submitPassRevocation(event, passId) {
    event.preventDefault();
    const reason = new FormData(event.currentTarget).get('reason');
    try {
      await HIPDC_API.post('visitors/passes/revoke.php', { reason }, { id: passId });
      HIPDC_Modal.close();
      await HIPDC_DB.refresh();
      HIPDC_Notify.show('Visitor pass revoked.', 'ok');
    } catch (error) {
      HIPDC_Notify.show(error.message, 'bad', 0);
    }
  },

  async submitVisitorPass(event, visitorId) {
    event.preventDefault();
    const form = new FormData(event.target);
    try {
      const result = await HIPDC_API.post('visitors/passes/issue.php', {
        visitor_id: visitorId, gate_id: Number(form.get('gate_id')),
        direction: form.get('direction'),
        valid_from: form.get('valid_from').replace('T', ' '),
        expires_at: form.get('expires_at').replace('T', ' ')
      });
      HIPDC_Modal.close();
      HIPDC_Notify.show('Pass submitted for approval. QR credentials are issued only after approval.', 'ok');
      await HIPDC_DB.refresh();
      return result;
    } catch (error) {
      HIPDC_Notify.show(error.message, 'bad', 0);
    }
  },

  showIssuedVisitorToken(request, token) {
    HIPDC_Modal.open(`
      <div class="modal">
        <div class="mh"><h3>Visitor pass approved</h3><button type="button" class="modal-close-btn" data-close-modal>&times;</button></div>
        <p>This QR credential is shown once. Copy it now; the server stores only its hash.</p>
        <p><b>${HIPDC_Utils.esc(request.visitor_name || request.title)}</b></p>
        <textarea class="inp" readonly rows="3" id="issued_visitor_token">${HIPDC_Utils.esc(token)}</textarea>
        <div class="acts"><button class="btn soft" type="button" onclick="navigator.clipboard.writeText(document.getElementById('issued_visitor_token').value)">Copy credential</button><button class="btn" data-close-modal>Close</button></div>
        <p class="muted">No QR scanner hardware or physical gate control is connected.</p>
      </div>`);
  },

  renderVehiclesTable() {
    const tbody = document.getElementById('admin_vehicles_tbody');
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
              title: 'No Registered Vehicles in Database',
              desc: 'No commercial or service vehicles registered in the system yet.',
              actionHtml: `<button class="btn sm" onclick="HIPDC_Admin.openVehicleModal()">${HIPDC_Utils.t('+ Register Vehicle')}</button>`
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
          <button class="btn sm soft" onclick="HIPDC_Admin.toggleVehicleStatus('${v.plate}')">
            ${v.status === 'Blocked' ? HIPDC_Utils.t('Unblock') : HIPDC_Utils.t('Block Access')}
          </button>
        </td>
      </tr>
    `).join('');
  },

  openVehicleModal() {
    const companies = HIPDC_DB.data.companies
      .filter(company => company.status === 'active')
      .map(company => `<option value="${HIPDC_Utils.esc(company.name)}">${HIPDC_Utils.esc(company.name)}</option>`)
      .join('');
    const drivers = HIPDC_DB.data.drivers
      .filter(driver => driver.status === 'active')
      .map(driver => `<option value="${HIPDC_Utils.esc(driver.full_name)}">${HIPDC_Utils.esc(driver.full_name)}</option>`)
      .join('');
    const modalHtml = `
      <div class="modal wide">
        <div class="mh">
          <div>
            <h3>${HIPDC_Utils.t('+ Register Vehicle')}</h3>
            <span class="muted">Adds a registry record. Automatic plate recognition is not connected.</span>
          </div>
          <button type="button" class="modal-close-btn" data-close-modal aria-label="Close dialog">&times;</button>
        </div>

        <form id="registerVehicleForm" onsubmit="HIPDC_Admin.saveVehicle(event)">
          <div class="grid" style="--n: 3;">
            <div class="field">
              <label>${HIPDC_Utils.t('Tenant / Company')}</label>
              <select class="inp" name="company" required><option value="">Select company</option>${companies}</select>
            </div>
            <div class="field">
              <label>${HIPDC_Utils.t('Plate')}</label>
              <input class="inp" name="plate" placeholder="SNNP 3-A..." required>
            </div>
            <div class="field">
              <label>${HIPDC_Utils.t('Car Model')}</label>
              <input class="inp" name="model" placeholder="e.g. Fuso Canter" required>
            </div>
            <div class="field">
              <label>${HIPDC_Utils.t('Driver')}</label>
              <select class="inp" name="driver"><option value="">No assigned driver</option>${drivers}</select>
            </div>
            <div class="field">
              <label>${HIPDC_Utils.t('Driver Phone')}</label>
              <input class="inp" name="phone" placeholder="+251 9..." required>
            </div>
            <div class="field">
              <label>${HIPDC_Utils.t('Licence Expiry')}</label>
              <input class="inp" type="date" name="expiry">
            </div>
          </div>

          <div class="acts" style="justify-content: flex-end; margin-top: 18px;">
            <button type="button" class="btn soft" data-close-modal>${HIPDC_Utils.t('Cancel')}</button>
            <button type="submit" class="btn">${HIPDC_Utils.t('Register Vehicle')}</button>
          </div>
        </form>
      </div>
    `;

    HIPDC_Modal.open(modalHtml);
  },

  async saveVehicle(e) {
    e.preventDefault();
    const form = e.target;
    const formData = new FormData(form);

    try {
      await HIPDC_DB.addVehicle({
        company: formData.get('company'), plate: formData.get('plate'),
        model: formData.get('model'), driver: formData.get('driver'),
        expiry: formData.get('expiry'), status: 'Active'
      });
      HIPDC_Modal.close();
      HIPDC_Notify.show('Vehicle registered.', 'ok');
    } catch (error) {
      HIPDC_Notify.show(error.message, 'bad', 0);
    }
  },

  async toggleVehicleStatus(plate) {
    const veh = HIPDC_DB.data.vehicles.find(v => v.plate === plate);
    if (veh) {
      try {
        await HIPDC_DB.setVehicleStatus(veh.id, veh.status === 'Blocked' ? 'active' : 'blocked');
        HIPDC_Notify.show(`Vehicle ${plate} status updated.`, 'info');
      } catch (error) {
        HIPDC_Notify.show(error.message, 'bad', 0);
      }
      this.renderPageData();
    }
  },

  renderLogisticsTable() {
    const tbody = document.getElementById('admin_logistics_tbody');
    if (!tbody) return;

    const gateSelect = document.getElementById('logistics_gate_select');
    const gate = gateSelect ? gateSelect.value : 'entry';
    const searchInput = document.getElementById('logistics_search');
    const q = searchInput ? searchInput.value.trim() : '';

    const list = HIPDC_DB.getLogs(gate, q);

    if (list.length === 0) {
      tbody.innerHTML = `
        <tr>
          <td colspan="8" class="table-empty">
            ${HIPDC_Utils.renderEmptyState({
              icon: 'list',
              title: `No ${gate.toUpperCase()} Gate Logs Found`,
              desc: `There are currently no access records saved for ${gate} gate in the database.`,
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
        <td><span class="pill">${gate.toUpperCase()}</span></td>
        <td class="col-plate">${l.plate}</td>
        <td>${HIPDC_Utils.esc(l.driver)}</td>
        <td>${HIPDC_Utils.esc(l.company)}</td>
        <td><span class="chip c-info">${HIPDC_Utils.t(l.type)}</span></td>
        <td><span class="chip ${l.adminApproval.includes('Approved') ? 'c-ok' : ''}">${HIPDC_Utils.t(l.adminApproval)}</span></td>
        <td>
          <button class="btn sm pdf" onclick='HIPDC_Admin.downloadGatePass(${JSON.stringify(l).replace(/'/g, "&apos;")})'>
            ${HIPDC_Utils.icon('pdf', 14)} PDF
          </button>
        </td>
      </tr>
    `).join('');
  },

  downloadGatePass(log) {
    HIPDC_PDF.generateExitLogPDF({
      id: log.reference || log.id || '—',
      plate: log.plate,
      driver: log.driver,
      company: log.company,
      time: log.time,
      direction: log.type,
      status: log.access_result === 'granted' ? 'Authorized' : 'Denied',
      notes: log.details || 'Official logistics record',
      items: log.items || []
    });
  },

  renderUsersTable() {
    const tbody = document.getElementById('admin_users_tbody');
    if (!tbody) return;

    const searchInput = document.getElementById('user_search');
    const q = searchInput ? searchInput.value.trim() : '';
    const users = HIPDC_DB.getUsers(q);
    const roleSelect = document.getElementById('user_role_filter');
    const gateSelect = document.getElementById('user_gate_filter');
    const statusValue = document.getElementById('user_status_filter')?.value || '';
    const options = (select, values, label) => {
      if (!select) return '';
      const selected = select.value;
      select.innerHTML = `<option value="">All ${label}</option>` + values.map(value =>
        `<option value="${HIPDC_Utils.esc(value)}">${HIPDC_Utils.esc(value)}</option>`
      ).join('');
      if (selected) select.value = selected;
      return select.value;
    };
    const roles = [...new Set(HIPDC_DB.data.users.map(user => user.role).filter(Boolean))].sort();
    const gates = [...new Set(HIPDC_DB.data.users.flatMap(user =>
      String(user.gate || '').split(', ').filter(value => value && value !== 'Unassigned')
    ))].sort();
    options(roleSelect, roles, 'roles');
    options(gateSelect, gates, 'gates');
    const selectedRole = roleSelect?.value || '';
    const selectedGate = gateSelect?.value || '';
    const list = users.filter(user =>
      (!selectedRole || user.role === selectedRole)
      && (!selectedGate || String(user.gate || '').split(', ').includes(selectedGate))
      && (!statusValue || user.status === statusValue)
    );

    tbody.innerHTML = list.map(u => {
      const nextStatus = u.statusCode === 'active' ? 'inactive' : 'active';
      const statusLabel = u.statusCode === 'active' ? 'Deactivate' : 'Activate';
      return `
      <tr>
        <td><b>${HIPDC_Utils.esc(u.name)}</b></td>
        <td><code>${HIPDC_Utils.esc(u.username)}</code></td>
        <td><span class="pill">${HIPDC_Utils.esc(u.role)}</span></td>
        <td>${HIPDC_Utils.esc(u.gate)}</td>
        <td>${HIPDC_Utils.esc(u.shift)}</td>
        <td>${HIPDC_Utils.esc(u.lastLogin)}</td>
        <td><span class="chip ${u.status === 'Active' ? 'c-ok' : 'c-bad'}">${HIPDC_Utils.t(u.status)}</span></td>
        <td>
          <button class="btn sm soft" type="button" onclick="HIPDC_Admin.editUserRole(${Number(u.id)})">Role</button>
          <button class="btn sm soft" type="button" onclick="HIPDC_Admin.editUserGates(${Number(u.id)})">Gates</button>
          <button class="btn sm soft" type="button" onclick="HIPDC_Admin.resetUserPassword(${Number(u.id)})">Password</button>
          <button class="btn sm ${u.statusCode === 'active' ? 'bad' : ''}" type="button" onclick="HIPDC_Admin.setUserStatus(${Number(u.id)}, '${nextStatus}')">${statusLabel}</button>
        </td>
      </tr>`;
    }).join('');
  },

  async openCreateUserModal() {
    try {
      if (!this.userOptionsLoaded) await this.loadUserOptions();
    } catch (error) {
      HIPDC_Notify.show(error.message, 'bad', 0);
      return;
    }
    const roles = (this.userOptions.roles || []).map(role =>
      `<option value="${Number(role.id)}">${HIPDC_Utils.esc(role.name)}</option>`
    ).join('');
    const gates = (this.userOptions.gates || []).map(gate =>
      `<option value="${Number(gate.id)}">${HIPDC_Utils.esc(gate.name)}</option>`
    ).join('');
    HIPDC_Modal.open(`<div class="modal"><div class="mh"><h3>Create user account</h3><button class="modal-close-btn" data-close-modal>&times;</button></div>
      <form onsubmit="HIPDC_Admin.createUser(event)">
        <div class="field"><label>Full name</label><input class="inp" name="full_name" required maxlength="120"></div>
        <div class="field"><label>Username</label><input class="inp" name="username" required maxlength="64" autocomplete="username"></div>
        <div class="field"><label>Email</label><input class="inp" type="email" name="email" required maxlength="254"></div>
        <div class="field"><label>Temporary password</label><input class="inp" type="password" name="password" required minlength="12" maxlength="72" autocomplete="new-password"></div>
        <div class="field"><label>Role</label><select class="inp" name="role_id" required><option value="">Select role</option>${roles}</select></div>
        <div class="field"><label>Gate assignment (officers)</label><select class="inp" name="gate_id"><option value="">No gate assignment</option>${gates}</select></div>
        <div class="field"><label>Direction</label><select class="inp" name="direction"><option value="entry">entry</option><option value="exit">exit</option><option value="both">both</option></select></div>
        <p class="muted">Officers need a gate assignment before they can sign in to Entry or Exit dashboards. Password must be at least 12 characters.</p>
        <div class="acts"><button class="btn soft" type="button" data-close-modal>Cancel</button><button class="btn" type="submit">Create account</button></div>
      </form></div>`);
  },

  async createUser(event) {
    event.preventDefault();
    const formEl = event.currentTarget;
    const submit = formEl.querySelector('[type="submit"]');
    if (submit?.disabled) return;
    if (submit) submit.disabled = true;
    const form = new FormData(formEl);
    const data = {
      name: form.get('full_name'),
      username: form.get('username'),
      email: form.get('email'),
      password: form.get('password'),
      role_id: Number(form.get('role_id'))
    };
    try {
      const created = await HIPDC_API.post('users/index.php', data);
      const gateId = form.get('gate_id');
      if (gateId && created.user?.role?.code === 'officer') {
        try {
          await HIPDC_API.post('users/gates.php', {
            assignments: [{ gate_id: Number(gateId), direction: form.get('direction') || 'both' }]
          }, { id: created.user.id });
        } catch (error) {
          HIPDC_Modal.close();
          await this.refreshUsers();
          HIPDC_Notify.show(
            'Account created, but gate assignment failed. Assign a gate before the officer can sign in. ' + error.message,
            'bad',
            0
          );
          return;
        }
      }
      HIPDC_Modal.close();
      HIPDC_Notify.show('User account created.', 'ok');
      await this.refreshUsers();
    } catch (error) {
      if (submit) submit.disabled = false;
      HIPDC_Notify.show(error.message, 'bad', 0);
    }
  },

  async editUserRole(id) {
    const user = (HIPDC_DB.data.users || []).find(item => Number(item.id) === Number(id));
    if (!user) return;
    try {
      if (!this.userOptionsLoaded) await this.loadUserOptions();
    } catch (error) {
      HIPDC_Notify.show(error.message, 'bad', 0);
      return;
    }
    const roles = (this.userOptions.roles || []).map(role =>
      `<option value="${Number(role.id)}" ${Number(role.id) === Number(user.roleId) ? 'selected' : ''}>${HIPDC_Utils.esc(role.name)}</option>`
    ).join('');
    if (!roles) {
      HIPDC_Notify.show('No roles are available to assign.', 'bad', 0);
      return;
    }
    HIPDC_Modal.open(`<div class="modal"><div class="mh"><h3>Change user role</h3><button class="modal-close-btn" data-close-modal>&times;</button></div>
      <form onsubmit="HIPDC_Admin.saveUserRole(event, ${Number(id)})">
        <div class="field"><label>Role</label><select class="inp" name="role_id" required>${roles}</select></div>
        <div class="acts"><button class="btn soft" type="button" data-close-modal>Cancel</button><button class="btn" type="submit">Save role</button></div>
      </form></div>`);
  },

  async saveUserRole(event, id) {
    event.preventDefault();
    const roleId = Number(new FormData(event.currentTarget).get('role_id'));
    try {
      await HIPDC_API.post('users/role.php', { role_id: roleId }, { id });
      HIPDC_Modal.close();
      HIPDC_Notify.show('User role updated.', 'ok');
      await this.refreshUsers();
    } catch (error) {
      HIPDC_Notify.show(error.message, 'bad', 0);
    }
  },

  resetUserPassword(id) {
    HIPDC_Modal.open(`<div class="modal"><div class="mh"><h3>Set temporary password</h3><button class="modal-close-btn" data-close-modal>&times;</button></div>
      <form onsubmit="HIPDC_Admin.saveUserPassword(event, ${Number(id)})">
        <div class="field"><label>New password</label><input class="inp" type="password" name="password" minlength="12" maxlength="72" autocomplete="new-password" required></div>
        <p class="muted">Password must be 12-72 characters. Existing sessions for this account are revoked.</p>
        <div class="acts"><button class="btn soft" type="button" data-close-modal>Cancel</button><button class="btn" type="submit">Set password</button></div>
      </form></div>`);
  },

  async saveUserPassword(event, id) {
    event.preventDefault();
    const password = new FormData(event.currentTarget).get('password');
    try {
      await HIPDC_API.post('users/password.php', { password }, { id });
      HIPDC_Modal.close();
      HIPDC_Notify.show('Password changed; previous sessions were revoked.', 'ok');
    } catch (error) {
      HIPDC_Notify.show(error.message, 'bad', 0);
    }
  },

  async setUserStatus(id, status) {
    try {
      await HIPDC_API.post('users/status.php', { status }, { id });
      HIPDC_Notify.show(status === 'active' ? 'User activated.' : 'User deactivated.', 'ok');
      await this.refreshUsers();
    } catch (error) {
      HIPDC_Notify.show(error.message, 'bad', 0);
    }
  },

  async editUserGates(id) {
    try {
      if (!this.userOptionsLoaded) await this.loadUserOptions();
      const current = await HIPDC_API.get('users/gates.php', { id });
      const selected = new Map((current.gate_assignments || []).map(item => [Number(item.gate_id), item.direction]));
      const options = (this.userOptions.gates || []).map(gate => `
        <div class="field"><label>${HIPDC_Utils.esc(gate.name)}</label>
          <select class="inp" name="gate_${Number(gate.id)}">
            <option value="">Unassigned</option>
            <option value="entry" ${selected.get(Number(gate.id)) === 'entry' ? 'selected' : ''}>Entry</option>
            <option value="exit" ${selected.get(Number(gate.id)) === 'exit' ? 'selected' : ''}>Exit</option>
            <option value="both" ${selected.get(Number(gate.id)) === 'both' ? 'selected' : ''}>Both</option>
          </select></div>`).join('') || '<p class="muted">No active gates available. Create a gate in Operations first.</p>';
      HIPDC_Modal.open(`<div class="modal"><div class="mh"><h3>Gate assignments</h3><button class="modal-close-btn" data-close-modal>&times;</button></div>
        <form onsubmit="HIPDC_Admin.saveUserGates(event, ${Number(id)})">${options}
        <div class="acts"><button class="btn soft" type="button" data-close-modal>Cancel</button><button class="btn" type="submit">Save assignments</button></div></form></div>`);
    } catch (error) {
      HIPDC_Notify.show(error.message, 'bad', 0);
    }
  },

  async saveUserGates(event, id) {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    const assignments = [];
    for (const [key, direction] of form.entries()) {
      if (key.startsWith('gate_') && direction) {
        assignments.push({ gate_id: Number(key.slice(5)), direction });
      }
    }
    try {
      await HIPDC_API.post('users/gates.php', { assignments }, { id });
      HIPDC_Modal.close();
      HIPDC_Notify.show('Gate assignments saved.', 'ok');
      await this.refreshUsers();
    } catch (error) {
      HIPDC_Notify.show(error.message, 'bad', 0);
    }
  },

  bindEvents() {
    HIPDC_LogFilters?.bind(() =>
      document.getElementById('logistics_gate_select')?.value || 'entry');
    HIPDC_ScheduleFilters?.bind(() => this.renderSchedulesTable());
    HIPDC_VehicleFilters?.bind(() => this.renderVehiclesTable());
    document.getElementById('approval_gate_filter')?.addEventListener('change', () => {
      this.selectedApprovalId = null;
      this.renderApprovalsPage();
    });
    document.getElementById('create_user_btn')?.addEventListener('click', () => {
      this.openCreateUserModal();
    });
    ['user_role_filter', 'user_gate_filter', 'user_status_filter'].forEach(id => {
      document.getElementById(id)?.addEventListener('change', () => this.renderUsersTable());
    });
    ['visitor_date_filter', 'visitor_pass_filter'].forEach(id => {
      document.getElementById(id)?.addEventListener('change', () => this.renderVisitorsTable());
    });

    document.querySelectorAll('[data-approval-tab]').forEach(btn => {
      btn.addEventListener('click', (e) => {
        e.preventDefault();
        document.querySelectorAll('[data-approval-tab]').forEach(b => b.classList.remove('on'));
        btn.classList.add('on');
        this.activeTab = btn.getAttribute('data-approval-tab');
        this.selectedApprovalId = null;
        this.renderApprovalsPage();
      });
    });

    ['approval_search', 'schedule_search', 'visitor_search', 'vehicle_search', 'logistics_search', 'user_search'].forEach(id => {
      const el = document.getElementById(id);
      if (el) {
        el.addEventListener('input', () => this.renderPageData());
      }
    });

    const gateSelect = document.getElementById('logistics_gate_select');
    if (gateSelect) {
      gateSelect.addEventListener('change', () => {
        this.renderLogisticsTable();
        HIPDC_LogFilters.load(gateSelect.value);
      });
    }
  }
};

document.addEventListener('DOMContentLoaded', () => {
  HIPDC_Admin.init();
});
