import 'dotenv/config';
import { randomUUID, randomBytes, randomInt } from 'node:crypto';
import { createServer } from 'node:http';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

import express from 'express';
import cookieParser from 'cookie-parser';
import bcrypt from 'bcryptjs';
import jwt from 'jsonwebtoken';
import { WebSocketServer } from 'ws';

import { Store } from './store.js';
import { FcmSender, signCommand } from './fcm.js';

const __dirname = dirname(fileURLToPath(import.meta.url));

const PORT = Number(process.env.PORT || 8080);
const JWT_SECRET = process.env.JWT_SECRET || 'dev-insecure-secret-change-me';
const DATA_FILE = process.env.DATA_FILE || join(__dirname, '..', 'data', 'store.json');
const SECURE_COOKIES = process.env.SECURE_COOKIES === 'true';

// "Keep the screen on after unlock" bounds. 0 disables the feature for a device.
const DEFAULT_KEEP_AWAKE_SECONDS = 120;
const MAX_KEEP_AWAKE_SECONDS = 3600;

if (JWT_SECRET === 'dev-insecure-secret-change-me') {
  console.warn('[warn] JWT_SECRET is the insecure default. Set a real one in .env before production.');
}

const store = new Store(DATA_FILE);

// Push channel. Disabled (and silently skipped) when no credentials are set.
const fcm = new FcmSender();
console.log(
  fcm.enabled
    ? '[fcm] push channel enabled'
    : '[fcm] push channel disabled (no credentials) — WebSocket only',
);

// deviceId -> live WebSocket connection (a phone that is online right now)
const liveDevices = new Map();
// commandId -> { resolve, timer } so a web request can await the phone's ack
const pendingAcks = new Map();

const app = express();
app.use(express.json());
app.use(cookieParser());

// --- helpers ---------------------------------------------------------------

function signSession(user) {
  return jwt.sign({ sub: user.id, email: user.email }, JWT_SECRET, { expiresIn: '7d' });
}

function authWeb(req, res, next) {
  const token = req.cookies?.session || (req.headers.authorization || '').replace(/^Bearer /, '');
  if (!token) return res.status(401).json({ error: 'not authenticated' });
  try {
    const payload = jwt.verify(token, JWT_SECRET);
    const user = store.getUser(payload.sub);
    if (!user) return res.status(401).json({ error: 'unknown user' });
    req.user = user;
    next();
  } catch {
    return res.status(401).json({ error: 'invalid session' });
  }
}

/** Clamps a user-supplied keep-awake duration into the allowed range. */
function normalizeKeepAwake(value, fallback = DEFAULT_KEEP_AWAKE_SECONDS) {
  const n = Number(value);
  if (!Number.isFinite(n)) return fallback;
  return Math.min(MAX_KEEP_AWAKE_SECONDS, Math.max(0, Math.round(n)));
}

function publicDevice(d) {
  return {
    id: d.id,
    name: d.name,
    online: liveDevices.has(d.id),
    lastSeen: d.lastSeen || null,
    createdAt: d.createdAt,
    keepAwakeSeconds: normalizeKeepAwake(d.keepAwakeSeconds),
    // Epoch ms until which the phone reported it is holding the screen on.
    keepAwakeUntil: d.keepAwakeUntil && d.keepAwakeUntil > Date.now() ? d.keepAwakeUntil : null,
    // 'disabled' = server has no FCM credentials, 'pending' = app not registered yet.
    push: !fcm.enabled ? 'disabled' : d.fcmToken ? 'ready' : 'pending',
    maxKeepAwakeSeconds: MAX_KEEP_AWAKE_SECONDS,
  };
}

/** A phone authenticates with the long-lived token it got when pairing. */
function authDevice(req, res, next) {
  const token =
    (req.headers.authorization || '').replace(/^Bearer /, '') || req.body?.deviceToken || '';
  const device = token && store.getDeviceByToken(token);
  if (!device) return res.status(401).json({ error: 'invalid device token' });
  req.device = device;
  next();
}

/** Resolves the HTTP request that is waiting for this command's acknowledgement. */
function resolveAck(commandId, result) {
  const pending = pendingAcks.get(commandId);
  if (!pending) return false;
  clearTimeout(pending.timer);
  pendingAcks.delete(commandId);
  pending.resolve(result);
  return true;
}

function waitForAck(commandId, timeoutMs) {
  return new Promise((resolve) => {
    const timer = setTimeout(() => {
      pendingAcks.delete(commandId);
      resolve({ status: 'no-ack' });
    }, timeoutMs);
    pendingAcks.set(commandId, { resolve, timer });
  });
}

