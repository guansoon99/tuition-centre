# Deploying to staging

The staging box is a Hetzner **Windows Server 2019/2022 VPS** at `135.181.95.15`. Not the Ubuntu box `DEPLOY.md` describes — same app, different OS, different deploy commands.

## What's already installed on the box

| Tool | Path |
|---|---|
| PHP 8.3 | `C:\tools\php83\php.exe` |
| Composer | `C:\ProgramData\ComposerSetup\bin\composer.bat` |
| Git | `C:\Program Files\Git\cmd\git.exe` |
| OpenSSH Server | Windows built-in capability, service name `sshd`, port 22 |
| App checkout | `C:\Users\Administrator\tuition-centre` (git-tracked, tracks `origin/main`) |

## SSH access

Key-based auth only. My public key (`rexja@GuanSoon` on the dev machine) is installed at `C:\ProgramData\ssh\administrators_authorized_keys` — see "First-time SSH setup" below if you're provisioning a fresh box.

From the dev machine:

```bash
ssh -i ~/.ssh/hetzner_tuition Administrator@135.181.95.15
```

Password auth is left enabled for console-level recovery but should not be used for regular deploys. **Passwords in chat/PR/commit history are considered compromised — rotate anything that appears there.**

## Deploy command (one paste)

From an SSH session (or wrapped by Claude via SSH):

```
cd /d C:\Users\Administrator\tuition-centre && git fetch && git reset --hard origin/main && composer install --no-dev --optimize-autoloader --no-interaction && php artisan migrate --force && php artisan cache:clear && php artisan config:cache && php artisan route:cache && php artisan view:cache && echo === DEPLOY OK ===
```

What it does, in order:
1. `cd` into the project
2. `git fetch` + `git reset --hard origin/main` — mirror the remote exactly (wipes any accidental local edits on the box)
3. `composer install --no-dev` — install any new PHP deps
4. `php artisan migrate --force` — run pending migrations
5. `php artisan cache:clear` — **must come after migrate.** The app cache stores serialised Eloquent models, which carry the attribute set they had when written. A migration that adds or renames a column leaves warm entries silently missing it — reads return `null` instead of the real value, with no error, until the entry's TTL expires. Clearing here closes that window. Note `config:cache` and friends do *not* do this: they rebuild framework caches, not the application cache.
6. Rebuild `config`, `route`, and `view` caches
7. Print `=== DEPLOY OK ===` if every step succeeded (`&&` chain stops on the first error)

No queue worker restart, no PHP-FPM reload — Windows PHP handler picks up changes on the next request. If a queue worker gets set up later (via Task Scheduler or NSSM), add `php artisan queue:restart` between the caches and the `echo`.

## First-time SSH setup on a fresh Windows box

Only needed once per new server. Run in an **elevated PowerShell** on the box:

```powershell
# Install + enable OpenSSH Server
Add-WindowsCapability -Online -Name OpenSSH.Server~~~~0.0.1.0
Start-Service sshd
Set-Service -Name sshd -StartupType Automatic

# Firewall
if (!(Get-NetFirewallRule -Name "OpenSSH-Server-In-TCP" -ErrorAction SilentlyContinue)) {
    New-NetFirewallRule -Name "OpenSSH-Server-In-TCP" -DisplayName "OpenSSH Server (sshd)" -Enabled True -Direction Inbound -Protocol TCP -Action Allow -LocalPort 22
}

# Install the deploy public key. Admin-account keys go to a SPECIAL path
# (NOT ~/.ssh/authorized_keys) — Windows OpenSSH ignores per-user files
# for accounts in the Administrators group.
$key = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIMvZBi8u+c/kHVNZLr0Amo9nVOQ9cEYPDESBNi8gQ1wz rexja@GuanSoon'
$path = 'C:\ProgramData\ssh\administrators_authorized_keys'
if (!(Test-Path $path)) { New-Item -ItemType File -Path $path -Force | Out-Null }
Add-Content -Path $path -Value $key

# sshd refuses the key file unless perms are tight
icacls $path /inheritance:r /grant "Administrators:F" /grant "SYSTEM:F"

Restart-Service sshd
```

Optional (nicer remote shell — default is `cmd`):

```powershell
if (!(Test-Path 'HKLM:\SOFTWARE\OpenSSH')) { New-Item -Path 'HKLM:\SOFTWARE\OpenSSH' -Force | Out-Null }
New-ItemProperty -Path 'HKLM:\SOFTWARE\OpenSSH' -Name DefaultShell -Value 'C:\Windows\System32\WindowsPowerShell\v1.0\powershell.exe' -PropertyType String -Force
Restart-Service sshd
```

## Common issues

**"git is not recognized"** in cmd — Git installer sometimes doesn't add itself to the machine PATH. Fix per-session with:

```
set "PATH=%PATH%;C:\Program Files\Git\cmd"
```

Or add it permanently under System Properties → Environment Variables.

**"Access denied" writing to `C:\ProgramData\ssh\…`** — the PowerShell isn't elevated. Start menu → PowerShell → right-click → Run as administrator.

**Migration fails on the GB rename** — the `2026_08_11_130000_rename_max_file_size_mb_to_gb` migration expects `max_file_size_mb` to exist. If it was already dropped manually, comment out the migration temporarily or add it to `migrations` table by hand.

**Post-deploy 500** — clear the caches you just built and retry:

```
php artisan config:clear && php artisan route:clear && php artisan view:clear
```

Then re-run the deploy from `composer install` onwards. A stale cached config is the usual culprit.
