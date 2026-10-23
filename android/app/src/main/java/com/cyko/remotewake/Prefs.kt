package com.cyko.remotewake

import android.content.Context

/** Small typed wrapper over SharedPreferences for the enrolment state. */
class Prefs(context: Context) {
    private val sp = context.getSharedPreferences("remotewake", Context.MODE_PRIVATE)

    var serverUrl: String?
        get() = sp.getString("serverUrl", null)
        set(v) = sp.edit().putString("serverUrl", v).apply()

    var deviceToken: String?
        get() = sp.getString("deviceToken", null)
        set(v) = sp.edit().putString("deviceToken", v).apply()

    var deviceId: String?
        get() = sp.getString("deviceId", null)
        set(v) = sp.edit().putString("deviceId", v).apply()

    var deviceName: String?
        get() = sp.getString("deviceName", null)
        set(v) = sp.edit().putString("deviceName", v).apply()

    /** How long to hold the screen on after an unlock, in seconds. 0 disables it. */
    var keepAwakeSeconds: Int
        get() = sp.getInt("keepAwakeSeconds", DEFAULT_KEEP_AWAKE_SECONDS)
        set(v) = sp.edit().putInt("keepAwakeSeconds", v.coerceIn(0, MAX_KEEP_AWAKE_SECONDS)).apply()

    /** Last FCM registration token we successfully handed to the server. */
    var reportedFcmToken: String?
        get() = sp.getString("reportedFcmToken", null)
        set(v) = sp.edit().putString("reportedFcmToken", v).apply()

    /**
     * Screen-off timeout to put back once a keep-awake hold ends. Persisted so a
     * process death cannot leave the device with a permanently raised timeout.
     */
    var savedScreenTimeout: Int
        get() = sp.getInt("savedScreenTimeout", -1)
        set(v) = sp.edit().putInt("savedScreenTimeout", v).apply()

    val isEnrolled: Boolean
        get() = !serverUrl.isNullOrBlank() && !deviceToken.isNullOrBlank()

    fun clear() = sp.edit().clear().apply()

    companion object {
        const val DEFAULT_KEEP_AWAKE_SECONDS = 120
        const val MAX_KEEP_AWAKE_SECONDS = 3600
    }
}
