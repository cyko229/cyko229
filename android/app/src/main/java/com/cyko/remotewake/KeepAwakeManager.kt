package com.cyko.remotewake

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.content.IntentFilter
import android.os.Handler
import android.os.Looper
import android.os.PowerManager
import android.provider.Settings
import android.util.Log
import androidx.core.content.ContextCompat

/**
 * Keeps the screen on for a user-chosen duration **after the phone is unlocked**.
 *
 * A remote "wake" lights the display but leaves the keyguard up, so holding the
 * screen on right then would just burn battery on a lock screen. Instead we *arm*
 * on the wake command and only *engage* when ACTION_USER_PRESENT tells us the
 * user actually got in.
 *
 * Two ways to hold it, best first:
 *  1. Raise [Settings.System.SCREEN_OFF_TIMEOUT] and restore it afterwards. The
 *     phone behaves completely normally in the meantime — but it needs the
 *     WRITE_SETTINGS grant.
 *  2. A SCREEN_BRIGHT_WAKE_LOCK with a timeout. No extra permission, works
 *     everywhere, and the system releases it if the user presses the power
 *     button — so the user always keeps the last word.
 */
object KeepAwakeManager {

    private const val TAG = "RemoteWakeKeep"

    /** How long we wait for an unlock after a remote wake before giving up. */
    private const val ARM_WINDOW_MS = 5 * 60 * 1000L

    private val handler = Handler(Looper.getMainLooper())

    // Separate tokens so cancelling one scheduled job never cancels the other.
    private val releaseToken = Any()
    private val disarmToken = Any()

    private var wakeLock: PowerManager.WakeLock? = null
    private var receiver: BroadcastReceiver? = null

    private var armedSeconds = 0
    private var armedUntilMs = 0L

    /** Epoch ms until which the screen is being held on, or 0 when idle. */
    @Volatile
    var holdUntilMs = 0L
        private set

    /** True while we are waiting for the user to unlock after a remote wake. */
    val isArmed: Boolean
        get() = armedUntilMs > System.currentTimeMillis()

    /** Called right after a remote wake: hold the screen once the user unlocks. */
    @Synchronized
    fun arm(context: Context, seconds: Int) {
        if (seconds <= 0) return
        armedSeconds = seconds.coerceIn(0, Prefs.MAX_KEEP_AWAKE_SECONDS)
        armedUntilMs = System.currentTimeMillis() + ARM_WINDOW_MS
        registerUserPresent(context.applicationContext)
        // Stop listening if the user never unlocks. Re-armed each time, so a
        // second wake command extends the window instead of leaking a receiver.
        handler.removeCallbacksAndMessages(disarmToken)
        postDelayed(disarmToken, ARM_WINDOW_MS + 1_000L) { if (!isArmed) disarm(context) }

        // If the phone is already unlocked, the unlock broadcast will never come.
        val keyguard = context.getSystemService(Context.KEYGUARD_SERVICE) as android.app.KeyguardManager
        if (!keyguard.isKeyguardLocked) {
            Log.i(TAG, "already unlocked, engaging now")
            engage(context, armedSeconds)
        }
    }

    /** The user unlocked the phone. Hold the screen if a remote wake armed us. */
    @Synchronized
    fun onUserPresent(context: Context) {
        if (!isArmed) {
            disarm(context)
            return
        }
        val seconds = armedSeconds
        disarm(context)
        engage(context, seconds)
    }

    /** Starts holding the screen on for [seconds]. */
    @Synchronized
    fun engage(context: Context, seconds: Int) {
        if (seconds <= 0) return
        val app = context.applicationContext
        val durationMs = seconds * 1000L
        holdUntilMs = System.currentTimeMillis() + durationMs

        if (raiseScreenTimeout(app, seconds)) {
            Log.i(TAG, "holding screen via screen-off timeout for ${seconds}s")
        } else {
            acquireWakeLock(app, durationMs)
            Log.i(TAG, "holding screen via wake lock for ${seconds}s")
        }

        handler.removeCallbacksAndMessages(releaseToken)
        postDelayed(releaseToken, durationMs) { release(app) }
        ConnectionService.reportStatus(app)
    }

