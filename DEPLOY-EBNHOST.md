# Publishing Language Hub on EBNhost (cPanel)

Language Hub is a **Node.js** website (Next.js). Your data lives in the **Neon** cloud database,
so the website can run on EBNhost, on Vercel, or on both at the same time — they share the same data.

You need:

| What | Where to get it |
|---|---|
| `language-hub-cpanel.zip` | made by `npm run build:cpanel` (folder `deploy/`) |
| `ENV-VALUES-PRIVATE.txt` | made by the same command (folder `deploy/`) — **keep it private** |
| Your cPanel login | EBNhost client area → Services → your hosting → *Login to cPanel* |

---

## Step 0 — Check that your plan can run Node.js (2 minutes)

1. Log in to **cPanel**.
2. In the search box at the top, type **node**.
3. Do you see **“Setup Node.js App”** (under *Software*)?
   - **Yes** → continue with Step 1.
   - **No** → your plan is PHP-only. Choose one:
     - **A. Ask EBNhost** (live chat / ticket): *“Please enable CloudLinux Node.js Selector (Setup Node.js App) with Node 20 or 22 on my account.”* Many plans can switch it on, or offer a small upgrade.
     - **B. Keep the website on Vercel (free) and use your EBNhost domain** — see *Option B* at the end.

---

## Step 1 — Make the package (on your computer)

Open a terminal in the `language-hub` folder and run:

```bash
npm install
npm run build:cpanel
```

When it finishes you have:

- `deploy/language-hub-cpanel.zip` — upload this
- `deploy/ENV-VALUES-PRIVATE.txt` — the secret settings you will paste into cPanel

---

## Step 2 — Create the Node.js app in cPanel

1. cPanel → **Setup Node.js App** → **Create Application**.
2. Fill in:
   | Field | Value |
   |---|---|
   | Node.js version | **20.x** or **22.x** (the highest 20+ offered) |
   | Application mode | **Production** |
   | Application root | `language-hub` |
   | Application URL | your domain, e.g. `languagehub.com.bd` (leave the path empty) |
   | Application startup file | `app.js` |
3. Click **Create**. cPanel makes the folder `/home/YOURUSER/language-hub`.
4. Click **Stop App** for now (top right of the app page).

---

## Step 3 — Upload the files

1. cPanel → **File Manager** → open the `language-hub` folder.
2. Delete the sample files cPanel created there (`app.js`, `public`, `tmp` if present).
   **Do not delete `node_modules`** if you see it — it is cPanel's own link.
3. Click **Upload** → choose `language-hub-cpanel.zip` → wait for 100 %.
4. Back in File Manager, right-click the zip → **Extract** → extract into `/language-hub`.
5. Check that you now see `app.js`, `server.js`, `.next`, `public`, `vendor_modules`, `package.json`.
   (Turn on *Settings → Show Hidden Files* to see `.next`.)
6. Delete the zip to save space.

---

## Step 4 — Add the secret settings

1. cPanel → **Setup Node.js App** → click the ✏️ edit icon of your app.
2. Under **Environment variables** click **Add Variable** for each line in
   `ENV-VALUES-PRIVATE.txt` (name on the left, value on the right). At minimum:
   - `DATABASE_URL`
   - `SESSION_SECRET`
   - `NODE_ENV` = `production`
   - `SITE_URL` = your address, e.g. `https://languagehub.com.bd` (used in password-reset links and payment returns)
3. Click **Save**.

> **Easier alternative:** upload a file named `.env.production` into the `language-hub` folder with the lines
> `DATABASE_URL=…`, `SESSION_SECRET=…`, `SITE_URL=https://your-domain` (one per line), then **Restart**.
> If the site shows *“This page couldn't load — A server error occurred”*, these settings are missing or wrong.
>
> Do **not** click *Run NPM Install* — everything the app needs is already inside `vendor_modules`.
> (If the host ever asks for it, *Run NPM Install* also works: `package.json` lists the same libraries.)

---

## Step 5 — Start it

1. Click **Start App** (or **Restart**).
2. Open your domain in the browser. The home page should load in a few seconds (the first load is slower).
3. If you see an error:
   - File Manager → `language-hub/stderr.log` shows the reason.
   - *“Cannot find module”* → check Step 3.5 (all folders extracted, `.next` included).
   - *“DATABASE_URL is not set”* / database errors → check Step 4, then **Restart**.
   - Still stuck → send EBNhost support the `stderr.log` text.

---

## Step 6 — HTTPS and the www address

1. cPanel → **SSL/TLS Status** → select your domain and `www.` → **Run AutoSSL**.
2. cPanel → **Domains** → turn **Force HTTPS Redirect** on.

---

## Step 7 — First login and launch checklist

1. Open `https://YOUR-DOMAIN/login` and log in with the admin email and the password printed by
   `npm run db:clean-demo -- --yes` (the demo data has already been removed).
2. **Admin → Users → Admin** → change the email to your real email and set your own password.
3. **Admin → Settings** → fill in: contact email, WhatsApp link, **bKash / Nagad numbers** (shown at checkout).
4. **Admin → Users → Add user** → create your teachers (role *teacher*), then their profile.
5. **Admin → Courses → New course** → add courses, teaching team, details, classes.
6. Buy a cheap test course with a second account to see the whole flow, then refund it in **Orders**.

---

## Updating the website later

1. On your computer: make your changes → `npm run build:cpanel`.
2. cPanel → Setup Node.js App → **Stop App**.
3. File Manager → in `language-hub`, delete `.next`, `public`, `vendor_modules`, `server.js`, `app.js`.
   **Keep** `.env.production` (your settings), `node_modules`, `stderr.log`.
4. Upload and extract the new zip (Step 3), then **Start App**.
   Your data is safe — it lives in the Neon database, not in these files.

---

## Option B — Keep the site on Vercel and use your EBNhost domain (free, no Node.js needed)

1. Vercel → your project **language-hub** → **Settings → Domains** → **Add** → type your domain.
   Vercel shows the DNS records it needs (usually an **A** record `76.76.21.21` for the bare domain
   and a **CNAME** `cname.vercel-dns.com` for `www`).
2. cPanel → **Zone Editor** → your domain → edit the existing `A` record of `@` and the `www`
   record to the values Vercel showed (delete old ones that point elsewhere).
3. Wait 10 minutes to a few hours. Vercel issues HTTPS automatically.
   Your email at EBNhost keeps working (MX records are not touched).
