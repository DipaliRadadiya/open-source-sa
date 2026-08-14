#!/usr/bin/env bash
#
# Self-hosted server control panel — installer.
#
#   curl -fsSL https://raw.githubusercontent.com/DipaliRadadiya/open-source-sa/main/install.sh | sudo bash
#
# Installs only what the panel needs to run *itself*. Everything the panel
# manages for you — database engines, extra PHP/Node versions, a different web
# server, fail2ban — is installed afterwards from the panel's own setup page,
# where it can show progress and be retried. A twenty-minute apt run with no UI
# and no retry is the thing this split exists to avoid.
#
# Design rules, in case you are editing this:
#
#   * Every step is idempotent. Re-running the script must converge, not
#     compound. A user whose install failed at minute nine will run it again.
#   * Nothing is changed before the preflight passes. An unsupported OS or a
#     busy port 80 is a refusal, not a half-installed server.
#   * The install never fails because TLS failed. A panel reachable over a
#     self-signed certificate is recoverable; a panel that does not exist is not.
#   * Matches what the panel expects, not what is conventional. Node goes in via
#     fnm because that is what the panel's Node feature manages; PHP needs the
#     ondrej repository or the panel can only ever offer the one version Ubuntu
#     ships.
#
# -E (errtrace) is load-bearing: the ERR trap below reports the ~200 commands
# that do not go through run(). Without errtrace that trap is NOT inherited by
# shell functions/subshells — and since every step runs inside a function, a
# failure there would abort silently (set -e stops, trap never fires). Keep -E.
set -Eeuo pipefail

# Runtime trees must be readable by nginx and executable by the panel account.
# Secret files (installer log, .env rewrite, auth files) set restrictive modes
# explicitly at their write sites; a global 077 umask would make the cloned app
# and fnm runtime inaccessible to the users that must serve and run them.
umask 022

# ─── Defaults ────────────────────────────────────────────────────────────────

REPO_URL="${REPO_URL:-https://github.com/DipaliRadadiya/open-source-sa.git}"
REPO_BRANCH="${REPO_BRANCH:-main}"
# Every artifact this script creates — the account, the paths, the systemd units,
# the sudoers and cron files — is named from this one slug, and none of them
# carries a product name.
#
# Deliberate: the panel is white-labelled, so a reseller's customer must not find
# an upstream brand stamped across their own server. Overridable, so rebranding
# is one value rather than a grep.
PANEL_SLUG="${PANEL_SLUG:-panel}"

APP_DIR="${APP_DIR:-/var/www/${PANEL_SLUG}}"
APP_USER="${APP_USER:-${PANEL_SLUG}}"
# Self-update writes its runner script and progress here. Deliberately outside
# APP_DIR: both would be destroyed by the checkout whose progress they record.
UPDATE_STATE_DIR="${UPDATE_STATE_DIR:-/var/lib/${PANEL_SLUG}-update}"
PHP_VERSION="${PHP_VERSION:-8.4}"
NODE_VERSION="${NODE_VERSION:-24}"
FRONTEND_PORT="${FRONTEND_PORT:-3100}"
FNM_DIR="/opt/fnm"
FNM_BIN="/usr/local/bin/fnm"
LOG_FILE="/var/log/${PANEL_SLUG}-install.log"

# How long to wait for another package manager before giving up. A fresh cloud
# server runs unattended-upgrades on first boot, so an installer that refuses to
# wait fails on exactly the machines it is meant for.
APT_LOCK_WAIT=900

OS_VERSION_ID=""   # 22.04 | 24.04 | 26.04 — set by preflight
OS_CODENAME=""     # jammy | noble | resolute — set by preflight

DOMAIN=""          # --domain=panel.example.com  (skips nip.io entirely)
ADMIN_EMAIL=""     # --email= for Let's Encrypt expiry notices
WANT_SSL=1         # --no-ssl
SCHEME="https"     # settled by configure_tls before any URL is written
DRY_RUN=0          # --dry-run

# Which stack to build. Asked rather than assumed, because it decides the web
# server — and the web server serves the panel itself, so it cannot be changed
# from inside the panel later without the panel going down with it.
#
# `ols` is deliberately absent. The panel's OpenLiteSpeed support has never run
# on real hardware, and offering it here would make it the first thing a new user
# can pick *and* the thing serving the panel they would use to recover. It
# becomes a fourth option once it has been proven on a box.
STACK=""           # --stack=lemp|lamp|mern  (prompted, or lemp)
WEB_SERVER=""      # derived from STACK

# ─── Output ──────────────────────────────────────────────────────────────────

if [[ -t 1 ]]; then
    BOLD=$'\033[1m'; DIM=$'\033[2m'; RED=$'\033[31m'
    GREEN=$'\033[32m'; YELLOW=$'\033[33m'; RESET=$'\033[0m'
else
    BOLD=""; DIM=""; RED=""; GREEN=""; YELLOW=""; RESET=""
fi

STEP_NO=0
# What we are doing right now, so a failure can say which part broke rather
# than only which line number did.
CURRENT_STEP="starting up"
say()  { printf '%s\n' "$*"; }
step() { STEP_NO=$((STEP_NO + 1)); CURRENT_STEP="$*"; printf '\n%s[%02d]%s %s%s%s\n' "$DIM" "$STEP_NO" "$RESET" "$BOLD" "$*" "$RESET"; }
ok()   { printf '     %s✓%s %s\n' "$GREEN" "$RESET" "$*"; }
skip() { printf '     %s·%s %s %s(already done)%s\n' "$DIM" "$RESET" "$*" "$DIM" "$RESET"; }
warn() { printf '     %s!%s %s\n' "$YELLOW" "$RESET" "$*" >&2; }

# Tail of the install log, indented, or a note saying why there isn't one.
# Every failure path prints this: "apt failed" with no output is not a
# diagnosis, and the log is the only place the actual output went.
log_tail() {
    local lines="${1:-15}"

    if [[ -r "${LOG_FILE:-}" ]]; then
        tail -n "$lines" "$LOG_FILE" 2>/dev/null | sed 's/^/       /'
    else
        printf '       (no log yet — the install stopped before creating %s)\n' "${LOG_FILE:-the log file}"
    fi
}

# One place that renders a stop, so every failure looks the same and always
# says: what broke, where, what the log holds, and what to do next.
report_failure() {
    local headline="$1" detail="${2:-}"

    printf '\n%s%sInstall stopped%s during: %s%s%s\n\n' "$BOLD" "$RED" "$RESET" "$BOLD" "$CURRENT_STEP" "$RESET" >&2
    printf '  %s\n' "$headline" >&2
    [[ -n "$detail" ]] && printf '  %s%s%s\n' "$DIM" "$detail" "$RESET" >&2

    # No log means we stopped in preflight, before anything was touched. Do
    # not point the user at a file that does not exist, and do not pad the
    # message with recovery steps for a machine that was never changed.
    if [[ ! -r "${LOG_FILE:-}" ]]; then
        printf '\n  %sNothing on this server was changed.%s\n\n' "$DIM" "$RESET" >&2

        return
    fi

    printf '\n  %sLast lines of the log:%s\n' "$BOLD" "$RESET" >&2
    log_tail 15 >&2

    printf '\n  %sWhat to do:%s\n' "$BOLD" "$RESET" >&2
    printf '    1. Read the full log:  sudo cat %s\n' "$LOG_FILE" >&2
    printf '    2. Fix what it reports, then run this installer again — it is\n' >&2
    printf '       safe to re-run and skips work that is already done.\n' >&2
    printf '    3. Still stuck? Open an issue with the log and the line above.\n\n' >&2
}

die() { report_failure "$*"; exit 1; }

# set -e kills the script on ANY failing command, including the ~200 that do
# not go through run(). Without this trap that death is completely silent:
# no message, no log pointer, just a non-zero exit — which reads to the user
# as "it did nothing". This turns every one of those into a real report.
on_error() {
    local code="$1" line="$2" command="$3"

    trap - ERR
    report_failure \
        "A command failed (exit ${code}) and the install cannot safely continue." \
        "line ${line}: ${command}"
    exit "$code"
}

trap 'on_error "$?" "$LINENO" "$BASH_COMMAND"' ERR

# Runs a command, sending its noise to the log. The log path is printed on
# failure, because "apt failed" with no output is not a diagnosis.
run() {
    if (( DRY_RUN )); then
        printf '     %s$ %s%s\n' "$DIM" "$*" "$RESET"
        return 0
    fi
    # The ERR trap must not also fire for this: `if !` already handles the
    # failure, and a doubled report is worse than none.
    # A command in an `if` condition does not fire the ERR trap, so this
    # reports once rather than twice.
    if ! "$@" >>"$LOG_FILE" 2>&1; then
        report_failure "This command failed. Its output is in the log." "\$ $*"
        exit 1
    fi
}

# Keep apt/network noise in the log, but make every long operation visible in
# the terminal so a download is never mistaken for a stalled installer.
run_progress() {
    local label="$1"
    shift
    local started=$SECONDS

    say "     → ${label}..."
    run "$@"
    ok "${label} ($((SECONDS - started))s)"
}

# ─── Arguments ───────────────────────────────────────────────────────────────

for arg in "$@"; do
    case "$arg" in
        --domain=*) DOMAIN="${arg#*=}" ;;
        --stack=*)  STACK="${arg#*=}" ;;
        --email=*)  ADMIN_EMAIL="${arg#*=}" ;;
        --branch=*) REPO_BRANCH="${arg#*=}" ;;
        --repo=*)   REPO_URL="${arg#*=}" ;;
        --no-ssl)   WANT_SSL=0 ;;
        --dry-run)  DRY_RUN=1 ;;
        -h|--help)
            cat <<'USAGE'
Control panel installer

  sudo bash install.sh [options]

  --stack=lemp                 Which stack to build:
                                 lemp  nginx + PHP          (default)
                                 lamp  Apache + PHP
                                 mern  nginx + Node
                               Asked interactively when not given and a terminal
                               is available. Required for `curl | bash`, which
                               has no terminal to ask on.
  --domain=panel.example.com   Use your own domain instead of a nip.io name.
                               Point its A record at this server first.
  --email=you@example.com      Address for Let's Encrypt expiry warnings.
  --branch=main                Branch to install from.
  --no-ssl                     Serve plain HTTP. Fine behind another proxy.
  --dry-run                    Print the steps without touching anything.
USAGE
            exit 0 ;;
        *) die "unknown option: $arg  (try --help)" ;;
    esac
done

