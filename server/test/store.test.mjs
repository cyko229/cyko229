// Dependency-free test of the data store (uses only Node built-ins).
// Run with:  node test/store.test.mjs
import assert from 'node:assert/strict';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { Store } from '../src/store.js';

const dir = mkdtempSync(join(tmpdir(), 'rw-store-'));
const file = join(dir, 'store.json');
let passed = 0;
const ok = (msg) => { passed++; console.log('  ok:', msg); };

try {
  let s = new Store(file);

  // users
  const u1 = s.createUser({ id: 'u1', email: 'a@b.c', passwordHash: 'h', createdAt: 't' });
  assert.equal(s.getUserByEmail('a@b.c').id, 'u1'); ok('user lookup by email');
  assert.equal(s.getUser('u1').email, 'a@b.c'); ok('user lookup by id');
  assert.equal(s.getUserByEmail('nope@x.y'), null); ok('unknown email -> null');

  // pairings
  s.createPairing({ code: '123456', userId: 'u1', name: 'Phone', expiresAt: Date.now() + 1000 });
  assert.equal(s.getPairing('123456').userId, 'u1'); ok('pairing stored and retrieved');
  s.deletePairing('123456');
  assert.equal(s.getPairing('123456'), null); ok('pairing deleted');

  // devices + ownership
  const d1 = s.createDevice({ id: 'd1', userId: 'u1', name: 'Phone', token: 'tok1', createdAt: 't' });
  const d2 = s.createDevice({ id: 'd2', userId: 'u2', name: 'Other', token: 'tok2', createdAt: 't' });
  assert.equal(s.getDeviceByToken('tok1').id, 'd1'); ok('device lookup by token');
  assert.equal(s.getDeviceByToken('bad'), null); ok('bad token -> null');
  const mine = s.listDevicesForUser('u1');
  assert.equal(mine.length, 1); assert.equal(mine[0].id, 'd1'); ok('listDevicesForUser isolates by owner');

  // update + delete
  s.updateDevice('d1', { lastSeen: 'now' });
  assert.equal(s.getDevice('d1').lastSeen, 'now'); ok('device update persists field');
  s.deleteDevice('d2');
  assert.equal(s.getDevice('d2'), null); ok('device deleted');

  // persistence: reload from disk
  s = new Store(file);
  assert.equal(s.getUser('u1').email, 'a@b.c'); ok('users survive reload from disk');
  assert.equal(s.getDeviceByToken('tok1').id, 'd1'); ok('devices survive reload from disk');
  assert.equal(s.getDevice('d2'), null); ok('deletions survive reload');

  console.log(`\nSTORE TESTS PASSED (${passed} assertions)`);
} finally {
  rmSync(dir, { recursive: true, force: true });
}
