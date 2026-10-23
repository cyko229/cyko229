// Dependency-free tests for command signing and the FCM credential loader.
// Run with:  node test/fcm.test.mjs
import assert from 'node:assert/strict';
import { FcmSender, loadCredentials, safeLoadCredentials, signCommand, verifyCommandSignature } from '../src/fcm.js';

let passed = 0;
const ok = (msg) => { passed++; console.log('  ok:', msg); };

const token = 'a'.repeat(64);
const payload = { commandId: 'cmd-1', action: 'wake', issuedAt: 1700000000000, keepAwakeSeconds: 120 };

// --- signing ---------------------------------------------------------------
const sig = signCommand(token, payload);
assert.match(sig, /^[0-9a-f]{64}$/); ok('signature is a sha256 hex digest');
assert.equal(signCommand(token, payload), sig); ok('signing is deterministic');
assert.ok(verifyCommandSignature(token, payload, sig)); ok('a valid signature verifies');

assert.ok(!verifyCommandSignature('b'.repeat(64), payload, sig)); ok('another device token does not verify');
assert.ok(!verifyCommandSignature(token, { ...payload, action: 'lock' }, sig)); ok('a tampered action does not verify');
assert.ok(!verifyCommandSignature(token, { ...payload, keepAwakeSeconds: 3600 }, sig));
ok('a tampered duration does not verify');
assert.ok(!verifyCommandSignature(token, { ...payload, issuedAt: 1 }, sig)); ok('a replayed timestamp does not verify');
assert.ok(!verifyCommandSignature(token, payload, '')); ok('an empty signature does not verify');
assert.ok(!verifyCommandSignature(token, payload, sig.slice(0, -1))); ok('a truncated signature does not verify');

// The canonical form the Android client recomputes — pin it so the two sides
// cannot drift apart silently.
assert.equal(
  sig,
  (await import('node:crypto')).createHmac('sha256', token)
    .update('cmd-1.wake.1700000000000.120')
    .digest('hex'),
);
ok('canonical string is commandId.action.issuedAt.keepAwakeSeconds');

// --- credentials -----------------------------------------------------------
assert.equal(loadCredentials({}), null); ok('no env vars -> no credentials');

const fromEnv = loadCredentials({
  FCM_PROJECT_ID: 'proj',
  FCM_CLIENT_EMAIL: 'robot@proj.iam.gserviceaccount.com',
  FCM_PRIVATE_KEY: '-----BEGIN PRIVATE KEY-----\\nabc\\n-----END PRIVATE KEY-----\\n',
});
assert.equal(fromEnv.projectId, 'proj'); ok('credentials read from individual env vars');
assert.ok(fromEnv.privateKey.includes('\n')); ok('escaped newlines in the key are unescaped');

assert.throws(() => loadCredentials({ FCM_SERVICE_ACCOUNT_FILE: '/nope/missing.json' }));
ok('a missing service-account file throws from loadCredentials');
assert.equal(safeLoadCredentials({ FCM_SERVICE_ACCOUNT_FILE: '/nope/missing.json' }), null);
ok('...but safeLoadCredentials just disables push instead of crashing the server');

assert.equal(new FcmSender(null).enabled, false); ok('sender without credentials reports disabled');
assert.equal(new FcmSender(fromEnv).enabled, true); ok('sender with credentials reports enabled');
await assert.rejects(
  () => new FcmSender(null).sendCommand({ fcmToken: 'x', token }, payload),
  /not configured/,
);
ok('sending without credentials is refused');
await assert.rejects(
  () => new FcmSender(fromEnv).sendCommand({ token }, payload),
  /no FCM token/,
);
ok('sending to a device with no registration token is refused');

// --- the actual send, with the network stubbed out ----------------------
// Verifies the two-step exchange (service-account JWT -> OAuth token -> send)
// and the exact shape of the message we hand to FCM.
const { generateKeyPairSync } = await import('node:crypto');
const { privateKey } = generateKeyPairSync('rsa', {
  modulusLength: 2048,
  privateKeyEncoding: { type: 'pkcs8', format: 'pem' },
  publicKeyEncoding: { type: 'spki', format: 'pem' },
});

