package com.cyko.remotewake

import android.annotation.SuppressLint
import android.app.admin.DevicePolicyManager
import android.content.ComponentName
import android.content.Intent
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.provider.Settings
import android.widget.Toast
import androidx.appcompat.app.AppCompatActivity
import com.cyko.remotewake.databinding.ActivityMainBinding
import okhttp3.Call
import okhttp3.Callback
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import okhttp3.Response
import org.json.JSONObject
import java.io.IOException

class MainActivity : AppCompatActivity() {

    private lateinit var binding: ActivityMainBinding
    private lateinit var prefs: Prefs
    private val http = OkHttpClient()

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityMainBinding.inflate(layoutInflater)
        setContentView(binding.root)
        prefs = Prefs(this)

        binding.pairBtn.setOnClickListener { pair() }
        binding.adminBtn.setOnClickListener { requestDeviceAdmin() }
        binding.batteryBtn.setOnClickListener { requestIgnoreBatteryOptimizations() }
        binding.unpairBtn.setOnClickListener { unpair() }

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            requestPermissions(arrayOf(android.Manifest.permission.POST_NOTIFICATIONS), 1)
        }
    }

    override fun onResume() {
        super.onResume()
        render()
    }

    @SuppressLint("SetTextI18n")
    private fun render() {
        val enrolled = prefs.isEnrolled
        binding.enrolForm.visibility = if (enrolled) android.view.View.GONE else android.view.View.VISIBLE
        binding.setupButtons.visibility = if (enrolled) android.view.View.VISIBLE else android.view.View.GONE

        val admin = isAdminActive()
        if (enrolled) {
            binding.status.text = buildString {
                append("✓ Associé en tant que « ${prefs.deviceName} »\n")
                append("Serveur : ${prefs.serverUrl}\n\n")
                append(if (admin) "✓ Verrouillage à distance activé" else "⚠ Verrouillage à distance NON activé (bouton ci-dessous)")
            }
        } else {
            binding.status.text = "Non associé. Générez un code depuis l'application web, puis saisissez l'adresse du serveur et le code ci-dessous."
        }
    }

    private fun pair() {
        val server = binding.serverUrl.text.toString().trim().trimEnd('/')
        val code = binding.pairingCode.text.toString().trim()
        if (server.isEmpty() || code.length != 6) {
            toast("Adresse du serveur et code à 6 chiffres requis.")
            return
        }
        val body = JSONObject().put("code", code).toString()
            .toRequestBody("application/json".toMediaType())
        val req = Request.Builder().url("$server/api/pairings/redeem").post(body).build()

        http.newCall(req).enqueue(object : Callback {
            override fun onFailure(call: Call, e: IOException) = runOnUiThread {
                toast("Connexion au serveur impossible : ${e.message}")
            }

            override fun onResponse(call: Call, response: Response) {
                val text = response.body?.string().orEmpty()
                runOnUiThread {
                    if (!response.isSuccessful) {
                        val err = runCatching { JSONObject(text).optString("error") }.getOrNull()
                        toast("Échec de l'association : ${err ?: response.code}")
                        return@runOnUiThread
                    }
                    val json = JSONObject(text)
                    prefs.serverUrl = server
                    prefs.deviceToken = json.getString("deviceToken")
                    prefs.deviceId = json.getString("deviceId")
                    prefs.deviceName = json.optString("name", "Mon téléphone")
                    ConnectionService.start(this@MainActivity)
                    toast("Appareil associé ✓")
                    render()
                }
            }
        })
    }

    private fun unpair() {
        ConnectionService.stop(this)
        removeDeviceAdmin()
        prefs.clear()
        toast("Appareil dissocié.")
        render()
    }

    // --- device admin (needed for remote lock) ---

    private fun adminComponent() = ComponentName(this, AdminReceiver::class.java)

    private fun isAdminActive(): Boolean {
        val dpm = getSystemService(DEVICE_POLICY_SERVICE) as DevicePolicyManager
        return dpm.isAdminActive(adminComponent())
    }

    private fun requestDeviceAdmin() {
        if (isAdminActive()) {
            toast("Déjà activé.")
            return
        }
        val intent = Intent(DevicePolicyManager.ACTION_ADD_DEVICE_ADMIN).apply {
            putExtra(DevicePolicyManager.EXTRA_DEVICE_ADMIN, adminComponent())
            putExtra(
                DevicePolicyManager.EXTRA_ADD_EXPLANATION,
                getString(R.string.admin_explanation),
            )
        }
        startActivity(intent)
    }

    private fun removeDeviceAdmin() {
        val dpm = getSystemService(DEVICE_POLICY_SERVICE) as DevicePolicyManager
        if (dpm.isAdminActive(adminComponent())) dpm.removeActiveAdmin(adminComponent())
    }

    @SuppressLint("BatteryLife")
    private fun requestIgnoreBatteryOptimizations() {
        val intent = Intent(
            Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS,
            Uri.parse("package:$packageName"),
        )
        startActivity(intent)
    }

    private fun toast(msg: String) = Toast.makeText(this, msg, Toast.LENGTH_LONG).show()
}
