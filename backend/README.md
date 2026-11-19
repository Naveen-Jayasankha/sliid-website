# SLIID PHP/MySQL Backend

Production-ready backend source for the Sri Lanka Institute of Interior Designers website. It is designed for PHP 8.1+ and MySQL 8/MariaDB on cPanel.

## Included

- Secure browser installer
- Session-based administrator login
- Super administrator and editor roles
- Member directory management
- News, event and publication management
- Free and paid event bookings with member/non-member pricing and capacity limits
- Bank-transfer receipt PDF upload and administrator verification
- Contact enquiry inbox
- Membership application workflow
- PDF and image uploads with MIME validation, including multi-image event galleries
- Public JSON API for the GitHub Pages frontend
- CORS allowlist, CSRF protection and prepared SQL statements
- Submission rate limiting and administrator audit logs

## cPanel deployment

1. Set the domain to PHP 8.1 or newer. The initial deployment can use `https://sliid.org/backend`.
2. Create a MySQL database and user, then grant all database privileges.
3. Upload this `backend` directory inside the website document root.
4. Confirm that PHP extensions `pdo_mysql`, `fileinfo`, `json` and `mbstring` are enabled.
5. Open `https://sliid.org/backend/install.php` and complete the installer.
6. Confirm that `.installed` and `config.php` exist. Use `0600` or `0640` permissions for `config.php` where supported.
7. Sign in through `https://sliid.org/backend/admin.php`.
8. Update `dist/api-config.json` in GitHub with the deployed API URL:

```json
{
  "apiBase": "/backend"
}
```

9. Commit the configuration. GitHub Pages will redeploy and begin loading live data.

## Public API

All requests use `api.php?resource=...`.

| Method | Resource | Purpose |
| --- | --- | --- |
| GET | `health` | Database and API health check |
| GET | `members` | Search active directory members |
| GET | `news` | Published news and articles |
| GET | `events` | Upcoming or past published events |
| GET | `publications` | Published documents |
| POST | `contact` | Save public enquiries |
| POST | `membership-applications` | Save applications and optional document |
| POST | `event-bookings` | Create a free booking or submit a paid booking with receipt PDF |

Member filters include `q`, `category`, `location`, `page` and `limit`. Events accept `past=1`. News accepts `slug`.

Existing installations are upgraded automatically when the Events admin or API is opened. The upgrade creates the event gallery, booking and participant tables and adds event pricing fields. It also adds the optional event `tags` field. Tags are displayed as hashtags and included in Event structured data for search engines.

To enable booking for an event, edit it in **Events**, select **Enable bookings on the website**, choose free or paid pricing, and publish it. Paid bookings currently use bank transfer. Receipt PDFs appear under **Event bookings** for verification. Card payment is intentionally shown as coming soon until a gateway is connected.

## Security

- Never commit `config.php` or `.installed`; both are ignored by Git.
- Keep the installer locked after installation.
- Use HTTPS for the API and public website.
- Back up both the database and `uploads` folder.
- Receipt files are stored under `uploads/receipts` with direct web access denied; administrators open them through the authenticated booking screen.
- Keep PHP and cPanel packages updated.