const calls = [];
const realFetch = globalThis.fetch;
globalThis.fetch = async (url, options) => {
  calls.push({ url: String(url), options });
  if (String(url).includes('oauth2.googleapis.com')) {
    return new Response(JSON.stringify({ access_token: 'tok-123', expires_in: 3600 }), {
      status: 200,
      headers: { 'Content-Type': 'application/json' },
    });
  }
  return new Response(JSON.stringify({ name: 'projects/proj/messages/1' }), {
    status: 200,
    headers: { 'Content-Type': 'application/json' },
  });
};

try {
  const sender = new FcmSender({
    projectId: 'proj',
    clientEmail: 'robot@proj.iam.gserviceaccount.com',
    privateKey,
  });
  const device = { id: 'd1', token, fcmToken: 'device-registration-token' };
  await sender.sendCommand(device, payload);

  assert.equal(calls.length, 2); ok('one token exchange plus one send');

  const [jwtCall, sendCall] = calls;
  const assertion = new URLSearchParams(jwtCall.options.body).get('assertion');
  const [header, claims] = assertion
    .split('.')
    .slice(0, 2) // the third segment is the signature, not JSON
    .map((part) => JSON.parse(Buffer.from(part, 'base64url')));
  assert.equal(header.alg, 'RS256'); ok('service-account JWT is RS256');
  assert.equal(claims.scope, 'https://www.googleapis.com/auth/firebase.messaging');
  ok('JWT requests the firebase.messaging scope');
  assert.equal(claims.iss, 'robot@proj.iam.gserviceaccount.com'); ok('JWT is issued by the service account');

  assert.match(sendCall.url, /projects\/proj\/messages:send$/); ok('send targets the project endpoint');
  assert.equal(sendCall.options.headers.Authorization, 'Bearer tok-123');
  ok('send uses the access token from the exchange');

  const message = JSON.parse(sendCall.options.body).message;
  assert.equal(message.token, 'device-registration-token'); ok('message targets the device registration token');
  assert.equal(message.android.priority, 'high'); ok('message is high priority (wakes the phone in Doze)');
  assert.equal(message.android.ttl, '60s'); ok('a stale wake expires rather than arriving late');
  assert.equal(message.notification, undefined); ok('data-only message, so the app acts instead of showing a bubble');
  assert.deepEqual(Object.keys(message.data).sort(), ['action', 'commandId', 'issuedAt', 'keepAwakeSeconds', 'sig', 'type']);
  ok('data carries exactly the fields the Android client parses');
  assert.equal(message.data.action, 'wake'); ok('action travels in the data payload');
  assert.equal(message.data.keepAwakeSeconds, '120'); ok('keep-awake duration travels with the command');
  assert.ok(verifyCommandSignature(token, payload, message.data.sig)); ok('pushed command is signed with the device token');

  // The access token is cached rather than re-fetched for every command.
  await sender.sendCommand(device, { ...payload, commandId: 'cmd-2' });
  assert.equal(calls.length, 3); ok('the OAuth token is reused for the next send');

  // An FCM-side rejection surfaces its error code so the caller can drop the token.
  globalThis.fetch = async () =>
    new Response(JSON.stringify({ error: { status: 'NOT_FOUND', details: [{ errorCode: 'UNREGISTERED' }] } }), {
      status: 404,
      headers: { 'Content-Type': 'application/json' },
    });
  const rejected = await sender.sendCommand(device, { ...payload, commandId: 'cmd-3' }).catch((e) => e);
  assert.equal(rejected.fcmErrorCode, 'UNREGISTERED'); ok('an UNREGISTERED device is reported as such');
} finally {
  globalThis.fetch = realFetch;
}

console.log(`\nFCM TESTS PASSED (${passed} assertions)`);
