# PrepDeck (PHP 8 + MySQL, mobile first)

Past questions by department and semester, flashcards, study guides, games, lecture recording and PrepDeck AI, with a free tier and a Plus subscription. Every page is server-rendered PHP using shared layout files and external sidebars.

## Folder layout
```
prepdeck/
  app/            core (Auth, CSRF, Settings...), services (Billing, AI, PDF, queue), helpers, nav
  includes/       header.php footer.php auth_split.php            (public pages)
                  dashboard_header.php dashboard_footer.php       (student + admin shell)
                  sidebar_student.php sidebar_admin.php           (the external sidebars)
                  sidebar_staff.php sidebar_lecturer.php
  public/         web root: index, login, register, page, logout
    lecturer/     lecturer dashboard (index, outlines, lessons, insights)
    staff/        PrepDeck staff desk for class reps and other staff (index, papers, courses, review, announcements; lecturers share papers/courses/review/announcements)
    pay/          Paystack return page
    dashboard/    student pages (index, ai, papers, decks, notes, materials, guides, recordings, games, progress, plan, settings, help)
    admin/        school admin pages (index, users, staff, teaching, papers, study, recordings, processing, academics, plans, payments, reports, announcements, site, audit, settings)
    platform/     platform owner pages (index, schools, users, permissions, subscriptions, settings, audit)
    api/          small JSON endpoints used by the pages (uploads, AI chat, decks, payments, job progress...)
    assets/       css + js
  database/       prepdeck_fresh.sql  upgrade_from_your_dump.sql  sample_data.sql  migrations/  seeds/ghana_schools.php
  bin/            worker.php (AI queue)  cron.php  cleanup.php  migrate.php  platform-owner.php
  storage/        private uploads (blocked from the web by .htaccess)
  design-prototypes/  the original HTML designs, for reference only
```
A new student page is: `require app/bootstrap.php; $u = require_login(); $active = 'x'; $title = '...'; include includes/dashboard_header.php; ...; include includes/dashboard_footer.php;`. Admin pages also set `$adminArea = true;` and call `require_perm('...')`. Menus come from `student_nav()` and `admin_nav()` in `app/ui.php`.

Lists are data tables: `<section class="card dt">` + `dt_head()`, an optional `<form class="toolbar">` filter, `<div class="dtwrap"><table class="dtable">`, then `dt_foot($total, $per, $page, $filters)` for "Showing x–y of n", rows per page (`per_page()`) and page links. Columns sort on click and rows stack into labelled cards on phones (`assets/css/ui.css`, `assets/js/shell.js`). Forms open in the modal overlay: put the form in `<template id="tpl-x">` and open it with `modal_btn('tpl-x', 'Title', 'Label')` (pass `'wide'` for long forms). One-button row actions use `row_form()`; add a confirm message to get the confirm overlay.

## Install (XAMPP)
1. Copy the folder to `htdocs/prepdeck`.
2. Create the database, then import ONE file with phpMyAdmin:
   - New install: `database/prepdeck_fresh.sql` (then optionally `sample_data.sql`)
   - Your existing `prepdeck` database: `database/upgrade_from_your_dump.sql` (safe to run twice; keeps your data)
3. Copy `.env.example` to `.env`. Set `DB_NAME`, `DB_USER`, `DB_PASS`. `APP_BASE=/prepdeck/public/` matches htdocs/prepdeck; use `/` if the public folder is your site root.
4. Run `php bin/migrate.php` (XAMPP: `C:/xampp/php/php.exe bin/migrate.php`). This adds the schools directory and multi-school support (see below).
5. Open `http://localhost/prepdeck/public/`. Login: `admin@prepdeck.local` / `Admin@12345`, which is the **platform owner**. **Change the password first** (Settings in the dashboard).
6. In Platform > Schools open your school (or add one), add a school admin, then in that school's Admin > Departments & exams add departments, courses and the current term. Students can also add a missing department when they register.

