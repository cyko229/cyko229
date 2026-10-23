// End-to-end smoke test: register -> pair -> simulate phone over WS -> send wake -> expect ack.
// Node 18+ provides a global WebSocket client — no dependency needed here. It is the
// standard (EventTarget) API, so listeners go through addEventListener, not .on().

import { createHmac } from 'node:crypto';

const BASE = 'http://localhost:8080';
let cookie = '';

async function api(path, body, method = 'POST', extraHeaders = {}) {
  const res = await fetch(BASE + path, {
    method,
    headers: {
      'Content-Type': 'application/json',
      ...(cookie ? { Cookie: cookie } : {}),
      ...extraHeaders,
    },
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

// 4. device shows up, offline, with the default keep-awake duration
let devices = await api('/api/devices', null, 'GET');
assert(devices.length === 1 && devices[0].online === false, 'device listed, offline before WS');
assert(devices[0].keepAwakeSeconds === 120, 'new device gets the default keep-awake duration');
assert(devices[0].keepAwakeUntil === null, 'no keep-awake hold in progress yet');

// 5. simulate phone connecting over WebSocket
const ws = new WebSocket(`ws://localhost:8080/device?token=${redeemed.deviceToken}`);
const received = [];
ws.addEventListener('message', (event) => {
  const msg = JSON.parse(event.data.toString());
  received.push(msg);
  if (msg.type === 'command') {
    // act like the phone: ack success, reporting the hold it just started
    ws.send(
      JSON.stringify({
        type: 'ack',
        commandId: msg.commandId,
        ok: true,
        keepAwakeUntil: msg.keepAwakeSeconds ? Date.now() + msg.keepAwakeSeconds * 1000 : 0,
      }),
    );
  }
  if (msg.type === 'ping') ws.send(JSON.stringify({ type: 'pong' }));
});
await new Promise((resolve, reject) => {
  ws.addEventListener('open', resolve, { once: true });
  ws.addEventListener('error', reject, { once: true });
});
await new Promise((r) => setTimeout(r, 300));

// 6. device now online
devices = await api('/api/devices', null, 'GET');
assert(devices[0].online === true, 'device online after WS connect');

// 7. the welcome frame carries the current keep-awake configuration
const welcome = received.find((m) => m.type === 'welcome');
assert(welcome?.keepAwakeSeconds === 120, 'welcome frame carries the keep-awake duration');

// 8. change the duration from the "web app": the phone is told live
const patched = await api(`/api/devices/${redeemed.deviceId}`, { keepAwakeSeconds: 300 }, 'PATCH');
assert(patched.keepAwakeSeconds === 300, 'PATCH stores the new keep-awake duration');
await new Promise((r) => setTimeout(r, 200));
assert(
  received.some((m) => m.type === 'config' && m.keepAwakeSeconds === 300),
  'phone received the new duration over the socket',
);

// 9. out-of-range durations are refused
for (const bad of [-1, 3601, 12.5, 'soon']) {
  try {
    await api(`/api/devices/${redeemed.deviceId}`, { keepAwakeSeconds: bad }, 'PATCH');
    throw new Error(`should have rejected keepAwakeSeconds=${bad}`);
  } catch (e) {
    assert(e.message.includes('400'), `keepAwakeSeconds=${bad} rejected with 400`);
  }
}

// 10. send wake command, expect ack ok, carrying the stored duration
const wake = await api(`/api/devices/${redeemed.deviceId}/command`, { action: 'wake' });
assert(wake.status === 'ok', 'wake command acked ok');
assert(wake.channel === 'socket', 'wake went over the WebSocket channel');
assert(wake.keepAwakeSeconds === 300, 'wake command carries the stored keep-awake duration');

const wakeCommand = received.find((m) => m.type === 'command' && m.action === 'wake');
assert(wakeCommand, 'phone received wake command');
assert(wakeCommand.keepAwakeSeconds === 300, 'phone was told how long to hold the screen');

// 11. the command is signed with the device token, so the phone can trust it
const expectedSig = createHmac('sha256', redeemed.deviceToken)
  .update(`${wakeCommand.commandId}.wake.${wakeCommand.issuedAt}.300`)
  .digest('hex');
assert(wakeCommand.sig === expectedSig, 'wake command carries a valid HMAC signature');

// 12. a one-off duration overrides the stored one without changing it
const oneOff = await api(`/api/devices/${redeemed.deviceId}/command`, {
  action: 'wake',
  keepAwakeSeconds: 30,
});
assert(oneOff.keepAwakeSeconds === 30, 'per-command duration override applies');
devices = await api('/api/devices', null, 'GET');
assert(devices[0].keepAwakeSeconds === 300, 'the stored duration is left untouched');

// 13. the phone reports its keep-awake progress; the web app can show it
const until = Date.now() + 300_000;
ws.send(JSON.stringify({ type: 'status', keepAwakeUntil: until }));
await new Promise((r) => setTimeout(r, 200));
devices = await api('/api/devices', null, 'GET');
assert(devices[0].keepAwakeUntil === until, 'keep-awake countdown is exposed to the web app');

// 14. send lock command
const lock = await api(`/api/devices/${redeemed.deviceId}/command`, { action: 'lock' });
assert(lock.status === 'ok', 'lock command acked ok');
assert(lock.keepAwakeSeconds === 0, 'lock never carries a keep-awake duration');

// 15. device-authenticated endpoints: FCM registration + HTTP ack
const auth = { Authorization: `Bearer ${redeemed.deviceToken}` };
const reg = await api('/api/device/fcm-token', { fcmToken: 'fake-fcm-token' }, 'POST', auth);
assert(reg.ok === true, 'phone registered its FCM token');
devices = await api('/api/devices', null, 'GET');
assert(
  ['ready', 'disabled'].includes(devices[0].push),
  'web app is told whether the push channel is usable',
);

try {
  await api('/api/device/fcm-token', { fcmToken: 'x' }, 'POST', { Authorization: 'Bearer nope' });
  throw new Error('should have rejected a bad device token');
} catch (e) {
  assert(e.message.includes('401'), 'bad device token rejected with 401 on device endpoints');
}

const strayAck = await api('/api/device/ack', { commandId: 'unknown', ok: true }, 'POST', auth);
assert(strayAck.delivered === false, 'an ack for an unknown command is harmless');

// 16. invalid action rejected
try {
  await api(`/api/devices/${redeemed.deviceId}/command`, { action: 'nuke' });
  throw new Error('should have rejected invalid action');
} catch (e) {
  assert(e.message.includes('400'), 'invalid action rejected with 400');
}

// 17. unauthorized device token rejected
const bad = new WebSocket('ws://localhost:8080/device?token=deadbeef');
bad.addEventListener('error', () => {}); // an unauthorised socket also errors; ignore it
const badClose = await new Promise((r) => bad.addEventListener('close', (e) => r(e.code), { once: true }));
assert(badClose === 4001, 'bad device token closed with 4001');

// 18. second user cannot command first user's device
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
