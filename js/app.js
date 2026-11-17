document.addEventListener('DOMContentLoaded', async () => {
  if (window.HIPDC_Theme) HIPDC_Theme.init();
  if (window.HIPDC_I18N) HIPDC_I18N.init();
  if (!window.HIPDC_API) return;

  const path = window.location.pathname.toLowerCase();
  const loginPage = path.endsWith('/pages/auth/login.html');
  if (path.endsWith('/index.html') || path === '/') {
    try {
      const user = await HIPDC_API.me();
      const direction = getAvailableDirections(user)[0];
      window.location.replace(user.role.code === 'admin'
        ? 'pages/admin/dashboard.html'
        : (direction ? 'pages/' + direction + '/dashboard.html' : 'pages/auth/login.html'));
    } catch (error) {
      window.location.replace('pages/auth/login.html');
    }
    return;
  }
  if (loginPage) return;

  try {
    const user = await HIPDC_API.me();
    if (window.HIPDC_DB) await HIPDC_DB.refresh();
    window.dispatchEvent(new Event('hipdc-authenticated'));
    window.addEventListener('hipdc-db-updated', updateDashboardValues);
    updateDashboardValues();
    const isAdminPage = path.includes('/pages/admin/');
    const requestedDirection = path.includes('/pages/exit/') ? 'exit' : 'entry';
    const directions = getAvailableDirections(user);

    if (user.role.code !== 'admin' && isAdminPage) {
      window.location.replace(directions[0]
        ? '../' + directions[0] + '/dashboard.html'
        : '../auth/login.html');
      return;
    }
    if (user.role.code !== 'admin' && path.includes('/pages/entry/') && !directions.includes('entry')) {
      if (directions.includes('exit')) {
        window.location.replace('../exit/dashboard.html');
      } else {
        window.location.replace('../auth/login.html');
      }
      return;
    }
    if (user.role.code !== 'admin' && path.includes('/pages/exit/') && !directions.includes('exit')) {
      if (directions.includes('entry')) {
        window.location.replace('../entry/dashboard.html');
      } else {
        window.location.replace('../auth/login.html');
      }
      return;
    }
    if (user.role.code === 'admin' && !isAdminPage) {
      window.location.replace('../admin/dashboard.html');
      return;
    }

    const name = user.name || user.username || 'User';
    const nameEl = document.getElementById('sidebar_user_name');
    const subEl = document.getElementById('sidebar_user_sub');
    const avatar = document.getElementById('sidebar_user_av');
    if (nameEl) nameEl.textContent = name;
    if (subEl) subEl.textContent = user.role.name || user.role.code;
    if (avatar) avatar.textContent = name.split(/\s+/).map(part => part[0]).join('').slice(0, 2).toUpperCase();
    document.documentElement.dataset.userRole = user.role.code;
    document.documentElement.dataset.gateDirection = directions.includes(requestedDirection)
      ? requestedDirection
      : (directions[0] || '');
    if (user.role.code === 'admin') addOperationsLink();
    installNotificationCenter(user);
  } catch (error) {
    if (error.status === 401) {
      window.location.replace(loginPage ? 'login.html' : '../auth/login.html');
      return;
    }
    if (window.HIPDC_Notify) {
      HIPDC_Notify.show('Cannot verify your session: ' + error.message, 'bad', 0);
    }
  }
});

function getAvailableDirections(user) {
  if (user.role.code === 'admin') return ['entry', 'exit'];
  const directions = new Set();
  (user.gate_assignments || []).forEach(assignment => {
    if (assignment.direction === 'both') {
      directions.add('entry');
      directions.add('exit');
    } else if (['entry', 'exit'].includes(assignment.direction)) {
      directions.add(assignment.direction);
    }
  });
  return ['entry', 'exit'].filter(direction => directions.has(direction));
}

function updateDashboardValues() {
  const summary = window.HIPDC_DB?.data.dashboard;
  if (!summary) return;
  const values = {
    expected_schedules_today: summary.expected_schedules_today,
    vehicle_entries: summary.vehicle_activity.entries,
    vehicle_exits: summary.vehicle_activity.exits,
    vehicle_presence: summary.vehicle_activity.current_presence,
    visitor_presence: summary.visitor_activity.current_presence,
    gate_granted: summary.gate_activity.granted,
    pending_approvals: summary.pending_approvals
  };
  Object.entries(values).forEach(([key, value]) => {
    const element = document.querySelector(`[data-dashboard="${key}"]`);
    if (element) element.textContent = String(value ?? 0);
  });
}

function addOperationsLink() {
  const nav = document.querySelector('aside nav');
  if (!nav || nav.querySelector('a[href="operations.html"]')) return;
  const link = document.createElement('a');
  link.href = 'operations.html';
  link.className = 'nav';
  link.textContent = 'Operations & Reports';
  nav.appendChild(link);
}