## Many schools (multi-tenant)
One install and one database serve every school in Ghana. Each school-owned row carries `school_id`, and every page and API filters on the current school, so a student, lecturer or school admin only ever sees their own school.
- **Directory:** `schools` is seeded from `database/seeds/ghana_schools.php` (public and private universities, the technical universities, colleges of education, nursing colleges and major senior high schools, with region and town). The landing page has "Choose your school" with search by name, region and type; visitors see the chosen school's library. "My school is not listed" sends a request that the platform owner approves in Platform > Schools > Requests (the requester is emailed).
- **Registration:** step 1 the school (searchable), step 2 department, level and the rest. Email addresses are unique across all schools, so sign-in is still email + password; a school that is switched off blocks its members.
- **Current school:** `sid()` (from `Tenant`) is the user's school, or the school picked in the header switcher by the platform owner or by staff who work in several schools (`user_schools`). In SQL use `in_school('alias')` / `in_my_school('table', $id)`; `questions_in_school()` for `exam_questions`. No school = `0`, which matches nothing.
- **Roles:** `super_admin` (role 5) is the platform owner: Platform > Schools (profile, logo, licence, student limit, active/listed, school admins, per-school switches, "Open dashboard"), All users (move between schools, any role, multi-school staff, extra permissions), Permissions, Subscriptions (all Plus subscriptions and payments, school licences, default and per-school prices, platform-wide coupons), Platform settings (maintenance, registration, AI kill switch and cost limits, school defaults) and Platform audit. `admin` (role 4) is a school admin: a PrepDeck staff member the platform owner creates (Platform > Schools > Add admin) to run one school's content: departments, courses, past papers, study content, announcements, and adding lecturers and class reps (who can get only permissions the admin holds). School admins also do not run the public site or PrepDeck AI: Platform > Site content (home page, message bar, contact links, FAQ, legal pages) and Platform settings (AI engine, limits, what the AI may do, auto-publishing, the lecturer outline tool) are the platform owner's. School admins have nothing to do with Plus: prices, payments, coupons, free/Plus limits, giving Plus and staff Plus benefits are the platform owner's (the `plans` permission is owner-only).
- **Permissions:** Platform > Permissions sets the default role matrix and, per school, its own matrix. That matrix is the ceiling for the school: extra permissions a school admin gives a lecturer or rep can never go beyond what the school's admin role has, and school admins cannot change platform settings, AI limits, games, the FAQ or legal pages.
- **Settings:** `site_settings` has a `school_id` (0 = platform default). A school's own value wins, except platform keys (`Settings::PLATFORM`) which only the platform owner can change. Plus prices follow the same rule (`plan_prices.school_id`, 0 = default).
- **Files:** new uploads go to `storage/uploads/<kind>/<school id>/` (papers, notes, audio, outlines, logos). Older files keep working where they are.
- **Existing install:** `php bin/migrate.php` creates school 1 from your school name, puts every existing row in it, and makes the first admin the platform owner (`php bin/platform-owner.php email` makes another account one).

## Password reset
- "Forgot password?" on the sign-in page asks for the email, and a link is mailed (valid once, 1 hour). `public/reset.php` checks the token and saves the new password. Admins can still hand out a temporary password in Admin > Users.
- Emails go through `Mailer` (SMTP in production, see Email below). On a local install with no SMTP settings (`APP_ENV=development`) messages are written to `storage/mail.log`, and the reset link is also shown on the page.

