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
# What an administrator sees on SSH login while the panel installs and once it
# has: the install keeps its progress here, and LOGIN_SCRIPT reads it (see
# start_login_status). Outside APP_DIR for the same reason as the update state,
# and readable by every account, because it holds step names and addresses and
# never a secret. The login script decides who is shown it.
INSTALL_STATE_DIR="${INSTALL_STATE_DIR:-/var/lib/${PANEL_SLUG}-install}"
LOGIN_SCRIPT="/etc/profile.d/${PANEL_SLUG}-login.sh"
PHP_VERSION="${PHP_VERSION:-8.4}"
NODE_VERSION="${NODE_VERSION:-24}"
FRONTEND_PORT="${FRONTEND_PORT:-3100}"
FNM_DIR="/opt/fnm"
FNM_BIN="/usr/local/bin/fnm"
# fnm itself, pinned: the release binary and its SHA-256 (GitHub's published
# asset digest), rather than fnm.vercel.app's script fetching whatever is
# latest and running it as root (review finding S3). Bump all three together.
FNM_VERSION="1.39.0"
FNM_SHA256_X86_64="7807664f39d39fc518da1c35ba0181e4b3267603c4b1dedeb4b5fc6ae440a224"
FNM_SHA256_AARCH64="4eaff58b2c5bf30d0934027572dd0b5bbb60d2a1af309230b53662d4b1d45599"
# The apt signing keys trusted for packages.sury.org and Docker: the primary
# key fingerprints, checked like the LiteSpeed ones (add_ols_repository).
SURY_KEY_FPR="15058500A0235D97F5D10063B188E2B695BD4743"
DOCKER_KEY_FPR="9DC858229FC7DD38854AE2D88D81803C0EBFCD88"
# The identity OpenLiteSpeed's workers run as, from its own httpd_config.conf
# defaults. Named once because two places need it -- the panel's fpm socket on
# the other stacks, and the panel's LSAPI socket directory on this one -- and
# they disagreed about it for as long as both existed.
OLS_USER="nobody"
OLS_GROUP="nogroup"
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
FORCE=0            # --force: install on a server that isn't fresh

# The fresh-server check's outcome (fresh_server_check): passed, forced,
# rerun or, on a dry run, not_fresh. Written to the state file and the log.
FRESH_CHECK=""
# What it found that a fresh server would not have, one short string each.
FRESH_FINDINGS=()
# Every line it printed, so the log holds them once it exists.
FRESH_NOTES=()
# Prefixed to every path the check reads. For the tests only; always empty here.
FRESH_ROOT=""

# A failed report's machine-readable cause, for the one failure that has one
# (code "not_fresh", with FAIL_FINDINGS). Empty for every other failure.
FAIL_CODE=""
FAIL_FINDINGS=()

# --central-token=, or the environment variable, which is the better of the two:
# an argument is readable in `ps` by every account on the box for the length of
# the install, and this one is a credential. Read from the environment here so
# that path keeps working, and never printed anywhere.
CENTRAL_TOKEN="${CENTRAL_TOKEN:-}"

# Set by Central when it creates the server (2026-10-17). All optional; without
# them the install is exactly what it was.
#
#   PANEL_ADMIN_USERNAME / PANEL_ADMIN_PASSWORD — the first administrator,
#     created before the panel is reachable. Otherwise a fresh panel is open for
#     registration and the first visitor becomes its administrator, and a
#     server Central built has a public address before its owner opens it.
#     The password is environment-only, never a flag: an argument is readable in
#     `ps` by every account here for the length of the install.
#   PROGRESS_URL — where each step is reported, signed with CENTRAL_TOKEN.
PANEL_ADMIN_USERNAME="${PANEL_ADMIN_USERNAME:-}"
PANEL_ADMIN_PASSWORD="${PANEL_ADMIN_PASSWORD:-}"
PROGRESS_URL="${PROGRESS_URL:-}"
# CENTRAL_URL — Central's own address (2026-10-20, RC-15). The panel reads its
#   public branding from it (GET /api/v1/site-settings); unset, the panel keeps
#   its built-in branding.
CENTRAL_URL="${CENTRAL_URL:-}"
PROGRESS_READY=0   # set once PROGRESS_URL has been checked
# PROGRESS_ACTIVITY=1 — Central asks for the lines the installer prints while a
#   long command runs (which packages are being set up, the wait for Ubuntu's
#   updates, "still building…"), as `activity`, `activity_code` and
#   `activity_elapsed` on an `installing` report. Off unless asked for: a
#   Central that does not expect them would add every one to its step history.
PROGRESS_ACTIVITY="${PROGRESS_ACTIVITY:-0}"

# Secrets this script comes to know beyond the ones above (every Redis password
# it tried), so the log lines a failed report carries can leave them out.
REDACT=()

# Which stack to build. Asked rather than assumed, because it decides the web
# server — and the web server serves the panel itself, so it cannot be changed
# from inside the panel later without the panel going down with it.
#
# `ols` was absent until 2026-09-01 and labelled experimental until 2026-09-09.
# Two decisions from that period are still load-bearing and should not be
# undone casually:
#
#   * The panel runs on LSPHP here, the same build its hosted sites use, and
#     ondrej's PHP is not installed at all. Reversed on 2026-09-10: the earlier
#     note said the panel stayed on PHP-FPM "so the panel does not also depend
#     on the lsphp packages it has never used", which traded one dependency for
#     two PHP builds on one box. See the note above configure_ols().
#   * The panel's own vhost is written OUTSIDE the panel-managed markers in
#     httpd_config.conf — see configure_ols() for why that is load-bearing.
#
# One capability difference remains, and the installer says it at selection:
# there is no per-site PHP isolation. It is not a defect in this script. The
# 8G firewall and the bot blocker both work on OLS — the templates render
# their rules into each site's vhconf (the WAF since 2026-09-30).
STACK=""           # --stack=lemp|lamp|mern|ols|docker  (prompted, or lemp)
WEB_SERVER=""      # derived from STACK

# The PHP the PANEL itself runs on: composer, artisan, the queue worker, cron
# and the web SAPI, all the same binary.
#
# One build, deliberately. Two would mean the CLI that installs the code and
# the SAPI that serves it having different extension sets, and the failure mode
# is the API answering 500 on a missing extension while the CLI that installed
# it works perfectly.
#
# On lemp/lamp/mern that is ondrej's `/usr/bin/php8.4` with php-fpm behind the
# web server. On ols it is LiteSpeed's lsphp, because OpenLiteSpeed spawns PHP
# itself over LSAPI and a second PHP from a second repository would be the two
# builds this exists to avoid. Set by derive_php_runtime() once the stack is
# known.
PANEL_PHP_BIN=""   # CLI: artisan, composer, queue worker, cron
COMPOSER_BIN=""    # resolved in install_packages; may predate us
PANEL_PHP_SAPI=""  # what the web server talks to: "fpm" or "lsapi"

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
# The SSH login status (start_login_status). Off until the first change is
# made: a refusal in preflight changed nothing, so there is nothing to report.
LOGIN_STATUS=0
STEP_TOTAL=0
# When this run began — one value for every report it makes, so a reader can
# tell this run's reports from an earlier run's (Central's progress, the SSH
# login status). Set here, not when the login status is prepared a few steps
# in: the first reports went out with 0.
INSTALL_STARTED="$(date +%s)"

# needrestart (Ubuntu 24.04 and later) runs after every apt transaction and
# restarts each service whose libraries changed — including the systemd unit
# an automated install runs in, which starts the whole install again from step
# one. Seen three times in one install on a fresh 24.04 server (2026-10-17).
# Its apt hook stands aside while this is set; this script's own apt runs
# inherit it, and nothing else on the server is affected.
export NEEDRESTART_SUSPEND=1
say()  { printf '%s\n' "$*"; }
step() {
    STEP_NO=$((STEP_NO + 1)); CURRENT_STEP="$*"
    printf '\n%s[%02d]%s %s%s%s\n' "$DIM" "$STEP_NO" "$RESET" "$BOLD" "$*" "$RESET"
    save_install_state installing
}
ok()   { printf '     %s✓%s %s\n' "$GREEN" "$RESET" "$*"; }
skip() { printf '     %s·%s %s %s(already done)%s\n' "$DIM" "$RESET" "$*" "$DIM" "$RESET"; }
warn() { printf '     %s!%s %s\n' "$YELLOW" "$RESET" "$*" >&2; }

# Log lines on stdin, made safe to show anyone: colour codes stripped, then
# every secret this script knows replaced with [redacted]. The terminal and
# Central's failed report both go through here — the terminal's copy ends up
# in the journal when Central runs the install under systemd-run.
redact_log() {
    local text secret
    text="$(sed 's/\x1b\[[0-9;?]*[A-Za-z]//g')"
    # Every name defaulted: setup_backend unsets PANEL_ADMIN_PASSWORD once the
    # admin exists, and a bare "$PANEL_ADMIN_PASSWORD" under `set -u` killed
    # this mid-report on every failure after that point.
    for secret in "${PANEL_ADMIN_PASSWORD:-}" "${CENTRAL_TOKEN:-}" "${REDIS_PASSWORD:-}" "${REDACT[@]}"; do
        [[ -n "$secret" ]] && text="${text//"$secret"/[redacted]}"
    done
    if [[ -n "$text" ]]; then
        printf '%s\n' "$text"
    fi
}

# Tail of the install log, indented, or a note saying why there isn't one.
# Every failure path prints this: "apt failed" with no output is not a
# diagnosis, and the log is the only place the actual output went.
log_tail() {
    local lines="${1:-15}"

    if [[ -r "${LOG_FILE:-}" ]]; then
        tail -n "$lines" "$LOG_FILE" 2>/dev/null | redact_log | sed 's/^/       /'
    else
        printf '       (no log yet — the install stopped before creating %s)\n' "${LOG_FILE:-the log file}"
    fi
}

# One place that renders a stop, so every failure looks the same and always
# says: what broke, where, what the log holds, and what to do next.
report_failure() {
    local headline="$1" detail="${2:-}"

    # The headline only: the detail can be a full command line.
    save_install_state failed "$headline"

    printf '\n%s%sInstall stopped%s during: %s%s%s\n\n' "$BOLD" "$RED" "$RESET" "$BOLD" "$CURRENT_STEP" "$RESET" >&2
    printf '  %s\n' "$headline" >&2
    [[ -n "$detail" ]] && printf '  %s%s%s\n' "$DIM" "$detail" "$RESET" >&2

    # No log means we stopped in preflight, before anything was touched. Do
    # not point the user at a file that does not exist, and do not pad the
    # message with recovery steps for a machine that was never changed.
    # The requirements check runs after the log exists, but it too stops before
    # anything is changed, and says so itself.
    if [[ ! -r "${LOG_FILE:-}" || "$headline" == *"Nothing has been changed on this server."* ]]; then
        # The fresh-server stop already says it, in the sentence Central shows.
        [[ "$headline" == *"Nothing on this server was changed."* || "$headline" == *"Nothing has been changed on this server."* ]] \
            || printf '\n  %sNothing on this server was changed.%s\n' "$DIM" "$RESET" >&2
        printf '\n' >&2

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

die() { progress_stop 2>/dev/null || true; report_failure "$*"; exit 1; }

# set -e kills the script on ANY failing command, including the ~200 that do
# not go through run(). Without this trap that death is completely silent:
# no message, no log pointer, just a non-zero exit — which reads to the user
# as "it did nothing". This turns every one of those into a real report.
on_error() {
    local code="$1" line="$2" command="$3"

    trap - ERR
    progress_stop 2>/dev/null || true
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
        progress_stop 2>/dev/null || true
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
    progress_start "${label,}"
    run "$@"
    progress_stop
    ok "${label} ($((SECONDS - started))s)"
}

# ─── Progress while a long command runs ──────────────────────────────────────
#
# A command that runs for minutes used to print one line and then nothing: a
# ten-minute wait for Ubuntu's own updates looked exactly like a hung installer,
# in the terminal and in Central. So while one runs, a ticker in the background
# watches it and prints a full line when there is something to say:
#
#   - which group of packages apt is setting up, from apt's own status lines;
#   - that apt is waiting for Ubuntu's updates, every 15 s;
#   - otherwise how far it got, or "still <doing what>", after 15 s of silence.
#
# Full lines only, never \r: the journal and Central's systemd-run capture
# keep every byte. The ticker only watches — the command runs exactly as
# before, with the same exit code and failure handling — and it is stopped
# before anything else is printed (progress_stop; die, run and the EXIT trap
# call it too, so none outlives a failure).

PROGRESS_PID=""
# A private directory for this run (apt's status file, the last activity sent
# to Central), made in main() and removed by the EXIT trap.
RUN_DIR=""

# 5 → "5 s", 75 → "1 min 15 s".
fmt_elapsed() {
    local s="${1:-0}"

    if (( s < 60 )); then
        printf '%d s' "$s"
    else
        printf '%d min %02d s' $((s / 60)) $((s % 60))
    fi
}

# The clock, as a function so the tests can set it.
now_s() { date +%s; }

# The readable group a package belongs to, or nothing for one that is not
# worth a line of its own (a library: it shows as "installing NN%").
apt_group() {
    local pkg="${1%%:*}" v

    case "$pkg" in
        php[0-9]*)
            v="${pkg#php}"
            printf 'PHP %s' "${v%%-*}" ;;
        lsphp[0-9]*)
            v="${pkg#lsphp}"; v="${v%%-*}"
            printf 'PHP %s.%s (LiteSpeed)' "${v:0:1}" "${v:1}" ;;
        nginx|nginx-*|libnginx-mod-*) printf 'nginx' ;;
        apache2|apache2-*) printf 'Apache' ;;
        openlitespeed*) printf 'OpenLiteSpeed' ;;
        redis-server|redis-tools|redis) printf 'Redis' ;;
        build-essential|gcc|gcc-[0-9]*|g++|g++-[0-9]*|cpp|cpp-[0-9]*|make|libc6-dev) printf 'build tools' ;;
        postfix) printf 'local mail (Postfix)' ;;
        certbot|python3-certbot*) printf 'certificate tool' ;;
        docker-*|containerd.io) printf 'Docker' ;;
        supervisor) printf 'supervisor' ;;
        sqlite3) printf 'SQLite' ;;
    esac
}

# The complete lines a file gained since byte offset ${!2}, into the variable
# named $3; the offset moves past them. A half-written last line waits for the
# next tick. Bytes, not characters: the ticker runs under LC_ALL=C.
progress_new_lines() {
    # Prefixed locals: the caller's variables are written by name, and a local
    # of the same name here would take the write instead.
    local _pn_file="$1" _pn_offvar="$2" _pn_outvar="$3" _pn_off _pn_size _pn_chunk

    printf -v "$_pn_outvar" '%s' ""
    _pn_off="${!_pn_offvar}"
    _pn_size=$(stat -c %s "$_pn_file" 2>/dev/null) || return 0
    if (( _pn_size < _pn_off )); then
        printf -v "$_pn_offvar" '%s' "$_pn_size"
        return 0
    fi
    (( _pn_size > _pn_off )) || return 0

    _pn_chunk="$(tail -c +"$((_pn_off + 1))" "$_pn_file" 2>/dev/null | head -c "$((_pn_size - _pn_off))"; printf x)"
    _pn_chunk="${_pn_chunk%x}"
    [[ "$_pn_chunk" == *$'\n'* ]] || return 0
    _pn_chunk="${_pn_chunk%$'\n'*}"$'\n'

    printf -v "$_pn_offvar" '%s' "$((_pn_off + ${#_pn_chunk}))"
    printf -v "$_pn_outvar" '%s' "$_pn_chunk"
}

# The ticker's state for one command: what it is doing ($1), its Central
# activity code ($2) and apt's status file ($3, optional).
progress_init() {
    PT_LABEL="$1"
    PT_CODE="${2:-heartbeat}"
    PT_STATUS="${3:-}"
    PT_START=$(now_s)
    PT_LAST_LINE=$PT_START
    PT_LAST_GROUP=0
    PT_STATUS_SEEN=$PT_START
    # Read and moved by name, through progress_new_lines.
    # shellcheck disable=SC2034
    PT_STATUS_OFF=0
    # shellcheck disable=SC2034
    PT_LOG_OFF=$(stat -c %s "$LOG_FILE" 2>/dev/null || echo 0)
    PT_SEEN="|"
    PT_QUEUE=()
    PT_DETAIL=""
    PT_LOCK_SINCE=0
    PT_LOCK_LAST=0
    PT_LOCK_SAID=0
    PT_LOCK_HOLDER=""
    PT_ACT_T=0
    PT_ACT_CODE=""
    if [[ -n "$RUN_DIR" && -r "${RUN_DIR}/activity-last" ]]; then
        read -r PT_ACT_T PT_ACT_CODE <"${RUN_DIR}/activity-last" || true
    fi
}

# One line from the ticker, indented under the command's "→" line.
progress_say() {
    printf '         %s%s%s\n' "$DIM" "$2" "$RESET"
    PT_LAST_LINE=$PT_NOW
    progress_activity "$1" "$2"
}

# The same line to Central, when it asked (PROGRESS_ACTIVITY=1): one when the
# code changes, at most every 15 s, otherwise one a minute. A few dozen per
# install, far inside Central's history and rate limits.
progress_activity() {
    [[ "$PROGRESS_ACTIVITY" == "1" ]] || return 0
    (( PROGRESS_READY )) || return 0

    if [[ "$1" == "$PT_ACT_CODE" ]]; then
        (( PT_NOW - PT_ACT_T >= 60 )) || return 0
    else
        (( PT_NOW - PT_ACT_T >= 15 )) || return 0
    fi

    PT_ACT_T=$PT_NOW
    PT_ACT_CODE="$1"
    [[ -n "$RUN_DIR" ]] && printf '%s %s\n' "$PT_ACT_T" "$PT_ACT_CODE" >"${RUN_DIR}/activity-last"
    ACTIVITY_CODE="$1" ACTIVITY_TEXT="$2" ACTIVITY_ELAPSED=$((PT_NOW - PT_START)) send_progress installing
}

# One look at the command: called every 2 s by the ticker, and directly by the
# tests with the clock set.
progress_tick() {
    local line kind pkg pct msg group chunk lock waiting=0

    PT_NOW=$(now_s)

    # apt's status lines: `pmstatus:<pkg>:<percent>:<text>`, `dlstatus:…`.
    if [[ -n "$PT_STATUS" ]]; then
        progress_new_lines "$PT_STATUS" PT_STATUS_OFF chunk
        while IFS= read -r line; do
            [[ -n "$line" ]] || continue
            PT_STATUS_SEEN=$PT_NOW
            IFS=: read -r kind pkg pct msg <<<"$line"
            # Some apt versions name the architecture: <pkg>:amd64.
            if [[ ! "$pct" =~ ^[0-9.]+$ ]]; then
                IFS=: read -r kind pkg _ pct msg <<<"$line"
            fi
            case "$kind" in
                dlstatus) PT_DETAIL="downloading ${pct%%.*}%" ;;
                pmstatus)
                    PT_DETAIL="installing ${pct%%.*}%"
                    group=$(apt_group "$pkg")
                    if [[ -n "$group" && "$PT_SEEN" != *"|${group}|"* && "$msg" =~ ^(Configuring|Preparing\ to\ configure|Installed) ]]; then
                        PT_SEEN+="${group}|"
                        PT_QUEUE+=("$group")
                    fi
                    ;;
            esac
        done <<<"$chunk"
    fi

    # apt waiting on a lock says so about once a second, in the log:
    #   Waiting for cache lock: Could not get lock /var/lib/dpkg/lock-frontend.
    #   It is held by process 2207 (unattended-upgr)... 12s
    progress_new_lines "$LOG_FILE" PT_LOG_OFF chunk
    if [[ "$chunk" == *"Waiting for cache lock"* ]]; then
        lock="${chunk##*Waiting for cache lock}"
        lock="${lock%%$'\n'*}"
        if [[ "$lock" =~ held\ by\ process\ ([0-9]+)\ \(([^\)]*)\) ]]; then
            PT_LOCK_HOLDER="${BASH_REMATCH[2]}, PID ${BASH_REMATCH[1]}"
        fi
        # apt counts its own wait ("... 12s"); started from that, not from now.
        if (( ! PT_LOCK_SINCE )); then
            PT_LOCK_SINCE=$PT_NOW
            [[ "$lock" =~ \.\.\.\ ([0-9]+)s ]] && PT_LOCK_SINCE=$((PT_NOW - BASH_REMATCH[1]))
        fi
        PT_LOCK_LAST=$PT_NOW
    fi

    if (( PT_LOCK_LAST && PT_NOW - PT_LOCK_LAST <= 5 )); then
        waiting=1
    elif [[ -n "$PT_STATUS" ]] && (( PT_NOW - PT_STATUS_SEEN >= 15 )) \
            && (( PT_LOCK_SINCE || PT_NOW - PT_LAST_LINE >= 15 )) && ubuntu_updates_running; then
        # An apt command with no word from apt for 15 s while Ubuntu's
        # updates run is waiting for them, whether or not it says so (-qq
        # does not). apt commands only: the build runs beside the updates on
        # purpose, and is not waiting for anything.
        waiting=1
        PT_LOCK_HOLDER="unattended-upgr"
        (( PT_LOCK_SINCE )) || PT_LOCK_SINCE=$PT_STATUS_SEEN
    fi

    if (( waiting )); then
        if (( PT_NOW - PT_LAST_LINE >= 15 || ! PT_LOCK_SAID )); then
            PT_LOCK_SAID=1
            progress_say apt_lock_wait "$(apt_wait_text "$PT_LOCK_HOLDER") Waiting… $(fmt_elapsed $((PT_NOW - PT_LOCK_SINCE))) so far"
        fi
        return 0
    fi
    if (( PT_LOCK_SINCE )); then
        PT_LOCK_SINCE=0
        PT_LOCK_LAST=0
        PT_LOCK_SAID=0
        if [[ "$PT_LOCK_HOLDER" == *unattended-upgr* ]]; then
            progress_say "$PT_CODE" "Ubuntu's updates let go of the package lock — carrying on"
        else
            progress_say "$PT_CODE" "the other package manager is done — carrying on"
        fi
        return 0
    fi

    if (( ${#PT_QUEUE[@]} && PT_NOW - PT_LAST_GROUP >= 5 )); then
        PT_LAST_GROUP=$PT_NOW
        progress_say "$PT_CODE" "setting up ${PT_QUEUE[0]} · $(fmt_elapsed $((PT_NOW - PT_START)))"
        PT_QUEUE=("${PT_QUEUE[@]:1}")
        return 0
    fi

    if (( PT_NOW - PT_LAST_LINE >= 15 )); then
        progress_say "$PT_CODE" "${PT_DETAIL:-still ${PT_LABEL}} · $(fmt_elapsed $((PT_NOW - PT_START)))"
    fi
}

# progress_start LABEL [ACTIVITY_CODE] [APT_STATUS_FILE]
progress_start() {
    (( DRY_RUN )) && return 0
    progress_stop

    (
        trap - ERR
        set +eu
        export LC_ALL=C
        trap 'kill "$PT_SLEEP" 2>/dev/null; exit 0' TERM
        progress_init "$@"
        while :; do
            sleep 2 &
            PT_SLEEP=$!
            wait "$PT_SLEEP"
            progress_tick
        done
    ) &
    PROGRESS_PID=$!
}

progress_stop() {
    [[ -n "${PROGRESS_PID:-}" ]] || return 0
    kill "$PROGRESS_PID" 2>/dev/null || true
    wait "$PROGRESS_PID" 2>/dev/null || true
    PROGRESS_PID=""
}

# run_progress for apt: the same "→ … ✓ (Ns)" lines, with the ticker reading
# apt's status lines in between. They go to a regular file, not a pipe: a file
# can neither block apt nor kill it with SIGPIPE if the reader dies.
run_apt() {
    local label="$1"
    shift

    if (( DRY_RUN )) || [[ -z "$RUN_DIR" ]]; then
        local started=$SECONDS
        say "     → ${label}..."
        run "$@"
        ok "${label} ($((SECONDS - started))s)"
        return 0
    fi

    if ! try_apt "$label" "$@"; then
        report_failure "This command failed. Its output is in the log." "\$ $*"
        exit 1
    fi
}

# run_apt that hands a failure back rather than stopping the install, for a
# caller with a fallback (install_packages's optional packages).
try_apt() {
    local label="$1"
    shift
    local started=$SECONDS status rc=0

    say "     → ${label}..."
    status="${RUN_DIR}/apt-status"
    : >"$status"
    progress_start "${label,}" apt_install "$status"
    "$@" -o APT::Status-Fd=3 3>>"$status" >>"$LOG_FILE" 2>&1 || rc=$?
    progress_stop
    if (( rc == 0 )); then
        ok "${label} ($((SECONDS - started))s)"
    fi
    return "$rc"
}

# The EXIT trap: no ticker outlives the install, the run directory goes, and
# needrestart gets this unit back. finish() released that only on success, so
# a failed install left needrestart skipping this unit's name until the next
# run that succeeded (review finding R13; seen live after a failed install).
# release_needrestart is safe to run twice.
on_exit() {
    progress_stop
    release_needrestart
    [[ -n "$RUN_DIR" ]] && rm -rf "$RUN_DIR"
    return 0
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
        --force)    FORCE=1 ;;
        --central-token=*) CENTRAL_TOKEN="${arg#*=}" ;;
        --admin-username=*) PANEL_ADMIN_USERNAME="${arg#*=}" ;;
        --progress-url=*) PROGRESS_URL="${arg#*=}" ;;
        -h|--help)
            cat <<'USAGE'
Control panel installer

  sudo bash install.sh [options]

  --stack=lemp                 Which stack to build:
                                 lemp    nginx + PHP        (default)
                                 lamp    Apache + PHP
                                 mern    nginx + Node
                                 ols     OpenLiteSpeed + PHP
                                 docker  nginx + Docker
                               Asked interactively when not given and a terminal
                               is available. Under `curl | bash`, which has no
                               terminal to ask on, the default (lemp) is used.
  --domain=panel.example.com   Use your own domain instead of a nip.io name.
                               Point its A record at this server first.
  --email=you@example.com      Address for Let's Encrypt expiry warnings.
  --branch=main                Branch to install from.
  --repo=URL                   Git repository to install from (a fork or mirror).
  --no-ssl                     Serve plain HTTP. Fine behind another proxy.
  --dry-run                    Run the checks and print the plan, then stop.
                               Nothing on the server is changed.
  --force                      Install on a server that isn't fresh. Without it
                               the install stops, changing nothing, when the
                               server already hosts something: another control
                               panel, sites, databases, Redis keys, PHP-FPM
                               pools, Docker containers or certificates. With
                               it, each of those is printed as a warning and
                               the install goes ahead beside them.
  --central-token=TOKEN        Register this server with a central panel that
                               already holds the same token. Optional; nothing
                               else needs it.
                               Prefer the CENTRAL_TOKEN environment variable
                               for automation: an argument is readable in `ps`
                               by every account on this machine for as long as
                               the install runs, and an environment variable is
                               not.
  --admin-username=NAME        Create the first administrator during the install,
                               so the panel is never open for registration. The
                               password comes from PANEL_ADMIN_PASSWORD in the
                               environment only (12-128 characters).
  --progress-url=URL           Report each step to this https URL, signed with
                               CENTRAL_TOKEN (Bearer). Also PROGRESS_URL.
USAGE
            exit 0 ;;
        # A usage mistake, not a failed install: no log to read, nothing to
        # report (FS-A11). It used to print the "read the full log / open an
        # issue" block over a typo.
        *) printf 'Unknown option: %s\nRun with --help to see the options.\n' "$arg" >&2
           exit 2 ;;
    esac
done

# ─── Preflight ───────────────────────────────────────────────────────────────
#
# Everything that could make this install fail badly is checked here, before a
# single package is installed. The alternative — discovering on step 11 that
# port 80 is taken — leaves the user with a server that is neither what it was
# nor what they wanted.