function cancelAck(commandId) {
  const pending = pendingAcks.get(commandId);
  if (pending) {
    clearTimeout(pending.timer);
    pendingAcks.delete(commandId);
  }
}

/** Pushes the current keep-awake setting to a phone that is connected right now. */
function sendConfig(device) {
  const ws = liveDevices.get(device.id);
  // Note: `ws?.x === ws?.y` is true for a missing socket (undefined === undefined),
  // so the socket itself has to be checked first.
  if (ws && ws.readyState === ws.OPEN) {
    ws.send(
      JSON.stringify({
        type: 'config',
        keepAwakeSeconds: normalizeKeepAwake(device.keepAwakeSeconds),
      }),
    );
  }
}

// --- auth routes -----------------------------------------------------------

app.post('/api/auth/register', async (req, res) => {
  const { email, password } = req.body || {};
  if (!email || !password || password.length < 8) {
    return res.status(400).json({ error: 'email and a password of at least 8 characters are required' });
  }
  if (store.getUserByEmail(email)) {
    return res.status(409).json({ error: 'an account with that email already exists' });
  }
  const user = store.createUser({
    id: randomUUID(),
    email,
    passwordHash: await bcrypt.hash(password, 12),
    createdAt: new Date().toISOString(),
  });
  res.cookie('session', signSession(user), cookieOpts());
  res.json({ id: user.id, email: user.email });
});

app.post('/api/auth/login', async (req, res) => {
  const { email, password } = req.body || {};
  const user = store.getUserByEmail(email || '');
  if (!user || !(await bcrypt.compare(password || '', user.passwordHash))) {
    return res.status(401).json({ error: 'invalid email or password' });
  }
  res.cookie('session', signSession(user), cookieOpts());
  res.json({ id: user.id, email: user.email });
});

app.post('/api/auth/logout', (req, res) => {
  res.clearCookie('session', cookieOpts());
  res.json({ ok: true });
});

app.get('/api/auth/me', authWeb, (req, res) => {
  res.json({ id: req.user.id, email: req.user.email });
});

function cookieOpts() {
  return {
    httpOnly: true,
    sameSite: 'lax',
    secure: SECURE_COOKIES,
    maxAge: 7 * 24 * 3600 * 1000,
    path: '/',
  };
}

// --- device pairing & management ------------------------------------------

// Web user asks for a pairing code to enrol a new phone.
app.post('/api/pairings', authWeb, (req, res) => {
  const code = String(randomInt(0, 1_000_000)).padStart(6, '0');
  const pairing = {
    code,
    userId: req.user.id,
    name: (req.body?.name || 'My phone').toString().slice(0, 60),
    expiresAt: Date.now() + 10 * 60 * 1000, // 10 minutes
  };
  store.createPairing(pairing);
  res.json({ code, expiresAt: pairing.expiresAt });
});

// Phone redeems the pairing code and receives its long-lived device token.
app.post('/api/pairings/redeem', (req, res) => {
  const { code } = req.body || {};
  const pairing = store.getPairing(String(code || ''));
  if (!pairing || pairing.expiresAt < Date.now()) {
    if (pairing) store.deletePairing(pairing.code);
    return res.status(400).json({ error: 'invalid or expired pairing code' });
  }
  store.deletePairing(pairing.code);
  const device = store.createDevice({
    id: randomUUID(),
    userId: pairing.userId,
    name: pairing.name,
    token: randomBytes(32).toString('hex'), // the phone's secret
    createdAt: new Date().toISOString(),
    lastSeen: null,
    keepAwakeSeconds: DEFAULT_KEEP_AWAKE_SECONDS,
  });
  res.json({
    deviceId: device.id,
    deviceToken: device.token,
    name: device.name,
    keepAwakeSeconds: device.keepAwakeSeconds,
    pushEnabled: fcm.enabled,
  });
});

app.get('/api/devices', authWeb, (req, res) => {
  res.json(store.listDevicesForUser(req.user.id).map(publicDevice));
});

// Device settings from the web app — today: how long to hold the screen on
// after the phone is unlocked (0 = don't).
app.patch('/api/devices/:id', authWeb, (req, res) => {
  const device = store.getDevice(req.params.id);
  if (!device || device.userId !== req.user.id) return res.status(404).json({ error: 'not found' });

  const patch = {};
  if (req.body?.name !== undefined) {
    const name = String(req.body.name).trim().slice(0, 60);
    if (!name) return res.status(400).json({ error: 'name cannot be empty' });
    patch.name = name;
  }
  if (req.body?.keepAwakeSeconds !== undefined) {
    const raw = Number(req.body.keepAwakeSeconds);
    if (!Number.isInteger(raw) || raw < 0 || raw > MAX_KEEP_AWAKE_SECONDS) {
      return res.status(400).json({
        error: `keepAwakeSeconds must be a whole number between 0 and ${MAX_KEEP_AWAKE_SECONDS}`,
      });
    }
    patch.keepAwakeSeconds = raw;
  }
  if (Object.keys(patch).length === 0) return res.status(400).json({ error: 'nothing to update' });

  const updated = store.updateDevice(device.id, patch);
  sendConfig(updated);
  res.json(publicDevice(updated));
});