# ─── Preflight ───────────────────────────────────────────────────────────────
#
# Everything that could make this install fail badly is checked here, before a
# single package is installed. The alternative — discovering on step 11 that
# port 80 is taken — leaves the user with a server that is neither what it was
# nor what they wanted.

preflight() {
    step "Checking this server"

    [[ $EUID -eq 0 ]] || die "run this as root:  sudo bash install.sh"

    [[ -r /etc/os-release ]] || die "cannot read /etc/os-release — is this Linux?"
    # shellcheck disable=SC1091
    . /etc/os-release

    if [[ "${ID:-}" != "ubuntu" ]] || [[ ! "${VERSION_ID:-}" =~ ^(22\.04|24\.04|26\.04)$ ]]; then
        die "unsupported OS: ${PRETTY_NAME:-unknown}
     Supported: Ubuntu 22.04 LTS, Ubuntu 24.04 LTS, Ubuntu 26.04 LTS.
     Nothing has been changed on this server."
    fi

    # Kept for the steps that have to branch on the release — the PHP repository
    # most of all, since the mechanism differs between 24.04 and 26.04. Captured
    # here rather than re-sourcing /etc/os-release later, so there is one place
    # that decides what this machine is.
    OS_VERSION_ID="${VERSION_ID}"
    OS_CODENAME="${VERSION_CODENAME:-}"

    [[ -n "$OS_CODENAME" ]] || die "cannot determine the Ubuntu codename from /etc/os-release.
     Nothing has been changed on this server."

    ok "${PRETTY_NAME}"

    case "$(uname -m)" in
        x86_64|aarch64) ok "architecture $(uname -m)" ;;
        *) die "unsupported architecture: $(uname -m) (need x86_64 or aarch64)" ;;
    esac

    # Ports, before any web server is installed. `ss` ships with iproute2 on
    # both supported releases. A web server that is already ours is not a
    # conflict — this is the re-run case.
    local port
    for port in 80 443; do
        if ss -ltnH "sport = :${port}" 2>/dev/null | grep -q .; then
            if [[ -f /etc/nginx/sites-enabled/${PANEL_SLUG}.conf ]] \
               || [[ -f /etc/apache2/sites-enabled/${PANEL_SLUG}.conf ]]; then
                skip "port ${port} is in use by our own web server"
            else
                die "port ${port} is already in use by something else.
     The panel needs 80 and 443. Stop that service and run this again:
       ss -ltnp 'sport = :${port}'"
            fi
        fi
    done
    ok "ports 80 and 443 are free"

    local free_mb
    free_mb=$(df -Pm /var | awk 'NR==2 {print $4}')
    (( free_mb >= 3000 )) || die "need at least 3 GB free on /var, found ${free_mb} MB"
    ok "${free_mb} MB free on /var"

    command -v systemctl >/dev/null 2>&1 || die "systemd is required"

    mkdir -p "$(dirname "$LOG_FILE")"
    : >"$LOG_FILE"
    chmod 600 "$LOG_FILE"
    ok "logging to $LOG_FILE"
}

# ─── Stack ───────────────────────────────────────────────────────────────────

resolve_stack() {
    step "Choosing the stack"

    # Asked from /dev/tty, never stdin. Under `curl … | bash` the *script itself*
    # is on stdin, so a plain `read` would swallow the rest of the script rather
    # than wait for an answer — the install would then behave bizarrely rather
    # than hang, which is worse. No terminal means no question: the flag or the
    # default decides.
    if [[ -z "$STACK" ]]; then
        if [[ -r /dev/tty && -w /dev/tty ]]; then
            printf '\n     Which stack should this server run?\n\n'
            printf '       1) lemp   nginx + PHP     %s(default)%s\n' "$DIM" "$RESET"
            printf '       2) lamp   Apache + PHP\n'
            printf '       3) mern   nginx + Node\n\n'
            printf '     Choice [1]: '

            local answer=""
            read -r answer < /dev/tty || answer=""
            printf '\n'

            case "${answer:-1}" in
                1|lemp|'') STACK="lemp" ;;
                2|lamp)    STACK="lamp" ;;
                3|mern)    STACK="mern" ;;
                *) die "not one of the options: ${answer}" ;;
            esac
        else
            STACK="lemp"
            say "     no terminal to ask on — defaulting to lemp (use --stack= to choose)"
        fi
    fi

    case "$STACK" in
        lemp|mern) WEB_SERVER="nginx" ;;
        lamp)      WEB_SERVER="apache" ;;
        ols)
            # Refused rather than attempted. The panel's OpenLiteSpeed support has
            # never run on hardware, and here it would be serving the panel
            # itself — so a wrong path leaves a server with no working panel and
            # no UI to recover from.
            die "the openlitespeed stack is not available from this installer yet.
     Its support in the panel has never been verified on a real server, and
     here it would be serving the panel itself. Use lemp, lamp or mern."
            ;;
        *) die "unknown stack: ${STACK}  (expected lemp, lamp or mern)" ;;
    esac

    # PHP and Node are installed regardless of the stack: the panel's API is PHP
    # and its own interface is a Next.js build. The stack decides what *sites*
    # get, and which web server owns port 80.
    ok "${STACK} — ${WEB_SERVER}, PHP ${PHP_VERSION}, Node ${NODE_VERSION}"
}

# ─── Hostnames ───────────────────────────────────────────────────────────────
#
# nip.io resolves <anything>.<ip-with-dashes>.nip.io to that IP, with no DNS to
# configure.
#
# The IP is written with dashes and the label is separated by a *dot*, which is
# load-bearing: it makes `panel` and `api` children of one shared parent, so a
# single session cookie can cover both. Separating with a dash instead would
# make them unrelated siblings under nip.io and logging in would not work.

resolve_hostnames() {
    step "Working out the address"

    if [[ -n "$DOMAIN" ]]; then
        PANEL_HOST="$DOMAIN"
        API_HOST="$DOMAIN"
        COOKIE_DOMAIN="$DOMAIN"
        SINGLE_HOST=1
        ok "using your domain: $DOMAIN"
        warn "make sure its A record points at this server, or TLS will fail"
        return
    fi

    # The address the outside world sees, which on a NAT'd box is not the one on
    # the interface. Falls back to the local route if the lookup services are
    # unreachable — a wrong-but-present name beats an empty one.
    local ip=""
    for url in https://api.ipify.org https://ifconfig.me/ip https://icanhazip.com; do
        ip=$(curl -fsS --max-time 5 "$url" 2>/dev/null | tr -d '[:space:]') || ip=""
        [[ "$ip" =~ ^[0-9]{1,3}(\.[0-9]{1,3}){3}$ ]] && break
        ip=""
    done

    if [[ -z "$ip" ]]; then
        ip=$(ip -4 route get 1.1.1.1 2>/dev/null | awk '!f{for(i=1;i<=NF;i++) if($i=="src"){print $(i+1); f=1}}')
        [[ -n "$ip" ]] || die "could not determine this server's IP address.
     Re-run with --domain=your.hostname to skip the lookup."
        warn "could not reach an IP lookup service; using the local address $ip"
    fi

    case "$ip" in
        10.*|127.*|192.168.*|172.1[6-9].*|172.2[0-9].*|172.3[01].*)
            warn "$ip is a private address — the nip.io name will only work from inside this network" ;;
    esac

    # A dot between the label and the dashed IP, not a dash — and this is the
    # difference between logging in and not.
    #
    # `panel-1-2-3-4.nip.io` and `api-1-2-3-4.nip.io` are both single labels
    # under nip.io: siblings, with no shared parent. A session cookie cannot be
    # scoped to cover both. `panel.1-2-3-4.nip.io` and `api.1-2-3-4.nip.io` are
    # children of `1-2-3-4.nip.io`, which *can* hold the cookie — which is how
    # the existing development server is set up, and why it works.
    local dashed="${ip//./-}"
    COOKIE_DOMAIN="${dashed}.nip.io"
    PANEL_HOST="panel.${COOKIE_DOMAIN}"
    API_HOST="api.${COOKIE_DOMAIN}"
    SINGLE_HOST=0
    ok "panel: https://${PANEL_HOST}"
    ok "api:   https://${API_HOST}"
}

# ─── Swap ────────────────────────────────────────────────────────────────────

configure_swap() {
    step "Checking memory"

    local ram_mb
    ram_mb=$(awk '/MemTotal/ {printf "%d", $2/1024}' /proc/meminfo)

    if (( $(swapon --show=NAME --noheadings 2>/dev/null | wc -l) > 0 )); then
        skip "swap is already configured (${ram_mb} MB RAM)"
        return
    fi

    if (( ram_mb >= 2048 )); then
        ok "${ram_mb} MB RAM, no swap needed"
        return
    fi

    # Below 2 GB the frontend build is the thing that dies: `next build` peaks
    # well above what a 1 GB box has, and the OOM killer reports it as a bare
    # "Killed" with no mention of memory. Swap turns a mysterious failure into
    # a slow success.
    say "     ${ram_mb} MB RAM — adding 2 GB of swap so the frontend build can finish"
    run fallocate -l 2G /swapfile
    run chmod 600 /swapfile
    run mkswap /swapfile
    run swapon /swapfile
    grep -q '^/swapfile ' /etc/fstab || printf '/swapfile none swap sw 0 0\n' >>/etc/fstab
    ok "2 GB swap active"
}

# ─── Packages ────────────────────────────────────────────────────────────────

# The panel offers PHP versions by asking apt what it can install. Without a
# multi-version repository that answer is "only the one Ubuntu ships", and the
# whole multi-version PHP feature is inert. It is a prerequisite of the panel
# working, not a preference — which is why this refuses rather than continues.
#
# The mechanism differs by release, and this is the part that would have broken
# a 26.04 install silently:
#
#   22.04 / 24.04  ppa:ondrej/php on Launchpad.
#   26.04          the PPA does not publish for resolute. The same maintainer's
#                  packages.sury.org does, so 26.04 uses that instead. Ubuntu
#                  26.04 also ships PHP 8.5 as its own default, so the pinned
#                  8.4 exists there ONLY through this repository.
#
# The releases already in the wild keep the PPA they were installed with: an
# existing install has ondrej sources on disk, and switching mechanism under it
# would leave two sources for the same packages.
add_php_repository() {
    if [[ "$OS_VERSION_ID" == "26.04" ]]; then
        local keyring=/etc/apt/keyrings/sury-php.gpg
        local list=/etc/apt/sources.list.d/sury-php.list

        if [[ -f "$keyring" && -f "$list" ]]; then
            skip "sury.org PHP repository"
            return
        fi

        run install -d -m 0755 /etc/apt/keyrings
        run_progress "Downloading the PHP repository signing key" curl -fsSL -o "$keyring" https://packages.sury.org/php/apt.gpg
        run chmod 0644 "$keyring"

        # Written with signed-by rather than apt-key: apt-key is deprecated and
        # a key trusted globally would sign for every repository on the box,
        # not just this one.
        if (( DRY_RUN )); then
            printf '     %s$ write %s%s\n' "$DIM" "$list" "$RESET"
        else
            printf 'deb [signed-by=%s] https://packages.sury.org/php/ %s main\n' \
                "$keyring" "$OS_CODENAME" >"$list"
        fi

        run_progress "Refreshing package lists from the PHP repository" apt-get update -qq
        ok "sury.org PHP repository added (${OS_CODENAME})"
        return
    fi

    # A glob inside [[ -f ]] is not expanded, so it is matched with compgen —
    # the naive version silently always thought the repository was missing.
    if ! compgen -G "/etc/apt/sources.list.d/ondrej*php*" >/dev/null; then
        run_progress "Adding the ondrej/php repository" add-apt-repository -y ppa:ondrej/php
        run_progress "Refreshing package lists from the PHP repository" apt-get update -qq
        ok "ondrej/php repository added"
    else
        skip "ondrej/php repository"
    fi
}

