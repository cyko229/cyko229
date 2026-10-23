package com.cyko.remotewake

import android.app.admin.DevicePolicyManager
import android.content.ComponentName
import android.content.Context
import android.content.Intent

/**
 * Executes the two remote actions on this device:
 *  - wake:  turn the screen on (works even when locked / screen off)
 *  - lock:  lock the device immediately (requires device-admin to be enabled)
 */
object ScreenController {

    fun wake(context: Context) {
        // We cannot turn the screen on from a background service directly, but launching an
        // activity flagged with setTurnScreenOn(true) / setShowWhenLocked(true) does exactly
        // that, and it is honoured even when the device is locked and the display is off.
        val intent = Intent(context, WakeActivity::class.java).apply {
            addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_NO_ANIMATION)
        }
        context.startActivity(intent)
    }

    /** Returns true if the device was locked, false if device-admin is not enabled. */
    fun lock(context: Context): Boolean {
        val dpm = context.getSystemService(Context.DEVICE_POLICY_SERVICE) as DevicePolicyManager
        val admin = ComponentName(context, AdminReceiver::class.java)
        if (!dpm.isAdminActive(admin)) return false
        dpm.lockNow()
        return true
    }
}
