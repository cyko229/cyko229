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

const __dirname = dirname(fileURLToPath(import.meta.url));

const PORT = Number(process.env.PORT || 8080);
const JWT_SECRET = process.env.JWT_SECRET || 'dev-insecure-secret-change-me';
const DATA_FILE = process.env.DATA_FILE || join(__dirname, '..', 'data', 'store.json');
const SECURE_COOKIES = process.env.SECURE_COOKIES === 'true';

if (JWT_SECRET === 'dev-insecure-secret-change-me') {
  console.warn('[warn] JWT_SECRET is the insecure default. Set a real one in .env before production.');
}

const store = new Store(DATA_FILE);

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

function publicDevice(d) {
  return {
    id: d.id,
    name: d.name,
    online: liveDevices.has(d.id),
    lastSeen: d.lastSeen || null,
    createdAt: d.createdAt,
  };
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
  });
  res.json({ deviceId: device.id, deviceToken: device.token, name: device.name });
});

app.get('/api/devices', authWeb, (req, res) => {
  res.json(store.listDevicesForUser(req.user.id).map(publicDevice));
});

app.delete('/api/devices/:id', authWeb, (req, res) => {
  const device = store.getDevice(req.params.id);
  if (!device || device.userId !== req.user.id) return res.status(404).json({ error: 'not found' });
  liveDevices.get(device.id)?.close(4003, 'device removed');
  store.deleteDevice(device.id);
  res.json({ ok: true });
});

// The core action: send a command to one of the user's phones.
app.post('/api/devices/:id/command', authWeb, async (req, res) => {
  const device = store.getDevice(req.params.id);
  if (!device || device.userId !== req.user.id) return res.status(404).json({ error: 'not found' });

  const action = req.body?.action;
  if (!['wake', 'lock'].includes(action)) {
    return res.status(400).json({ error: 'action must be "wake" or "lock"' });
  }

  const ws = liveDevices.get(device.id);
  if (!ws) return res.status(409).json({ error: 'device is offline' });

  const commandId = randomUUID();
  const ack = new Promise((resolve) => {
    const timer = setTimeout(() => {
      pendingAcks.delete(commandId);
      resolve({ status: 'no-ack' });
    }, 8000);
    pendingAcks.set(commandId, { resolve, timer });
  });

  ws.send(JSON.stringify({ type: 'command', commandId, action }));
  const result = await ack;
  res.json({ commandId, action, ...result });
});

// --- static web app --------------------------------------------------------

app.use(express.static(join(__dirname, '..', '..', 'web')));

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
  ws.send(JSON.stringify({ type: 'welcome', deviceId: device.id, name: device.name }));
  console.log(`[device] ${device.name} (${device.id}) online`);

  ws.on('message', (raw) => {
    let msg;
    try {
      msg = JSON.parse(raw.toString());
    } catch {
      return;
    }
    if (msg.type === 'ack' && msg.commandId) {
      const pending = pendingAcks.get(msg.commandId);
      if (pending) {
        clearTimeout(pending.timer);
        pendingAcks.delete(msg.commandId);
        pending.resolve({ status: msg.ok ? 'ok' : 'failed', detail: msg.detail });
      }
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
