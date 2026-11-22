package org.sliid.admin.notifications

import android.app.PendingIntent
import android.content.Intent
import androidx.core.app.NotificationCompat
import androidx.core.app.NotificationManagerCompat
import com.google.firebase.messaging.FirebaseMessagingService
import com.google.firebase.messaging.RemoteMessage
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import org.sliid.admin.MainActivity
import org.sliid.admin.R
import org.sliid.admin.data.ApiClient
import org.sliid.admin.data.SessionManager

class SliidMessagingService : FirebaseMessagingService() {
    override fun onNewToken(token: String) {
        if (SessionManager(this).token != null) CoroutineScope(Dispatchers.IO).launch {
            runCatching { ApiClient(this@SliidMessagingService).registerDevice(token) }
        }
    }

    override fun onMessageReceived(message: RemoteMessage) {
        val intent = Intent(this, MainActivity::class.java).apply {
            flags = Intent.FLAG_ACTIVITY_CLEAR_TOP or Intent.FLAG_ACTIVITY_SINGLE_TOP
            putExtra("destination", if (message.data["type"] == "booking") "bookings" else "dashboard")
            message.data["booking_id"]?.toIntOrNull()?.let { putExtra("booking_id", it) }
        }
        val pending = PendingIntent.getActivity(this, message.data["booking_id"]?.hashCode() ?: 0, intent, PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE)
        val notification = NotificationCompat.Builder(this, "sliid_admin_alerts")
            .setSmallIcon(android.R.drawable.ic_dialog_info)
            .setContentTitle(message.notification?.title ?: "SLIID Admin")
            .setContentText(message.notification?.body ?: "New administration update")
            .setStyle(NotificationCompat.BigTextStyle().bigText(message.notification?.body))
            .setPriority(NotificationCompat.PRIORITY_HIGH)
            .setAutoCancel(true)
            .setContentIntent(pending)
            .setSound(android.net.Uri.parse("android.resource://$packageName/${R.raw.sliid_alert}"))
            .build()
        NotificationManagerCompat.from(this).notify(System.currentTimeMillis().toInt(), notification)
    }
}
