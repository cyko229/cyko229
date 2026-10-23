package com.cyko.remotewake

import android.content.Context
import android.util.Log
import org.json.JSONObject
import java.security.MessageDigest
import javax.crypto.Mac
import javax.crypto.spec.SecretKeySpec

/**
 * Validates and runs the commands the relay sends, whichever channel they came
 * in on (WebSocket or FCM push).
 *
 * Only two actions exist, and each command must carry an HMAC computed with
 * this phone's device token — the secret it was given when pairing. So a push
 * is executed only if it genuinely came from the relay this phone is paired
 * with: a stolen Firebase key alone cannot drive the device.
 */
object CommandHandler {

    private const val TAG = "RemoteWakeCmd"

    /** Commands older than this are refused: a stale wake helps nobody. */
    private const val MAX_AGE_MS = 2 * 60 * 1000L

    private val ALLOWED_ACTIONS = setOf("wake", "lock")

    /** Recently executed ids — FCM may deliver the same message more than once. */
    private val recentIds = object : LinkedHashMap<String, Long>(16, 0.75f, true) {
        override fun removeEldestEntry(eldest: MutableMap.MutableEntry<String, Long>): Boolean = size > 32
    }

    data class Command(
        val commandId: String,
        val action: String,
        val issuedAt: Long,
        val keepAwakeSeconds: Int,
        val signature: String?,
    )

    data class Result(val ok: Boolean, val detail: String? = null)

    fun parse(json: JSONObject): Command = Command(
        commandId = json.optString("commandId"),
        action = json.optString("action"),
        issuedAt = json.optLong("issuedAt"),
        keepAwakeSeconds = json.optInt("keepAwakeSeconds", 0),
        signature = json.optString("sig").ifEmpty { null },
    )

    fun parse(data: Map<String, String>): Command = Command(
        commandId = data["commandId"].orEmpty(),
        action = data["action"].orEmpty(),
        issuedAt = data["issuedAt"]?.toLongOrNull() ?: 0L,
        keepAwakeSeconds = data["keepAwakeSeconds"]?.toIntOrNull() ?: 0,
        signature = data["sig"],
    )

    /**
     * Runs the command if it checks out. [requireSignature] is true for pushes,
     * which arrive over Google's infrastructure; on the WebSocket the token was
     * already proven at connection time, so an older server that does not sign
     * is still accepted there.
     */
    @Synchronized
    fun execute(context: Context, command: Command, requireSignature: Boolean): Result {
        val deviceToken = Prefs(context).deviceToken ?: return Result(false, "not paired")

        if (command.commandId.isEmpty()) return Result(false, "missing command id")
        if (command.action !in ALLOWED_ACTIONS) {
            Log.w(TAG, "refused unknown action '${command.action}'")
            return Result(false, "unknown action")
        }
        if (command.issuedAt > 0 && Math.abs(System.currentTimeMillis() - command.issuedAt) > MAX_AGE_MS) {
            Log.w(TAG, "refused stale command ${command.commandId}")
            return Result(false, "stale command")
        }
        if (command.signature != null) {
            if (!verify(deviceToken, command)) {
                Log.w(TAG, "refused command with bad signature")
                return Result(false, "bad signature")
            }
        } else if (requireSignature) {
            Log.w(TAG, "refused unsigned push command")
            return Result(false, "unsigned command")
        }

        val now = System.currentTimeMillis()
        recentIds.entries.removeAll { now - it.value > MAX_AGE_MS }
        if (recentIds.put(command.commandId, now) != null) {
            Log.i(TAG, "ignoring duplicate delivery of ${command.commandId}")
            return Result(true, "duplicate")
        }

        return when (command.action) {
            "wake" -> {
                // Use the duration this command carries. The stored preference is
                // only ever written from a server "config" frame, so a one-off
                // override from the web app does not overwrite the saved setting.
                ScreenController.wake(
                    context,
                    command.keepAwakeSeconds.coerceIn(0, Prefs.MAX_KEEP_AWAKE_SECONDS),
                )
                Result(true)
            }
            "lock" -> {
                // Locking blanks the screen: any hold in progress is moot.
                KeepAwakeManager.release(context)
                if (ScreenController.lock(context)) Result(true)
                else Result(false, "device admin not enabled")
            }
            else -> Result(false, "unknown action")
        }
    }

    private fun verify(deviceToken: String, command: Command): Boolean {
        val canonical = listOf(
            command.commandId,
            command.action,
            command.issuedAt.toString(),
            command.keepAwakeSeconds.toString(),
        ).joinToString(".")
        val mac = Mac.getInstance("HmacSHA256").apply {
            init(SecretKeySpec(deviceToken.toByteArray(Charsets.UTF_8), "HmacSHA256"))
        }
        val expected = mac.doFinal(canonical.toByteArray(Charsets.UTF_8))
            .joinToString("") { "%02x".format(it) }
        return MessageDigest.isEqual(
            expected.toByteArray(Charsets.UTF_8),
            command.signature.orEmpty().toByteArray(Charsets.UTF_8),
        )
    }
}
