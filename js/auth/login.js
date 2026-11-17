document.addEventListener('DOMContentLoaded', () => {
  let requestedGate = 'entry';
  const roleButtons = document.querySelectorAll('[data-role]');
  roleButtons.forEach(button => {
    button.addEventListener('click', event => {
      event.preventDefault();
      roleButtons.forEach(item => item.classList.remove('on'));
      button.classList.add('on');
      requestedGate = button.getAttribute('data-role');
    });
  });

  const form = document.getElementById('loginForm');
  if (!form) return;
  form.addEventListener('submit', async event => {
    event.preventDefault();
    const username = document.getElementById('username')?.value.trim() || '';
    const password = document.getElementById('password')?.value || '';
    if (!username || !password) {
      HIPDC_Notify.show('Please enter both username and password.', 'warn');
      return;
    }

    const submit = form.querySelector('[type="submit"]');
    if (submit) submit.disabled = true;
    try {
      const user = await HIPDC_API.login(username, password);
      const profile = await HIPDC_API.me();
      if (profile.role.code === 'admin') {
        window.location.replace('../admin/dashboard.html');
        return;
      }
      const allowedDirections = new Set(
        (profile.gate_assignments || []).flatMap(assignment =>
          assignment.direction === 'both'
            ? ['entry', 'exit']
            : [assignment.direction]
        )
      );
      const direction = allowedDirections.has(requestedGate)
        ? requestedGate
        : (allowedDirections.has('entry') ? 'entry' : (allowedDirections.has('exit') ? 'exit' : null));
      if (!direction) {
        await HIPDC_API.logout();
        HIPDC_Notify.show('This account has no active gate assignment. Contact an administrator.', 'bad', 0);
        return;
      }
      window.location.replace('../' + direction + '/dashboard.html');
    } catch (error) {
      HIPDC_Notify.show(error.message || 'Sign in failed.', 'bad', 0);
      if (submit) submit.disabled = false;
    }
  });
});
