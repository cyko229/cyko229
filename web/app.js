const $ = (sel) => document.querySelector(sel);

const state = {
  mode: 'login',
  // deviceId -> { data, el, refs, draft } so cards are patched in place rather
  // than rebuilt every poll (rebuilding would fight the user mid-edit).
  devices: new Map(),
  installPrompt: null,
};

const MAX_KEEP_AWAKE = 3600;
const KEEP_AWAKE_STEP = 30;
const KEEP_AWAKE_PRESETS = [0, 30, 120, 300, 900];

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

function toast(message, kind = '', { duration = 2600, onClick = null } = {}) {
  const el = $('#toast');
  el.textContent = message;
  el.className = `toast ${kind}${onClick ? ' clickable' : ''}`;
  el.onclick = onClick;
  clearTimeout(state.toastTimer);
  state.toastTimer = setTimeout(() => el.classList.add('hidden'), duration);
}

// --- durations -------------------------------------------------------------

/** 125 -> "02:05" — the chronometer reading. */
function formatClock(seconds) {
  const m = Math.floor(seconds / 60);
  const s = seconds % 60;
  return `${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
}

/** 125 -> "2 min 5 s" — the human label next to it. */
function formatHuman(seconds) {
  if (seconds === 0) return 'désactivé';
  const m = Math.floor(seconds / 60);
  const s = seconds % 60;
  if (m === 0) return `${s} s`;
  if (s === 0) return `${m} min`;
  return `${m} min ${s} s`;
}

function clampDuration(seconds) {
  return Math.min(MAX_KEEP_AWAKE, Math.max(0, Math.round(seconds)));
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

function createDeviceEl(d) {
  const el = document.createElement('div');
  el.className = 'device';
  el.innerHTML = `
    <div class="device-head">
      <span class="device-name"></span>
      <span class="status">
        <span class="dot"></span>
        <span class="status-text"></span>
      </span>
    </div>
    <div class="actions">
      <button class="wake" data-action="wake">Allumer l'écran</button>
      <button data-action="lock">Verrouiller</button>
    </div>

    <div class="keepawake">
      <div class="keepawake-head">
        <h3>Écran allumé après déverrouillage</h3>
        <span class="muted keepawake-human"></span>
      </div>
      <p class="muted">
        Une fois le téléphone déverrouillé, l'écran reste allumé pendant cette durée.
      </p>
      <div class="chrono">
        <button class="chrono-step" data-step="-1" aria-label="Réduire la durée">−</button>
        <output class="chrono-display" aria-live="polite">00:00</output>
        <button class="chrono-step" data-step="1" aria-label="Augmenter la durée">+</button>
      </div>
      <div class="presets"></div>
      <button class="primary save hidden">Enregistrer la durée</button>
      <div class="countdown hidden">
        <div class="countdown-bar"><span></span></div>
        <span class="countdown-text"></span>
      </div>
    </div>

    <button class="link remove">Dissocier cet appareil</button>
  `;

  const refs = {
    name: el.querySelector('.device-name'),
    dot: el.querySelector('.dot'),
    statusText: el.querySelector('.status-text'),
    buttons: [...el.querySelectorAll('.actions button')],
    human: el.querySelector('.keepawake-human'),
    display: el.querySelector('.chrono-display'),
    presets: el.querySelector('.presets'),
    save: el.querySelector('.save'),
    countdown: el.querySelector('.countdown'),
    countdownBar: el.querySelector('.countdown-bar span'),
    countdownText: el.querySelector('.countdown-text'),
  };

  refs.buttons.forEach((btn) =>
    btn.addEventListener('click', () => sendCommand(d.id, btn.dataset.action, btn)),
  );

  el.querySelectorAll('.chrono-step').forEach((btn) =>
    btn.addEventListener('click', () => {
      const entry = state.devices.get(d.id);
      const current = entry.draft ?? entry.data.keepAwakeSeconds;
      setDraft(d.id, clampDuration(current + Number(btn.dataset.step) * KEEP_AWAKE_STEP));
    }),
  );

  for (const preset of KEEP_AWAKE_PRESETS) {
    const chip = document.createElement('button');
    chip.className = 'preset';
    chip.dataset.seconds = String(preset);
    chip.textContent = preset === 0 ? 'Désactivé' : formatHuman(preset);
    chip.addEventListener('click', () => setDraft(d.id, preset));
    refs.presets.appendChild(chip);
  }

  refs.save.addEventListener('click', () => saveKeepAwake(d.id));
  el.querySelector('.remove').addEventListener('click', () => removeDevice(d.id, d.name));

  return { el, refs };
}

/** Records an unsaved duration the user is dialling in. */
function setDraft(deviceId, seconds) {
  const entry = state.devices.get(deviceId);
  if (!entry) return;
  entry.draft = seconds === entry.data.keepAwakeSeconds ? null : seconds;
  paintKeepAwake(entry);
}

function paintKeepAwake(entry) {
  const { refs, data } = entry;
  const shown = entry.draft ?? data.keepAwakeSeconds;
  refs.display.textContent = formatClock(shown);
  refs.display.classList.toggle('off', shown === 0);
  refs.human.textContent = entry.draft === null ? formatHuman(shown) : `${formatHuman(shown)} — non enregistré`;
  refs.save.classList.toggle('hidden', entry.draft === null);
  refs.presets.querySelectorAll('.preset').forEach((chip) => {
    chip.classList.toggle('active', Number(chip.dataset.seconds) === shown);
  });
}

function paintCountdown(entry) {
  const { refs, data } = entry;
  const remaining = data.keepAwakeUntil ? Math.round((data.keepAwakeUntil - Date.now()) / 1000) : 0;
  if (remaining <= 0) {
    refs.countdown.classList.add('hidden');
    return;
  }
  const total = Math.max(data.keepAwakeSeconds, remaining);
  refs.countdown.classList.remove('hidden');
  refs.countdownBar.style.width = `${Math.min(100, (remaining / total) * 100)}%`;
  refs.countdownText.textContent = `Écran maintenu allumé — ${formatClock(remaining)}`;
}

function updateDeviceEl(entry, d) {
  entry.data = d;
  const { refs } = entry;
  refs.name.textContent = d.name;
  refs.dot.className = `dot ${d.online ? 'online' : 'offline'}`;

  // With a push channel registered, an offline socket is no longer a dead end.
  const reachable = d.online || d.push === 'ready';
  refs.statusText.textContent = d.online
    ? 'En ligne'
    : d.push === 'ready'
      ? 'Veille — joignable par push'
      : 'Hors ligne';
  refs.buttons.forEach((btn) => {
    btn.disabled = !reachable && !btn.dataset.busy;
  });

  paintKeepAwake(entry);
  paintCountdown(entry);
}

async function loadDevices() {
  const devices = await api('/api/devices');
  const container = $('#devices');
  const seen = new Set();

  if (devices.length === 0) {
    state.devices.clear();
    container.innerHTML =
      '<p class="muted empty" style="margin-top:20px">Aucun appareil associé pour l\'instant.</p>';
    return;
  }
  // Drop the empty-state line, if it is what is currently there. Matching on a
  // bare <p> would also match the paragraphs inside a device card.
  container.querySelector(':scope > .empty')?.remove();

  for (const d of devices) {
    seen.add(d.id);
    let entry = state.devices.get(d.id);
    if (!entry) {
      const { el, refs } = createDeviceEl(d);
      entry = { data: d, el, refs, draft: null };
      state.devices.set(d.id, entry);
      container.appendChild(el);
    }
    updateDeviceEl(entry, d);
  }

  for (const [id, entry] of state.devices) {
    if (!seen.has(id)) {
      entry.el.remove();
      state.devices.delete(id);
    }
  }
}

async function saveKeepAwake(deviceId) {
  const entry = state.devices.get(deviceId);
  if (!entry || entry.draft === null) return;
  const seconds = entry.draft;
  entry.refs.save.disabled = true;
  try {
    const updated = await api(`/api/devices/${deviceId}`, {
      method: 'PATCH',
      body: JSON.stringify({ keepAwakeSeconds: seconds }),
    });
    entry.draft = null;
    updateDeviceEl(entry, updated);
    toast(
      seconds === 0
        ? 'Maintien de l\'écran désactivé.'
        : `Écran maintenu ${formatHuman(seconds)} après déverrouillage ✓`,
      'ok',
    );
  } catch (err) {
    toast(err.message, 'err');
  } finally {
    entry.refs.save.disabled = false;
  }
}

async function sendCommand(deviceId, action, btn) {
  const label = btn.textContent;
  btn.dataset.busy = '1';
  btn.disabled = true;
  btn.textContent = '…';
  try {
    const res = await api(`/api/devices/${deviceId}/command`, {
      method: 'POST',
      body: JSON.stringify({ action }),
    });
    const via = res.channel === 'push' ? ' (via notification push)' : '';
    if (res.status === 'ok') {
      const held =
        action === 'wake' && res.keepAwakeSeconds > 0
          ? ` — écran maintenu ${formatHuman(res.keepAwakeSeconds)} après déverrouillage`
          : '';
      toast(`${action === 'wake' ? 'Écran allumé ✓' : 'Téléphone verrouillé ✓'}${via}${held}`, 'ok');
      await loadDevices();
    } else if (res.status === 'no-ack') {
      toast(`Commande envoyée${via}, pas de confirmation reçue.`, '');
    } else {
      toast(`Échec : ${res.detail || res.status}`, 'err');
    }
  } catch (err) {
    toast(err.message, 'err');
  } finally {
    delete btn.dataset.busy;
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

// --- PWA: install prompt + service worker ----------------------------------

function isStandalone() {
  return window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
}

window.addEventListener('beforeinstallprompt', (e) => {
  e.preventDefault(); // we show our own button instead of the mini-infobar
  state.installPrompt = e;
  if (!isStandalone()) $('#install-card').classList.remove('hidden');
});

$('#install-btn').addEventListener('click', async () => {
  const prompt = state.installPrompt;
  if (!prompt) return;
  state.installPrompt = null;
  $('#install-card').classList.add('hidden');
  prompt.prompt();
  await prompt.userChoice.catch(() => null);
});

window.addEventListener('appinstalled', () => {
  state.installPrompt = null;
  $('#install-card').classList.add('hidden');
  toast('RemoteWake installé ✓', 'ok');
});

function registerServiceWorker() {
  if (!('serviceWorker' in navigator)) return;
  navigator.serviceWorker
    .register('sw.js')
    .then((reg) => {
      reg.addEventListener('updatefound', () => {
        const installing = reg.installing;
        if (!installing) return;
        installing.addEventListener('statechange', () => {
          // A controller already exists => this is an update, not a first install.
          if (installing.state === 'installed' && navigator.serviceWorker.controller) {
            toast('Nouvelle version disponible — toucher pour actualiser', '', {
              duration: 10000,
              onClick: () => {
                installing.postMessage('skip-waiting');
                reg.waiting?.postMessage('skip-waiting');
              },
            });
          }
        });
      });
    })
    .catch((err) => console.warn('service worker non enregistré:', err.message));

  let reloading = false;
  navigator.serviceWorker.addEventListener('controllerchange', () => {
    if (reloading) return;
    reloading = true;
    location.reload();
  });
}

// --- bootstrap -------------------------------------------------------------

async function showDashboard() {
  const me = await api('/api/auth/me');
  $('#auth').classList.add('hidden');
  $('#dashboard').classList.remove('hidden');
  $('#who').textContent = me.email;
  await loadDevices();

  // Refresh device online/keep-awake state periodically...
  clearInterval(state.poll);
  state.poll = setInterval(() => loadDevices().catch(() => {}), 5000);
  // ...and tick the countdowns smoothly in between.
  clearInterval(state.tick);
  state.tick = setInterval(() => state.devices.forEach(paintCountdown), 1000);
}

(async function init() {
  setMode('login');
  registerServiceWorker();
  try {
    await showDashboard();
  } catch {
    $('#auth').classList.remove('hidden');
  }
})();
