// End-to-end smoke test: register -> pair -> simulate phone over WS -> send wake -> expect ack.
// Node 18+ provides a global WebSocket client — no dependency needed here.

const BASE = 'http://localhost:8080';
let cookie = '';

async function api(path, body, method = 'POST') {
  const res = await fetch(BASE + path, {
    method,
    headers: { 'Content-Type': 'application/json', ...(cookie ? { Cookie: cookie } : {}) },
    body: body ? JSON.stringify(body) : undefined,
  });
  const setCookie = res.headers.get('set-cookie');
  if (setCookie) cookie = setCookie.split(';')[0];
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(`${path} -> ${res.status} ${JSON.stringify(data)}`);
  return data;
}

function assert(cond, msg) {
  if (!cond) throw new Error('ASSERT FAILED: ' + msg);
  console.log('  ok:', msg);
}

const email = `test${Date.now()}@example.com`;

// 1. register
await api('/api/auth/register', { email, password: 'supersecret' });
assert(cookie.includes('session'), 'got session cookie on register');

// 2. create pairing code
const pairing = await api('/api/pairings', { name: 'Test Phone' });
assert(/^\d{6}$/.test(pairing.code), 'pairing code is 6 digits');

// 3. phone redeems code -> gets token
const redeemed = await api('/api/pairings/redeem', { code: pairing.code });
assert(redeemed.deviceToken && redeemed.deviceId, 'phone received device token');

// 4. device shows up, offline
let devices = await api('/api/devices', null, 'GET');
assert(devices.length === 1 && devices[0].online === false, 'device listed, offline before WS');

// 5. simulate phone connecting over WebSocket
const ws = new WebSocket(`ws://localhost:8080/device?token=${redeemed.deviceToken}`);
const received = [];
ws.on('message', (raw) => {
  const msg = JSON.parse(raw.toString());
  received.push(msg);
  if (msg.type === 'command') {
    // act like the phone: ack success
    ws.send(JSON.stringify({ type: 'ack', commandId: msg.commandId, ok: true }));
  }
  if (msg.type === 'ping') ws.send(JSON.stringify({ type: 'pong' }));
});
await new Promise((r, j) => { ws.on('open', r); ws.on('error', j); });
await new Promise((r) => setTimeout(r, 300));

// 6. device now online
devices = await api('/api/devices', null, 'GET');
assert(devices[0].online === true, 'device online after WS connect');

// 7. send wake command, expect ack ok
const wake = await api(`/api/devices/${redeemed.deviceId}/command`, { action: 'wake' });
assert(wake.status === 'ok', 'wake command acked ok');
assert(received.some((m) => m.type === 'command' && m.action === 'wake'), 'phone received wake command');

// 8. send lock command
const lock = await api(`/api/devices/${redeemed.deviceId}/command`, { action: 'lock' });
assert(lock.status === 'ok', 'lock command acked ok');

// 9. invalid action rejected
try {
  await api(`/api/devices/${redeemed.deviceId}/command`, { action: 'nuke' });
  throw new Error('should have rejected invalid action');
} catch (e) {
  assert(e.message.includes('400'), 'invalid action rejected with 400');
}

// 10. unauthorized device token rejected
const bad = new WebSocket('ws://localhost:8080/device?token=deadbeef');
const badClose = await new Promise((r) => bad.on('close', (code) => r(code)));
assert(badClose === 4001, 'bad device token closed with 4001');

// 11. second user cannot command first user's device
cookie = '';
await api('/api/auth/register', { email: `other${Date.now()}@example.com`, password: 'supersecret' });
try {
  await api(`/api/devices/${redeemed.deviceId}/command`, { action: 'wake' });
  throw new Error('should not reach another user device');
} catch (e) {
  assert(e.message.includes('404'), 'other user gets 404 for foreign device');
}

ws.close();
console.log('\nALL E2E CHECKS PASSED');
process.exit(0);