# Proven before anything is installed, not discovered halfway through.
#
# Every failure this catches is the same shape: a repository that was added
# successfully but carries nothing for this release. `add-apt-repository` and a
# written sources file both succeed in that case, and the install would then
# die on `apt-get install php8.4-fpm` — after the web server and Redis are
# already on the box, which is the half-installed state preflight exists to
# avoid.
assert_php_available() {
    if (( DRY_RUN )); then
        printf '     %s$ apt-cache policy php%s-fpm%s\n' "$DIM" "$PHP_VERSION" "$RESET"
        return
    fi

    local candidate
    candidate=$(apt-cache policy "php${PHP_VERSION}-fpm" 2>/dev/null | awk '/Candidate:/ {print $2}')

    if [[ -z "$candidate" || "$candidate" == "(none)" ]]; then
        die "PHP ${PHP_VERSION} is not available from apt on ${PRETTY_NAME:-this release}.
     The PHP repository was added but carries no php${PHP_VERSION} for ${OS_CODENAME}.
     Check it resolves:
       apt-cache policy php${PHP_VERSION}-fpm
     Nothing further has been installed."
    fi

    ok "PHP ${PHP_VERSION} available (${candidate})"
}

# ─── apt locks ───────────────────────────────────────────────────────────────
#
# Ubuntu allows one package operation at a time, arbitrated by locks on
# /var/lib/dpkg/lock-frontend, /var/lib/apt/lists/lock and
# /var/cache/apt/archives/lock. A server that booted minutes ago is usually
# still running unattended-upgrades, and apt's default behaviour on a held lock
# is to print "Could not get lock" and exit — which killed the install.
#
# Two layers, because either alone is wrong:
#
#   configure_apt_lock_wait  makes apt itself block for the lock instead of
#                            erroring. This is the part that is actually
#                            correct: the lock is taken atomically, so nothing
#                            can slip in between a check and our command. It is
#                            a config drop-in rather than a flag on each call
#                            because apt is also invoked by things we do not
#                            control — add-apt-repository, certbot, and later
#                            the panel itself.
#
#   wait_for_apt_lock        explains the delay. Without it apt blocks silently
#                            and a ten-minute wait is indistinguishable from a
#                            hung installer.
#
# Never: kill the holder, delete a lock file, or run `dpkg --configure -a`
# blindly. Each of those can leave the package database permanently broken —
# far worse than the wait they save.
configure_apt_lock_wait() {
    local conf="/etc/apt/apt.conf.d/99${PANEL_SLUG}-lock-wait"

    if (( DRY_RUN )); then
        printf '     %s$ write %s%s\n' "$DIM" "$conf" "$RESET"
        return 0
    fi

    printf 'DPkg::Lock::Timeout "%s";\n' "$APT_LOCK_WAIT" >"$conf"
    chmod 0644 "$conf"
}

# Asks apt whether the lock is free, by having apt try to take it and refuse to
# wait. This is deliberately not a process check: unattended-upgrades leaves a
# long-lived helper process running on an idle server, so "is something named
# apt alive" reports busy on a machine where the lock is free — a false wait
# followed by a false failure, which is worse than the problem being fixed.
#
# `apt-get check` is read-only and takes the same locks a real operation does.
# It can also fail for reasons that are not locks — broken dependencies, most
# often — so only the two messages apt emits for a *contended* lock count as
# held. Matching "unable to acquire the dpkg frontend lock" more loosely would
# be wrong: apt says that to a non-root caller too, where the lock is free and
# the problem is permission. LC_ALL=C keeps the message in English on a
# localised server.
apt_lock_is_held() {
    local output lock

    # apt-get check only takes the dpkg/frontend lock. `apt-get update` needs the
    # lists lock, and a first-boot unattended-upgrades holds THAT one while
    # leaving the frontend lock free — so a check-only probe reports "free" and
    # the update then dies on "Could not get lock /var/lib/apt/lists/lock". fuser
    # reports a holder of any of the real lock files, whatever the lock type, so
    # we wait for the one that actually matters.
    if command -v fuser >/dev/null 2>&1; then
        for lock in /var/lib/apt/lists/lock /var/lib/dpkg/lock-frontend \
                    /var/lib/dpkg/lock /var/cache/apt/archives/lock; do
            fuser "$lock" >/dev/null 2>&1 && return 0
        done
    fi

    output=$(LC_ALL=C apt-get -o DPkg::Lock::Timeout=0 check 2>&1) && return 1

    grep -qiE 'it is held by process|temporarily unavailable' <<<"$output"
}

# Best-effort, for the message only — it is never what decides to wait.
apt_lock_holder() {
    local pid name

    for pid in $(pgrep -x 'apt|apt-get|dpkg|unattended-upgr' 2>/dev/null); do
        [[ "$pid" == "$$" ]] && continue
        name=$(tr '\0' ' ' <"/proc/${pid}/cmdline" 2>/dev/null | cut -c1-60)
        printf '%s, PID %s' "${name:-package manager}" "$pid"
        return 0
    done

    printf 'another package manager'
}

wait_for_apt_lock() {
    local holder waited=0

    (( DRY_RUN )) && return 0
    apt_lock_is_held || return 0
    holder=$(apt_lock_holder)

    say "     → Another package manager is running (${holder})."
    say "       Waiting up to $((APT_LOCK_WAIT / 60)) minutes for it to finish..."

    while apt_lock_is_held; do
        if (( waited >= APT_LOCK_WAIT )); then
            printf '\n' >&2
            die "another package manager is still running after $((waited / 60)) minutes:
       ${holder}
     It was left alone deliberately — killing it can corrupt this server's
     package database. Wait for it to finish, then run this installer again."
        fi

        sleep 5
        waited=$((waited + 5))
        printf '\r       waiting... %ss' "$waited"
    done

    (( waited > 0 )) && printf '\r%s\r' "                                        "
    ok "the other package manager finished (${waited}s)"
}

install_packages() {
    step "Installing packages"

    export DEBIAN_FRONTEND=noninteractive

    configure_apt_lock_wait
    wait_for_apt_lock

    run_progress "Refreshing system package lists" apt-get update -qq
    # update-notifier-common provides `apt-check`, which is how the panel counts
    # pending and security updates. Without it the Settings page cannot tell the
    # difference between "nothing waiting" and "could not look", so it reports
    # neither. Cheap, and it is the same source Ubuntu's own MOTD uses.
    run_progress "Installing installer prerequisites" apt-get install -y software-properties-common curl git unzip zip rsync ca-certificates gnupg update-notifier-common

    add_php_repository
    assert_php_available

    # Matches the extensions the panel actually loads. Kept explicit rather than
    # pulling php${V} — the metapackage drags in apache2 as a dependency, which
    # would fight nginx for port 80.
    local php_pkgs=(
        "php${PHP_VERSION}-fpm" "php${PHP_VERSION}-cli" "php${PHP_VERSION}-common"
        "php${PHP_VERSION}-bcmath" "php${PHP_VERSION}-curl" "php${PHP_VERSION}-intl"
        "php${PHP_VERSION}-mbstring" "php${PHP_VERSION}-xml" "php${PHP_VERSION}-zip"
        "php${PHP_VERSION}-gd" "php${PHP_VERSION}-sqlite3" "php${PHP_VERSION}-mysql"
        "php${PHP_VERSION}-redis" "php${PHP_VERSION}-igbinary" "php${PHP_VERSION}-opcache"
    )
    # Only the chosen web server. Installing both would have them fight over
    # port 80, and apt starts them on install.
    local web_pkgs=()
    case "$WEB_SERVER" in
        nginx)  web_pkgs=(nginx) ;;
        apache) web_pkgs=(apache2) ;;
    esac

    run_progress "Installing ${WEB_SERVER}, Redis, SQLite, and PHP ${PHP_VERSION}" apt-get install -y "${web_pkgs[@]}" redis-server sqlite3 "${php_pkgs[@]}"
    ok "${WEB_SERVER}, redis, sqlite, PHP ${PHP_VERSION}"

    if ! command -v composer >/dev/null 2>&1; then
        run curl -fsSL -o /tmp/composer-setup.php https://getcomposer.org/installer
        run php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer
        rm -f /tmp/composer-setup.php
        ok "composer"
    else
        skip "composer"
    fi
}

# ─── Node, through fnm ───────────────────────────────────────────────────────
#
# fnm rather than apt or nodesource because the panel's Node feature manages
# versions *with fnm* and treats anything else as an untouchable system binary.
# Installing Node from apt here would leave that feature unable to add or switch
# versions on a server it just installed.

