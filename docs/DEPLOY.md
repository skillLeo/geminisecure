# Deploying GeminiSecure on Windows

This guide is for whoever has an **Administrator** shell on the host. The build
never had one, so these steps have not been run here. Every command is exact for
this machine's paths. Where your paths differ, change the path and nothing else.

| Piece | Where it runs | Without it |
| --- | --- | --- |
| MySQL 8.4 | Windows service `MySQL84`, port **3307** | Nothing works. It does not come back after a reboot. |
| Reverb | Service `GeminiSecure-Reverb`, `127.0.0.1:8080` | Dispatch screens fall back to polling. Alerts arrive late, and the shift bar says so. |
| Queue worker | Service `GeminiSecure-Queue` | **No PDF is ever rendered.** Statements, receipts, minutes and certificates stay "being produced" forever. |
| Scheduler | Service `GeminiSecure-Scheduler` | No automated arrears reminders (`dunning:run`, 09:00 Jamaica time). |

Paths assumed below:

```
PHP        C:\tools\php\php.exe
App        C:\xampp\htdocs\geminisecure
MySQL      C:\Program Files\MySQL\MySQL Server 8.4\bin\mysqld.exe
my.ini     C:\ProgramData\MySQL\MySQL Server 8.4\my.ini
Logs       C:\xampp\htdocs\geminisecure\storage\logs
```

---

## 1. MySQL 8.4 as a service (port 3307)

MySQL registers itself. Stop any foreground `mysqld` first, so the service can
take port 3307.

```powershell
# Administrator PowerShell
Get-Process mysqld -ErrorAction SilentlyContinue | Stop-Process -Force

& "C:\Program Files\MySQL\MySQL Server 8.4\bin\mysqld.exe" --install MySQL84 --defaults-file="C:\ProgramData\MySQL\MySQL Server 8.4\my.ini"
Set-Service MySQL84 -StartupType Automatic
Start-Service MySQL84

# Check: listening on 3307
Get-NetTCPConnection -LocalPort 3307 -State Listen
```

`--defaults-file` is the file the foreground process already uses, so the
service starts on 3307 with the same configuration. XAMPP's MariaDB on 3306 is
separate and untouched (D-003).

---

## 2. NSSM, which turns PHP commands into services

`php.exe` is not a Windows service binary, so `sc.exe create` cannot run it
directly. NSSM wraps it, restarts it when it exits, and writes its output to a
log.

```powershell
# Administrator PowerShell
winget install --id NSSM.NSSM -e
# or download nssm 2.24 from https://nssm.cc/download and put nssm.exe on PATH

nssm version
```

---

## 3. Reverb as a service (127.0.0.1:8080)

```powershell
# Administrator PowerShell
nssm install GeminiSecure-Reverb "C:\tools\php\php.exe" "artisan reverb:start --host=127.0.0.1 --port=8080"
nssm set GeminiSecure-Reverb AppDirectory "C:\xampp\htdocs\geminisecure"
nssm set GeminiSecure-Reverb AppStdout "C:\xampp\htdocs\geminisecure\storage\logs\reverb.log"
nssm set GeminiSecure-Reverb AppStderr "C:\xampp\htdocs\geminisecure\storage\logs\reverb.log"
nssm set GeminiSecure-Reverb AppExit Default Restart
nssm set GeminiSecure-Reverb Start SERVICE_AUTO_START
nssm start GeminiSecure-Reverb

Get-NetTCPConnection -LocalPort 8080 -State Listen
```

`REVERB_HOST` and `REVERB_PORT` in `.env` must match (`127.0.0.1`, `8080`).

---

## 4. The queue worker as a service

**PDFs do not render without this.** `QUEUE_CONNECTION=database`, so the worker
also depends on MySQL.

```powershell
# Administrator PowerShell
nssm install GeminiSecure-Queue "C:\tools\php\php.exe" "artisan queue:work --sleep=3 --tries=3 --max-time=3600"
nssm set GeminiSecure-Queue AppDirectory "C:\xampp\htdocs\geminisecure"
nssm set GeminiSecure-Queue AppStdout "C:\xampp\htdocs\geminisecure\storage\logs\queue.log"
nssm set GeminiSecure-Queue AppStderr "C:\xampp\htdocs\geminisecure\storage\logs\queue.log"
nssm set GeminiSecure-Queue DependOnService MySQL84
nssm set GeminiSecure-Queue AppExit Default Restart
nssm set GeminiSecure-Queue Start SERVICE_AUTO_START
nssm start GeminiSecure-Queue
```

