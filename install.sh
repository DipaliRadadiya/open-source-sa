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
set -euo pipefail

# Secrets are written to disk here. 077 means the group/other bits are never
# set in the first place, rather than being cleaned up afterwards.
umask 077

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
PHP_VERSION="${PHP_VERSION:-8.4}"
NODE_VERSION="${NODE_VERSION:-24}"
FRONTEND_PORT="${FRONTEND_PORT:-3100}"
FNM_DIR="/opt/fnm"
FNM_BIN="/usr/local/bin/fnm"
LOG_FILE="/var/log/${PANEL_SLUG}-install.log"

DOMAIN=""          # --domain=panel.example.com  (skips nip.io entirely)
ADMIN_EMAIL=""     # --email= for Let's Encrypt expiry notices
WANT_SSL=1         # --no-ssl
SCHEME="https"     # settled by configure_tls before any URL is written
DRY_RUN=0          # --dry-run

# ─── Output ──────────────────────────────────────────────────────────────────

if [[ -t 1 ]]; then
    BOLD=$'\033[1m'; DIM=$'\033[2m'; RED=$'\033[31m'
    GREEN=$'\033[32m'; YELLOW=$'\033[33m'; RESET=$'\033[0m'
else
    BOLD=""; DIM=""; RED=""; GREEN=""; YELLOW=""; RESET=""
fi

STEP_NO=0
say()  { printf '%s\n' "$*"; }
step() { STEP_NO=$((STEP_NO + 1)); printf '\n%s[%02d]%s %s%s%s\n' "$DIM" "$STEP_NO" "$RESET" "$BOLD" "$*" "$RESET"; }
ok()   { printf '     %s✓%s %s\n' "$GREEN" "$RESET" "$*"; }
skip() { printf '     %s·%s %s %s(already done)%s\n' "$DIM" "$RESET" "$*" "$DIM" "$RESET"; }
warn() { printf '     %s!%s %s\n' "$YELLOW" "$RESET" "$*" >&2; }
die()  { printf '\n%sInstall stopped:%s %s\n\n' "$RED" "$RESET" "$*" >&2; exit 1; }

# Runs a command, sending its noise to the log. The log path is printed on
# failure, because "apt failed" with no output is not a diagnosis.
run() {
    if (( DRY_RUN )); then
        printf '     %s$ %s%s\n' "$DIM" "$*" "$RESET"
        return 0
    fi
    if ! "$@" >>"$LOG_FILE" 2>&1; then
        die "command failed: $*
     Last lines of $LOG_FILE:
$(tail -n 15 "$LOG_FILE" 2>/dev/null | sed 's/^/       /')"
    fi
}

# ─── Arguments ───────────────────────────────────────────────────────────────

for arg in "$@"; do
    case "$arg" in
        --domain=*) DOMAIN="${arg#*=}" ;;
        --email=*)  ADMIN_EMAIL="${arg#*=}" ;;
        --branch=*) REPO_BRANCH="${arg#*=}" ;;
        --repo=*)   REPO_URL="${arg#*=}" ;;
        --no-ssl)   WANT_SSL=0 ;;
        --dry-run)  DRY_RUN=1 ;;
        -h|--help)
            cat <<'USAGE'
Control panel installer

  sudo bash install.sh [options]

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

    if [[ "${ID:-}" != "ubuntu" ]] || [[ ! "${VERSION_ID:-}" =~ ^(22\.04|24\.04)$ ]]; then
        die "unsupported OS: ${PRETTY_NAME:-unknown}
     Supported: Ubuntu 22.04 LTS, Ubuntu 24.04 LTS.
     Nothing has been changed on this server."
    fi
    ok "${PRETTY_NAME}"

    case "$(uname -m)" in
        x86_64|aarch64) ok "architecture $(uname -m)" ;;
        *) die "unsupported architecture: $(uname -m) (need x86_64 or aarch64)" ;;
    esac

    # Ports, before nginx is installed. `ss` ships with iproute2 on both
    # supported releases. An nginx that is already ours is not a conflict.
    local port
    for port in 80 443; do
        if ss -ltnH "sport = :${port}" 2>/dev/null | grep -q .; then
            if [[ -f /etc/nginx/sites-enabled/${PANEL_SLUG}.conf ]]; then
                skip "port ${port} is in use by our own nginx"
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
        ip=$(ip -4 route get 1.1.1.1 2>/dev/null | awk '{for(i=1;i<=NF;i++) if($i=="src"){print $(i+1); exit}}')
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

