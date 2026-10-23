# ServerAvatar OSS backend

Laravel 13 API for the self-hosted ServerAvatar OSS panel. It manages the **local
server**, rather than connecting to managed machines through an SSH agent. The
browser interface lives in [`../frontend/`](../frontend/).

- [Server installation and stack selection](../README.md#installation)
- [Supported platforms](../SUPPORTED_PLATFORMS.md)
- [API reference](API_REFERENCE.md) — routes, permissions, response shapes and restrictions

This guide describes the current source and a development setup. It is **not**
a production provisioning runbook. For a real panel, use the root install guide
on a fresh supported server.

## Prerequisites and safety

- **PHP 8.4** (the installer default), Composer, and the extensions required by
  the locked dependencies. Typical needs include PDO SQLite, mbstring, XML,
  cURL, intl, bcmath and ZIP; use Composer's platform check to verify your PHP.
- SQLite for the default panel database; MySQL/MariaDB or PostgreSQL can be
  configured instead. This is separate from the databases hosted for applications.
- Node.js and npm for the separate Next.js frontend and optional backend Vite
  asset build. The server installer defaults to Node 24.
- Redis and the PHP Redis extension if selecting Redis-backed cache, sessions
  and queues. The example environment defaults all three to the database driver.

Use a disposable Linux VM for operations that create users, install runtimes,
write web-server configuration, or manage services. Running `artisan serve`
does not sandbox those operations. **Do not** copy production sudoers, systemd,
firewall or privileged installer steps into a workstation setup.

## Local bootstrap

From the repository root:

```bash
cd backend
composer install
composer check-platform-reqs
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
```

The copy command is for a **new checkout**; do not overwrite an existing `.env`.
Edit it for your local environment:

```dotenv
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8000
FRONTEND_URL=http://localhost:3000
DB_CONNECTION=sqlite
SESSION_DRIVER=database
QUEUE_CONNECTION=database
CACHE_STORE=database
CENTRAL_URL=
```

Leave `DB_DATABASE` unset to use `database/database.sqlite`, or set an absolute
path. The example enables SQLite WAL and a busy timeout for concurrent access.
Keep `APP_KEY` private and stable: changing it can make encrypted integration
credentials unreadable. `CENTRAL_URL=` explicitly disables the optional Central
branding lookup for this local setup.

Initialise the schema and permission catalog:

```bash
php artisan migrate
php artisan db:seed --class=PermissionSeeder
php artisan serve --host=127.0.0.1 --port=8000
```

Use the first-user registration flow to create your administrator. Registration
closes after the first account. `PermissionSeeder` is idempotent and synchronises
the permission catalog and protected Administrator role. Do **not** use a plain
`php artisan db:seed` for a real panel: `DatabaseSeeder` creates a development
administrator with fixed credentials. `DemoDataSeeder` is development/test data,
not a required installation step.

The migration history was consolidated on **23 October 2026** into the
`2026_10_24_*` fresh-install baseline. **Older panels require reinstallation**;
running these migrations on their old database is not a supported upgrade path.
For subsequent schema changes, add a new reversible migration after the baseline;
do not edit its create-table files. See the [root migration note](../README.md#migration-baseline--23-october-2026).

## Frontend and authentication

Follow the [root development instructions](../README.md#development-and-checks)
for `frontend/` dependencies and local environment variables. The frontend's
`NEXT_PUBLIC_API_URL` is the backend origin **without** an `/api` suffix; clients
append `/api` themselves. For example, pair `http://localhost:8000` with
`http://localhost:3000`, rather than mixing `localhost` and `127.0.0.1`.

Routes are under `/api`, with **no `/api/v1` prefix**. Sanctum supports bearer
tokens and browser cookie/session authentication. Local frontend ports are in
`config/cors.php`; configure `FRONTEND_URL` and `SANCTUM_STATEFUL_DOMAINS` when
using other hosts. Cookie clients first request `/sanctum/csrf-cookie` and send
credentials with subsequent requests. See the [API reference](API_REFERENCE.md)
for endpoint-specific authorization and rate limits.

## Queue, cache and scheduler

Provisioning, deployments, backups and other long operations require a worker.
On a disposable development server, run it in a separate terminal:

```bash
php artisan queue:work --queue=high,default --tries=1 --timeout=0
```

The `high` queue carries priority work such as certificates; listening only to
`default` leaves that work pending. The production installer manages a worker
service; the command above is a foreground development process. Restart workers
after code changes so they do not keep executing previously loaded classes.

For Redis-backed operation, configure `REDIS_HOST`, `REDIS_PORT` and, where
needed, `REDIS_PASSWORD`, then set **all three** of `SESSION_DRIVER`,
`QUEUE_CONNECTION` and `CACHE_STORE` to `redis`. Otherwise the database defaults
work with the migrated tables. Do not assume Redis is being used just because
it is installed. After environment changes, clear cached configuration:

```bash
php artisan config:clear
```

Scheduled maintenance is declared in `routes/console.php`: metrics, runtime
metadata refresh, due backups, certificate expiry refresh and cleanup jobs among
others. On an appropriate disposable server, use a separate terminal:

```bash
php artisan schedule:list
php artisan schedule:work
```

The scheduler can trigger **real host operations** against configured resources;
inspect those schedules before starting it. Production uses the installer's
scheduled execution rather than a development terminal. Do not run multiple
scheduler setups unintentionally.

## Architecture

```text
app/Actions/                 single-purpose application/auth/admin actions
app/Http/Controllers/        API and admin request handlers
app/Http/Requests/           validated, authorized Form Requests
app/Http/Resources/          response serializers
app/Services/                application orchestration and integrations
app/Services/Server/         local server operations and stack-specific drivers
app/Services/Addons/         bounded adapters for separate paid CLI addons
app/Contracts/               extension-point interfaces
app/Jobs/                    queued operations
app/Models/                  Eloquent models and casts
app/Support/                 shared helpers and scheduling support
config/                      server paths, capabilities, integrations and framework config
routes/api.php               API entry point and route-group middleware
routes/api/server/           permission-gated operational API
routes/api/admin/            administrator API
routes/console.php           scheduled commands
resources/recipes/           Docker recipe metadata and Compose templates
lang/                        translated API messages
```

Keep controllers thin, validate writes in Form Requests, serialize through
Resources, and enforce authorization with the existing gates/policies. Server
commands belong in the server-operations layer, not inline shell code in a
controller. Stack capabilities determine available site types: native PHP/Node/
static on LEMP/LAMP/OLS, Node/static on MERN, containers on Docker. Match existing
patterns rather than assuming one stack's paths or configuration applies to all.

Central integration is optional and distinct from ordinary user authentication.
The paid WP Toolkit and Log Monitoring Suite adapters are Central-only; they do
not install the separate binaries or implement their licensing. See the
[root integration notes](../README.md#optional-paid-addons) and API reference.

## Tests, formatting and static analysis

From `backend/`, after installing development dependencies:

```bash
composer test
vendor/bin/pest tests/Feature
vendor/bin/pint --test
vendor/bin/phpstan analyse --no-progress
php artisan route:list
```

`composer test` clears cached configuration and runs the Pest-backed Laravel
suite. `phpunit.xml` uses an in-memory SQLite database, array cache/sessions,
synchronous queues and test-specific server-operation settings. Tests should
fake host commands and network integrations: do not point them at production
resources. New endpoints need success, validation and authorization coverage;
keep user-facing messages translated in all eight locales.

To format changed PHP intentionally, use `vendor/bin/pint` and review its diff.
Static analysis is configured in `phpstan.neon` through Larastan. These commands
are the checks provided by the project, not a claim that any particular checkout
has already passed them.

The backend also retains a small Vite asset build:

```bash
npm ci
npm run build
```

This does not build the Next.js interface. The skeleton `composer setup` and
`composer dev` scripts combine Laravel/Vite defaults; they do not replace the
permission seeding, separate frontend setup, priority queue or production
provisioning described above.

## Configuration and troubleshooting

Use `.env.example` and the relevant `config/` files as the configuration map.
Do not commit `.env`, application keys, tokens, storage credentials or generated
artifacts. Use `php artisan config:clear` after local configuration changes and
restart long-lived queue workers after code changes. Check `storage/logs/` and
queued-job failures when an operation remains pending; the API's error reference
links failures to the panel error log.

Production installation configures privileged access, service units and
stack-specific paths. A locally booted API intentionally does not reproduce all
of that: use the supported installer in a disposable VM when testing those
features, rather than granting a workstation-wide sudo policy to make a request
succeed.
