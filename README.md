# UDOM BloodLink — BRUNOO

This repository is the **UDOM BloodLink donor-management project**. It is separate from the CODAX repository and must be deployed to the BRUNOO website only.

## Current implementation status

The existing `index.html` is a front-end demonstration. Its donor/request records are browser-local demo data; it is not yet connected to the PHP/MySQL API. Do not use it with real student or patient information.

The repository now includes a PHP/MySQL backend foundation:
- MySQL schema for staff accounts, donor records, emergency requests, SMS logs and audit logs.
- Session-based login with password hashing, role checks and CSRF tokens.
- API endpoints for login, session check, donor registration/search, emergency requests and preliminary donor matching.
- Configuration template that keeps real database credentials out of Git.

## InfinityFree setup required

1. In the InfinityFree control panel for **brunoo.kesug.com**, create a MySQL database.
2. Open phpMyAdmin for that database and import `database/schema.sql`.
3. Copy `config/config.example.php` to `config/config.local.php` using the hosting File Manager or FTP.
4. Replace the four database placeholders with the exact MySQL hostname, database name, username and password shown by InfinityFree. These are MySQL details, not the FTP password.
5. Keep `config/config.local.php` private. It is excluded by `.gitignore`.
6. Test `/api/index.php?action=me`; without a logged-in session it should return HTTP 401 after database setup.

## First administrator account

Create the first administrator only after importing the schema. Generate a password hash using PHP's `password_hash('YOUR-STRONG-PASSWORD', PASSWORD_DEFAULT)`, then insert the account through phpMyAdmin:

```sql
INSERT INTO users (full_name, email, password_hash, role)
VALUES ('System Administrator', 'admin@example.invalid', 'PASTE_GENERATED_HASH_HERE', 'admin');
```

Replace the example name/email and generated hash with values chosen by the institution. Do not use a shared or guessed password.

## API routes

- `POST /api/index.php?action=login` — JSON `{ "email": "...", "password": "..." }`
- `GET /api/index.php?action=me` — current session and CSRF token
- `POST /api/index.php?action=logout` — requires `X-CSRF-Token`
- `GET /api/index.php?action=donors&q=&group=`
- `POST /api/index.php?action=donors` — requires login, role and CSRF token
- `GET /api/index.php?action=requests`
- `POST /api/index.php?action=requests` — requires login, role and CSRF token
- `GET /api/index.php?action=matches&request_id=ID`

All endpoints return JSON. Donor matching compares the recorded blood group only; it does **not** determine clinical compatibility or fitness to donate.

## SMS integration

No SMS provider credentials have been supplied. The current browser interface can open a phone's SMS application, but this backend does not send SMS. A provider account, approved sender ID, server-side credentials, delivery callback and budget are required for automatic SMS. Never put provider secrets in browser JavaScript or commit them to GitHub.

## Before real deployment

This is not yet approved for real hospital use. The front-end must be connected to these API routes and tested; HTTPS, institutional authorization, privacy/legal review, backups, access controls, audit review, clinical workflow approval and a real SMS provider must be completed first. Use fictional test records until then.
