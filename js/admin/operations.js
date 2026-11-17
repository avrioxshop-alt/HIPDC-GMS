const HIPDC_Operations = {
  companies: [],
  drivers: [],
  gates: [],
  lanes: [],
  users: [],
  options: {},
  reportPage: 1,
  auditPage: 1,

  init() {
    window.addEventListener('hipdc-authenticated', () => this.load());
    document.getElementById('company_form')?.addEventListener('submit', event => this.createCompany(event));
    document.getElementById('driver_form')?.addEventListener('submit', event => this.createDriver(event));
    document.getElementById('gate_form')?.addEventListener('submit', event => this.createGate(event));
    document.getElementById('lane_form')?.addEventListener('submit', event => this.createLane(event));
    document.getElementById('shift_form')?.addEventListener('submit', event => this.createShift(event));
    document.getElementById('user_form')?.addEventListener('submit', event => this.createUser(event));
    document.getElementById('report_form')?.addEventListener('submit', event => this.runReport(event));
    document.getElementById('report_csv')?.addEventListener('click', () => this.exportCsv());
    document.getElementById('audit_form')?.addEventListener('submit', event => this.loadAudit(event));
    document.getElementById('report_prev')?.addEventListener('click', () => {
      if (this.reportPage > 1) {
        this.reportPage -= 1;
        this.runReport();
      }
    });
    document.getElementById('report_next')?.addEventListener('click', () => {
      this.reportPage += 1;
      this.runReport();
    });
    document.getElementById('audit_prev')?.addEventListener('click', () => {
      if (this.auditPage > 1) {
        this.auditPage -= 1;
        this.loadAudit();
      }
    });
    document.getElementById('audit_next')?.addEventListener('click', () => {
      this.auditPage += 1;
      this.loadAudit();
    });
    document.getElementById('notification_read_all')?.addEventListener('click', () => this.readAll());
    ['lane_gate_id', 'shift_gate_id'].forEach(id =>
      document.getElementById(id)?.addEventListener('change', () => this.populateLanes(id)));
  },

  async load() {
    try {
      const results = await Promise.allSettled([
        HIPDC_API.allPages('companies/index.php', 'companies'),
        HIPDC_API.allPages('drivers/index.php', 'drivers'),
        HIPDC_API.allPages('gates/index.php', 'gates'),
        HIPDC_API.allPages('lanes/index.php', 'lanes'),
        HIPDC_API.allPages('users/index.php', 'users'),
        HIPDC_API.allPages('shifts/index.php', 'shifts'),
        HIPDC_API.allPages('notifications/index.php', 'notifications')
      ]);
      const assign = (index, key) => {
        if (results[index].status === 'fulfilled') this[key] = results[index].value;
        else HIPDC_Notify.show(key + ': ' + results[index].reason.message, 'bad', 0);
      };
      assign(0, 'companies');
      assign(1, 'drivers');
      assign(2, 'gates');
      assign(3, 'lanes');
      assign(4, 'users');
      if (results[5].status === 'fulfilled') this.renderShifts(results[5].value);
      else HIPDC_Notify.show('Shifts: ' + results[5].reason.message, 'bad', 0);
      if (results[6].status === 'fulfilled') this.renderNotifications(results[6].value);
      else HIPDC_Notify.show('Notifications: ' + results[6].reason.message, 'bad', 0);

      const options = await HIPDC_API.get('users/options.php');
      this.options = options;
      this.renderRegistries();
      this.populateOptions();
      this.renderUsers();
      await this.loadAudit();
    } catch (error) {
      HIPDC_Notify.show(error.message, 'bad', 0);
    }
  },

  renderRegistries() {
    this.renderRows('company_rows', this.companies, row =>
      `<tr><td>${HIPDC_Utils.esc(row.name)}</td><td>${HIPDC_Utils.esc(row.registration_number || '—')}</td><td>${HIPDC_Utils.esc(row.status)}</td>
        <td><button class="btn sm soft" onclick="HIPDC_Operations.editRegistry('company', ${Number(row.id)})">Edit</button>
          <button class="btn sm soft" onclick="HIPDC_Operations.setRegistryStatus('companies', ${Number(row.id)}, '${row.status === 'active' ? 'inactive' : 'active'}')">${row.status === 'active' ? 'Deactivate' : 'Activate'}</button></td></tr>`);
    this.renderRows('driver_rows', this.drivers, row =>
      `<tr><td>${HIPDC_Utils.esc(row.full_name)}</td><td>${HIPDC_Utils.esc(row.company_name || '—')}</td><td>${HIPDC_Utils.esc(row.license_number || '—')}</td><td>${HIPDC_Utils.esc(row.status)}</td>
        <td><button class="btn sm soft" onclick="HIPDC_Operations.editRegistry('driver', ${Number(row.id)})">Edit</button>
          <button class="btn sm soft" onclick="HIPDC_Operations.setRegistryStatus('drivers', ${Number(row.id)}, '${row.status === 'active' ? 'inactive' : 'active'}')">${row.status === 'active' ? 'Deactivate' : 'Activate'}</button></td></tr>`);
    this.renderRows('gate_rows', this.gates, row =>
      `<tr><td>${HIPDC_Utils.esc(row.name)}</td><td>${HIPDC_Utils.esc(row.code)}</td><td>${row.supports_entry ? 'Entry' : ''}${row.supports_entry && row.supports_exit ? ' / ' : ''}${row.supports_exit ? 'Exit' : ''}</td><td>${HIPDC_Utils.esc(row.status)}</td>
        <td><button class="btn sm soft" onclick="HIPDC_Operations.editRegistry('gate', ${Number(row.id)})">Edit</button>
          <button class="btn sm soft" onclick="HIPDC_Operations.setRegistryStatus('gates', ${Number(row.id)}, '${row.status === 'active' ? 'inactive' : 'active'}')">${row.status === 'active' ? 'Deactivate' : 'Activate'}</button></td></tr>`);
    this.renderRows('lane_rows', this.lanes, row =>
      `<tr><td>${HIPDC_Utils.esc(row.name)}</td><td>${HIPDC_Utils.esc(row.gate_name)}</td><td>${HIPDC_Utils.esc(row.direction)}</td><td>${HIPDC_Utils.esc(row.status)}</td>
        <td><button class="btn sm soft" onclick="HIPDC_Operations.editRegistry('lane', ${Number(row.id)})">Edit</button>
          <button class="btn sm soft" onclick="HIPDC_Operations.setRegistryStatus('lanes', ${Number(row.id)}, '${row.status === 'active' ? 'inactive' : 'active'}')">${row.status === 'active' ? 'Deactivate' : 'Activate'}</button></td></tr>`);
  },

  editRegistry(kind, id) {
    const records = { company: this.companies, driver: this.drivers, gate: this.gates, lane: this.lanes };
    const row = records[kind]?.find(item => Number(item.id) === Number(id));
    if (!row) return;
    const companies = this.companies.map(company =>
      `<option value="${Number(company.id)}" ${Number(company.id) === Number(row.company_id) ? 'selected' : ''}>${HIPDC_Utils.esc(company.name)}</option>`
    ).join('');
    const activeGates = this.gates.filter(gate => gate.status === 'active');
    let fields = '';
    if (kind === 'company') {
      fields = `<div class="field"><label>Name</label><input class="inp" name="name" required maxlength="160" value="${HIPDC_Utils.esc(row.name)}"></div>
        <div class="field"><label>Registration number</label><input class="inp" name="registration_number" maxlength="80" value="${HIPDC_Utils.esc(row.registration_number || '')}"></div>
        <div class="field"><label>Contact person</label><input class="inp" name="contact_person" maxlength="120" value="${HIPDC_Utils.esc(row.contact_person || '')}"></div>
        <div class="field"><label>Phone</label><input class="inp" name="phone" value="${HIPDC_Utils.esc(row.phone || '')}"></div>
        <div class="field"><label>Email</label><input class="inp" type="email" name="email" value="${HIPDC_Utils.esc(row.email || '')}"></div>
        <div class="field"><label>Address</label><input class="inp" name="address" maxlength="255" value="${HIPDC_Utils.esc(row.address || '')}"></div>`;
    } else if (kind === 'driver') {
      fields = `<div class="field"><label>Full name</label><input class="inp" name="full_name" required maxlength="120" value="${HIPDC_Utils.esc(row.full_name)}"></div>
        <div class="field"><label>Company</label><select class="inp" name="company_id"><option value="">No company</option>${companies}</select></div>
        <div class="field"><label>Identification number</label><input class="inp" name="identification_number" value="${HIPDC_Utils.esc(row.identification_number || '')}"></div>
        <div class="field"><label>License number</label><input class="inp" name="license_number" value="${HIPDC_Utils.esc(row.license_number || '')}"></div>
        <div class="field"><label>Phone</label><input class="inp" name="phone" value="${HIPDC_Utils.esc(row.phone || '')}"></div>`;
    } else if (kind === 'gate') {
      fields = `<div class="field"><label>Gate code</label><input class="inp" name="code" required maxlength="64" value="${HIPDC_Utils.esc(row.code)}"></div>
        <div class="field"><label>Name</label><input class="inp" name="name" required maxlength="120" value="${HIPDC_Utils.esc(row.name)}"></div>
        <div class="field"><label>Location</label><input class="inp" name="location" maxlength="160" value="${HIPDC_Utils.esc(row.location || '')}"></div>
        <div class="field"><label>Description</label><input class="inp" name="description" maxlength="255" value="${HIPDC_Utils.esc(row.description || '')}"></div>
        <label><input type="checkbox" name="supports_entry" ${row.supports_entry ? 'checked' : ''}> Entry</label>
        <label><input type="checkbox" name="supports_exit" ${row.supports_exit ? 'checked' : ''}> Exit</label>`;
    } else {
      const gates = activeGates.map(gate =>
        `<option value="${Number(gate.id)}" ${Number(gate.id) === Number(row.gate_id) ? 'selected' : ''}>${HIPDC_Utils.esc(gate.name)}</option>`
      ).join('');
      fields = `<div class="field"><label>Gate</label><select class="inp" name="gate_id" required>${gates}</select></div>
        <div class="field"><label>Lane code</label><input class="inp" name="code" required maxlength="64" value="${HIPDC_Utils.esc(row.code)}"></div>
        <div class="field"><label>Lane name</label><input class="inp" name="name" required maxlength="120" value="${HIPDC_Utils.esc(row.name)}"></div>
        <div class="field"><label>Direction</label><select class="inp" name="direction">
          <option value="entry" ${row.direction === 'entry' ? 'selected' : ''}>Entry</option>
          <option value="exit" ${row.direction === 'exit' ? 'selected' : ''}>Exit</option>
          <option value="both" ${row.direction === 'both' ? 'selected' : ''}>Both</option>
        </select></div>`;
    }
    HIPDC_Modal.open(`<div class="modal"><div class="mh"><h3>Edit ${kind}</h3><button class="modal-close-btn" data-close-modal>&times;</button></div>
      <form onsubmit="HIPDC_Operations.saveRegistryEdit(event, '${kind}', ${Number(id)})">${fields}
        <div class="acts"><button class="btn soft" type="button" data-close-modal>Cancel</button><button class="btn">Save changes</button></div>
      </form></div>`);
  },

  async saveRegistryEdit(event, kind, id) {
    event.preventDefault();
    const form = event.currentTarget;
    const data = Object.fromEntries(new FormData(form));
    if (kind === 'gate') {
      data.supports_entry = form.elements.supports_entry.checked;
      data.supports_exit = form.elements.supports_exit.checked;
    }
    if (kind === 'driver' || kind === 'lane') {
      data.company_id = kind === 'driver' ? (data.company_id ? Number(data.company_id) : null) : undefined;
      if (kind === 'lane') data.gate_id = Number(data.gate_id);
    }
    const endpoint = { company: 'companies', driver: 'drivers', gate: 'gates', lane: 'lanes' }[kind];
    try {
      await HIPDC_API.post(endpoint + '/update.php', data, { id });
      HIPDC_Modal.close();
      HIPDC_Notify.show(kind + ' updated.', 'ok');
      await this.load();
    } catch (error) {
      HIPDC_Notify.show(error.message, 'bad', 0);
    }
  },

  async setRegistryStatus(endpoint, id, status) {
    try {
      await HIPDC_API.post(endpoint + '/status.php', { status }, { id });
      HIPDC_Notify.show('Status updated.', 'ok');
      await this.load();
    } catch (error) {
      HIPDC_Notify.show(error.message, 'bad', 0);
    }
  },

  renderUsers() {
    this.renderRows('user_rows', this.users, user => `
      <tr><td>${HIPDC_Utils.esc(user.name)}<br><small>${HIPDC_Utils.esc(user.username)}</small></td>
        <td>${HIPDC_Utils.esc(user.role.name)}</td><td>${HIPDC_Utils.esc(user.status)}</td>
        <td>${(user.gate_assignments || []).map(item => HIPDC_Utils.esc(item.gate_name) + ' (' + HIPDC_Utils.esc(item.direction) + ')').join('<br>') || 'Unassigned'}</td>
        <td><button class="btn sm soft" onclick="HIPDC_Operations.editUserRole(${Number(user.id)})">Role</button>
          <button class="btn sm soft" onclick="HIPDC_Operations.editUserGates(${Number(user.id)})">Gates</button>
          <button class="btn sm soft" onclick="HIPDC_Operations.resetUserPassword(${Number(user.id)})">Password</button>
          <button class="btn sm ${user.status === 'active' ? 'bad' : ''}" onclick="HIPDC_Operations.setUserStatus(${Number(user.id)}, '${user.status === 'active' ? 'inactive' : 'active'}')">${user.status === 'active' ? 'Deactivate' : 'Activate'}</button></td></tr>`);
  },

  editUserRole(id) {
    const user = this.users.find(item => Number(item.id) === Number(id));
    if (!user) return;
    const roles = (this.options.roles || []).map(role =>
      `<option value="${Number(role.id)}" ${Number(role.id) === Number(user.role_id) ? 'selected' : ''}>${HIPDC_Utils.esc(role.name)}</option>`
    ).join('');
    HIPDC_Modal.open(`<div class="modal"><div class="mh"><h3>Change user role</h3><button class="modal-close-btn" data-close-modal>&times;</button></div>
      <form onsubmit="HIPDC_Operations.saveUserRole(event, ${Number(id)})">
        <div class="field"><label>Role</label><select class="inp" name="role_id" required>${roles}</select></div>
        <p class="muted">Role changes use the server's existing role and permission assignments.</p>
        <div class="acts"><button class="btn soft" type="button" data-close-modal>Cancel</button><button class="btn">Save role</button></div>
      </form></div>`);
  },

  async saveUserRole(event, id) {
    event.preventDefault();
    const roleId = Number(new FormData(event.currentTarget).get('role_id'));
    try {
      await HIPDC_API.post('users/role.php', { role_id: roleId }, { id });
      HIPDC_Modal.close();
      HIPDC_Notify.show('User role updated.', 'ok');
      await this.load();
    } catch (error) {
      HIPDC_Notify.show(error.message, 'bad', 0);
    }
  },

  resetUserPassword(id) {
    HIPDC_Modal.open(`<div class="modal"><div class="mh"><h3>Set temporary password</h3><button class="modal-close-btn" data-close-modal>&times;</button></div>
      <form onsubmit="HIPDC_Operations.saveUserPassword(event, ${Number(id)})">
        <div class="field"><label>New password</label><input class="inp" type="password" name="password" minlength="12" maxlength="72" autocomplete="new-password" required></div>
        <p class="muted">Use a unique password. The API revokes existing sessions for this account.</p>
        <div class="acts"><button class="btn soft" type="button" data-close-modal>Cancel</button><button class="btn">Set password</button></div>
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
      await this.load();
    } catch (error) {
      HIPDC_Notify.show(error.message, 'bad', 0);
    }
  },

  async editUserGates(id) {
    try {
      const current = await HIPDC_API.get('users/gates.php', { id });
      const selected = new Map((current.gate_assignments || []).map(item => [Number(item.gate_id), item.direction]));
      const options = (this.options.gates || []).map(gate => `
        <div class="field"><label>${HIPDC_Utils.esc(gate.name)}</label>
          <select class="inp" name="gate_${Number(gate.id)}">
            <option value="">Unassigned</option>
            <option value="entry" ${selected.get(Number(gate.id)) === 'entry' ? 'selected' : ''}>Entry</option>
            <option value="exit" ${selected.get(Number(gate.id)) === 'exit' ? 'selected' : ''}>Exit</option>
            <option value="both" ${selected.get(Number(gate.id)) === 'both' ? 'selected' : ''}>Both</option>
          </select></div>`).join('');
      HIPDC_Modal.open(`<div class="modal"><div class="mh"><h3>Gate assignments</h3><button class="modal-close-btn" data-close-modal>&times;</button></div>
        <form onsubmit="HIPDC_Operations.saveUserGates(event, ${Number(id)})">${options}
        <div class="acts"><button class="btn soft" type="button" data-close-modal>Cancel</button><button class="btn">Save assignments</button></div></form></div>`);
    } catch (error) {
      HIPDC_Notify.show(error.message, 'bad', 0);
    }
  },

  async saveUserGates(event, id) {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    const assignments = [];
    for (const [key, direction] of form.entries()) {
      if (direction) assignments.push({ gate_id: Number(key.slice(5)), direction });
    }
    try {
      await HIPDC_API.post('users/gates.php', { assignments }, { id });
      HIPDC_Modal.close();
      HIPDC_Notify.show('Gate assignments saved.', 'ok');
      await this.load();
    } catch (error) {
      HIPDC_Notify.show(error.message, 'bad', 0);
    }
  },

  populateOptions() {
    this.setOptions('driver_company_id', this.companies.filter(row => row.status === 'active'),
      row => row.id, row => row.name, 'No company');
    this.setOptions('lane_gate_id', this.gates.filter(row => row.status === 'active'),
      row => row.id, row => row.name, 'Select gate');
    this.setOptions('shift_gate_id', this.gates.filter(row => row.status === 'active'),
      row => row.id, row => row.name, 'Select gate');
    this.setOptions('user_gate_id', this.options.gates || [],
      row => row.id, row => row.name, 'No gate assignment');
    this.setOptions('user_role_id', this.options.roles || [],
      row => row.id, row => row.name, 'Select role');
    this.setOptions('report_company_id', this.companies,
      row => row.id, row => row.name, 'All companies');
    this.setOptions('report_gate_id', this.gates,
      row => row.id, row => row.name, 'All gates');
    this.setOptions('shift_officer_id', this.users.filter(user =>
      user.role.code === 'officer' && user.status === 'active'),
    row => row.id, row => row.name, 'Select Officer');
    this.populateLanes('shift_gate_id');
    this.populateLanes('lane_gate_id');
  },

  setOptions(id, rows, getValue, getLabel, emptyLabel) {
    const select = document.getElementById(id);
    if (!select) return;
    const current = select.value;
    select.innerHTML = `<option value="">${emptyLabel}</option>` + rows.map(row =>
      `<option value="${Number(getValue(row))}">${HIPDC_Utils.esc(getLabel(row))}</option>`
    ).join('');
    if (current) select.value = current;
  },

  populateLanes(gateSelectId) {
    const targetId = gateSelectId === 'shift_gate_id' ? 'shift_lane_id' : 'lane_form_lane_id';
    const target = document.getElementById(targetId);
    const gateId = document.getElementById(gateSelectId)?.value;
    if (!target) return;
    const lanes = gateId
      ? this.lanes.filter(lane => Number(lane.gate_id) === Number(gateId) && lane.status === 'active')
      : [];
    target.innerHTML = '<option value="">No specific lane</option>' + lanes.map(lane =>
      `<option value="${Number(lane.id)}">${HIPDC_Utils.esc(lane.name)}</option>`
    ).join('');
  },

  async createCompany(event) {
    event.preventDefault();
    const form = Object.fromEntries(new FormData(event.currentTarget));
    await this.submitForm('companies/index.php', form, 'Company registered.');
  },

  async createDriver(event) {
    event.preventDefault();
    const form = Object.fromEntries(new FormData(event.currentTarget));
    form.company_id = form.company_id ? Number(form.company_id) : null;
    await this.submitForm('drivers/index.php', form, 'Driver registered.');
  },

  async createGate(event) {
    event.preventDefault();
    const data = Object.fromEntries(new FormData(event.currentTarget));
    data.supports_entry = event.currentTarget.elements.supports_entry.checked;
    data.supports_exit = event.currentTarget.elements.supports_exit.checked;
    await this.submitForm('gates/index.php', data, 'Gate created.');
  },

  async createLane(event) {
    event.preventDefault();
    const data = Object.fromEntries(new FormData(event.currentTarget));
    data.gate_id = Number(data.gate_id);
    await this.submitForm('lanes/index.php', data, 'Lane created.');
  },

  async createShift(event) {
    event.preventDefault();
    const data = Object.fromEntries(new FormData(event.currentTarget));
    data.officer_id = Number(data.officer_id);
    data.gate_id = Number(data.gate_id);
    data.lane_id = data.lane_id ? Number(data.lane_id) : null;
    data.starts_at = data.starts_at.replace('T', ' ');
    data.ends_at = data.ends_at.replace('T', ' ');
    await this.submitForm('shifts/index.php', data, 'Officer shift assigned.');
  },

  async createUser(event) {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    const data = {
      name: form.get('full_name'),
      username: form.get('username'),
      email: form.get('email') || null,
      password: form.get('password'),
      role_id: Number(form.get('role_id'))
    };
    try {
      const created = await HIPDC_API.post('users/index.php', data);
      const gateId = form.get('gate_id');
      if (gateId && created.user.role.code === 'officer') {
        try {
          await HIPDC_API.post('users/gates.php', {
            assignments: [{ gate_id: Number(gateId), direction: form.get('direction') }]
          }, { id: created.user.id });
        } catch (error) {
          event.currentTarget.reset();
          await this.load();
          HIPDC_Notify.show(
            'The account was created, but the gate assignment failed. Assign a gate before operational use. ' + error.message,
            'bad', 0
          );
          return;
        }
      }
      event.currentTarget.reset();
      HIPDC_Notify.show('User account created. Set a unique password for the user.', 'ok');
      await this.load();
    } catch (error) {
      HIPDC_Notify.show(error.message, 'bad', 0);
    }
  },

  async submitForm(endpoint, body, message) {
    try {
      await HIPDC_API.post(endpoint, body);
      HIPDC_Notify.show(message, 'ok');
      const form = document.getElementById(endpoint.startsWith('companies') ? 'company_form'
        : endpoint.startsWith('drivers') ? 'driver_form'
        : endpoint.startsWith('gates') ? 'gate_form'
        : endpoint.startsWith('lanes') ? 'lane_form' : 'shift_form');
      form?.reset();
      await this.load();
    } catch (error) {
      HIPDC_Notify.show(error.message, 'bad', 0);
    }
  },

  async runReport(event) {
    if (event) {
      event.preventDefault();
      this.reportPage = 1;
    }
    const form = new FormData(document.getElementById('report_form'));
    const params = Object.fromEntries(form);
    Object.keys(params).forEach(key => { if (!params[key]) delete params[key]; });
    try {
      const data = await HIPDC_API.get('reports/index.php', {
        ...params, page: this.reportPage, per_page: 50
      });
      const rows = data.rows || [];
      const headers = rows.length ? Object.keys(rows[0]) : [];
      document.getElementById('report_head').innerHTML = `<tr>${headers.map(key => `<th>${HIPDC_Utils.esc(key)}</th>`).join('')}</tr>`;
      this.renderRows('report_rows', rows, row =>
        `<tr>${headers.map(key => `<td>${HIPDC_Utils.esc(row[key] ?? '')}</td>`).join('')}</tr>`);
      this.updatePagination('report', data.pagination);
    } catch (error) {
      HIPDC_Notify.show(error.message, 'bad', 0);
    }
  },

  async exportCsv() {
    const form = new FormData(document.getElementById('report_form'));
    const params = Object.fromEntries(form);
    Object.keys(params).forEach(key => { if (!params[key]) delete params[key]; });
    params.format = 'csv';
    try {
      const blob = await HIPDC_API.csv('reports/index.php', params);
      const url = URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = url;
      link.download = 'hipdc-' + (params.report || 'report') + '.csv';
      link.click();
      window.setTimeout(() => URL.revokeObjectURL(url), 1000);
    } catch (error) {
      HIPDC_Notify.show(error.message, 'bad', 0);
    }
  },

  async loadAudit(event) {
    if (event) {
      event.preventDefault();
      this.auditPage = 1;
    }
    const form = document.getElementById('audit_form');
    const params = Object.fromEntries(new FormData(form));
    Object.keys(params).forEach(key => { if (!params[key]) delete params[key]; });
    try {
      const data = await HIPDC_API.get('audit/index.php', {
        ...params, page: this.auditPage, per_page: 50
      });
      const events = data.events || [];
      this.renderRows('audit_rows', events, row =>
        `<tr><td>${HIPDC_Utils.esc(row.created_at)}</td><td>${HIPDC_Utils.esc(row.actor_name || row.actor_username || '—')}</td><td>${HIPDC_Utils.esc(row.action)}</td><td>${HIPDC_Utils.esc(row.entity_type)}</td><td>${HIPDC_Utils.esc(row.entity_id ?? '—')}</td></tr>`);
      this.updatePagination('audit', data.pagination);
    } catch (error) {
      HIPDC_Notify.show(error.message, 'bad', 0);
    }
  },

  async renderNotifications(notifications) {
    const target = document.getElementById('notification_rows');
    if (!target) return;
    if (!notifications.length) {
      target.innerHTML = '<p class="muted">No notifications.</p>';
      return;
    }
    target.innerHTML = notifications.map(item => `
      <div class="req"><div><b>${HIPDC_Utils.esc(item.title)}</b>
        <p>${HIPDC_Utils.esc(item.message)}</p><small>${HIPDC_Utils.esc(item.created_at)}</small></div>
        ${item.read_at ? '<span class="chip">Read</span>' : `<button class="btn sm" onclick="HIPDC_Operations.readNotification(${Number(item.id)})">Mark read</button>`}
      </div>`).join('');
  },

  async readNotification(id) {
    try {
      await HIPDC_API.post('notifications/read.php', { id });
      await this.load();
    } catch (error) {
      HIPDC_Notify.show(error.message, 'bad', 0);
    }
  },

  async readAll() {
    try {
      await HIPDC_API.post('notifications/read-all.php');
      await this.load();
    } catch (error) {
      HIPDC_Notify.show(error.message, 'bad', 0);
    }
  },

  renderShifts(shifts) {
    this.renderRows('shift_rows', shifts, row =>
      `<tr><td>${HIPDC_Utils.esc(row.officer_name)}</td><td>${HIPDC_Utils.esc(row.gate_name)}</td><td>${HIPDC_Utils.esc(row.starts_at)} – ${HIPDC_Utils.esc(row.ends_at)}</td><td>${HIPDC_Utils.esc(row.status)}</td></tr>`);
  },

  renderRows(id, rows, renderRow) {
    const target = document.getElementById(id);
    if (target) target.innerHTML = rows.length ? rows.map(renderRow).join('')
      : `<tr><td colspan="8" class="muted">No records.</td></tr>`;
  },

  updatePagination(kind, pagination = {}) {
    const page = Number(pagination.page) || 1;
    const pages = Number(pagination.pages) || 1;
    const label = document.getElementById(kind + '_pagination');
    const previous = document.getElementById(kind + '_prev');
    const next = document.getElementById(kind + '_next');
    if (label) label.textContent = `Page ${page} of ${pages} · ${Number(pagination.total) || 0} records`;
    if (previous) previous.disabled = page <= 1;
    if (next) next.disabled = page >= pages;
  }
};

document.addEventListener('DOMContentLoaded', () => HIPDC_Operations.init());
