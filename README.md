# ServerAvatar OSS

A self-hosted server control panel with a Laravel API and a Next.js interface.
It runs on the server it manages: create applications, manage databases and
runtimes, deploy code, and handle routine server operations from a browser.
Optional Central integration provides remote management and access to paid addons.

> **Pre-1.0:** evaluate on a disposable server first. Features and platform support
> depend on the selected stack, installed runtimes, and upstream packages. This
> README describes the current source, not a guarantee of production readiness.

## What it does

- **Applications:** native PHP, Node.js and static sites, one-click installers
  such as WordPress, Nextcloud and n8n, and deploy-from-git applications. The
  Docker stack supports image-based apps, Compose projects and curated recipes.
- **Deployment and site operations:** GitHub, GitLab and Bitbucket accounts,
  signature-verified deploy webhooks, deployment history, environment editing,
  file management, background workers, domains and Let's Encrypt certificates.
  Clone, staging, application updates and server-to-server sync are available
  where the application type supports them.
- **Databases and runtimes:** manage MariaDB/MySQL, PostgreSQL and MongoDB where
  supported, database users, imports/exports and query monitoring; manage PHP
  versions and Node.js through fnm. The panel's own database defaults to SQLite.
- **Backups:** manual and scheduled application backups, standalone database
  backups, retention, verification, downloads and restores. Destinations are
  S3-compatible storage, FTP, SFTP and Google Drive (service account or OAuth).
  There is **no local archive destination**; temporary local files are not a
  substitute for configuring backup storage.
- **Security:** per-site Linux accounts, PHP isolation, firewall and fail2ban
  management, SSH settings, per-site 8G WAF and AI bot controls where supported.
- **Operations:** CPU/memory/disk metrics, service management and resource usage,
  logs, cron jobs, disk cleaning, task progress, activity and error logs,
  permission-based users/roles, and panel updates.
- **Localisation and branding:** English, Spanish, German, French, Portuguese,
  Japanese, Russian and Hindi; configurable branding and optional Central branding.

See the [API reference](backend/API_REFERENCE.md) for supported operations,
permissions and feature-specific restrictions.

## Requirements

Use a **fresh, dedicated server** with root access:

- Ubuntu **22.04, 24.04 or 26.04 LTS** on **x86_64 or aarch64**.
- At least **3 GB free on `/var`**, plus room for swap, applications, databases
  and temporary backup/restore files.
- Memory sized for your workloads. The installer sizes swap to provide at least
  **3072 MB RAM + swap** for the frontend build and at least **1024 MB swap**.
  This is a build budget, not a promise that every application fits a small VPS.
- Ports **80 and 443** available for the selected web server, reachable for HTTP
  traffic and certificate validation; outbound access to package repositories
  and other services used during installation.

[Supported platforms](SUPPORTED_PLATFORMS.md) documents upstream package caveats,
including MongoDB unavailability on Ubuntu 26.04 and LSPHP version differences.
Recheck dated vendor availability before choosing a platform.

## Installation

The installer changes packages, users, services and system configuration. Do not
run it on your workstation or an existing production server as a development
setup. Review [install.sh](install.sh) before running it.

```bash
curl -fsSL https://raw.githubusercontent.com/DipaliRadadiya/open-source-sa/main/install.sh \
  -o install.sh
sudo bash install.sh --stack=lemp
```

Choose one of five installation profiles:

| Flag | Web server / application profile |
|---|---|
| `--stack=lemp` | nginx; native PHP, Node.js and static applications (default) |
| `--stack=lamp` | Apache; native PHP, Node.js and static applications |
| `--stack=mern` | nginx; Node.js and static applications, not hosted PHP apps |
| `--stack=ols` | OpenLiteSpeed; native PHP, Node.js and static applications |
| `--stack=docker` | nginx + Docker; container applications only |

The panel itself still needs PHP and Node.js regardless of the hosted application
profile. Installing a runtime does **not** make all application types available
on every stack. The API's site-type catalog reflects the server's capabilities;
application database/runtime requirements still apply. For example, NodeBB needs
MongoDB or PostgreSQL, not MySQL/MariaDB.

Without `--stack`, an interactive terminal prompts; a non-interactive run defaults
to LEMP. Other supported options:

| Option | Purpose |
|---|---|
| `--domain=panel.example.com` | Panel hostname; point its A record at the server first. |
| `--email=you@example.com` | Contact for Let's Encrypt expiry warnings. |
| `--no-ssl` | Serve plain HTTP, for example behind a separately secured proxy. |
| `--dry-run` | Run checks and print the plan without changing the server. |
| `--branch=main` | Select the source branch. |
| `--repo=URL` | Select a fork or mirror. |
| `--force` | Bypass the fresh-server guard; **not** a migration or compatibility guarantee. |

Without a domain, the installer derives a nip.io hostname from the server's IP.
Certificate issuance can hit shared rate limits; the installer can fall back to a
self-signed certificate. Use your own hostname for a publicly trusted deployment.

On completion, open the printed URL. Unless an administrator was supplied during
installation, the first registered account becomes administrator and public
registration closes. Finish the setup checks before creating applications.

