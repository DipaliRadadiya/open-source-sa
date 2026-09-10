# open-source-sa

A self-hosted server control panel. Install it on a fresh Ubuntu server and manage
sites, databases, runtimes and security from a browser — no SSH for day-to-day work.

It runs **on** the server it manages. There is no agent, no central service, and
nothing phones home.

```bash
curl -fsSL https://raw.githubusercontent.com/DipaliRadadiya/open-source-sa/main/install.sh \
  | sudo bash -s -- --stack=lemp
```

The installer prints a URL when it finishes. Open it, register — the first account
becomes the administrator and registration then closes — and the setup page walks
you through anything else the server needs.

> **Pre-1.0.** Read [Status](#status) before putting anything you care about on it.

---

## What it does

**Applications.** 17 site types, installed in one step: WordPress, Nextcloud,
Joomla, Moodle, Mautic, PrestaShop, Craft CMS, Statamic, Akaunting, phpMyAdmin,
Uptime Kuma, n8n, Node-RED, NodeBB, plus plain PHP, static, and deploy-from-git.

**Deploy from git.** Connect GitHub, GitLab or Bitbucket with a token, or point at
a public repository. Push to the tracked branch and it deploys — signature-verified
webhooks, one queued deploy per burst rather than one per commit.

**Databases.** MariaDB, MySQL, PostgreSQL or MongoDB. The panel installs the
engine, creates its own credentialed account, and manages databases and users per
site.

**Runtimes.** Multiple PHP versions side by side, and Node versions through fnm, so
each site pins what it needs.

**Security.** ufw firewall, fail2ban, Let's Encrypt certificates, SSH hardening,
per-site Linux users so one site cannot read another's files.

**Operations.** Live CPU/memory/disk metrics, per-service resource usage, log
viewer, cron jobs with captured output, scheduled disk cleaner, and an activity log
of who did what.

**Eight languages.** English, Spanish, German, French, Portuguese, Japanese,
Russian and Hindi — including error messages, which are rendered in the *reader's*
language rather than the language of whoever caused them.

## Requirements

- Ubuntu 22.04 LTS, 24.04 LTS or 26.04 LTS
- Root access
- Ports 80 and 443 free
- 1 GB RAM (the installer adds swap below 2 GB so the frontend build survives)

## Installing

```bash
sudo bash install.sh --stack=lemp     # nginx + PHP          (default)
sudo bash install.sh --stack=lamp     # Apache + PHP
sudo bash install.sh --stack=mern     # nginx + Node
sudo bash install.sh --stack=ols      # OpenLiteSpeed + PHP
```

Run it without `--stack` from a terminal and it asks. Under `curl | bash` there is
no terminal to ask on, so pass the flag.

**On `--stack=ols`:** supported, with three differences worth knowing before you
pick it rather than after.

**The WAF (8G firewall) is not available.** OpenLiteSpeed needs those rules as
rewrite directives inside each site's `vhconf.conf`, and the templates do not
carry them yet. The panel refuses to enable it rather than storing a setting it
cannot enforce — but if a site needs that protection, it needs a different
stack. The **bot blocker does work** on OpenLiteSpeed.

**There is no per-site PHP isolation.** OpenLiteSpeed starts PHP itself and has
no per-site pools. Per-site `php.ini` settings do work, through `lsphp`.

**Only WordPress reads its own `.htaccess`.** OpenLiteSpeed needs a restart to
pick up a change to one, so it is enabled for WordPress alone — LiteSpeed Cache
talks to the cache module through that file and has no other way in. Every site
gets the standard front-controller rewrite from its vhost either way, so normal
routing and permalinks work; what does not apply is any *additional* rule an
application ships in its own `.htaccess`.

Everything else is the same: vhosts, certificates (OLS binds them to a listener
rather than a vhost, which the panel handles), per-site logs, staging, clone and
Sync. On this stack **everything runs on LSPHP** -- the panel and its hosted
sites alike, one PHP build per box. The other stacks run the panel and their
sites on PHP-FPM.

One thing to keep in mind whichever stack you choose: the web server the
installer picks is the one serving the panel itself, so if it misbehaves there is
no working panel left to fix it from.

| Option | |
|---|---|
| `--domain=panel.example.com` | Use your own domain. Point its A record here first. |
| `--email=you@example.com` | For Let's Encrypt expiry notices. |
| `--no-ssl` | Plain HTTP. Fine behind another proxy. |
| `--dry-run` | Print the steps without touching anything. |

**Without a domain** the panel is reachable at a [nip.io](https://nip.io) address
derived from the server's IP, so there is no DNS to configure. Certificates for
those names come from a rate limit shared by everyone using nip.io, so issuance
sometimes fails — the installer falls back to a self-signed certificate and keeps
going rather than failing. Pass `--domain=` for a certificate that works reliably.

The installer is **re-runnable**: if it fails partway, fix the cause and run it
again rather than rebuilding the server.

## What runs where

Every application type works on every stack. The web server is not what decides
it — **the runtime and the database are**, and the installer puts both PHP and
Node on every server because the panel itself needs them.

| Application | Runtime | Database |
|---|---|---|
| WordPress, Nextcloud, Joomla, Moodle, Mautic, Craft CMS, Akaunting, PrestaShop | PHP | MySQL or MariaDB |
| Statamic, phpMyAdmin, git deploy, plain PHP | PHP | — |
| Uptime Kuma, n8n, Node-RED | Node | — |
| NodeBB | Node | MongoDB **or** PostgreSQL |
| Static site | — | — |

NodeBB is the only one that can be greyed out on an otherwise working server: it
speaks MongoDB or PostgreSQL and neither flavour of MySQL, so on a MySQL-only box
the card says so rather than failing halfway through its setup. Everything else
needs either no database or the MySQL/MariaDB pair, which the panel can install
for you.

NodeBB is also the only application where you get a **choice** of engine, and the
create form offers one only when the server actually has both — MySQL and MariaDB
cannot coexist on one box, so listing both of those is not a choice. The choice is
permanent: nothing moves a forum from one engine to the other afterwards.

Databases are independent of the web server — all four engines install the same
way on all four stacks.

**One OpenLiteSpeed caveat.** On that stack *everything* runs LiteSpeed's own
`lsphp` build -- hosted sites and the panel alike -- and LiteSpeed publishes a
smaller extension set than `ppa:ondrej/php`. Most
of the difference is not real — `gd`, `mbstring`, `xml` and `zip` are compiled into
`lsphp` rather than shipped as separate packages — but there is genuinely **no
`lsphp*-mongodb`**, so a PHP application that needs the MongoDB *driver* would have
to build it with pecl. Nothing shipped here needs it; NodeBB is a Node application
and talks to MongoDB directly. The panel's extension screen lists what your server
can actually install rather than a fixed list, so it will not offer you something
apt would then refuse.

## Built with

Laravel 13 on PHP 8.4, SQLite by default, Redis for cache, Sanctum for auth,
[Pest](https://pestphp.com) for tests. The interface is Next.js 16 with React 19 and
Tailwind 4. **2,567 tests** cover the backend.

```
backend/     Laravel API
frontend/    Next.js interface
install.sh   installer
```

## Status

Honest about what has and has not been exercised:

| | |
|---|---|
| Backend features | passing; 3 tests skipped, naming an endpoint that was never built (`POST /applications/{id}/rollback`) |
| `install.sh` | confirmed end to end on real hardware 2026-09-01, on the OpenLiteSpeed stack |
| nginx / Apache / MERN stacks | exercised by the installer |
| OpenLiteSpeed | installs and served the panel on PHP-FPM (2026-09-01); ⚠️ the LSPHP move (2026-09-10) has **not run on hardware**, and **no site has been created on one yet** |
| MongoDB | installable from the panel |
| Licence | ⚠️ **not chosen yet** — see below |

**There is no licence file yet**, which means the usual copyright default applies
and you do not currently have permission to use, modify or redistribute this. That
is an oversight rather than an intention; a licence will be added.

## White-labelling

Nothing the panel writes to your server carries a product name — not the cron files,
not the fail2ban config, not the config it generates inside your applications. The
installer names every account, path, service and file from a single `PANEL_SLUG`
(default `panel`), and the interface reads its name and logo from `BRANDING_*`
environment variables.

## Contributing

Issues and pull requests welcome. Two things worth knowing before you send code:

- **Tests are Pest**, and every endpoint has a feature test covering both the
  allowed and the denied case.
- **User-facing strings are translation keys**, never literals — all eight locales
  are kept key-complete.