function installNotificationCenter(user) {
  if (!(user.permissions || []).includes('notifications.view')) return;
  const actions = document.querySelector('.top .tr');
  if (!actions) return;
  const button = document.createElement('button');
  button.type = 'button';
  button.className = 'pill';
  button.textContent = 'Notifications';
  button.setAttribute('aria-expanded', 'false');
  const panel = document.createElement('section');
  panel.className = 'panel notification-center';
  panel.setAttribute('role', 'dialog');
  panel.setAttribute('aria-label', 'My notifications');
  // .panel { display:flex } overrides the HTML hidden attribute, so start closed with inline display.
  panel.style.cssText = 'display:none;position:fixed;z-index:1000;right:24px;top:76px;width:min(420px,calc(100vw - 32px));max-height:70vh;overflow:auto;box-shadow:var(--shadow-lg);';
  panel.innerHTML = '<div class="tr" style="justify-content:space-between;align-items:center;gap:8px">'
    + '<b>My notifications</b>'
    + '<div class="tr" style="gap:8px;margin:0">'
    + '<button type="button" class="btn sm soft" data-read-all>Mark all read</button>'
    + '<button type="button" class="modal-close-btn" data-close-notifications aria-label="Close notifications">&times;</button>'
    + '</div></div>'
    + '<div data-notification-list class="muted">Loading…</div>';
  actions.prepend(button);
  document.body.appendChild(panel);

  let loadVersion = 0;
  let busy = false;
  let closeAfterMarkAll = false;
  let markAllLoading = false;
  let isOpen = false;

  const setOpen = open => {
    // User intentionally opened the panel again; do not auto-close a prior mark-all.
    if (open) closeAfterMarkAll = false;
    isOpen = open;
    panel.style.display = open ? 'flex' : 'none';
    button.setAttribute('aria-expanded', String(open));
  };

  const listEl = () => panel.querySelector('[data-notification-list]');

  const load = async ({ showLoading = false } = {}) => {
    const version = ++loadVersion;
    const list = listEl();
    if (showLoading && list) list.textContent = 'Loading…';
    try {
      const notifications = await HIPDC_API.allPages('notifications/index.php', 'notifications');
      if (version !== loadVersion) return;
      const unread = notifications.filter(item => !item.read_at).length;
      button.textContent = unread ? 'Notifications (' + unread + ')' : 'Notifications';
      const target = listEl();
      if (!target) return;
      target.innerHTML = notifications.length
        ? notifications.map(item => `<div class="req"><div><b>${HIPDC_Utils.esc(item.title)}</b><p>${HIPDC_Utils.esc(item.message)}</p><small>${HIPDC_Utils.esc(item.created_at)}</small></div>${item.read_at ? '<span class="chip">Read</span>' : `<button class="btn sm" data-read-id="${Number(item.id)}">Read</button>`}</div>`).join('')
        : '<p class="muted">No notifications.</p>';
    } catch (error) {
      if (version !== loadVersion) return;
      const target = listEl();
      if (target) target.textContent = error.message;
      throw error;
    }
  };

  button.addEventListener('click', event => {
    event.preventDefault();
    event.stopPropagation();
    const opening = !isOpen;
    setOpen(opening);
    if (opening) load({ showLoading: true }).catch(() => {});
  });

  document.addEventListener('click', event => {
    if (!isOpen) return;
    if (panel.contains(event.target) || button.contains(event.target)) return;
    setOpen(false);
  });

  document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && isOpen) setOpen(false);
  });

  panel.addEventListener('click', async event => {
    if (event.target.closest('[data-close-notifications]')) {
      event.preventDefault();
      event.stopPropagation();
      setOpen(false);
      return;
    }

    const readButton = event.target.closest('[data-read-id]');
    const readAllButton = event.target.closest('[data-read-all]');
    if (!readButton && !readAllButton) return;
    if (busy) return;
    event.stopPropagation();
    busy = true;
    if (readAllButton) {
      readAllButton.disabled = true;
      closeAfterMarkAll = true;
      markAllLoading = true;
      const list = listEl();
      if (list) list.textContent = 'Loading…';
    }
    try {
      if (readButton) {
        await HIPDC_API.post('notifications/read.php', { id: Number(readButton.dataset.readId) });
        await load();
      } else {
        await HIPDC_API.post('notifications/read-all.php');
        await load();
        if (closeAfterMarkAll) setOpen(false);
      }
    } catch (error) {
      if (markAllLoading) {
        await load().catch(loadError => {
          const list = listEl();
          if (list) list.textContent = loadError.message || error.message;
        });
      }
      HIPDC_Notify.show(error.message, 'bad', 0);
    } finally {
      busy = false;
      closeAfterMarkAll = false;
      markAllLoading = false;
      const markAll = panel.querySelector('[data-read-all]');
      if (markAll) markAll.disabled = false;
    }
  });
}
