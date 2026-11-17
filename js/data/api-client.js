/**
 * Same-origin client for the HIPDC PHP session and JSON APIs.
 */
const HIPDC_API = {
  baseUrl: new URL('../../api/', document.currentScript.src).href,
  csrfToken: null,
  currentUser: null,

  async request(path, options = {}) {
    const method = (options.method || 'GET').toUpperCase();
    const headers = new Headers(options.headers || {});
    const init = {
      method,
      headers,
      credentials: 'same-origin',
      cache: 'no-store'
    };

    if (options.body !== undefined) {
      headers.set('Content-Type', 'application/json');
      init.body = JSON.stringify(options.body);
    }

    if (!['GET', 'HEAD'].includes(method) && options.csrf !== false) {
      headers.set('X-CSRF-Token', await this.getCsrfToken());
    }

    const response = await fetch(new URL(path.replace(/^\//, ''), this.baseUrl), init);
    const contentType = response.headers.get('Content-Type') || '';
    if (contentType.includes('text/csv')) {
      if (!response.ok) {
        throw new Error('CSV export failed with HTTP ' + response.status + '.');
      }
      return response.blob();
    }

    let payload;
    try {
      payload = await response.json();
    } catch (error) {
      throw new Error('The server returned an invalid response (HTTP ' + response.status + ').');
    }
    if (!response.ok || !payload.success) {
      const apiError = new Error(payload.message || 'The request failed.');
      apiError.status = response.status;
      apiError.data = payload.data || {};
      throw apiError;
    }
    return payload.data || {};
  },

  async getCsrfToken(forceRefresh = false) {
    if (!this.csrfToken || forceRefresh) {
      const data = await this.request('auth/csrf.php');
      this.csrfToken = data.token;
    }
    return this.csrfToken;
  },

  get(path, params = {}) {
    const query = new URLSearchParams();
    Object.entries(params).forEach(([key, value]) => {
      if (value !== undefined && value !== null && value !== '') {
        query.set(key, String(value));
      }
    });
    const suffix = query.toString();
    return this.request(path + (suffix ? '?' + suffix : ''));
  },

  post(path, body = {}, params = {}, headers = {}) {
    const query = new URLSearchParams(params);
    const suffix = query.toString();
    return this.request(path + (suffix ? '?' + suffix : ''), { method: 'POST', body, headers });
  },

  async login(username, password) {
    await this.getCsrfToken();
    const data = await this.post('auth/login.php', { username, password });
    this.csrfToken = null;
    return data.user;
  },

  async me() {
    const data = await this.get('auth/me.php');
    this.currentUser = data.user;
    return data.user;
  },

  async logout() {
    this.csrfToken = null;
    await this.post('auth/logout.php');
    this.currentUser = null;
  },

  list(path, key, params = {}) {
    return this.get(path, { page: 1, per_page: 100, ...params })
      .then(data => data[key] || []);
  },

  async allPages(path, key, params = {}) {
    const result = [];
    let page = 1;
    let pages = 1;
    do {
      const data = await this.get(path, { page, per_page: 100, ...params });
      result.push(...(data[key] || []));
      pages = data.pagination ? Number(data.pagination.pages) || 1 : 1;
      page += 1;
    } while (page <= pages);
    return result;
  },

  async currentGateLocations() {
    const data = await this.get('gate-operations/gates.php');
    return data.locations || [];
  },

  async csv(path, params = {}) {
    return this.get(path, { ...params, format: 'csv' });
  }
};

window.HIPDC_API = HIPDC_API;

const HIPDC_DB = {
  data: {
    vehicles: [], schedules: [], approvals: [], visitors: [], users: [], drivers: [],
    companies: [], gates: [], lanes: [], cameras: [],
    logs: { entry: [], exit: [] }, passes: [], dashboard: null
  },
  logRequestVersions: { entry: 0, exit: 0 },
  logFilters: { entry: null, exit: null },

  async refresh() {
    if (!HIPDC_API.currentUser) await HIPDC_API.me();
    const permissions = new Set(HIPDC_API.currentUser.permissions || []);
    const tasks = [];
    const add = (permission, callback) => {
      if (permissions.has(permission)) tasks.push(callback());
    };
    add('vehicles.view', async () => {
      this.data.vehicles = (await HIPDC_API.allPages('vehicles/index.php', 'vehicles'))
        .map(row => this.mapVehicle(row));
    });
    add('schedules.view', async () => {
      this.data.schedules = (await HIPDC_API.allPages('schedules/index.php', 'schedules'))
        .map(row => this.mapSchedule(row));
    });
    add('approvals.view', async () => {
      this.data.approvals = (await HIPDC_API.allPages('approvals/index.php', 'requests'))
        .map(row => this.mapApproval(row));
    });
    add('visitors.view', async () => {
      this.data.visitors = (await HIPDC_API.allPages('visitors/index.php', 'visitors'))
        .map(row => this.mapVisitor(row));
    });
    add('visitors.passes.view', async () => {
      const passes = await HIPDC_API.allPages('visitors/passes/index.php', 'passes');
      this.data.passes = passes;
    });
    add('gate_operations.view', async () => {
      const today = new Date();
      const date = [today.getFullYear(), String(today.getMonth() + 1).padStart(2, '0'), String(today.getDate()).padStart(2, '0')].join('-');
      const directions = await Promise.all(['entry', 'exit'].map(async direction => {
        const filters = this.logFilters[direction] || { from: date, to: date };
        this.logFilters[direction] = filters;
        const events = await HIPDC_API.allPages('gate-operations/events/index.php', 'events', {
          ...filters, direction
        });
        return { direction, events };
      }));
      this.data.logs = {
        entry: directions.find(item => item.direction === 'entry').events.map(event => this.mapEvent(event)),
        exit: directions.find(item => item.direction === 'exit').events.map(event => this.mapEvent(event))
      };
    });
    add('cameras.view', async () => {
      this.data.cameras = await HIPDC_API.allPages('cameras/index.php', 'cameras');
    });
    if (HIPDC_API.currentUser.role.code === 'admin') {
      add('companies.view', async () => {
        this.data.companies = await HIPDC_API.allPages('companies/index.php', 'companies');
      });
      add('drivers.view', async () => {
        this.data.drivers = await HIPDC_API.allPages('drivers/index.php', 'drivers');
      });
      add('users.view', async () => {
        this.data.users = (await HIPDC_API.allPages('users/index.php', 'users')).map(user => ({
          ...user,
          roleId: user.role?.id,
          roleCode: user.role?.code || '',
          role: user.role?.name || '—',
          statusCode: user.status,
          status: user.status === 'active' ? 'Active' : 'Inactive',
          gate: (user.gate_assignments || []).map(item => item.gate_name).join(', ') || 'Unassigned',
          shift: '—',
          lastLogin: user.last_login_at
            ? new Date(user.last_login_at.replace(' ', 'T')).toLocaleString()
            : 'Never'
        }));
      });
      add('gates.view', async () => {
        this.data.gates = await HIPDC_API.allPages('gates/index.php', 'gates');
      });
      add('lanes.view', async () => {
        this.data.lanes = await HIPDC_API.allPages('lanes/index.php', 'lanes');
      });
    }
    if (permissions.has('dashboard.view')) {
      tasks.push(HIPDC_API.get('dashboard/index.php').then(data => { this.data.dashboard = data; }));
    }
    const results = await Promise.allSettled(tasks);
    const failures = results.filter(result => result.status === 'rejected');
    if (failures.length) {
      window.HIPDC_Notify?.show('Some records could not be loaded: ' +
        failures.map(result => result.reason.message).join(' '), 'bad', 0);
    }
    window.dispatchEvent(new Event('hipdc-db-updated'));
  },

  mapVehicle(row) {
    const driver = (this.data.drivers || []).find(item => Number(item.id) === Number(row.assigned_driver_id));
    return {
      ...row, company: row.company_name || '—', plate: row.plate_number,
      driver: row.driver_name || driver?.full_name || '—',
      model: [row.make, row.model].filter(Boolean).join(' ') || row.vehicle_type,
      expiry: row.registration_expiry || '—',
      phone: driver?.phone || '—',
      status: row.status === 'active' ? 'Active' : (row.status === 'blocked' ? 'Blocked' : 'Inactive')
    };
  },

  mapSchedule(row) {
    return {
      ...row, company: row.company_name || '—', plate: row.plate_number,
      driver: row.driver_name || '—', model: [row.make, row.model].filter(Boolean).join(' ') || '—',
      win: new Date(row.scheduled_at.replace(' ', 'T')).toLocaleString(),
      cargo: row.cargo_summary || row.purpose, status: row.status === 'scheduled' ? 'Expected' : 'Cancelled'
    };
  },

  mapApproval(row) {
    const status = { pending: 'Pending', approved: 'Approved', denied: 'Denied', cancelled: 'Cancelled' };
    return {
      ...row, title: row.title, gate: row.direction === 'exit' ? 'Exit' : 'Entry',
      plate: row.plate_number || row.visitor_name || row.goods_declaration_reference || '—',
      company: row.company_name || '—',
      driver: row.driver_name || '—',
      officer: row.submitted_by_username || (row.submitted_by ? 'User #' + row.submitted_by : '—'),
      time: new Date(row.created_at.replace(' ', 'T')).toLocaleString(),
      status: status[row.status] || row.status, note: row.decision_reason || row.note || '',
      destination: '', waybill: '',
      requestType: row.request_type,
      submittedById: row.submitted_by,
      visitorPassId: row.visitor_pass_id,
      goodsDeclarationId: row.goods_declaration_id
    };
  },

  mapVisitor(row) {
    return {
      ...row, name: row.full_name, company: row.company_name || row.organization || '—',
      plate: '—', date: new Date(row.expected_arrival_at.replace(' ', 'T')).toLocaleString(),
      status: row.status === 'active' ? 'Active' : 'Inactive'
    };
  },

  mapEvent(row) {
    return {
      ...row, time: new Date(row.event_at.replace(' ', 'T')).toLocaleString(),
      plate: row.plate_number || '—', driver: row.driver_name || row.visitor_name || '—',
      company: row.company_name || '—',
      type: row.access_result === 'granted' ? 'Granted ' + row.event_type : 'Denied ' + row.event_type,
      adminApproval: row.access_result === 'granted' ? 'Authorized' : (row.denial_reason || 'Denied'),
      details: row.reference
    };
  },

  async resolveCompany(name) {
    const record = this.data.companies.find(row => row.name.toLowerCase() === String(name || '').trim().toLowerCase());
    if (!record) throw new Error('Select an existing registered company; company records are not created from this form.');
    return record;
  },

  async resolveVehicle(plate) {
    let record = this.data.vehicles.find(row => row.plate.toUpperCase() === String(plate || '').trim().toUpperCase());
    if (!record) {
      const rows = await HIPDC_API.allPages('vehicles/index.php', 'vehicles', { q: plate });
      const vehicle = rows.find(row => row.plate_number.toUpperCase() === String(plate || '').trim().toUpperCase());
      if (vehicle) record = this.mapVehicle(vehicle);
    }
    if (!record) throw new Error('No registered vehicle matches that plate number.');
    return record;
  },

  getDefaultSchema() { return this.data; },
  load() { return this.data; },
  save(data) {
    const previous = this.data.vehicles || [];
    const changed = (data.vehicles || []).find(row => {
      const old = previous.find(item => Number(item.id) === Number(row.id));
      return old && old.status !== row.status;
    });
    if (changed) this.setVehicleStatus(changed.id, changed.status === 'Blocked' ? 'blocked' : 'active');
  },
  getVehicles(query = '') {
    return this.filter(this.data.vehicles, query, ['company', 'plate', 'driver', 'model']);
  },
  async addVehicle(input) {
    const company = await this.resolveCompany(input.company);
    const driver = this.data.drivers.find(row =>
      row.full_name.toLowerCase() === String(input.driver || '').trim().toLowerCase());
    if (input.driver && !driver) throw new Error('Select a driver registered to the selected company.');
    const data = await HIPDC_API.post('vehicles/index.php', {
      company_id: company.id, assigned_driver_id: driver?.id || null,
      plate_number: input.plate, vehicle_type: input.vehicle_type || 'truck',
      make: input.make || null, model: input.model || input.model_name || null,
      registration_expiry: input.expiry || null
    });
    await this.refresh();
    return data.vehicle;
  },
  getSchedules(query = '') { return this.filter(this.data.schedules, query, ['company', 'plate', 'driver']); },
  async addSchedule(input) {
    const vehicle = await this.resolveVehicle(input.plate);
    const driver = this.data.drivers.find(row => Number(row.id) === Number(input.driver_id)
      || row.full_name.toLowerCase() === String(input.driver || '').trim().toLowerCase());
    if (!driver) throw new Error('Select a registered driver associated with the vehicle.');
    const scheduledAt = input.scheduled_at || (input.date + ' ' + (input.time || '09:00') + ':00');
    const direction = input.direction || document.documentElement.dataset.gateDirection || 'entry';
    const body = {
      company_id: vehicle.company_id || null, vehicle_id: vehicle.id, driver_id: driver.id,
      gate_id: input.gate_id || null, lane_id: input.lane_id || null,
      direction, scheduled_at: scheduledAt.replace('T', ' '),
      purpose: input.purpose || 'Visit', cargo_summary: input.cargo || null
    };
    const result = input.id
      ? await HIPDC_API.post('schedules/update.php', body, { id: input.id })
      : await HIPDC_API.post('schedules/index.php', body);
    await this.refresh();
    return result.schedule;
  },
  getApprovals(status = 'All', query = '') {
    return this.filter(this.data.approvals.filter(item =>
      status === 'All' || item.status.toLowerCase() === status.toLowerCase()), query,
    ['company', 'plate', 'driver', 'title']);
  },
  getPendingApprovalsCount() { return this.data.approvals.filter(item => item.status === 'Pending').length; },
  async submitApprovalRequest(input) {
    const vehicle = await this.resolveVehicle(input.plate);
    const direction = input.direction || (input.gate === 'Exit' ? 'exit' : 'entry');
    const location = (await HIPDC_API.currentGateLocations()).find(item =>
      item.operation_direction === direction
      && (!input.gate_id || Number(item.gate_id) === Number(input.gate_id))
      && (!input.lane_id || Number(item.lane_id) === Number(input.lane_id))
    );
    if (!location) throw new Error('No active assigned gate is available for this direction.');
    const result = await HIPDC_API.post('approvals/submit.php', {
      request_type: input.request_type || 'access', title: input.title || 'Access request',
      note: input.note || null, company_id: vehicle.company_id || null,
      driver_id: vehicle.assigned_driver_id || null, vehicle_id: vehicle.id,
      schedule_id: input.schedule_id || null, gate_id: location.gate_id,
      lane_id: input.lane_id || location.lane_id, direction, details: input.details || {}
    });
    await this.refresh();
    return this.mapApproval(result.request);
  },
  async updateApprovalStatus(id, status, reason = '') {
    const result = await HIPDC_API.post('approvals/decision.php', {
      status: status.toLowerCase() === 'approved' ? 'approved' : 'denied', reason
    }, { id });
    this.lastIssuedQrToken = result.qr_token || null;
    await this.refresh();
    return this.mapApproval(result.request);
  },
  getLogs(gate = 'entry', query = '') {
    return this.filter(this.data.logs[gate] || [], query, ['plate', 'driver', 'company']);
  },
  async loadLogs(direction, filters = {}) {
    if (!['entry', 'exit'].includes(direction)) throw new Error('Invalid event direction.');
    const version = ++this.logRequestVersions[direction];
    this.logFilters[direction] = { ...filters };
    const params = { ...filters, direction };
    const events = await HIPDC_API.allPages('gate-operations/events/index.php', 'events', params);
    if (version !== this.logRequestVersions[direction]) return;
    this.data.logs[direction] = events.map(event => this.mapEvent(event));
    window.dispatchEvent(new Event('hipdc-db-updated'));
  },
  recordGateLog() { return Promise.reject(new Error('Gate events must be recorded through the authorized gate-operations API.')); },
  getVisitors(query = '') { return this.filter(this.data.visitors, query, ['name', 'company', 'reference']); },
  async addVisitor(input) {
    const company = input.company ? await this.resolveCompany(input.company) : null;
    const arrival = input.expected_arrival_at || new Date().toISOString().slice(0, 16).replace('T', ' ');
    const data = await HIPDC_API.post('visitors/index.php', {
      full_name: input.name, document_reference: input.id_num || null,
      phone: input.phone || null, email: input.email || null, organization: input.organization || null,
      company_id: company?.id || null, host_person: input.host_person || null,
      purpose: input.purpose, expected_arrival_at: arrival,
      expected_departure_at: input.expected_departure_at || null,
      schedule_id: input.schedule_id || null
    });
    await this.refresh();
    return data.visitor;
  },
  async updateVisitor(id, input) {
    const company = input.company ? await this.resolveCompany(input.company) : null;
    const data = await HIPDC_API.post('visitors/update.php', {
      full_name: input.name, document_reference: input.id_num || null,
      phone: input.phone || null, email: input.email || null,
      organization: input.organization || null, company_id: company?.id || null,
      host_person: input.host_person || null, purpose: input.purpose,
      expected_arrival_at: input.expected_arrival_at,
      expected_departure_at: input.expected_departure_at || null,
      schedule_id: input.schedule_id || null
    }, { id });
    await this.refresh();
    return data.visitor;
  },
  async setVisitorStatus(id, status) {
    const data = await HIPDC_API.post('visitors/status.php', { status }, { id });
    await this.refresh();
    return data.visitor;
  },
  getUsers(query = '') { return this.filter(this.data.users, query, ['name', 'username']); },
  async setVehicleStatus(id, status) {
    await HIPDC_API.post('vehicles/status.php', { status }, { id });
    await this.refresh();
  },
  seedTestData() {
    window.HIPDC_Notify?.show('Sample data is disabled. Register real operational records first.', 'warn');
  },
  filter(items, query, fields) {
    const value = String(query || '').trim().toLowerCase();
    if (!value) return [...items];
    return items.filter(item => fields.some(field => String(item[field] || '').toLowerCase().includes(value)));
  }
};

window.HIPDC_DB = HIPDC_DB;

window.HIPDC_LogFilters = {
  bind(directionResolver) {
    const now = new Date();
    const today = [
      now.getFullYear(),
      String(now.getMonth() + 1).padStart(2, '0'),
      String(now.getDate()).padStart(2, '0')
    ].join('-');
    ['logistics_from', 'logistics_to'].forEach(id => {
      const input = document.getElementById(id);
      if (input && !input.value) input.value = today;
      input?.addEventListener('change', () => this.load(directionResolver()));
    });
  },

  async load(direction) {
    const from = document.getElementById('logistics_from')?.value || '';
    const to = document.getElementById('logistics_to')?.value || '';
    if (from && to && from > to) {
      HIPDC_Notify.show('The start date must not be later than the end date.', 'warn');
      return;
    }
    try {
      await HIPDC_DB.loadLogs(direction, { from, to });
    } catch (error) {
      HIPDC_Notify.show(error.message, 'bad', 0);
    }
  }
};

window.HIPDC_ScheduleFilters = {
  bind(onChange) {
    const dateInput = document.getElementById('schedule_date_filter');
    const companySelect = document.getElementById('schedule_company_filter');
    const statusSelect = document.getElementById('schedule_status_filter');
    if (dateInput && !dateInput.value) {
      const now = new Date();
      dateInput.value = [
        now.getFullYear(),
        String(now.getMonth() + 1).padStart(2, '0'),
        String(now.getDate()).padStart(2, '0')
      ].join('-');
    }
    [dateInput, companySelect, statusSelect].forEach(input =>
      input?.addEventListener('change', onChange));
  },

  populate(schedules) {
    const select = document.getElementById('schedule_company_filter');
    if (!select) return;
    const selected = select.value;
    const companies = [...new Map(schedules.map(schedule => [
      schedule.company_id || schedule.company,
      { id: schedule.company_id || schedule.company, name: schedule.company }
    ])).values()].filter(company => company.name && company.name !== '—');
    select.innerHTML = '<option value="">All companies</option>' + companies.map(company =>
      `<option value="${HIPDC_Utils.esc(company.id)}">${HIPDC_Utils.esc(company.name)}</option>`
    ).join('');
    if (selected) select.value = selected;
  },

  filter(schedules) {
    const date = document.getElementById('schedule_date_filter')?.value || '';
    const company = document.getElementById('schedule_company_filter')?.value || '';
    const status = document.getElementById('schedule_status_filter')?.value || '';
    return schedules.filter(schedule =>
      (!date || String(schedule.scheduled_at || '').slice(0, 10) === date)
      && (!company || String(schedule.company_id || schedule.company) === company)
      && (!status || schedule.status === status)
    );
  }
};

window.HIPDC_VehicleFilters = {
  bind(onChange, defaultStatus) {
    const statusSelect = document.getElementById('vehicle_status_filter');
    const companySelect = document.getElementById('vehicle_company_filter');
    if (statusSelect && defaultStatus && !statusSelect.dataset.initialized) {
      statusSelect.value = defaultStatus;
      statusSelect.dataset.initialized = 'true';
    }
    [companySelect, statusSelect].forEach(input =>
      input?.addEventListener('change', onChange));
  },

  populate(vehicles) {
    const select = document.getElementById('vehicle_company_filter');
    if (!select) return;
    const selected = select.value;
    const companies = [...new Map(vehicles.map(vehicle => [
      vehicle.company_id || vehicle.company,
      { id: vehicle.company_id || vehicle.company, name: vehicle.company }
    ])).values()].filter(company => company.name && company.name !== '—');
    select.innerHTML = '<option value="">All companies</option>' + companies.map(company =>
      `<option value="${HIPDC_Utils.esc(company.id)}">${HIPDC_Utils.esc(company.name)}</option>`
    ).join('');
    if (selected) select.value = selected;
  },

  filter(vehicles) {
    const company = document.getElementById('vehicle_company_filter')?.value || '';
    const status = document.getElementById('vehicle_status_filter')?.value || '';
    return vehicles.filter(vehicle =>
      (!company || String(vehicle.company_id || vehicle.company) === company)
      && (!status || vehicle.status === status)
    );
  }
};
