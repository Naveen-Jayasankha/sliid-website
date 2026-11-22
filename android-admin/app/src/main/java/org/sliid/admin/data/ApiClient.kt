package org.sliid.admin.data

import android.content.Context
import android.os.Build
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import org.json.JSONObject
import org.sliid.admin.BuildConfig
import java.io.IOException
import java.io.File
import java.util.concurrent.TimeUnit

class ApiException(message: String, val status: Int = 0) : IOException(message)

class ApiClient(context: Context) {
    private val session = SessionManager(context)
    private val jsonType = "application/json; charset=utf-8".toMediaType()
    private val client = OkHttpClient.Builder()
        .connectTimeout(15, TimeUnit.SECONDS)
        .readTimeout(25, TimeUnit.SECONDS)
        .build()

    private suspend fun call(action: String, method: String = "GET", body: JSONObject? = null, query: String = ""): JSONObject = withContext(Dispatchers.IO) {
        val suffix = buildString {
            append("?action=").append(action)
            if (query.isNotBlank()) append('&').append(query)
        }
        val builder = Request.Builder().url(BuildConfig.API_BASE_URL + suffix).header("Accept", "application/json")
        session.token?.let { builder.header("Authorization", "Bearer $it") }
        if (method == "POST") builder.post((body ?: JSONObject()).toString().toRequestBody(jsonType))
        val response = client.newCall(builder.build()).execute()
        val text = response.body?.string().orEmpty()
        val payload = runCatching { JSONObject(text) }.getOrElse { JSONObject().put("ok", false).put("message", "Invalid server response.") }
        if (!response.isSuccessful || !payload.optBoolean("ok")) throw ApiException(payload.optString("message", "Request failed."), response.code)
        payload
    }

    suspend fun login(email: String, password: String): JSONObject {
        val payload = call("login", "POST", JSONObject().put("email", email).put("password", password).put("device_name", "${Build.MANUFACTURER} ${Build.MODEL}"))
        session.token = payload.getString("token")
        session.adminName = payload.getJSONObject("admin").getString("name")
        return payload
    }
    suspend fun dashboard() = call("dashboard")
    suspend fun bookings(status: String = "") = call("bookings", query = if (status.isBlank()) "" else "status=$status")
    suspend fun booking(id: Int) = call("booking", query = "id=$id")
    suspend fun ticket(value: String) = call("ticket", query = "value=" + java.net.URLEncoder.encode(value, "UTF-8"))
    suspend fun checkIn(value: String) = call("check-in", "POST", JSONObject().put("value", value))
    suspend fun updateBooking(id: Int, status: String, notes: String) = call("booking-status", "POST", JSONObject().put("id", id).put("status", status).put("notes", notes))
    suspend fun registerDevice(fcmToken: String) = call("device", "POST", JSONObject().put("fcm_token", fcmToken).put("device_name", "${Build.MANUFACTURER} ${Build.MODEL}"))
    suspend fun downloadReceipt(id: Int, cacheDir: File): File = withContext(Dispatchers.IO) {
        val builder=Request.Builder().url(BuildConfig.API_BASE_URL+"?action=receipt&id=$id")
        session.token?.let{builder.header("Authorization","Bearer $it")}
        val response=client.newCall(builder.build()).execute()
        if(!response.isSuccessful) throw ApiException("Unable to open receipt.",response.code)
        val file=File(cacheDir,"SLIID-receipt-$id.pdf")
        response.body?.byteStream()?.use{input->file.outputStream().use{output->input.copyTo(output)}} ?: throw ApiException("Receipt is empty.")
        file
    }
    suspend fun logout() { runCatching { call("logout", "POST") }; session.clear() }
}