# Central's inputs, checked before the first step reports anywhere and before
# anything on the server changes. A bad one is a mistake in the caller, not a
# half-built server.
validate_central_inputs() {
    if [[ -n "$PANEL_ADMIN_USERNAME" || -n "$PANEL_ADMIN_PASSWORD" ]]; then
        [[ -n "$PANEL_ADMIN_USERNAME" && -n "$PANEL_ADMIN_PASSWORD" ]] \
            || die "set both PANEL_ADMIN_USERNAME and PANEL_ADMIN_PASSWORD, or neither."
        [[ "$PANEL_ADMIN_USERNAME" =~ ^[A-Za-z0-9_-]{3,32}$ ]] \
            || die "the administrator username must be 3-32 letters, digits, '-' or '_'."
        (( ${#PANEL_ADMIN_PASSWORD} >= 12 && ${#PANEL_ADMIN_PASSWORD} <= 128 )) \
            || die "the administrator password must be 12-128 characters."
        [[ ! "$PANEL_ADMIN_PASSWORD" =~ [[:cntrl:]] ]] \
            || die "the administrator password cannot contain line breaks or control characters."
    fi

    if [[ -n "$CENTRAL_URL" ]]; then
        [[ "$CENTRAL_URL" =~ ^https://[A-Za-z0-9.-]+(:[0-9]+)?/?$ ]] \
            || die "CENTRAL_URL must be an https:// address with no path, e.g. https://central.example.com"
    fi

    if [[ -n "$PROGRESS_URL" ]]; then
        # https only — the request carries the central token. Plain http to this
        # machine itself is allowed, for testing.
        [[ "$PROGRESS_URL" =~ ^https://[^[:space:]]+$ || "$PROGRESS_URL" =~ ^http://127\.0\.0\.1(:[0-9]+)?/[^[:space:]]*$ ]] \
            || die "PROGRESS_URL must be an https:// address."
        [[ -n "$CENTRAL_TOKEN" ]] \
            || die "PROGRESS_URL needs CENTRAL_TOKEN, which signs every report."
        PROGRESS_READY=1
    fi
}

# Everything the operator (or Central) hands the installer, checked before it
# is used anywhere. These go into vhosts, unit files, sed expressions, paths
# that are later deleted, and git's command line; each used to be taken as
# given. The realistic failure is a typo -- a pasted https://panel.example.com/
# as --domain made a broken vhost and a certbot failure ten minutes later --
# but a `;` or `}` in --domain also rewrote the nginx config around it.
validate_inputs() {
    # Before anything is changed, so it says so -- which also keeps
    # report_failure from showing an earlier install's log as if it were
    # this run's.
    input_refused() {
        die "$1
     Nothing has been changed on this server."
    }

    if [[ -n "$DOMAIN" ]]; then
        DOMAIN="${DOMAIN,,}"
        # Labels of letters, digits and inner hyphens; two or more of them;
        # the last starts with a letter (so not an IP, and punycode TLDs pass).
        [[ ${#DOMAIN} -le 253 && "$DOMAIN" =~ ^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]([a-z0-9-]{0,61}[a-z0-9])?$ ]] \
            || input_refused "--domain must be a host name like panel.example.com -- no https://, no path, no port. Got: ${DOMAIN}"
    fi

    if [[ -n "$ADMIN_EMAIL" ]]; then
        [[ "$ADMIN_EMAIL" =~ ^[A-Za-z0-9._%+][A-Za-z0-9._%+-]*@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$ ]] \
            || input_refused "--email must be an email address like you@example.com. Got: ${ADMIN_EMAIL}"
    fi

    # What `git check-ref-format` allows, narrowed: no leading '-' (it would
    # be an option), no '..', no spaces or shell characters.
    [[ "$REPO_BRANCH" =~ ^[A-Za-z0-9._][A-Za-z0-9._/-]*$ && "$REPO_BRANCH" != *..* && "$REPO_BRANCH" != */ ]] \
        || input_refused "--branch must be a branch name like main or release/v8. Got: ${REPO_BRANCH}"

    [[ "$REPO_URL" =~ ^(https?|ssh|git|file)://[^[:space:]]+$ \
        || "$REPO_URL" =~ ^[A-Za-z0-9._-]+@[A-Za-z0-9.-]+:[^[:space:]]+$ \
        || "$REPO_URL" =~ ^/[^[:space:]]+$ ]] \
        || input_refused "--repo must be a git URL (https://, ssh://, user@host:path) or an absolute path. Got: ${REPO_URL}"

    # A size in MB for fallocate (configure_swap). 0 means leave swap alone.
    if [[ -n "${PANEL_SWAP_MB:-}" ]]; then
        [[ "$PANEL_SWAP_MB" =~ ^(0|[1-9][0-9]{0,5})$ ]] \
            || input_refused "PANEL_SWAP_MB must be a whole number of MB, like 2048 (0 leaves swap alone). Got: ${PANEL_SWAP_MB}"
    fi

    # The slug names units, vhosts, files under /etc and the login script.
    [[ "$PANEL_SLUG" =~ ^[a-z][a-z0-9-]{0,31}$ ]] \
        || input_refused "PANEL_SLUG must be lower-case letters, digits and '-', starting with a letter (at most 32). Got: ${PANEL_SLUG}"
    [[ "$APP_USER" =~ ^[a-z][a-z0-9_-]{0,31}$ ]] \
        || input_refused "APP_USER must be a Linux user name: lower-case letters, digits, '-' and '_' (at most 32). Got: ${APP_USER}"
    # At least two levels deep: a run that finds something other than a
    # checkout here moves it aside (fetch_source), and /var is not a site.
    [[ "$APP_DIR" =~ ^(/[A-Za-z0-9._-]+){2,}$ && "/${APP_DIR}/" != */../* && "/${APP_DIR}/" != */./* ]] \
        || input_refused "APP_DIR must be an absolute path at least two levels deep, like /var/www/panel, with no spaces or '..'. Got: ${APP_DIR}"
}

preflight() {
    validate_inputs
    validate_central_inputs
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

    fresh_server_check

    # Ports, before any web server is installed. `ss` ships with iproute2 on
    # both supported releases. A web server that is already ours is not a
    # conflict — this is the re-run case.
    # A web server on 80/443 is not automatically a conflict. Three cases:
    #
    #   ours          — a re-run. Always fine.
    #   a supported   — an existing nginx/Apache, which is what a server being
    #   web server      migrated from another panel looks like. The panel adds
    #                   one vhost beside whatever is already there; it never
    #                   rewrites global config, and `nginx -t` gates the reload.
    #                   Refusing here forced the operator to stop their web
    #                   server for the whole install, taking every hosted site
    #                   offline to install a panel that was going to share that
    #                   web server anyway.
    #                   Since 2026-10-18 such a server only gets this far with
    #                   --force: a web server serving sites is one of the things
    #                   fresh_server_check stops on. So taking a server over from
    #                   another panel — the planned v7 → v8 in-place migration
    #                   included — needs --force, which Central's migration flow
    #                   passes. The sharing below is what those installs use;
    #                   keep it.
    #   anything else — a real conflict, and still fatal.
    local port
    for port in 80 443; do
        ss -ltnH "sport = :${port}" 2>/dev/null | grep -q . || continue

        if [[ -f /etc/nginx/sites-enabled/${PANEL_SLUG}.conf ]] \
           || [[ -f /etc/apache2/sites-enabled/${PANEL_SLUG}.conf ]]; then
            skip "port ${port} is in use by our own web server"
            continue
        fi

        local holder
        holder=$(ss -ltnpH "sport = :${port}" 2>/dev/null | grep -oP 'users:\(\("\K[^"]+' | head -1)

        case "$holder" in
            nginx|apache2|httpd|litespeed|lshttpd)
                skip "port ${port} is served by ${holder}, which the panel will share"
                ;;
            *)
                die "port ${port} is already in use by ${holder:-something else}.
     The panel needs 80 and 443, or a web server it can share them with.
     Stop that service and run this again:
       ss -ltnp 'sport = :${port}'"
                ;;
        esac
    done

    # Memory, swap and disk are checked in check_requirements(), once the stack
    # is known: a Docker server needs more disk than the others.

    command -v systemctl >/dev/null 2>&1 || die "systemd is required"

    # A dry run writes nothing at all (FS-A1) — not even the log, which a
    # real install truncates here.
    if (( DRY_RUN )); then
        LOG_FILE=/dev/null
        return 0
    fi

    mkdir -p "$(dirname "$LOG_FILE")"
    : >"$LOG_FILE"
    chmod 600 "$LOG_FILE"
    ok "logging to $LOG_FILE"

    # The check ran before the log existed; this is its record.
    {
        printf 'FRESH_CHECK=%s\n' "$FRESH_CHECK"
        local note
        for note in "${FRESH_NOTES[@]}"; do
            printf 'fresh-server check: %s\n' "$note"
        done
    } >>"$LOG_FILE"
}

# The plan a dry run prints, then stops (FS-A1). It used to carry on through
# every step with `run` silenced — and the many writes that do not go through
# `run` (vhosts, systemd units, the pool, cron, sudoers, both .env files) were
# made anyway, on a run that promised to change nothing.
dry_run_plan() {
    if [[ "$FRESH_CHECK" == "not_fresh" ]]; then
        step "Dry run — this server isn't fresh; nothing was changed"
        printf '     A real run would stop at step 1, because this server already hosts:\n'
        local finding
        for finding in "${FRESH_FINDINGS[@]}"; do
            printf '       - %s\n' "$finding"
        done
        printf '     Use a fresh Ubuntu server, or add --force to install here anyway.\n'
        printf '     With --force, a real run would, in order:\n'
    else
        step "Dry run — the checks passed; nothing was changed"
        printf '     A real run would, in order:\n'
    fi
    printf '       - make sure there is swap for the build\n'
    printf '       - create the %s account\n' "$APP_USER"
    printf '       - install every package for the %s stack (%s) in one go\n' "$STACK" "$WEB_SERVER"
    printf '       - install Node\n'
    printf '       - fetch the panel into %s\n' "$APP_DIR"
    printf '       - configure Redis, mail, PHP and %s\n' "$WEB_SERVER"
    printf '       - get a certificate for %s\n' "$PANEL_HOST"
    printf '       - set up the backend, build the frontend, start the services\n'
    printf '       - write the sudo grant and open ports 22, 80 and 443\n\n'
    printf '     Run again without --dry-run to install.\n\n'
}

# ─── Fresh-server check ──────────────────────────────────────────────────────
#
# The installer is for a server with nothing on it yet. On 2026-10-18 it was
# run by mistake on a busy server — 26 sites, MariaDB, Redis, PHP-FPM pools —
# and accepted it, because preflight looked at the OS, the ports and the disk
# only. It then upgraded packages, installed Docker and Postfix, appended to
# that server's redis.conf and restarted Redis, and added a global conf to its
# PHP-FPM before failing at step 14.
#
# So preflight stops on strong evidence that a server already hosts something,
# and only on that: every provider's stock Ubuntu image must pass. Each check
# below looks for one thing a fresh image never has. What images do ship —
# snapd, cloud-init, guest agents, swap, firewall rules, an MTA, an empty
# database server — is printed as harmless, and anything the check cannot read
# for certain is a warning, never a stop. Nothing here writes anything, so a
# stop still truthfully says "Nothing on this server was changed".
#
# A re-run is never checked (the sites may be the panel's own), and --force
# turns every finding into a warning.

fresh_note() {
    FRESH_NOTES+=("$1")
}

fresh_ok() {
    ok "$1"
    fresh_note "ok: $1"
}

fresh_warn() {
    warn "$1"
    fresh_note "warning: $1"
}

fresh_found() {
    FRESH_FINDINGS+=("$1")
    fresh_note "found: $1"
}

# "1 site" / "3 sites".
fresh_count() {
    if (( $1 == 1 )); then
        printf '1 %s' "$2"
    else
        printf '%s %s' "$1" "$3"
    fi
}

# The items joined with $1.
fresh_join() {
    local sep="$1" out="" item
    shift
    for item in "$@"; do
        out+="${out:+$sep}${item}"
    done
    printf '%s' "$out"
}

# At most three names, then how many more: "a, b, c and 9 more".
fresh_names() {
    if (( $# <= 3 )); then
        fresh_join ', ' "$@"
    else
        printf '%s and %s more' "$(fresh_join ', ' "${@:1:3}")" "$(( $# - 3 ))"
    fi
}

# Lines in $1 that are not blank, as a number. awk rather than `grep -c`, which
# fails on no match and would stop the script through the ERR trap.
fresh_lines() {
    printf '%s\n' "$1" | awk 'NF { n++ } END { print n + 0 }'
}

# The entries of a directory, one per line, except those matching any of the
# given patterns. Nothing when the directory does not exist. Dotfiles are left
# out, as nginx's and Apache's `*` includes leave them out.
fresh_entries() {
    local dir="$1" entry name pattern
    shift
    [[ -d "$dir" ]] || return 0
    for entry in "$dir"/*; do
        [[ -e "$entry" || -L "$entry" ]] || continue
        name="${entry##*/}"
        for pattern in "$@"; do
            # shellcheck disable=SC2053 # a pattern, deliberately unquoted
            [[ "$name" == $pattern ]] && continue 2
        done
        printf '%s\n' "$name"
    done
}

# Is this package installed? From dpkg's own file list, so nothing is run.
fresh_has_package() {
    local f
    for f in "${FRESH_ROOT}/var/lib/dpkg/info/$1.list" "${FRESH_ROOT}/var/lib/dpkg/info/$1:"*.list; do
        [[ -e "$f" ]] && return 0
    done
    return 1
}

# This installer has been here before: what says so, or nothing. A re-run, a
# Retry from Central and a re-install on our own panel are never blocked —
# whatever that server hosts, the panel put it there.
fresh_server_rerun() {
    local R="$FRESH_ROOT"

    if [[ -e "${INSTALL_STATE_DIR}/state" ]]; then
        printf 'this installer'\''s state file %s' "${INSTALL_STATE_DIR}/state"
    elif [[ -f "${R}/etc/nginx/sites-enabled/${PANEL_SLUG}.conf" ]]; then
        printf 'the panel'\''s own nginx site'
    elif [[ -f "${R}/etc/apache2/sites-enabled/${PANEL_SLUG}.conf" ]]; then
        printf 'the panel'\''s own Apache site'
    elif grep -q "^virtualHost ${PANEL_SLUG} " "${R}/usr/local/lsws/conf/httpd_config.conf" 2>/dev/null; then
        printf 'the panel'\''s own OpenLiteSpeed site'
    elif [[ -d "${APP_DIR}/.git" && -f "${APP_DIR}/install.sh" && -f "${APP_DIR}/backend/artisan" ]]; then
        # A checkout of this repository, not merely something at the path:
        # `panel` is a generic name, and a directory or an account by that
        # name used to be enough -- the check was skipped on a server that
        # was not ours, and fetch_source then cleared the directory as a
        # leftover clone (review finding R2). The account is no longer asked
        # at all: every install since the state file has one, and an account
        # alone proves nothing.
        printf 'the panel in %s' "$APP_DIR"
    else
        return 1
    fi
}

# Another control panel. Each is known by a directory or binary of its own,
# which no stock image has.
fresh_check_panels() {
    local entry label paths path
    local -a found=()
    local -a panels=(
        "cPanel/WHM|/usr/local/cpanel"
        "Plesk|/usr/local/psa /opt/psa"
        "DirectAdmin|/usr/local/directadmin"
        "CyberPanel|/usr/local/CyberCP"
        "CloudPanel|/home/clp /usr/bin/clpctl"
        "HestiaCP|/usr/local/hestia"
        "VestaCP|/usr/local/vesta"
        "aaPanel|/www/server/panel"
        "Webmin/Virtualmin|/etc/webmin /usr/share/webmin"
        "ISPConfig|/usr/local/ispconfig"
        "RunCloud|/RunCloud /opt/RunCloud"
        "Ploi|/home/ploi"
        "Forge|/home/forge"
        "GridPane|/opt/gridpane /root/gridenv"
        "ServerPilot|/opt/sp /usr/local/bin/sp-agent"
        "SpinupWP|/etc/spinupwp /usr/local/bin/spinupwp"
    )

    for entry in "${panels[@]}"; do
        label="${entry%%|*}"
        paths="${entry#*|}"
        for path in $paths; do
            if [[ -e "${FRESH_ROOT}${path}" ]]; then
                found+=("$label")
                fresh_found "another control panel: ${label} (${path})"
                break
            fi
        done
    done

    (( ${#found[@]} )) || fresh_ok "no other control panel"
}

# The v7 agent, by the signals the panel's own LegacyAgentDetector uses: a
# listener on port 43210, hardcoded in the agent, and the unit that owns it
# (its name is a build-time choice, so it cannot be looked for by name).
fresh_check_legacy_agent() {
    local port=43210 listeners pid unit=""

    listeners="$(ss -ltnH 2>/dev/null)" || listeners=""
    awk '{ print $4 }' <<<"$listeners" | grep -qE "[:.]${port}\$" || return 0

    pid="$(ss -ltnpH "sport = :${port}" 2>/dev/null | grep -oP 'pid=\K[0-9]+' | head -1)" || pid=""
    if [[ -n "$pid" ]]; then
        unit="$(sed -n 's#^0::.*/\([^/]*\.service\)$#\1#p' "${FRESH_ROOT}/proc/${pid}/cgroup" 2>/dev/null | head -1)" || unit=""
    fi

    fresh_found "the v7 agent (port ${port}${unit:+, ${unit}})"
}

# A web server with a site besides the distro's default one. Only installed
# web servers are looked at; a web server serving only its welcome page is
# what an image with nginx or Apache preinstalled looks like.
fresh_check_web_servers() {
    local R="$FRESH_ROOT" f
    local -a sites=()

    if [[ -d "${R}/etc/nginx" ]]; then
        mapfile -t sites < <(fresh_entries "${R}/etc/nginx/sites-enabled" default || true)
        # conf.d holds settings as well as sites: only a file with a server
        # block is a site. default.conf is nginx.org's own welcome page.
        for f in "${R}/etc/nginx/conf.d/"*.conf; do
            [[ -f "$f" && "${f##*/}" != "default.conf" ]] || continue
            grep -qE '^[[:space:]]*server[[:space:]]*\{' "$f" 2>/dev/null && sites+=("${f##*/}")
        done
        if (( ${#sites[@]} )); then
            fresh_found "nginx with $(fresh_count "${#sites[@]}" site sites) ($(fresh_names "${sites[@]}"))"
        else
            fresh_ok "nginx serves only its default page"
        fi
    fi

    if [[ -d "${R}/etc/apache2" ]]; then
        mapfile -t sites < <(fresh_entries "${R}/etc/apache2/sites-enabled" 000-default.conf default-ssl.conf || true)
        if (( ${#sites[@]} )); then
            fresh_found "Apache with $(fresh_count "${#sites[@]}" site sites) ($(fresh_names "${sites[@]}"))"
        else
            fresh_ok "Apache serves only its default page"
        fi
    fi

    if [[ -d "${R}/usr/local/lsws/conf" ]]; then
        # Example is the vhost every OpenLiteSpeed and LiteSpeed install ships.
        mapfile -t sites < <(
            {
                fresh_entries "${R}/usr/local/lsws/conf/vhosts" Example
                sed -n 's/^[[:space:]]*virtual[Hh]ost[[:space:]]\{1,\}\([^[:space:]{]\{1,\}\).*/\1/p' \
                    "${R}/usr/local/lsws/conf/httpd_config.conf" 2>/dev/null
            } | grep -vx 'Example' | sort -u || true
        )
        if (( ${#sites[@]} )); then
            fresh_found "LiteSpeed with $(fresh_count "${#sites[@]}" site sites) ($(fresh_names "${sites[@]}"))"
        else
            fresh_ok "LiteSpeed serves only its Example site"
        fi
    fi

    if [[ -f "${R}/etc/caddy/Caddyfile" ]]; then
        # The top-level blocks, except the global options `{`, snippets
        # `(name) {` and the package's own `:80 {`.
        mapfile -t sites < <(
            sed -n 's/^\([^[:space:]#{(][^{]*\)[[:space:]]*{[[:space:]]*$/\1/p' "${R}/etc/caddy/Caddyfile" 2>/dev/null \
                | sed 's/[[:space:]]*$//' | grep -vxE ':80|http://' || true
        )
        if (( ${#sites[@]} )); then
            fresh_found "Caddy with $(fresh_count "${#sites[@]}" site sites) ($(fresh_names "${sites[@]}"))"
        else
            fresh_ok "Caddy serves only its default page"
        fi
    fi
}

# A database server with a database or user of anyone's. Databases are read
# from the data directory, which needs neither a password nor a running server;
# users need the server, so not being able to list them is a warning.
fresh_check_databases() {
    local R="$FRESH_ROOT" label out
    local -a dbs=() users=() parts=()

    if [[ -e "${R}/usr/sbin/mysqld" || -e "${R}/usr/sbin/mariadbd" ]]; then
        label="MySQL"
        [[ -e "${R}/usr/sbin/mariadbd" ]] && label="MariaDB"

        # One directory per database. '#'-names are InnoDB's own (redo log,
        # temp tablespaces).
        local d
        for d in "${R}/var/lib/mysql/"*/; do
            [[ -d "$d" ]] || continue
            d="${d%/}"; d="${d##*/}"
            case "$d" in
                mysql|performance_schema|sys|'#'*|lost+found) ;;
                *) dbs+=("$d") ;;
            esac
        done

        # The accounts the packages create: root, MariaDB's mysql and
        # mariadb.sys, MySQL's mysql.* and Ubuntu's debian-sys-maint.
        if out="$(timeout 10 mysql --protocol=socket -NBe 'SELECT user FROM mysql.user' 2>/dev/null)"; then
            mapfile -t users < <(
                awk 'NF' <<<"$out" | sort -u \
                    | grep -vxE 'root|mysql|mariadb\.sys|mysql\.sys|mysql\.session|mysql\.infoschema|debian-sys-maint|PUBLIC' || true
            )
        else
            fresh_warn "${label}'s users could not be listed without a password (its databases were checked)"
        fi

        parts=()
        (( ${#dbs[@]} )) && parts+=("$(fresh_count "${#dbs[@]}" database databases) ($(fresh_names "${dbs[@]}"))")
        (( ${#users[@]} )) && parts+=("$(fresh_count "${#users[@]}" user users) ($(fresh_names "${users[@]}"))")
        if (( ${#parts[@]} )); then
            fresh_found "${label} with $(fresh_join ' and ' "${parts[@]}")"
        else
            fresh_ok "${label} has only its own system databases"
        fi
    fi

    if [[ -d "${R}/usr/lib/postgresql" ]]; then
        # A new cluster has exactly three databases (template0, template1,
        # postgres), each a numbered directory under base/.
        local base n extra=0
        for base in "${R}/var/lib/postgresql/"*/*/base; do
            [[ -d "$base" ]] || continue
            n="$(fresh_entries "$base" | grep -cxE '[0-9]+' || true)"
            (( n > 3 )) && extra=$(( extra + n - 3 ))
        done

        users=()
        if out="$(cd / && timeout 10 runuser -u postgres -- psql -XAtqc "SELECT rolname FROM pg_roles WHERE rolname <> 'postgres' AND rolname !~ '^pg_'" 2>/dev/null)"; then
            mapfile -t users < <(awk 'NF' <<<"$out" || true)
        else
            fresh_warn "PostgreSQL's users could not be listed (its databases were checked)"
        fi

        parts=()
        (( extra )) && parts+=("$(fresh_count "$extra" database databases)")
        (( ${#users[@]} )) && parts+=("$(fresh_count "${#users[@]}" user users) ($(fresh_names "${users[@]}"))")
        if (( ${#parts[@]} )); then
            fresh_found "PostgreSQL with $(fresh_join ' and ' "${parts[@]}")"
        else
            fresh_ok "PostgreSQL has only its own system databases"
        fi
    fi

    if [[ -e "${R}/usr/bin/mongod" ]]; then
        # admin, config and local are MongoDB's own. mongosh writes a log of
        # every session under $HOME, so it gets one that does not exist: this
        # check writes nothing, and a mongosh that refuses is only a warning.
        local count_users=0
        if out="$(HOME=/nonexistent timeout 15 mongosh --quiet --norc --eval 'db.adminCommand({listDatabases: 1, nameOnly: true}).databases.forEach(d => print("db " + d.name)); print("users " + db.getSiblingDB("admin").getCollection("system.users").countDocuments({}))' 2>/dev/null)"; then
            mapfile -t dbs < <(sed -n 's/^db //p' <<<"$out" | grep -vxE 'admin|config|local' || true)
            count_users="$(sed -n 's/^users \([0-9]\{1,\}\)$/\1/p' <<<"$out" | head -1)"
            count_users="${count_users:-0}"

            parts=()
            (( ${#dbs[@]} )) && parts+=("$(fresh_count "${#dbs[@]}" database databases) ($(fresh_names "${dbs[@]}"))")
            (( count_users )) && parts+=("$(fresh_count "$count_users" user users)")
            if (( ${#parts[@]} )); then
                fresh_found "MongoDB with $(fresh_join ' and ' "${parts[@]}")"
            else
                fresh_ok "MongoDB has only its own system databases"
            fi
        else
            fresh_warn "MongoDB is installed, but its databases could not be listed"
        fi
    fi
}

# Redis with any key. A Redis that wants a password, or is not running, cannot
# be counted, so that is a warning.
fresh_check_redis() {
    local out keys

    [[ -e "${FRESH_ROOT}/usr/bin/redis-server" ]] || return 0

    if out="$(timeout 5 redis-cli --no-auth-warning INFO keyspace 2>/dev/null)" && [[ "$out" != *NOAUTH* ]]; then
        keys="$(sed -n 's/^db[0-9]\{1,\}:keys=\([0-9]\{1,\}\).*/\1/p' <<<"$out" | awk '{ n += $1 } END { print n + 0 }')"
        if (( keys )); then
            fresh_found "Redis with $(fresh_count "$keys" key keys)"
        else
            fresh_ok "Redis is empty"
        fi
    else
        fresh_warn "Redis is installed, but its keys could not be counted (not running, or it needs a password)"
    fi
}

# PHP-FPM with a pool besides the package's `www`. Read as the section names
# in pool.d, since one file can hold several pools and [global] is not one.
fresh_check_fpm() {
    local f
    local -a pools=()

    for f in "${FRESH_ROOT}/etc/php/"*/fpm/pool.d/*.conf; do
        [[ -f "$f" ]] || continue
        mapfile -t -O "${#pools[@]}" pools < <(sed -n 's/^[[:space:]]*\[\([^]]*\)\][[:space:]]*$/\1/p' "$f" || true)
    done
    (( ${#pools[@]} )) || return 0

    mapfile -t pools < <(printf '%s\n' "${pools[@]}" | grep -vxE 'www|global' | sort -u || true)
    if (( ${#pools[@]} )); then
        fresh_found "PHP-FPM with $(fresh_count "${#pools[@]}" pool pools) ($(fresh_names "${pools[@]}"))"
    else
        fresh_ok "PHP-FPM has only its default www pool"
    fi
}

# Docker with any container, running or stopped, or any image. Asked of the
# daemon only when it is already running: `docker ps` would start a stopped
# daemon through its socket, and this check changes nothing.
fresh_check_docker() {
    local R="$FRESH_ROOT" containers images out
    local -a parts=()

    [[ -e "${R}/usr/bin/dockerd" ]] || return 0

    if systemctl is-active --quiet docker 2>/dev/null; then
        if out="$(timeout 20 docker ps -aq 2>/dev/null)"; then
            containers="$(fresh_lines "$out")"
        else
            fresh_warn "Docker is running, but its containers could not be listed"
            return 0
        fi
        out="$(timeout 20 docker images -aq 2>/dev/null)" || out=""
        images="$(fresh_lines "$out")"
    else
        containers="$(fresh_entries "${R}/var/lib/docker/containers" | awk 'NF { n++ } END { print n + 0 }')"
        images=0
    fi

    (( containers )) && parts+=("$(fresh_count "$containers" container containers)")
    (( images )) && parts+=("$(fresh_count "$images" image images)")
    if (( ${#parts[@]} )); then
        fresh_found "Docker with $(fresh_join ' and ' "${parts[@]}")"
    else
        fresh_ok "Docker Engine has no containers or images"
    fi
}

# Site files and certificates: anything in /var/www besides the web server's
# own index page, a public_html in any home, any Let's Encrypt certificate.
fresh_check_site_data() {
    local R="$FRESH_ROOT"
    local -a www=() homes=() certs=()

    mapfile -t www < <(
        {
            fresh_entries "${R}/var/www" html
            fresh_entries "${R}/var/www/html" 'index*.html' | sed 's#^#html/#'
        } || true
    )
    if (( ${#www[@]} )); then
        fresh_found "site files in /var/www ($(fresh_names "${www[@]}"))"
    else
        fresh_ok "no site files in /var/www"
    fi

    local d
    for d in "${R}/home/"*/public_html; do
        [[ -e "$d" ]] || continue
        d="${d%/public_html}"
        homes+=("${d##*/}")
    done
    (( ${#homes[@]} )) && fresh_found "public_html in $(fresh_count "${#homes[@]}" home homes) ($(fresh_names "${homes[@]}"))"

    for d in "${R}/etc/letsencrypt/live/"*/; do
        [[ -d "$d" ]] || continue
        d="${d%/}"
        certs+=("${d##*/}")
    done
    (( ${#certs[@]} )) && fresh_found "$(fresh_count "${#certs[@]}" "Let's Encrypt certificate" "Let's Encrypt certificates") ($(fresh_names "${certs[@]}"))"

    (( ${#homes[@]} + ${#certs[@]} )) || fresh_ok "no public_html and no certificates"
}

# What stock images ship, listed so the log shows it was seen and let through.
fresh_list_harmless() {
    local R="$FRESH_ROOT" pkg sudoers
    local -a pkgs=(snapd cloud-init unattended-upgrades qemu-guest-agent open-vm-tools droplet-agent do-agent
        hc-utils amazon-ssm-agent google-guest-agent walinuxagent ufw fail2ban postfix exim4-daemon-light)

    for pkg in "${pkgs[@]}"; do
        fresh_has_package "$pkg" && fresh_ok "${pkg} is installed (harmless)"
    done
    [[ -d "${R}/snap/amazon-ssm-agent" ]] && fresh_ok "amazon-ssm-agent is installed (harmless)"
    if [[ -r "${R}/proc/swaps" ]] && (( $(awk 'NR > 1' "${R}/proc/swaps" | awk 'NF { n++ } END { print n + 0 }') )); then
        fresh_ok "swap is on (harmless)"
    fi
    sudoers="$(sed -n 's/^sudo:[^:]*:[^:]*://p' "${R}/etc/group" 2>/dev/null | head -1)" || sudoers=""
    [[ -n "$sudoers" ]] && fresh_ok "sudo users: ${sudoers//,/, } (harmless)"
    for pkg in python3 node php; do
        [[ -e "${R}/usr/bin/${pkg}" ]] && fresh_ok "${pkg} is installed (harmless without sites)"
    done
    return 0
}

# Is this server fresh? Stops the install, through die, when it is not — after
# the OS checks and before anything on the server changes. See the note above.
fresh_server_check() {
    local rerun finding

    if rerun="$(fresh_server_rerun)"; then
        FRESH_CHECK="rerun"
        ok "re-run: fresh-server check skipped (${rerun})"
        fresh_note "re-run: fresh-server check skipped (${rerun})"
        return 0
    fi

    fresh_check_panels
    fresh_check_legacy_agent
    fresh_check_web_servers
    fresh_check_databases
    fresh_check_redis
    fresh_check_fpm
    fresh_check_docker
    fresh_check_site_data
    fresh_list_harmless

    if (( ${#FRESH_FINDINGS[@]} == 0 )); then
        FRESH_CHECK="passed"
        fresh_ok "this server is fresh"
        return 0
    fi

    if (( FORCE || DRY_RUN )); then
        for finding in "${FRESH_FINDINGS[@]}"; do
            fresh_warn "not fresh: ${finding}"
        done
        if (( FORCE )); then
            FRESH_CHECK="forced"
            fresh_warn "--force: installing anyway, beside what is already here (FRESH_CHECK=forced)"
        else
            FRESH_CHECK="not_fresh"
            fresh_warn "a real run stops here; add --force to install anyway"
        fi
        return 0
    fi

    FAIL_CODE="not_fresh"
    FAIL_FINDINGS=("${FRESH_FINDINGS[@]}")
    die "This server isn't fresh, so the install stopped. Found: $(fresh_join '; ' "${FRESH_FINDINGS[@]}"). Nothing on this server was changed. Use a fresh Ubuntu server, or run again with --force to install here anyway."
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
            printf '       3) mern   nginx + Node\n'
            printf '       4) ols    OpenLiteSpeed + PHP\n'
            printf '       5) docker nginx + Docker\n\n'
            printf '     Choice [1]: '

            local answer=""
            read -r answer < /dev/tty || answer=""
            printf '\n'

            case "${answer:-1}" in
                1|lemp|'') STACK="lemp" ;;
                2|lamp)    STACK="lamp" ;;
                3|mern)    STACK="mern" ;;
                4|ols)     STACK="ols" ;;
                5|docker)  STACK="docker" ;;
                *) die "not one of the options: ${answer}" ;;
            esac
        else
            STACK="lemp"
            say "     no terminal to ask on — defaulting to lemp (use --stack= to choose)"
        fi
    fi

    case "$STACK" in
        # docker is nginx too, and deliberately so: containers are reached
        # through a reverse proxy on the allocated port, which is the same
        # vhost a Node app already gets. The stack name is a product label over
        # a runtime flag, not a fourth web server -- there is nothing a Docker
        # web-server driver would do differently.
        lemp|mern|docker) WEB_SERVER="nginx" ;;
        lamp)      WEB_SERVER="apache" ;;
        ols)
            WEB_SERVER="openlitespeed"
            # Said once, plainly, to whoever is watching the install rather than
            # only to whoever reads the source. Not a warning about the stack
            # any more — a capability difference they would otherwise find out
            # about from a control that refuses to switch on.
            say "     note: there is no per-site PHP isolation on OpenLiteSpeed"
            say "     (no per-site pools). The 8G firewall and bot blocker work."
            ;;
        *) die "unknown stack: ${STACK}  (expected lemp, lamp, mern, ols or docker)" ;;
    esac

    derive_php_runtime

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

# The source address of this server's route to the internet, or nothing when
# there is no route. `ip route get` exits 2 on "Network is unreachable", and
# under pipefail that failure used to fire the ERR trap before the caller's own
# "could not determine this server's IP" could explain it, so a server with no
# network got a raw trap report (twice) instead.
local_route_ip() {
    { ip -4 route get 1.1.1.1 2>/dev/null || true; } \
        | awk '!f{for(i=1;i<=NF;i++) if($i=="src"){print $(i+1); f=1}}'
}

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
    local ip="" url
    for url in https://api.ipify.org https://ifconfig.me/ip https://icanhazip.com; do
        ip=$(curl -fsS --max-time 5 "$url" 2>/dev/null | tr -d '[:space:]') || ip=""
        [[ "$ip" =~ ^[0-9]{1,3}(\.[0-9]{1,3}){3}$ ]] && break
        ip=""
    done

    if [[ -z "$ip" ]]; then
        ip=$(local_route_ip)
        [[ -n "$ip" ]] || die "could not determine this server's IP address: no IP lookup service answered and there is no route to the internet.
     This server needs a working internet connection to install (packages, Node, certificates).
     Check its network (try: curl -I https://api.ipify.org), then run this again, or re-run with --domain=your.hostname to skip the lookup."
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

# The memory the install needs while the frontend build runs: RAM and swap
# together, measured rather than assumed. Re-measured 2026-11-01 on fresh
# TeamCompute boxes (Ubuntu 24.04, main b7404f244), because the 3072 MB this
# used to be had gone stale as the frontend grew:
#
#   - a clean `next build` alone peaks at 3059 MB RAM + 257 MB swap (2 vCPU,
#     4 GB box), and the panel's own services already use ~600 MB by then;
#   - 2 GB RAM + 1.1 GB swap (the old 3072 budget): the kernel OOM-killed
#     next-build at 1.6 GB anon-rss -- the bare "Killed" users reported;
#   - 1 GB RAM + 2.1 GB swap: swap filled, the box stopped answering SSH;
#   - 2 GB RAM + 2.6 GB swap (this budget): built in 299 s on 1 vCPU, peaking
#     at 1634 MB RAM + 1506 MB swap for the build, with 437 MB already in use.
#
# 4608 is the 3.3 GB build plus the 0.6 GB already running, plus margin.
#
# Kept in step with panel_update.preflight.min_free_memory_mb, which the
# in-place updater reports against free memory + swap: that check sees the
# services already running, so it asks for the build alone (3584).
BUILD_MEMORY_MB=4608

# Every box gets swap, including ones with RAM to spare. The build is not the
# only reason to have it: a swapless server has no margin at all, so the first
# unexpected spike -- a backup, a restore, a customer's own build, a burst of
# php-fpm children -- is answered by the OOM killer instead of by paging, and
# what it picks is not necessarily the process that grew. A gigabyte of disk
# is a cheap way to turn a hard kill into a slow minute.
MINIMUM_SWAP_MB=1024

# How far below target the existing swapfile may sit before it is rebuilt.
# Exists because mkswap's one-page header makes every file measure 1 MB short
# of the size it was created at, so an exact comparison is never satisfied and
# the installer rebuilds its swapfile on every single run. 64 MB is far above
# that 1 MB and far below any increment worth acting on.
SWAP_TOLERANCE_MB=64

# The swapfile the installer manages. A second file rather than resizing the
# first: /swapfile may not be ours, and swapoff-ing a file the box is actively
# paging into is a far worse failure than using a little more disk.
#
# ⚠️ This name is also the panel's `server.swap_file`, and the two must stay
# equal: the Memory screen answers "is swap on?" by looking for that exact path
# in the kernel's swap list. While they disagreed, this gigabyte was live and
# the screen said off. A test asserts the two match, so changing it here alone
# fails the suite rather than the server.
PANEL_SWAPFILE=/swapfile-panel

# How much of the swap is the file we manage. Read from /proc/swaps rather than
# `swapon --show`, which is in /sbin and not always on PATH. A file listed as
# "(deleted)" does not match, which is correct: it is gone and is not coming
# back.
req_our_swap_mb() {
    local ours
    ours=$(awk -v f="$PANEL_SWAPFILE" '$1 == f {printf "%d", $3/1024}' /proc/swaps 2>/dev/null)
    printf '%s\n' "${ours:-0}"
}

# The size configure_swap() will (re)create the panel's swapfile at, or 0 when
# it leaves swap alone. One function for the check and the action, so the
# check cannot promise one thing while configure_swap() does another.
#
# Two independent requirements, and the box has to satisfy both:
#
#   1. RAM + swap must clear BUILD_MEMORY_MB, or `next build` is killed with a
#      bare "Killed". On a 2 GB box this is the binding one.
#   2. There must be at least MINIMUM_SWAP_MB of swap regardless, so that even
#      a large server has somewhere to page rather than reaching for the OOM
#      killer at the first spike.
#
# Sized against the swap that is NOT ours, because our own file is deleted and
# recreated. Counting it would size the replacement as though it still
# existed: a box wanting 2048 MB, already holding our 1024 MB file, computed
# 1025 MB -- then removed the 1024 and created 1025, landing at half the target.
#
# mkswap spends one page on its header, so a file of N MB reports as N-1.
# Without SWAP_TOLERANCE_MB every re-run saw a 1 MB shortfall and "fixed" it,
# and since the fix is swapoff/delete/recreate, re-running the installer
# REPLACED a gigabyte of swap with a 1 MB file ("size = 1020 KiB", observed).
swap_to_add_mb() {
    local ram_mb="$1" swap_mb="$2" ours_mb="$3" wanted_mb add_mb
    wanted_mb=$(( BUILD_MEMORY_MB - ram_mb ))
    (( wanted_mb < MINIMUM_SWAP_MB )) && wanted_mb=$MINIMUM_SWAP_MB
    add_mb=$(( wanted_mb - (swap_mb - ours_mb) ))
    if (( add_mb <= 0 )) || (( ours_mb > 0 && ours_mb + SWAP_TOLERANCE_MB >= add_mb )); then
        add_mb=0
    fi
    printf '%s\n' "$add_mb"
}

# ─── Requirements ────────────────────────────────────────────────────────────
#
# The numbers the README's Requirements table quotes. A test reads both and
# fails when they disagree, so change them here and there together.
#
# MIN_RAM_MB: below this the install is refused up front. A 1 GB box cannot
# build the panel even with swap (it thrashed until SSH stopped answering, see
# BUILD_MEMORY_MB); 2 GB builds with the swap configure_swap() adds. A "2 GB"
# server reports less than 2048 MB to the OS (1967 on TeamCompute), hence the
# tolerance: the check refuses only when RAM + RAM_TOLERANCE_MB is still short.
MIN_RAM_MB=2048
RECOMMENDED_RAM_MB=4096
RAM_TOLERANCE_MB=256

# Free space on /var, besides the swap. Measured 2026-11-01 on a fresh Ubuntu
# 24.04 image (1.6 GB used before): a lemp install writes 3.4 GB on top of its
# swapfile -- the panel in /var/www 1.8 GB (code, vendor, node_modules, the
# build), packages and apt's lists 0.8 GB, Node 0.2 GB, npm's cache 0.2 GB. The
# 3 GB this check used to ask for was less than the install itself, so 5 GB:
# the install plus room for the logs, the database and a first site.
MIN_DISK_MB=5120
# A Docker server stores every application's images on this disk as well.
# Measured 2026-11-01 (fresh Ubuntu 24.04, Docker 29): a docker install writes
# 3.8 GB besides its swapfile, and pulling two 1-Click Recipes' images took
# another 9.2 GB -- Open WebUI 0.9 GB, Dify 8.3 GB (its API image alone is
# 4.1 GB). 20 GB is the install plus room for one large recipe and a few
# small ones.
DOCKER_MIN_DISK_MB=20480

# Every failed requirement, collected so the refusal names them all at once
# instead of one per run.
REQUIREMENT_FAILURES=()
# Set when this server cannot add swap at all (a container). configure_swap()
# then skips its optional minimum instead of failing half-way through.
SWAP_UNAVAILABLE=0

# The facts, one function each, so the tests can describe a small server
# without being one.
req_ram_mb() {
    local ram limit
    ram=$(awk '/MemTotal/ {printf "%d", $2/1024}' /proc/meminfo)
    # A memory limit on this process's cgroup (a container, or systemd's
    # MemoryMax) is the RAM the build really gets, whatever MemTotal says.
    limit=$(req_cgroup_limit_mb)
    if [[ -n "$limit" ]] && (( limit < ram )); then
        ram=$limit
    fi
    printf '%s\n' "$ram"
}
# The smallest memory.max on the way up from this process's cgroup: a limit
# on a parent slice binds just as hard as one on the scope itself.
req_cgroup_limit_mb() {
    local cg max lowest=""
    cg=$(awk -F: '$1 == "0" {print $3}' /proc/self/cgroup 2>/dev/null)
    while [[ -n "$cg" && "$cg" != "/" ]]; do
        max=$(cat "/sys/fs/cgroup${cg}/memory.max" 2>/dev/null || true)
        if [[ "$max" =~ ^[0-9]+$ ]] && [[ -z "$lowest" || "$max" -lt "$lowest" ]]; then
            lowest=$max
        fi
        cg=${cg%/*}
    done
    [[ -n "$lowest" ]] && printf '%s\n' $(( lowest / 1048576 ))
    return 0
}
# How many processes in this process's cgroup the OOM killer has killed, by
# either kind (the kernel's own or a cgroup limit). memory.events counts the
# whole subtree and anyone may read it, so a rise across the build is proof
# without needing the kernel log.
req_oom_kills() {
    local cg
    cg=$(awk -F: '$1 == "0" {print $3}' /proc/self/cgroup 2>/dev/null)
    awk '$1 == "oom_kill" {print $2; found=1} END {if (!found) print 0}' "/sys/fs/cgroup${cg}/memory.events" 2>/dev/null || echo 0
}
req_swap_mb() { awk '/SwapTotal/ {printf "%d", $2/1024}' /proc/meminfo; }
req_free_mb() { df -Pm "$1" 2>/dev/null | awk 'NR==2 {print $4}'; }
req_same_disk() { [[ "$(df -P "$1" 2>/dev/null | awk 'NR==2 {print $1}')" == "$(df -P "$2" 2>/dev/null | awk 'NR==2 {print $1}')" ]]; }
# "none" on a VM or bare metal, otherwise the container type (lxc, openvz, …).
req_container() { systemd-detect-virt --container 2>/dev/null || true; }
# Can this kernel run Docker's containers? Two things Docker cannot work
# without: being allowed to mount in a new mount namespace, and the overlay
# filesystem it stores images on. Both are tried rather than looked up: an
# overlay module that is not loaded yet is missing from /proc/filesystems, and
# a container cannot `modprobe` -- asking those two refused Docker in an LXD
# container that runs it fine (found live, 2026-11-01). The mounts are made
# inside a private namespace that dies with them, over an empty temporary
# directory; the only lasting trace is the kernel loading its overlay module,
# which Docker would do anyway.
req_docker_kernel() {
    local dir problems=()
    if dir=$(mktemp -d 2>/dev/null) && mkdir -p "$dir/lower" "$dir/upper" "$dir/work" "$dir/merged"; then
        if ! unshare --mount --propagation private mount -t tmpfs req-probe "$dir/merged" >/dev/null 2>&1; then
            problems+=("this server is not allowed to create containers (mounting in a new namespace was refused)")
        elif ! unshare --mount --propagation private mount -t overlay req-probe \
                -o "lowerdir=$dir/lower,upperdir=$dir/upper,workdir=$dir/work" "$dir/merged" >/dev/null 2>&1; then
            problems+=("the overlay filesystem Docker stores images on cannot be mounted here")
        fi
    fi
    [[ -n "$dir" && -d "$dir" ]] && rm -rf "$dir"
    [[ -e /sys/fs/cgroup/cgroup.controllers || -d /sys/fs/cgroup/memory ]] \
        || problems+=("control groups are not available")
    if (( ${#problems[@]} )); then
        local IFS=';'
        printf '%s\n' "${problems[*]}"
    fi
    return 0
}

requirement_fail() {
    REQUIREMENT_FAILURES+=("$1")
}

# Everything this server needs, checked after the stack is known and before
# anything is changed. Every shortfall is collected and named in one message.
check_requirements() {
    step "Checking requirements"

    local ram swap ours container free_var need_disk add_mb new_mb
    ram=$(req_ram_mb)
    swap=$(req_swap_mb)
    ours=$(req_our_swap_mb)
    container=$(req_container)
    container=${container:-none}

    if (( ram + RAM_TOLERANCE_MB < MIN_RAM_MB )); then
        requirement_fail "Memory: this server has ${ram} MB of RAM. The panel needs at least ${MIN_RAM_MB} MB (${RECOMMENDED_RAM_MB} MB recommended)."
    else
        ok "${ram} MB RAM"
    fi

    # Swap, worked out by the function configure_swap() itself uses.
    if [[ "${PANEL_SWAP_MB:-}" == "0" ]]; then
        add_mb=0
    elif [[ -n "${PANEL_SWAP_MB:-}" ]]; then
        add_mb=$PANEL_SWAP_MB
    else
        add_mb=$(swap_to_add_mb "$ram" "$swap" "$ours")
    fi

    if (( add_mb > 0 )) && [[ "$container" != "none" ]]; then
        if (( ram + swap < BUILD_MEMORY_MB )); then
            requirement_fail "Swap: this server is a ${container} container, which cannot add swap. It has ${ram} MB of RAM and ${swap} MB of swap; building the panel needs ${BUILD_MEMORY_MB} MB of the two together. Give the container more memory (or swap from its host), or use a virtual machine."
        else
            # Enough to build; the extra margin is optional, so do without.
            SWAP_UNAVAILABLE=1
            ok "${ram} MB RAM + ${swap} MB swap (a ${container} container cannot add swap; not needed)"
            add_mb=0
        fi
    elif (( add_mb > 0 && ours > 0 )); then
        ok "${ram} MB RAM + ${swap} MB swap; the panel's swapfile will be resized to ${add_mb} MB"
    elif (( add_mb > 0 )); then
        ok "${ram} MB RAM + ${swap} MB swap; ${add_mb} MB of swap will be added"
    else
        ok "${ram} MB RAM + ${swap} MB swap"
    fi

    # Our own swapfile is deleted before its replacement is made, so only the
    # difference comes out of the free space.
    new_mb=$(( add_mb - ours ))
    (( add_mb == 0 || new_mb < 0 )) && new_mb=0

    need_disk=$MIN_DISK_MB
    [[ "$STACK" == "docker" ]] && need_disk=$DOCKER_MIN_DISK_MB
    free_var=$(req_free_mb /var)
    free_var=${free_var:-0}
    # The swapfile is created on /; when that is the same disk as /var it
    # comes out of the same free space.
    if (( new_mb > 0 )) && req_same_disk / /var; then
        if (( free_var < need_disk + new_mb )); then
            requirement_fail "Disk: ${free_var} MB free on /var. This install needs ${need_disk} MB there$( [[ "$STACK" == "docker" ]] && printf ' for a Docker server' ) plus ${new_mb} MB for swap: $(( need_disk + new_mb )) MB in total."
        else
            ok "${free_var} MB free on /var (${need_disk} MB + ${new_mb} MB swap needed)"
        fi
    else
        if (( free_var < need_disk )); then
            requirement_fail "Disk: ${free_var} MB free on /var. This install needs at least ${need_disk} MB there$( [[ "$STACK" == "docker" ]] && printf ' for a Docker server' )."
        else
            ok "${free_var} MB free on /var"
        fi
        if (( new_mb > 0 )); then
            local free_root
            free_root=$(req_free_mb /)
            if (( ${free_root:-0} < new_mb )); then
                requirement_fail "Disk: ${free_root:-0} MB free on /, where the swapfile needs ${new_mb} MB."
            fi
        fi
    fi

    if [[ "$STACK" == "docker" ]]; then
        local problems
        if [[ "$container" == "openvz" ]]; then
            requirement_fail "Docker: this server is an OpenVZ container, which cannot run Docker. Use a virtual machine."
        else
            problems=$(req_docker_kernel)
            if [[ -n "$problems" ]]; then
                requirement_fail "Docker: this server cannot run Docker: ${problems//;/; }.$( [[ "$container" == lxc* ]] && printf ' On LXC/LXD, turn on nesting for this container (security.nesting=true) and restart it.' )"
            elif [[ "$container" == lxc* ]]; then
                # What LXD's security.nesting changes cannot be seen from in
                # here: with it off, every probe above still passes and Docker
                # still installs, and only `docker run` fails, inside runc
                # ("open sysctl net.ipv4.ip_unprivileged_port_start ...
                # permission denied", measured 2026-11-01 on LXD 5.21). So say
                # it rather than guess either way.
                ok "this server can mount what Docker needs"
                warn "this is an LXC container: Docker's containers only start if nesting is on for it (on the host: lxc config set <name> security.nesting=true, then restart it)"
            else
                ok "this server can run Docker"
            fi
        fi
    fi

    if (( ${#REQUIREMENT_FAILURES[@]} )); then
        local message="This server does not meet the panel's requirements, so the install stopped." item
        for item in "${REQUIREMENT_FAILURES[@]}"; do
            message+=$'\n'"     - ${item}"
        done
        message+=$'\n'"     The README lists the requirements under \"Requirements\"."
        message+=$'\n'"     Nothing has been changed on this server."
        die "$message"
    fi
}

configure_swap() {
    step "Checking memory"

    local ram_mb swap_mb add_mb ours_mb swapfile="$PANEL_SWAPFILE"
    # The same reading check_requirements() used: a cgroup memory limit is the
    # RAM the build really gets.
    ram_mb=$(req_ram_mb)

    # Existing swap is counted, not treated as a reason to stop looking. The
    # old check bailed the moment *any* swap existed, so a box that came with a
    # 256 MB swapfile got nothing added and failed the build anyway.
    swap_mb=$(req_swap_mb)
    ours_mb=$(req_our_swap_mb)

    # PANEL_SWAP_MB is the escape hatch: `0` skips this entirely (for a box
    # whose swap the operator manages), any other number forces that size.
    if [[ "${PANEL_SWAP_MB:-}" == "0" ]]; then
        skip "swap management disabled (PANEL_SWAP_MB=0)"
        return
    fi

    # A container cannot add swap; check_requirements() has already made sure
    # this one has enough memory to build without it.
    if (( SWAP_UNAVAILABLE )); then
        ok "${ram_mb} MB RAM + ${swap_mb} MB swap; a container cannot add swap, and this is enough"
        return
    fi

    if [[ -n "${PANEL_SWAP_MB:-}" ]]; then
        add_mb="$PANEL_SWAP_MB"
        say "     adding ${add_mb} MB of swap (PANEL_SWAP_MB)"
    else
        add_mb=$(swap_to_add_mb "$ram_mb" "$swap_mb" "$ours_mb")
        if (( add_mb == 0 )); then
            ok "${ram_mb} MB RAM + ${swap_mb} MB swap, nothing to add"
            return
        fi
        say "     ${ram_mb} MB RAM + ${swap_mb} MB swap — sizing ${swapfile} to ${add_mb} MB (want $(( add_mb + swap_mb - ours_mb )) MB of swap in total)"
    fi

    if [[ -e "$swapfile" ]]; then
        # Off first, and only if it is on: swapoff fails on a file that is not
        # active, which is fine. When the kernel refuses for an active one
        # (what it holds does not fit back into RAM), that failure used to be
        # thrown away, and the install died one line later on "rm -f
        # /swapfile-panel" -- the kernel will not unlink an active swap file
        # either -- with nothing in the log saying why (review finding R10,
        # reproduced on a real server). Say the real reason, before touching
        # anything.
        if (( ours_mb > 0 )) && ! swapoff "$swapfile" >>"$LOG_FILE" 2>&1; then
            die "could not turn off ${swapfile} to resize it (usually: what it holds does not fit back into RAM).
     Nothing has been deleted; its output is in ${LOG_FILE}. Free some memory or run with PANEL_SWAP_MB=0 to leave swap alone, then run this installer again."
        fi
        run rm -f "$swapfile"
    fi

    run fallocate -l "${add_mb}M" "$swapfile"
    run chmod 600 "$swapfile"
    run mkswap "$swapfile"
    run swapon "$swapfile"
    grep -q "^${swapfile} " /etc/fstab || printf '%s none swap sw 0 0\n' "$swapfile" >>/etc/fstab
    ok "${add_mb} MB swap active"
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
# Which PHP the panel runs on, decided once from the stack.
#
# LSPHP names its tree by the compacted version -- `lsphp84`, never `lsphp8.4`
# -- and assuming the dot produces a path that does not exist, which OLS does
# not check when the configuration is tested: the panel would be reported up
# and answer 503 on every request.
derive_php_runtime() {
    if [[ "$WEB_SERVER" == "openlitespeed" ]]; then
        local lsphp_compact="${PHP_VERSION//./}"
        PANEL_PHP_BIN="/usr/local/lsws/lsphp${lsphp_compact}/bin/php"
        PANEL_PHP_SAPI="lsapi"
    else
        PANEL_PHP_BIN="/usr/bin/php${PHP_VERSION}"
        PANEL_PHP_SAPI="fpm"
    fi
}

# Writes the PHP source only; install_packages refreshes every source at once,
# after all of them are written (see the note there).
add_php_repository() {
    if [[ "$OS_VERSION_ID" == "26.04" ]]; then
        local keyring=/etc/apt/keyrings/sury-php.gpg
        local list=/etc/apt/sources.list.d/sury-php.list

        if [[ -f "$keyring" && -f "$list" ]]; then
            skip "sury.org PHP repository"
            return
        fi

        run install -d -m 0755 /etc/apt/keyrings
        fetch_apt_key "PHP repository" https://packages.sury.org/php/apt.gpg "$SURY_KEY_FPR" "$keyring"

        # Written with signed-by rather than apt-key: apt-key is deprecated and
        # a key trusted globally would sign for every repository on the box,
        # not just this one.
        if (( DRY_RUN )); then
            printf '     %s$ write %s%s\n' "$DIM" "$list" "$RESET"
        else
            printf 'deb [signed-by=%s] https://packages.sury.org/php/ %s main\n' \
                "$keyring" "$OS_CODENAME" >"$list"
        fi

        ok "sury.org PHP repository added (${OS_CODENAME})"
        return
    fi

    # A glob inside [[ -f ]] is not expanded, so it is matched with compgen —
    # the naive version silently always thought the repository was missing.
    #
    # The refresh happens either way, and that is the point: install_packages
    # refreshes every source after this, whether the file was just written or
    # was already there. `add-apt-repository` writes the sources file before the
    # download succeeds, so a run interrupted between the two leaves a file apt
    # has never fetched an index for. Skipping the refresh because the file
    # exists is what turned that into a dead install: apt has the source and no
    # packages from it.
    #
    # -n: no refresh of its own. One refresh for every source, not one each.
    if ! compgen -G "/etc/apt/sources.list.d/ondrej*php*" >/dev/null; then
        run_progress "Adding the ondrej/php repository" add-apt-repository -y -n ppa:ondrej/php
    else
        skip "ondrej/php repository (refreshed with the others)"
    fi
}

# The version apt would install for php${PHP_VERSION}-fpm, empty when there is
# none. `(none)` is apt's way of saying the package is known but unreachable —
# flattened to empty here so callers have one thing to test.
php_candidate() {
    local candidate
    candidate=$(apt-cache policy "php${PHP_VERSION}-fpm" 2>/dev/null | awk '/Candidate:/ {print $2}')

    [[ "$candidate" == "(none)" ]] && candidate=""

    printf '%s' "$candidate"
}

# Proven before anything is installed, not discovered halfway through: without
# this the install would die on `apt-get install php8.4-fpm` with the web server
# and Redis already on the box, which is the half-installed state preflight
# exists to avoid.
#
# Asks apt the question that matters — "can you install php8.4?" — rather than
# "does a file with the right name exist?".
#
# The difference is the whole bug this replaced. The old flow decided the
# repository was present from a filename, skipped refreshing it, and then died
# claiming the repository "carries no php${PHP_VERSION}". That sentence was
# false: ondrej publishes php8.4 for noble, and has throughout. What had
# actually happened was that apt never fetched the index — a previous run
# interrupted after `add-apt-repository` wrote the file, or a refresh that
# failed to download.
#
# `apt-get update` is the reason that goes unnoticed: it exits **0** when a
# repository cannot be fetched, printing only `W: Failed to fetch`. Verified
# against a deliberately unreachable source. So "the repository was added"
# could be reported over a download that never happened.
#
# Hence: ask apt, after the source is in place AND the index refreshed. Since
# 2026-11-02 that is the normal path rather than the repair: install_packages
# writes the PHP source when it is missing (add_php_repository), refreshes every
# source once, and only then asks here — so a re-run with a stale source still
# converges, because the refresh always runs.
ensure_php_available() {
    if (( DRY_RUN )); then
        printf '     %s$ apt-cache policy php%s-fpm%s\n' "$DIM" "$PHP_VERSION" "$RESET"
        return
    fi

    local candidate
    candidate=$(php_candidate)

    if [[ -z "$candidate" ]]; then
        # Says what was established, not what was assumed. The repository being
        # empty is only one of the reasons, and it is the least likely.
        die "apt cannot install PHP ${PHP_VERSION} on ${PRETTY_NAME:-this release}, after adding and refreshing the PHP repository.
     Usually one of:
       - the repository index failed to download (apt-get update reports this as a warning, not an error)
       - a stale PHP repository on this box points at a different Ubuntu release
       - no network route to the repository
     Check what apt sees:
       apt-cache policy php${PHP_VERSION}-fpm
       grep -r . /etc/apt/sources.list.d/ | grep -i -e ondrej -e sury
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

# Is Ubuntu installing its own updates right now? The units apt.systemd.daily
# runs in are `activating` for as long as they run; and a run started some
# other way is still an `unattended-upgrade` process. Not its `-shutdown`
# helper, which idles on every server (see apt_lock_is_held). The panel asks
# the same two things (SystemUpdatesActivity) for its setup page.
ubuntu_updates_running() {
    local state

    for state in $(systemctl show -p ActiveState --value apt-daily.service apt-daily-upgrade.service 2>/dev/null); do
        [[ "$state" == "activating" || "$state" == "deactivating" ]] && return 0
    done
    pgrep -f 'bin/unattended-upgrade( |$)' >/dev/null 2>&1
}

# Something else is doing package work: it holds the lock, or Ubuntu's updates
# are running (between their download and install they hold no lock at all).
package_manager_busy() {
    apt_lock_is_held || ubuntu_updates_running
}

# What a wait is for, in words. $1: the holder apt or apt_lock_holder named.
apt_wait_text() {
    if [[ "${1:-}" == *unattended-upgr* ]] || ubuntu_updates_running; then
        printf '%s' "Ubuntu is installing its own security updates (normal on a new server)."
    else
        printf '%s' "Another package manager is running (${1:-another package manager})."
    fi
}

# Waits for the lock another package manager holds, saying what it is waiting
# for every 15 s. Never takes the lock from it.
#
# `soft` as $1: give up with a warning (return 1) rather than stop the install,
# for a caller that can carry on without apt (a certificate retry).
#
# While install_packages is waiting (EARLY_WORK=1), the time goes to the work
# that needs no package at all — Node, the panel's source, the interface's npm
# packages — one item at a time, with the lock checked again after each.
wait_for_apt_lock() {
    local mode="${1:-}" holder started=$SECONDS waited=0 said=0

    (( DRY_RUN )) && return 0
    apt_lock_is_held || return 0
    holder=$(apt_lock_holder)

    say "     → $(apt_wait_text "$holder")"
    say "       Waiting up to $((APT_LOCK_WAIT / 60)) minutes for it to finish..."

    while apt_lock_is_held; do
        if (( waited >= APT_LOCK_WAIT )); then
            if [[ "$mode" == "soft" ]]; then
                warn "another package manager is still running after $((waited / 60)) minutes — carrying on without waiting for it"
                return 1
            fi
            die "another package manager is still running after $((waited / 60)) minutes:
       ${holder}
     It was left alone deliberately — killing it can corrupt this server's
     package database. Wait for it to finish, then run this installer again."
        fi

        EARLY_RAN=0
        if (( EARLY_WORK )); then
            early_work_next
        fi
        if (( ! EARLY_RAN )); then
            sleep 5
        fi
        waited=$((SECONDS - started))

        if (( waited - said >= 15 )); then
            said=$waited
            say "       $(apt_wait_text "$holder") Waiting… $(fmt_elapsed "$waited") so far"
        fi
    done

    if [[ "$holder" == *unattended-upgr* ]]; then
        ok "Ubuntu's updates let go of the package lock ($(fmt_elapsed "$waited"))"
    else
        ok "the other package manager finished ($(fmt_elapsed "$waited"))"
    fi
}

# ─── Work that needs no package, while the lock is held ──────────────────────
#
# Installing Node through fnm, fetching the panel and `npm ci` need curl,
# unzip, git and the panel account — all on the image or made before the
# package step — and no apt at all. So when install_packages has to wait for
# another package manager, it does them in the meantime (wait_for_apt_lock).
#
# Each runs in a subshell that cannot report: a failure here goes to the log
# only, and the item simply runs again in its own step, with the normal fatal
# handling. That matters, because Central deletes a server it is told failed.
EARLY_WORK=0
EARLY_RAN=0
EARLY_TRIED="|"
EARLY_DONE="|"

# The overrides are called by the item's own code, which shellcheck cannot see.
# shellcheck disable=SC2317
early_sandbox() {
    trap - ERR
    trap 'progress_stop' EXIT
    PROGRESS_PID=""
    report_failure() { printf 'while waiting: stopped: %s %s\n' "$1" "${2:-}"; }
    save_install_state() { :; }
    send_progress() { :; }
    die() { printf 'while waiting: stopped: %s\n' "$*"; exit 1; }
}

early_item() {
    local key="$1" label="$2" fn="$3" started=$SECONDS rc=0

    EARLY_RAN=1
    EARLY_TRIED+="${key}|"
    say "     → while we wait: ${label}..."

    # In the background and waited for, not in an `if`: errexit is off inside
    # an if's condition, and the item has to stop at its first failure.
    ( early_sandbox; "$fn" ) >>"$LOG_FILE" 2>&1 &
    wait $! || rc=$?

    if (( rc == 0 )); then
        EARLY_DONE+="${key}|"
        ok "while we wait: ${label} ($((SECONDS - started))s)"
    else
        say "       not finished while we waited — it runs again in its own step"
    fi
}

# The next item whose tools are present, if any (sets EARLY_RAN).
early_work_next() {
    if [[ "$EARLY_TRIED" != *"|node|"* ]] && command -v curl >/dev/null 2>&1 && command -v unzip >/dev/null 2>&1; then
        early_item node "installing Node ${NODE_VERSION} (fnm)" install_node_body
    elif [[ "$EARLY_TRIED" != *"|fetch|"* ]] && command -v git >/dev/null 2>&1; then
        early_item fetch "fetching the panel" fetch_source_body
    elif [[ "$EARLY_TRIED" != *"|deps|"* && "$EARLY_DONE" == *"|node|"* && "$EARLY_DONE" == *"|fetch|"* ]]; then
        early_item deps "downloading the interface's packages" frontend_deps
    fi
    return 0
}

# MongoDB publishes an ASCII-armoured signing key. Panel releases before this
# repair saved that response with a `.gpg` extension, which tells apt to parse
# it as a dearmoured binary keyring. The repository list survived the failed
# engine install, so every later `apt-get update` — including this installer's
# first package step — stopped with NO_PUBKEY before updated panel code could
# repair its own file.
#
# The signing key for one MongoDB series, as the armored file apt reads
# straight from signed-by. A reply that is not a key stops the install.
fetch_mongodb_key() {
    local series="$1" keyring="$2" label="$3"

    run install -d -m 0755 /etc/apt/keyrings
    run_progress "$label" \
        curl -fsSL --proto '=https' --proto-redir '=https' \
        -o "$keyring" "https://pgp.mongodb.com/server-${series}.asc"

    if (( ! DRY_RUN )) && ! grep -q '^-----BEGIN PGP PUBLIC KEY BLOCK-----$' "$keyring"; then
        die "MongoDB ${series} returned an invalid repository signing key."
    fi

    run chmod 0644 "$keyring"
}

# Touch only the exact MongoDB source/key pattern the panel wrote. An unrelated
# third-party repository is the server owner's configuration, not ours to
# rewrite. The old `.gpg` file is left in place; once no source references it,
# it is harmless, and deleting an operator-managed file would be needless.
repair_panel_mongodb_repository() {
    local list series old_key keyring current fixed

    for list in /etc/apt/sources.list.d/mongodb-org-*.list; do
        [[ -e "$list" ]] || continue

        series=${list##*/mongodb-org-}
        series=${series%.list}
        [[ "$series" =~ ^[0-9]+\.[0-9]+$ ]] || continue

        old_key="/etc/apt/keyrings/mongodb-server-${series}.gpg"
        keyring="/etc/apt/keyrings/mongodb-server-${series}.asc"
        current=$(cat "$list" 2>/dev/null || true)

        # Only the official MongoDB Ubuntu source with the panel's old key path
        # is ours to rewrite. A correct `.asc` source with a missing key is also
        # repaired, covering a download interrupted between the two writes.
        if [[ "$current" == *"https://repo.mongodb.org/apt/ubuntu"* \
              && "$current" == *"signed-by=${old_key}"* ]]; then
            fetch_mongodb_key "$series" "$keyring" "Repairing the MongoDB ${series} repository signing key"
            fixed=${current//$old_key/$keyring}

            if (( DRY_RUN )); then
                printf '     %s$ rewrite %s to use %s%s\n' "$DIM" "$list" "$keyring" "$RESET"
            else
                printf '%s\n' "$fixed" >"$list"
            fi

            ok "MongoDB ${series} repository key repaired"
        elif [[ "$current" == *"https://repo.mongodb.org/apt/ubuntu"* \
                && "$current" == *"signed-by=${keyring}"* \
                && ! -s "$keyring" ]]; then
            fetch_mongodb_key "$series" "$keyring" "Restoring the MongoDB ${series} repository signing key"
            ok "MongoDB ${series} repository key restored"
        fi
    done
}

# Docker, from Docker's own repository.
#
# Not `curl -fsSL https://get.docker.com | sh`. That is the documented
# convenience script and it pipes a remote file straight into a root shell on
# someone else's server — the same trade this installer already refuses for
# LiteSpeed, whose one-liner drops keys into /etc/apt/trusted.gpg.d/ and trusts
# them for every repository on the box. One keyring, scoped to one source with
# signed-by, the way sury and MongoDB are already handled here.
#
# Ubuntu ships `docker.io` in universe, which would need no repository at all.
# It is consistently several minor versions behind and does not carry the
# compose v2 plugin, and compose is the format the panel generates — so the
# older package would work right up until the first `docker compose` call.
# The Docker source only; its packages go into install_packages's one install.
add_docker_repository() {
    local keyring="/etc/apt/keyrings/docker.asc"
    local list="/etc/apt/sources.list.d/docker.list"

    if [[ -f "$keyring" && -f "$list" ]]; then
        skip "Docker repository already configured"
        return 0
    fi

    run install -d -m 0755 /etc/apt/keyrings
    fetch_apt_key "Docker repository" https://download.docker.com/linux/ubuntu/gpg "$DOCKER_KEY_FPR" "$keyring"

    if (( DRY_RUN )); then
        printf '     %s$ write %s%s\n' "$DIM" "$list" "$RESET"
    else
        # dpkg's own architecture, not `uname -m`: the repository is
        # indexed by amd64/arm64, and uname says x86_64/aarch64.
        printf 'deb [arch=%s signed-by=%s] https://download.docker.com/linux/ubuntu %s stable\n' \
            "$(dpkg --print-architecture)" "$keyring" "$OS_CODENAME" >"$list"
    fi

    ok "Docker repository added (${OS_CODENAME})"
}

# Every package the panel needs, one per line: the one list install_packages
# hands apt in a single transaction.
#
# update-notifier-common provides `apt-check`, which is how the panel counts
# pending and security updates. Without it the Settings page cannot tell the
# difference between "nothing waiting" and "could not look", so it reports
# neither. Cheap, and it is the same source Ubuntu's own MOTD uses.
#
# build-essential is here because npm packages ship prebuilt binaries only
# for some Node versions, and fall back to compiling from source for the
# rest. A server with no compiler turns that fallback into a dead install:
# n8n died in `node-gyp` building isolated-vm on 2026-09-15, and the error
# it surfaced was about dependency resolution, because npm buries the one
# line that matters under thousands of peer warnings.
#
# Installed for every stack, deliberately. Which packages need compiling is
# not knowable at install time — the user picks site types later, and a
# git-deployed project can depend on anything — so a conditional would have
# to guess the future. It is ~235 MB, and it is the difference between an
# install that works and one that fails for a reason the user cannot act on.
# `pigz` is gzip across every core, and backups are where that shows.
# Measured on a 103 GB site with 8 cores: `tar -czf` took 67 minutes with
# gzip pinned at 99.5% of one core and seven idle; pigz did the same work in
# ~15. Same format — pigz emits ordinary gzip, so `tar -tzf`, the verify step
# and every archive already in a bucket are unaffected.
#
# The panel falls back to gzip when this is absent, so it is an improvement
# rather than a requirement. That fallback is load-bearing: the updater ships
# code, never packages, so this line only ever reaches *fresh* installs —
# an existing panel upgrading to the code that prefers pigz will not have it
# until somebody runs `apt install pigz` by hand. ~60 KB.
#
# `acl` is setfacl: each site user's home is closed to every other local
# account and opened, by ACL, to the panel and the web server only (see
# HomeDirectoryAccess). Ubuntu 26.04 does not ship it. Unlike pigz, existing
# servers get it too: `sites:resync` installs it when it is missing.
panel_packages() {
    printf '%s\n' software-properties-common curl git unzip zip rsync ca-certificates gnupg \
        update-notifier-common build-essential pigz acl

    # Only the chosen web server. Installing both would have them fight over
    # port 80, and apt starts them on install.
    case "$WEB_SERVER" in
        nginx)         printf '%s\n' nginx ;;
        apache)        printf '%s\n' apache2 ;;
        openlitespeed) printf '%s\n' openlitespeed ;;
    esac

    printf '%s\n' redis-server sqlite3 supervisor

    # OpenLiteSpeed's panel PHP is lsphp, installed by install_ols_packages
    # right after this list (see the note there).
    [[ "$PANEL_PHP_SAPI" == "fpm" ]] && panel_php_packages

    # certbot from the start, not when the certificate is issued: an apt run
    # there is the one Ubuntu's updates queued behind for seven minutes.
    # No certbot plugin exists for OpenLiteSpeed. --webroot instead: certbot
    # drops its token in the document root and never touches the config, so
    # installing the certificate is our job (see install_ols_certificate).
    if (( WANT_SSL )); then
        case "$WEB_SERVER" in
            nginx)         printf '%s\n' certbot python3-certbot-nginx ;;
            apache)        printf '%s\n' certbot python3-certbot-apache ;;
            openlitespeed) printf '%s\n' certbot ;;
        esac
    fi

    # Docker, when the stack asked for it, from Docker's own repository
    # (add_docker_repository). buildx and compose are plugins now, not
    # separate binaries. Without docker-compose-plugin there is no `docker
    # compose` subcommand at all, and the panel generates compose files — so
    # omitting it ships a stack whose every deploy fails on the first call.
    if [[ "$STACK" == "docker" ]]; then
        printf '%s\n' docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
    fi

    return 0
}

# Matches the extensions the panel actually loads. Kept explicit rather
# than pulling php${V} — the metapackage drags in apache2 as a
# dependency, which would fight nginx for port 80.
panel_php_packages() {
    printf '%s\n' \
        "php${PHP_VERSION}-fpm" "php${PHP_VERSION}-cli" "php${PHP_VERSION}-common" \
        "php${PHP_VERSION}-bcmath" "php${PHP_VERSION}-curl" "php${PHP_VERSION}-intl" \
        "php${PHP_VERSION}-mbstring" "php${PHP_VERSION}-xml" "php${PHP_VERSION}-zip" \
        "php${PHP_VERSION}-gd" "php${PHP_VERSION}-sqlite3" "php${PHP_VERSION}-mysql" \
        "php${PHP_VERSION}-pgsql" \
        "php${PHP_VERSION}-redis" "php${PHP_VERSION}-igbinary" "php${PHP_VERSION}-opcache" \
        "php${PHP_VERSION}-soap" \
        "php${PHP_VERSION}-imagick" "php${PHP_VERSION}-imap" "php${PHP_VERSION}-gmp"
    # pgsql alongside mysql: the panel can manage a PostgreSQL server, and
    # without this extension no PHP application on the box can connect to
    # one — the databases would be creatable and unusable.
    #
    # soap has no package on the LiteSpeed stack because LSPHP compiles
    # it in, so an OpenLiteSpeed box has always had it. Naming it here
    # is what stops nginx and Apache being the odd ones out: the two
    # lists are now identical, and PhpRuntimeTest fails if they drift.
    #
    # What v7 installs as well (operator, 2026-10-07): imagick for
    # WordPress's image editor, imap for Mautic, gmp for Nextcloud.
    # Measured on 24.04 and 26.04 for every PHP 7.4–8.5.
}

# What writing the third-party sources needs before the refresh: curl, gpg,
# the CA bundle and add-apt-repository. Ubuntu's cloud images ship all of
# them, so this is normally nothing; a missing one costs one extra refresh.
install_source_tools() {
    local missing=()

    command -v curl >/dev/null 2>&1 || missing+=(curl)
    command -v gpg >/dev/null 2>&1 || missing+=(gnupg)
    [[ -f /etc/ssl/certs/ca-certificates.crt ]] || missing+=(ca-certificates)
    if [[ "$PANEL_PHP_SAPI" == "fpm" && "$OS_VERSION_ID" != "26.04" ]]; then
        command -v add-apt-repository >/dev/null 2>&1 || missing+=(software-properties-common)
    fi

    (( ${#missing[@]} )) || return 0

    wait_for_apt_lock
    run_apt "Refreshing system package lists" apt-get update -qq
    run_apt "Installing ${missing[*]}" apt-get install -y "${missing[@]}"
}

# Every third-party source the stack needs, written before the one refresh.
add_package_sources() {
    if [[ "$PANEL_PHP_SAPI" == "fpm" ]]; then
        add_php_repository
    fi
    if [[ "$STACK" == "docker" ]]; then
        add_docker_repository
    fi
    if [[ "$WEB_SERVER" == "openlitespeed" ]]; then
        add_ols_repository
    fi
}

# Local mail, installed right after the main list. A call of its own because
# it is never fatal (configure_mail): one package failing must not take the
# whole transaction down with it. A Postfix that was here before the install
# is left exactly as it was.
install_postfix() {
    if [[ "$POSTFIX_STATE" == "preinstalled" ]]; then
        return 0
    fi
    if dpkg-query -W -f='${Status}' postfix 2>/dev/null | grep -q "install ok installed"; then
        POSTFIX_STATE="installed"
        return 0
    fi

    preseed_postfix

    local started=$SECONDS
    say "     → Installing local mail (Postfix)..."
    progress_start "installing local mail (Postfix)" apt_install
    if DEBIAN_FRONTEND=noninteractive apt-get install -y postfix >>"$LOG_FILE" 2>&1; then
        POSTFIX_STATE="installed"
    else
        POSTFIX_STATE="failed"
    fi
    progress_stop
    ok "Installing local mail (Postfix) ($((SECONDS - started))s)"
}

# Postfix's two questions, answered before apt asks them: v7's "Internet Site"
# for this machine's own name.
preseed_postfix() {
    local mailname
    mailname=$(hostname -f 2>/dev/null || hostname)
    printf 'postfix postfix/main_mailer_type select Internet Site\npostfix postfix/mailname string %s\n' "$mailname" \
        | debconf-set-selections >>"$LOG_FILE" 2>&1 || true
}

# What happened to Postfix in the package step: preinstalled, installed or
# failed. configure_mail acts on it.
POSTFIX_STATE=""

# ─── The package step ────────────────────────────────────────────────────────
#
# Every apt operation of the install happens here, in one block with no gaps,
# and none after it (a test holds that line). The reason is measured, not
# assumed (2026-11-01, seven fresh 4 GB servers): Ubuntu's first-boot
# unattended-upgrades queues behind our package work and takes the lock in the
# first gap of a few seconds — on six of seven boxes the gap after this step —
# and then holds it for 6–9 minutes. The next apt call of ours (Postfix, or
# certbot) waited all of that, and everything else waited behind it.
#
# With every package in now, Ubuntu's updates run beside the configuration
# and the build, which need no package at all. Nothing interrupts them.
#
#   1. sources first, written without a refresh of their own;
#   2. one refresh;
#   3. one install of every package (panel_packages);
#   4. straight after, the non-fatal ones: Postfix, lsphp's extensions.
# A host booted with ipv6.disable=1 has no IPv6 socket family at all, and
# nginx refuses to start on any `listen [::]` there ("socket() [::]:80 failed
# (97: Address family not supported by protocol)"). /proc/net/if_inet6 exists
# exactly when the kernel has IPv6; the sysctl disable_ipv6 switch leaves it,
# and leaves binding to [::] working (review finding P1, checked on a real
# server).
# The argument is only for the tests; the installer calls it with none.
have_ipv6() {
    [[ -e "${1:-/proc/net/if_inet6}" ]]
}

# The nginx files the installer writes, without their [::] listens when the
# host has no IPv6. certbot --nginx then adds no IPv6 listen of its own either:
# it mirrors the server block's existing listens.
nginx_without_ipv6() {
    have_ipv6 || sed -i '/^[[:space:]]*listen[[:space:]]\+\[::\]/d' "$@"
}

# Ubuntu's nginx package starts nginx as it installs, with a default site that
# listens on [::]:80, so on a host with no IPv6 the package itself fails to
# configure and the install stops at "Installing packages" before any file of
# ours exists. Its postinst only links that site when nothing is at
# sites-enabled/default yet, so a comment-only file there lets nginx start;
# configure_nginx deletes it, as it deletes the stock site. A link there is the
# stock site from an earlier failed run, and is replaced the same way.
# Arguments only for the tests; the installer calls it with none.
# shellcheck disable=SC2120
make_room_for_nginx_without_ipv6() {
    local inet6="${1:-/proc/net/if_inet6}" enabled="${2:-/etc/nginx/sites-enabled}"
    [[ "$WEB_SERVER" == "nginx" ]] || return 0
    have_ipv6 "$inet6" && return 0
    [[ ! -e "${enabled}/default" || -L "${enabled}/default" ]] || return 0
    mkdir -p "$enabled"
    rm -f "${enabled}/default"
    printf '# This host has no IPv6, and the default site listens on [::]:80.\n# Kept out by the Control panel installer; configure_nginx removes this file.\n' \
        >"${enabled}/default"
    say "     no IPv6 on this host: keeping nginx's default [::]:80 site out"
}

install_packages() {
    step "Installing packages"

    export DEBIAN_FRONTEND=noninteractive

    configure_apt_lock_wait
    EARLY_WORK=1
    wait_for_apt_lock
    repair_panel_mongodb_repository

    if dpkg-query -W -f='${Status}' postfix 2>/dev/null | grep -q "install ok installed"; then
        POSTFIX_STATE="preinstalled"
    fi

    install_source_tools
    add_package_sources

    wait_for_apt_lock
    run_apt "Refreshing package lists" apt-get update -qq

    # Asked after the refresh, of apt itself — see ensure_php_available().
    if [[ "$PANEL_PHP_SAPI" == "fpm" ]]; then
        ensure_php_available
    fi

    # The optional packages (Postfix; lsphp and its extensions on
    # OpenLiteSpeed) go into the SAME transaction. They used to be calls of
    # their own, straight after, and the sub-second gap before them was enough:
    # Ubuntu's updates wait on the lock behind us, and on one of seven fresh
    # servers (2026-11-02) they took it there, and Postfix waited five minutes.
    # apt has no way to hold the lock across two calls (it ignores
    # DPKG_FRONTEND_LOCKED, tested on apt 2.8), so there is one call.
    #
    # They stay optional: if the whole list fails, the required ones are
    # installed on their own and the optional ones after, each non-fatal, as
    # before. Only then is there a gap, and only in that rare case.
    local pkgs=() optional=()
    mapfile -t pkgs < <(panel_packages)
    if [[ "$POSTFIX_STATE" != "preinstalled" ]]; then
        optional+=(postfix)
        preseed_postfix
    fi
    if [[ "$PANEL_PHP_SAPI" == "lsapi" ]]; then
        mapfile -t -O "${#optional[@]}" optional < <(install_ols_packages --list)
    fi

    (( DRY_RUN )) || make_room_for_nginx_without_ipv6

    wait_for_apt_lock
    EARLY_WORK=0
    if (( DRY_RUN )) || [[ -z "$RUN_DIR" ]]; then
        run_apt "Installing packages" apt-get install -y "${pkgs[@]}" "${optional[@]}"
        install_postfix
    elif try_apt "Installing packages" apt-get install -y "${pkgs[@]}" "${optional[@]}"; then
        install_postfix
        if [[ "$PANEL_PHP_SAPI" == "lsapi" ]]; then
            OLS_PACKAGES_DONE=1
        fi
    else
        warn "one of the optional packages (local mail, or a PHP extension) could not be installed with the rest — installing the rest on its own"
        run_apt "Installing packages" apt-get install -y "${pkgs[@]}"
        install_postfix
    fi

    if [[ "$PANEL_PHP_SAPI" == "lsapi" ]]; then
        # OpenLiteSpeed: the panel runs on LSPHP, the same build its hosted
        # sites use. ondrej's PHP is not installed at all on this stack.
        #
        # Two builds is the thing being avoided. Pointing only the web SAPI at
        # lsphp while composer and artisan stayed on /usr/bin/php would give the
        # panel two PHPs with two extension sets, and the failure mode is the
        # API answering 500 on an extension the CLI that installed the code has.
        #
        # A side effect worth naming: /etc/apache2 comes from `phpX.Y-fpm`, so
        # an OLS box that never installs it never grows the phantom directory
        # that made every OpenLiteSpeed server detect as Apache.
        #
        # Here rather than in configure_web_server, and the ordering is the
        # reason: composer is fetched a few lines below and run with
        # PANEL_PHP_BIN, which on this stack is lsphp.
        #
        # install_ols_packages guards itself, so the later call from
        # configure_ols is a no-op rather than a second apt run.
        install_ols_packages

        # gd, xml, zip, mbstring and bcmath have no packages -- they are
        # compiled into lsphp, verified by extracting the .deb, which ships no
        # .so files at all. The rest are separate packages and are added by
        # install_ols_packages alongside the ones hosted sites need.

        # `php` on PATH, pointing at LSPHP's CLI.
        #
        # Found on real hardware, and it stopped the install dead:
        #
        #   env: 'php': No such file or directory
        #
        # composer and wp-cli are PHARs whose shebang is `#!/usr/bin/env php`.
        # Installing them with an explicit interpreter is not enough -- the
        # moment anything EXECUTES them, the kernel reads that line and looks
        # for `php` on PATH. There is none on this stack, because ondrej's PHP
        # is deliberately not installed.
        #
        # wp-cli is the one that matters beyond the install: the panel drives
        # every WordPress operation through /usr/local/bin/wp, so without this
        # a WordPress site could not be created, updated or backed up on an
        # OpenLiteSpeed server.
        #
        # /usr/local/bin comes before /usr/bin on the default PATH, and this is
        # only created on this stack, so it cannot shadow ondrej's php
        # elsewhere.
        run ln -sfn "$PANEL_PHP_BIN" /usr/local/bin/php
    fi

    ok "${WEB_SERVER}, redis, sqlite, PHP ${PHP_VERSION}"

    if [[ "$STACK" == "docker" ]]; then
        run systemctl enable --now docker
        ok "Docker installed ($(docker --version 2>/dev/null || echo 'version unknown'))"
    fi

    # Whatever composer we end up using, remember *where* it is. The check
    # below asks "is composer installed anywhere", and the install step later
    # used to invoke a hardcoded /usr/local/bin/composer — so on a server that
    # already had one somewhere else the install skipped the download and then
    # died on a path it had never created. Found on a migrated box carrying
    # Ubuntu's /usr/bin/composer.
    COMPOSER_BIN=$(command -v composer 2>/dev/null || true)

    if [[ -z "$COMPOSER_BIN" ]]; then
        progress_start "downloading composer"
        install_composer_binary
        progress_stop
        COMPOSER_BIN=/usr/local/bin/composer
        ok "composer"
    else
        skip "composer (${COMPOSER_BIN})"
    fi
}

# ─── Checked downloads ───────────────────────────────────────────────────────
#
# Everything here is fetched over https from a well-known host and then run or
# trusted as root, and used to be taken on that alone: Composer's installer,
# fnm's install script, the sury.org and Docker apt keys (review finding S3).
# A compromised upstream or CDN would have been root on every new server. Each
# is now checked against something that does not come from the same download,
# and the scratch files live in RUN_DIR (mktemp, root-only, removed on exit)
# instead of fixed names in /tmp.

# A scratch file for one download.
download_tmp() {
    mktemp "${RUN_DIR:-${TMPDIR:-/tmp}}/download.XXXXXX"
}

# An apt signing key, trusted only if it is exactly one key with the expected
# primary fingerprint. One, not "includes": signed-by trusts every key in the
# file, so an extra key would sign for this repository too.
fetch_apt_key() {
    local label="$1" url="$2" fpr="$3" dest="$4" tmp keys got

    if (( DRY_RUN )); then
        printf '     %s$ fetch %s, check it is %s, install as %s%s\n' "$DIM" "$url" "$fpr" "$dest" "$RESET"
        return 0
    fi

    tmp="$(download_tmp)"
    run_progress "Downloading the ${label} signing key" curl -fsSL -o "$tmp" "$url"
    keys="$(gpg --show-keys --with-colons "$tmp" 2>/dev/null)" || keys=""
    got="$(awk -F: '$1 == "pub" { p = 1; next } p && $1 == "fpr" { print $10; exit }' <<<"$keys")"

    if [[ "$(grep -c '^pub:' <<<"$keys" || true)" != 1 || "$got" != "$fpr" ]]; then
        rm -f "$tmp"
        die "the ${label} signing key from ${url} is not the expected one.
     expected exactly one key, ${fpr}
     got      ${got:-nothing} ($(grep -c '^pub:' <<<"$keys" || true) key(s))
     Refusing to trust it. Nothing has been added to apt."
    fi

    run install -m 0644 "$tmp" "$dest"
    rm -f "$tmp"
}

# Composer, from its installer, checked against the SHA-384 Composer publishes
# for it on a different host -- the check getcomposer.org/download documents.
install_composer_binary() {
    local setup sig expected actual
    setup="$(download_tmp)"
    sig="$(download_tmp)"

    run curl -fsSL -o "$setup" https://getcomposer.org/installer
    run curl -fsSL -o "$sig" https://composer.github.io/installer.sig
    expected="$(tr -d '[:space:]' <"$sig")"
    actual="$(sha384sum "$setup" | cut -d' ' -f1)"

    if [[ ! "$expected" =~ ^[0-9a-f]{96}$ || "$actual" != "$expected" ]]; then
        rm -f "$setup" "$sig"
        die "Composer's installer does not match the checksum Composer publishes for it.
     expected ${expected:-nothing} (https://composer.github.io/installer.sig)
     got      ${actual}
     Refusing to run it. Try again in a few minutes: the two can disagree
     for a moment right after a Composer release."
    fi

    # PANEL_PHP_BIN, not a bare `php`: on OpenLiteSpeed there is no
    # /usr/bin/php at all, and a bare invocation would either fail or --
    # worse -- find some other PHP and install composer against it.
    run "$PANEL_PHP_BIN" "$setup" --install-dir=/usr/local/bin --filename=composer
    rm -f "$setup" "$sig"
}

# fnm, as the pinned release binary checked against its pinned SHA-256. The
# same file fnm.vercel.app's script installs, without the script: it fetched
# "latest", checked nothing, and was itself run as root unchecked.
install_fnm_binary() {
    local asset want zip

    case "$(uname -m)" in
        x86_64)  asset=fnm-linux; want="$FNM_SHA256_X86_64" ;;
        aarch64) asset=fnm-arm64; want="$FNM_SHA256_AARCH64" ;;
        *)       die "no fnm build for $(uname -m)" ;;
    esac

    zip="$(download_tmp)"
    run curl -fsSL -o "$zip" "https://github.com/Schniz/fnm/releases/download/v${FNM_VERSION}/${asset}.zip"

    if [[ "$(sha256sum "$zip" | cut -d' ' -f1)" != "$want" ]]; then
        rm -f "$zip"
        die "fnm ${FNM_VERSION} (${asset}.zip) does not match its pinned checksum.
     expected ${want}
     got      $(sha256sum "$zip" 2>/dev/null | cut -d' ' -f1)
     Refusing to install it."
    fi

    run unzip -o -q "$zip" fnm -d "$FNM_DIR"
    run chmod 0755 "${FNM_DIR}/fnm"
    rm -f "$zip"
}

# ─── Node, through fnm ───────────────────────────────────────────────────────
#
# fnm rather than apt or nodesource because the panel's Node feature manages
# versions *with fnm* and treats anything else as an untouchable system binary.
# Installing Node from apt here would leave that feature unable to add or switch
# versions on a server it just installed.

install_node() {
    step "Installing Node ${NODE_VERSION} (fnm)"
    install_node_body
}

# fnm's own layout: node-versions/vX.Y.Z/installation/bin/node — four levels
# below node-versions, which is why maxdepth is 5 and not 3.
find_node_bin() {
    find "${FNM_DIR}/node-versions" -maxdepth 5 -type f -name node -path "*v${NODE_VERSION}.*" -print -quit 2>/dev/null || true
}

# The step without its header, so install_packages can run it while it waits
# for the package lock (early_work_next). Re-run safe: a second run skips
# what the first did.
install_node_body() {
    if [[ ! -x "$FNM_BIN" ]]; then
        mkdir -p "$FNM_DIR"
        progress_start "downloading fnm"
        install_fnm_binary
        progress_stop
        ln -sf "${FNM_DIR}/fnm" "$FNM_BIN"
        ok "fnm installed at $FNM_BIN"
    else
        skip "fnm"
    fi

    export FNM_DIR
    if ! "$FNM_BIN" list 2>/dev/null | grep -q "v${NODE_VERSION}\."; then
        progress_start "downloading Node ${NODE_VERSION}"
        run "$FNM_BIN" install "$NODE_VERSION"
        progress_stop
        run "$FNM_BIN" alias "$NODE_VERSION" default
        ok "Node ${NODE_VERSION}"
    else
        skip "Node ${NODE_VERSION}"
    fi

    # Resolved once and written into the frontend's unit file. The panel's own
    # frontend should keep running the version it was built against even if
    # someone later changes the server's default.
    NODE_BIN=$(find_node_bin)
    [[ -x "${NODE_BIN:-}" ]] || die "installed Node ${NODE_VERSION} but cannot find its binary under ${FNM_DIR}"

    # Bare node/npm/npx for everything that is not the panel: an SSH user, a
    # cron job, and any Node app installed without a pinned version. The
    # Node screen's "set default" makes exactly these links; setting fnm's
    # alias alone did not, so a fresh server had a default Node and no `npm`
    # on anyone's PATH, and a Node-RED install died on
    # `npm: No such file or directory`. Re-run safe: -sfn replaces a link.
    #
    # Not over a Node the server already has. A server that comes from v7 runs
    # its sites on v7's Node (nodesource, /usr/bin/node); /usr/local/bin comes
    # first on PATH, so a link here would move every one of them to this
    # version without a word (operator, 2026-10-07: keep v7's Node).
    local node_bin_dir bin
    node_bin_dir=$(dirname "$NODE_BIN")
    if [[ -x /usr/bin/node ]]; then
        skip "node already on this server ($(/usr/bin/node -v 2>/dev/null)) — not shadowed"
    else
        for bin in node npm npx; do
            run ln -sfn "${node_bin_dir}/${bin}" "/usr/local/bin/${bin}"
        done
        ok "node, npm and npx linked into /usr/local/bin"
    fi

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

# Does the panel we just checked out know the stack we were asked to build?
#
# It is possible for it not to, and it cost two servers. This script is fetched
# from a URL -- often a branch -- while the panel is cloned from $REPO_BRANCH,
# which defaults to main regardless of where the script itself came from. So
# `curl .../feat/some-branch/install.sh | bash -s -- --stack=newthing` installs
# main's panel, and main has never heard of `newthing`.
#
# The failure without this check lands on `artisan server:record-stack`, which
# is the LAST thing setup_backend does -- after packages, composer, migrations
# and seeding. Thirteen steps of work, then "Unknown stack". The fix is to fail
# here instead, before any of it, and to name the flag that resolves it.
#
# Grepped rather than asked of artisan: composer has not run yet at this point,
# so there is no artisan to ask. StackRegistryTest keeps this list and the
# installer's own in step, so the grep is an early warning rather than the
# authority.
assert_panel_knows_stack() {
    local registry="${APP_DIR}/backend/app/Services/Server/Capabilities/ServerCapabilities.php"

    # No file means a layout this check does not understand. Say nothing rather
    # than refuse an install over a path that moved.
    [[ -f "$registry" ]] || return 0

    if grep -qE "^[[:space:]]*'${STACK}' => \\[" "$registry"; then
        return 0
    fi

    die "the panel on branch '${REPO_BRANCH}' does not support --stack=${STACK}.

     This script came from one place and the panel was cloned from another.
     If the stack is new, install the panel from the same branch:

       --branch=<the branch this installer came from>"
}

fetch_source() {
    step "Fetching the panel"

    if [[ "$EARLY_DONE" == *"|fetch|"* ]]; then
        skip "fetched while waiting for the package lock"
        return 0
    fi
    fetch_source_body
}

# The step without its header, for early_work_next — see install_node_body.
fetch_source_body() {
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
        # As ${APP_USER}: a fetch run as root writes a root-owned
        # .git/FETCH_HEAD, and the panel's own updater — which fetches as
        # ${APP_USER} — can then never rewrite it.
        # An explicit refspec, not a bare branch name. The clone below is
        # --depth 1, which pins remote.origin.fetch to ONE branch:
        #
        #   +refs/heads/main:refs/remotes/origin/main
        #
        # so `git fetch origin some-other-branch` fetches into FETCH_HEAD and
        # never creates refs/remotes/origin/some-other-branch. The reset on the
        # next line then dies on an unknown revision, and re-running the
        # installer with a different --branch on an existing box was impossible.
        # Writing the destination ref ourselves works for main too -- it
        # rewrites the ref it already has.
        progress_start "downloading the panel"
        run sudo -u "${APP_USER}" -H git -C "$APP_DIR" fetch --depth 1 origin \
            "+refs/heads/${REPO_BRANCH}:refs/remotes/origin/${REPO_BRANCH}"
        progress_stop
        run sudo -u "${APP_USER}" -H git -C "$APP_DIR" reset --hard "origin/${REPO_BRANCH}"
        ok "updated to the latest ${REPO_BRANCH}"
    else
        mkdir -p "$(dirname "$APP_DIR")"
        if [[ -e "$APP_DIR" ]]; then
            # A previous attempt can leave APP_DIR behind without a valid
            # .git — an interrupted clone, or something else pre-creating
            # the path. git then either refuses to clone into it or, worse,
            # leaves it in a state where later plumbing (git config, fetch)
            # fails with a cryptic "not in a git directory".
            #
            # Moved aside, not deleted. This used to be rm -rf on the theory
            # that the installer owns APP_DIR, and with `panel` as the
            # default name the directory was sometimes another application
            # (review finding R2). A leftover clone costs some disk to keep;
            # somebody's site costs everything to lose.
            local aside
            aside="${APP_DIR}.old-$(date +%Y%m%d-%H%M%S)"
            run mv "$APP_DIR" "$aside"
            warn "${APP_DIR} existed but was not a git checkout — moved to ${aside} (delete it once you have checked it is not needed)"
        fi
        progress_start "downloading the panel"
        run git clone --depth 1 --branch "$REPO_BRANCH" -- "$REPO_URL" "$APP_DIR"
        progress_stop
        ok "cloned into ${APP_DIR}"
    fi

    assert_panel_knows_stack

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
    #
    # Run as ${APP_USER}, not as root. `git config` rewrites .git/config, and a
    # root-owned .git/config is how a tree handed to ${APP_USER} two lines ago
    # ends up with root-owned files in it again. The same shape further up —
    # `git -C "$APP_DIR" fetch` on a re-run — leaves a root-owned
    # .git/FETCH_HEAD, and every later `sudo -u ${APP_USER} git fetch` then
    # dies with "cannot open '.git/FETCH_HEAD': Permission denied". Reported
    # from the field, reproduced on a real box.
    run sudo -u "${APP_USER}" -H git -C "$APP_DIR" config core.fileMode false
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

# Local mail, as v7 sets a server up (operator, 2026-10-07). Without an MTA
# PHP's mail() fails, so WordPress password resets, contact forms and every
# notification an application sends go nowhere, with no error on screen.
#
# Postfix as v7 has it ("Internet Site", relaying only for this machine), but
# listening on loopback only: the sites need to send, nothing needs to
# receive, and v7's listener on every interface was kept closed only by the
# firewall. Never fatal — a panel without local mail still works.
#
# Installed in the package step (install_postfix); configured here, which needs
# no apt at all.
configure_mail() {
    step "Setting up local mail (Postfix)"

    case "$POSTFIX_STATE" in
        preinstalled)
            skip "Postfix already installed — its configuration is left as it is"
            return ;;
        installed) ;;
        *)
            warn "could not install Postfix — sites will not be able to send mail"
            return ;;
    esac

    postconf -e 'inet_interfaces = loopback-only' >>"$LOG_FILE" 2>&1 || true
    systemctl restart postfix >>"$LOG_FILE" 2>&1 || true
    ok "Postfix sends mail for this server (listening on loopback only)"
}

# What the default user's ACL line in redis.conf leaves it with, read the way
# Redis applies the line, left to right: "nopass" (no password at all),
# "password", "none" (neither — nobody can log in as it), or nothing when the
# file has no such line. A password token clears nopass and resetpass clears
# both, so `nopass` appearing on the line is not enough on its own.
redis_default_acl() {
    awk '$1 == "user" && $2 == "default" { line = $0 }
        END {
            if (line == "") exit
            n = split(line, t); np = 0; pw = 0
            for (i = 3; i <= n; i++) {
                if (t[i] == "nopass") { np = 1; pw = 0 }
                else if (t[i] == "resetpass" || t[i] == "reset") { np = 0; pw = 0 }
                else if (t[i] ~ /^[>#]/) { np = 0; pw++ }
                else if (t[i] ~ /^[<!]/ && pw > 0) { pw-- }
            }
            print (np ? "nopass" : (pw > 0 ? "password" : "none"))
        }' "$1" 2>/dev/null || true
}

# Gives the default user this password (as its SHA-256, the form CONFIG REWRITE
# writes) in place of the password rules its ACL line had. The rest of the line
# — the keys, channels and commands it may use — and every other user's line
# stay as they are, and the old line is kept above it as a comment. Written
# back into the same file, so its owner and mode do not change.
redis_set_default_password() {
    local conf="$1" hash new
    hash="$(printf '%s' "$2" | sha256sum | cut -d' ' -f1)"

    new="$(awk -v hash="$hash" '
        NR == FNR { if ($1 == "user" && $2 == "default") last = FNR; next }
        FNR == last {
            print "# Before the Control panel installer gave the default user a password:"
            print "# " $0
            out = "user default"
            for (i = 3; i <= NF; i++) {
                if ($i != "nopass" && $i != "resetpass" && $i !~ /^[<>#!]/) out = out " " $i
            }
            print out " resetpass #" hash
            next
        }
        { print }' "$conf" "$conf")"
    printf '%s\n' "$new" >"$conf"
}

# A Redis password counts only when Redis itself proves it. redis-cli is no
# proof on its own: when AUTH fails it prints "AUTH failed: ..." and carries on,
# and a PING then works anyway if the default user needs no password. So the
# authenticated PING must answer PONG with no AUTH failure, and a PING with no
# password must be refused — otherwise .env gets a password Redis rejects and
# every connection the panel makes fails (2026-10-18, step 14).
#
# The password goes in REDISCLI_AUTH, not -a, so it is not in the process list.
redis_password_works() {
    local out
    [[ -n "$1" ]] || return 1

    out="$(REDISCLI_AUTH="$1" redis-cli ping 2>&1)" || true
    [[ "$out" == *PONG* && "$out" != *"AUTH failed"* && "$out" != *WRONGPASS* && "$out" != *NOAUTH* ]] || return 1

    out="$(env -u REDISCLI_AUTH redis-cli ping 2>&1)" || true
    [[ "$out" == *NOAUTH* ]]
}

# The argument is only for the tests; the installer calls it with none.
# shellcheck disable=SC2120
configure_redis() {
    step "Securing Redis"

    REDIS_PASSWORD=""
    local conf="${1:-/etc/redis/redis.conf}"

    if [[ ! -f "$conf" ]]; then
        warn "redis.conf not found — the panel will fall back to database cache"
        return
    fi

    # The password Redis really has may not be the `requirepass` line. The
    # panel's Redis settings change it with CONFIG SET + CONFIG REWRITE, which
    # on current Redis writes an ACL `user default ... #<hash>` line and leaves
    # the installer's old `requirepass` behind — the ACL line wins. The panel
    # keeps the live one in its own .env, so on a re-run that is tried first,
    # then the `requirepass` line, and whichever Redis accepts is used below.
    # Using the stale one silently moved the panel's cache to the database.
    local existing panel_env="${APP_DIR}/backend/.env" from_panel=""
    existing=$(awk '/^requirepass / {print $2; exit}' "$conf" 2>/dev/null || true)
    if [[ -f "$panel_env" ]]; then
        from_panel=$(awk -F= '/^REDIS_PASSWORD=/ {sub(/^REDIS_PASSWORD=/, ""); print; exit}' "$panel_env" 2>/dev/null || true)
        from_panel=${from_panel#\"}; from_panel=${from_panel%\"}
        [[ "$from_panel" == "null" ]] && from_panel=""
    fi
    local candidates=()
    [[ -n "$from_panel" ]] && candidates+=("$from_panel")
    [[ -n "$existing" && "$existing" != "$from_panel" ]] && candidates+=("$existing")

    # `user default on nopass ...` — what CONFIG REWRITE writes for a Redis
    # that never had a password — beats `requirepass`: Redis applies the ACL
    # line after it, so the default user stays open while CONFIG GET
    # requirepass shows the new value. Appending `requirepass` there changed
    # nothing (2026-10-18). The password goes on the ACL line itself; the
    # `requirepass` line stays as well, or is added, because a re-run finds the
    # password there when the panel's .env does not have it yet.
    local acl new
    acl="$(redis_default_acl "$conf")"

    if [[ "$acl" != "nopass" ]] && [[ -n "$existing" || "$acl" == "password" ]]; then
        skip "Redis already has a password"
    else
        # An open default user with a `requirepass` line (a re-run after the
        # line was appended to no effect) gets that password.
        new="$existing"
        if [[ -z "$new" ]]; then
            # No trailing `head -c`: it closes the pipe early and SIGPIPEs tr, which
            # pipefail turns into a fatal exit 141. Over-read, then slice in bash.
            new=$(head -c 48 /dev/urandom | base64 | tr -d '/+=')
            new=${new:0:32}
            printf '\n# Added by the Control panel installer\nrequirepass %s\n' "$new" >>"$conf"
        fi

        if [[ "$acl" == "nopass" ]]; then
            redis_set_default_password "$conf" "$new"
            ok "Redis's default user had no password — it has one now"
        else
            ok "generated a Redis password"
        fi
        # The only one that can work now.
        candidates=("$new")
    fi
    REDACT+=("${candidates[@]}")

    # Bounded like v7 (operator, 2026-10-07): 256 MB. Without it Redis grows
    # until the kernel kills something, and with `noeviction` a full cache
    # makes every write fail. Left alone when the operator already set one.
    # volatile-lru, not allkeys-lru: the panel's queue is on this Redis, and a
    # queued job has no TTL, so only volatile-* can never evict one. The Redis
    # screen refuses allkeys-* for that reason, and could not be saved on a
    # fresh install while this wrote it. The panel's update moves servers that
    # still have the old line (`redis:repair-installer-policy`).
    if ! grep -qE '^maxmemory ' "$conf"; then
        printf '\n# Added by the Control panel installer\nmaxmemory 256mb\nmaxmemory-policy volatile-lru\n' >>"$conf"
        ok "Redis memory capped at 256 MB (volatile-lru)"
    fi

    run systemctl enable --now redis-server
    run systemctl restart redis-server

    # Proven, not assumed: if Redis cannot be reached with this credential the
    # panel must not be configured to depend on it, or the first request 500s
    # with NOAUTH and the screen you would fix it from is behind that failure.
    #
    # Three tries, a second apart: Ubuntu's updates may be running beside this
    # step, and their needrestart hook can restart Redis under the probe.
    local candidate attempt
    for attempt in 1 2 3; do
        for candidate in "${candidates[@]}"; do
            if redis_password_works "$candidate"; then
                REDIS_PASSWORD="$candidate"
                break 2
            fi
        done
        if (( attempt < 3 )); then
            sleep 1
        fi
    done

    if [[ -n "$REDIS_PASSWORD" ]]; then
        ok "Redis reachable"
        CACHE_STORE="redis"
    else
        warn "Redis did not accept a password the panel can use — using the database cache instead"
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

    # Through the interpreter rather than relying on composer's own shebang.
    # The `php` symlink above makes the shebang work too, but naming the binary
    # here means this step does not depend on PATH at all.
    progress_start "installing the API's PHP dependencies" composer
    run sudo -u "$APP_USER" -H "$PANEL_PHP_BIN" "${COMPOSER_BIN:-/usr/local/bin/composer}" install --no-dev --no-interaction --prefer-dist --optimize-autoloader -d "$dir"
    progress_stop
    ok "dependencies installed"

    [[ -f "${dir}/.env" ]] || cp "${dir}/.env.example" "${dir}/.env"

    local db="${dir}/database/database.sqlite"
    [[ -f "$db" ]] || : >"$db"

    write_backend_env "$dir" "$scheme" "$db"

    chown -R "${APP_USER}:${APP_USER}" "$dir"
    chmod -R 775 "${dir}/storage" "${dir}/bootstrap/cache"

    grep -q '^APP_KEY=base64:' "${dir}/.env" \
        || run sudo -u "$APP_USER" -H sh -c 'cd "$1" && exec "$2" artisan key:generate --force' -- "$dir" "${PANEL_PHP_BIN}"
    ok "application key set"

    run sudo -u "$APP_USER" -H sh -c 'cd "$1" && exec "$2" artisan migrate --force' -- "$dir" "${PANEL_PHP_BIN}"

    # The first administrator, the moment there is a table to put it in — and
    # before the frontend is built and the services start, so registration is
    # closed before anyone can open the panel. The password goes on STDIN, as
    # the central token does below, and is dropped as soon as it is used.
    if [[ -n "$PANEL_ADMIN_USERNAME" ]]; then
        run sudo -u "$APP_USER" -H sh -c 'cd "$1" && exec "$2" artisan panel:create-admin --username="$3"' \
            -- "$dir" "${PANEL_PHP_BIN}" "$PANEL_ADMIN_USERNAME" <<<"$PANEL_ADMIN_PASSWORD"
        PANEL_ADMIN_PASSWORD=""
        unset PANEL_ADMIN_PASSWORD
        ok "administrator ${PANEL_ADMIN_USERNAME} ready — registration is closed"
    fi
    run sudo -u "$APP_USER" -H sh -c 'cd "$1" && exec "$2" artisan db:seed --class=PermissionSeeder --force' -- "$dir" "${PANEL_PHP_BIN}"
    ok "database migrated and permissions seeded"

    prime_runtime_catalogues "$dir"

    # Tell the panel what we built. It can detect that nginx and PHP are here,
    # but not whether that was a deliberate `lemp` build or a box somebody
    # assembled by hand — and the difference matters to the setup page.
    run sudo -u "$APP_USER" -H sh -c 'cd "$1" && exec "$2" artisan server:record-stack "$3"' -- "$dir" "${PANEL_PHP_BIN}" "$STACK"
    ok "stack recorded as ${STACK}"

    # Central-management token. Passed in by the central panel's provisioning
    # flow when this server is being installed on behalf of an existing customer.
    # The token is stored server-side and the same value is registered with
    # central so it can call this server's API without any user session.
    #
    # Handed to artisan on STDIN, never as an argument: an argument is readable
    # by every account on the box via `ps` for as long as the command runs, and
    # run() echoes a failing command into the log. A herestring is visible to
    # neither, so nothing has to be redacted afterwards.
    #
    # The storing is a real artisan command rather than inline PHP. The previous
    # version was `tinker --execute=` inside a single-quoted `sh -c`, which
    # passed an empty token (no expansion inside single quotes, and sudo resets
    # the environment regardless), invalid PHP (`\\'settings\\'` closes the
    # quote instead of escaping), and ran `php "$2" artisan` where $2 is already
    # the PHP binary. It had never once worked.
    if [[ -n "$CENTRAL_TOKEN" ]]; then
        run sudo -u "$APP_USER" -H sh -c 'cd "$1" && exec "$2" artisan panel:central-token' \
            -- "$dir" "${PANEL_PHP_BIN}" <<<"$CENTRAL_TOKEN"
        ok "central management token stored"
    fi
}

# The API's .env, for setup_backend: the URLs, the database, the cache,
# queue and session drivers, and what the self-updater needs to know about
# this install. $1 is the backend directory, $2 the scheme, $3 the database.
write_backend_env() {
    local dir="$1" scheme="$2" db="$3"

    set_env "${dir}/.env" APP_ENV production
    set_env "${dir}/.env" APP_DEBUG false
    set_env "${dir}/.env" APP_URL "${scheme}://${API_HOST}"
    set_env "${dir}/.env" FRONTEND_URL "${scheme}://${PANEL_HOST}"
    set_env "${dir}/.env" SANCTUM_STATEFUL_DOMAINS "$PANEL_HOST"
    [[ -n "$CENTRAL_URL" ]] && set_env "${dir}/.env" CENTRAL_URL "${CENTRAL_URL%/}"
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

    # The interpreter itself, not just its version.
    #
    # The self-updater built `/usr/bin/php${PANEL_PHP_VERSION}` from the line
    # above, which is right on nginx and Apache and names nothing at all on
    # OpenLiteSpeed: that stack installs no ondrej PHP, and the panel runs on
    # LSPHP under /usr/local/lsws. Every self-update on every OLS install
    # called a binary that does not exist — so an OLS panel could not update
    # itself, and could not ship a security fix.
    #
    # This value has been computed correctly by derive_php_runtime since the
    # LSPHP work landed; it was simply never written down. Writing it removes
    # the guess on both stacks rather than only the broken one.
    set_env "${dir}/.env" PANEL_PHP_BIN "$PANEL_PHP_BIN"

    # The same version again, under the key the *sites* use. `PANEL_PHP_VERSION`
    # above is the panel's own interpreter and is read by the self-updater
    # alone; `SERVER_DEFAULT_PHP_VERSION` is what a site that names no version
    # gets — its pool, its vhost's `lsphp` path, its installer's CLI.
    #
    # This installer installs exactly one PHP, the one asked for here, and the
    # config default for sites was the literal `8.4` regardless. So
    # `PHP_VERSION=8.3 ./install.sh` produced a server with only 8.3 on it
    # where every site was created pointing at an 8.4 that was never installed:
    # on nginx a pool written into a missing `/etc/php/8.4`, on OpenLiteSpeed
    # an `lsphp84` binary that is not there and a site reported Active that
    # answers 503. Two keys because they are genuinely two decisions — a panel
    # can be updated onto a newer PHP without moving every site onto it — but
    # at install time there is only one PHP, so they start out agreeing.
    set_env "${dir}/.env" SERVER_DEFAULT_PHP_VERSION "$PHP_VERSION"
    # Which unit the update path reloads to pick up new PHP code.
    #
    # On OpenLiteSpeed there is no php-fpm at all: lshttpd spawns the panel's
    # LSPHP itself, so restarting the web server is what recycles PHP. Naming a
    # service that does not exist would make every panel update report a failed
    # step for work that was never needed.
    if [[ "$PANEL_PHP_SAPI" == "lsapi" ]]; then
        set_env "${dir}/.env" PANEL_PHP_FPM_SERVICE "lshttpd.service"
    else
        set_env "${dir}/.env" PANEL_PHP_FPM_SERVICE "${PANEL_SLUG}-fpm.service"
    fi
    set_env "${dir}/.env" PANEL_FRONTEND_SERVICE "${PANEL_SLUG}-frontend.service"
    set_env "${dir}/.env" PANEL_QUEUE_SERVICE "${PANEL_SLUG}-queue.service"
    set_env "${dir}/.env" PANEL_NODE_BIN_DIR "$(dirname "$NODE_BIN")"
    # Node is installed under fnm, not on the panel process's PATH, so a bare
    # `node` invocation (e.g. the dashboard runtime probe) fails with
    # "node: not found". Pin the absolute binary for server.node_binary.
    set_env "${dir}/.env" SERVER_NODE_BINARY "$NODE_BIN"
}

# The two catalogues the panel otherwise fills only on a daily schedule, for
# setup_backend. $1 is the backend directory.
prime_runtime_catalogues() {
    local dir="$1"

    # Prime the npm catalogue. `npm_latest` on the Node screen is read from
    # this table, and an empty table is reported as null — which the panel
    # correctly reads as "we do not know" and so keeps offering the Update
    # button on an npm that is already current. Only this command fills it and
    # it is otherwise scheduled daily, so without this a brand-new server
    # spends up to a day telling the user to update something that is current.
    #
    # Deliberately not `run`: that aborts the install on a non-zero exit, and
    # this reaches out to the npm registry. A third party being down must not
    # fail somebody's server install — the daily schedule will retry, and the
    # only cost in the meantime is the over-eager button described above.
    if sudo -u "$APP_USER" -H sh -c 'cd "$1" && exec "$2" artisan runtimes:refresh-npm' \
        -- "$dir" "${PANEL_PHP_BIN}" >>"$LOG_FILE" 2>&1; then
        ok "npm catalogue primed"
    else
        warn "npm catalogue not primed; the Node screen may offer an npm update that is not needed until the daily refresh runs"
    fi

    # Prime the runtime lifecycle catalogue, for the same reason and with the
    # same failure mode. The Node picker hides versions the project has ended
    # support for, and "ended support" is read from this table — so an empty
    # one means every dead release is offered as though it were fine. That is
    # not cosmetic: a user picked Node 21, dead since June 2024, and the
    # one-click n8n install died compiling a native module nobody publishes a
    # prebuilt binary for any more.
    #
    # Non-fatal, and not `run`, for the reason the npm block above gives: this
    # reaches out to github, and a third party being down must not fail an
    # install. Failing open is deliberate on the read side too — unknown is
    # not dead — so the only cost until the daily schedule catches up is the
    # old behaviour.
    if sudo -u "$APP_USER" -H sh -c 'cd "$1" && exec "$2" artisan runtimes:refresh-lifecycle' \
        -- "$dir" "${PANEL_PHP_BIN}" >>"$LOG_FILE" 2>&1; then
        ok "runtime lifecycle catalogue primed"
    else
        warn "runtime lifecycle catalogue not primed; end-of-life Node versions may be offered until the daily refresh runs"
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
    #
    # But do not mistake this for the thing that keeps a small box alive any
    # more. Next 16 builds with Turbopack by default, and Turbopack's working
    # set lives in a Rust heap that NODE_OPTIONS cannot reach: on a 1.5 GB
    # cgroup the kernel kills the native `MainThread` at 1.4 GB anon-rss, with
    # this cap set and V8 nowhere near it. The cap is kept because it is still
    # correct for the `--webpack` fallback and costs nothing; what actually
    # buys headroom now is configure_swap() above and the build-worker cap in
    # frontend/next.config.mjs.
    local ram_mb heap_mb
    ram_mb=$(awk '/MemTotal/ {printf "%d", $2/1024}' /proc/meminfo)
    if (( ram_mb >= 7500 )); then
        heap_mb=4096
    elif (( ram_mb >= 3500 )); then
        heap_mb=3072
    else
        # Above physical RAM on a small box, deliberately: configure_swap()
        # has already brought RAM + swap up to BUILD_MEMORY_MB by this point,
        # and a slow build that finishes beats a fast one that does not.
        heap_mb=2048
    fi

    say "     this is the slow part — a few minutes"
    frontend_deps

    # Ubuntu's first-boot updates usually run beside this step now (see
    # install_packages), and dpkg, unattended-upgrades and an occasional
    # update-initramfs take a few hundred MB of their own. So, only while
    # they run:
    #
    #   - a server below the recommended RAM waits for them and builds alone,
    #     as every install did before: beside them it could run out of memory;
    #   - a larger one builds beside them, but as the process the kernel stops
    #     first (oom_score_adj +500) — never dpkg in the middle of an upgrade;
    #   - a build killed for memory there waits for them, then builds once
    #     more, alone, as before. A second kill is explained as always.
    #
    # Nothing here touches unattended-upgrades; only our own score moves.
    local ram beside=0
    if package_manager_busy; then
        ram=$(req_ram_mb)
        if (( ram < RECOMMENDED_RAM_MB - RAM_TOLERANCE_MB )); then
            say "     Waiting for Ubuntu's updates to finish before building — building beside them could run out of memory on a server with ${ram} MB of RAM"
            wait_for_apt_lock
        else
            beside=1
        fi
    fi

    # Not through run(): when the kernel kills the build for memory, all the
    # log says is "Killed", and that needs explaining in words.
    #
    # Under a memory limit the OOM killer does not stop at the build: once it
    # has killed next-build it can go on through everything in the cgroup,
    # this installer included, and then nothing explains anything (seen live
    # under a systemd MemoryMax). So the installer makes itself the last thing
    # it would pick while the build runs, and the build goes back to the
    # score it would have had — or +500 beside Ubuntu's updates. Root may
    # lower its own score; anyone may raise.
    local since oom_before adj rc attempt
    adj=$(cat "/proc/$BASHPID/oom_score_adj" 2>/dev/null || echo 0)
    for attempt in 1 2; do
        rc=0
        since=$(date +%s)
        oom_before=$(req_oom_kills)
        echo -900 >"/proc/$BASHPID/oom_score_adj" 2>/dev/null || true
        progress_start "building the interface (a few minutes)" build
        (
            echo "$adj" >"/proc/$BASHPID/oom_score_adj" 2>/dev/null || true
            if (( beside )); then
                echo 500 >"/proc/$BASHPID/oom_score_adj" 2>/dev/null || true
            fi
            exec sudo -u "$APP_USER" -H env "PATH=${node_dir}:/usr/local/bin:/usr/bin:/bin" \
                "NODE_OPTIONS=--max-old-space-size=${heap_mb}" \
                npm --prefix "$dir" run build
        ) >>"$LOG_FILE" 2>&1 || rc=$?
        progress_stop
        echo "$adj" >"/proc/$BASHPID/oom_score_adj" 2>/dev/null || true
        if (( rc != 0 )); then
            if (( beside && attempt == 1 )) && build_was_killed "$rc" "$since" "$oom_before"; then
                say "     The build ran out of memory beside Ubuntu's updates; waiting for them, then building again"
                wait_for_apt_lock
                beside=0
                continue
            fi
            build_was_killed "$rc" "$since" "$oom_before" && die "$(build_kill_message)"
            report_failure "This command failed. Its output is in the log." "\$ npm --prefix ${dir} run build"
            exit 1
        fi
        break
    done
    ok "panel built (${heap_mb} MB build heap)"
}

# `npm ci` for the interface, skipped when node_modules already matches
# package-lock.json (the marker below): when it ran while install_packages
# waited for the package lock, or on a re-run of the installer.
frontend_deps() {
    local dir="${APP_DIR}/frontend" node_dir marker sum

    [[ -x "${NODE_BIN:-}" ]] || NODE_BIN=$(find_node_bin)
    [[ -x "${NODE_BIN:-}" ]] || die "cannot find Node ${NODE_VERSION} under ${FNM_DIR}"
    node_dir=$(dirname "$NODE_BIN")
    marker="${dir}/node_modules/.panel-ci.sha256"
    sum=$(sha256sum "${dir}/package-lock.json" 2>/dev/null | cut -c1-64 || true)

    if [[ -n "$sum" && -f "$marker" && "$(cat "$marker" 2>/dev/null)" == "$sum" ]]; then
        skip "the interface's packages (package-lock.json unchanged)"
        return 0
    fi

    progress_start "downloading the interface's packages" npm_ci
    run sudo -u "$APP_USER" -H env "PATH=${node_dir}:/usr/local/bin:/usr/bin:/bin" \
        npm --prefix "$dir" ci --no-audit --no-fund
    progress_stop

    if [[ -n "$sum" ]]; then
        printf '%s\n' "$sum" >"$marker"
        chown "${APP_USER}:${APP_USER}" "$marker"
    fi
    ok "the interface's packages are installed"
}

# Did the kernel kill the build for lack of memory? Exit 137 is SIGKILL. npm
# does not pass that on (it exits 1 and logs "signal SIGKILL"), so the log, the
# cgroup's OOM count and the kernel log are asked as well.
build_was_killed() {
    local rc="$1" since="$2" oom_before="${3:-}"
    (( rc == 137 )) && return 0
    tail -n 40 "$LOG_FILE" 2>/dev/null | grep -qE '(^|[[:space:]])Killed$|SIGKILL' && return 0
    [[ -n "$oom_before" ]] && (( $(req_oom_kills) > oom_before )) && return 0
    journalctl -k --since "@${since}" --no-pager 2>/dev/null | grep -qiE 'out of memory|oom-kill|oom_reaper' && return 0
    return 1
}

# The explanation for a build the kernel killed: the numbers, the fix, and
# that running the installer again is safe.
build_kill_message() {
    local ram swap limit
    ram=$(awk '/MemTotal/ {printf "%d", $2/1024}' /proc/meminfo)
    swap=$(req_swap_mb)
    limit=$(req_cgroup_limit_mb)
    printf '%s\n' "Building the panel ran out of memory, so the system stopped it (\"Killed\")."
    printf '     %s\n' "This server has ${ram} MB of RAM and ${swap} MB of swap. The build needs about ${BUILD_MEMORY_MB} MB of RAM and swap together."
    if [[ -n "$limit" ]] && (( limit < ram )); then
        printf '     %s\n' "This install also runs under a memory limit of ${limit} MB (a container or a systemd MemoryMax), which is what it really gets."
    fi
    printf '     %s\n' "To fix it, give the build more memory, then run this installer again:"
    printf '       %s\n' "- add swap:  sudo fallocate -l 2G /swapfile2 && sudo chmod 600 /swapfile2 && sudo mkswap /swapfile2 && sudo swapon /swapfile2"
    printf '       %s\n' "- or stop other programs that use a lot of memory, or move to a server with at least ${RECOMMENDED_RAM_MB} MB of RAM."
    printf '     %s' "Running the installer again is safe: it skips the work that is already done."
}

# ─── PHP-FPM pool ────────────────────────────────────────────────────────────

configure_fpm() {
    # Nothing to do on OpenLiteSpeed: there is no php-fpm on this stack at all.
    #
    # The dedicated master below exists for one reason -- to escape the distro
    # unit's ProtectSystem=full, which would stop the panel running `sudo
    # useradd` -- and OpenLiteSpeed's own unit has no sandboxing to escape. It
    # ships without ProtectSystem, without ProtectHome, with PrivateTmp=false
    # and with accounting off, saying so in a comment: "do not want to be
    # limited in anyway". So the problem this function solves does not exist
    # here, and lshttpd spawns the panel's LSPHP itself -- see configure_ols.
    if [[ "$PANEL_PHP_SAPI" == "lsapi" ]]; then
        skip "php-fpm (OpenLiteSpeed runs the panel on LSPHP)"
        return 0
    fi

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

    # Whoever connects to the socket has to be able to write to it, and that is
    # the web server, not the pool's own user. nginx and Apache both run as
    # www-data; OpenLiteSpeed runs as nobody:nogroup. Getting this wrong is not
    # a warning — the web server simply cannot open the socket, and every PHP
    # request answers 502 while php-fpm itself looks perfectly healthy.
    local socket_owner="www-data"
    local socket_group="www-data"
    if [[ "$WEB_SERVER" == "openlitespeed" ]]; then
        socket_owner="$OLS_USER"
        socket_group="$OLS_GROUP"
    fi

    write_panel_fpm_pool "$pool_dir" "$socket_owner" "$socket_group"

    write_panel_fpm_master "$pool_dir" "$master_conf"

    # Let a reload of the hosted-site master finish the requests it would
    # otherwise cut off. The panel reloads it on every site created or deleted
    # and every PHP setting saved, and with process_control_timeout at its
    # default of 0 each reload answered every in-flight request on every site
    # with a 502 (measured 2026-10-01). Its own file in pool.d, so the distro's
    # php-fpm.conf stays untouched; FPM reads [global] from any included file.
    # The panel writes the same file for each PHP version installed later --
    # FpmReloadGrace, which a test keeps in step with this.
    cat >"/etc/php/${PHP_VERSION}/fpm/pool.d/00.panel-global.conf" <<'GRACE'
; Managed by the panel. Lets a reload finish the requests it would otherwise cut off.
[global]
process_control_timeout = 30s
GRACE

    # The distro unit is left enabled for hosted-site pools, but the panel no
    # longer rides on it.
    run systemctl enable "php${PHP_VERSION}-fpm"
    run systemctl restart "php${PHP_VERSION}-fpm"
    run systemctl daemon-reload
    # enable + restart, not enable --now: on a re-run the pool config may have
    # changed and --now would leave the old master running with the old config.
    run systemctl enable "${PANEL_SLUG}-fpm.service"
    run systemctl restart "${PANEL_SLUG}-fpm.service"
    ok "panel pool on /run/php/${PANEL_SLUG}-fpm.sock (dedicated master ${PANEL_SLUG}-fpm.service)"
}

# For configure_fpm: the panel's pool file. $1 is the pool directory, $2 and
# $3 the socket's owner and group.
write_panel_fpm_pool() {
    local pool_dir="$1" socket_owner="$2" socket_group="$3"

    # Its own pool on its own socket, owned by the panel's user. `ondemand`
    # because a control panel is idle most of the time and a 1 GB box has
    # better uses for the memory than parked PHP workers.
    cat >"${pool_dir}/${PANEL_SLUG}.conf" <<POOL
[${PANEL_SLUG}]
user = ${APP_USER}
group = ${APP_USER}
listen = /run/php/${PANEL_SLUG}-fpm.sock
listen.owner = ${socket_owner}
listen.group = ${socket_group}
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
}

# For configure_fpm: the panel's own fpm master, its config and its unit. $1
# is the pool directory, $2 the master config file.
write_panel_fpm_master() {
    local pool_dir="$1" master_conf="$2"

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
    cat >"/etc/systemd/system/${PANEL_SLUG}-fpm.service" <<UNIT
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
}

# ─── nginx ───────────────────────────────────────────────────────────────────

configure_web_server() {
    case "$WEB_SERVER" in
        nginx)         configure_nginx ;;
        apache)        configure_apache ;;
        openlitespeed) configure_ols ;;
    esac
}

# ─── OpenLiteSpeed ───────────────────────────────────────────────────────────
#
# The config syntax and every path below were first checked on 2026-09-01
# against the openlitespeed 1.9.2 package for Ubuntu 26.04 (resolute), by
# extracting the .deb rather than trusting LiteSpeed's docs, and the installer
# has since run on real servers. Settled that way:
#
#   * `add lsapi:<name> php` takes ONE colon, matching the shipped
#     `add lsapi:lsphp php`. The `type::name` form with two colons is only for
#     a load balancer's worker list. (`fcgi` is also a valid external-app type,
#     so PHP-FPM would work too; the panel does not use it, see below.)
#   * A proxy context is `type proxy` + `handler <extprocessor name>`, per the
#     OLS-specific attribute definition.
#   * lsphp extensions are NOT missing. There are no -gd/-xml/-zip/-mbstring
#     packages because they are compiled statically into lsphp84 — its Depends
#     carry libfreetype6/libjpeg8/libpng16/libwebp7 (gd), libonig5 (mbstring)
#     and libxml2-16 (xml), and the .deb ships no .so files at all.
#
# The panel runs on LSPHP, not PHP-FPM, and one binary does both jobs.
#
# This reverses an earlier decision, and the reasoning is worth keeping because
# the danger it named is real. That note said pointing the web SAPI at lsphp84
# "would give the panel two different PHP builds with two different extension
# sets, and the failure mode is the API 500ing on a missing extension while the
# CLI that installed it works fine". Correct -- but only for moving HALF of it.
#
# So both halves moved. PANEL_PHP_BIN is lsphp for composer, artisan, the queue
# worker, cron and the web SAPI alike, and ondrej's PHP is not installed on this
# stack at all. That is one build, not two, and it removes the second PHP
# instead of adding one: an OLS box used to carry both.
#
# Two things follow, both checked rather than assumed. Every extension the panel
# needs exists for LSPHP (curl, intl, sqlite3, pgsql, mysql, redis, igbinary,
# opcache as packages; gd, xml, zip, mbstring and bcmath compiled in -- the .deb
# ships no .so at all). And `configure_fpm` has nothing to do here, because the
# dedicated master it builds exists only to escape the distro unit's
# ProtectSystem=full, and OpenLiteSpeed's own unit has no sandboxing to escape.
configure_ols() {
    step "Configuring OpenLiteSpeed"

    install_ols_packages

    prepare_ols_panel_runtime

    local conf="/usr/local/lsws/conf/httpd_config.conf"
    local vhost_dir="/usr/local/lsws/conf/vhosts/${PANEL_SLUG}"
    local api_vhost_dir="/usr/local/lsws/conf/vhosts/${PANEL_SLUG}-api"

    [[ -f "$conf" ]] || die "OpenLiteSpeed installed but ${conf} is missing — see $LOG_FILE"

    # Back up before touching the file every future site also depends on. The
    # panel does the same on every edit (see OlsSharedConfig); the installer is
    # the one write that happens before the panel exists to do it.
    [[ -f "${conf}.preinstall" ]] || run cp -f "$conf" "${conf}.preinstall"
    # And this run's own copy, which is the one restored below. .preinstall is
    # the file as OpenLiteSpeed shipped it, from the FIRST run: on a re-run the
    # live file also holds every site the panel has registered since
    # (OlsSharedConfig), and restoring .preinstall deleted them all from the
    # web server (review finding R6).
    run cp -f "$conf" "${conf}.pre-run"

    run mkdir -p "$vhost_dir" "/usr/local/lsws/conf/vhosts" "${vhost_dir}/logs"

    ensure_ols_context_paths
    ensure_ols_dynamic_response_limit "$conf"

    write_ols_vhost "$vhost_dir"

    if (( ! SINGLE_HOST )); then
        run mkdir -p "$api_vhost_dir" "${api_vhost_dir}/logs"
        write_ols_api_vhost "$api_vhost_dir"
    fi

    register_ols_panel_vhost "$conf" "$vhost_dir" "$api_vhost_dir"
    harden_ols_webadmin

    test_ols_config "$conf"

    run systemctl enable lshttpd
    run systemctl restart lshttpd
    ok "OpenLiteSpeed serving ${PANEL_HOST}"
}

# For configure_ols: the panel's LSAPI socket directory (now and after a
# reboot) and the panel's own PHP settings.
prepare_ols_panel_runtime() {
    # The panel's LSAPI socket directory, and the reason it is not /tmp/lshttpd
    # with everyone else's.
    #
    # 0750 ${OLS_USER}:${APP_USER} -- lshttpd owns it, the panel's user is the
    # group. Both identities can reach it; a hosted site's user is in neither,
    # so a compromised site cannot reach the socket the panel's privileged PHP
    # listens on, and therefore cannot speak LSAPI to it and get code execution
    # as the one user on this box with a sudoers grant. That is the whole point
    # of the directory and it survives the ownership below unchanged.
    #
    # OWNER IS THE WEB SERVER, NOT THE PANEL. This was the other way round and
    # the API returned 503 on every OpenLiteSpeed install ever made. With
    # autoStart, lshttpd does not connect to this socket -- it CREATES it, plus
    # lsphp.sock.pid beside it, and creating a file needs write on the
    # directory. As ${APP_USER}:${OLS_GROUP} the server got r-x from the group
    # and could not create either, so the external app never started:
    #
    #   [panel-lsphp]: Failed to lock pid file for [/run/panel/lsphp.sock]:
    #   Permission denied ... Can not start this external application.
    #
    # The rule is the one configure_fpm states for its socket owner -- the
    # web server has to be able to write to the socket. Under fpm that lands on
    # listen.owner, because fpm creates the socket. Under LSAPI the server
    # creates it, so it lands on the directory instead. Same rule, different
    # file, and the difference is easy to miss: it looks like a permissions
    # nicety and it is the difference between a working panel and a dead one.
    #
    # tmpfiles.d as well as mkdir: /run is a tmpfs and does not survive a
    # reboot, and a panel that comes back with no socket directory is a panel
    # that does not come back. Keep the two in step -- a mismatch is a panel
    # that works until the first reboot.
    run mkdir -p "/run/${PANEL_SLUG}"
    run chown "${OLS_USER}:${APP_USER}" "/run/${PANEL_SLUG}"
    run chmod 0750 "/run/${PANEL_SLUG}"

    # The panel's own PHP settings.
    #
    # On the other stacks these are php_admin_value lines in the panel's fpm
    # pool. There is no pool here, so they go in an ini of the panel's own and
    # LSPHP is pointed at it -- the same mechanism SitePhpIni uses for hosted
    # sites on this stack.
    #
    # Not optional. The pool comment records what happens without them: the
    # panel falls back to PHP's defaults, upload_max_filesize=2M and
    # post_max_size=8M, while the web server allows 64M and the file manager
    # advertises 50M -- so every upload over 2M is rejected by PHP before
    # Laravel ever sees the request. That bug was fixed once on fpm; moving to
    # LSAPI without carrying these would reintroduce it here.
    run mkdir -p "/etc/${PANEL_SLUG}/php"
    cat >"/etc/${PANEL_SLUG}/php/zz-panel.ini" <<PANELINI
; Managed by the installer. Overwritten on every run.
error_log = /var/log/php-${PANEL_SLUG}.log
log_errors = On
; Sized to match maxReqBodySize below, not to enable large uploads: anything
; bigger goes through the resumable chunked endpoint, which never sends more
; than one chunk per request.
upload_max_filesize = 64M
post_max_size = 64M
memory_limit = 256M
PANELINI
    run chmod 0644 "/etc/${PANEL_SLUG}/php/zz-panel.ini"

    printf 'd /run/%s 0750 %s %s -\n' "$PANEL_SLUG" "$OLS_USER" "$APP_USER" \
        >"/etc/tmpfiles.d/${PANEL_SLUG}.conf"
    run systemd-tmpfiles --create "/etc/tmpfiles.d/${PANEL_SLUG}.conf"
}

# For configure_ols: test the config it wrote; on a rejection restore the
# copy taken at the start of this run and stop. $1 is httpd_config.conf.
test_ols_config() {
    local conf="$1"

    # Tested before restarting, exactly as with nginx and Apache: a broken
    # config that reaches a restart takes the web server down, and on this box
    # that includes the panel you would use to fix it.
    #
    # `openlitespeed -t` is what OpenLiteSpeed documents for this, and the only
    # thing that does it. lswsctrl has NO config-test verb -- it takes
    # start|stop|restart|reload|status and prints its usage for anything else,
    # exiting non-zero. So `lswsctrl config_test` never tested anything: it
    # failed exactly the way a rejected config fails, and this function then
    # restored the original and aborted every single --stack=ols install.
    local test_output test_status=0
    test_output=$(/usr/local/lsws/bin/openlitespeed -t 2>&1) || test_status=$?
    printf '%s\n' "$test_output" >>"$LOG_FILE"

    if (( test_status != 0 )); then
        warn "the generated OpenLiteSpeed config failed its own test — restoring the file as it was before this run"

        # ALL of it, not the [ERROR] lines. OpenLiteSpeed fails this test on
        # [WARN] too — an unbalanced brace is reported as one — so filtering to
        # [ERROR] hid the entire reason for a failure on the one run where it
        # mattered, and cost a round trip to a real server to find that out.
        # A test whose output we cannot see is worth about as much as no test.
        printf '     openlitespeed -t exited %s:\n' "$test_status" >&2
        printf '%s\n' "$test_output" | sed 's/^/     /' >&2

        run cp -f "${conf}.pre-run" "$conf"

        # Now ask the same question of the file exactly as it was before this
        # run. If that fails too, nothing this run wrote is to blame
        # and restoring it has fixed nothing — saying "the original has been
        # restored" would be an outright lie, and the operator would go looking
        # in the wrong file.
        local base_output base_status=0
        base_output=$(/usr/local/lsws/bin/openlitespeed -t 2>&1) || base_status=$?
        printf '%s\n' "$base_output" >>"$LOG_FILE"

        if (( base_status != 0 )); then
            die "OpenLiteSpeed also rejects its configuration as it was before this
     run, so this is not something this run put in the file. Both
     tests are in $LOG_FILE. Nothing has been left modified."
        fi

        die "OpenLiteSpeed rejected the generated config; the file as it was before this run has been restored — see $LOG_FILE"
    fi
}

# The directories the vhost's static contexts point at, created before the
# config that names them.
#
# OpenLiteSpeed resolves a `context`'s `location` at config-load time and
# reports `path is not accessible` for one that does not exist. nginx and Apache
# do not care -- they resolve per request -- so this is the one stack where the
# order matters, and the order cannot change: configure_web_server() runs before
# build_frontend() on purpose, because Next inlines NEXT_PUBLIC_* at build time
# and the URLs are not final until TLS is settled. So at this point
# frontend/.next does not exist yet, and neither does the acme-challenge
# directory certbot has not yet had a reason to create.
#
# Dropping a context is not cosmetic in either case: the acme-challenge one is
# how `certbot --webroot` proves the domain, and without it issuance gets
# Laravel's 404 page and Let's Encrypt reads that as unauthorized.
#
# Ownership follows the panel account because build_frontend() writes into
# .next as that user; a root-owned .next/static would trade this failure for a
# build one. It chowns the tree again anyway, so this is belt and braces.
ensure_ols_context_paths() {
    local dirs=(
        "${APP_DIR}/backend/public/.well-known/acme-challenge"
        "${APP_DIR}/frontend/.next/static"
    )

    run mkdir -p "${dirs[@]}"
    run chown -R "${APP_USER}:${APP_USER}" \
        "${APP_DIR}/backend/public/.well-known" \
        "${APP_DIR}/frontend/.next"
}

# Lift OpenLiteSpeed's cap on dynamically generated responses.
#
# `maxDynRespSize` bounds the body of anything PHP produces, and it ships at
# 2047M. The panel serves file downloads through PHP — it must, because the
# bytes belong to the site's own Linux user and only the panel holds the
# sudoers grant to read them — so that cap is a hard ceiling on the size of any
# file the file manager can hand back. A 6.5 GB download is refused with a bare
# 413 before PHP runs at all, which reaches the browser as "this site can't be
# reached".
#
# OpenLiteSpeed only. nginx and Apache have no equivalent limit on a proxied or
# FastCGI response body, so this is one of the places the three genuinely
# differ rather than one where the config merely looks different.
#
# 1024G rather than 0: tested on a real server, `0` is read as zero and every
# download over a few hundred bytes 413s. There is no "unlimited" value.
ensure_ols_dynamic_response_limit() {
    local conf="$1"
    local want="1024G"

    if grep -qE "^[[:space:]]*maxDynRespSize[[:space:]]+${want}[[:space:]]*$" "$conf"; then
        skip "OpenLiteSpeed maxDynRespSize already ${want}"
        return 0
    fi

    if grep -qE "^[[:space:]]*maxDynRespSize" "$conf"; then
        run sed -i -E "s|^([[:space:]]*)maxDynRespSize[[:space:]]+\\S+[[:space:]]*$|\\1maxDynRespSize               ${want}|" "$conf"
    else
        # Absent rather than wrong: append it at the end of the file. The
        # server-wide directives in httpd_config.conf sit outside any block,
        # so the end of the file is as good a place as any.
        printf 'maxDynRespSize               %s\n' "$want" >>"$conf"
    fi

    ok "OpenLiteSpeed maxDynRespSize set to ${want}"
}

# The LiteSpeed apt repository, pinned rather than bootstrapped.
#
# LiteSpeed's documented one-liner is `wget -O - https://repo.litespeed.sh |
# sudo bash`, which drops its keys into /etc/apt/trusted.gpg.d/ — trusting them
# for *every* repository on the box, not just theirs. That is the old apt-key
# model and it is not a trade this installer should make on someone else's
# server. Same treatment the MongoDB source already gets: one keyring, scoped to
# one source with signed-by.
OLS_PACKAGES_DONE=0

# The LiteSpeed source only; install_packages refreshes it with the others and
# installs openlitespeed in its one list.
add_ols_repository() {
    local keyring=/usr/share/keyrings/litespeed.gpg
    local list=/etc/apt/sources.list.d/litespeed.list

    # BOTH keys, not one. LiteSpeed publish two and sign the repository with the
    # second; installing only lst_debian_repo.gpg produces
    #
    #   NO_PUBKEY 011AA62DEDA1F085
    #   E: The repository ... is not signed.
    #
    # which is what a real install hit. Their own bootstrapper installs both,
    # which is why it works and picking "the obvious one" did not.
    #
    #   lst_debian_repo.gpg -> 3F6F627083084D0E
    #   lst_repo.gpg        -> 011AA62DEDA1F085  <- signs the Release file
    #
    # Fingerprints are pinned. This file becomes a root-level apt trust anchor,
    # so "whatever that URL served" is not good enough: a key swapped in transit
    # over plain HTTP would otherwise be trusted for every package it signs.
    local -a key_urls=(
        http://rpms.litespeedtech.com/debian/lst_debian_repo.gpg
        http://rpms.litespeedtech.com/debian/lst_repo.gpg
    )
    local -a key_fprs=(
        42259994257E19EB6A91CA853F6F627083084D0E
        3E892522DB44E1B063D366C5011AA62DEDA1F085
    )

    # Rebuilt whenever the keyring on disk does not already hold every expected
    # key, not merely when the file is absent. A box that ran an earlier build
    # of this installer has a keyring with only one of the two, and `-f` alone
    # would skip past it forever — which is exactly what happened: the re-run
    # after the fix produced the identical NO_PUBKEY error, because the broken
    # file was still there and still counted as "done".
    local needs_keyring=0 fpr
    if [[ ! -f "$keyring" ]]; then
        needs_keyring=1
    else
        local have
        have="$(gpg --show-keys --with-colons "$keyring" 2>/dev/null | awk -F: '/^fpr:/ {print $10}')"
        for fpr in "${key_fprs[@]}"; do
            grep -qx "$fpr" <<<"$have" || needs_keyring=1
        done
    fi

    if (( needs_keyring )); then
        local tmpdir i url got
        tmpdir="$(mktemp -d "${RUN_DIR:-${TMPDIR:-/tmp}}/litespeed-key.XXXXXX")"

        for i in "${!key_urls[@]}"; do
            url="${key_urls[$i]}"
            fpr="${key_fprs[$i]}"

            run curl -fsSL -o "${tmpdir}/key${i}" "$url"

            got="$(gpg --show-keys --with-colons "${tmpdir}/key${i}" 2>/dev/null \
                   | awk -F: '/^fpr:/ {print $10; exit}')"

            if [[ "$got" != "$fpr" ]]; then
                rm -rf "$tmpdir"
                die "the LiteSpeed signing key from ${url} is not the expected one.
     expected ${fpr}
     got      ${got:-nothing}
     Refusing to trust it. Nothing has been added to apt."
            fi
        done

        # Concatenated through --dearmor so the result is one keyring holding
        # both keys, whichever encoding they arrive in.
        if ! cat "${tmpdir}"/key* | gpg --dearmor >"${tmpdir}/keyring" 2>/dev/null; then
            rm -rf "$tmpdir"
            die "could not build the LiteSpeed keyring — see $LOG_FILE"
        fi

        run install -m 644 "${tmpdir}/keyring" "$keyring"
        rm -rf "$tmpdir"
    fi

    # LiteSpeed publish one suite per Debian/Ubuntu codename under debian/.
    printf 'deb [signed-by=%s] http://rpms.litespeedtech.com/debian/ %s main\n' \
        "$keyring" "$OS_CODENAME" >"$list"
    ok "LiteSpeed repository added (${OS_CODENAME})"
}

# lsphp, straight after install_packages's one install (openlitespeed is in
# that list). Called twice on this stack -- once from install_packages so the
# panel has a PHP to run composer with, once from configure_ols where it has
# always lived. The second is a no-op rather than a second apt run.
install_ols_packages() {
    local lsphp="lsphp${PHP_VERSION//./}" started=$SECONDS
    # The panel's own extensions are in this list too, because on this stack the
    # panel runs on the same lsphp its sites do. curl/intl/sqlite3 are what
    # Laravel and the panel's SQLite database need; redis/igbinary back the
    # cache and queue; opcache is the difference between a usable panel and a
    # slow one.
    local core=(
        "${lsphp}" "${lsphp}-common"
        "${lsphp}-curl" "${lsphp}-intl" "${lsphp}-sqlite3"
        "${lsphp}-redis" "${lsphp}-igbinary" "${lsphp}-opcache"
    )
    # The database clients hosted sites connect with. The panel itself runs on
    # SQLite, so these are the sites' and do not stop the install (below).
    local sites=("${lsphp}-mysql" "${lsphp}-pgsql")
    # imagick and imap, as v7 installs them (operator, 2026-10-07). Optional:
    # LiteSpeed packages them from 8.2 up only. gmp needs nothing — LiteSpeed
    # compiles it in.
    local extras=("${lsphp}-imagick" "${lsphp}-imap")

    # --list: the names, for install_packages's one transaction.
    if [[ "${1:-}" == "--list" ]]; then
        printf '%s\n' "${core[@]}" "${sites[@]}" "${extras[@]}"
        return 0
    fi

    if (( OLS_PACKAGES_DONE )); then
        return 0
    fi
    OLS_PACKAGES_DONE=1

    export DEBIAN_FRONTEND=noninteractive

    # lsphp is the panel's own PHP on this stack and the hosted sites' too.
    # Installed here so the first site created does not have to wait for it,
    # and so a box that cannot provide it fails now — during an install the
    # user is watching — rather than later inside a queued job.
    #
    # Three calls, because apt fails the whole command if one name is missing:
    #
    #   * the panel's own set is fatal (review finding R7). Carrying on
    #     without it only moved the failure to composer or artisan, minutes
    #     later and far from the cause;
    #   * the sites' database clients warn. `-pgsql` alongside `-mysql`, for
    #     the same reason the PHP-FPM list above carries it: the panel can
    #     manage a PostgreSQL server, and without the client extension no site
    #     on this stack could connect to a database the panel had just made
    #     for it. LiteSpeed's repository carries both for every version it
    #     ships (8.0–8.4 on jammy and noble, verified 2026-09-10 against their
    #     Packages index);
    #   * imagick/imap warn, in a call of their own (below).
    say "     → Installing ${lsphp} and its extensions..."
    progress_start "installing ${lsphp}" apt_install
    if ! apt-get install -y "${core[@]}" >>"$LOG_FILE" 2>&1; then
        die "could not install ${lsphp} and the extensions the panel runs on (${core[*]#"${lsphp}-"}).
     Check what LiteSpeed's repository offers with: apt-cache search ${lsphp}
     The apt output is in $LOG_FILE."
    fi

    if ! apt-get install -y "${sites[@]}" >>"$LOG_FILE" 2>&1; then
        warn "could not install ${sites[*]} — PHP sites on ${lsphp} may not reach MySQL or PostgreSQL"
        warn "check with: apt-cache search ${lsphp}"
    fi

    # A call of their own: in the call above one missing name would take the
    # panel's own PHP down with it.
    if ! apt-get install -y "${extras[@]}" >>"$LOG_FILE" 2>&1; then
        warn "${lsphp} has no imagick/imap package — sites on it run without them"
    fi
    progress_stop
    ok "Installing ${lsphp} and its extensions ($((SECONDS - started))s)"
}

# The listener `map` lines, one per virtual host, newline-terminated.
#
# nginx and Apache give the API its own server block on ${API_HOST}.
# OpenLiteSpeed routes by listener `map`, and the installer only ever named
# ${PANEL_HOST} -- so on a two-host install the API hostname resolved to the box
# and matched no virtual host at all. Worse once the shipped `map Example *`
# catch-all was dropped, since that had been answering it by accident.
#
# One map per name rather than a comma-separated list on one map, because the
# two names now have two different vhosts behind them: the panel proxies to
# Next, the API serves Laravel.
ols_listener_maps() {
    printf '  map                     %s %s\n' "$PANEL_SLUG" "$PANEL_HOST"
    (( SINGLE_HOST )) || printf '  map                     %s-api %s\n' "$PANEL_SLUG" "$API_HOST"
}

# The panel's own vhconf.conf.
#
# Deliberately NOT built from the panel's php.blade.php template: that one is
# for hosted sites and runs them on lsphp with per-site suEXEC. The panel is a
# different animal — the same lsphp over an lsapi external app, plus a proxy to
# Next.
write_ols_vhost() {
    local vhost_dir="$1"
    local api_context="" front_controller="" doc_root="${APP_DIR}/frontend" index_block="" proxy_context=""

    # Single-host installs serve the API and the SPA from one name, so this one
    # vhost has to do both. Two-host installs get a separate backend vhost (see
    # write_ols_api_vhost) exactly as the Apache stack gets a second
    # <VirtualHost> -- and this one is then pure proxy, with no PHP in it at all.
    if (( SINGLE_HOST )); then
        read -r -d '' api_context <<CONF || true
extprocessor ${PANEL_SLUG}-lsphp {
  type                    lsapi
# NOT under /tmp/lshttpd, where every hosted site's socket lives.
#
# The panel's PHP holds the sudo grant. A site whose PHP could open this socket
# and speak LSAPI would get code execution as the panel's user, and sudo with
# it -- the same threat php-fpm answers by owning its socket www-data:www-data.
#
# So the panel's socket sits in a directory only two identities can reach:
# ${OLS_USER}, which owns it because lshttpd CREATES the socket here, and
# ${PANEL_SLUG} as the group, which runs the PHP behind it. A site user is in
# neither, so it cannot reach the path at all -- which holds regardless of what
# permissions lshttpd gives the socket itself, and LiteSpeed's own packaging
# chmods 0777 in places, so that is not a default worth trusting.
#
# Owner is the web server. See configure_ols for why the reverse -- which looks
# more natural, the panel owning the panel's directory -- meant the API 503'd
# on every OpenLiteSpeed install.
  address                 uds://run/${PANEL_SLUG}/lsphp.sock
  maxConns                10
  initTimeout             60
  retryTimeout            0
  persistConn             1
  respBuffer              0
  autoStart               2
  path                    ${PANEL_PHP_BIN%/php}/lsphp
# The panel's own identity. Same directive that isolates every hosted site --
# see the note in the site template -- and it matters more here, because this
# is the one user on the box with a sudoers grant. Without it OpenLiteSpeed
# would run the panel as nobody and every privileged operation would fail.
  extUser                 ${APP_USER}
  extGroup                ${APP_USER}
  env                     PHP_LSAPI_CHILDREN=10
  env                     PHP_LSAPI_MAX_REQUESTS=500
# The panel's own settings, written by configure_ols.
#
# THE LEADING COLON IS NOT A TYPO. An empty entry means "also scan the
# directory PHP was compiled with", which is where every extension's ini
# lives. Without it this REPLACES that directory and the panel loses pdo_sqlite,
# curl, redis and opcache -- which would not look like a settings problem, it
# would look like PHP had been installed wrong.
  env                     PHP_INI_SCAN_DIR=:/etc/${PANEL_SLUG}/php
  memSoftLimit            2047M
  memHardLimit            2047M
}

scripthandler {
  add                     lsapi:${PANEL_SLUG}-lsphp php
}

# 'context /api' and 'context /sanctum' alongside the catch-all proxy: OLS
# matches the most specific context, but Sanctum's CSRF route is top-level
# rather than under /api, and without its own context the SPA's very first
# request goes to Next, which 404s and login never starts. The same trap the
# nginx and Apache blocks each call out.
context /api {
  location                ${APP_DIR}/backend/public/
  allowBrowse             1
  enableScript            1
}

context /sanctum {
  location                ${APP_DIR}/backend/public/
  allowBrowse             1
  enableScript            1
}

# The rewrite below sends /api and /sanctum to /index.php, and OLS matches
# contexts again on the REWRITTEN path. /index.php is under neither context
# above, so without this one it landed in the catch-all proxy: Next got every
# API request, rendered its page by fetching /api through this same host, and
# looped until the proxy timed out -- the panel never loaded (2026-10-17).
context /index.php {
  location                ${APP_DIR}/backend/public/index.php
  allowBrowse             1
  enableScript            1
}
CONF

        # The front controller, fenced to the paths the backend owns.
        #
        # A rewrite block is a *virtual host* level rule -- OpenLiteSpeed's
        # manual says in as many words not to put document-root rules here, and
        # to use a context instead. We cannot: the context this rule would go in
        # is `/`, and in single-host mode that one is the proxy to Next. So the
        # fence is a RewriteCond on REQUEST_URI instead.
        #
        # Without it, `RewriteCond %{REQUEST_FILENAME} !-f` is true for every SPA
        # route -- /dashboard is not a file under backend/public -- so every page
        # of the panel would be rewritten to /index.php and answered by Laravel's
        # 404 instead of by Next. Apache avoids the same collision with
        # `<Location /api> ProxyPass !`, which is the same fence written the
        # other way round.
        front_controller=$(ols_front_controller '  RewriteCond %{REQUEST_URI} ^/(api|sanctum)(/|$)
')
        doc_root="${APP_DIR}/backend/public"
        read -r -d '' index_block <<'CONF' || true
index {
  useServer               0
  indexFiles              index.php
}
CONF
    fi

    read -r -d '' proxy_context <<CONF || true
extprocessor ${PANEL_SLUG}-next {
  type                    proxy
  address                 127.0.0.1:${FRONTEND_PORT}
  maxConns                20
  initTimeout             60
  retryTimeout            0
  respBuffer              0
}

# Hashed immutable assets straight off disk: Next's standalone output does
# not serve them and routing them through Node is wasted work.
context /_next/static {
  location                ${APP_DIR}/frontend/.next/static
  allowBrowse             1
  enableExpires           1
  expiresByType           *=A31536000
}

# Everything else goes to Next.
context / {
  type                    proxy
  handler                 ${PANEL_SLUG}-next
  addDefaultCharset       off
}
CONF

    # In two-host mode this vhost has no PHP in it at all -- no scripthandler,
    # no index.php, and crucially NO rewrite block. The front controller here
    # would rewrite every SPA route to /index.php, because /dashboard is not a
    # file on disk, and the panel would answer Laravel's 404 on every page. The
    # backend lives in its own vhost; nothing in this one needs a rewrite.
    cat >"${vhost_dir}/vhconf.conf" <<CONF
# Managed by the Control panel installer.
docRoot                   ${doc_root}
vhDomain                  ${PANEL_HOST}
# Matches client_max_body_size on the nginx panel vhost. OpenLiteSpeed's default
# is effectively unlimited, so this is not a 413 fix -- it is one panel
# behaving the same whichever web server serves it, and a bound on how much a
# stranger can make the panel buffer.
maxReqBodySize            64M
enableGzip                1

$(ols_log_blocks)

${index_block}

${api_context}

${proxy_context}

$(ols_acme_context)

$(ols_dotfile_context)

${front_controller}
CONF

    run chown -R lsadm:lsadm "$vhost_dir"
    ok "panel vhost written to ${vhost_dir}/vhconf.conf"
}

# The backend on its own hostname, two-host installs only.
#
# nginx and Apache both do this: ${API_HOST} gets its own server block serving
# the whole of backend/public, and ${PANEL_HOST} gets one that only proxies to
# Next. OpenLiteSpeed used to be the odd one out, running both roles through a
# single vhost -- which left the API hostname mapped to nothing at all, and
# sent ${API_HOST}/docs/api-reference to Next.
#
# Splitting them also removes the rewrite collision for free: with no proxy
# context in this vhost, the front-controller rule can be a plain virtual-host
# rewrite, which is the shape OpenLiteSpeed documents for a PHP application.
write_ols_api_vhost() {
    local vhost_dir="$1"

    cat >"${vhost_dir}/vhconf.conf" <<CONF
# Managed by the Control panel installer.
docRoot                   ${APP_DIR}/backend/public
vhDomain                  ${API_HOST}
# Same bound as the panel vhost. This is the one that actually receives
# uploads in a two-host install, so leaving it off here would be the half that
# mattered.
maxReqBodySize            64M
enableGzip                1

$(ols_log_blocks)

index {
  useServer               0
  indexFiles              index.php
}

extprocessor ${PANEL_SLUG}-lsphp {
  type                    lsapi
# NOT under /tmp/lshttpd, where every hosted site's socket lives.
#
# The panel's PHP holds the sudo grant. A site whose PHP could open this socket
# and speak LSAPI would get code execution as the panel's user, and sudo with
# it -- the same threat php-fpm answers by owning its socket www-data:www-data.
#
# So the panel's socket sits in a directory only two identities can reach:
# ${OLS_USER}, which owns it because lshttpd CREATES the socket here, and
# ${PANEL_SLUG} as the group, which runs the PHP behind it. A site user is in
# neither, so it cannot reach the path at all -- which holds regardless of what
# permissions lshttpd gives the socket itself, and LiteSpeed's own packaging
# chmods 0777 in places, so that is not a default worth trusting.
#
# Owner is the web server. See configure_ols for why the reverse -- which looks
# more natural, the panel owning the panel's directory -- meant the API 503'd
# on every OpenLiteSpeed install.
  address                 uds://run/${PANEL_SLUG}/lsphp.sock
  maxConns                10
  initTimeout             60
  retryTimeout            0
  persistConn             1
  respBuffer              0
  autoStart               2
  path                    ${PANEL_PHP_BIN%/php}/lsphp
# The panel's own identity. Same directive that isolates every hosted site --
# see the note in the site template -- and it matters more here, because this
# is the one user on the box with a sudoers grant. Without it OpenLiteSpeed
# would run the panel as nobody and every privileged operation would fail.
  extUser                 ${APP_USER}
  extGroup                ${APP_USER}
  env                     PHP_LSAPI_CHILDREN=10
  env                     PHP_LSAPI_MAX_REQUESTS=500
# The panel's own settings, written by configure_ols.
#
# THE LEADING COLON IS NOT A TYPO. An empty entry means "also scan the
# directory PHP was compiled with", which is where every extension's ini
# lives. Without it this REPLACES that directory and the panel loses pdo_sqlite,
# curl, redis and opcache -- which would not look like a settings problem, it
# would look like PHP had been installed wrong.
  env                     PHP_INI_SCAN_DIR=:/etc/${PANEL_SLUG}/php
  memSoftLimit            2047M
  memHardLimit            2047M
}

scripthandler {
  add                     lsapi:${PANEL_SLUG}-lsphp php
}

context / {
  location                ${APP_DIR}/backend/public/
  allowBrowse             1
  enableScript            1
}

$(ols_acme_context)

$(ols_dotfile_context)

$(ols_front_controller "")
CONF

    run chown -R lsadm:lsadm "$vhost_dir"
    ok "API vhost written to ${vhost_dir}/vhconf.conf"
}

# Blocks both vhosts share, written once so the two cannot drift apart.
ols_log_blocks() {
    cat <<CONF
errorlog \$VH_ROOT/logs/error.log {
  useServer               0
  logLevel                WARN
  rollingSize             10M
}

accesslog \$VH_ROOT/logs/access.log {
  useServer               0
  rollingSize             10M
  keepDays                30
}
CONF
}

# Served from the backend's public/ so certbot --webroot has somewhere to drop
# its token. Its own context so the front-controller rewrite cannot swallow it
# and answer Laravel's 404 page, which Let's Encrypt reads as unauthorized.
# In both vhosts, because the certificate covers both names and certbot proves
# each one separately.
ols_acme_context() {
    cat <<CONF
context /.well-known/acme-challenge {
  location                ${APP_DIR}/backend/public/.well-known/acme-challenge
  allowBrowse             1
  addDefaultCharset       off
}
CONF
}

# Version control and dotfiles must never be served: a .git inside a web root
# is a full source disclosure. "exp:" is OLS's regex context -- nginx's "~" is
# not valid here and fails the config test.
ols_dotfile_context() {
    cat <<'CONF'
context exp:^/\.(git|svn|hg|bzr|env|panel) {
  allowBrowse             0
}
CONF
}

# Laravel's front controller. $1 is an extra RewriteCond, used by the
# single-host vhost to fence the rule to the paths the backend owns.
ols_front_controller() {
    local fence="$1"

    cat <<CONF
rewrite {
  enable                  1
  # Apache's mod_rewrite, which OLS implements -- not nginx's try_files. At
  # vhost level OLS does NOT strip the leading slash before matching, so the
  # loop guard is ^/index\.php\$ rather than ^index\.php\$; without the slash
  # the rule rewrites index.php to itself.
  RewriteRule ^/index\.php\$ - [L]
${fence}  RewriteCond %{REQUEST_FILENAME} !-f
  RewriteCond %{REQUEST_FILENAME} !-d
  RewriteRule . /index.php [L]
}
CONF
}

# Register the panel's vhost in the shared httpd_config.conf.
#
# **Outside the panel-managed markers, on purpose.** OlsSharedConfig owns the
# region between its BEGIN/END comments and *rebuilds* it from what it reads —
# add, replace and remove are all "parse the region, change one entry, render it
# back". A panel vhost written inside that region would be treated as an
# ordinary site and regenerated from vhostBlock()'s generic template the first
# time any real site was provisioned, losing the lsapi and proxy contexts above
# and taking the panel down with it. Written outside, the panel's own entry is
# copied through untouched exactly like a user's hand-written config.
register_ols_panel_vhost() {
    local conf="$1" vhost_dir="$2" api_vhost_dir="$3"

    if grep -q "^virtualHost ${PANEL_SLUG} " "$conf" 2>/dev/null; then
        skip "panel vhost already registered in httpd_config.conf"
        return
    fi

    # A `map` is only legal inside a listener block, so the listener has to
    # exist. OLS ships a `Default` one; a box where it has been renamed is a
    # refusal rather than a guess at which address we are meant to answer on.
    #
    # Matched with no space required before the brace. The config OpenLiteSpeed
    # actually ships writes `listener Default{`, and an exact 'listener Default {'
    # match finds nothing — so this died on every fresh install until the shipped
    # file was read rather than assumed. The panel's own OlsSharedConfig already
    # gets this right with \s*; this is the installer catching up to it.
    if ! grep -qE '^listener[[:space:]]+Default[[:space:]]*\{' "$conf"; then
        die "no 'listener Default' block in ${conf} — cannot map the panel vhost.
     This installer does not invent a listener: which address and port the
     server should answer on is not something it can guess."
    fi

    # OpenLiteSpeed ships its Default listener on *:8088, not *:80 — the panel
    # would be registered correctly and still answer on nothing. Moved to 80,
    # and the shipped `map Example *` catch-all dropped: left in place it
    # answers for our hostname too, exactly as Ubuntu's default nginx site and
    # Apache's 000-default do, both of which this installer already removes.
    #
    # The `virtualHost Example` block goes with it, not just the map. Removing
    # only the map left the vhost defined and still validated on every config
    # load, where its document root — root-owned, as apt unpacked it — trips
    #
    #   [WARN] [config:server:vhosts:vhost:Example] Uid of
    #       /usr/local/lsws/Example/html/ is 0, smaller than minimum
    #       requirement 11, use server uid!
    #
    # on a demo site the panel has already decided should not be reachable.
    # Deleting nginx's default site and running a2dissite 000-default are the
    # same decision; this is finishing it rather than doing half of it.
    local tmp80="${conf}.panel-tmp"
    awk '
        !inexample && /^virtualHost[[:space:]]+Example[[:space:]]*\{/ {
            inexample = 1; exdepth = 0
        }
        inexample {
            n = gsub(/\{/, "{"); exdepth += n
            n = gsub(/\}/, "}"); exdepth -= n
            if (exdepth == 0) { inexample = 0 }
            next
        }
        /^listener[[:space:]]+Default[[:space:]]*\{/ { inlistener = 1 }
        inlistener && /^[[:space:]]*address[[:space:]]/ {
            sub(/address[[:space:]]+.*/, "address                  *:80")
        }
        inlistener && /^[[:space:]]*map[[:space:]]+Example[[:space:]]/ { next }
        inlistener && /\}/ { inlistener = 0 }
        { print }
    ' "$conf" >"$tmp80" || { rm -f "$tmp80"; die "could not rewrite ${conf} to move the Default listener to :80"; }
    mv -f "$tmp80" "$conf"

    cat >>"$conf" <<CONF

virtualHost ${PANEL_SLUG} {
  vhRoot                  ${vhost_dir}/
  configFile              ${vhost_dir}/vhconf.conf
  allowSymbolLink         1
  enableScript            1
  # NOT restrained: unlike a hosted site, the panel's document root
  # (${APP_DIR}/backend/public) and its Next build live outside vhRoot, and
  # "restrained 1" confines the vhost to vhRoot — every request would 404.
  restrained              0
}
CONF

    if (( ! SINGLE_HOST )); then
        cat >>"$conf" <<CONF

virtualHost ${PANEL_SLUG}-api {
  vhRoot                  ${api_vhost_dir}/
  configFile              ${api_vhost_dir}/vhconf.conf
  allowSymbolLink         1
  enableScript            1
  # Same reason as above: the document root is under ${APP_DIR}, not vhRoot.
  restrained              0
}
CONF
    fi

    # The map goes just inside the Default listener's closing brace. Inserted
    # with awk on brace depth rather than "the next line starting with }" — a
    # hand-indented closing brace would otherwise put the map in whatever block
    # came next, where it is illegal and fails the config test.
    local tmp="${conf}.panel-tmp"
    awk -v maps="$(ols_listener_maps)" '
        /^listener[[:space:]]+Default[[:space:]]*\{/ { inlistener = 1; depth = 0 }
        inlistener {
            n = gsub(/\{/, "{"); depth += n
            n = gsub(/\}/, "}"); depth -= n
            if (depth == 0) {
                printf "%s\n", maps
                inlistener = 0
            }
        }
        { print }
    ' "$conf" >"$tmp" || { rm -f "$tmp"; die "could not add the panel to the Default listener in ${conf}"; }
    mv -f "$tmp" "$conf"

    ok "panel vhost registered for ${PANEL_HOST}"
}

# OpenLiteSpeed ships a second control panel of its own, WebAdmin on :7080,
# which this binds to the server itself (S5) and gives a random password.
# The Default listener on :8088 is handled in register_ols_panel_vhost, not here.
# The argument is only for the tests; the installer calls it with none.
# shellcheck disable=SC2120
harden_ols_webadmin() {
    local admin_conf="${1:-/usr/local/lsws/admin/conf/admin_config.conf}"
    # A random WebAdmin password, printed nowhere. The operator can set their
    # own with /usr/local/lsws/admin/misc/admpass.sh; what matters here is that
    # the shipped default does not survive the install.
    local admin_pass
    admin_pass="$(head -c 18 /dev/urandom | base64 | tr -d '/+=' | head -c 20)"

    if [[ -x /usr/local/lsws/admin/misc/admpass.sh ]]; then
        if printf '%s\n%s\n%s\n' admin "$admin_pass" "$admin_pass" \
            | /usr/local/lsws/admin/misc/admpass.sh >>"$LOG_FILE" 2>&1; then
            ok "OpenLiteSpeed WebAdmin password randomised"
        else
            warn "could not set the WebAdmin password — do it with /usr/local/lsws/admin/misc/admpass.sh"
        fi
    fi

    # Bound to this server only. Leaving 7080 off the firewall allow-list
    # (configure_firewall) kept nothing out, because the installer never
    # enables ufw: on a stock Ubuntu box the console answered on every address
    # while this line said it was closed (review finding S5). Nothing of the
    # panel's uses WebAdmin; an operator reaches it through an SSH tunnel.
    # Applies at the restart configure_ols does next. The panel does the same
    # for servers installed before this (panel:ols-webadmin).
    if [[ -f "$admin_conf" ]]; then
        sed -i -E 's/^([[:space:]]*address[[:space:]]+)[^[:space:]]+:7080[[:space:]]*$/\1127.0.0.1:7080/' "$admin_conf" 2>>"$LOG_FILE" || true
    fi
    if [[ -f "$admin_conf" ]] && grep -qE '^[[:space:]]*address[[:space:]]+127\.0\.0\.1:7080$' "$admin_conf"; then
        ok "WebAdmin console on :7080 listens on this server only (ssh -L 7080:127.0.0.1:7080 to use it)"
    else
        warn "could not bind the WebAdmin console to 127.0.0.1 — :7080 may be reachable from outside; see ${admin_conf}"
    fi
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
        cat >"/etc/nginx/sites-available/${PANEL_SLUG}.conf" <<NGINX
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
        cat >"/etc/nginx/sites-available/${PANEL_SLUG}.conf" <<NGINX
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

    nginx_without_ipv6 "/etc/nginx/sites-available/${PANEL_SLUG}.conf"
    ln -sf "/etc/nginx/sites-available/${PANEL_SLUG}.conf" "/etc/nginx/sites-enabled/${PANEL_SLUG}.conf"

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
    # reload-or-restart, not reload: a stopped unit cannot be reloaded, and
    # the port check above tells the operator to stop whatever holds :80 --
    # which on a re-install is this very web server. Following that advice
    # then killed the install here. Both outcomes are wanted: reload if it is
    # running, start it if it is not.
    run systemctl reload-or-restart nginx
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

    # Without a global ServerName every `apachectl configtest` -- the panel runs
    # one before each reload and shows its output on the Services screen --
    # opens with "AH00558: Could not reliably determine the server's fully
    # qualified domain name". Harmless, and noise in every test. Every vhost
    # the panel writes names itself, so this is only the fallback's name.
    printf 'ServerName %s\n' "$(hostname)" >"/etc/apache2/conf-available/${PANEL_SLUG}-servername.conf"
    run a2enconf "${PANEL_SLUG}-servername"

    write_apache_panel_site

    run a2ensite "${PANEL_SLUG}"

    # Tested before reloading, exactly as with nginx: a broken config that reaches
    # a reload takes the web server down, and on this box that includes the panel
    # you would use to fix it.
    if ! apache2ctl configtest >>"$LOG_FILE" 2>&1; then
        die "the generated Apache config failed its own test — see $LOG_FILE"
    fi

    # Ubuntu 26.04's apache2.service mounts /home read-only for Apache
    # (ProtectHome=read-only). Every site the panel creates lives in its owner's
    # home and logs to <site>/logs, which the root master opens at start -- so
    # the first site's reload failed with "Read-only file system: could not
    # open error log file" and took Apache down, the panel included. Found on
    # the 26.04 test server. ReadWritePaths=/home does not lift it (measured);
    # ProtectHome=no does. The panel's ApacheDriver writes the same drop-in on
    # servers installed before this.
    mkdir -p /etc/systemd/system/apache2.service.d
    printf '[Service]\nProtectHome=no\n' >"/etc/systemd/system/apache2.service.d/${PANEL_SLUG}-site-logs.conf"
    run systemctl daemon-reload

    run systemctl enable apache2
    # restart, not reload: a running Apache keeps the sandbox it started with,
    # and a stopped one (the installer's own port-80 advice stops it) cannot be
    # reloaded at all.
    run systemctl restart apache2
    ok "Apache serving ${PANEL_HOST}"
}

# For configure_apache: the panel's site file, with one vhost or two
# (SINGLE_HOST).
write_apache_panel_site() {
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

    cat >"/etc/systemd/system/${PANEL_SLUG}-frontend.service" <<UNIT
[Unit]
Description=Control panel web interface (Next.js)
After=network.target

[Service]
Type=simple
User=${APP_USER}
Group=${APP_USER}
WorkingDirectory=${APP_DIR}/frontend
# Next's standalone output does not serve its own static assets or public files;
# they are copied in beside the server it does run. Only public/ is optional: a
# failed copy of the assets fails the start rather than serving pages with no
# CSS or JS (review finding R8). Must match FrontendUnit::ASSET_COPY.
ExecStartPre=/bin/sh -c 'mkdir -p .next/standalone/.next && rm -rf .next/standalone/.next/static .next/standalone/public && cp -a .next/static .next/standalone/.next/static && if [ -d public ]; then cp -a public .next/standalone/public; fi'
ExecStart=${NODE_BIN} ${APP_DIR}/frontend/.next/standalone/server.js
Restart=always
RestartSec=5
Environment=PORT=${FRONTEND_PORT}
# Loopback only: every web server above proxies to 127.0.0.1, and Next binds
# 0.0.0.0 unless told otherwise (bug #15).
Environment=HOSTNAME=127.0.0.1
Environment=NODE_ENV=production
# This account's sudo grant is root-equivalent (see configure_sudoers) and the
# interface never uses it, so a code-execution bug in Next or a dependency
# must not be one 'sudo -n sh' away from root. Blocks setuid for this unit only:
# the queue worker and PHP need the grant and do not get this line. Kept to
# the one setting with no kernel namespace behind it -- PrivateTmp and
# ProtectSystem need mount namespaces, which some containers refuse, and a
# unit that cannot start is a panel with no interface. Must match
# FrontendUnit::NO_NEW_PRIVILEGES.
NoNewPrivileges=yes
StandardOutput=journal
StandardError=journal

[Install]
WantedBy=multi-user.target
UNIT

    # One worker. Server operations are sequential by nature — two workers
    # racing to change the same server is a failure mode, not throughput.
    cat >"/etc/systemd/system/${PANEL_SLUG}-queue.service" <<UNIT
[Unit]
Description=Control panel queue worker
After=network.target

[Service]
Type=simple
User=${APP_USER}
Group=${APP_USER}
WorkingDirectory=${backend}
# Reads 'high' before 'default': still one job at a time, but a certificate
# waits for the job that is running rather than for every job queued. These
# are the ONLY queues drained: a job sent anywhere else is accepted, stored
# and never run -- no error, no failed_jobs row. Backups shipped that way and
# never once executed on a real install. Must match QueueWorker::QUEUES.
# No signal support means no graceful drain: fail before accepting any job.
# Must match QueueWorkerUnit::SIGNAL_CHECK and use the worker's interpreter.
ExecStartPre=${PANEL_PHP_BIN} -r 'exit(extension_loaded("pcntl") && function_exists("pcntl_async_signals") && function_exists("pcntl_signal") && function_exists("pcntl_alarm") ? 0 : 1);'
ExecStart=${PANEL_PHP_BIN} ${backend}/artisan queue:work --queue=high,default --sleep=3 --tries=1 --max-time=3600
Restart=always
RestartSec=5
# TERM only the main worker: Laravel finishes its synchronous accepted job,
# then exits before reserving the next. control-group would TERM sudo children
# immediately, regardless of the long timeout (RC-F04).
KillMode=mixed
KillSignal=SIGTERM
RestartKillSignal=SIGTERM
SendSIGHUP=no
# Bound the stop and kill ALL remaining cgroup processes at main exit/deadline.
# Keep the existing 1800s bound; jobs exceeding it can still be interrupted.
SendSIGKILL=yes
FinalKillSignal=SIGKILL
TimeoutStopFailureMode=kill
TimeoutStopSec=1800
StandardOutput=journal
StandardError=journal

[Install]
WantedBy=multi-user.target
UNIT

    # The scheduler tick. Without it the metrics collector never samples and the
    # disk cleaner never runs — features that look built but do nothing.
    cat >"/etc/cron.d/${PANEL_SLUG}-scheduler" <<CRON
* * * * * ${APP_USER} ${PANEL_PHP_BIN} ${backend}/artisan schedule:run >> /dev/null 2>&1
CRON
    chmod 644 "/etc/cron.d/${PANEL_SLUG}-scheduler"

    run systemctl daemon-reload
    # enable --now only *starts* a stopped unit; on a re-run it is a no-op, and
    # the already-running process keeps serving the build that build_frontend
    # just replaced underneath it. For Next that means the standalone output and
    # its client reference manifests change on disk while the old server holds
    # the old ones, and every affected route 500s with "the client reference
    # manifest for route X does not exist". Restart explicitly so a re-run
    # actually serves what it just built — the installer promises re-runs are
    # safe, so they must also be correct. Same reason the queue worker restarts:
    # it holds the old code in memory until it does.
    run systemctl enable "${PANEL_SLUG}-frontend.service"
    run systemctl enable "${PANEL_SLUG}-queue.service"
    run systemctl restart "${PANEL_SLUG}-frontend.service"
    run systemctl restart "${PANEL_SLUG}-queue.service"
    ok "panel, queue worker and scheduler running"

    keep_queue_from_needrestart

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
    # The list itself lives in backend/config/server.php, under `privilege`.
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

    # Rendered by the panel, not written here.
    #
    # This function used to carry its own copy of the binary list, which had to
    # agree with config/server.php and nothing made it. Every privilege bug the
    # panel has had was that duplication -- touch, certbot, openssl, crontab,
    # stat and mysqldump were each added to one copy and not the other, and each
    # broke a feature that looked configured. There is now one list, and this
    # asks for it.
    #
    # As APP_USER, like every other artisan call here: run as root it would
    # leave root-owned files in storage/framework under a service that is not
    # root, and the next cache write would fail.
    #
    # setup_backend has already run (see main), so vendor/ exists.
    #
    # Rendered beside the grant under a name with a dot in it, which sudo
    # skips, made 0440, validated, and only then moved over the real name --
    # what panel:sudoers itself does. Rendering straight into the real name
    # emptied a working grant on every re-run before anything was rendered,
    # and a failure then left the panel with no privileges at all. A failure
    # now leaves the grant that was there.
    local grant="/etc/sudoers.d/${PANEL_SLUG}"
    local staged="/etc/sudoers.d/${PANEL_SLUG}.new"
    rm -f "$staged"
    if ! sudo -u "$APP_USER" -H sh -c 'cd "$1" && exec "$2" artisan panel:sudoers --print' \
        -- "${APP_DIR}/backend" "${PANEL_PHP_BIN}" >"$staged" 2>>"$LOG_FILE"; then
        rm -f "$staged"
        die "could not render the sudoers grant from config/server.php"
    fi
    chmod 440 "$staged"

    # A malformed sudoers file locks everyone out of sudo, so it is validated
    # before it goes anywhere sudo reads.
    if ! visudo -cqf "$staged" >>"$LOG_FILE" 2>&1; then
        rm -f "$staged"
        die "the generated sudoers file did not validate; the existing grant is unchanged"
    fi
    mv -f "$staged" "$grant"
    ok "sudoers rule installed for ${APP_USER}"

    # The security page reads effective sshd config with `sudo -n sshd -T`, and
    # sshd refuses to run without its privilege-separation directory /run/sshd.
    # That lives on a tmpfs and is only created by ssh.service — on a
    # socket-activated ssh (Ubuntu 24.04+ default) it may be absent at rest and
    # is wiped on every reboot, so the read fails with "Missing privilege
    # separation directory". A tmpfiles rule recreates it at boot regardless.
    cat >"/etc/tmpfiles.d/${PANEL_SLUG}-sshd.conf" <<TMPFILES
d /run/sshd 0755 root root -
TMPFILES
    run systemd-tmpfiles --create "/etc/tmpfiles.d/${PANEL_SLUG}-sshd.conf"
    ok "sshd runtime directory (/run/sshd) guaranteed at boot"
}

# ─── Firewall ────────────────────────────────────────────────────────────────

# The port SSH is actually on, resolved the same way the panel resolves it
# (App\Support\SshPort): the first `Port` in a sshd_config.d drop-in, else
# the first in sshd_config itself, else 22. Asking the same question the same
# way is the point -- this used to hardcode 22 while `firewall:record-defaults`
# recorded the real port, so on a server whose SSH had been moved the panel's
# rule list and the box disagreed from the moment of install.
#
# sshd_config itself is read too because that is where people move the port by
# hand. Reading the drop-ins alone allowed 22 on a server listening on 2222,
# and on a server whose ufw is already on that is the rule which locks the
# owner out the day port 22 is closed (review finding R11).
#
# Deliberately not `sshd -T`, which is more authoritative: it needs /run/sshd
# to exist, and reading the same files as the panel keeps the two answers
# identical even where both are wrong. A drop-in is also where the panel
# *writes* a port change, and Ubuntu includes them before the rest of the main
# file, so a drop-in still wins.
#
# Arguments only for the tests; the installer calls it with none.
# shellcheck disable=SC2120
find_ssh_port() {
    local main="${1:-/etc/ssh/sshd_config}" dir="${2:-/etc/ssh/sshd_config.d}" file found
    for file in "$dir"/*.conf "$main"; do
        [[ -f "$file" ]] || continue
        found=$(grep -oPi '^\s*Port\s+\K[0-9]+' "$file" 2>/dev/null | head -1) || true
        if [[ -n "$found" ]]; then
            printf '%s\n' "$found"
            return 0
        fi
    done
    printf '22\n'
}

configure_firewall() {
    step "Checking the firewall"

    if ! command -v ufw >/dev/null 2>&1; then
        skip "ufw is not installed"
        return
    fi

    local ssh_port
    ssh_port="$(find_ssh_port)"

    # Rules are added but ufw is never enabled here. Enabling a firewall on
    # someone's server as a side effect of an install is how people lose SSH.
    run ufw allow "${ssh_port}/tcp"
    run ufw allow 80/tcp
    run ufw allow 443/tcp

    if [[ "$(ufw status 2>/dev/null)" == *inactive* ]]; then
        ok "rules added for ${ssh_port}, 80, 443 (ufw is inactive — not enabling it)"
    else
        ok "rules added for ${ssh_port}, 80, 443"
    fi

    # Tell the panel what we just allowed. Without this the firewall screen
    # reads its enabled flag live from ufw and its rule list from the database,
    # so a server whose ufw was already active showed an enabled firewall over
    # an empty table — the rules were on the box and the panel had no record of
    # them. Records only; it never runs ufw, so the "we do not enable someone's
    # firewall for them" rule above still holds. Idempotent.
    run sudo -u "$APP_USER" -H sh -c 'cd "$1" && exec "$2" artisan firewall:record-defaults' \
        -- "${APP_DIR}/backend" "${PANEL_PHP_BIN}"
    ok "rules recorded in the panel"
}

# ─── TLS ─────────────────────────────────────────────────────────────────────

# The self-signed certificate both fallbacks use when Let's Encrypt says no
# (OpenLiteSpeed's, and nginx's or Apache's): ten years, for the panel's name,
# where the vhost files already point.
make_self_signed_certificate() {
    mkdir -p "/etc/ssl/${PANEL_SLUG}"
    run openssl req -x509 -nodes -newkey rsa:2048 -days 3650 \
        -keyout "/etc/ssl/${PANEL_SLUG}/key.pem" \
        -out "/etc/ssl/${PANEL_SLUG}/cert.pem" \
        -subj "/CN=${PANEL_HOST}"
}

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
        run systemctl reload-or-restart apache2
        ok "self-signed certificate in place — browsers will warn until a real one is issued"
        TLS_STATE="self-signed"
        SCHEME="https"
    else
        # One a2dissite puts Apache back exactly as it was. Serving plain HTTP
        # beats serving nothing.
        warn "the self-signed configuration did not validate; staying on HTTP"
        run a2dissite "${PANEL_SLUG}-tls"
        if apache2ctl configtest >>"$LOG_FILE" 2>&1; then
            run systemctl reload-or-restart apache2
        else
            warn "Apache's config still fails its test without the fallback; it keeps running what it loaded — see $LOG_FILE"
        fi
        TLS_STATE="none"
        SCHEME="http"
    fi
}

# The OpenLiteSpeed half of TLS.
#
# Two things differ from the other stacks and both are structural, not cosmetic:
#
#   * There is no certbot plugin, so `--webroot` issues the certificate and
#     nothing installs it. We write the paths into the vhost ourselves.
#   * OLS binds certificates to a *listener*, not to a vhost. A site answers on
#     443 only if a secure listener exists AND the site is mapped into it — so
#     the certificate alone is not enough, which is the trap this function is
#     mostly here to avoid.
configure_tls_ols() {
    local conf="/usr/local/lsws/conf/httpd_config.conf"
    local vhconf="/usr/local/lsws/conf/vhosts/${PANEL_SLUG}/vhconf.conf"
    local cert key

    local args=(certonly --webroot -w "${APP_DIR}/backend/public"
                --non-interactive --agree-tos -d "$PANEL_HOST")
    (( SINGLE_HOST )) || args+=(-d "$API_HOST")

    if [[ -n "$ADMIN_EMAIL" ]]; then
        args+=(-m "$ADMIN_EMAIL")
    else
        args+=(--register-unsafely-without-email)
    fi

    if certbot_issue "${args[@]}"; then
        cert="/etc/letsencrypt/live/${PANEL_HOST}/fullchain.pem"
        key="/etc/letsencrypt/live/${PANEL_HOST}/privkey.pem"
        TLS_STATE="letsencrypt"
        SCHEME="https"
        ok "Let's Encrypt certificate issued"

        # Reload on renewal. certbot --nginx/--apache do that themselves;
        # certonly --webroot has no installer and reloads nothing, so the
        # renewed certificate sat on disk while lshttpd kept serving the old
        # one from memory -- an expired panel certificate a month after the
        # first renewal. The panel writes this same file, byte for byte
        # (CertbotClient::ensureRenewalHook), but only once a site certificate
        # exists; the panel's own has no row. Same name and body, so neither
        # writer undoes the other.
        local hook_dir=/etc/letsencrypt/renewal-hooks/deploy
        run install -d -m 0755 "$hook_dir"
        printf '%s\n' '#!/bin/sh' \
            '# Managed by the panel. Reloads the web server after certbot renews, so' \
            '# the new certificate is actually served rather than sitting on disk.' \
            '/usr/local/lsws/bin/lswsctrl restart' >"${hook_dir}/sv-oss-reload"
        run chmod 0755 "${hook_dir}/sv-oss-reload"
        ok "renewals reload OpenLiteSpeed"
    else
        # Same reasoning as the nginx path: nip.io shares one Let's Encrypt rate
        # limit globally, so failing here is common and must not fail the
        # install. Encrypted with a self-signed certificate beats plaintext.
        warn "could not get a Let's Encrypt certificate (see $LOG_FILE)"
        if [[ -z "$DOMAIN" ]]; then
            warn "nip.io shares one certificate rate limit globally, so this is common"
            warn "re-run later, or use --domain=your.own.domain for a reliable certificate"
        fi
        say "     falling back to a self-signed certificate so traffic is still encrypted"

        make_self_signed_certificate

        cert="/etc/ssl/${PANEL_SLUG}/cert.pem"
        key="/etc/ssl/${PANEL_SLUG}/key.pem"
        TLS_STATE="self-signed"
        SCHEME="https"
    fi

    install_ols_certificate "$conf" "$vhconf" "$cert" "$key"
}

install_ols_certificate() {
    local conf="$1" vhconf="$2" cert="$3" key="$4"
    local vhconfs=("$vhconf")

    # The API vhost needs the certificate too. It answers on ${API_HOST}, which
    # is on the same certificate, and a vhost without vhssl is served the
    # listener's default -- so the SPA's own API calls would be the ones getting
    # a name mismatch.
    (( SINGLE_HOST )) || vhconfs+=("/usr/local/lsws/conf/vhosts/${PANEL_SLUG}-api/vhconf.conf")

    # Every file this edits, as it was, so a config OpenLiteSpeed rejects is
    # taken back out below rather than left for the next restart to find. A
    # rejected config does not stop the server that is running; it stops the
    # one that starts after a reboot, and with it the panel and every site
    # (review finding R3).
    local target backups=() mapped=1
    for target in "$conf" "${vhconfs[@]}"; do
        [[ -f "$target" ]] || continue
        cp -a "$target" "${target}.pre-tls"
        backups+=("$target")
    done

    # The certificate on the vhost. Without this a second site on the box would
    # be served the panel's certificate and every visitor gets a name mismatch.
    for target in "${vhconfs[@]}"; do
        [[ -f "$target" ]] || continue
        grep -q '^vhssl {' "$target" && continue
        cat >>"$target" <<CONF

vhssl {
  keyFile                 ${key}
  certFile                ${cert}
  certChain               1
  # TLS 1.2 as the floor. OLS spells the version set as a bitmask-style list;
  # 1.0 and 1.1 are deprecated, fail PCI checks, and buy compatibility only
  # with browsers that stopped getting security updates years ago.
  sslProtocol             24
}
CONF
    done

    # The secure listener, and the panel mapped into it. Created only if absent:
    # a box that already has one has it for a reason.
    if ! grep -qE '^listener[[:space:]]+Defaultssl[[:space:]]*\{' "$conf"; then
        cat >>"$conf" <<CONF

listener Defaultssl {
  address                 *:443
  secure                  1
  keyFile                 ${key}
  certFile                ${cert}
  certChain               1
  sslProtocol             24
$(ols_listener_maps)
}
CONF
    elif ! awk '/^listener[[:space:]]+Defaultssl[[:space:]]*\{/,/^\}/' "$conf" | grep -q "map .*${PANEL_SLUG}[ -]"; then
        local tmp="${conf}.panel-tmp"
        awk -v maps="$(ols_listener_maps)" '
            /^listener[[:space:]]+Defaultssl[[:space:]]*\{/ { inlistener = 1; depth = 0 }
            inlistener {
                n = gsub(/\{/, "{"); depth += n
                n = gsub(/\}/, "}"); depth -= n
                if (depth == 0) {
                    printf "%s\n", maps
                    inlistener = 0
                }
            }
            { print }
        ' "$conf" >"$tmp" || { rm -f "$tmp"; mapped=0; warn "could not add the panel to the Defaultssl listener in ${conf}"; }
        if (( mapped )); then
            mv -f "$tmp" "$conf"
        fi
    fi

    # A failed rewrite above is handled as a rejected config: without the map
    # the certificate is on a listener that never serves the panel (review
    # finding R9; `awk … && mv` carried on as if it had worked).
    if (( ! mapped )) || ! /usr/local/lsws/bin/openlitespeed -t >>"$LOG_FILE" 2>&1; then
        for target in "${backups[@]}"; do
            mv -f "${target}.pre-tls" "$target"
        done
        warn "OpenLiteSpeed rejected the TLS config — it has been taken back out and the panel stays on HTTP"
        warn "the certificate was issued; install it by hand from ${cert}"
        SCHEME="http"
        TLS_STATE="none"
        return
    fi
    for target in "${backups[@]}"; do
        rm -f "${target}.pre-tls"
    done

    run systemctl restart lshttpd
    ok "OpenLiteSpeed serving HTTPS for ${PANEL_HOST}"
}

configure_tls() {
    step "Setting up HTTPS"

    if (( ! WANT_SSL )); then
        SCHEME="http"
        TLS_STATE="none"
        skip "--no-ssl given"
        return
    fi

    # certbot and its plugin came in with every other package (panel_packages).
    if [[ "$WEB_SERVER" == "openlitespeed" ]]; then
        configure_tls_ols
        return
    fi

    # A self-signed fallback left by an earlier run comes out first (review
    # finding R5). That run said "re-run later"; on the re-run certbot then
    # succeeded beside it, two blocks answered on 443 for the same name, and
    # the stale self-signed one could be the one served. If certbot fails
    # again, the fallback below writes it afresh. The running server keeps
    # what it loaded until certbot or the fallback reloads it.
    if [[ "$WEB_SERVER" == "apache" ]]; then
        if [[ -e "/etc/apache2/sites-enabled/${PANEL_SLUG}-tls.conf" ]]; then
            run a2dissite "${PANEL_SLUG}-tls"
        fi
        rm -f "/etc/apache2/sites-available/${PANEL_SLUG}-tls.conf"
    else
        rm -f "/etc/nginx/sites-enabled/${PANEL_SLUG}-tls.conf" "/etc/nginx/sites-available/${PANEL_SLUG}-tls.conf"
    fi

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

    if certbot_issue "${args[@]}"; then
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
    make_self_signed_certificate

    if [[ "$WEB_SERVER" == "apache" ]]; then
        self_signed_apache
        return
    fi

    # A separate file rather than appending to the port-80 config, so a re-run
    # can simply delete it, and a later `certbot --nginx` swaps the real
    # certificate into these 443 blocks. They include the very same location
    # snippets the port-80 site does — nothing is duplicated, so HTTP and HTTPS
    # cannot end up serving different things.
    #
    # TLS 1.2 as the floor, as the panel's site templates and the OpenLiteSpeed
    # path set it: left out, the block inherits nginx.conf's stock list, which
    # still names TLS 1.0 and 1.1 (review finding S6).
    local tls_conf=/etc/nginx/sites-available/${PANEL_SLUG}-tls.conf
    local http_conf=/etc/nginx/sites-available/${PANEL_SLUG}.conf
    local api_locations=/etc/nginx/snippets/${PANEL_SLUG}-api.conf
    local panel_locations=/etc/nginx/snippets/${PANEL_SLUG}-web.conf

    if (( SINGLE_HOST )); then
        cat >"$tls_conf" <<NGINX
# Self-signed fallback, written by the Control panel installer.
# For a real certificate run: certbot --nginx -d ${PANEL_HOST}
server {
    listen 443 ssl;
    listen [::]:443 ssl;
    server_name ${PANEL_HOST};
    root ${APP_DIR}/backend/public;

    ssl_certificate /etc/ssl/${PANEL_SLUG}/cert.pem;
    ssl_certificate_key /etc/ssl/${PANEL_SLUG}/key.pem;
    ssl_protocols TLSv1.2 TLSv1.3;

    include ${api_locations};
    include ${panel_locations};
}
NGINX
    else
        cat >"$tls_conf" <<NGINX
# Self-signed fallback, written by the Control panel installer.
# For a real certificate run: certbot --nginx -d ${PANEL_HOST} -d ${API_HOST}
server {
    listen 443 ssl;
    listen [::]:443 ssl;
    server_name ${API_HOST};
    root ${APP_DIR}/backend/public;
    index index.php;

    ssl_certificate /etc/ssl/${PANEL_SLUG}/cert.pem;
    ssl_certificate_key /etc/ssl/${PANEL_SLUG}/key.pem;
    ssl_protocols TLSv1.2 TLSv1.3;

    include ${api_locations};
}

server {
    listen 443 ssl;
    listen [::]:443 ssl;
    server_name ${PANEL_HOST};
    root ${APP_DIR}/frontend;

    ssl_certificate /etc/ssl/${PANEL_SLUG}/cert.pem;
    ssl_certificate_key /etc/ssl/${PANEL_SLUG}/key.pem;
    ssl_protocols TLSv1.2 TLSv1.3;

    include ${panel_locations};
}
NGINX
    fi

    nginx_without_ipv6 "$tls_conf"
    ln -sf "$tls_conf" "/etc/nginx/sites-enabled/${PANEL_SLUG}-tls.conf"

    # And plain HTTP only sends visitors to HTTPS, as `certbot --redirect` does
    # with a real certificate: before, the panel went on answering logins on
    # port 80 beside the encrypted copy (review finding S6). The ACME location
    # stays, so certbot can still prove the name later. configure_nginx writes
    # this file afresh on every run, so a re-run starts from the full site.
    cp -a "$http_conf" "${http_conf}.pre-tls"
    # $host and $request_uri are nginx's, not the shell's.
    # shellcheck disable=SC2016
    sed -i -e '/^    include /d' \
        -e 's|^\(    location /\.well-known/acme-challenge/ .*\)$|\1\n    location / { return 301 https://$host$request_uri; }|' \
        "$http_conf"

    if nginx -t >>"$LOG_FILE" 2>&1; then
        rm -f "${http_conf}.pre-tls"
        run systemctl reload-or-restart nginx
        ok "self-signed certificate in place — browsers will warn until a real one is issued"
        TLS_STATE="self-signed"
        SCHEME="https"
    else
        # Removing one symlink puts nginx back exactly as it was, which is why
        # this went in a separate file rather than being appended to the working
        # one. Serving plain HTTP beats serving nothing.
        warn "the self-signed configuration did not validate; staying on HTTP"
        rm -f "/etc/nginx/sites-enabled/${PANEL_SLUG}-tls.conf"
        mv -f "${http_conf}.pre-tls" "$http_conf"
        if nginx -t >>"$LOG_FILE" 2>&1; then
            run systemctl reload-or-restart nginx
        else
            warn "nginx's config still fails its test without the fallback; it keeps running what it loaded — see $LOG_FILE"
        fi
        TLS_STATE="none"
        SCHEME="http"
    fi
}

# certbot, with a short retry while Ubuntu's updates run beside it: their
# needrestart hook can restart the web server in the middle of the challenge.
# Once 10 s later, then once more after they let go of the package lock. Three
# tries at most, well inside Let's Encrypt's 5 failed validations per host an
# hour. Never fatal: the callers fall back to a self-signed certificate.
certbot_issue() {
    local attempt

    progress_start "getting a certificate from Let's Encrypt" certificate
    for attempt in 1 2 3; do
        if certbot "$@" >>"$LOG_FILE" 2>&1; then
            progress_stop
            return 0
        fi
        if (( attempt == 3 )) || ! package_manager_busy; then
            break
        fi
        if (( attempt == 1 )); then
            say "     certbot failed while Ubuntu's updates were running; trying again in 10 s"
            sleep 10
        else
            progress_stop
            say "     certbot failed again; waiting for Ubuntu's updates to let go, then one last try"
            wait_for_apt_lock soft || break
            progress_start "getting a certificate from Let's Encrypt" certificate
        fi
    done
    progress_stop
    return 1
}

# ─── SSH login status ────────────────────────────────────────────────────────
#
# Somebody who logged in while the install ran (a second session, or after
# their first one dropped) found a half-built server with nothing saying what it
# was doing. And once it finished, the address it printed lived only in the
# terminal that ran it. So the install keeps its progress in one small file, and
# a login script shows administrators the matching message:
#
#   installing    step N of M and its name, when it started, how to follow it
#   failed        the step, the reason, where the log is, that a re-run is safe
#   interrupted   "installing", but that installer is gone (the session closed,
#                 it was killed, the server rebooted). Worked out at login from
#                 the installer's PID and the boot id, since nobody is left to
#                 write it down.
#   installed     the addresses, version, stack, live service status, and a
#                 warning while nobody has claimed the administrator account
#
# Shown to root and the sudo/admin group only, in interactive login shells.
# Deliberately not /etc/update-motd.d: pam_motd runs those as root on every
# login with no idea who is logging in, so every site's own SSH user (on a
# white-labelled server, a reseller's customer) would get the administrator's
# view. And nothing in here can stop an install: the status is a courtesy.

# How many step() calls a real install makes, for "step N of M": the 3 before
# the dry-run gate, then main()'s, where configure_fpm skips its step on
# OpenLiteSpeed. Keep in step with main(). The login script drops the "of M"
# when a count outruns it, so a forgotten bump reads oddly rather than wrongly.
install_step_total() {
    if [[ "$PANEL_PHP_SAPI" == "lsapi" ]]; then
        echo 19
    else
        echo 20
    fi
}

# needrestart (Ubuntu 24.04 and later) restarts every service whose libraries
# an apt run replaced. When this script runs as a systemd service — how an
# automated install (Central, systemd-run) keeps going without a terminal —
# that includes this script, which then starts again from step one. On a fresh
# server the apt run is often not ours at all: Ubuntu's own unattended-upgrades
# starts within minutes of first boot and calls needrestart itself, without
# NEEDRESTART_SUSPEND. Seen three times in one install (2026-10-17).
#
# So the unit this script runs in is excluded by name for as long as it runs,
# and the exclusion is removed when the install finishes. Nothing to do from a
# terminal: needrestart restarts services, not login sessions.
NEEDRESTART_GUARD=""
# Arguments only for the tests; the installer calls it with none.
# shellcheck disable=SC2120
protect_from_needrestart() {
    local cgroup="${1:-/proc/self/cgroup}" confd="${2:-/etc/needrestart/conf.d}" unit
    unit="$(sed -n 's#^0::.*/\([^/]*\.service\)$#\1#p' "$cgroup" 2>/dev/null | head -1)"
    [[ -n "$unit" && -d "$confd" ]] || return 0
    [[ "$unit" =~ ^[A-Za-z0-9@._-]+\.service$ ]] || return 0

    NEEDRESTART_GUARD="${confd}/zz-${PANEL_SLUG}-install.conf"
    if printf '# install.sh is running as %s; removed when it finishes.\n$nrconf{override_rc}{qr(^\\Q%s\\E$)} = 0;\n' \
        "$unit" "$unit" >"$NEEDRESTART_GUARD" 2>/dev/null; then
        ok "needrestart will leave ${unit} alone until the install is done"
    else
        NEEDRESTART_GUARD=""
    fi
}

# needrestart must never restart the queue worker itself. The worker drains
# the job it is running before it stops (up to 30 minutes), and a panel job
# that installs packages is waiting for the very lock Ubuntu's
# unattended-upgrades holds while its needrestart hook waits for that restart:
# each waits for the other until the job gives up on the lock and fails. Seen
# live (2026-11-02): MariaDB installed from the panel right after the install
# said "done", while Ubuntu's first-boot updates still ran.
#
# Nothing is lost: the worker restarts itself every hour (--max-time=3600),
# which picks up upgraded libraries anyway. Every other service still restarts.
# Arguments only for the tests; the installer calls it with none.
# shellcheck disable=SC2120
keep_queue_from_needrestart() {
    local confd="${1:-/etc/needrestart/conf.d}" unit="${PANEL_SLUG}-queue.service" conf

    [[ -d "$confd" ]] || return 0
    conf="${confd}/${PANEL_SLUG}-queue.conf"
    # A literal $nrconf: it is Perl for needrestart, not shell.
    # shellcheck disable=SC2016
    if printf '# Written by the Control panel installer: needrestart leaves %s alone; it restarts itself hourly.\n$nrconf{override_rc}{qr(^\\Q%s\\E$)} = 0;\n' \
        "$unit" "$unit" >"$conf" 2>/dev/null; then
        ok "needrestart leaves the queue worker alone (it restarts itself every hour)"
    else
        warn "could not write ${conf}"
    fi
}

release_needrestart() {
    [[ -n "$NEEDRESTART_GUARD" ]] && rm -f "$NEEDRESTART_GUARD"
    NEEDRESTART_GUARD=""
}

start_login_status() {
    # Both end up inside a script every login shell sources, so plain names
    # and paths only, or no login status at all.
    if [[ ! "$PANEL_SLUG" =~ ^[a-z0-9][a-z0-9-]*$ || ! "$INSTALL_STATE_DIR" =~ ^/[A-Za-z0-9/._-]+$ ]]; then
        warn "no SSH login status: PANEL_SLUG and INSTALL_STATE_DIR must be plain names and paths"
        return 0
    fi

    STEP_TOTAL="$(install_step_total)"

    if ! { mkdir -p "$INSTALL_STATE_DIR" && chmod 0755 "$INSTALL_STATE_DIR" && write_login_script; } >>"$LOG_FILE" 2>&1; then
        warn "could not set up the SSH login status, so logins will not show this install's progress"
        return 0
    fi

    LOGIN_STATUS=1
    save_install_state installing
    ok "SSH logins by administrators show this install's progress"
}

# One line, at most 200 characters (or $2): the login script reads the file
# line by line.
one_line() {
    local s="${1//$'\r'/ }"

    s="${s//$'\n'/ }"
    printf '%s' "${s:0:${2:-200}}"
}

# BRANDING_NAME, when the operator set one: the same value the interface shows.
branding_name() {
    local v

    v="$(sed -n 's/^BRANDING_NAME=//p' "${APP_DIR}/backend/.env" 2>/dev/null | tail -n 1)"
    v="${v#\"}"
    printf '%s' "${v%\"}"
}

# The units this install runs the panel on, in the order the login shows them.
panel_units() {
    local units=""

    if [[ "$PANEL_PHP_SAPI" == "fpm" ]]; then
        units="${PANEL_SLUG}-fpm "
    fi
    units+="${PANEL_SLUG}-queue ${PANEL_SLUG}-frontend"

    case "$WEB_SERVER" in
        nginx)         units+=" nginx" ;;
        apache)        units+=" apache2" ;;
        openlitespeed) units+=" lshttpd" ;;
    esac

    if [[ "$STACK" == "docker" ]]; then
        units+=" docker"
    fi
    printf '%s' "$units"
}

# The version the panel itself reports (GET /api/basic-info), asked on this
# machine so DNS and hairpin routing cannot get in the way. Blank if the panel
# does not answer: it is shown, not relied on.
installed_version() {
    local port=443

    if [[ "$SCHEME" == "http" ]]; then
        port=80
    fi
    curl -fsSk --connect-timeout 2 --max-time 5 --resolve "${API_HOST}:${port}:127.0.0.1" \
        "${SCHEME}://${API_HOST}/api/basic-info" 2>/dev/null \
        | sed -n 's/.*"app_version":"\([^"]*\)".*/\1/p'
}

# Never fails and never prints. It runs inside step() and inside the failure
# report, so a full disk or a missing tool must neither stop the install nor
# re-enter the ERR trap. The file is replaced whole (a temp file, then a
# rename), so a login never reads half of one.
# A JSON string literal. Only what can appear in a step title, a reason or a
# URL needs escaping: backslash and quote, and any control character, which
# becomes a space (one_line has already flattened newlines).
json_str() {
    local s="$1"
    s="${s//\\/\\\\}"
    s="${s//\"/\\\"}"
    s="$(printf '%s' "$s" | tr '[:cntrl:]' ' ')"
    printf '"%s"' "$s"
}

# json_str for text that keeps its lines: newlines and tabs are escaped rather
# than flattened, any other control character still becomes a space.
json_text() {
    local s="$1"
    s="${s//\\/\\\\}"
    s="${s//\"/\\\"}"
    s="${s//$'\n'/\\n}"
    s="${s//$'\t'/\\t}"
    s="$(printf '%s' "$s" | tr '[:cntrl:]' ' ')"
    printf '"%s"' "$s"
}

# The end of the install log, for a failed report: Central shows it, so the
# person who started the install sees what went wrong without logging in.
#
# The last 40 lines, colour codes stripped, and every secret this script knows
# replaced with [redacted] before anything else is done with the text. Then at
# most 8 KB, keeping the end — that is where the error is — and only whole
# UTF-8 characters, because one cut in half makes the whole report invalid JSON.
report_log_tail() {
    [[ -r "${LOG_FILE:-}" ]] || return 0

    local text
    text="$(tail -n 40 "$LOG_FILE" 2>/dev/null | redact_log)"
    printf '%s' "$text" | tail -c 8192 | iconv -f UTF-8 -t UTF-8 -c 2>/dev/null
}

# Report this step to Central (PROGRESS_URL), signed with the central token.
#
# The same facts save_install_state writes for the SSH login screen, so the two
# can never disagree. Never the administrator password.
#
# Best effort and never fatal: Central being down must not fail or stall an
# install. A step is sent in the background — a slow Central cannot slow the
# install — while "installed" and "failed" are sent before returning, because
# they are the last thing this script does and a background request would die
# with it (the install's unit stops its whole process group). `seq` lets Central
# drop a report that arrives after a later one.
#
# The token goes in a 0600 header file read with `curl -H @file`, never on the
# command line, for the reason the token itself is read from the environment.
send_progress() {
    (( PROGRESS_READY )) || return 0
    (( DRY_RUN )) && return 0

    local state="$1" reason="${2:-}"
    local dir
    dir="$(mktemp -d "${TMPDIR:-/tmp}/panel-progress.XXXXXX" 2>/dev/null)" || return 0  # 0700

    (
        trap - ERR
        set +eu
        umask 077
        printf 'Authorization: Bearer %s\n' "$CENTRAL_TOKEN" >"${dir}/header"
        {
            printf '{"state":%s' "$(json_str "$state")"
            # Milliseconds by arithmetic, not `%3N`: Ubuntu 26.04's date
            # (uutils) ignores the width and prints all nine digits, a number
            # too large for a JavaScript client to hold exactly.
            printf ',"seq":%s' "$(( $(date +%s%N) / 1000000 ))"
            printf ',"step":%s,"total":%s' "${STEP_NO:-0}" "${STEP_TOTAL:-0}"
            printf ',"title":%s' "$(json_str "$(one_line "$CURRENT_STEP")")"
            printf ',"started_at":%s,"updated_at":%s' "${INSTALL_STARTED:-0}" "$(date +%s)"
            # What the long command of this step is doing right now (the
            # ticker's line), only when Central asked: PROGRESS_ACTIVITY=1.
            if [[ "${PROGRESS_ACTIVITY:-0}" == "1" && -n "${ACTIVITY_CODE:-}" ]]; then
                printf ',"activity":%s' "$(json_str "$(one_line "${ACTIVITY_TEXT:-}" 200)")"
                printf ',"activity_code":%s' "$(json_str "$ACTIVITY_CODE")"
                printf ',"activity_elapsed":%s' "$(( ${ACTIVITY_ELAPSED:-0} + 0 ))"
            fi
            if [[ "$state" == "failed" ]]; then
                # Longer than the login screen's line: the fresh-server stop
                # names everything it found.
                printf ',"reason":%s' "$(json_str "$(one_line "$reason" 2000)")"
                # Only a log that exists: a stop in preflight comes before
                # the log does, and Central would show a path to nothing.
                [[ -r "${LOG_FILE:-}" ]] && printf ',"log_file":%s' "$(json_str "$LOG_FILE")"
                printf ',"log_tail":%s' "$(json_text "$(report_log_tail)")"
                # Why, for a machine, when this failure has a known cause.
                if [[ -n "${FAIL_CODE:-}" ]]; then
                    printf ',"code":%s,"findings":[' "$(json_str "$FAIL_CODE")"
                    sep=""
                    for finding in "${FAIL_FINDINGS[@]}"; do
                        printf '%s%s' "$sep" "$(json_str "$(one_line "$finding" 500)")"
                        sep=","
                    done
                    printf ']'
                fi
            fi
            if [[ "$state" == "installed" ]]; then
                printf ',"name":%s' "$(json_str "$(one_line "$(branding_name)")")"
                printf ',"panel_url":%s' "$(json_str "${SCHEME}://${PANEL_HOST}")"
                printf ',"api_url":%s' "$(json_str "${SCHEME}://${API_HOST}")"
                printf ',"stack":%s,"web_server":%s' "$(json_str "$STACK")" "$(json_str "$WEB_SERVER")"
                printf ',"version":%s' "$(json_str "$(one_line "$(installed_version)")")"
                printf ',"admin_username":%s' "$(json_str "$PANEL_ADMIN_USERNAME")"
            fi
            printf '}\n'
        } >"${dir}/body"

        curl -fsS -m 5 --retry 2 --retry-delay 1 -X POST \
            -H @"${dir}/header" -H 'Content-Type: application/json' -H 'Accept: application/json' \
            --data-binary @"${dir}/body" "$PROGRESS_URL" >/dev/null 2>&1
        rm -rf "$dir"
    ) >/dev/null 2>&1 &

    local pid=$!
    if [[ "$state" == "installed" || "$state" == "failed" ]]; then
        wait "$pid" 2>/dev/null || true
    fi
    return 0
}

# The server's own note of what was installed, for whoever looks after it:
# /root/panel-details.txt, root only. Addresses, stack, version and the
# administrator's username — not the password, which is the caller's to keep.
write_details_file() {
    (( DRY_RUN )) && return 0

    local file=/root/panel-details.txt tmp
    tmp="$(mktemp /root/.panel-details.XXXXXX 2>/dev/null)" || { warn "could not write ${file}"; return 0; }

    if {
        printf '# %s — written by install.sh on %s\n' "$(one_line "$(branding_name)")" "$(date -u '+%Y-%m-%d %H:%M UTC')"
        printf 'Panel URL:      %s://%s\n' "$SCHEME" "$PANEL_HOST"
        printf 'API URL:        %s://%s\n' "$SCHEME" "$API_HOST"
        printf 'Stack:          %s\n' "$STACK"
        printf 'Web server:     %s\n' "$WEB_SERVER"
        printf 'Panel version:  %s\n' "$(one_line "$(installed_version)")"
        printf 'PHP (panel):    %s\n' "$PANEL_PHP_BIN"
        printf 'Files:          %s\n' "$APP_DIR"
        printf 'Install log:    %s\n' "$LOG_FILE"
        if [[ -n "$PANEL_ADMIN_USERNAME" ]]; then
            printf 'Administrator:  %s (created at install; the password is not stored here)\n' "$PANEL_ADMIN_USERNAME"
        else
            printf 'Administrator:  none created at install — the first account registered in the panel becomes it\n'
        fi
    } >"$tmp" && chmod 0600 "$tmp" && mv -f "$tmp" "$file"; then
        ok "details saved to ${file} (readable by root only)"
    else
        rm -f "$tmp"
        warn "could not write ${file}"
    fi
}

save_install_state() {
    send_progress "$@"
    (( LOGIN_STATUS )) || return 0

    (
        trap - ERR
        set +eu
        state="$1"
        reason="${2:-}"
        tmp="$(mktemp "${INSTALL_STATE_DIR}/.state.XXXXXX")" || exit 0

        {
            printf 'STATE=%s\n' "$state"
            printf 'STEP_NO=%s\n' "$STEP_NO"
            printf 'STEP_TOTAL=%s\n' "$STEP_TOTAL"
            printf 'STEP_TITLE=%s\n' "$(one_line "$CURRENT_STEP")"
            printf 'STARTED_AT=%s\n' "$INSTALL_STARTED"
            printf 'UPDATED_AT=%s\n' "$(date +%s)"
            # $$ is the installer itself even in this subshell. With the boot id
            # it tells the login script whether that installer is still alive.
            printf 'PID=%s\n' "$$"
            printf 'BOOT_ID=%s\n' "$(cat /proc/sys/kernel/random/boot_id 2>/dev/null)"
            printf 'LOG_FILE=%s\n' "$LOG_FILE"
            printf 'REASON=%s\n' "$(one_line "$reason")"
            printf 'FRESH_CHECK=%s\n' "${FRESH_CHECK:-}"

            if [[ "$state" == "installed" ]]; then
                printf 'NAME=%s\n' "$(one_line "$(branding_name)")"
                printf 'PANEL_URL=%s://%s\n' "$SCHEME" "$PANEL_HOST"
                printf 'API_URL=%s://%s\n' "$SCHEME" "$API_HOST"
                printf 'STACK=%s\n' "$STACK"
                printf 'WEB_SERVER=%s\n' "$WEB_SERVER"
                printf 'UNITS=%s\n' "$(panel_units)"
                printf 'APP_DIR=%s\n' "$APP_DIR"
                printf 'APP_USER=%s\n' "$APP_USER"
                printf 'PHP_BIN=%s\n' "$PANEL_PHP_BIN"
                printf 'VERSION=%s\n' "$(one_line "$(installed_version)")"
            fi
        } >"$tmp" || { rm -f "$tmp"; exit 0; }

        # Administrators only (the sudo group), as the login script shows it: a
        # failure reason can quote a command line, and a site's own SSH user
        # has no business reading it (review finding S7; it was 0644). The
        # group is best effort: without one the file stays root's alone, and
        # the login status shows to root only rather than to everybody.
        chgrp sudo "$tmp" 2>/dev/null || true
        chmod 0640 "$tmp" && mv -f "$tmp" "${INSTALL_STATE_DIR}/state" || rm -f "$tmp"
    ) >/dev/null 2>&1 || true
}

# The login script. Quoted heredoc: nothing in it is expanded here, only the
# two paths are filled in. It is POSIX sh, because /etc/profile is also read
# by dash.
write_login_script() {
    local tmp

    tmp="$(mktemp "${LOGIN_SCRIPT}.XXXXXX")" || return 1

    if ! sed -e "s|@STATE@|${INSTALL_STATE_DIR}/state|g" \
             -e "s|@OFF@|/etc/${PANEL_SLUG}/login-banner.off|g" >"$tmp" <<'LOGIN'
# Written by the control panel installer. At SSH login it shows administrators
# what the install is doing or, once it is done, where the panel is and whether
# it is healthy. Removing this file only hides that message. To hide it for every
# account without removing it:  touch @OFF@
#
# /etc/profile sources this, so it must never get in the way of a login: it runs
# in a subshell, leaves nothing behind, reads one status file, asks this
# machine's own panel one question, and gives up quietly on anything odd.

__cp_login_status() (
    f='@STATE@'
    [ -r "$f" ] && [ -t 1 ] || exit 0
    [ -e '@OFF@' ] && exit 0
    [ -e "${HOME:-/nonexistent}/.hushlogin" ] && exit 0

    # Administrators only. A site's own SSH user (on a white-labelled server, a
    # reseller's customer) gets nothing from this.
    if [ "$(id -u)" != 0 ]; then
        case " $(id -Gn 2>/dev/null) " in
            *" sudo "* | *" admin "* | *" wheel "*) ;;
            *) exit 0 ;;
        esac
    fi

    state='' step='' total='' title='' started='' updated='' pid='' boot='' log='' reason=''
    name='' panel='' api='' stack='' web='' units='' dir='' user='' php='' version=''
    # Read, never sourced: a failure reason is free text.
    while IFS= read -r line || [ -n "$line" ]; do
        value=${line#*=}
        case $line in
            STATE=*) state=$value ;;
            STEP_NO=*) step=$value ;;
            STEP_TOTAL=*) total=$value ;;
            STEP_TITLE=*) title=$value ;;
            STARTED_AT=*) started=$value ;;
            UPDATED_AT=*) updated=$value ;;
            PID=*) pid=$value ;;
            BOOT_ID=*) boot=$value ;;
            LOG_FILE=*) log=$value ;;
            REASON=*) reason=$value ;;
            NAME=*) name=$value ;;
            PANEL_URL=*) panel=$value ;;
            API_URL=*) api=$value ;;
            STACK=*) stack=$value ;;
            WEB_SERVER=*) web=$value ;;
            UNITS=*) units=$value ;;
            APP_DIR=*) dir=$value ;;
            APP_USER=*) user=$value ;;
            PHP_BIN=*) php=$value ;;
            VERSION=*) version=$value ;;
        esac
    done <"$f"
    [ -n "$state" ] || exit 0
    [ -n "$name" ] || name='Control panel'

    B='' R='' G='' Y='' N=''
    if [ "${TERM:-dumb}" != dumb ]; then
        B=$(printf '\033[1m') R=$(printf '\033[31m') G=$(printf '\033[32m')
        Y=$(printf '\033[33m') N=$(printf '\033[0m')
    fi
    case ${LC_ALL:-${LC_CTYPE:-${LANG:-}}} in
        *[Uu][Tt][Ff]-8* | *[Uu][Tt][Ff]8*) ok='✓' bad='✗' ;;
        *) ok='+' bad='x' ;;
    esac
    sudo='sudo '
    if [ "$(id -u)" = 0 ]; then sudo=; fi
    now=$(date +%s)
    case $started in '' | *[!0-9]*) started=$now ;; esac
    case $updated in '' | *[!0-9]*) updated=$now ;; esac

    clock() { date -d "@$1" '+%H:%M %Z' 2>/dev/null; }

    case $step in '' | *[!0-9]*) step= ;; esac
    case $total in '' | *[!0-9]*) total= ;; esac
    where=''
    if [ -n "$step" ]; then
        where="step $step"
        if [ -n "$total" ] && [ "$step" -le "$total" ]; then where="$where of $total"; fi
    fi
    if [ -n "$title" ]; then where="${where:+$where }($title)"; fi

    if [ "$state" = installing ]; then
        alive=''
        if [ -d /proc/1 ]; then
            # The same boot, and that PID is still a bash. Not just any live
            # PID: after a reboot the number belongs to something else.
            if [ -n "$pid" ] && [ "$boot" = "$(cat /proc/sys/kernel/random/boot_id 2>/dev/null)" ]; then
                case $( { tr '\0' ' ' <"/proc/$pid/cmdline"; } 2>/dev/null) in
                    *bash*) alive=1 ;;
                esac
            fi
        elif [ $((now - updated)) -lt 3600 ]; then
            # /proc is mounted with hidepid, so root's processes are hidden
            # from this account. A step reported within the hour will do.
            alive=1
        fi
        [ -n "$alive" ] || state=interrupted
    fi

    case $state in
        installing)
            mins=$(((now - started) / 60))
            printf '\n %s%s: installing, %s%s\n' "$B" "$name" "$where" "$N"
            printf '   Started %s, %s min ago. Follow it: %stail -f %s\n' "$(clock "$started")" "$mins" "$sudo" "$log"
            printf "   Please don't reboot the server or run apt until it finishes.\n\n"
            ;;
        failed)
            printf '\n %s%s%s: install stopped at %s, %s%s\n' "$B" "$R" "$name" "$where" "$(clock "$updated")" "$N"
            if [ -n "$reason" ]; then printf '   %s\n' "$reason"; fi
            printf '   See why: %stail -n 50 %s\n' "$sudo" "$log"
            printf '   Fix what it reports, then run the installer again. It is safe to re-run.\n\n'
            ;;
        interrupted)
            printf '\n %s%s%s: install interrupted at %s, %s%s\n' "$B" "$Y" "$name" "$where" "$(clock "$updated")" "$N"
            printf '   The installer is no longer running: its session closed, it was stopped, or the server restarted.\n'
            printf '   Run the installer again. It is safe to re-run and skips what is already done.\n\n'
            ;;
        installed)
            # One question to this machine's own panel: the live version, whether
            # anyone has registered yet, and whether the API answers at all.
            info=''
            if [ -n "$api" ] && command -v curl >/dev/null 2>&1; then
                rest=${api#*://}
                host=${rest%%/*}
                port=443
                case $api in http://*) port=80 ;; esac
                case $host in *:*) port=${host##*:} host=${host%%:*} ;; esac
                info=$(curl -fsSk --connect-timeout 1 --max-time 2 --resolve "$host:$port:127.0.0.1" \
                    "${api%/}/api/basic-info" 2>/dev/null)
            fi
            live=$(printf '%s' "$info" | sed -n 's/.*"app_version":"\([^"]*\)".*/\1/p')
            if [ -n "$live" ]; then version=$live; fi

            printf '\n %s%s%s\n' "$B" "$name" "$N"
            printf '   Panel     %s\n' "$panel"
            if [ -n "$api" ] && [ "$api" != "$panel" ]; then printf '   API       %s\n' "$api"; fi

            about=$version
            if [ -n "$stack" ]; then about="${about:+$about · }$stack stack"; fi
            if [ -n "$web" ]; then about="${about:+$about · }$web"; fi
            if [ -n "$about" ]; then printf '   Version   %s\n' "$about"; fi

            svc=''
            if [ -n "$units" ] && command -v systemctl >/dev/null 2>&1; then
                # One call for all of them; it answers one line per unit, in order.
                # shellcheck disable=SC2086
                states=$(systemctl is-active $units 2>/dev/null)
                i=0
                for u in $units; do
                    i=$((i + 1))
                    s=$(printf '%s\n' "$states" | sed -n "${i}p")
                    if [ "$s" = active ]; then
                        svc="$svc  $G$ok$N $u"
                    else
                        svc="$svc  $R$bad $u${s:+ ($s)}$N"
                    fi
                done
            fi
            if [ -n "$info" ]; then
                svc="$svc  $G$ok$N api"
            elif [ -n "$api" ]; then
                svc="$svc  $R$bad api (not answering)$N"
            fi
            if [ -n "$svc" ]; then printf '   Services%s\n' "$svc"; fi

            # Exactly how install.sh runs it. From this account's home directory,
            # which the panel account cannot enter, every check that starts a
            # process fails with "posix_spawn() failed: Permission denied".
            if [ -n "$user" ] && [ -n "$php" ] && [ -n "$dir" ]; then
                printf "   Check     sudo -u %s -H sh -c 'cd %s/backend && exec %s artisan panel:doctor'\n" "$user" "$dir" "$php"
            fi

            case $info in
                *'"registration_open":true'*)
                    printf '\n %s%s! No administrator yet.%s Open the panel and register now: the first account\n' "$B" "$Y" "$N"
                    printf '   becomes the administrator, and until then anyone who reaches it can claim it.\n'
                    ;;
            esac
            printf '\n'
            ;;
    esac
)

case $- in
    *i*) __cp_login_status ;;
esac
unset -f __cp_login_status
LOGIN
    then
        rm -f "$tmp"
        return 1
    fi

    chmod 0644 "$tmp" && mv -f "$tmp" "$LOGIN_SCRIPT"
}

# ─── Finish ──────────────────────────────────────────────────────────────────

# The command that swaps the self-signed fallback for a real certificate, for
# the web server this box runs and every name the fallback covers. It said
# `certbot --nginx -d <panel>` on every stack, which on Apache is the wrong
# plugin and in two-host mode leaves the API name out (review finding R12).
# The Apache fallback is its own site, taken out first as configure_tls does
# on a re-run; on nginx certbot puts the certificate into the fallback's 443
# blocks. OpenLiteSpeed has no certbot plugin: the installer wires the
# certificate in itself.
certificate_hint() {
    local names="-d ${PANEL_HOST}"
    (( SINGLE_HOST )) || names+=" -d ${API_HOST}"

    case "$WEB_SERVER" in
        nginx)  printf 'sudo certbot --nginx %s' "$names" ;;
        apache) printf 'sudo a2dissite %s-tls && sudo certbot --apache --redirect %s' "$PANEL_SLUG" "$names" ;;
        *)      printf 'run this installer again, with the same options' ;;
    esac
}

finish() {
    step "Finishing up"

    local backend="${APP_DIR}/backend"

    # Last, because it freezes whatever .env says at this moment. Anything that
    # edits .env after this must re-run it.
    run sudo -u "$APP_USER" -H sh -c 'cd "$1" && exec "$2" artisan config:cache' -- "$backend" "${PANEL_PHP_BIN}"
    run sudo -u "$APP_USER" -H sh -c 'cd "$1" && exec "$2" artisan route:cache' -- "$backend" "${PANEL_PHP_BIN}"
    ok "configuration cached"

    # Close the checkout to every local account but the web server's. It is
    # 755 all the way down, and config:cache has just written the APP_KEY and
    # the Redis password into a 644 file inside it -- readable by every site
    # and SSH user the panel will create. As APP_USER like every artisan call
    # here (setfacl goes through the panel's sudo grant, configured above);
    # the same command runs on every update through sites:resync. Never fatal;
    # the command reports what it did.
    run sudo -u "$APP_USER" -H sh -c 'cd "$1" && exec "$2" artisan panel:close-directory' -- "$backend" "${PANEL_PHP_BIN}"

    # The neutral answer for a domain no site claims (bug #55): without it the
    # first site created would answer every unknown name, and on
    # OpenLiteSpeed the TLS listener shows the panel's certificate. Written by
    # sites:resync, which every update runs too. Never fatal: a web server
    # that refuses it keeps its own default.
    if sudo -u "$APP_USER" -H sh -c 'cd "$1" && exec "$2" artisan sites:resync' -- "$backend" "${PANEL_PHP_BIN}" >>"$LOG_FILE" 2>&1; then
        ok "unknown domains get a neutral answer"
    else
        warn "could not set the default site for unknown domains — see $LOG_FILE"
    fi

    # Container login (RC-28): a Docker app's own system user logs straight into
    # its container. The group, the root helper, its sudo rule (visudo-checked)
    # and the sshd Match block (sshd -t before reload). After configure_sudoers,
    # because the visudo check runs through that grant. Not fatal: a failure
    # leaves sshd and sudo exactly as they were, and every update retries it.
    if sudo -u "$APP_USER" -H sh -c 'cd "$1" && exec "$2" artisan containers:sync-shell-access' -- "$backend" "${PANEL_PHP_BIN}" >>"$LOG_FILE" 2>&1; then
        ok "Docker apps' own users can log in to their containers"
    else
        warn "container login for Docker apps is not set up — see $LOG_FILE"
    fi

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
    else
        local doctor_rc=0
        progress_start "checking the installation" doctor
        sudo -u "$APP_USER" -H sh -c 'cd "$1" && exec "$2" artisan panel:doctor' -- "$backend" "${PANEL_PHP_BIN}" || doctor_rc=$?
        progress_stop
        if (( doctor_rc == 0 )); then
            ok "all checks passed"
        else
            warn "some checks failed — see above. The panel is installed but parts of it will not work until these are fixed."
        fi
    fi

    save_install_state installed
    write_details_file
    release_needrestart

    local scheme="$SCHEME"

    printf '\n%s%s The control panel is installed%s\n\n' "$BOLD" "$GREEN" "$RESET"
    printf '  Panel:   %s%s://%s%s\n' "$BOLD" "$scheme" "$PANEL_HOST" "$RESET"
    (( SINGLE_HOST )) || printf '  API:     %s://%s\n' "$scheme" "$API_HOST"
    printf '  Stack:   %s (%s)\n' "$STACK" "$WEB_SERVER"
    printf '  Log:     %s\n' "$LOG_FILE"
    printf '  Files:   %s\n' "$APP_DIR"
    (( LOGIN_STATUS )) && printf '  SSH:     administrators see this address and the panel status at every login\n'
    printf '\n'

    # Only while nobody has registered (FS-A3): a re-run on a panel with an
    # administrator told them anyone could claim the account.
    if sudo -u "$APP_USER" -H sh -c 'cd "$1" && exec "$2" artisan panel:registration-open' -- "$backend" "${PANEL_PHP_BIN}" >/dev/null 2>&1; then
        printf '  %sOpen the panel and register — the first account becomes the%s\n' "$BOLD" "$RESET"
        printf '  %sadministrator, and registration closes behind it.%s\n\n' "$BOLD" "$RESET"

        printf '  %sDo that now.%s Until you do, anyone who reaches this address can\n' "$YELLOW" "$RESET"
        printf '  claim the administrator account.\n\n'
    else
        printf '  Sign in with your administrator account.\n\n'
    fi

    if [[ "${TLS_STATE:-none}" == "self-signed" ]]; then
        printf '  Your browser will warn about the certificate. To replace it with a\n'
        printf '  real one once a rate-limit slot frees up:\n'
        printf '    %s\n\n' "$(certificate_hint)"
    fi

    printf '  Then use the setup page to install a database engine and anything\n'
    printf '  else you need.\n\n'

    # The install no longer waits for Ubuntu's first-boot updates (they run
    # beside it; see install_packages), so on a new server they can still be
    # going. Nothing to do about it but know: the panel's own installs wait
    # for them, and the setup page says so (system_updates_running).
    if ubuntu_updates_running; then
        printf '  ✅ %sPanel is ready.%s Ubuntu is still finishing its security updates in the\n' "$BOLD" "$RESET"
        printf '     background (usually a few minutes). Installing new software from the\n'
        printf "     panel may take a few extra minutes until it's done.\n\n"
        printf 'Ubuntu updates still running at the end of the install\n' >>"$LOG_FILE"
    fi
}

# ─── Run ─────────────────────────────────────────────────────────────────────

main() {
    printf '%sControl panel installer%s\n' "$BOLD" "$RESET"
    (( DRY_RUN )) && printf '%s(dry run — nothing will be changed)%s\n' "$DIM" "$RESET"

    preflight
    resolve_stack
    resolve_hostnames
    check_requirements

    if (( DRY_RUN )); then
        dry_run_plan
        exit 0
    fi

    RUN_DIR="$(mktemp -d "${TMPDIR:-/tmp}/${PANEL_SLUG}-install.XXXXXX")"
    trap on_exit EXIT

    # First, because everything after this changes the server: from here on an
    # administrator who logs in can see how far it got.
    start_login_status
    protect_from_needrestart
    configure_swap
    # Before the packages, not after: install_node() hands fnm's tree to this
    # account, and install_packages may run it early, while it waits for the
    # package lock. useradd needs no package — and run before apt, it never
    # contends with a postinst adding a user of its own for /etc/passwd's lock.
    create_user
    # Every apt operation of the install, in one block (see install_packages).
    # Nothing after it may use apt: Ubuntu's first-boot updates start right
    # behind it and hold the lock for minutes, and they run beside everything
    # below, which needs no package. InstallerAptPhaseTest holds that line.
    install_packages
    install_node
    fetch_source
    configure_redis
    configure_mail
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