    /** Stops holding the screen and puts the system setting back. */
    @Synchronized
    fun release(context: Context) {
        val app = context.applicationContext
        handler.removeCallbacksAndMessages(releaseToken)
        restoreScreenTimeout(app)
        wakeLock?.let { if (it.isHeld) runCatching { it.release() } }
        wakeLock = null
        if (holdUntilMs != 0L) {
            holdUntilMs = 0L
            ConnectionService.reportStatus(app)
        }
    }

    /** Called at service start: undo a raised timeout left behind by a crash. */
    fun restoreAfterRestart(context: Context) {
        if (holdUntilMs == 0L) restoreScreenTimeout(context.applicationContext)
    }

    // --- mechanism 1: the system screen-off timeout ---

    fun canWriteSettings(context: Context): Boolean = Settings.System.canWrite(context)

    private fun raiseScreenTimeout(context: Context, seconds: Int): Boolean {
        if (!canWriteSettings(context)) return false
        return runCatching {
            val prefs = Prefs(context)
            if (prefs.savedScreenTimeout < 0) {
                prefs.savedScreenTimeout =
                    Settings.System.getInt(context.contentResolver, Settings.System.SCREEN_OFF_TIMEOUT, 30_000)
            }
            // A small margin so the display does not blink off a hair early.
            Settings.System.putInt(
                context.contentResolver,
                Settings.System.SCREEN_OFF_TIMEOUT,
                seconds * 1000 + 5_000,
            )
            true
        }.getOrElse {
            Log.w(TAG, "could not raise screen timeout: ${it.message}")
            false
        }
    }

    private fun restoreScreenTimeout(context: Context) {
        val prefs = Prefs(context)
        val saved = prefs.savedScreenTimeout
        if (saved < 0) return
        runCatching {
            Settings.System.putInt(context.contentResolver, Settings.System.SCREEN_OFF_TIMEOUT, saved)
        }.onFailure { Log.w(TAG, "could not restore screen timeout: ${it.message}") }
        prefs.savedScreenTimeout = -1
    }

    // --- mechanism 2: a screen wake lock ---

    @Suppress("DEPRECATION") // SCREEN_BRIGHT_WAKE_LOCK is the only no-permission option
    private fun acquireWakeLock(context: Context, durationMs: Long) {
        val pm = context.getSystemService(Context.POWER_SERVICE) as PowerManager
        wakeLock?.let { if (it.isHeld) runCatching { it.release() } }
        wakeLock = pm.newWakeLock(
            PowerManager.SCREEN_BRIGHT_WAKE_LOCK or PowerManager.ON_AFTER_RELEASE,
            "RemoteWake:keepAwake",
        ).apply {
            setReferenceCounted(false)
            // The timeout is a safety net; we also release it ourselves.
            acquire(durationMs + 2_000L)
        }
    }

    // --- unlock detection ---

    private fun registerUserPresent(context: Context) {
        if (receiver != null) return
        val r = object : BroadcastReceiver() {
            override fun onReceive(ctx: Context, intent: Intent) {
                if (intent.action == Intent.ACTION_USER_PRESENT) onUserPresent(ctx)
            }
        }
        receiver = r
        ContextCompat.registerReceiver(
            context,
            r,
            IntentFilter(Intent.ACTION_USER_PRESENT),
            ContextCompat.RECEIVER_NOT_EXPORTED,
        )
    }

    @Synchronized
    private fun disarm(context: Context) {
        armedUntilMs = 0L
        armedSeconds = 0
        handler.removeCallbacksAndMessages(disarmToken)
        receiver?.let { runCatching { context.applicationContext.unregisterReceiver(it) } }
        receiver = null
    }

    private fun postDelayed(token: Any, delayMs: Long, action: () -> Unit) {
        handler.postAtTime({ action() }, token, android.os.SystemClock.uptimeMillis() + delayMs)
    }
}
