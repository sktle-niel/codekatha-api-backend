# Deploying the CODEKATHAX API to Hostinger

This API runs on standard **Hostinger shared hosting** (PHP 8 + MySQL +
phpMyAdmin). PHPMailer is bundled, so there is nothing to `composer install`.

The plan: host the API on a **subdomain** (e.g. `api.codekathax.com`) whose
document root points at the project's `public/` folder, keeping `app/`,
`.env`, and the SQL outside the web root.

---

## 1. Create the database (hPanel → Databases → MySQL)

1. Create a new database + user. Hostinger prefixes them, e.g.
   `u123456_codekathax` (DB) and `u123456_ckxuser` (user). Note the password.
2. Open **phpMyAdmin** for that database, go to the **Import** tab, and import
   `database/schema.sql`. You should now see a `project_requests` table.

## 2. Create the subdomain

1. hPanel → **Domains → Subdomains** → create `api.codekathax.com`.
2. Note its document root. By default it is something like
   `public_html/api`. You will point this at `public/` in the next step.

## 3. Upload the files

Upload the **whole project** to a folder OUTSIDE `public_html` if your plan
allows (e.g. `/home/u123456/codekathax-api/`), then set the subdomain's
document root to that folder's `public/`.

If you can only upload inside `public_html`, upload the project to
`public_html/codekathax-api/` and set the subdomain document root to
`public_html/codekathax-api/public`. The included `.htaccess` files block
direct access to `app/`, `.env`, and the SQL as a safety net.

Use **hPanel → File Manager** or **FTP**. Make sure these go up:
`app/` (incl. `app/PHPMailer/`), `public/`, `database/`, and `.env`.

## 4. Configure `.env` on the server

Edit `.env` (in the project root, NOT in `public/`) with the real values:

```ini
DB_HOST=localhost
DB_PORT=3306
DB_NAME=u123456_codekathax
DB_USER=u123456_ckxuser
DB_PASS=your-db-password

ALLOWED_ORIGINS=https://codekathax.com,https://www.codekathax.com

IP_SALT=paste-a-long-random-string-here

MAIL_TO=niel.ladica07@gmail.com
MAIL_FROM=no-reply@codekathax.com
MAIL_FROM_NAME=CODEKATHAX Website
MAIL_ENABLED=true
```

> Set `ALLOWED_ORIGINS` to the exact origin(s) your website is served from.

## 5. Set up email (SMTP)

**Recommended on Hostinger — a domain mailbox:**

1. hPanel → **Emails** → create a mailbox, e.g. `no-reply@codekathax.com`.
2. Add to `.env`:

   ```ini
   SMTP_HOST=smtp.hostinger.com
   SMTP_PORT=465
   SMTP_SECURE=ssl
   SMTP_USER=no-reply@codekathax.com
   SMTP_PASS=the-mailbox-password
   ```

**Or use Gmail (works anywhere):**

1. Turn on 2-Step Verification on your Google account.
2. Google Account → **Security → App passwords** → generate one (16 chars).
3. Add to `.env`:

   ```ini
   SMTP_HOST=smtp.gmail.com
   SMTP_PORT=465
   SMTP_SECURE=ssl
   SMTP_USER=niel.ladica07@gmail.com
   SMTP_PASS=the-16-char-app-password
   ```

If SMTP is left blank, submissions are still saved to MySQL — only the email is
skipped (the API returns `"emailed": false`).

## 6. Test the live API

- Visit `https://api.codekathax.com/` → should return the health JSON.
- Submit the form on your website. Check:
  - phpMyAdmin → `project_requests` has a new row.
  - Your inbox (`niel.ladica07@gmail.com`) received the email.

## 7. Point the website at the API

In the **frontend** project, set the API base URL before building for
production. In `codekathaxwebsite/.env` (or `.env.production`):

```ini
VITE_API_BASE=https://api.codekathax.com
```

Then rebuild: `npm run build` and deploy the `dist/` folder. The form's
`fetch` will call `https://api.codekathax.com/requests.php`.

---

## Troubleshooting

| Symptom | Fix |
| --- | --- |
| `Database error` / 500 | Check `.env` DB creds; confirm `schema.sql` was imported. |
| CORS error in browser | Add your site's exact origin to `ALLOWED_ORIGINS`. |
| Saved but `emailed: false` | SMTP not set or wrong. For Gmail use an **App Password**, not your login. |
| Gmail blocks the send | Use port 465 + `ssl`, and an App Password (2FA must be on). |
| 403 when opening `/app/...` | Expected — `app/` is intentionally blocked from the web. |
