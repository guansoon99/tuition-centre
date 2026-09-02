# Deploying to staging

**Trigger: "deploy staging" means this document and this server.**

This is *not* the target described in [DEPLOY.md](DEPLOY.md). That file plans a
production box — Ubuntu 24.04, nginx, MySQL, `php8.3-fpm`. Staging is a Windows
server running the app off `artisan serve` with SQLite. Do not mix the two
procedures; almost every command differs.

## The server

| | |
| --- | --- |
| IPv4 | `135.181.95.15` |
| IPv6 | `2a01:4f9:c010:b4fc::/64` |
| Provider | Hetzner VPS |
| OS | **Windows Server 2022 Standard** (10.0.20348) |
| SSH user | **`Administrator`** |
| SSH key | `~/.ssh/hetzner_tuition` (ed25519, `SHA256:sH49WtpYts6m4WrWxpIhQtrLYHF6HeIy+mnFsQKookc`) |
| App path | `C:\Users\Administrator\tuition-centre` |
| URL | http://135.181.95.15 |

⚠️ **There is no `root` user.** This is Windows. `ssh root@135.181.95.15` fails
with `Permission denied (publickey,password,keyboard-interactive)`, which looks
exactly like a rejected key and sends you chasing a key problem that isn't
there. The username is the whole fix.

Note also that the machine is shared with unrelated software (`C:\Jts`,
`C:\IBC` — Interactive Brokers). Only `C:\Users\Administrator\tuition-centre`
is ours.

```bash
ssh -i ~/.ssh/hetzner_tuition Administrator@135.181.95.15
```

## The stack

