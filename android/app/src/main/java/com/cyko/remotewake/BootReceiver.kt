package com.cyko.remotewake

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent

/** Restarts the connection service after a reboot so the phone comes back online on its own. */
class BootReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        if (intent.action == Intent.ACTION_BOOT_COMPLETED && Prefs(context).isEnrolled) {
            ConnectionService.start(context)
        }
    }
}
