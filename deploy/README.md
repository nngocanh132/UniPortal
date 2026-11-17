# UniPortal – Cloud deployment (Railway)

This document describes the production layout and the exact steps still
required to publish. Nothing here creates accounts, uploads data or changes
any external system; those steps need the owner's Railway account and an
explicit approval to publish the seeded student data.

## 1. Why Railway

| Option | Verdict |
| --- | --- |
| Railway (Docker + MySQL plugin + volume) | **Chosen.** Runs PHP 8.2/Apache unchanged, managed MySQL, persistent volume, automatic HTTPS, GitHub/CLI deploys, `MYSQL*` env vars injected automatically. Usage-based pricing after the trial (~US$5/month Hobby plan) and no permanent free tier. |
| Render | Docker works, but free web services sleep, free instances have no persistent disk, and a managed MySQL is a separate paid resource. |
| Shared PHP hosting | Cheap but usually blocks required PHP 8.1+ extensions, gives no shell/SSH for DB import, no persistent storage guarantees, and weak concurrency. |
| VPS (DigitalOcean/Hetzner) | Full control but requires manual TLS, MySQL backups and OS patching – not “easy maintenance”. |

The container is provider-neutral, so the same image also runs on Fly.io,
Render, Koyeb or a VPS.

## 2. Production layout

```
/var/www/html                ← repository root (outside the web root)
├── Dockerfile
├── docker/                  ← php.ini, Apache vhost, entrypoint
├── public/                  ← Apache DocumentRoot
│   ├── index.php            ← login page
│   ├── download.php         ← authenticated private file download
│   ├── api/                 ← payment webhook + status API
│   └── assets/              ← pages, CSS, JS, images
├── src/                     ← application code (not web-readable)
├── storage/                 ← VOLUME mount point
│   ├── uploads/             ← assignments / submissions / payments
│   ├── logs/                ← PHP error log
│   └── sessions/            ← PHP sessions
└── deploy/db/               ← local-only DB dump (git-ignored)
```

* `public/` is the document root, so `src/`, `storage/`, `.env`, `.git/`,
  `database/` and `deploy/` can never be requested over HTTP.
* `/UniPortal/public/...` and `/public/...` aliases are kept for links that
  were stored in the database before the migration.
* `src/src/config/app.php` derives the URL base path automatically:
  `''` when `public/` is the document root, `/UniPortal/public` on XAMPP.
  All application links are generated with `app_url()` / `app_link()`.
* TLS is terminated by Railway's edge; the vhost maps
  `X-Forwarded-Proto: https` to `HTTPS=on`, so session cookies become
  `Secure` automatically.

## 3. Environment variables

Set these on the Railway **web service** (never in a committed file):

| Variable | Value |
| --- | --- |
| `APP_ENV` | `production` |
| `APP_DEBUG` | `0` |
| `APP_TIMEZONE` | `Asia/Ho_Chi_Minh` |
| `SESSION_SECURE_COOKIE` | `1` |
| `APP_BASE_PATH` | leave unset (auto-detected) |
| `DB_*` | not needed – Railway injects `MYSQLHOST`, `MYSQLPORT`, `MYSQLUSER`, `MYSQLPASSWORD`, `MYSQLDATABASE`, `MYSQL_URL`; `DATABASE_URL` is also supported |
| `PAYMENT_*`, `SEPAY_*` | only when a real payment account is connected; keep `PAYMENT_TEST_MODE=0` in production |

Do not set payment test keys on a public instance.

## 4. Deployment steps (require the owner's account)

1. Create a **private** GitHub repository and push this project.
   `.env`, `deploy/db/`, `storage/uploads/` and `storage/logs/` are ignored;
   verify with `git status` before pushing.
