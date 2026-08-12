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

**Databases.** MariaDB, MySQL or MongoDB. The panel installs the engine, creates
its own credentialed account, and manages databases and users per site.

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
sudo bash install.sh --stack=lemp     # nginx + PHP   (default)
sudo bash install.sh --stack=lamp     # Apache + PHP
sudo bash install.sh --stack=mern     # nginx + Node
```

Run it without `--stack` from a terminal and it asks. Under `curl | bash` there is
no terminal to ask on, so pass the flag.

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

## Built with

Laravel 13 on PHP 8.4, SQLite by default, Redis for cache, Sanctum for auth,
[Pest](https://pestphp.com) for tests. The interface is Next.js 16 with React 19 and
Tailwind 4. **872 tests** cover the backend.

```
backend/     Laravel API
frontend/    Next.js interface
install.sh   installer
```

## Status

Honest about what has and has not been exercised:

| | |
|---|---|
| Backend features | 872 passing tests |
| `install.sh` | ⚠️ **not yet run end to end on a real server** |
| OpenLiteSpeed | ⚠️ written, **never run on hardware** — the installer refuses to offer it |
| MongoDB | operable, but the panel cannot install the engine yet |
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
