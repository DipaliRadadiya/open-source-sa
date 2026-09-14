# Supported platforms

The panel runs on **Ubuntu 22.04, 24.04 and 26.04 LTS**. `install.sh` checks this
before it touches anything and refuses anything else:

```bash
# install.sh — the preflight, before anything is installed
if [[ "${ID:-}" != "ubuntu" ]] || [[ ! "${VERSION_ID:-}" =~ ^(22\.04|24\.04|26\.04)$ ]]; then
```

All three are equally supported. What differs is what *third parties* publish for
each release — and on one release that difference is visible to users, so it is
written down here rather than discovered.

> **This page states what vendors had published on the dates given.** It goes out
> of date by itself, without anything in this repository changing. Every row says
> how it was measured so it can be rechecked rather than believed — see
> [Rechecking](#rechecking).

---

## The releases

| | 22.04 | 24.04 | 26.04 |
|---|---|---|---|
| Codename | `jammy` | `noble` | `resolute` |
| PHP repository | `ppa:ondrej/php` | `ppa:ondrej/php` | **`packages.sury.org`** |
| PostgreSQL (Ubuntu archive) | 14 | 16 | 18 |
| MongoDB | ✅ | ✅ | ❌ **not published** |

**PHP comes from a different place on 26.04.** The `ondrej/php` PPA does not
publish for `resolute`; the same maintainer's `packages.sury.org` does, and the
installer switches to it automatically. The panel's pinned PHP (8.4) exists there
only through that repository — 26.04's own default is 8.5. Measured 2026-09-11:
sury publishes PHP **5.6 → 8.6 for `resolute`, identical to `noble`**. No gap.

---

## MongoDB is not available on Ubuntu 26.04

MongoDB publishes per Ubuntu codename from its own repository, and for `resolute`
it ships the tools and **not the database**. Measured 2026-09-11 against
`repo.mongodb.org`:

| | 24.04 `noble` | 26.04 `resolute` |
|---|---|---|
| packages published | **13** | **1** |
| `mongodb-org`, `mongodb-org-server` | ✅ | ❌ |
| `mongodb-mongosh` | ✅ | ❌ |
| `mongodb-database-tools` | ✅ | ✅ — the only one |

Only the **8.0** series has a `resolute` suite at all; `7.0` and `8.2` return 404.

⚠️ **`mongosh` is missing too**, and that is the panel's MongoDB *client*. So a
26.04 server cannot manage a **remote** MongoDB either — not only a local one.

**There is no workaround.** MongoDB is distributed only from `repo.mongodb.org`.
Unlike PHP — where a second repository publishes the same packages — there is no
alternative source to point at. Use MySQL, MariaDB or PostgreSQL, or wait for
MongoDB to build for 26.04.

### What the panel does about it

The setup page does not offer a button that cannot work. `GET /databases/engines`
marks the engine unavailable:

```json
{ "engine": "mongodb", "installable": false,
  "unavailable": { "code": "os_unsupported", "reason": "MongoDB has not published packages for Ubuntu 26.04 yet. …" } }
```

`POST /databases/engines/mongodb` refuses with **422** and the same sentence,
queueing nothing and writing nothing to the server.

**This list is config, and it is meant to be overridable.** It states what a
vendor has published *today*; the day MongoDB ships for `resolute` the entry is
wrong in the direction of refusing something that works. Clear it per server
without waiting for a panel release:

```bash
SERVER_MONGO_UNSUPPORTED=            # in the panel's .env, then: php artisan config:clear
```

If the list is ever wrong the other way — too optimistic — the install itself
still classifies the real failure as `os_unsupported` and says which release.

---

## OpenLiteSpeed: `lsphp8.1` is missing on 26.04

LiteSpeed publishes per codename too. Measured 2026-09-11 against
`rpms.litespeedtech.com`:

| | 24.04 `noble` | 26.04 `resolute` |
|---|---|---|
| `openlitespeed` | ✅ | ✅ |
| `lsphp` versions | 8.1 – 8.5 | **8.2 – 8.5** |

Only matters if you are moving a site pinned to **PHP 8.1** on the OLS stack onto
a 26.04 box. The panel's default is 8.4, so new servers are unaffected.

---

## Release-independent

- **Node** — installed with `fnm`, a downloaded binary. Not an apt package, so the
  Ubuntu release does not enter into it.
- **MySQL, MariaDB, PostgreSQL** — from Ubuntu's own archive. No third-party
  repository, so nothing to be behind on.
- **nginx, Apache, Redis, fail2ban, certbot** — Ubuntu archive.

---

## Rechecking

Every claim above is a repository index anyone can read. Re-run these rather than
trusting the dates:

```bash
# MongoDB — what is actually published for a codename
curl -s https://repo.mongodb.org/apt/ubuntu/dists/<codename>/mongodb-org/8.0/multiverse/binary-amd64/Packages.gz \
  | gunzip | grep '^Package:' | sort -u

# LiteSpeed — openlitespeed and the lsphp builds
curl -s http://rpms.litespeedtech.com/debian/dists/<codename>/main/binary-amd64/Packages.gz \
  | gunzip | grep '^Package:' | sort -u

# PHP from sury
curl -s https://packages.sury.org/php/dists/<codename>/main/binary-amd64/Packages.gz \
  | gunzip | grep -oE '^Package: php[0-9]\.[0-9]-fpm' | sort -u

# PostgreSQL in Ubuntu's own archive
curl -s http://archive.ubuntu.com/ubuntu/dists/<codename>/main/binary-amd64/Packages.gz \
  | gunzip | grep -oE '^Package: postgresql-[0-9]+$' | sort -u
```

A `404` on the `dists/<codename>/` path means the vendor publishes nothing for
that release at all — which is different from publishing an incomplete set, and
worth saying precisely when reporting it.
