package org.sliid.admin.data

import android.content.Context
import androidx.security.crypto.EncryptedSharedPreferences
import androidx.security.crypto.MasterKey

class SessionManager(context: Context) {
    private val masterKey = MasterKey.Builder(context).setKeyScheme(MasterKey.KeyScheme.AES256_GCM).build()
    private val prefs = EncryptedSharedPreferences.create(
        context,
        "sliid_admin_secure",
        masterKey,
        EncryptedSharedPreferences.PrefKeyEncryptionScheme.AES256_SIV,
        EncryptedSharedPreferences.PrefValueEncryptionScheme.AES256_GCM,
    )

    var token: String?
        get() = prefs.getString("api_token", null)
        set(value) { prefs.edit().putString("api_token", value).apply() }
    var adminName: String
        get() = prefs.getString("admin_name", "Administrator") ?: "Administrator"
        set(value) { prefs.edit().putString("admin_name", value).apply() }

    fun clear() = prefs.edit().clear().apply()
}