app.delete('/api/devices/:id', authWeb, (req, res) => {
  const device = store.getDevice(req.params.id);
  if (!device || device.userId !== req.user.id) return res.status(404).json({ error: 'not found' });
  liveDevices.get(device.id)?.close(4003, 'device removed');
  store.deleteDevice(device.id);
  res.json({ ok: true });
});

// The core action: send a command to one of the user's phones. We try the live
// WebSocket first (lowest latency) and fall back to an FCM push, which is the
// only channel that still reaches a phone sleeping in Doze.
app.post('/api/devices/:id/command', authWeb, async (req, res) => {
  const device = store.getDevice(req.params.id);
  if (!device || device.userId !== req.user.id) return res.status(404).json({ error: 'not found' });

  const action = req.body?.action;
  if (!['wake', 'lock'].includes(action)) {
    return res.status(400).json({ error: 'action must be "wake" or "lock"' });
  }

  // Locking turns the screen off, so holding it awake only applies to "wake".
  // The web app may override the stored duration for a single command.
  let keepAwakeSeconds = 0;
  if (action === 'wake') {
    if (req.body?.keepAwakeSeconds !== undefined) {
      const raw = Number(req.body.keepAwakeSeconds);
      if (!Number.isInteger(raw) || raw < 0 || raw > MAX_KEEP_AWAKE_SECONDS) {
        return res.status(400).json({
          error: `keepAwakeSeconds must be a whole number between 0 and ${MAX_KEEP_AWAKE_SECONDS}`,
        });
      }
      keepAwakeSeconds = raw;
    } else {
      keepAwakeSeconds = normalizeKeepAwake(device.keepAwakeSeconds);
    }
  }

  const payload = {
    commandId: randomUUID(),
    action,
    issuedAt: Date.now(),
    keepAwakeSeconds,
  };
  // The phone checks this signature before acting, so a command is only ever
  // executed if it really came from the relay this phone is paired with.
  const sig = signCommand(device.token, payload);

  let socketResult = null;
  const ws = liveDevices.get(device.id);
  if (ws && ws.readyState === ws.OPEN) {
    const ack = waitForAck(payload.commandId, 8000);
    ws.send(JSON.stringify({ type: 'command', ...payload, sig }));
    socketResult = await ack;
    if (socketResult.status !== 'no-ack') {
      return res.json({ ...payload, channel: 'socket', ...socketResult });
    }
    // The socket is stale: the phone is listed as online but is not answering.
  }

  if (fcm.enabled && device.fcmToken) {
    // A phone woken by a push acks over HTTP (POST /api/device/ack); give it
    // longer, since it has to come out of Doze first.
    const ack = waitForAck(payload.commandId, 15000);
    try {
      await fcm.sendCommand(device, payload);
    } catch (err) {
      cancelAck(payload.commandId);
      if (err.fcmErrorCode === 'UNREGISTERED' || err.fcmErrorCode === 'INVALID_ARGUMENT') {
        store.updateDevice(device.id, { fcmToken: null }); // stale registration
      }
      console.warn('[fcm]', err.message);
      return res.status(502).json({ error: `push failed: ${err.message}` });
    }
    const result = await ack;
    return res.json({ ...payload, channel: 'push', ...result });
  }

  if (socketResult) return res.json({ ...payload, channel: 'socket', ...socketResult });
  return res.status(409).json({ error: 'device is offline' });
});

// --- endpoints the phone itself calls (device-token auth) ------------------

// The app reports its FCM registration token here, at pairing and whenever
// Firebase rotates it.
app.post('/api/device/fcm-token', authDevice, (req, res) => {
  const fcmToken = String(req.body?.fcmToken || '').trim();
  if (!fcmToken) return res.status(400).json({ error: 'fcmToken is required' });
  store.releaseFcmToken(fcmToken, req.device.id);
  store.updateDevice(req.device.id, { fcmToken, lastSeen: new Date().toISOString() });
  res.json({ ok: true, pushEnabled: fcm.enabled });
});

