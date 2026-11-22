package org.sliid.admin

import android.app.Application
import android.app.NotificationChannel
import android.app.NotificationManager
import android.media.AudioAttributes
import android.net.Uri
import android.os.Build

class SliidAdminApp : Application() {
    override fun onCreate() {
        super.onCreate()
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            val sound = Uri.parse("android.resource://$packageName/${R.raw.sliid_alert}")
            val channel = NotificationChannel("sliid_admin_alerts", "SLIID admin alerts", NotificationManager.IMPORTANCE_HIGH).apply {
                description = "New bookings, receipts and event administration alerts"
                enableVibration(true)
                setSound(sound, AudioAttributes.Builder().setUsage(AudioAttributes.USAGE_NOTIFICATION).build())
            }
            getSystemService(NotificationManager::class.java).createNotificationChannel(channel)
        }
    }
}