## AI, recordings, payments
- PDF text needs poppler (`pdftotext`). Windows: install poppler and add it to PATH. Scanned PDFs are read with `ocrmypdf` if it is installed.
- Put `AI_API_KEY` in `.env` to switch on PrepDeck AI chat and document processing. Without it uploads queue and then show "failed: AI_API_KEY is not set".
- Lecture transcription needs `STT_API_URL` + `STT_API_KEY` (any OpenAI-compatible audio transcription endpoint). `ffprobe` (ffmpeg) is used to read audio length if available.
- Jobs run in the background. An upload starts a worker automatically; for reliability also run `php bin/worker.php` under a supervisor, and a cron every 15 minutes: `php bin/cron.php`.
- AI answers are saved as drafts until an admin publishes them (Admin > AI processing), unless you turn on auto-publish in Settings > AI.
- **Payments (Paystack):** in `.env` set `PAY_PROVIDER=paystack` and `PAY_SECRET_KEY=sk_test_...` (use `sk_live_...` when live). Optional `APP_URL=https://your-site` if the return link is wrong behind a proxy. In the Paystack dashboard (Settings > API Keys & Webhooks) set the webhook URL to `https://your-site/prepdeck/public/api/payments.php?a=webhook`. Flow: the student picks Monthly/Semester/Year (+coupon) -> redirected to Paystack -> returns to `pay/callback.php` -> the server re-verifies the payment with Paystack (amount and currency must match) -> Plus turns on. The webhook (signature checked) does the same if the student closes the tab. Admin > Payments has "Check with Paystack" and "Mark as paid" (for cash/other payments). Amounts are sent in the smallest unit (pesewas); your Paystack account must support the currency set in Admin > Plans. Without keys, manual confirmation still works.

## Plus rules (all editable in Admin > Plans & access)
Free: browse papers and shared decks, Match game, 1 note, 3 uploads of 1 page, 15 minute timer. Plus: solved answers, AI, study guides, recordings, all games, own decks and progress, unlimited notes/uploads, timer up to your maximum. Rules are enforced on the server (answer text is never sent to free accounts).

## Lecturers and class reps
- Admin > Lecturers & reps: promote a student by email (or create the account), choose the role, tick extra permissions, and assign the courses they manage. People can only upload, review and announce inside their assigned courses.
- **Courses:** school admins add departments and courses in Admin > Departments & exams. Lecturers and class reps also get the "courses" permission by default (the platform owner can turn it off per role in Platform > Permissions): in their desk (or lecturer dashboard) > Courses they can add courses, always to the school they belong to. A course they add is assigned to them, so they can upload to it straight away. Departments, terms and exams stay admin-only.
- Class rep = student + upload past questions (to assigned courses) + a rep desk at `/staff/`. Uploads wait for review unless you switch on "Class rep uploads go live". Lecturer = upload + `review` (approve papers and AI answers for assigned courses only) + announce to their department; lecturer uploads go live by default.
- Role benefits (Admin > Lecturers & reps): give reps and/or lecturers Plus features free; auto-publish for each role.
- `review` is scoped to assigned courses; `approve` is site-wide. In an upgraded database, existing lecturers keep any `approve`/`decks` they already had; remove them in Settings > Roles & permissions if you want them scoped.

