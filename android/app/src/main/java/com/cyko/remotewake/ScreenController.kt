package com.cyko.remotewake

import android.app.KeyguardManager
import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.app.admin.DevicePolicyManager
import android.content.ComponentName
import android.content.Context
import android.content.Intent
import android.os.Build
import android.os.Handler
import android.os.Looper
import android.util.Log

/**
 * Executes the remote actions on this device:
 *  - wake: turn the screen on (works even when locked / screen off), then hold
 *    it on for the configured duration once the user unlocks
 *  - lock: lock the device immediately (requires device-admin to be enabled)
 */
object ScreenController {

    private const val TAG = "RemoteWakeScreen"
    private const val FULLSCREEN_CHANNEL = "remotewake_wake"
    private const val FULLSCREEN_NOTIF_ID = 2

    /**
     * @param keepAwakeSeconds how long to hold the screen on after the unlock
     *        that follows; 0 leaves the normal screen timeout alone.
     */
    fun wake(context: Context, keepAwakeSeconds: Int = Prefs(context).keepAwakeSeconds) {
        // Arm first: if the user is quick to unlock, we are already listening.
        KeepAwakeManager.arm(context, keepAwakeSeconds)

        // We cannot turn the screen on from a background service directly, but
        // launching an activity flagged setTurnScreenOn(true) / setShowWhenLocked(true)
        // does exactly that, and it is honoured with the display off and locked.
        val requestedAt = android.os.SystemClock.elapsedRealtime()
        val intent = wakeIntent(context, keepAwakeSeconds)
        runCatching { context.startActivity(intent) }
            .onFailure { Log.w(TAG, "startActivity refused: ${it.message}") }

        // Background activity starts are restricted on Android 10+, and a silent
        // refusal is indistinguishable from success. Check whether the activity
        // really came up and, if not, take the sanctioned route: a notification
        // with a full-screen intent, which the system itself launches.
        Handler(Looper.getMainLooper()).postDelayed({
            if (WakeActivity.shownSince(requestedAt)) return@postDelayed
            Log.i(TAG, "activity did not appear, falling back to a full-screen intent")
            postFullScreenIntent(context, keepAwakeSeconds)
        }, 1200)
    }

    /** Returns true if the device was locked, false if device-admin is not enabled. */
    fun lock(context: Context): Boolean {
        val dpm = context.getSystemService(Context.DEVICE_POLICY_SERVICE) as DevicePolicyManager
        val admin = ComponentName(context, AdminReceiver::class.java)
        if (!dpm.isAdminActive(admin)) return false
        dpm.lockNow()
        return true
    }

    private fun wakeIntent(context: Context, keepAwakeSeconds: Int) =
        Intent(context, WakeActivity::class.java).apply {
            addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_NO_ANIMATION)
            putExtra(WakeActivity.EXTRA_KEEP_AWAKE_SECONDS, keepAwakeSeconds)
        }

    /**
     * On Android 14+ a non-calling app needs the user to grant full-screen-intent
     * use; see [canUseFullScreenIntent]. Without it the notification still shows
     * as a heads-up, which lights the screen on most devices.
     */
    private fun postFullScreenIntent(context: Context, keepAwakeSeconds: Int) {
        val manager = context.getSystemService(NotificationManager::class.java) ?: return
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            manager.createNotificationChannel(
                NotificationChannel(
                    FULLSCREEN_CHANNEL,
                    context.getString(R.string.channel_wake_name),
                    NotificationManager.IMPORTANCE_HIGH,
                ),
            )
        }
        val pending = PendingIntent.getActivity(
            context,
            0,
            wakeIntent(context, keepAwakeSeconds),
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
        )
        val notification = Notification.Builder(context, FULLSCREEN_CHANNEL)
            .setContentTitle(context.getString(R.string.wake_notif_title))
            .setContentText(context.getString(R.string.wake_notif_text))
            .setSmallIcon(android.R.drawable.ic_lock_idle_lock)
            .setCategory(Notification.CATEGORY_ALARM)
            .setFullScreenIntent(pending, true)
            .setAutoCancel(true)
            .setTimeoutAfter(10_000)
            .build()
        runCatching { manager.notify(FULLSCREEN_NOTIF_ID, notification) }
            .onFailure { Log.w(TAG, "full-screen notification refused: ${it.message}") }
    }

    /** True when the system lets us launch an activity over the lock screen. */
    fun canUseFullScreenIntent(context: Context): Boolean {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.UPSIDE_DOWN_CAKE) return true
        val manager = context.getSystemService(NotificationManager::class.java) ?: return false
        return manager.canUseFullScreenIntent()
    }

    fun isLocked(context: Context): Boolean {
        val keyguard = context.getSystemService(Context.KEYGUARD_SERVICE) as KeyguardManager
        return keyguard.isKeyguardLocked
    }
}
