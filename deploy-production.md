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