install_node() {
    step "Installing Node ${NODE_VERSION} (fnm)"

    if [[ ! -x "$FNM_BIN" ]]; then
        mkdir -p "$FNM_DIR"
        run curl -fsSL -o /tmp/fnm-install.sh https://fnm.vercel.app/install
        # --skip-shell: this is a service account with no interactive shell to
        # rewrite, and the panel calls fnm by absolute path anyway.
        run bash /tmp/fnm-install.sh --install-dir "$FNM_DIR" --skip-shell
        rm -f /tmp/fnm-install.sh
        ln -sf "${FNM_DIR}/fnm" "$FNM_BIN"
        ok "fnm installed at $FNM_BIN"
    else
        skip "fnm"
    fi

    export FNM_DIR
    if ! "$FNM_BIN" list 2>/dev/null | grep -q "v${NODE_VERSION}\."; then
        run "$FNM_BIN" install "$NODE_VERSION"
        run "$FNM_BIN" alias "$NODE_VERSION" default
        ok "Node ${NODE_VERSION}"
    else
        skip "Node ${NODE_VERSION}"
    fi

    # Resolved once and written into the frontend's unit file. The panel's own
    # frontend should keep running the version it was built against even if
    # someone later changes the server's default.
    # fnm lays versions out as node-versions/vX.Y.Z/installation/bin/node —
    # four levels below node-versions, which is why maxdepth is 5 and not 3.
    NODE_BIN=$(find "${FNM_DIR}/node-versions" -maxdepth 5 -type f -name node -path "*v${NODE_VERSION}.*" -print -quit 2>/dev/null)
    [[ -x "${NODE_BIN:-}" ]] || die "installed Node ${NODE_VERSION} but cannot find its binary under ${FNM_DIR}"

    # The installer runs as root under umask 077, but the frontend build and
    # the panel's runtime manager execute Node as the panel account. Without
    # this ownership hand-off, `env npm` finds the binary but cannot traverse
    # /opt/fnm, producing a misleading "Permission denied" error.
    run chown -R "${APP_USER}:${APP_USER}" "$FNM_DIR"
    ok "node binary: $NODE_BIN"
}

# ─── The panel's own user ────────────────────────────────────────────────────

create_user() {
    step "Creating the ${APP_USER} account"

    if id -u "$APP_USER" >/dev/null 2>&1; then
        skip "user ${APP_USER}"
    else
        run useradd --system --create-home --home-dir "/home/${APP_USER}" --shell /usr/sbin/nologin "$APP_USER"
        ok "user ${APP_USER}"
    fi

    # `adm` is Debian's read-the-logs group, and several of the logs the panel
    # reports on are root:adm 0750 — /var/log/unattended-upgrades among them.
    # Membership is the intended way in, and a far narrower grant than adding
    # another root command to the sudoers list: it is read access to logs, not
    # the ability to run anything.
    if id -nG "$APP_USER" | tr ' ' '\n' | grep -qx adm; then
        skip "${APP_USER} is in the adm group"
    else
        run usermod -aG adm "$APP_USER"
        ok "${APP_USER} added to the adm group (log read access)"
    fi
}

# ─── Source ──────────────────────────────────────────────────────────────────

fetch_source() {
    step "Fetching the panel"

    # git >=2.35.2 refuses to run any command against a repo whose top-level
    # directory is owned by a different user than the one invoking it (the
    # "dubious ownership" check, added after CVE-2022-24765). Every git call
    # in this function runs as root, but $APP_DIR is handed to $APP_USER via
    # chown below and stays that way — so without this exception, the very
    # next git config call, and every fetch/reset on future re-runs, fails
    # with a bare "fatal: not in a git directory" that looks like a missing
    # or broken clone rather than what it actually is.
    if ! git config --global --get-all safe.directory 2>/dev/null | grep -qxF "$APP_DIR"; then
        run git config --global --add safe.directory "$APP_DIR"
    fi

    if [[ -d "${APP_DIR}/.git" ]]; then
        run git -C "$APP_DIR" fetch --depth 1 origin "$REPO_BRANCH"
        run git -C "$APP_DIR" reset --hard "origin/${REPO_BRANCH}"
        ok "updated to the latest ${REPO_BRANCH}"
    else
        mkdir -p "$(dirname "$APP_DIR")"
        if [[ -e "$APP_DIR" ]]; then
            # A previous attempt can leave APP_DIR behind without a valid
            # .git — an interrupted clone, or something else pre-creating
            # the path. git then either refuses to clone into it or, worse,
            # leaves it in a state where later plumbing (git config, fetch)
            # fails with a cryptic "not in a git directory". APP_DIR is
            # entirely owned by this installer (see UPDATE_STATE_DIR above),
            # so clearing it before a fresh clone is safe.
            warn "${APP_DIR} exists but is not a git checkout (leftover from an interrupted attempt) — removing before cloning"
            run rm -rf "$APP_DIR"
        fi
        run git clone --depth 1 --branch "$REPO_BRANCH" "$REPO_URL" "$APP_DIR"
        ok "cloned into ${APP_DIR}"
    fi

    # Before composer and npm run, not after. Both run as ${APP_USER} and both
    # write into a tree git just created as root — without this they fail on
    # the first write to vendor/ or node_modules/.
    run chown -R "${APP_USER}:${APP_USER}" "$APP_DIR"
    ok "owned by ${APP_USER}"

    # setup_backend() below chmod -R 775's storage/ and bootstrap/cache/,
    # which includes the tracked .gitignore placeholders that keep those
    # otherwise-empty directories in git. That flips their mode from the
    # repo's 644 to 775, and with core.fileMode on (git's default) that reads
    # as an uncommitted change forever after — which the panel-update
    # preflight's clean-working-tree check takes at face value and refuses to
    # update on, despite there being nothing to lose.
    run git -C "$APP_DIR" config core.fileMode false
    ok "git mode tracking disabled (storage permissions are not source control)"
}

# Sets a key in an .env file, preserving every other line — comments, ordering
# and blank lines included. Written to a temp file and moved, because a
# half-written .env is a panel that will not boot at all.
set_env() {
    local file="$1" key="$2" value="$3" tmp

    # Anything outside a plain charset gets quoted, or dotenv either misreads it
    # or fails to parse the file at all — a `#` starts a comment mid-value, and a
    # bare `$` is read as interpolation. Values we generate are alphanumeric, but
    # a password already present in redis.conf is whatever the operator chose.
    if [[ -n "$value" && ! "$value" =~ ^[A-Za-z0-9_.:/@-]+$ ]]; then
        # Backslashes first, or the escapes added below get escaped again. Then
        # quotes, then dollars — getting this order wrong silently produces a
        # value that parses as something else.
        value="${value//\\/\\\\}"
        value="${value//\"/\\\"}"
        value="${value//\$/\\\$}"
        value="\"${value}\""
    fi

    tmp=$(mktemp "${file}.XXXXXX")

    if grep -qE "^[[:space:]]*${key}=" "$file" 2>/dev/null; then
        # The value goes in via awk's ENVIRON, never through the regex
        # replacement, so characters like & and \1 in a generated password
        # cannot be interpreted.
        VALUE="$value" KEY="$key" awk '
            $0 ~ "^[[:space:]]*" ENVIRON["KEY"] "=" { print ENVIRON["KEY"] "=" ENVIRON["VALUE"]; next }
            { print }
        ' "$file" >"$tmp"
    else
        cat "$file" >"$tmp" 2>/dev/null || true
        printf '%s=%s\n' "$key" "$value" >>"$tmp"
    fi

    chmod 640 "$tmp"
    mv -f "$tmp" "$file"
}

# ─── Redis ───────────────────────────────────────────────────────────────────

configure_redis() {
    step "Securing Redis"

    REDIS_PASSWORD=""
    local conf=/etc/redis/redis.conf

    if [[ ! -f "$conf" ]]; then
        warn "redis.conf not found — the panel will fall back to database cache"
        return
    fi

    local existing
    existing=$(awk '/^requirepass / {print $2; exit}' "$conf" 2>/dev/null || true)

    if [[ -n "$existing" ]]; then
        REDIS_PASSWORD="$existing"
        skip "Redis already has a password"
    else
        # No trailing `head -c`: it closes the pipe early and SIGPIPEs tr, which
        # pipefail turns into a fatal exit 141. Over-read, then slice in bash.
        REDIS_PASSWORD=$(head -c 48 /dev/urandom | base64 | tr -d '/+=')
        REDIS_PASSWORD=${REDIS_PASSWORD:0:32}
        printf '\n# Added by the Control panel installer\nrequirepass %s\n' "$REDIS_PASSWORD" >>"$conf"
        ok "generated a Redis password"
    fi

    run systemctl enable --now redis-server
    run systemctl restart redis-server

    # Proven, not assumed: if Redis cannot be reached with this credential the
    # panel must not be configured to depend on it, or the first request 500s
    # with NOAUTH and the screen you would fix it from is behind that failure.
    if redis-cli -a "$REDIS_PASSWORD" --no-auth-warning ping 2>/dev/null | grep -q PONG; then
        ok "Redis reachable"
        CACHE_STORE="redis"
    else
        warn "Redis did not answer — using the database cache instead"
        REDIS_PASSWORD=""
        CACHE_STORE="database"
    fi
}

# ─── Backend ─────────────────────────────────────────────────────────────────

