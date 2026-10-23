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

    val isEnrolled: Boolean
        get() = !serverUrl.isNullOrBlank() && !deviceToken.isNullOrBlank()

    fun clear() = sp.edit().clear().apply()
}