`--max-time=3600` makes the worker exit every hour, and NSSM starts a fresh one.
A long-lived PHP process that renders PDFs leaks memory, and a planned restart
avoids that.

---

## 5. The scheduler as a service

```powershell
# Administrator PowerShell
nssm install GeminiSecure-Scheduler "C:\tools\php\php.exe" "artisan schedule:work"
nssm set GeminiSecure-Scheduler AppDirectory "C:\xampp\htdocs\geminisecure"
nssm set GeminiSecure-Scheduler AppStdout "C:\xampp\htdocs\geminisecure\storage\logs\scheduler.log"
nssm set GeminiSecure-Scheduler AppStderr "C:\xampp\htdocs\geminisecure\storage\logs\scheduler.log"
nssm set GeminiSecure-Scheduler DependOnService MySQL84
nssm set GeminiSecure-Scheduler AppExit Default Restart
nssm set GeminiSecure-Scheduler Start SERVICE_AUTO_START
nssm start GeminiSecure-Scheduler

# Check: dunning:run is listed at 09:00 America/Jamaica
C:\tools\php\php.exe C:\xampp\htdocs\geminisecure\artisan schedule:list --timezone=America/Jamaica
```

---

## 6. The environment file

Copy `.env.example` to `.env` and fill it in **before** any `artisan` command.
Production refuses to boot on an undeliverable mailer (13 B7):

| Key | Production value |
| --- | --- |
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_KEY` | `php artisan key:generate`, first install only |
| `DB_PORT` | `3307` |
| `MAIL_MAILER` | `smtp`. **Never `log` or `array`**: the app throws at boot. |
| `MAIL_HOST`, `MAIL_PORT`, `MAIL_SCHEME`, `MAIL_USERNAME`, `MAIL_PASSWORD` | Your SMTP relay's. The `replace-with…` placeholders also refuse to boot. |
| `MAIL_FROM_ADDRESS` | An address on a domain you own, with SPF/DKIM set up, or invitations land in spam. |
| `QUEUE_CONNECTION` | `database` |
| `GS_SIMULATOR_ENABLED` | `false` |

---

## 7. Each release

Run these in order, in a normal (not elevated) shell, from `C:\xampp\htdocs\geminisecure`:

```powershell
composer install --no-dev --optimize-autoloader
npm ci
npm run build

php artisan down

php artisan migrate --database=mysql_owner --force   # central (gs_platform) first
php artisan tenants:migrate --force                  # then every estate
php artisan grants:append-only                       # central: audit log and Gemini's journals insert-only
php artisan grants:estates                           # every estate: journals and ballots insert-only

php artisan config:cache
php artisan route:cache                              # production only: local mode registers path-based
                                                     # estate routes under the same names, and caching refuses
php artisan view:cache

php artisan queue:restart                            # the worker picks up the new code on its next job
php artisan up
```

Then restart the long-running services so they load the new code:

```powershell
# Administrator PowerShell
nssm restart GeminiSecure-Reverb
nssm restart GeminiSecure-Scheduler
```

### Checks after each release

```powershell
php artisan gate:isolation      # tenant isolation and append-only enforcement
php artisan gate:ledger         # every estate's books balance; retention triggers installed
php artisan gate:console        # role access to every console route
php artisan dunning:run --dry-run
Get-Service MySQL84, GeminiSecure-Reverb, GeminiSecure-Queue, GeminiSecure-Scheduler
```

All four services should read `Running`.

---

## 8. A new estate

```powershell
php artisan estate:provision <subdomain> "<Estate Name>" --status=onboarding --receipt-prefix=<PFX>
php artisan estate:receipt-prefix <subdomain>        # confirm it before the estate's first receipt
```

The receipt prefix is fixed from the first receipt on (D-089).

---

## 9. Removing a service

```powershell
# Administrator PowerShell
nssm stop GeminiSecure-Queue
nssm remove GeminiSecure-Queue confirm
```

Remove MySQL's service with `Stop-Service MySQL84` and then
`& "C:\Program Files\MySQL\MySQL Server 8.4\bin\mysqld.exe" --remove MySQL84`.