setup_backend() {
    step "Setting up the API"

    local dir="${APP_DIR}/backend"
    # SCHEME is decided by configure_tls, which runs before this — the URLs
    # written here have to match what the server actually answers on.
    local scheme="$SCHEME"

    run sudo -u "$APP_USER" -H composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader -d "$dir"
    ok "dependencies installed"

    [[ -f "${dir}/.env" ]] || cp "${dir}/.env.example" "${dir}/.env"

    local db="${dir}/database/database.sqlite"
    [[ -f "$db" ]] || : >"$db"

    set_env "${dir}/.env" APP_ENV production
    set_env "${dir}/.env" APP_DEBUG false
    set_env "${dir}/.env" APP_URL "${scheme}://${API_HOST}"
    set_env "${dir}/.env" FRONTEND_URL "${scheme}://${PANEL_HOST}"
    set_env "${dir}/.env" SANCTUM_STATEFUL_DOMAINS "$PANEL_HOST"
    set_env "${dir}/.env" DB_CONNECTION sqlite
    set_env "${dir}/.env" DB_DATABASE "$db"
    # All three follow whether Redis actually answered, not whether it was
    # installed — configure_redis sets CACHE_STORE to "database" when the probe
    # fails, and the other two have to agree with it or the panel is pointed at
    # a Redis it already proved it cannot reach.
    #
    # They matter more than they look on a SQLite panel: SQLite allows exactly
    # one writer, so a database queue means the worker polls the same file every
    # request writes to, and a database session driver adds a write per request.
    # That is how an idle single-user panel produces "database is locked".
    set_env "${dir}/.env" QUEUE_CONNECTION "$CACHE_STORE"
    set_env "${dir}/.env" SESSION_DRIVER "$CACHE_STORE"
    set_env "${dir}/.env" CACHE_STORE "$CACHE_STORE"

    # Leading dot so the cookie covers both hosts under the shared parent. With
    # a single host there is no parent to share and it is same-origin anyway.
    if (( SINGLE_HOST )); then
        set_env "${dir}/.env" SESSION_DOMAIN "$COOKIE_DOMAIN"
    else
        set_env "${dir}/.env" SESSION_DOMAIN ".${COOKIE_DOMAIN}"
    fi

    if [[ -n "$REDIS_PASSWORD" ]]; then
        set_env "${dir}/.env" REDIS_PASSWORD "$REDIS_PASSWORD"
        set_env "${dir}/.env" REDIS_HOST 127.0.0.1
        set_env "${dir}/.env" REDIS_PORT 6379
    fi

    # Self-update needs to know what this installer named things. The config
    # defaults assume PANEL_SLUG=panel and a default fnm alias; an install that
    # used neither would restart units that do not exist and build with the
    # wrong node. The installer is the only thing that knows the real answers,
    # so it writes them rather than leaving the admin to discover them after a
    # failed update.
    set_env "${dir}/.env" PANEL_UPDATE_STATE_DIR "$UPDATE_STATE_DIR"
    set_env "${dir}/.env" PANEL_PHP_VERSION "$PHP_VERSION"
    set_env "${dir}/.env" PANEL_PHP_FPM_SERVICE "${PANEL_SLUG}-fpm.service"
    set_env "${dir}/.env" PANEL_FRONTEND_SERVICE "${PANEL_SLUG}-frontend.service"
    set_env "${dir}/.env" PANEL_QUEUE_SERVICE "${PANEL_SLUG}-queue.service"
    set_env "${dir}/.env" PANEL_NODE_BIN_DIR "$(dirname "$NODE_BIN")"
    # Node is installed under fnm, not on the panel process's PATH, so a bare
    # `node` invocation (e.g. the dashboard runtime probe) fails with
    # "node: not found". Pin the absolute binary for server.node_binary.
    set_env "${dir}/.env" SERVER_NODE_BINARY "$NODE_BIN"

    chown -R "${APP_USER}:${APP_USER}" "$dir"
    chmod -R 775 "${dir}/storage" "${dir}/bootstrap/cache"

    grep -q '^APP_KEY=base64:' "${dir}/.env" \
        || run sudo -u "$APP_USER" -H sh -c 'cd "$1" && exec "$2" artisan key:generate --force' -- "$dir" "/usr/bin/php${PHP_VERSION}"
    ok "application key set"

    run sudo -u "$APP_USER" -H sh -c 'cd "$1" && exec "$2" artisan migrate --force' -- "$dir" "/usr/bin/php${PHP_VERSION}"
    run sudo -u "$APP_USER" -H sh -c 'cd "$1" && exec "$2" artisan db:seed --class=PermissionSeeder --force' -- "$dir" "/usr/bin/php${PHP_VERSION}"
    ok "database migrated and permissions seeded"

    # Tell the panel what we built. It can detect that nginx and PHP are here,
    # but not whether that was a deliberate `lemp` build or a box somebody
    # assembled by hand — and the difference matters to the setup page.
    run sudo -u "$APP_USER" -H sh -c 'cd "$1" && exec "$2" artisan server:record-stack "$3"' -- "$dir" "/usr/bin/php${PHP_VERSION}" "$STACK"
    ok "stack recorded as ${STACK}"

    # Central-management token. Passed in by the central panel's provisioning
    # flow when this server is being installed on behalf of an existing customer.
    # The token is stored server-side and the same value is registered with
    # central so it can call this server's API without any user session.
    if [[ -n "${CENTRAL_TOKEN:-}" ]]; then
        run sudo -u "$APP_USER" -H sh -c '
            cd "$1" && php "$2" artisan tinker --execute="
                \\
                \$token = \"${CENTRAL_TOKEN}\";
                \$settings = \\DB::table(\\'settings\\')->where(\\'id\\', 1)->first();
                if (\$settings) {
                    \\DB::table(\\'settings\\')->where(\\'id\\', 1)->update([\\'central_token\\' => \$token, \\'updated_at\\' => now()]);
                } else {
                    \\DB::table(\\'settings\\')->insert([\\'id\\' => 1, \\'central_token\\' => \$token, \\'created_at\\' => now(), \\'updated_at\\' => now()]);
                }
            "' -- "$dir" "/usr/bin/php${PHP_VERSION}"
        ok "central management token stored"
    fi
}

# ─── Frontend ────────────────────────────────────────────────────────────────

build_frontend() {
    step "Building the panel"

    local dir="${APP_DIR}/frontend"
    # Next inlines NEXT_PUBLIC_* at build time, so this has to be the final
    # scheme, not a guess. That is why TLS is settled before this runs.
    local scheme="$SCHEME"
    local api_base="${scheme}://${API_HOST}"

    printf 'NEXT_PUBLIC_API_URL=%s\nNEXT_PUBLIC_APP_URL=%s://%s\n' \
        "$api_base" "$scheme" "$PANEL_HOST" >"${dir}/.env.production"
    chown "${APP_USER}:${APP_USER}" "${dir}/.env.production"

    chown -R "${APP_USER}:${APP_USER}" "$dir"

    # npm is a script with a `#!/usr/bin/env node` shebang, so it runs under
    # whatever node is first on PATH. Pinning PATH here is what makes the build
    # use the version we installed rather than whatever else is on the box.
    local node_dir
    node_dir=$(dirname "$NODE_BIN")

    # V8 will not grow its old space past this cap no matter how much memory
    # the box has, so a large `next build` dies with "FATAL ERROR: Reached
    # heap limit Allocation failed - JavaScript heap out of memory" while
    # `free` still shows plenty spare. That is a different failure from the
    # one configure_swap() addresses: swap cannot help here, because nothing
    # is short of memory — V8 is refusing to use it.
    #
    # Node picks this default from total RAM, so the boxes most likely to hit
    # it are exactly the small ones the panel is meant to run on. Sized from
    # RAM rather than hardcoded: a fixed 4 GB on a 1 GB box invites the OOM
    # killer instead, trading a clear V8 error for a bare "Killed".
    local ram_mb heap_mb
    ram_mb=$(awk '/MemTotal/ {printf "%d", $2/1024}' /proc/meminfo)
    if (( ram_mb >= 7500 )); then
        heap_mb=4096
    elif (( ram_mb >= 3500 )); then
        heap_mb=3072
    else
        # Above physical RAM on a small box, deliberately: configure_swap()
        # has already added 2 GB by this point, and a slow build that
        # finishes beats a fast one that does not.
        heap_mb=2048
    fi

    say "     this is the slow part — a few minutes"
    run sudo -u "$APP_USER" -H env "PATH=${node_dir}:/usr/local/bin:/usr/bin:/bin" \
        npm --prefix "$dir" ci --no-audit --no-fund
    run sudo -u "$APP_USER" -H env "PATH=${node_dir}:/usr/local/bin:/usr/bin:/bin" \
        "NODE_OPTIONS=--max-old-space-size=${heap_mb}" \
        npm --prefix "$dir" run build
    ok "panel built (${heap_mb} MB build heap)"
}

# ─── PHP-FPM pool ────────────────────────────────────────────────────────────

