package com.cyko.remotewake

import android.app.admin.DeviceAdminReceiver

/**
 * Registers the app as a device administrator. The only policy we use is lockNow(),
 * which powers the remote-lock action. The user must explicitly enable this in
 * Settings via the prompt shown from MainActivity.
 */
class AdminReceiver : DeviceAdminReceiver()
