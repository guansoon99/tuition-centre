# Deploying to production

**Trigger: "deploy production" means this document and this server.**

Staging is a different box with a different procedure — see
[deploy-staging.md](deploy-staging.md). The full build-from-nothing story is
[DEPLOY.md](DEPLOY.md); this file is only the day-to-day update.

## The server

| | |
| --- | --- |
| Provider | DigitalOcean, Singapore |
| IPv4 | `157.245.149.36` |
| Domain | `osterqin.com` (via Cloudflare, proxied) |
| OS | Ubuntu 24.04 LTS |
| SSH user | `root` (key only — `~/.ssh/osterqin`, passphrase-protected, load into the agent first) |
| App path | `/var/www/tuition` |
| Stack | nginx → PHP-FPM 8.3 → MySQL 8.0; files in Cloudflare R2 |

Before any SSH from a fresh terminal on Windows, load the key into the agent
once per boot — it has a passphrase and the agent is what lets scripted SSH
skip the prompt:

```powershell
Start-Service ssh-agent; ssh-add C:\Users\rexja\.ssh\osterqin
```

## Deploy

1. Push to `main`. CI runs the suite on SQLite **and MySQL** — wait for the
   green tick on the commit. Production is MySQL and this is the only check
   that exercises it before the box does.

2. One command:

    ```bash
    ssh root@157.245.149.36 'bash /var/www/tuition/deploy/update.sh'
    ```

That script ([deploy/update.sh](deploy/update.sh)) refuses to deploy a commit
whose CI is red or still running, pulls `origin/main`, runs `composer install`
only if `composer.lock` changed, migrates, clears the application cache
**after** migrating and rebuilds the framework caches, reloads PHP-FPM, then
confirms `https://osterqin.com/login` answers 200. It prints the rollback
command at the end.

The script also restarts the queue worker (`tuition-queue`, see DEPLOY.md
"Queue worker"), which runs the student import in the background.

There is no `npm` on the server and none is needed: `public/build/` is
committed. **Any change to Tailwind classes must be built locally and
committed** (`npm run build`), exactly as for staging.

## Running artisan on the box

Always as the web user:

```bash
sudo -u www-data php artisan <command>
```

Run as root, artisan leaves root-owned files under `storage/` — compiled
Blade views, and the cache entry that `backup:run`'s `withoutOverlapping()`
uses as its mutex. www-data then cannot write them: pages 500 on a view
recompile, and the nightly backup fails to take its lock and silently never
runs. That happened once (2026-09-08, a test run of the backup as root).
`update.sh` runs artisan as root by design and chowns afterwards, so it is
the one exception. If in doubt:

```bash
chown -R www-data:www-data /var/www/tuition/storage /var/www/tuition/bootstrap/cache
```

## Rollback

```bash
ssh root@157.245.149.36 'bash /var/www/tuition/deploy/update.sh <previous-sha>'
```

Same script, pointed at an older commit. It rolls the **code** back. It does
not undo a migration that the bad deploy ran — for that, either
`php artisan migrate:rollback --force` on the box, or restore last night's
dump from R2 (see Backups in DEPLOY.md). Check `migrate:status` before
deciding which.

## Escape hatch

`--force` skips the CI gate. Use it when CI is down or when you are deploying
a commit that predates the workflow. Not for "the tests are red but I'm sure
it's fine".

## Deployment log

