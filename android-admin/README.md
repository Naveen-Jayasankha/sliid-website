# SLIID Admin for Android

Native Android administration app for SLIID events. This is not a WebView and does not embed the browser-based admin panel.

## Included in version 1

- Secure administrator login with a revocable 90-day device token
- Dashboard counts and recent bookings
- Booking and bank-receipt review
- Approve or reject a booking; approval issues and emails e-tickets
- Native CameraX + ML Kit QR scanner
- Ticket lookup, duplicate-check warning and one-tap entrance check-in
- Firebase Cloud Messaging for new booking and receipt alerts
- A custom SLIID two-tone notification sound
- Encrypted on-device session storage

## Firebase files still required

Do not commit either credential to Git.

1. Register Android app `org.sliid.admin` in the Firebase project.
2. Download `google-services.json` and place it at `app/google-services.json`.
3. In Firebase Console, create a service account key for the server.
4. Store that JSON outside `public_html` on cPanel, for example:
   `/home/sliidorg/firebase/sliid-service-account.json`
5. Add this to `backend/config.php`:

```php
'firebase' => [
    'enabled' => true,
    'project_id' => 'FIREBASE_PROJECT_ID',
    'service_account_path' => '/home/sliidorg/firebase/sliid-service-account.json',
],
```

## Build

Open this folder in Android Studio, allow Gradle to sync, select a connected Android phone and click **Run**. Use **Build > Generate Signed App Bundle or APK** for the installable release.

The production API URL is configured in `app/build.gradle.kts` as:
`https://sliid.org/backend/mobile-api.php`.

## QR workflow

1. Open **Scan** in the app and allow camera access.
2. Point the camera at the QR on the attendee's e-ticket.
3. The app retrieves the ticket through the protected mobile API.
4. Verify the name, type, NIC/member number, event and status.
5. Tap **Confirm entrance check-in**.
6. A second scan shows **Already checked in** with its original check-in time.

## Security

- Passwords are checked by the existing PHP backend and never stored in the app.
- API tokens are random, stored hashed on the server and encrypted on Android.
- Receipt PDFs require a valid Bearer token.
- Check-in uses a database transaction to prevent duplicate admission.
- Firebase service credentials remain on the server and never enter the APK.
