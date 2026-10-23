package com.cyko.remotewake

import android.os.Build
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.os.SystemClock
import android.view.WindowManager
import androidx.appcompat.app.AppCompatActivity

/**
 * A transparent, no-UI activity. Being shown is what turns the display on; it then
 * closes itself. On API 27+ we use the official setTurnScreenOn / setShowWhenLocked
 * APIs, with window flags as a fallback for good measure.
 */
class WakeActivity : AppCompatActivity() {

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        shownAt = SystemClock.elapsedRealtime()

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O_MR1) {
            setShowWhenLocked(true)
            setTurnScreenOn(true)
        }
        @Suppress("DEPRECATION")
        window.addFlags(
            WindowManager.LayoutParams.FLAG_TURN_SCREEN_ON or
                WindowManager.LayoutParams.FLAG_SHOW_WHEN_LOCKED or
                WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON,
        )

        // The screen is on now; nothing to show. Linger briefly so the display
        // actually powers up, then finish. We deliberately do NOT dismiss the
        // keyguard — the user still unlocks normally. Holding the screen on for
        // the user's chosen duration happens after that unlock, in KeepAwakeManager.
        val keepAwakeSeconds = intent.getIntExtra(EXTRA_KEEP_AWAKE_SECONDS, 0)
        if (keepAwakeSeconds > 0) KeepAwakeManager.arm(this, keepAwakeSeconds)

        Handler(Looper.getMainLooper()).postDelayed({ finish() }, 3000)
    }

    companion object {
        const val EXTRA_KEEP_AWAKE_SECONDS = "keepAwakeSeconds"

        /** elapsedRealtime of the last time this activity was actually shown. */
        @Volatile
        private var shownAt = 0L

        /** Did the activity come up since [since]? Tells a silent background-start refusal apart from a success. */
        fun shownSince(since: Long): Boolean = shownAt >= since
    }
}
