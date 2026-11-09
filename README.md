# Language Hub

An online school for learning Chinese, Japanese, Korean and English, taught in Bangla.
PHP 8 + MySQL, phone first, Bangla/English and light/dark switch on every page.

- Live batches (max 15 students) and recorded courses
- One-screen login and sign-up with a 6-digit code sent by email (no passwords)
- Maximum 2 devices per account
- SSLCommerz payments with coupons
- Admin, teacher and student panels

## Run locally (XAMPP)

1. Put the project in `htdocs` (or link it there) and open `http://localhost/language-hub/`.
2. Create a database called `language_hub` and import the schema, then every file in `config/migrate_*.sql`.
3. Copy `config/secrets.example.php` to `config/secrets.php`.
   Locally the email code is shown on screen until SMTP is filled in.

## Deploy on Vercel

The PHP pages run through `api/index.php` with the community
[vercel-php](https://github.com/vercel-community/php) runtime (see `vercel.json`).
Vercel has no database, so use a hosted MySQL (TiDB Cloud Serverless or Aiven both have free plans).

Set these in Vercel → Project → Settings → Environment Variables:

| Name | Example |
|---|---|
| `PROD_DB_HOST` | `gateway01.ap-southeast-1.prod.aws.tidbcloud.com` |
| `PROD_DB_PORT` | `4000` |
| `PROD_DB_NAME` | `language_hub` |
| `PROD_DB_USER` | `xxxx.root` |
| `PROD_DB_PASS` | your database password |
| `SMTP_HOST` / `SMTP_PORT` | `smtp.gmail.com` / `465` |
| `SMTP_USER` / `SMTP_PASS` | Gmail address / 16-character App Password |
| `VAPID_PUBLIC_KEY` / `VAPID_PRIVATE_KEY` | optional, for push notifications |

On Vercel, sessions are stored in MySQL (`config/migrate_sessions.sql`) and the cache lives in `/tmp`.
**Uploaded files (thumbnails, avatars) are not kept on Vercel** — host those on cPanel or an object store.

## Deploy on cPanel

Upload the files, create `config/secrets.php` from the example, and import the database.
`.htaccess` handles clean URLs and blocks private folders.