// Acknowledgement for a command that arrived as a push (no socket to reply on).
app.post('/api/device/ack', authDevice, (req, res) => {
  const { commandId, ok, detail, keepAwakeUntil } = req.body || {};
  if (!commandId) return res.status(400).json({ error: 'commandId is required' });
  const patch = { lastSeen: new Date().toISOString() };
  if (Number.isFinite(Number(keepAwakeUntil))) patch.keepAwakeUntil = Number(keepAwakeUntil);
  store.updateDevice(req.device.id, patch);
  const delivered = resolveAck(String(commandId), {
    status: ok ? 'ok' : 'failed',
    detail: detail || undefined,
  });
  res.json({ ok: true, delivered });
});

// Keep-awake progress, so the web app can show a live countdown.
app.post('/api/device/status', authDevice, (req, res) => {
  const patch = { lastSeen: new Date().toISOString() };
  const until = Number(req.body?.keepAwakeUntil);
  if (Number.isFinite(until)) patch.keepAwakeUntil = until;
  store.updateDevice(req.device.id, patch);
  res.json({ ok: true, keepAwakeSeconds: normalizeKeepAwake(req.device.keepAwakeSeconds) });
});

// --- static web app --------------------------------------------------------

app.use(
  express.static(join(__dirname, '..', '..', 'web'), {
    setHeaders(res, filePath) {
      // The service worker must never be served stale, or installed clients
      // would keep running an old build forever.
      if (filePath.endsWith('sw.js')) {
        res.setHeader('Cache-Control', 'no-cache');
        res.setHeader('Service-Worker-Allowed', '/');
      }
      if (filePath.endsWith('.webmanifest')) {
        res.setHeader('Content-Type', 'application/manifest+json; charset=utf-8');
      }
    },
  }),
);

// --- HTTP + WebSocket server ----------------------------------------------

const server = createServer(app);

// Phones connect here. They authenticate with their device token, then stay
// connected so the server can push commands with near-zero latency.
const wss = new WebSocketServer({ server, path: '/device' });

wss.on('connection', (ws, req) => {
  const url = new URL(req.url, 'http://localhost');
  const token = url.searchParams.get('token');
  const device = token && store.getDeviceByToken(token);

  if (!device) {
    ws.close(4001, 'unauthorized');
    return;
  }

  // Replace any stale connection for the same device.
  liveDevices.get(device.id)?.close(4000, 'replaced by newer connection');
  liveDevices.set(device.id, ws);
  store.updateDevice(device.id, { lastSeen: new Date().toISOString() });
  ws.send(
    JSON.stringify({
      type: 'welcome',
      deviceId: device.id,
      name: device.name,
      keepAwakeSeconds: normalizeKeepAwake(device.keepAwakeSeconds),
      pushEnabled: fcm.enabled,
    }),
  );
  console.log(`[device] ${device.name} (${device.id}) online`);

  ws.on('message', (raw) => {
    let msg;
    try {
      msg = JSON.parse(raw.toString());
    } catch {
      return;
    }
    if (msg.type === 'ack' && msg.commandId) {
      if (Number.isFinite(Number(msg.keepAwakeUntil))) {
        store.updateDevice(device.id, { keepAwakeUntil: Number(msg.keepAwakeUntil) });
      }
      resolveAck(String(msg.commandId), {
        status: msg.ok ? 'ok' : 'failed',
        detail: msg.detail,
      });
    } else if (msg.type === 'status') {
      // The phone tells us how long it is still holding the screen on.
      const patch = { lastSeen: new Date().toISOString() };
      if (Number.isFinite(Number(msg.keepAwakeUntil))) patch.keepAwakeUntil = Number(msg.keepAwakeUntil);
      store.updateDevice(device.id, patch);
    } else if (msg.type === 'fcmToken' && msg.fcmToken) {
      store.releaseFcmToken(String(msg.fcmToken), device.id);
      store.updateDevice(device.id, { fcmToken: String(msg.fcmToken) });
    } else if (msg.type === 'pong') {
      store.updateDevice(device.id, { lastSeen: new Date().toISOString() });
    }
  });

  const ping = setInterval(() => {
    if (ws.readyState === ws.OPEN) ws.send(JSON.stringify({ type: 'ping' }));
  }, 30000);

  ws.on('close', () => {
    clearInterval(ping);
    if (liveDevices.get(device.id) === ws) {
      liveDevices.delete(device.id);
      store.updateDevice(device.id, { lastSeen: new Date().toISOString() });
      console.log(`[device] ${device.name} (${device.id}) offline`);
    }
  });
});

server.listen(PORT, () => {
  console.log(`RemoteWake server listening on http://localhost:${PORT}`);
});