configure_fpm() {
    step "Configuring PHP-FPM"

    # The panel runs under its OWN php-fpm master, not the distro's shared
    # php${PHP_VERSION}-fpm.service. Two reasons, both load-bearing:
    #
    #   1. The panel runs privileged host commands (useradd, writing webserver
    #      configs, ...) synchronously via `sudo` from its PHP workers. The
    #      distro unit ships ProtectSystem=full, which mounts /etc, /usr and
    #      /boot read-only for the master and every child it spawns — so
    #      `sudo useradd` dies with "cannot lock /etc/passwd". ReadWritePaths=
    #      does not reliably punch back through ProtectSystem, so the panel's
    #      master simply must not be sandboxed that way.
    #
    #   2. ProtectSystem is a property of the fpm *master* and is shared by
    #      every pool under it. Relaxing the shared unit would strip that
    #      protection from every hosted site too. A dedicated master confines
    #      the relaxation to the panel alone; hosted-site pools stay on the
    #      distro unit (PoolManager writes them into its pool.d) with their
    #      hardening intact.
    local pool_dir="/etc/php/${PHP_VERSION}/fpm/${PANEL_SLUG}-pool.d"
    local master_conf="/etc/php/${PHP_VERSION}/fpm/php-fpm-${PANEL_SLUG}.conf"

    run mkdir -p "$pool_dir"

    # Migrate away from the old layout: a pool left in the distro's shared
    # pool.d by an earlier install would be loaded by the hardened shared
    # master too, racing the dedicated master for the same socket.
    run rm -f "/etc/php/${PHP_VERSION}/fpm/pool.d/${PANEL_SLUG}.conf"

    # Its own pool on its own socket, owned by the panel's user. `ondemand`
    # because a control panel is idle most of the time and a 1 GB box has
    # better uses for the memory than parked PHP workers.
    cat >"${pool_dir}/${PANEL_SLUG}.conf" <<POOL
[${PANEL_SLUG}]
user = ${APP_USER}
group = ${APP_USER}
listen = /run/php/${PANEL_SLUG}-fpm.sock
listen.owner = www-data
listen.group = www-data
pm = ondemand
pm.max_children = 10
pm.process_idle_timeout = 30s
pm.max_requests = 500
php_admin_value[error_log] = /var/log/php-${PANEL_SLUG}.log
php_admin_flag[log_errors] = on
; Upload limits for the panel's *own* pool. These were previously unset, which
; left the panel on the distro defaults (upload_max_filesize=2M,
; post_max_size=8M) while nginx allowed 64M and the file manager advertised
; 50M -- so every upload over 2M was rejected by PHP before Laravel ever saw
; the request.
;
; Sized to match client_max_body_size, not to enable large uploads: files
; bigger than this go through the resumable chunked endpoint, which never
; sends more than one chunk per request. Raising these further would not help
; it and would only widen what a single request can consume.
php_admin_value[upload_max_filesize] = 64M
php_admin_value[post_max_size] = 64M
; The single-shot upload path buffers the file through PHP memory twice (read
; + pipe to stdin), so this has to clear 2x the 50M that endpoint accepts. The
; chunked path streams instead and is unaffected by this value.
php_admin_value[memory_limit] = 256M
POOL

    # A minimal master config that loads only the panel pool. Kept separate
    # from the distro's php-fpm.conf so a package upgrade never pulls the panel
    # pool back under the shared, hardened master.
    cat >"$master_conf" <<GLOBAL
[global]
pid = /run/php/php${PHP_VERSION}-fpm-${PANEL_SLUG}.pid
error_log = /var/log/php${PHP_VERSION}-fpm-${PANEL_SLUG}.log
daemonize = no
include = ${pool_dir}/*.conf
GLOBAL

    # Deliberately NOT sandboxed with ProtectSystem/ReadWritePaths: the panel
    # legitimately writes /etc, /usr and more as root via sudo. It is isolated
    # from hosted sites by being its own master, and the only account with sudo
    # here is the panel's own — hosted pools run as www-data with no sudo — so
    # this master carries no extra tenant risk.
    cat >/etc/systemd/system/${PANEL_SLUG}-fpm.service <<UNIT
[Unit]
Description=Control panel PHP-FPM (dedicated master)
After=network.target

[Service]
Type=notify
PIDFile=/run/php/php${PHP_VERSION}-fpm-${PANEL_SLUG}.pid
ExecStart=/usr/sbin/php-fpm${PHP_VERSION} --nodaemonize --fpm-config ${master_conf}
ExecReload=/bin/kill -USR2 \$MAINPID
Restart=on-failure

[Install]
WantedBy=multi-user.target
UNIT

    # The distro unit is left enabled for hosted-site pools, but the panel no
    # longer rides on it.
    run systemctl enable "php${PHP_VERSION}-fpm"
    run systemctl daemon-reload
    run systemctl enable --now "${PANEL_SLUG}-fpm.service"
    ok "panel pool on /run/php/${PANEL_SLUG}-fpm.sock (dedicated master ${PANEL_SLUG}-fpm.service)"
}

# ─── nginx ───────────────────────────────────────────────────────────────────

configure_web_server() {
    case "$WEB_SERVER" in
        nginx)  configure_nginx ;;
        apache) configure_apache ;;
    esac
}

configure_nginx() {
    step "Configuring nginx"

    mkdir -p /etc/nginx/snippets

    # The location blocks live in snippets that both the port-80 and (later) the
    # port-443 server blocks include. Written this way so the two can never
    # drift apart — the alternative, duplicating them per listener, is how a
    # config ends up serving different things over HTTP and HTTPS.
    local api_locations=/etc/nginx/snippets/${PANEL_SLUG}-api.conf
    local panel_locations=/etc/nginx/snippets/${PANEL_SLUG}-web.conf

    # The API. `^~ /api` in single-host mode so it wins over the catch-all proxy
    # to Next without a regex fight; a plain `/` when the API has its own name.
    local api_prefix="/"
    local sanctum_location=""
    if (( SINGLE_HOST )); then
        api_prefix="^~ /api"
        # Sanctum serves its CSRF-cookie route at /sanctum/csrf-cookie — a
        # top-level path, NOT under /api. In single-host mode the catch-all in
        # the panel snippet proxies everything but /api to Next, so without an
        # explicit /sanctum route the SPA's very first request
        # (GET /sanctum/csrf-cookie) hits Next and 404s, and login never starts.
        read -r -d '' sanctum_location <<'SANCTUM' || true
location ^~ /sanctum {
    try_files $uri $uri/ /index.php?$query_string;
}
SANCTUM
    fi

    cat >"$api_locations" <<NGINX
# Managed by the Control panel installer.
# Bounds one request, not one upload: files of any size go through the
# resumable chunked endpoint, which sends a chunk per request. This never has
# to grow to accommodate bigger files.
client_max_body_size 64M;

# Keeps request bodies in memory instead of spilling them to client_body_temp.
# This is the difference between one disk write per uploaded byte and two, and
# on a box that also serves the hosted sites the second write is not the cost
# that matters -- streaming the bytes through the page cache evicts the sites'
# hot files, and they start hitting disk.
#
# Must stay above the largest chunk the client will send. That is now sized by
# file: 8M up to 512M, 16M up to 5G, 32M beyond -- chunks go up one at a time,
# so each one costs a round trip, and a 5 GB file at 8M spends over two minutes
# on latency alone before any bandwidth is used. Raising the ladder in
# lib/api/files.js means raising this in the same change, or nginx starts
# spilling exactly the files the larger chunks were meant to speed up.
#
# nginx allocates this per request and only as much as the body needs, so the
# cost is not 36M standing: it is 36M x however many large uploads are in
# flight at once, bounded by pm.max_children (10).
client_body_buffer_size 36M;

location ${api_prefix} {
    try_files \$uri \$uri/ /index.php?\$query_string;
}

${sanctum_location}
location ~ \.php\$ {
    include fastcgi_params;
    fastcgi_pass unix:/run/php/${PANEL_SLUG}-fpm.sock;
    fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
    fastcgi_index index.php;
    # Long enough for a queued install to be *dispatched*; the work itself
    # happens in the worker, not in the request.
    fastcgi_read_timeout 300;
}

location ~ /\.(?!well-known) { deny all; }
NGINX

    cat >"$panel_locations" <<NGINX
# Managed by the Control panel installer.
# Hashed, immutable assets served straight off disk — Next's standalone output
# does not serve them, and round-tripping them through Node is wasted work.
location /_next/static/ {
    alias ${APP_DIR}/frontend/.next/static/;
    add_header Cache-Control "public, max-age=31536000, immutable";
    access_log off;
}

location / {
    proxy_pass http://127.0.0.1:${FRONTEND_PORT};
    proxy_http_version 1.1;
    proxy_set_header Host \$host;
    proxy_set_header X-Real-IP \$remote_addr;
    proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto \$scheme;
    # The client's own Connection header is passed through rather than
    # hardcoding "upgrade": the usual \$connection_upgrade recipe needs an nginx map
    # in the http block, which a site config cannot declare.
    proxy_set_header Upgrade \$http_upgrade;
    proxy_set_header Connection \$http_connection;
}
NGINX

    if (( SINGLE_HOST )); then
        cat >/etc/nginx/sites-available/${PANEL_SLUG}.conf <<NGINX
# Managed by the Control panel installer.
server {
    listen 80;
    listen [::]:80;
    server_name ${PANEL_HOST};
    root ${APP_DIR}/backend/public;

    location /.well-known/acme-challenge/ { root ${APP_DIR}/backend/public; }

    include ${api_locations};
    include ${panel_locations};
}
NGINX
    else
        cat >/etc/nginx/sites-available/${PANEL_SLUG}.conf <<NGINX
# Managed by the Control panel installer.
server {
    listen 80;
    listen [::]:80;
    server_name ${API_HOST};
    root ${APP_DIR}/backend/public;
    index index.php;

    location /.well-known/acme-challenge/ { root ${APP_DIR}/backend/public; }

    include ${api_locations};
}

server {
    listen 80;
    listen [::]:80;
    server_name ${PANEL_HOST};
    root ${APP_DIR}/frontend;

    location /.well-known/acme-challenge/ { root ${APP_DIR}/frontend; }

    include ${panel_locations};
}
NGINX
    fi

    ln -sf /etc/nginx/sites-available/${PANEL_SLUG}.conf /etc/nginx/sites-enabled/${PANEL_SLUG}.conf

    # Ubuntu's default site is a catch-all on port 80. Left enabled it answers
    # for our hostnames too, depending on which loads first.
    rm -f /etc/nginx/sites-enabled/default

    # Tested before reloading, always. A broken config that reaches a reload
    # takes the web server down — and on this box that includes the panel you
    # would use to fix it.
    if ! nginx -t >>"$LOG_FILE" 2>&1; then
        die "the generated nginx config failed its own test — see $LOG_FILE"
    fi

    run systemctl enable nginx
    run systemctl reload nginx
    ok "nginx serving ${PANEL_HOST}"
}

# ─── Services ────────────────────────────────────────────────────────────────

configure_apache() {
    step "Configuring Apache"

    # proxy_fcgi + SetHandler rather than mod_php or fcgid. mod_php would run PHP
    # inside Apache as www-data, which throws away the per-site user isolation the
    # whole panel is built on — the FPM pool exists precisely so PHP runs as the
    # panel's own account.
    run a2enmod proxy proxy_fcgi proxy_http rewrite headers setenvif ssl

    # Apache's default site is a catch-all on port 80 and answers for our
    # hostnames depending on load order.
    run a2dissite 000-default

    local conf="/etc/apache2/sites-available/${PANEL_SLUG}.conf"
    local api_block panel_block

    # The API. `AllowOverride All` because Laravel ships a .htaccess doing the
    # front-controller rewrite — reproducing it here would mean two copies of the
    # same rules that can disagree after an upgrade.
    read -r -d '' api_block <<CONF || true
    DocumentRoot ${APP_DIR}/backend/public

    <Directory ${APP_DIR}/backend/public>
        AllowOverride All
        Require all granted
    </Directory>

    <FilesMatch \\.php\$>
        SetHandler "proxy:unix:/run/php/${PANEL_SLUG}-fpm.sock|fcgi://localhost"
    </FilesMatch>

    # 300s so dispatching a queued install has room; the work itself happens in
    # the worker, not the request.
    ProxyTimeout 300
CONF

    read -r -d '' panel_block <<CONF || true
    ProxyPreserveHost On

    # Hashed immutable assets straight off disk. Next's standalone output does not
    # serve them, and routing them through Node is wasted work.
    Alias /_next/static ${APP_DIR}/frontend/.next/static
    <Directory ${APP_DIR}/frontend/.next/static>
        Require all granted
        Header set Cache-Control "public, max-age=31536000, immutable"
    </Directory>
    ProxyPass /_next/static !

    # The WebSocket rewrite MUST come before ProxyPass / — otherwise the
    # catch-all swallows the upgrade as ordinary HTTP and the handshake never
    # completes. Ordering, not presence, is what makes this work.
    RewriteEngine On
    RewriteCond %{HTTP:Upgrade} =websocket [NC]
    RewriteRule /(.*) ws://127.0.0.1:${FRONTEND_PORT}/\$1 [P,L]

    ProxyPass / http://127.0.0.1:${FRONTEND_PORT}/
    ProxyPassReverse / http://127.0.0.1:${FRONTEND_PORT}/
CONF

    if (( SINGLE_HOST )); then
        # One name: /api to Laravel, everything else to Next. Same origin, so no
        # cross-site cookie to get wrong.
        cat >"$conf" <<CONF
# Managed by the control panel installer.
<VirtualHost *:80>
    ServerName ${PANEL_HOST}

${api_block}

    <Location /api>
        ProxyPass !
    </Location>

    # Sanctum's CSRF-cookie route (/sanctum/csrf-cookie) is top-level, not under
    # /api — exclude it from the Next proxy too, or the SPA's first request 404s
    # and login never starts.
    <Location /sanctum>
        ProxyPass !
    </Location>

${panel_block}
</VirtualHost>
CONF
    else
        cat >"$conf" <<CONF
# Managed by the control panel installer.
<VirtualHost *:80>
    ServerName ${API_HOST}

${api_block}
</VirtualHost>

<VirtualHost *:80>
    ServerName ${PANEL_HOST}
    DocumentRoot ${APP_DIR}/frontend

${panel_block}
</VirtualHost>
CONF
    fi

    run a2ensite "${PANEL_SLUG}"

    # Tested before reloading, exactly as with nginx: a broken config that reaches
    # a reload takes the web server down, and on this box that includes the panel
    # you would use to fix it.
    if ! apache2ctl configtest >>"$LOG_FILE" 2>&1; then
        die "the generated Apache config failed its own test — see $LOG_FILE"
    fi

    run systemctl enable apache2
    run systemctl reload apache2
    ok "Apache serving ${PANEL_HOST}"
}

install_services() {
    step "Installing services"

    local backend="${APP_DIR}/backend"

    # The panel writes its update runner and progress state here as the panel
    # account, so the directory has to exist and belong to it before the first
    # update rather than being created by whoever happens to run first. 0750:
    # the script it holds is executable and names service units.
    run mkdir -p "$UPDATE_STATE_DIR"
    run chown "${APP_USER}:${APP_USER}" "$UPDATE_STATE_DIR"
    run chmod 750 "$UPDATE_STATE_DIR"
    ok "update state directory: ${UPDATE_STATE_DIR}"

    cat >/etc/systemd/system/${PANEL_SLUG}-frontend.service <<UNIT
[Unit]
Description=Control panel web interface (Next.js)
After=network.target

[Service]
Type=simple
User=${APP_USER}
Group=${APP_USER}
WorkingDirectory=${APP_DIR}/frontend
# Next's standalone output does not serve its own static assets or public files;
# they are copied in beside the server it does run.
ExecStartPre=/bin/sh -c 'mkdir -p .next/standalone/.next && rm -rf .next/standalone/.next/static .next/standalone/public && cp -a .next/static .next/standalone/.next/static && cp -a public .next/standalone/public 2>/dev/null || true'
ExecStart=${NODE_BIN} ${APP_DIR}/frontend/.next/standalone/server.js
Restart=always
RestartSec=5
Environment=PORT=${FRONTEND_PORT}
Environment=NODE_ENV=production
StandardOutput=journal
StandardError=journal

[Install]
WantedBy=multi-user.target
UNIT

    # One worker. Server operations are sequential by nature — two workers
    # racing to change the same server is a failure mode, not throughput.
    cat >/etc/systemd/system/${PANEL_SLUG}-queue.service <<UNIT
[Unit]
Description=Control panel queue worker
After=network.target

[Service]
Type=simple
User=${APP_USER}
Group=${APP_USER}
WorkingDirectory=${backend}
# No --queue, so this consumes `default` and nothing else. Jobs must
# therefore not name a queue: one sent elsewhere is accepted, stored and
# never run -- no error, no failed_jobs row, it simply never happens.
# Backups shipped that way and never once executed on a real install.
ExecStart=/usr/bin/php${PHP_VERSION} ${backend}/artisan queue:work --sleep=3 --tries=1 --max-time=3600
Restart=always
RestartSec=5
# Longer than the longest job, so a stop during a 30-minute install waits
# rather than killing it half-applied.
TimeoutStopSec=1800
StandardOutput=journal
StandardError=journal

[Install]
WantedBy=multi-user.target
UNIT

    # The scheduler tick. Without it the metrics collector never samples and the
    # disk cleaner never runs — features that look built but do nothing.
    cat >/etc/cron.d/${PANEL_SLUG}-scheduler <<CRON
* * * * * ${APP_USER} /usr/bin/php${PHP_VERSION} ${backend}/artisan schedule:run >> /dev/null 2>&1
CRON
    chmod 644 /etc/cron.d/${PANEL_SLUG}-scheduler

    run systemctl daemon-reload
    run systemctl enable --now ${PANEL_SLUG}-frontend.service
    run systemctl enable --now ${PANEL_SLUG}-queue.service
    ok "panel, queue worker and scheduler running"
}

# ─── Privileges ──────────────────────────────────────────────────────────────

configure_sudoers() {
    step "Granting the panel its privileges"

    # READ THIS BEFORE CHANGING IT.
    #
    # The panel creates system users, writes vhosts, and restarts services, so
    # it needs root for specific commands. PHP itself runs unprivileged and only
    # these escalate.
    #
    # Be clear about what this does and does not buy. The list includes `tee`,
    # `chown` and `sh`, because the panel's existing operations use them — and
    # any one of those as root is a path to full root. So this is **not**
    # least privilege, and an attacker with code execution inside the panel can
    # escalate.
    #
    # What it does buy, which is not nothing: PHP-FPM does not run as root, so
    # every bug *short of* code execution — a path traversal, a file disclosure,
    # a mis-scoped read — stays confined to an unprivileged account. Running FPM
    # as root would make the same bugs instantly fatal.
    #
    # The real fix is a privileged helper exposing semantic operations
    # (create-system-user, write-vhost-for-domain) that validate their arguments,
    # so the web tier never names a path at all. Until that exists, this is the
    # honest trade and it is written down rather than implied.
    local bins=(
        /usr/bin/apt-get /usr/bin/apt-cache /usr/bin/dpkg-query
        /usr/bin/systemctl /usr/bin/journalctl
        # The panel update starts from an HTTP request and so inherits the
        # panel's own php-fpm cgroup, which systemd kills wholesale when that
        # unit restarts -- which the update itself does, partway through.
        # systemd-run puts the script in a transient unit of its own so it
        # survives. setsid and nohup do not help: both deal with signals, and
        # a cgroup kill is not a signal the process gets to ignore.
        /usr/bin/systemd-run
        /usr/sbin/useradd /usr/sbin/userdel /usr/sbin/usermod /usr/sbin/groupadd
        /usr/sbin/chpasswd /usr/bin/gpasswd /usr/bin/getent /usr/bin/id
        /usr/bin/tee /usr/bin/mkdir /usr/bin/chown /usr/bin/chmod /usr/bin/rm
        /usr/bin/cp /usr/bin/mv /usr/bin/ln /usr/bin/install /usr/bin/truncate
        /usr/bin/find /usr/bin/tail /usr/bin/cat /usr/bin/test /usr/bin/which
        # Both paths, deliberately: runuser lives in /usr/sbin on Debian/Ubuntu
        # and in /usr/bin on RHEL-family. sudo matches on the resolved absolute
        # path, so a single wrong entry silently denies every asUser() operation
        # -- file manager, deployments, wp-cli -- with "a password is required".
        # sudo ignores entries whose file does not exist, so listing both is safe.
        /usr/sbin/runuser /usr/bin/runuser /usr/bin/sh /usr/bin/env
        /usr/sbin/nginx /usr/sbin/apachectl /usr/bin/lswsctrl
        /usr/sbin/phpenmod /usr/sbin/phpdismod /usr/bin/update-alternatives
        # Wildcard, not one entry per version: PoolManager tests a per-app
        # pool with `php-fpmX.Y -t` before every reload, and the version list
        # changes as PHP versions are added/removed through the panel's own
        # Node/PHP-version feature -- a fixed list here would need editing
        # every time that happens.
        /usr/sbin/php-fpm*
        /usr/bin/mysql /usr/bin/mariadb /usr/bin/mariadb-dump /usr/bin/redis-cli /usr/bin/mongosh
        /usr/sbin/ufw /usr/bin/fail2ban-client /usr/sbin/sshd
        /usr/bin/fallocate /usr/sbin/mkswap /usr/sbin/swapon /usr/sbin/swapoff
        /usr/bin/hostnamectl /usr/bin/timedatectl /usr/sbin/shutdown /usr/bin/df /usr/bin/du
        /usr/bin/ps /usr/bin/kill /usr/bin/ss /usr/bin/curl /usr/bin/unzip /usr/bin/zip
        /usr/bin/tar /usr/bin/git /usr/local/bin/fnm /usr/local/bin/wp
        /usr/bin/rsync
    )

    local list
    list=$(printf '%s, ' "${bins[@]}")
    list="${list%, }"

    cat >/etc/sudoers.d/${PANEL_SLUG} <<SUDOERS
# Managed by the Control panel installer.
# See configure_sudoers() in install.sh for what this does and does not contain.
Defaults:${APP_USER} !requiretty
${APP_USER} ALL=(root) NOPASSWD: ${list}
SUDOERS
    chmod 440 /etc/sudoers.d/${PANEL_SLUG}

    # A malformed sudoers file locks everyone out of sudo, so it is validated
    # and removed if it does not parse.
    if ! visudo -cqf /etc/sudoers.d/${PANEL_SLUG} >>"$LOG_FILE" 2>&1; then
        rm -f /etc/sudoers.d/${PANEL_SLUG}
        die "the generated sudoers file did not validate and has been removed"
    fi
    ok "sudoers rule installed for ${APP_USER}"

    # The security page reads effective sshd config with `sudo -n sshd -T`, and
    # sshd refuses to run without its privilege-separation directory /run/sshd.
    # That lives on a tmpfs and is only created by ssh.service — on a
    # socket-activated ssh (Ubuntu 24.04+ default) it may be absent at rest and
    # is wiped on every reboot, so the read fails with "Missing privilege
    # separation directory". A tmpfiles rule recreates it at boot regardless.
    cat >/etc/tmpfiles.d/${PANEL_SLUG}-sshd.conf <<TMPFILES
d /run/sshd 0755 root root -
TMPFILES
    run systemd-tmpfiles --create /etc/tmpfiles.d/${PANEL_SLUG}-sshd.conf
    ok "sshd runtime directory (/run/sshd) guaranteed at boot"
}

# ─── Firewall ────────────────────────────────────────────────────────────────

configure_firewall() {
    step "Checking the firewall"

    if ! command -v ufw >/dev/null 2>&1; then
        skip "ufw is not installed"
        return
    fi

    # Rules are added but ufw is never enabled here. Enabling a firewall on
    # someone's server as a side effect of an install is how people lose SSH.
    run ufw allow 22/tcp
    run ufw allow 80/tcp
    run ufw allow 443/tcp

    if [[ "$(ufw status 2>/dev/null)" == *inactive* ]]; then
        ok "rules added for 22, 80, 443 (ufw is inactive — not enabling it)"
    else
        ok "rules added for 22, 80, 443"
    fi
}

# ─── TLS ─────────────────────────────────────────────────────────────────────

# The Apache half of the self-signed fallback. Separate file and separate site so
# a later successful certbot run edits an untouched config, and so backing this
# out is one `a2dissite` rather than an unpick.
self_signed_apache() {
    local conf="/etc/apache2/sites-available/${PANEL_SLUG}-tls.conf"
    local names="${PANEL_HOST}"
    (( SINGLE_HOST )) || names="${PANEL_HOST} ${API_HOST}"

    {
        printf '# Self-signed fallback, written by the control panel installer.\n'
        printf '# Delete this site and run: certbot --apache -d %s\n' "$names"
        # Apache needs one VirtualHost per name here, and each includes the same
        # blocks the port-80 sites use — read back from the file we already wrote
        # rather than duplicated, so the two cannot drift.
        sed -e 's/\*:80/*:443/' \
            -e "/ServerName/a\\    SSLEngine on\\n    SSLCertificateFile /etc/ssl/${PANEL_SLUG}/cert.pem\\n    SSLCertificateKeyFile /etc/ssl/${PANEL_SLUG}/key.pem" \
            "/etc/apache2/sites-available/${PANEL_SLUG}.conf" | tail -n +2
    } >"$conf"

    run a2ensite "${PANEL_SLUG}-tls"

    if apache2ctl configtest >>"$LOG_FILE" 2>&1; then
        run systemctl reload apache2
        ok "self-signed certificate in place — browsers will warn until a real one is issued"
        TLS_STATE="self-signed"
        SCHEME="https"
    else
        # One a2dissite puts Apache back exactly as it was. Serving plain HTTP
        # beats serving nothing.
        warn "the self-signed configuration did not validate; staying on HTTP"
        run a2dissite "${PANEL_SLUG}-tls"
        apache2ctl configtest >>"$LOG_FILE" 2>&1 && systemctl reload apache2
        TLS_STATE="none"
        SCHEME="http"
    fi
}

configure_tls() {
    step "Setting up HTTPS"

    if (( ! WANT_SSL )); then
        SCHEME="http"
        TLS_STATE="none"
        skip "--no-ssl given"
        return
    fi

    export DEBIAN_FRONTEND=noninteractive
    case "$WEB_SERVER" in
        nginx)  run apt-get install -y certbot python3-certbot-nginx ;;
        apache) run apt-get install -y certbot python3-certbot-apache ;;
    esac

    # certbot's plugin has to match the web server it is editing.
    local plugin="--nginx"
    [[ "$WEB_SERVER" == "apache" ]] && plugin="--apache"

    local args=("$plugin" --non-interactive --agree-tos --redirect -d "$PANEL_HOST")
    (( SINGLE_HOST )) || args+=(-d "$API_HOST")

    if [[ -n "$ADMIN_EMAIL" ]]; then
        args+=(-m "$ADMIN_EMAIL")
    else
        args+=(--register-unsafely-without-email)
    fi

    if certbot "${args[@]}" >>"$LOG_FILE" 2>&1; then
        ok "Let's Encrypt certificate issued"
        TLS_STATE="letsencrypt"
        SCHEME="https"
        return
    fi

    # Expected often enough to be handled rather than treated as an error.
    # nip.io is not on the Public Suffix List, so Let's Encrypt counts every
    # *.nip.io certificate in the world against one 50-per-week limit — a
    # bucket shared with everybody else using it. The install must not fail
    # because that bucket happened to be empty.
    warn "could not get a Let's Encrypt certificate (see $LOG_FILE)"
    if [[ -z "$DOMAIN" ]]; then
        warn "nip.io shares one certificate rate limit globally, so this is common"
        warn "re-run later, or use --domain=your.own.domain for a reliable certificate"
    fi

    say "     falling back to a self-signed certificate so traffic is still encrypted"
    mkdir -p /etc/ssl/${PANEL_SLUG}
    run openssl req -x509 -nodes -newkey rsa:2048 -days 3650 \
        -keyout /etc/ssl/${PANEL_SLUG}/key.pem \
        -out /etc/ssl/${PANEL_SLUG}/cert.pem \
        -subj "/CN=${PANEL_HOST}"

    if [[ "$WEB_SERVER" == "apache" ]]; then
        self_signed_apache
        return
    fi

    # A separate file rather than appending to the port-80 config, so a later
    # successful `certbot --nginx` run has an untouched file to edit and this one
    # can simply be deleted. The 443 blocks include the very same location
    # snippets the port-80 blocks do — nothing is duplicated, so HTTP and HTTPS
    # cannot end up serving different things.
    local tls_conf=/etc/nginx/sites-available/${PANEL_SLUG}-tls.conf
    local api_locations=/etc/nginx/snippets/${PANEL_SLUG}-api.conf
    local panel_locations=/etc/nginx/snippets/${PANEL_SLUG}-web.conf

    if (( SINGLE_HOST )); then
        cat >"$tls_conf" <<NGINX
# Self-signed fallback, written by the Control panel installer.
# Delete this file and run: certbot --nginx -d ${PANEL_HOST}
server {
    listen 443 ssl;
    listen [::]:443 ssl;
    server_name ${PANEL_HOST};
    root ${APP_DIR}/backend/public;

    ssl_certificate /etc/ssl/${PANEL_SLUG}/cert.pem;
    ssl_certificate_key /etc/ssl/${PANEL_SLUG}/key.pem;

    include ${api_locations};
    include ${panel_locations};
}
NGINX
    else
        cat >"$tls_conf" <<NGINX
# Self-signed fallback, written by the Control panel installer.
# Delete this file and run: certbot --nginx -d ${PANEL_HOST} -d ${API_HOST}
server {
    listen 443 ssl;
    listen [::]:443 ssl;
    server_name ${API_HOST};
    root ${APP_DIR}/backend/public;
    index index.php;

    ssl_certificate /etc/ssl/${PANEL_SLUG}/cert.pem;
    ssl_certificate_key /etc/ssl/${PANEL_SLUG}/key.pem;

    include ${api_locations};
}

server {
    listen 443 ssl;
    listen [::]:443 ssl;
    server_name ${PANEL_HOST};
    root ${APP_DIR}/frontend;

    ssl_certificate /etc/ssl/${PANEL_SLUG}/cert.pem;
    ssl_certificate_key /etc/ssl/${PANEL_SLUG}/key.pem;

    include ${panel_locations};
}
NGINX
    fi

    ln -sf "$tls_conf" /etc/nginx/sites-enabled/${PANEL_SLUG}-tls.conf

    if nginx -t >>"$LOG_FILE" 2>&1; then
        run systemctl reload nginx
        ok "self-signed certificate in place — browsers will warn until a real one is issued"
        TLS_STATE="self-signed"
        SCHEME="https"
    else
        # Removing one symlink puts nginx back exactly as it was, which is why
        # this went in a separate file rather than being appended to the working
        # one. Serving plain HTTP beats serving nothing.
        warn "the self-signed configuration did not validate; staying on HTTP"
        rm -f /etc/nginx/sites-enabled/${PANEL_SLUG}-tls.conf
        nginx -t >>"$LOG_FILE" 2>&1 && systemctl reload nginx
        TLS_STATE="none"
        SCHEME="http"
    fi
}

# ─── Finish ──────────────────────────────────────────────────────────────────

finish() {
    step "Finishing up"

    local backend="${APP_DIR}/backend"

    # Last, because it freezes whatever .env says at this moment. Anything that
    # edits .env after this must re-run it.
    run sudo -u "$APP_USER" -H sh -c 'cd "$1" && exec "$2" artisan config:cache' -- "$backend" "/usr/bin/php${PHP_VERSION}"
    run sudo -u "$APP_USER" -H sh -c 'cd "$1" && exec "$2" artisan route:cache' -- "$backend" "/usr/bin/php${PHP_VERSION}"
    ok "configuration cached"

    # Prove the panel actually works before claiming the install succeeded.
    # Everything above this line only shows that commands ran as *root*; the
    # panel runs as an unprivileged account, and the gap between those two is
    # where a whole non-functional install can hide. Run as APP_USER for that
    # reason — as root it would pass regardless.
    #
    # Not fatal: the panel is installed either way, and the failures are
    # usually fixable in place. But it is printed loudly, because an installer
    # that says "done" over a broken panel is worse than one that fails.
    step "Checking the installation"
    if (( DRY_RUN )); then
        printf '     %s$ artisan panel:doctor%s\n' "$DIM" "$RESET"
    elif sudo -u "$APP_USER" -H sh -c 'cd "$1" && exec "$2" artisan panel:doctor' -- "$backend" "/usr/bin/php${PHP_VERSION}"; then
        ok "all checks passed"
    else
        warn "some checks failed — see above. The panel is installed but parts of it will not work until these are fixed."
    fi

    local scheme="$SCHEME"

    printf '\n%s%s The control panel is installed%s\n\n' "$BOLD" "$GREEN" "$RESET"
    printf '  Panel:   %s%s://%s%s\n' "$BOLD" "$scheme" "$PANEL_HOST" "$RESET"
    (( SINGLE_HOST )) || printf '  API:     %s://%s\n' "$scheme" "$API_HOST"
    printf '  Stack:   %s (%s)\n' "$STACK" "$WEB_SERVER"
    printf '  Log:     %s\n' "$LOG_FILE"
    printf '  Files:   %s\n\n' "$APP_DIR"

    printf '  %sOpen the panel and register — the first account becomes the%s\n' "$BOLD" "$RESET"
    printf '  %sadministrator, and registration closes behind it.%s\n\n' "$BOLD" "$RESET"

    printf '  %sDo that now.%s Until you do, anyone who reaches this address can\n' "$YELLOW" "$RESET"
    printf '  claim the administrator account.\n\n'

    if [[ "${TLS_STATE:-none}" == "self-signed" ]]; then
        printf '  Your browser will warn about the certificate. To replace it with a\n'
        printf '  real one once a rate-limit slot frees up:\n'
        printf '    sudo certbot --nginx -d %s\n\n' "$PANEL_HOST"
    fi

    printf '  Then use the setup page to install a database engine and anything\n'
    printf '  else you need.\n\n'
}

# ─── Run ─────────────────────────────────────────────────────────────────────

main() {
    printf '%sControl panel installer%s\n' "$BOLD" "$RESET"
    (( DRY_RUN )) && printf '%s(dry run — nothing will be changed)%s\n' "$DIM" "$RESET"

    preflight
    resolve_stack
    resolve_hostnames
    configure_swap
    install_packages
    # fnm's shared runtime tree is owned by the panel account, so that
    # account must exist before install_node() changes its ownership.
    create_user
    install_node
    fetch_source
    configure_redis
    configure_fpm
    # nginx and TLS come before the two things that bake URLs in: the backend's
    # .env and the frontend's build. Next inlines NEXT_PUBLIC_* at build time, so
    # building first and getting TLS afterwards ships a panel calling https on a
    # server answering http.
    configure_web_server
    configure_tls
    setup_backend
    build_frontend
    install_services
    configure_sudoers
    configure_firewall
    finish
}

main "$@"
