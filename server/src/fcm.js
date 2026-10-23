// Firebase Cloud Messaging (HTTP v1) sender — no SDK, only Node built-ins.
//
// FCM is the channel that still reaches a phone in deep Doze, where a plain
// WebSocket is suspended. We sign a short-lived JWT with the service account
// key, exchange it for an OAuth access token, and POST data messages.
//
// Configuration (see .env.example): either FCM_SERVICE_ACCOUNT_FILE pointing at
// the JSON Google gives you, or the three FCM_PROJECT_ID / FCM_CLIENT_EMAIL /
// FCM_PRIVATE_KEY variables. With none of them set, FCM is simply disabled and
// the server falls back to the WebSocket channel alone.

import { createSign, createHmac, timingSafeEqual } from 'node:crypto';
import { readFileSync } from 'node:fs';

const TOKEN_URL = 'https://oauth2.googleapis.com/token';
const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

function base64url(input) {
  return Buffer.from(input).toString('base64url');
}

function loadCredentials(env = process.env) {
  if (env.FCM_SERVICE_ACCOUNT_FILE) {
    const raw = JSON.parse(readFileSync(env.FCM_SERVICE_ACCOUNT_FILE, 'utf8'));
    return {
      projectId: raw.project_id,
      clientEmail: raw.client_email,
      privateKey: raw.private_key,
    };
  }
  if (env.FCM_PROJECT_ID && env.FCM_CLIENT_EMAIL && env.FCM_PRIVATE_KEY) {
    return {
      projectId: env.FCM_PROJECT_ID,
      clientEmail: env.FCM_CLIENT_EMAIL,
      // Allow the key to be pasted on a single line with literal \n.
      privateKey: env.FCM_PRIVATE_KEY.replace(/\\n/g, '\n'),
    };
  }
  return null;
}

/**
 * Signs a command so the phone can prove it came from its own relay server.
 * The key is the device token, a secret only that phone and the server know —
 * so even a leaked Firebase project cannot inject a command the app accepts.
 */
export function signCommand(deviceToken, payload) {
  const canonical = [
    payload.commandId,
    payload.action,
    payload.issuedAt,
    payload.keepAwakeSeconds ?? '',
  ].join('.');
  return createHmac('sha256', deviceToken).update(canonical).digest('hex');
}

export function verifyCommandSignature(deviceToken, payload, signature) {
  const expected = Buffer.from(signCommand(deviceToken, payload), 'utf8');
  const given = Buffer.from(String(signature || ''), 'utf8');
  return expected.length === given.length && timingSafeEqual(expected, given);
}

/** Misconfigured credentials disable push; they never stop the server booting. */
function safeLoadCredentials(env = process.env) {
  try {
    return loadCredentials(env);
  } catch (err) {
    console.warn('[fcm] credentials could not be read, push disabled:', err.message);
    return null;
  }
}

export class FcmSender {
  constructor(credentials = safeLoadCredentials()) {
    this.credentials = credentials;
    this.accessToken = null;
    this.accessTokenExpiresAt = 0;
  }

  get enabled() {
    return Boolean(this.credentials);
  }

  async _getAccessToken() {
    // Reuse the token until a minute before it lapses.
    if (this.accessToken && Date.now() < this.accessTokenExpiresAt - 60_000) {
      return this.accessToken;
    }
    const { clientEmail, privateKey } = this.credentials;
    const iat = Math.floor(Date.now() / 1000);
    const header = base64url(JSON.stringify({ alg: 'RS256', typ: 'JWT' }));
    const claims = base64url(
      JSON.stringify({
        iss: clientEmail,
        scope: SCOPE,
        aud: TOKEN_URL,
        iat,
        exp: iat + 3600,
      }),
    );
    const signature = createSign('RSA-SHA256')
      .update(`${header}.${claims}`)
      .sign(privateKey)
      .toString('base64url');

    const res = await fetch(TOKEN_URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({
        grant_type: 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        assertion: `${header}.${claims}.${signature}`,
      }),
    });
    const body = await res.json().catch(() => ({}));
    if (!res.ok) {
      throw new Error(`FCM token exchange failed (${res.status}): ${body.error_description || body.error || ''}`);
    }
    this.accessToken = body.access_token;
    this.accessTokenExpiresAt = Date.now() + Number(body.expires_in || 3600) * 1000;
    return this.accessToken;
  }

  /**
   * Pushes a command as a high-priority *data* message. Data-only messages are
   * handed to onMessageReceived even when the app is backgrounded, which is what
   * lets the phone act instead of just showing a notification.
   */
  async sendCommand(device, payload) {
    if (!this.enabled) throw new Error('FCM is not configured');
    if (!device.fcmToken) throw new Error('device has no FCM token');

    const data = {
      type: 'command',
      commandId: payload.commandId,
      action: payload.action,
      issuedAt: String(payload.issuedAt),
      keepAwakeSeconds: String(payload.keepAwakeSeconds ?? ''),
      sig: signCommand(device.token, payload),
    };

    const accessToken = await this._getAccessToken();
    const res = await fetch(
      `https://fcm.googleapis.com/v1/projects/${this.credentials.projectId}/messages:send`,
      {
        method: 'POST',
        headers: {
          Authorization: `Bearer ${accessToken}`,
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          message: {
            token: device.fcmToken,
            data,
            android: {
              priority: 'high', // wakes the app out of Doze
              ttl: '60s', // a stale wake command is useless
            },
          },
        }),
      },
    );
    const body = await res.json().catch(() => ({}));
    if (!res.ok) {
      const reason = body.error?.details?.[0]?.errorCode || body.error?.status || res.status;
      const err = new Error(`FCM send failed: ${reason}`);
      err.fcmErrorCode = body.error?.details?.[0]?.errorCode || null;
      throw err;
    }
    return body;
  }
}

export { loadCredentials, safeLoadCredentials };
