// Tiny file-backed JSON store. Fine for a single-user / small deployment.
// For anything larger, swap this module for a real database (Postgres, SQLite...).

import { readFileSync, writeFileSync, mkdirSync, existsSync } from 'node:fs';
import { dirname } from 'node:path';

export class Store {
  constructor(file) {
    this.file = file;
    this.data = { users: {}, devices: {}, pairings: {} };
    this._load();
  }

  _load() {
    try {
      if (existsSync(this.file)) {
        this.data = JSON.parse(readFileSync(this.file, 'utf8'));
        this.data.users ??= {};
        this.data.devices ??= {};
        this.data.pairings ??= {};
      }
    } catch (err) {
      console.error('[store] failed to read', this.file, err.message);
    }
  }

  _save() {
    const dir = dirname(this.file);
    if (!existsSync(dir)) mkdirSync(dir, { recursive: true });
    writeFileSync(this.file, JSON.stringify(this.data, null, 2));
  }

  // ---- Users (web accounts) ----
  getUserByEmail(email) {
    return Object.values(this.data.users).find((u) => u.email === email) || null;
  }

  getUser(id) {
    return this.data.users[id] || null;
  }

  createUser(user) {
    this.data.users[user.id] = user;
    this._save();
    return user;
  }

  // ---- Pairing codes (short-lived, used to enrol a phone) ----
  createPairing(pairing) {
    this.data.pairings[pairing.code] = pairing;
    this._save();
    return pairing;
  }

  getPairing(code) {
    return this.data.pairings[code] || null;
  }

  deletePairing(code) {
    delete this.data.pairings[code];
    this._save();
  }

  // ---- Devices (enrolled phones) ----
  createDevice(device) {
    this.data.devices[device.id] = device;
    this._save();
    return device;
  }

  getDevice(id) {
    return this.data.devices[id] || null;
  }

  getDeviceByToken(token) {
    return Object.values(this.data.devices).find((d) => d.token === token) || null;
  }

  listDevicesForUser(userId) {
    return Object.values(this.data.devices).filter((d) => d.userId === userId);
  }

  updateDevice(id, patch) {
    if (!this.data.devices[id]) return null;
    Object.assign(this.data.devices[id], patch);
    this._save();
    return this.data.devices[id];
  }

  deleteDevice(id) {
    delete this.data.devices[id];
    this._save();
  }
}