install_packages() {
    step "Installing packages"

    export DEBIAN_FRONTEND=noninteractive

    run apt-get update -qq
    run apt-get install -y software-properties-common curl git unzip ca-certificates gnupg

    # The panel offers PHP versions by asking apt what it can install. Without
    # this repository that answer is "only the one Ubuntu ships", and the whole
    # multi-version PHP feature is inert. It is a prerequisite of the panel
    # working, not a preference.
    # A glob inside [[ -f ]] is not expanded, so it is matched with compgen —
    # the naive version silently always thought the repository was missing.
    if ! compgen -G "/etc/apt/sources.list.d/ondrej*php*" >/dev/null; then
        run add-apt-repository -y ppa:ondrej/php
        run apt-get update -qq
        ok "ondrej/php repository added"
    else
        skip "ondrej/php repository"
    fi

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
    run apt-get install -y nginx redis-server sqlite3 "${php_pkgs[@]}"
    ok "nginx, redis, sqlite, PHP ${PHP_VERSION}"

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
    NODE_BIN=$(find "${FNM_DIR}/node-versions" -maxdepth 5 -type f -name node -path "*v${NODE_VERSION}.*" 2>/dev/null | head -n1)
    [[ -x "${NODE_BIN:-}" ]] || die "installed Node ${NODE_VERSION} but cannot find its binary under ${FNM_DIR}"
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
}

# ─── Source ──────────────────────────────────────────────────────────────────

fetch_source() {
    step "Fetching the panel"

    if [[ -d "${APP_DIR}/.git" ]]; then
        run git -C "$APP_DIR" fetch --depth 1 origin "$REPO_BRANCH"
        run git -C "$APP_DIR" reset --hard "origin/${REPO_BRANCH}"
        ok "updated to the latest ${REPO_BRANCH}"
    else
        mkdir -p "$(dirname "$APP_DIR")"
        run git clone --depth 1 --branch "$REPO_BRANCH" "$REPO_URL" "$APP_DIR"
        ok "cloned into ${APP_DIR}"
    fi

    # Before composer and npm run, not after. Both run as ${APP_USER} and both
    # write into a tree git just created as root — without this they fail on
    # the first write to vendor/ or node_modules/.
    run chown -R "${APP_USER}:${APP_USER}" "$APP_DIR"
    ok "owned by ${APP_USER}"
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
        REDIS_PASSWORD=$(head -c 24 /dev/urandom | base64 | tr -d '/+=' | head -c 32)
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
    set_env "${dir}/.env" QUEUE_CONNECTION database
    set_env "${dir}/.env" SESSION_DRIVER database
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

    chown -R "${APP_USER}:${APP_USER}" "$dir"
    chmod -R 775 "${dir}/storage" "${dir}/bootstrap/cache"

    grep -q '^APP_KEY=base64:' "${dir}/.env" \
        || run sudo -u "$APP_USER" -H "/usr/bin/php${PHP_VERSION}" "${dir}/artisan" key:generate --force
    ok "application key set"

    run sudo -u "$APP_USER" -H "/usr/bin/php${PHP_VERSION}" "${dir}/artisan" migrate --force
    run sudo -u "$APP_USER" -H "/usr/bin/php${PHP_VERSION}" "${dir}/artisan" db:seed --class=PermissionSeeder --force
    ok "database migrated and permissions seeded"

    # Tell the panel what we built. It can detect that nginx and PHP are here,
    # but not whether that was a deliberate `lemp` build or a box somebody
    # assembled by hand — and the difference matters to the setup page.
    run sudo -u "$APP_USER" -H "/usr/bin/php${PHP_VERSION}" "${dir}/artisan" server:record-stack lemp
    ok "stack recorded as lemp"
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

    say "     this is the slow part — a few minutes"
    run sudo -u "$APP_USER" -H env "PATH=${node_dir}:/usr/local/bin:/usr/bin:/bin" \
        npm --prefix "$dir" ci --no-audit --no-fund
    run sudo -u "$APP_USER" -H env "PATH=${node_dir}:/usr/local/bin:/usr/bin:/bin" \
        npm --prefix "$dir" run build
    ok "panel built"
}

# ─── PHP-FPM pool ────────────────────────────────────────────────────────────