2. Create a Railway account (<https://railway.app>) and a new project.
3. **Deploy from the GitHub repo** – Railway detects `Dockerfile`
   (`railway.json` sets the builder and health check `/index.php`).
4. **Add MySQL** from the Railway catalog. It injects the `MYSQL*` variables
   into the linked service automatically.
5. **Add a volume** and mount it at `/var/www/html/storage`. Without it,
   uploads, sessions and logs disappear on every redeploy.
6. Set the environment variables from section 3.
7. **Import the database** (see section 5).
8. **Generate a domain** (Settings → Networking → Generate Domain).
   Railway issues an HTTPS certificate automatically.
9. Open `https://<domain>/index.php` and run the acceptance checklist
   (section 7).

### Importing the dump

The dump is produced locally and is git-ignored:

```bash
deploy/db/uniportal-YYYYMMDD-HHMMSS.sql.gz
```

Option A – TCP proxy (works with any MySQL client):

1. Railway MySQL service → Settings → Networking → **TCP Proxy**.
2. `gunzip -c deploy/db/uniportal-*.sql.gz | mysql -h <proxy-host> -P <port> -u root -p <database>`

Option B – Railway CLI:

```bash
railway login          # browser authorization
railway link           # select project/service
railway variables      # read MYSQLHOST / MYSQLPORT / MYSQLUSER / MYSQLPASSWORD / MYSQLDATABASE
railway connect MySQL  # opens a mysql shell inside the project network
```

The dump contains schema + data + primary keys, 77 foreign keys and 160
secondary indexes, is UTF-8 (`utf8mb4`), and imports with
`FOREIGN_KEY_CHECKS=0` / `UNIQUE_CHECKS=0` as written by `mysqldump`.
Do **not** enable `NO_ZERO_DATE` issues – the schema has no zero dates.

phpMyAdmin is not installed; use a native MySQL client through the TCP proxy
and delete the proxy when finished.

### Migrating existing uploaded files

The database references 370 demo files that live under `storage/uploads/`.
They are packaged separately (git-ignored):

```bash
tar -czf deploy/uploads-$(date +%Y%m%d-%H%M%S).tar.gz -C storage uploads
cat deploy/uploads-*.tar.gz | railway ssh -- "tar -xz -C /var/www/html/storage"
railway ssh -- "chown -R www-data:www-data /var/www/html/storage"
```

Do this after the volume is mounted. New uploads are written to the volume
automatically.

## 5. Data and privacy gate

The database contains 5,353 seeded students, 79 lecturers, 5,433 accounts and
6,325 submission records with realistic Vietnamese names, student IDs and
e-mail addresses, plus 370 uploaded demo files. All demo accounts currently
use the password `password`.

**Do not publish this instance publicly before either:**

* rotating the demo account passwords / disabling unused accounts, or
* restricting access (private demo, IP allow-list or an edge password gate).

This is a data-owner decision and has not been performed.

## 6. Verification performed locally

* Production-mode PHP server (`APP_ENV=production`, docroot `public/`) –
  login, all student/lecturer/admin pages, downloads, notifications, role
  isolation and error masking.
* Database dump imported into a scratch database: all 40 tables and exact
  row counts match, UTF-8 names preserved, foreign keys enforced.
* Deny rules: `.env`, `src/`, `storage/`, `database/` and `deploy/` are not
  reachable over HTTP.
* Docker image build was **not** executed because the local Docker daemon is
  not running; the Dockerfile/Apache config follow the standard
  `php:8.2-apache` layout.

## 7. Acceptance checklist (run on the public URL)

1. `GET /index.php` returns 200 over HTTPS; certificate valid.
2. Login as student, lecturer and AcademicAffairs; wrong password fails.
3. Student: dashboard, GPA, attendance, registration (view sections, enroll,
   drop), assignments, tuition + QR, GPA planner, alerts, timetable, profile.
4. Lecturer: dashboard, schedule, classes, materials, assignments, grading,
   warnings, profile.
5. Admin: dashboard, students, lecturers, courses, classes, schedule,
   registration periods, alerts, tuition, config.
6. Upload a submission file; verify the private path is not directly
   reachable and download works after login.
7. Register a course and verify the timetable updates.
8. Create a notification, open it from the bell menu, confirm the redirect
   stays on the same host.
9. Check `storage/logs/php-error.log` for warnings; no stack traces or
   credentials in HTTP responses.
10. Restart the service, confirm the uploaded file and the database rows are
    still present (volume + managed MySQL persistence).

## 8. Updates and rollback

* Push to the connected GitHub branch → Railway rebuilds and redeploys.
* SQL migrations live in `database/`; apply them through the TCP proxy
  before/after the code deploy as appropriate.
* Keep a fresh dump before each schema change:
  ```bash
  mysqldump --single-transaction --quick --routines --triggers \
    --default-character-set=utf8mb4 --hex-blob uniportal \
    | gzip -9 > deploy/db/uniportal-$(date +%Y%m%d-%H%M%S).sql.gz
  ```
* Roll back by redeploying the previous commit and restoring the matching
  dump.
