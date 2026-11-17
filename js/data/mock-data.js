/**
 * HIPDC Gate & Logistics Management - Data Layer & PHP API Connector
 * 
 * Complies with Backend Integration Preparation:
 * - Clean empty states when database is empty (no hardcoded records by default)
 * - Prepared for PHP + MySQL REST API endpoints
 * - Stores state in localStorage with cross-tab reactive synchronization
 * - Provides full Admin Approval workflow management
 */

const HIPDC_DB = {
  STORAGE_KEY: 'hipdc_database_v2',

  // PHP REST API Endpoints ready for backend connection
  API_CONFIG: {
    useRemoteBackend: false, // Set to true when PHP backend is deployed
    baseUrl: '../../api',
    endpoints: {
      login: '/auth/login.php',
      vehicles: '/vehicles/index.php',
      vehicleCreate: '/vehicles/create.php',
      schedules: '/schedules/index.php',
      scheduleCreate: '/schedules/create.php',
      approvals: '/approvals/index.php',
      approvalCreate: '/approvals/create.php',
      approvalUpdate: '/approvals/update.php',
      logs: '/logs/index.php',
      logCreate: '/logs/create.php',
      visitors: '/visitors/index.php',
      visitorCreate: '/visitors/create.php',
      users: '/users/index.php'
    }
  },

  // Base state schema - Starts with clean empty states as requested
  getDefaultSchema() {
    return {
      vehicles: [],
      schedules: [],
      logs: {
        entry: [],
        exit: []
      },
      approvals: [],
      visitors: [],
      users: [
        { name: 'Officer Abebe', username: 'abebe.t', role: 'Officer', gate: 'Entry', shift: 'Day', lastLogin: 'Today 06:02', status: 'Active' },
        { name: 'Officer Kebede', username: 'kebede.l', role: 'Officer', gate: 'Exit', shift: 'Day', lastLogin: 'Today 06:05', status: 'Active' },
        { name: 'Admin Selam', username: 'selam.m', role: 'Admin', gate: 'All', shift: 'All', lastLogin: 'Today 07:30', status: 'Active' }
      ],
      cameras: [
        { name: 'Entry gate · ANPR Lane 1', gate: 'Entry', activePlate: 'SNNP 3-A48217' },
        { name: 'Exit gate · ANPR Lane 2', gate: 'Exit', activePlate: 'SNNP 3-B20871' },
        { name: 'Camera 1 · Main Access Road', gate: 'Park' },
        { name: 'Camera 2 · Central Warehouse', gate: 'Park' },
        { name: 'Camera 3 · Heavy Truck Parking', gate: 'Park' },
        { name: 'Camera 4 · Perimeter Fence North', gate: 'Park' }
      ]
    };
  },

  load() {
    const raw = localStorage.getItem(this.STORAGE_KEY);
    if (!raw) {
      const initial = this.getDefaultSchema();
      this.save(initial);
      return initial;
    }
    try {
      return JSON.parse(raw);
    } catch (e) {
      console.error('Failed to parse local database state, resetting to empty schema.', e);
      const fallback = this.getDefaultSchema();
      this.save(fallback);
      return fallback;
    }
  },

  save(data) {
    localStorage.setItem(this.STORAGE_KEY, JSON.stringify(data));
    window.dispatchEvent(new Event('hipdc-db-updated'));
  },

  /* ---------- Registered Vehicles Methods ---------- */
  getVehicles(query = '') {
    const db = this.load();
    let list = db.vehicles || [];
    if (query) {
      const q = query.toLowerCase();
      list = list.filter(v => 
        (v.company && v.company.toLowerCase().includes(q)) ||
        (v.plate && v.plate.toLowerCase().includes(q)) ||
        (v.driver && v.driver.toLowerCase().includes(q)) ||
        (v.model && v.model.toLowerCase().includes(q))
      );
    }
    return list;
  },

  addVehicle(vehicle) {
    const db = this.load();
    const newRecord = {
      id: Date.now(),
      company: vehicle.company || 'Unassigned Tenant',
      plate: vehicle.plate || 'SNNP 3-NEW',
      driver: vehicle.driver || 'Driver Name',
      model: vehicle.model || 'Standard Truck',
      expiry: vehicle.expiry || 'Dec 2027',
      status: vehicle.status || 'Active',
      phone: vehicle.phone || '+251 91 000 0000',
      createdAt: new Date().toISOString()
    };
    db.vehicles.unshift(newRecord);
    this.save(db);
    return newRecord;
  },

  /* ---------- Schedules Methods ---------- */
  getSchedules(query = '') {
    const db = this.load();
    let list = db.schedules || [];
    if (query) {
      const q = query.toLowerCase();
      list = list.filter(s => 
        (s.company && s.company.toLowerCase().includes(q)) ||
        (s.plate && s.plate.toLowerCase().includes(q)) ||
        (s.driver && s.driver.toLowerCase().includes(q))
      );
    }
    return list;
  },

  addSchedule(sch) {
    const db = this.load();
    const newRecord = {
      id: Date.now(),
      company: sch.company,
      plate: sch.plate,
      driver: sch.driver,
      model: sch.model || 'Isuzu FSR',
      win: sch.win || '09:00–12:00',
      purpose: sch.purpose || 'Delivery',
      status: sch.status || 'Expected',
      phone: sch.phone || '+251 91 123 4567',
      cargo: sch.cargo || '—'
    };
    db.schedules.unshift(newRecord);
    this.save(db);
    return newRecord;
  },

  /* ---------- Admin Approvals Workflow Methods ---------- */
  getApprovals(status = 'All', query = '') {
    const db = this.load();
    let list = db.approvals || [];
    if (status && status !== 'All') {
      list = list.filter(a => a.status.toLowerCase() === status.toLowerCase());
    }
    if (query) {
      const q = query.toLowerCase();
      list = list.filter(a => 
        (a.company && a.company.toLowerCase().includes(q)) ||
        (a.plate && a.plate.toLowerCase().includes(q)) ||
        (a.driver && a.driver.toLowerCase().includes(q)) ||
        (a.title && a.title.toLowerCase().includes(q))
      );
    }
    return list;
  },

  getPendingApprovalsCount() {
    const db = this.load();
    return (db.approvals || []).filter(a => a.status === 'Pending').length;
  },

  submitApprovalRequest(req) {
    const db = this.load();
    const newRequest = {
      id: req.id || Date.now(),
      title: req.title || 'Administrative Clearance Required',
      gate: req.gate || 'Entry',
      plate: req.plate || 'SNNP 3-UNKNOWN',
      company: req.company || 'External Logistics',
      driver: req.driver || 'Driver',
      officer: req.officer || 'Gate Officer',
      time: req.time || HIPDC_Utils.getNowTime(),
      status: 'Pending',
      note: req.note || '',
      items: req.items || [],
      destination: req.destination || '',
      waybill: req.waybill || '',
      createdAt: new Date().toISOString()
    };
    db.approvals.unshift(newRequest);
    this.save(db);

    // Broadcast approval creation
    if (window.HIPDC_Notify) {
      window.HIPDC_Notify.show('Request submitted to Admin for approval. Gate locked until approved.', 'warn');
    }
    return newRequest;
  },

  updateApprovalStatus(id, newStatus, reason = '') {
    const db = this.load();
    const req = (db.approvals || []).find(a => String(a.id) === String(id));
    if (req) {
      req.status = newStatus;
      if (reason) {
        req.reason = reason;
        req.note = (req.note ? req.note + ' · ' : '') + 'Admin Reason: ' + reason;
      }
      req.reviewedAt = new Date().toISOString();
      this.save(db);
      return req;
    }
    return null;
  },

  /* ---------- Logistics Logs Methods ---------- */
  getLogs(gate = 'entry', query = '') {
    const db = this.load();
    let list = (db.logs && db.logs[gate]) ? db.logs[gate] : [];
    if (query) {
      const q = query.toLowerCase();
      list = list.filter(log => 
        (log.plate && log.plate.toLowerCase().includes(q)) ||
        (log.driver && log.driver.toLowerCase().includes(q)) ||
        (log.company && log.company.toLowerCase().includes(q))
      );
    }
    return list;
  },

  recordGateLog(log) {
    const db = this.load();
    const gate = log.gate === 'exit' ? 'exit' : 'entry';
    if (!db.logs) db.logs = { entry: [], exit: [] };
    if (!db.logs[gate]) db.logs[gate] = [];

    const newLog = {
      id: log.id || Date.now(),
      time: log.time || HIPDC_Utils.getNowTime(),
      date: log.date || HIPDC_Utils.getTodayString(),
      plate: log.plate,
      driver: log.driver || '—',
      company: log.company || '—',
      type: log.type || (gate === 'entry' ? 'Scheduled' : 'Left empty'),
      adminApproval: log.adminApproval || '—',
      details: log.details || '—',
      items: log.items || []
    };

    db.logs[gate].unshift(newLog);
    this.save(db);
    return newLog;
  },

  /* ---------- Visitors & Passes Methods ---------- */
  getVisitors(query = '') {
    const db = this.load();
    let list = db.visitors || [];
    if (query) {
      const q = query.toLowerCase();
      list = list.filter(v => 
        (v.name && v.name.toLowerCase().includes(q)) ||
        (v.company && v.company.toLowerCase().includes(q)) ||
        (v.plate && v.plate.toLowerCase().includes(q))
      );
    }
    return list;
  },

  addVisitor(v) {
    const db = this.load();
    const newVisitor = {
      id: Date.now(),
      name: v.name,
      company: v.company,
      plate: v.plate || '—',
      date: v.date || HIPDC_Utils.getTodayString(),
      purpose: v.purpose || 'Official Visit',
      status: v.status || 'Active'
    };
    db.visitors.unshift(newVisitor);
    this.save(db);
    return newVisitor;
  },

  /* ---------- User Accounts Methods ---------- */
  getUsers(query = '') {
    const db = this.load();
    let list = db.users || [];
    if (query) {
      const q = query.toLowerCase();
      list = list.filter(u => 
        (u.name && u.name.toLowerCase().includes(q)) ||
        (u.username && u.username.toLowerCase().includes(q))
      );
    }
    return list;
  },

  addUser(u) {
    const db = this.load();
    const newUser = {
      name: u.name,
      username: u.username,
      role: u.role || 'Officer',
      gate: u.gate || 'Entry',
      shift: u.shift || 'Day',
      lastLogin: 'Never',
      status: u.status || 'Active'
    };
    db.users.push(newUser);
    this.save(db);
    return newUser;
  },

  /* ---------- Utility: Seed Test Data / Reset to Empty ---------- */
  clearToEmptyState() {
    const emptyState = this.getDefaultSchema();
    this.save(emptyState);
    if (window.HIPDC_Notify) {
      window.HIPDC_Notify.show('Database cleared to empty state (no records).', 'info');
    }
    setTimeout(() => window.location.reload(), 400);
  },

  seedTestData() {
    const sample = {
      vehicles: [
        { id: 1, company: 'Ayka Addis Textile', plate: 'SNNP 3-A48217', driver: 'Abebe Tadesse', model: 'Isuzu FSR', expiry: 'Mar 2027', status: 'Active', phone: '+251 91 120 4455' },
        { id: 2, company: 'Arvind Lifestyle', plate: 'SNNP 3-A51030', driver: 'Mulu Haile', model: 'Sino Truck', expiry: 'Jan 2027', status: 'Active', phone: '+251 91 220 1180' },
        { id: 3, company: 'Eclat Apparel', plate: 'SNNP 3-B20871', driver: 'Dawit Bekele', model: 'Toyota Hilux', expiry: 'Dec 2026', status: 'Expiring', phone: '+251 91 330 9021' },
        { id: 4, company: 'Jay Jay Textile', plate: 'SNNP 3-A33942', driver: 'Samuel Girma', model: 'Isuzu NPR', expiry: 'Aug 2027', status: 'Blocked', phone: '+251 92 410 7733' },
        { id: 5, company: 'Shints Garment', plate: 'SNNP 3-C11825', driver: 'Henok Assefa', model: 'Fuso Canter', expiry: 'May 2027', status: 'Active', phone: '+251 91 550 2210' }
      ],
      schedules: [
        { id: 101, company: 'Ayka Addis Textile', plate: 'SNNP 3-A48217', driver: 'Abebe Tadesse', model: 'Isuzu FSR', win: '09:00–11:00', purpose: 'Delivery', status: 'Expected', phone: '+251 91 120 4455', cargo: 'Raw Cotton Yarns · 240 Bales' },
        { id: 102, company: 'Arvind Lifestyle', plate: 'SNNP 3-A51030', driver: 'Mulu Haile', model: 'Sino Truck', win: '09:30–12:00', purpose: 'Pickup', status: 'Inside', phone: '+251 91 220 1180', cargo: 'Finished Cotton T-Shirts · 1,200 Cartons' },
        { id: 103, company: 'Eclat Apparel', plate: 'SNNP 3-B20871', driver: 'Dawit Bekele', model: 'Toyota Hilux', win: '10:00–13:00', purpose: 'Delivery', status: 'Expected', phone: '+251 91 330 9021', cargo: 'Sewing Accessories & Zippers · 45 Boxes' },
        { id: 104, company: 'Jay Jay Textile', plate: 'SNNP 3-A33942', driver: 'Samuel Girma', model: 'Isuzu NPR', win: '13:00–15:00', purpose: 'Delivery', status: 'Expected', phone: '+251 92 410 7733', cargo: 'Dyed Fabric Rolls · 80 Rolls' },
        { id: 105, company: 'Shints Garment', plate: 'SNNP 3-C11825', driver: 'Henok Assefa', model: 'Fuso Canter', win: '14:00–16:30', purpose: 'Pickup', status: 'Inside', phone: '+251 91 550 2210', cargo: 'Knitted Sportswear · 650 Cartons' }
      ],
      logs: {
        entry: [
          { id: 201, time: '08:42', plate: 'SNNP 3-A48217', driver: 'Abebe Tadesse', company: 'Ayka Addis Textile', type: 'Scheduled', adminApproval: '—', details: 'Cotton raw materials' }
        ],
        exit: [
          { id: 202, time: '08:55', plate: 'SNNP 3-A51030', driver: 'Mulu Haile', company: 'Arvind Lifestyle', type: 'Left with goods', adminApproval: 'Approved by admin', details: 'T-shirts · 40 cartons', items: [{ item: 'T-Shirts', qty: '1,200', unit: 'Cartons', container: 'MSKU 482011', seal: 'S-77120' }] }
        ]
      },
      approvals: [
        {
          id: 301,
          title: 'Left with goods · 40 cartons',
          gate: 'Exit',
          plate: 'SNNP 3-A51030',
          company: 'Arvind Lifestyle',
          driver: 'Mulu Haile',
          officer: 'Kebede',
          time: '08:51',
          status: 'Pending',
          note: '40 cartons of T-shirts to Djibouti Port. Export container seal verified.',
          items: [{ item: 'T-Shirts', qty: '1,200', unit: 'Cartons', container: 'MSKU 482011', seal: 'S-77120' }],
          destination: 'Djibouti Port',
          waybill: 'GP-2026-0412'
        },
        {
          id: 302,
          title: 'Not scheduled · 2 generators',
          gate: 'Entry',
          plate: 'SNNP 3-A48217',
          company: 'Ayka Addis Textile',
          driver: 'Abebe Tadesse',
          officer: 'Abebe',
          time: '08:42',
          status: 'Pending',
          note: 'Driver brought 2 backup generators for Ayka warehouse construction.',
          items: [],
          destination: 'Warehouse 4',
          waybill: 'WB-9912'
        }
      ],
      visitors: [
        { id: 401, name: 'Tesfaye Alemu', company: 'Ayka Addis Textile', plate: 'SNNP 3-C70115', date: '12 Nov', purpose: 'Factory audit', status: 'Active' },
        { id: 402, name: 'Sara Bekele', company: 'Arvind Lifestyle', plate: 'AA 2-B31842', date: '11 Nov', purpose: 'Buyer visit', status: 'Entry used' }
      ],
      users: [
        { name: 'Officer Abebe', username: 'abebe.t', role: 'Officer', gate: 'Entry', shift: 'Day', lastLogin: 'Today 06:02', status: 'Active' },
        { name: 'Officer Kebede', username: 'kebede.l', role: 'Officer', gate: 'Exit', shift: 'Day', lastLogin: 'Today 06:05', status: 'Active' },
        { name: 'Admin Selam', username: 'selam.m', role: 'Admin', gate: 'All', shift: 'All', lastLogin: 'Today 07:30', status: 'Active' }
      ],
      cameras: [
        { name: 'Entry gate · ANPR Lane 1', gate: 'Entry', activePlate: 'SNNP 3-A48217' },
        { name: 'Exit gate · ANPR Lane 2', gate: 'Exit', activePlate: 'SNNP 3-B20871' },
        { name: 'Camera 1 · Main Access Road', gate: 'Park' },
        { name: 'Camera 2 · Central Warehouse', gate: 'Park' },
        { name: 'Camera 3 · Heavy Truck Parking', gate: 'Park' },
        { name: 'Camera 4 · Perimeter Fence North', gate: 'Park' }
      ]
    };
    this.save(sample);
    if (window.HIPDC_Notify) {
      window.HIPDC_Notify.show('Sample test records loaded into local database.', 'ok');
    }
    setTimeout(() => window.location.reload(), 400);
  }
};