| Date | From → to | Notes |
| --- | --- | --- |
| 2026-09-08 | — → `2a12282` | First build (Phases A and B of DEPLOY.md). MySQL migration `CAST … AS INTEGER` failed on first migrate and was fixed in `0d39e4e`. |
| 2026-09-08 | `0d39e4e` → `dd07a6a` | First run of `deploy/update.sh`; CI gate green on both legs. Five commits: the settings-row lookup fix from the MySQL CI leg, the deploy scripts and runbooks, and Deactivate/Activate for banner slides and announcements. One migration (`is_active` on `announcements`). No dependency change. Asset rebuild (`app-DfeY9C-O.css`), verified served with the old file 404ing. Four new routes verified in the cached list, `storage/` ownership clean after the script's chown, `/login` 200 from outside, 72 migrations ran. |
| 2026-09-08 | `dd07a6a` → `1e7ef00` | Students-tab search on the course edit page. CI green on both legs. No migration, no dependency change, no asset change, no new route. `/login` 200. |
| 2026-09-08 | `1e7ef00` → `0e213b5` | The not-a-teacher-on-this-course message on refusals. CI green on both legs. PHP only: no migration, no dependency change, no asset change, no new route. `/login` 200, 72 migrations. |
| 2026-09-08 | `0e213b5` → `a91d1a5` | The materials-tab modals show the refusal reason. CI green on both legs. Blade only: no migration, no dependency change, no asset change, no new route. `/login` 200, 72 migrations. |
| 2026-09-08 | `a91d1a5` → `8e1a6e1` | Non-admins can no longer assign or remove themselves as course teachers; nginx template and update.sh gain the course-media fall-through that was applied to this box by hand the same day. CI green on both legs. No migration, no dependency change, no asset change. `/login` 200, 72 migrations. The new media-route check in the verify step first runs on the next deploy (this one ran the previous script); run by hand afterwards: 302, as it should be. |
| 2026-09-08 | `8e1a6e1` → `8005672` | Uploaded lesson videos survive (Quill blot + sanitiser exemption). CI green on both legs. No migration, no dependency change. **Asset rebuild** — `quill-DKgO3A95.js` replaced by `quill-CRFb2Cuz.js`, verified served with the blot in it and the old file 404ing. First deploy where the verify step's course-media check ran: 302, as it should. `/login` 200, 72 migrations. |
| 2026-09-08 | `8005672` → `1732196` | Action menus for sections and materials. CI green on both legs. One migration (`indent` on `materials`, default 0). No dependency change. Six new routes, verified in the cached list. **Asset rebuild** — `app-DfeY9C-O.css` replaced by `app-Dy_K7Y4D.css`, verified served with the old file 404ing. Media-route check in the verify step: 302. `/login` 200, 73 migrations. |
| 2026-09-09 | `1732196` → `07af1b7` | Creating a course is now the `courses.create` permission rather than the admin role. CI green on both legs. One migration (creates the permission row, no backfill). No dependency change, no asset change. The create routes changed middleware; verified `permission:courses.create` on them in the cached list. First attempt failed before the script ran: the Windows ssh-agent had stopped overnight, so the key had to be reloaded. `/login` 200, 74 migrations. |
| 2026-09-09 | `07af1b7` → `a2bc0e6` | Manage Teachers holders may add themselves to a course again (self-removal still needs an admin). CI green on both legs. PHP only: no migration, no dependency change, no asset change. `/login` 200, 74 migrations. |
| 2026-09-10 | `a2bc0e6` → `4799056` | Calendar holidays no longer vanish when Month is clicked while on Month. CI green on both legs. No migration, no dependency change. **Asset rebuild** — `calendar-DM7NUVIi.js` replaced by `calendar-CgJKT8w5.js`, verified served with the painting module in it and the old file 404ing. `/login` 200, 74 migrations. |
| 2026-09-13 | `4799056` → `9bcc268` | Student import as a background job; /users column order and name sort (page + export); login password eye. CI green on both legs. Two migrations (`jobs`, `student_imports`). No dependency change. **Asset rebuild** (`app-CNdNG4S4.css`). **One-off after the script:** installed `deploy/tuition-queue.service` (enabled, active, running as www-data), then switched `.env` to `QUEUE_CONNECTION=database` and rebuilt the config cache — worker first, so nothing could queue before there was something to run it. Smoke-tested the queue with a harmless queued artisan command. `/login` 200, 76 migrations. |
| 2026-09-13 | `9bcc268` → `bc80717` | Import progress panel trimmed. CI green. No migration, no dependency change, no asset change. First deploy where `update.sh` restarts the queue worker: signal broadcast, worker back as a new process. `/login` 200, 76 migrations. |
| 2026-09-13 | `bc80717` → `1552d53` | The submissions ZIP streams instead of being built first. CI green on both legs. PHP only: no migration, no dependency change (ZipStream was already in vendor/ via the Excel library), no asset change. The verify step saw the worker as `activating` — caught mid-restart, settled to active. `/login` 200, 76 migrations. |
| 2026-09-13 | `1552d53` → `9f3f4d2` | The submissions ZIP announces its exact size (download percentage in the browser). CI green on both legs. PHP only: no migration, no dependency change, no asset change. Worker restarted and settled. `/login` 200, 76 migrations. |
| 2026-09-13 | `9f3f4d2` → `31f01f0` | Users permissions reordered and the new `users.delete_student` permission (bulk delete reaches students only; Delete unchanged). CI green on both legs. One migration (creates the permission row, no backfill). No dependency change, no asset change. Bulk-destroy route middleware verified as `permission:users.delete\|users.delete_student` in the cached list. Worker restarted: new PID, active and running. The verify step printed both `activating` and `not-installed` for the worker — a script bug (`is-active` exits non-zero for any state but active, so the fallback fired too), fixed in the next commit. `/login` 200, 77 migrations. No role holds the new permission yet; grant it on the roles screen. |
| 2026-09-14 | data only, code `31f01f0` | **Data operation, not a deploy.** Hard-deleted every student-role account on request: 2,791 users (399 live, all from the 2026-09-11 import, plus 2,392 already soft-deleted duplicates), cascading 3,191 enrollments, 2 submissions and 3 submission files; their 3 R2 objects removed. Role/permission pivot rows cleaned by hand (no FK). Two soft-deleted non-student accounts left as they were. Backups first: local `mysqldump` at `/root/backups/tuition-before-student-purge-20260913-193815.sql.gz` and the nightly backup command run to R2 (`backups/db-2026-09-14_033919.sql`). Username list kept at `/root/backups/purged-student-usernames-20260913-193919.txt`. Usernames are free for a fresh import. `/login` 200 afterwards. |
| 2026-09-14 | `31f01f0` → `d81e42c` | New public homepage edited on the page itself (`homepage.edit`), card images and reviewer photos, Facebook and Xiaohongshu contact types with per-contact icons. CI green on both legs; the first push (`55146d0`) failed the MySQL leg on an order-sensitive test assertion (MySQL re-sorts JSON object keys), fixed in `20c824b`. Three migrations. No dependency change. **Asset rebuild** (`app-C4V21JLp.css`), verified served, old file 404. Routes verified in the cached list; permission row present, held by no role yet — grant it on the roles screen. First attempt failed before the script ran: the Windows ssh-agent had stopped again, key reloaded. First deploy with the fixed worker line in the verify step: `active`, once. `/login` and `/` 200, 80 migrations. |
| 2026-09-14 | `d81e42c` → `be5ce90` | Full-width hero, wider page padding, built-in contact icons from `public/images/icons`. CI green on both legs. No migration, no dependency change. **Asset rebuild** (`app-DIvi795x.css`), verified served, old file 404; the four icon files verified 200 through nginx. Worker active. `/login` and `/` 200, 80 migrations. |
| 2026-09-14 | `be5ce90` → `fba179a` | Homepage `view` permission and the Settings sidebar order. CI green on both legs. One migration (permission row; roles holding `homepage.edit` also get `view` — none held it yet). No dependency change, no asset change. Worker active. `/login` 200, 81 migrations. |
| 2026-09-15 | `fba179a` → `ff3ab68` | Back-office homepage inside the admin layout, bottom bar removed. CI green on both legs. No migration, no dependency change. **Asset rebuild** (`app-DeoXEdWJ.css`), verified served, old file 404. Worker active. `/login` and `/` 200, 81 migrations. |
| 2026-09-15 | `ff3ab68` → `ae0ae1f` | Long words wrap inside feature cards and reviews. CI green on both legs. No migration, no dependency change. **Asset rebuild** (`app-C2u8UDK7.css`), verified served, old file 404. Worker active. `/login` and `/` 200, 81 migrations. |
| 2026-09-16 | `ae0ae1f` → `40aeb40` | Dashboard banner fills the full width. CI green on both legs. No migration, no dependency change. **Asset rebuild** (`app-CBNzmNUA.css`), verified served, old file 404. Worker active. `/login` and `/` 200, 81 migrations. |
| 2026-09-22 | `40aeb40` → `37979ab` | Six-character import passwords; login counter with its column on /users and in the export. CI green on both legs. One migration (`users.login_count`, default 0, existing accounts start at zero). No dependency change, no asset change. First attempt failed before the script ran: the Windows ssh-agent had stopped again, key reloaded. Worker active. `/login` and `/` 200, 82 migrations. |
| 2026-09-22 | `37979ab` → `e29b18c` | Course tab column order; the intermittent blank 401 (single-session check with no login redirect: five today, all the older device after the same account signed in elsewhere) now redirects to `/login?signed_out=elsewhere` with a notice; About icons without background. CI green on both legs. No migration, no dependency change. **Asset rebuild** (`app-OwG-B24v.css`), verified served, old file 404. Worker active. `/login` and `/` 200, the notice verified, 82 migrations. |