configure_fpm() {
    step "Configuring PHP-FPM"

    # Its own pool on its own socket, owned by the panel's user. `ondemand`
    # because a control panel is idle most of the time and a 1 GB box has
    # better uses for the memory than parked PHP workers.
    cat >"/etc/php/${PHP_VERSION}/fpm/pool.d/${PANEL_SLUG}.conf" <<POOL
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
POOL

    run systemctl enable "php${PHP_VERSION}-fpm"
    run systemctl restart "php${PHP_VERSION}-fpm"
    ok "pool listening on /run/php/${PANEL_SLUG}-fpm.sock"
}

# ─── nginx ───────────────────────────────────────────────────────────────────

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
    (( SINGLE_HOST )) && api_prefix="^~ /api"

    cat >"$api_locations" <<NGINX
# Managed by the Control panel installer.
client_max_body_size 64M;

location ${api_prefix} {
    try_files \$uri \$uri/ /index.php?\$query_string;
}

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
    # hardcoding "upgrade": the usual \$connection_upgrade recipe needs a `map`
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

install_services() {
    step "Installing services"

    local backend="${APP_DIR}/backend"

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
        /usr/sbin/useradd /usr/sbin/userdel /usr/sbin/usermod /usr/sbin/groupadd
        /usr/sbin/chpasswd /usr/bin/gpasswd /usr/bin/getent /usr/bin/id
        /usr/bin/tee /usr/bin/mkdir /usr/bin/chown /usr/bin/chmod /usr/bin/rm
        /usr/bin/cp /usr/bin/mv /usr/bin/ln /usr/bin/install /usr/bin/truncate
        /usr/bin/find /usr/bin/tail /usr/bin/cat /usr/bin/test /usr/bin/which
        /usr/bin/runuser /usr/bin/sh /usr/bin/env
        /usr/sbin/nginx /usr/sbin/apachectl /usr/bin/lswsctrl
        /usr/sbin/phpenmod /usr/sbin/phpdismod /usr/bin/update-alternatives
        /usr/bin/mysql /usr/bin/redis-cli /usr/bin/mongosh
        /usr/sbin/ufw /usr/bin/fail2ban-client
        /usr/bin/fallocate /usr/sbin/mkswap /usr/sbin/swapon /usr/sbin/swapoff
        /usr/bin/hostnamectl /usr/bin/timedatectl /usr/bin/df /usr/bin/du
        /usr/bin/ps /usr/bin/kill /usr/bin/ss /usr/bin/curl /usr/bin/unzip
        /usr/bin/tar /usr/bin/git /usr/local/bin/fnm /usr/local/bin/wp
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

    if ufw status 2>/dev/null | head -n1 | grep -q inactive; then
        ok "rules added for 22, 80, 443 (ufw is inactive — not enabling it)"
    else
        ok "rules added for 22, 80, 443"
    fi
}

# ─── TLS ─────────────────────────────────────────────────────────────────────

configure_tls() {
    step "Setting up HTTPS"

    if (( ! WANT_SSL )); then
        SCHEME="http"
        TLS_STATE="none"
        skip "--no-ssl given"
        return
    fi

    export DEBIAN_FRONTEND=noninteractive
    run apt-get install -y certbot python3-certbot-nginx

    local args=(--nginx --non-interactive --agree-tos --redirect -d "$PANEL_HOST")
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
    run sudo -u "$APP_USER" -H "/usr/bin/php${PHP_VERSION}" "${backend}/artisan" config:cache
    run sudo -u "$APP_USER" -H "/usr/bin/php${PHP_VERSION}" "${backend}/artisan" route:cache
    ok "configuration cached"

    local scheme="$SCHEME"

    printf '\n%s%s The control panel is installed%s\n\n' "$BOLD" "$GREEN" "$RESET"
    printf '  Panel:   %s%s://%s%s\n' "$BOLD" "$scheme" "$PANEL_HOST" "$RESET"
    (( SINGLE_HOST )) || printf '  API:     %s://%s\n' "$scheme" "$API_HOST"
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
    resolve_hostnames
    configure_swap
    install_packages
    install_node
    create_user
    fetch_source
    configure_redis
    configure_fpm
    # nginx and TLS come before the two things that bake URLs in: the backend's
    # .env and the frontend's build. Next inlines NEXT_PUBLIC_* at build time, so
    # building first and getting TLS afterwards ships a panel calling https on a
    # server answering http.
    configure_nginx
    configure_tls
    setup_backend
    build_frontend
    install_services
    configure_sudoers
    configure_firewall
    finish
}

main "$@"