The fresh-server guard refuses conflicting panels, sites, databases and other
existing workloads. A recognised installation can be rerun after fixing a failed
step. Rerunning is not a general recovery tool for arbitrary existing servers;
inspect the error and preserve backups before retrying or using `--force`.

### OpenLiteSpeed differences

OLS runs the panel and hosted PHP sites on **LSPHP**, not PHP-FPM. Hosted PHP sites
have per-site external processors, users and sockets: **PHP isolation exists**,
although there is no FPM-pool isolation toggle. Per-site PHP settings use an ini
file. Available PHP versions/extensions depend on LiteSpeed's packages.

Only WordPress enables automatic `.htaccess` loading; other sites receive their
routing rules through the generated vhost. WAF detect-mode matches are marked in
the site's access log (`waf=1`) rather than a separate WAF log. Do not assume
Apache/FPM configuration instructions apply unchanged to OLS.

### Automated installs and Central

Automation can provide `PANEL_ADMIN_USERNAME` and `PANEL_ADMIN_PASSWORD` to create
the first administrator during installation. The password must be 12–128
characters. Keep passwords and tokens out of command arguments, shell history
and logs; inject them through your provisioning system's secret environment.

`CENTRAL_TOKEN` can provision the management connection, and `PROGRESS_URL` can
receive best-effort HTTPS JSON progress reports authenticated with that token.
The corresponding non-secret flags include `--admin-username` and
`--progress-url`; prefer the environment variable over `--central-token` for the
secret. Use `sudo --preserve-env` deliberately when passing provisioning variables
through sudo. Progress-report failures do not stop installation.

Central management is optional: administrators can enable/rotate or revoke its
management token. The API also supports ordinary Sanctum bearer tokens and browser
sessions. Central cannot use its token to change panel users or roles.

**Network behaviour:** this is not an offline-only panel. Installers, release and
runtime metadata, certificate issuance, configured storage/integrations and
Central features contact external services. Central's public branding endpoint
has a configured default; explicitly set `CENTRAL_URL=` in the backend environment
(and clear cached configuration) to disable that branding lookup. Revoking the
management token and disabling branding lookup are separate settings.

### Optional paid addons

The OSS backend includes **Central-only API adapters** for WP Toolkit
(`wp-toolkit`) and Log Monitoring Suite (`log-monitoring-suite`, repository
[`srvrsoorg/log-monitoring-suite`](https://github.com/srvrsoorg/log-monitoring-suite)).
These are separate CLI binaries, not built-in OSS screens. They must be
provisioned separately for Central to use them and perform their own licence
checks. Installing the OSS panel does not install, update or unlock them. See [Central paid addons](backend/API_REFERENCE.md#central--paid-addons-wp-toolkit-log-monitoring-suite)
for the bounded operations and error responses; this repository does not provide
a standalone addon installation/update guide.

## Migration baseline — 23 October 2026

The migration history was consolidated into a **fresh-install baseline** (files
named `2026_10_24_*`). Older panels must be **reinstalled** for this baseline;
do not treat a normal update or `php artisan migrate` as an upgrade path for their
old databases. Preserve application data and verified off-server backups before
reinstallation. There is no general v7 migration promise here.

For future changes, **add new, reversible migrations after the baseline**. Do not
edit the baseline create-table migrations to change an installed schema.

## Development and checks

Use an isolated checkout and a disposable Linux VM for real server-operation
work. API actions can execute privileged host commands; a local web server is
not a sandbox. See [backend setup](backend/README.md) for Composer, database,
queue and scheduler instructions.

The frontend uses Node.js (the installer defaults to Node 24), Next.js 16,
React 19 and Tailwind 4:

```bash
cd frontend
npm ci
```

Create `frontend/.env.local` with local origins, not deployed server addresses:

```dotenv
NEXT_PUBLIC_API_URL=http://localhost:8000
NEXT_PUBLIC_APP_URL=http://localhost:3000
```

Run `npm run dev` and the backend separately. Keep the same hostname on both
origins for browser session authentication. The backend CORS/Sanctum settings must
allow the frontend origin.

Checks provided by the current manifests/configuration:

```bash
# From backend/ (after setup)
composer test
vendor/bin/pint --test
vendor/bin/phpstan analyse --no-progress

# From frontend/
npm run lint
npm test
npm run build
```

The backend also has a small Vite asset build (`npm ci && npm run build` inside
`backend/`); it is separate from the Next.js interface. Do not substitute the
Laravel skeleton's `composer setup`/`composer dev` scripts for the complete panel
setup documented in the backend guide.

## Repository layout and contributing

```text
backend/                 Laravel API, server operations and Pest tests
backend/API_REFERENCE.md API contracts and restrictions
frontend/                Next.js interface and frontend checks
install.sh               server installer
SUPPORTED_PLATFORMS.md   OS and upstream-package caveats
```

Use Pest for backend tests and cover validation and authorization for changed
endpoints. Keep user-facing strings in translation dictionaries across all eight
locales. Match existing services, Form Requests, Resources and policies; do not
put server-operation logic directly in controllers. Keep secrets and generated
files out of commits. Run the relevant tests, formatting/static analysis and
build checks before submitting a pull request.

**Licence:** there is currently no project licence file at the repository root.
Dependency/framework licences do not establish the licence for this project;
clarify permitted use and redistribution with the maintainers.
