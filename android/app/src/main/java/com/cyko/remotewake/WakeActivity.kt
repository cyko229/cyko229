package com.cyko.remotewake

import android.os.Build
import android.os.Bundle
import android.os.Handler
import android.os.Looper
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
        // keyguard — the user still unlocks normally.
        Handler(Looper.getMainLooper()).postDelayed({ finish() }, 3000)
    }
}