| | |
| --- | --- |
| PHP | 8.3.14 (NTS, VC++ 2019 x64) at `C:\tools\php83\php.exe` |
| Web server | `php artisan serve --host=0.0.0.0 --port=80` — no nginx, no IIS |
| Database | **SQLite** — `database\database.sqlite` |
| Cache / session | `file` |
| Filesystem disk | `local` |
| `APP_ENV` | `production` (`APP_DEBUG=false`) — despite being staging |
| git | `C:\Program Files\Git\cmd\git.exe` |
| composer | `C:\ProgramData\ComposerSetup\bin\composer.bat` |
| node / npm | **not installed** — see [Frontend assets](#frontend-assets) |

## Deploy

Run these from your own machine. One SSH call per step, so a failure stops
where it happened rather than half-applying.

### 1. Update the code

```bash
ssh -i ~/.ssh/hetzner_tuition Administrator@135.181.95.15 "cd C:\Users\Administrator\tuition-centre && git fetch origin && git reset --hard origin/main && git log --oneline -1"
```

`git reset --hard` does **not** delete untracked files, which is what keeps the
server's `.env` and `.env-r2` safe. Never run `git clean` here — it would take
both, and neither is in the repo.

### 2. Dependencies — only if they changed

Check first, from the local repo:

```bash
git diff --name-only <deployed-sha> origin/main -- composer.json composer.lock
```

Nothing printed means skip this step. If it did print something:

```bash
ssh -i ~/.ssh/hetzner_tuition Administrator@135.181.95.15 "cd C:\Users\Administrator\tuition-centre && composer install --no-dev --optimize-autoloader"
```

### 3. Migrations

```bash
ssh -i ~/.ssh/hetzner_tuition Administrator@135.181.95.15 "cd C:\Users\Administrator\tuition-centre && php artisan migrate --force"
```

`--force` is required because `APP_ENV=production` — without it the command
waits on an interactive confirmation that never arrives over SSH.

**No database backup is needed.** This is staging and its data is disposable.
(If you ever want one anyway:
`copy /Y database\database.sqlite database\database.sqlite.bak-YYYYMMDD`. One
from 2026-08-19 is already sitting there and can be deleted whenever.)

### 4. Caches — clear, then rebuild

```bash
ssh -i ~/.ssh/hetzner_tuition Administrator@135.181.95.15 "cd C:\Users\Administrator\tuition-centre && php artisan cache:clear && php artisan config:cache && php artisan route:cache && php artisan view:cache"
```

**Order matters: `cache:clear` after `migrate`, never before.** The file cache
can hold serialised Eloquent models written before the migration; those
deserialise with the old attribute set, so newly added columns silently read as
`null` until the entry expires. Clearing after the schema changes is what avoids
it.

This server runs with config and routes cached, so **a deploy that adds a route
will 404 until `route:cache` reruns.** It is not optional.

❌ **Do not run `php artisan filament:cache-components`.** `DEPLOY.md` lists it,
but Filament is not a dependency of this project
(`grep -c filament composer.json` → `0`) and the command fails with *"There are
no commands defined in the filament namespace"*. Harmless, but it is not a step.

### 5. Verify

```bash
curl -s -o /dev/null -w "status=%{http_code}\n" http://135.181.95.15/
```

Expect `status=200`. Then confirm the schema and any new routes landed:

```bash
ssh -i ~/.ssh/hetzner_tuition Administrator@135.181.95.15 "cd C:\Users\Administrator\tuition-centre && php artisan migrate:status && php artisan route:list"
```

## Rollback

```bash
ssh -i ~/.ssh/hetzner_tuition Administrator@135.181.95.15 "cd C:\Users\Administrator\tuition-centre && git reset --hard <previous-sha> && php artisan cache:clear && php artisan config:cache && php artisan route:cache && php artisan view:cache"
```

Reverting code does **not** revert migrations. If the bad deploy migrated, roll
the schema back too (`php artisan migrate:rollback --force`) or restore a SQLite
copy — otherwise old code meets a newer schema.

## Frontend assets

**node and npm are not installed on this server**, so `npm run build` cannot run
there — and never needs to, because `public/build/` is committed to the repo.
The server receives compiled CSS and JS through `git reset --hard` like any
other file.

The consequence: **any change to Tailwind classes must be built locally and
committed, or staging will not have it.** New utility classes — arbitrary values
like `grid-cols-[13rem,1fr]` especially — produce no CSS at all until the build
runs. Locally:

```bash
npm run build
```

then commit the changed files under `public/build/` alongside the templates.

## Restarting the web server

A normal deploy needs no restart. PHP's built-in server re-reads source files on
every request, so updated code is live as soon as `git reset --hard` finishes;
config and routes come from the cache files rebuilt in step 4.

Restart only if the process died, or if code changes visibly fail to appear.

**Stop** — this kills both the `artisan serve` parent and the `php -S` child it
spawns. Nothing else on this box runs PHP, so matching by name is safe:

```bash
ssh -i ~/.ssh/hetzner_tuition Administrator@135.181.95.15 "powershell -NoProfile -Command \"Stop-Process -Name php -Force\""
```

**Start** — this has to go through WMI:

```bash
ssh -i ~/.ssh/hetzner_tuition Administrator@135.181.95.15 "powershell -NoProfile -Command \"Invoke-CimMethod -ClassName Win32_Process -MethodName Create -Arguments @{CommandLine='C:\tools\php83\php.exe artisan serve --host=0.0.0.0 --port=80'; CurrentDirectory='C:\Users\Administrator\tuition-centre'}\""
```

⚠️ **`Start-Process` does not work here.** Verified 2026-08-19: a server started
with `Start-Process -WindowStyle Hidden` comes up fine and then dies the instant
the SSH session closes — so it answers while you are connected and is gone by
the time you check from anywhere else. `Invoke-CimMethod` creates the process
outside the session and it survives. Both were tested on port 8080, leaving port
80 alone; the WMI one returned `HTTP 200` from a fresh session, the
`Start-Process` one returned `Unable to connect to the remote server`.

⚠️ **`artisan serve` is not a service and has no scheduled task.** It was
started by hand, so nothing brings it back: if the process dies or the server
reboots, staging stays down until someone runs the start command above. Making
it a scheduled task with *Run whether user is logged on or not* at startup would
fix that permanently.

`artisan serve` is also single-threaded — one request at a time. Fine for
staging, unusable for production.

## Gotchas worth knowing before you debug something else

**SSH lands in `cmd.exe`, not a POSIX shell.** A `|` inside a quoted PowerShell
command gets eaten by `cmd` as a pipe:

```
'tuition' is not recognized as an internal or external command
```

For anything with pipes or nested quotes, send it base64-encoded instead:

```bash
ENC=$(printf '%s' 'Get-Process | Select-Object Name' | iconv -f UTF-8 -t UTF-16LE | base64 -w0); ssh -i ~/.ssh/hetzner_tuition Administrator@135.181.95.15 "powershell -NoProfile -EncodedCommand $ENC"
```

**PowerShell output over SSH can arrive as CLIXML** — a wall of
`<Objs Version="1.1.0.1" ...>` after the real output. That is progress-stream
noise, not an error. Ignore it, or avoid cmdlets that emit progress records.

**Ports currently open to the internet:** 22, 80, 135, 139, 445, 3389, 5985,
47001. RDP (3389) and SMB (445) being world-reachable on a box that also runs
brokerage software is worth firewalling to a known IP.

**Untracked files that must never be deleted:** `.env`, `.env-r2`. They hold
this server's configuration and are deliberately absent from git.

## Deployment log

| Date | From → to | Notes |
| --- | --- | --- |
| 2026-08-19 | `b96de7a` → `e3152fd` | Two migrations (`last_modified_at`, `feedback_files`). No dependency change. `filament:cache-components` failed — removed from the procedure. |
| 2026-09-02 | `e7129cc` → `4cd32b2` | One migration (`collapsed` on `user_collapsed_sections`). No dependency change, no asset change. New route `courses.fold-sections`, so `route:cache` mattered. |
| 2026-09-02 | `4cd32b2` → `fb56269` | No migration, no dependency change, no asset change. *Removed* the route `courses.fold-sections`, so `route:cache` mattered again — a stale cache would have kept the deleted endpoint answering. Verified 404 afterwards. |
