package com.cyko.remotewake

import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.Service
import android.content.Context
import android.content.Intent
import android.os.Build
import android.os.IBinder
import android.util.Log
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.Response
import okhttp3.WebSocket
import okhttp3.WebSocketListener
import org.json.JSONObject
import java.util.concurrent.TimeUnit

/**
 * Long-lived foreground service. It keeps an authenticated WebSocket open to the relay
 * server and executes "wake" / "lock" commands the moment they arrive. OkHttp sends
 * periodic pings and we reconnect with backoff on any drop.
 *
 * NOTE: a persistent socket is the zero-setup path and works well when the app is exempt
 * from battery optimisation. In deep Doze the socket is suspended, which is why the app
 * also registers for Firebase Cloud Messaging: a high-priority push still gets through,
 * and [FcmService] runs the very same command path (see docs/android-capabilities.md).
 */
class ConnectionService : Service() {

    private lateinit var prefs: Prefs
    private var client: OkHttpClient? = null
    private var socket: WebSocket? = null
    private var shuttingDown = false
    private var backoffMs = 1000L

    override fun onCreate() {
        super.onCreate()
        prefs = Prefs(this)
        startInForeground()
        // A crash could have left the screen-off timeout raised; put it back.
        KeepAwakeManager.restoreAfterRestart(this)
        // Make sure the relay has a current push token for this install.
        FcmService.refreshToken(this)
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        if (prefs.isEnrolled) connect() else stopSelf()
        return START_STICKY
    }

    private fun connect() {
        if (shuttingDown) return
        val server = prefs.serverUrl ?: return
        val token = prefs.deviceToken ?: return

        // http(s)://host  ->  ws(s)://host/device?token=...
        val wsUrl = server.replaceFirst("http", "ws").trimEnd('/') + "/device?token=$token"

        client = OkHttpClient.Builder()
            .pingInterval(25, TimeUnit.SECONDS)
            .retryOnConnectionFailure(true)
            .build()

        socket = client!!.newWebSocket(Request.Builder().url(wsUrl).build(), object : WebSocketListener() {
            override fun onOpen(webSocket: WebSocket, response: Response) {
                Log.i(TAG, "connected")
                backoffMs = 1000L
                liveSocket = webSocket
                // Tell the relay whether a keep-awake hold is still running.
                reportStatus(applicationContext)
            }

            override fun onMessage(webSocket: WebSocket, text: String) {
                handleMessage(webSocket, text)
            }

            override fun onFailure(webSocket: WebSocket, t: Throwable, response: Response?) {
                Log.w(TAG, "socket failure: ${t.message}")
                if (liveSocket === webSocket) liveSocket = null
                scheduleReconnect()
            }

            override fun onClosed(webSocket: WebSocket, code: Int, reason: String) {
                Log.i(TAG, "closed: $code $reason")
                if (liveSocket === webSocket) liveSocket = null
                if (code == 4001) {
                    // Token rejected: we have been de-enrolled server-side. Stop cleanly.
                    prefs.clear()
                    stopSelf()
                } else {
                    scheduleReconnect()
                }
            }
        })
    }

    private fun handleMessage(webSocket: WebSocket, text: String) {
        val msg = runCatching { JSONObject(text) }.getOrNull() ?: return
        when (msg.optString("type")) {
            "command" -> {
                val command = CommandHandler.parse(msg)
                // The token was already proven when this socket was opened, so a
                // signature is welcome here but not required.
                val result = CommandHandler.execute(applicationContext, command, requireSignature = false)
                val ack = JSONObject()
                    .put("type", "ack")
                    .put("commandId", command.commandId)
                    .put("ok", result.ok)
                    .put("keepAwakeUntil", KeepAwakeManager.holdUntilMs)
                if (result.detail != null) ack.put("detail", result.detail)
                webSocket.send(ack.toString())
            }
            // The web app changed the keep-awake duration.
            "config", "welcome" -> {
                if (msg.has("keepAwakeSeconds")) {
                    prefs.keepAwakeSeconds = msg.optInt("keepAwakeSeconds", prefs.keepAwakeSeconds)
                    Log.i(TAG, "keep-awake duration is now ${prefs.keepAwakeSeconds}s")
                }
            }
            "ping" -> webSocket.send(JSONObject().put("type", "pong").toString())
        }
    }

    private fun scheduleReconnect() {
        if (shuttingDown) return
        socket = null
        val delay = backoffMs
        backoffMs = (backoffMs * 2).coerceAtMost(60_000L)
        android.os.Handler(mainLooper).postDelayed({ if (!shuttingDown) connect() }, delay)
    }

    private fun startInForeground() {
        val channelId = "remotewake_connection"
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            val channel = NotificationChannel(
                channelId,
                getString(R.string.channel_name),
                NotificationManager.IMPORTANCE_LOW,
            )
            getSystemService(NotificationManager::class.java).createNotificationChannel(channel)
        }
        val notif: Notification = Notification.Builder(this, channelId)
            .setContentTitle(getString(R.string.notif_title))
            .setContentText(getString(R.string.notif_text))
            .setSmallIcon(android.R.drawable.ic_lock_idle_lock)
            .setOngoing(true)
            .build()

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            startForeground(
                1,
                notif,
                android.content.pm.ServiceInfo.FOREGROUND_SERVICE_TYPE_SPECIAL_USE,
            )
        } else {
            startForeground(1, notif)
        }
    }

    override fun onDestroy() {
        shuttingDown = true
        if (liveSocket === socket) liveSocket = null
        socket?.close(1000, "service stopping")
        client?.dispatcher?.executorService?.shutdown()
        super.onDestroy()
    }

    override fun onBind(intent: Intent?): IBinder? = null

    companion object {
        private const val TAG = "RemoteWakeSvc"

        /** The socket of the running service, if any, for one-off reports. */
        @Volatile
        private var liveSocket: WebSocket? = null

        /** Tells the relay how long the screen is still being held on. */
        fun reportStatus(context: Context) {
            val payload = JSONObject()
                .put("type", "status")
                .put("keepAwakeUntil", KeepAwakeManager.holdUntilMs)
            val sentOverSocket = liveSocket?.send(payload.toString()) ?: false
            if (!sentOverSocket) Relay.sendStatus(context)
        }

        fun start(context: Context) {
            val intent = Intent(context, ConnectionService::class.java)
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                context.startForegroundService(intent)
            } else {
                context.startService(intent)
            }
        }

        fun stop(context: Context) {
            context.stopService(Intent(context, ConnectionService::class.java))
        }
    }
}
