# SLIID PHP/MySQL Backend

Production-ready backend source for the Sri Lanka Institute of Interior Designers website. It is designed for PHP 8.1+ and MySQL 8/MariaDB on cPanel.

## Included

- Secure browser installer
- Session-based administrator login
- Super administrator and editor roles
- Member directory management
- News, event and publication management
- Contact enquiry inbox
- Membership application workflow
- PDF and image uploads with MIME validation
- Public JSON API for the GitHub Pages frontend
- CORS allowlist, CSRF protection and prepared SQL statements
- Submission rate limiting and administrator audit logs

## cPanel deployment

1. Create a subdomain such as `api.sliid.lk` and select PHP 8.1 or newer.
2. Create a MySQL database and user, then grant all database privileges.
3. Upload the contents of this `backend` directory to the subdomain document root.
4. Confirm that PHP extensions `pdo_mysql`, `fileinfo`, `json` and `mbstring` are enabled.
5. Open `https://api.sliid.lk/install.php` and complete the installer.
6. Confirm that `.installed` and `config.php` exist. Use `0600` or `0640` permissions for `config.php` where supported.
7. Sign in through `https://api.sliid.lk/admin.php`.
8. Update `dist/api-config.json` in GitHub with the deployed API URL:

```json
{
  "apiBase": "https://api.sliid.lk"
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

Member filters include `q`, `category`, `location`, `page` and `limit`. Events accept `past=1`. News accepts `slug`.

## Security

- Never commit `config.php` or `.installed`; both are ignored by Git.
- Keep the installer locked after installation.
- Use HTTPS for the API and public website.
- Back up both the database and `uploads` folder.
- Keep PHP and cPanel packages updated.