## Lecturer dashboard (`/lecturer/`)
Lecturers sign in to their own dashboard; class reps and other PrepDeck staff keep the staff desk at `/staff/`. Both share the upload, review and announcement pages, which show the right sidebar for the viewer.
- **Dashboard:** courses taught, students reached, lesson notes, paper views, review queue, upcoming exams, a per-course table (papers, lesson notes, views and practice scores).
- **Course outlines (PrepDeck Plus):** upload the course outline PDF for an assigned course. PrepDeck AI lists the topics, weeks and objectives, then writes a lesson note per topic (objectives, explanations, worked examples, misconceptions, summary, key terms, review questions, class activity). Progress is shown live like any other upload. Lecturers can edit, add or remove topics and write or rewrite single notes.
- **Lesson notes:** edit, download (.md), and publish to students. Published notes appear for students under Dashboard > Lesson notes. AI notes are drafts until the lecturer publishes them.
- **Student insights:** daily views, students reached, practice attempts and average scores per paper, papers where students score under 50%, most read lesson notes.
- **Plus:** the outline tool needs Plus. A lecturer has it through the "Lecturers get Plus features free" role benefit, a Plus subscription bought on the Plan page, or Plus given by an admin.
- **Admin > Lecturer tools** (`admin/teaching.php`): per-lecturer usage (outlines, notes, this month's count, tokens) with Give Plus / Remove Plus; every course outline (topics, notes, AI jobs, retry, delete); every lesson note (read, hide from students, publish, delete); settings for the feature switch, the monthly allowance per lecturer (`lesson_quota`, 0 = no limit), outline topic and page limits, auto-publishing and whether students need Plus to read lesson notes. Admin > Lecturers & reps shows the same numbers on each lecturer's page.
- Run `php bin/migrate.php` after updating (adds `course_outlines`, `outline_topics`, `lesson_notes`), and make sure `storage/uploads/outlines` exists and is writable.

## PrepDeck AI processing (live)
Every upload (past paper, notes, lecture recording) becomes a job. The uploader sees a live panel: each step (reading, finding questions, model answers, flashcards, study guide, games), counters, and a running log of what the AI is doing, streamed from `api/jobs.php?a=stream`. Admins can watch any running job from Admin > AI processing ("Watch live").
- An upload starts a background worker straight away (Windows and Linux). If the host blocks background processes, the open page runs the job itself, and `bin/cron.php` processes anything left in the queue.
- Scanned PDFs (little text per page), or servers without poppler, are read by Claude directly (PDFs up to 100 pages / 30 MB).
- Admin > Settings > AI & recordings > **Test AI connection** shows whether the key, model and account credit work. "Credit balance is too low" means credit must be added in the Anthropic Console (Plans & Billing); the key itself is fine.
- Students practise with **Practice exams** (Plus): timed, one question at a time, model answers, self-marking, scores saved to their history.

## Deploying (production checklist)
1. **Web root = `public/`.** Point the virtual host's DocumentRoot at `prepdeck/public` and set `APP_BASE=/`. If you keep the XAMPP layout, the root `.htaccess` (plus one in each private folder) blocks `.env`, code, SQL and uploads; check that `/prepdeck/.env` returns 403.
2. `.env`: `APP_ENV=production`, `APP_URL=https://your-site`, a real DB user (not root), `AI_API_KEY`, `MAIL_FROM`, Paystack keys. Never commit `.env`. If `.env` was ever reachable from the web, **rotate the AI key and the DB password**.
3. **HTTPS.** The session cookie becomes secure and HSTS switches on automatically.
4. In `public/.htaccess`, change the three `ErrorDocument` paths from `/prepdeck/public/error.php` to `/error.php` when `public/` is the web root.
5. **Worker:** keep one running. Linux (systemd): `ExecStart=/usr/bin/php /var/www/prepdeck/bin/worker.php` with `Restart=always`. Windows: Task Scheduler "At startup": `C:\xampp\php\php.exe C:\xampp\htdocs\prepdeck\bin\worker.php`.
6. **Cron every 15 minutes:** `php bin/cron.php` (expires Plus, processes the AI queue, daily cleanup). Windows: Task Scheduler, repeat every 15 minutes.
7. **Monitoring:** `api/health.php` returns 200 with queue numbers, or 503 if the database is down. PHP errors go to `storage/logs/php-error.log`.
8. Change the default admin password, open Admin > Settings > System and fix anything marked "Check", and take daily database backups (`mysqldump prepdeck > backup.sql`).

## Continuous deployment (GitHub → Hostinger)
Workflow: `.github/workflows/ci-cd.yml`.

| Event | What runs |
|---|---|
| Pull request | PHP syntax check on 8.1/8.2/8.3, unit tests, a fresh MariaDB built from `database/*.sql`, migrations (run twice), every page rendered as admin/lecturer/student, HTTP checks. Nothing deploys. |
| Push to `staging` | The same tests, then deploy to the **staging** environment. |
| Push to `main` | The same tests, then deploy to **production**. |
| Actions > CI / CD > Run workflow | Redeploy, or **roll back** to the previous release. |

**How users are not disrupted:** each deploy is unpacked into `releases/<time>-<commit>/` next to the live site, linked to the shared `.env` and `storage/` (uploads, logs), checked (`bin/selfcheck.php`), migrated (`bin/migrate.php`), and only then made live by swapping the `current` symlink in one atomic rename. Sessions, uploads and running AI jobs carry on. The pipeline then calls `api/health.php` until it reports the new release; if it does not within 3 minutes, it switches back automatically. The last 5 releases are kept for instant rollback.

**One-time setup**
1. Hostinger hPanel > Advanced > SSH Access: enable SSH and note host, port (65002) and user.
2. On your computer: `ssh-keygen -t ed25519 -f prepdeck_deploy -N ""`. Add `prepdeck_deploy.pub` in hPanel > SSH Access > SSH keys.
3. Copy `deploy/server-setup.sh` to the server and run `bash server-setup.sh /home/<user>/prepdeck <your-domain>`. It creates `releases/`, `shared/.env` (fill it in) and points `public_html` at `current/public` (your old `public_html` is kept as `public_html.before-prepdeck`).
4. hPanel > Databases: create the database, put it in `shared/.env`, and import `database/prepdeck_fresh.sql` once with phpMyAdmin.
5. hPanel > Advanced > Cron Jobs, every 15 minutes: `php /home/<user>/prepdeck/current/bin/cron.php`.
6. GitHub > Settings > Environments: create `production` (and `staging` if you want one; a subdomain with its own database works well). Add **secrets** `SSH_HOST`, `SSH_PORT`, `SSH_USER`, `SSH_PRIVATE_KEY` (contents of `prepdeck_deploy`), `DEPLOY_PATH` (`/home/<user>/prepdeck`), and the **variable** `SITE_URL` (`https://your-domain`). Optional: `SSH_KNOWN_HOSTS`, and the variable `PHP_BIN` if the SSH `php` is not the version your site uses (e.g. `/opt/alt/php82/usr/bin/php`). Tick "Required reviewers" on production if a person should approve each release.
7. Protect `main` (Settings > Branches): require pull requests and the "CI / CD" checks to pass.

**Adding features safely:** work on a branch, open a pull request (tests run), merge to `staging` to try it on the staging site, then merge to `main`. Database changes go in a new file in `database/migrations/` (see `bin/migrate.php`) and must be additive: new tables, new nullable or defaulted columns, new indexes. Rename or drop a column only in a later release, after no live code uses it, so the release that is still serving during the switch and any rollback keep working.

## Email
`app/services/Mailer.php` sends every email as text + HTML over SMTP (Hostinger: `smtp.hostinger.com`, 465/SSL), logs it in `email_log` (Admin > Settings > Email), and retries temporary failures from `bin/cron.php`. Emails sent: welcome + confirm your address, password reset, payment receipt, and "your study material is ready / processing stopped" (students can turn that one off in Settings; every notification email has a one-click unsubscribe). Addresses are checked properly: international domains, plus-addressing and new TLDs are accepted; misspelt domains (gmial.com) get a suggestion; domains that cannot receive mail are refused at sign-up.

**To stay out of spam** (Admin > Settings > Email checks all of this live):
1. Send from a mailbox on your own domain (`MAIL_FROM=no-reply@your-domain`), never a Gmail address.
2. **SPF:** TXT on your domain, e.g. `v=spf1 include:_spf.mail.hostinger.com ~all` (hPanel adds it when the domain uses Hostinger email).
3. **DKIM:** hPanel > Emails > (domain) > DNS / DKIM: enable it. If you send through a provider that does not sign, set `DKIM_SELECTOR`, `DKIM_DOMAIN`, `DKIM_PRIVATE_KEY` and publish the public key.
4. **DMARC:** TXT `_dmarc.your-domain` = `v=DMARC1; p=none; rua=mailto:postmaster@your-domain`. Move to `p=quarantine` after a few clean weeks.
5. Press **Send test** and send to a Gmail and an Outlook address; in Gmail open "Show original" and check SPF, DKIM and DMARC all say PASS.

## Before going live
Set `APP_ENV=production`, use HTTPS, change the admin password, keep `storage/` outside the web root if you can, and take database backups.
