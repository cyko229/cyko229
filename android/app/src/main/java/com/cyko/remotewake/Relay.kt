package com.cyko.remotewake

import android.content.Context
import android.util.Log
import okhttp3.Call
import okhttp3.Callback
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import okhttp3.Response
import org.json.JSONObject
import java.io.IOException
import java.util.concurrent.TimeUnit

/**
 * The few plain HTTP calls the phone makes back to the relay. The WebSocket
 * carries everything while it is connected; these cover the case where the
 * phone was woken by a push and has no socket yet.
 */
object Relay {

    private const val TAG = "RemoteWakeRelay"
    private const val JSON = "application/json"

    private val client: OkHttpClient by lazy {
        OkHttpClient.Builder()
            .callTimeout(20, TimeUnit.SECONDS)
            .build()
    }

    /** Fire-and-forget POST authenticated with the device's own token. */
    fun post(context: Context, path: String, body: JSONObject, onDone: ((Boolean) -> Unit)? = null) {
        val prefs = Prefs(context)
        val server = prefs.serverUrl ?: return onDone?.invoke(false) ?: Unit
        val token = prefs.deviceToken ?: return onDone?.invoke(false) ?: Unit

        val request = Request.Builder()
            .url(server.trimEnd('/') + path)
            .header("Authorization", "Bearer $token")
            .post(body.toString().toRequestBody(JSON.toMediaType()))
            .build()

        client.newCall(request).enqueue(object : Callback {
            override fun onFailure(call: Call, e: IOException) {
                Log.w(TAG, "POST $path failed: ${e.message}")
                onDone?.invoke(false)
            }

            override fun onResponse(call: Call, response: Response) {
                response.use {
                    if (!it.isSuccessful) Log.w(TAG, "POST $path -> ${it.code}")
                    onDone?.invoke(it.isSuccessful)
                }
            }
        })
    }

    /** Acknowledges a command that arrived over the push channel. */
    fun sendAck(context: Context, commandId: String, ok: Boolean, detail: String?) {
        val body = JSONObject()
            .put("commandId", commandId)
            .put("ok", ok)
            .put("keepAwakeUntil", KeepAwakeManager.holdUntilMs)
        if (detail != null) body.put("detail", detail)
        post(context, "/api/device/ack", body)
    }

    /** Reports how long the screen is still being held on. */
    fun sendStatus(context: Context) {
        post(context, "/api/device/status", JSONObject().put("keepAwakeUntil", KeepAwakeManager.holdUntilMs))
    }

    /** Registers (or refreshes) this install's FCM token server-side. */
    fun sendFcmToken(context: Context, fcmToken: String) {
        post(context, "/api/device/fcm-token", JSONObject().put("fcmToken", fcmToken)) { ok ->
            if (ok) Prefs(context).reportedFcmToken = fcmToken
        }
    }
}
