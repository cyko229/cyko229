const $ = (sel) => document.querySelector(sel);

const state = { mode: 'login' };

async function api(path, options = {}) {
  const res = await fetch(path, {
    headers: { 'Content-Type': 'application/json' },
    credentials: 'same-origin',
    ...options,
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(data.error || `erreur ${res.status}`);
  return data;
}

function toast(message, kind = '') {
  const el = $('#toast');
  el.textContent = message;
  el.className = `toast ${kind}`;
  setTimeout(() => el.classList.add('hidden'), 2600);
}

// --- auth ------------------------------------------------------------------

function setMode(mode) {
  state.mode = mode;
  document.querySelectorAll('.tab').forEach((t) => t.classList.toggle('active', t.dataset.tab === mode));
  $('#auth-submit').textContent = mode === 'login' ? 'Se connecter' : 'Créer le compte';
  $('#password').autocomplete = mode === 'login' ? 'current-password' : 'new-password';
  $('#auth-error').textContent = '';
}

document.querySelectorAll('.tab').forEach((t) => t.addEventListener('click', () => setMode(t.dataset.tab)));

$('#auth-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  $('#auth-error').textContent = '';
  try {
    const body = JSON.stringify({ email: $('#email').value, password: $('#password').value });
    const path = state.mode === 'login' ? '/api/auth/login' : '/api/auth/register';
    await api(path, { method: 'POST', body });
    await showDashboard();
  } catch (err) {
    $('#auth-error').textContent = err.message;
  }
});

$('#logout').addEventListener('click', async () => {
  await api('/api/auth/logout', { method: 'POST' });
  location.reload();
});

// --- pairing ---------------------------------------------------------------

$('#pair-btn').addEventListener('click', async () => {
  try {
    const name = $('#device-name').value || 'Mon téléphone';
    const { code } = await api('/api/pairings', { method: 'POST', body: JSON.stringify({ name }) });
    $('#pair-code').textContent = code;
    $('#pair-result').classList.remove('hidden');
  } catch (err) {
    toast(err.message, 'err');
  }
});

// --- devices ---------------------------------------------------------------

async function loadDevices() {
  const devices = await api('/api/devices');
  const container = $('#devices');
  if (devices.length === 0) {
    container.innerHTML = '<p class="muted" style="margin-top:20px">Aucun appareil associé pour l\'instant.</p>';
    return;
  }
  container.innerHTML = '';
  for (const d of devices) {
    const el = document.createElement('div');
    el.className = 'device';
    el.innerHTML = `
      <div class="device-head">
        <span class="device-name"></span>
        <span class="status">
          <span class="dot ${d.online ? 'online' : 'offline'}"></span>
          ${d.online ? 'En ligne' : 'Hors ligne'}
        </span>
      </div>
      <div class="actions">
        <button class="wake" ${d.online ? '' : 'disabled'} data-action="wake">Allumer l'écran</button>
        <button ${d.online ? '' : 'disabled'} data-action="lock">Verrouiller</button>
      </div>
      <button class="link remove">Dissocier cet appareil</button>
    `;
    el.querySelector('.device-name').textContent = d.name;
    el.querySelectorAll('.actions button').forEach((btn) =>
      btn.addEventListener('click', () => sendCommand(d.id, btn.dataset.action, btn)),
    );
    el.querySelector('.remove').addEventListener('click', () => removeDevice(d.id, d.name));
    container.appendChild(el);
  }
}

async function sendCommand(deviceId, action, btn) {
  const label = btn.textContent;
  btn.disabled = true;
  btn.textContent = '…';
  try {
    const res = await api(`/api/devices/${deviceId}/command`, {
      method: 'POST',
      body: JSON.stringify({ action }),
    });
    if (res.status === 'ok') toast(action === 'wake' ? 'Écran allumé ✓' : 'Téléphone verrouillé ✓', 'ok');
    else if (res.status === 'no-ack') toast('Commande envoyée, pas de confirmation reçue.', '');
    else toast(`Échec : ${res.detail || res.status}`, 'err');
  } catch (err) {
    toast(err.message, 'err');
  } finally {
    btn.disabled = false;
    btn.textContent = label;
  }
}

async function removeDevice(deviceId, name) {
  if (!confirm(`Dissocier "${name}" ?`)) return;
  try {
    await api(`/api/devices/${deviceId}`, { method: 'DELETE' });
    await loadDevices();
    toast('Appareil dissocié.', 'ok');
  } catch (err) {
    toast(err.message, 'err');
  }
}

// --- bootstrap -------------------------------------------------------------

async function showDashboard() {
  const me = await api('/api/auth/me');
  $('#auth').classList.add('hidden');
  $('#dashboard').classList.remove('hidden');
  $('#who').textContent = me.email;
  await loadDevices();
  // Refresh device online/offline state periodically.
  clearInterval(state.poll);
  state.poll = setInterval(loadDevices, 5000);
}

(async function init() {
  setMode('login');
  try {
    await showDashboard();
  } catch {
    $('#auth').classList.remove('hidden');
  }
})();
