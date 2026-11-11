# Language Hub

An online school for learning Chinese, Japanese, Korean and English, taught in Bangla.
Phone-first, with a Bangla/English and light/dark switch on every page.

**v2 stack:** Next.js 16 (React 19, TypeScript) on Vercel + PostgreSQL (Neon).
The original PHP/MySQL site is kept in [`legacy-php/`](legacy-php/) for reference while the remaining features are ported.

## What works

| Area | Features |
|---|---|
| Public site | Home (language carousel, every course with instant filters, next live batch), course list with search/filters/sort, language pages, course page, teachers, teacher profile, About / Terms / Privacy / Refund / FAQ / Careers, contact form |
| Login | Email + password, sign-up, 6-digit email code (when SMTP is set), max 2 devices per account with "sign out a device" step, rate limiting |
| Student | Dashboard (next live class, my courses, devices), My courses, classroom (video/PDF/text lessons, progress, live class countdown + join, recordings), checkout (coupons, SSLCommerz or manual bKash/Nagad TrxID), orders, printable invoice, notifications, profile and password |
| Teacher panel | Dashboard, my courses, course text, curriculum editor (modules + lessons), live class scheduling (students are notified), students and progress, public profile |
| Admin panel | Dashboard, courses (create/edit/delete, prices, teacher, batch), curriculum + live classes for any course, languages, users & roles & passwords & teacher profiles, enrollments (manual access), orders (approve/reject/refund manual payments), coupons, contact messages, settings |

Not ported yet (still in `legacy-php/`): MCQ/CQ exams, notice board, support tickets, success stories,
admission tracker, certificates, push notifications, site analytics.

## Project layout

```
app/            pages (app/(site) = public + student, app/admin, app/teacher, app/login)
app/do/         form handlers (POST → 303 redirect, origin-checked)
app/api/        JSON endpoints used by the front-end scripts and the payment gateway
components/     shared UI (site chrome, panels, course card, curriculum editor …)
lib/            db, auth/sessions, http helpers, courses, orders/payments, i18n, formatting
content/        static page copy (About, Terms …)
db/schema.sql   Postgres schema (safe to re-run)
public/         CSS, JS, images, service worker
scripts/        db-schema, MySQL import, demo seed, smoke test
legacy-php/     the original PHP site
```

## Run locally

```bash
npm install
vercel env pull .env.local     # or copy .env.example to .env.local and fill it in
npm run db:schema              # create tables (first time only)
npm run dev                    # http://localhost:3000
```

Without SMTP, the email-code login shows the code on screen in development.

## Database

- `npm run db:schema` creates or updates the tables.
- `npm run db:import` copies data from the old MySQL database (XAMPP, `language_hub`).
- `npm run db:seed` sets passwords on the demo admin / teacher / student accounts and prints them once.

## Testing

```bash
BASE_URL=http://localhost:3000 ADMIN_PASSWORD=… TEACHER_PASSWORD=… STUDENT_PASSWORD=… npm run test:smoke
```

The smoke test logs in as each role and walks through enrolment, progress, manual payment → admin approval,
coupons, course editing, live classes and permission checks. It cleans up the rows it creates.

## Deploy (Vercel)

Pushing to `main` deploys. Environment variables (Project → Settings → Environment Variables):

| Name | Needed | Notes |
|---|---|---|
| `DATABASE_URL` | yes | added automatically by the Neon integration |
| `SESSION_SECRET` | yes | random 32+ characters; signs login cookies |
| `SMTP_HOST` `SMTP_PORT` `SMTP_USER` `SMTP_PASS` | optional | enables email-code login (Gmail: `smtp.gmail.com`, `465`, App Password) |
| `SSLCZ_STORE_ID` `SSLCZ_STORE_PASS` `SSLCZ_LIVE` | optional | enables SSLCommerz; without it checkout uses manual bKash/Nagad + admin approval |

Set the bKash/Nagad numbers shown at checkout in **Admin → Settings**.
