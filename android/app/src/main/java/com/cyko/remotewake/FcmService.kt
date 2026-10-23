package com.cyko.remotewake

import android.content.Context
import android.util.Log
import com.google.firebase.FirebaseApp
import com.google.firebase.messaging.FirebaseMessaging
import com.google.firebase.messaging.FirebaseMessagingService
import com.google.firebase.messaging.RemoteMessage

/**
 * Receives the relay's push commands.
 *
 * Why this exists: with the screen off for a while the phone enters Doze and the
 * WebSocket is suspended until the next maintenance window — a remote wake would
 * arrive minutes late, or not at all. A *high-priority data message* is the one
 * thing Android still delivers immediately in that state, so FCM is the primary
 * channel and the socket is the low-latency path while the phone is awake.
 *
 * Only signed commands for the two known actions are executed; everything else
 * is dropped. See [CommandHandler].
 *
 * FCM is optional: without `google-services.json` Firebase stays uninitialised,
 * [isAvailable] returns false and the app runs on the WebSocket alone.
 */
class FcmService : FirebaseMessagingService() {

    override fun onMessageReceived(message: RemoteMessage) {
        val data = message.data
        if (data["type"] != "command") {
            Log.i(TAG, "ignoring push of type '${data["type"]}'")
            return
        }
        if (!Prefs(this).isEnrolled) {
            Log.w(TAG, "push received while not paired — ignoring")
            return
        }

        val command = CommandHandler.parse(data)
        // A push travels through Google's servers, so the signature is what proves
        // it came from our relay: unsigned commands are refused here.
        val result = CommandHandler.execute(applicationContext, command, requireSignature = true)
        Log.i(TAG, "push command '${command.action}' -> ok=${result.ok} ${result.detail ?: ""}")

        Relay.sendAck(applicationContext, command.commandId, result.ok, result.detail)

        // The push also means the socket is stale; bring it back for follow-ups.
        if (result.ok) ConnectionService.start(applicationContext)
    }

    override fun onNewToken(token: String) {
        Log.i(TAG, "FCM registration token refreshed")
        if (Prefs(this).isEnrolled) Relay.sendFcmToken(applicationContext, token)
    }

    companion object {
        private const val TAG = "RemoteWakeFcm"

        /** True when the build actually carries a Firebase configuration. */
        fun isAvailable(context: Context): Boolean =
            runCatching { FirebaseApp.getApps(context).isNotEmpty() }.getOrDefault(false)

        /**
         * Hands the current registration token to the relay. Safe to call often:
         * it is a no-op when Firebase is absent or the server already has it.
         */
        fun refreshToken(context: Context, force: Boolean = false) {
            val prefs = Prefs(context)
            if (!prefs.isEnrolled || !isAvailable(context)) return
            runCatching {
                FirebaseMessaging.getInstance().token.addOnCompleteListener { task ->
                    if (!task.isSuccessful) {
                        Log.w(TAG, "could not get FCM token: ${task.exception?.message}")
                        return@addOnCompleteListener
                    }
                    val token = task.result ?: return@addOnCompleteListener
                    if (force || token != prefs.reportedFcmToken) {
                        Relay.sendFcmToken(context.applicationContext, token)
                    }
                }
            }.onFailure { Log.w(TAG, "FCM unavailable: ${it.message}") }
        }
    }
}
