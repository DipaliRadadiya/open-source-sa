// CI/CD for the self-hosted control panel (an install.sh-provisioned server).
//
// Built directly from install.sh's setup_backend() / build_frontend() /
// install_services() — mirrors that layout exactly:
//   - APP_DIR  = /var/www/panel   (git checkout == live runtime, same as install.sh)
//   - APP_USER = panel            (owns the checkout; configure_sudoers() already
//                                  grants it passwordless root sudo for systemctl
//                                  and friends — see /etc/sudoers.d/panel)
//   - PHP      = /usr/bin/php8.4  (explicit version; install.sh never calls bare `php`,
//                                  since this box hosts multiple PHP versions)
//   - Node     = fnm-managed, NOT on system PATH. install.sh resolves it once at
//                install time and writes the bin dir into backend/.env as
//                PANEL_NODE_BIN_DIR — read that instead of guessing a path.
//   - DB       = sqlite (install.sh's default). Path comes from backend/.env's
//                DB_DATABASE, backed up with `sqlite3 ".backup"` before every
//                migration (safe under concurrent writers, unlike a raw cp).
//   - Services: panel-fpm.service     (dedicated FPM master — NOT php8.4-fpm)
//               panel-frontend.service (Next.js standalone server)
//               panel-queue.service    (long-running `artisan queue:work` —
//                                       MUST restart every deploy, or it keeps
//                                       executing the previous release's code
//                                       for jobs already loaded in memory)
//
// The Jenkins agent does NOT run this pipeline as the `panel` user — every
// step that touches the checkout, .env, vendor/, node_modules/, etc. runs
// through `sudo -u panel -H` so file ownership matches what install.sh set
// up (everything under APP_DIR is panel:panel). Service restarts chain a
// second sudo (`sudo -u panel -H sudo systemctl ...`) rather than assuming
// the Jenkins user has its own root grant, since only `panel` is guaranteed
// that NOPASSWD rule by configure_sudoers().
//
// Requires: the Jenkins execution user has NOPASSWD sudo to become `panel`
// (e.g. `jenkins ALL=(panel) NOPASSWD: ALL` in /etc/sudoers.d/jenkins).
//
// Laravel's caches are cleared and rebuilt on every deploy, before the
// restart. install.sh runs `optimize`, so route/config/view caches exist on a
// deployed server and Laravel prefers them over the source — skipping this
// makes a successful deploy serve the previous release's routes and config.
//
// Frontend is only rebuilt when a file under frontend/ actually changed
// between the commit that was live before this run and the one just
// fetched — the diff needs real history, so the checkout is unshallowed
// on its first CI run (install.sh's initial clone is --depth 1) and fetched
// normally from then on.

pipeline {
    agent any

    options {
        timestamps()
        disableConcurrentBuilds()
        buildDiscarder(logRotator(numToKeepStr: '20'))
    }

    parameters {
        string(name: 'BRANCH', defaultValue: 'main', description: 'Branch to deploy')
    }

    environment {
        APP_DIR      = '/var/www/panel'
        BACKEND_DIR  = '/var/www/panel/backend'
        FRONTEND_DIR = '/var/www/panel/frontend'
        PHP_BIN      = '/usr/bin/php8.4'
        BACKUP_DIR   = '/home/panel/db-backups'
    }

    // Enable one of these once the job is wired to your git host:
    //   triggers { githubPush() }
    //   triggers { pollSCM('H/5 * * * *') }

    stages {
        stage('Update code') {
            steps {
                script {
                    env.OLD_SHA = sh(
                        script: 'sudo -u panel -H git -C "$APP_DIR" rev-parse HEAD 2>/dev/null || echo none',
                        returnStdout: true
                    ).trim()
                }
                sh '''
                    set -eu
                    sudo -u panel -H git config --global --add safe.directory "$APP_DIR" || true
                    if sudo -u panel -H test -f "$APP_DIR/.git/shallow"; then
                        sudo -u panel -H git -C "$APP_DIR" fetch --unshallow origin "$BRANCH"
                    else
                        sudo -u panel -H git -C "$APP_DIR" fetch origin "$BRANCH"
                    fi
                    sudo -u panel -H git -C "$APP_DIR" reset --hard "origin/$BRANCH"
                    sudo -u panel -H git -C "$APP_DIR" config core.fileMode false
                '''
                script {
                    env.NEW_SHA = sh(
                        script: 'sudo -u panel -H git -C "$APP_DIR" rev-parse HEAD',
                        returnStdout: true
                    ).trim()

                    if (env.OLD_SHA == 'none' || env.OLD_SHA == env.NEW_SHA) {
                        env.FRONTEND_CHANGED = 'true'
                        echo 'First CI deploy (or no new commits) — building frontend unconditionally.'
                    } else {
                        def diff = sh(
                            script: 'sudo -u panel -H git -C "$APP_DIR" diff --name-only "$OLD_SHA" "$NEW_SHA" -- frontend/',
                            returnStdout: true
                        ).trim()
                        env.FRONTEND_CHANGED = diff ? 'true' : 'false'
                    }
                    echo "frontend changed since last deploy: ${env.FRONTEND_CHANGED}"
                }
            }
        }

        stage('Backup database') {
            steps {
                sh '''
                    set -eu
                    DB_PATH=$(sudo -u panel -H sh -c 'grep -E "^DB_DATABASE=" "$1/.env" | cut -d= -f2-' -- "$BACKEND_DIR")
                    [ -n "$DB_PATH" ] || { echo "DB_DATABASE missing from backend/.env"; exit 1; }

                    STAMP=$(date +%Y%m%d-%H%M%S)
                    BACKUP_FILE="$BACKUP_DIR/database-$STAMP.sqlite"

                    sudo -u panel -H mkdir -p "$BACKUP_DIR"
                    sudo -u panel -H sqlite3 "$DB_PATH" ".backup '$BACKUP_FILE'"
                    echo "Database backed up to $BACKUP_FILE"

                    # Keep the last 20 backups so this directory doesn't grow forever.
                    sudo -u panel -H sh -c 'cd "$1" && ls -1t database-*.sqlite 2>/dev/null | tail -n +21 | xargs -r rm -f' -- "$BACKUP_DIR"
                '''
            }
        }

        stage('Backend: install & migrate') {
            steps {
                sh '''
                    set -eu
                    sudo -u panel -H composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader -d "$BACKEND_DIR"
                    # Some cached views/route files were left owned by root (an
                    # earlier manual artisan run outside this pipeline) -- chmod
                    # requires being the owner or root, so plain `panel` can't
                    # touch them. Route through panel's own root-chmod sudo grant.
                    sudo -u panel -H sudo chown -R panel:panel "$BACKEND_DIR/storage" "$BACKEND_DIR/bootstrap/cache"
                    sudo -u panel -H sudo chmod -R 775 "$BACKEND_DIR/storage" "$BACKEND_DIR/bootstrap/cache"
                    sudo -u panel -H "$PHP_BIN" "$BACKEND_DIR/artisan" migrate --force
                '''
            }
        }

        stage('Frontend: install & build') {
            when {
                environment name: 'FRONTEND_CHANGED', value: 'true'
            }
            steps {
                sh '''
                    set -eu
                    NODE_BIN_DIR=$(sudo -u panel -H sh -c 'grep -E "^PANEL_NODE_BIN_DIR=" "$1/.env" | cut -d= -f2-' -- "$BACKEND_DIR")
                    [ -n "$NODE_BIN_DIR" ] || { echo "PANEL_NODE_BIN_DIR missing from backend/.env"; exit 1; }

                    # V8 will not grow its old space past this cap however much
                    # memory the agent has, so a large `next build` dies with
                    # "Reached heap limit ... JavaScript heap out of memory"
                    # while free memory still looks fine. Sized from RAM the
                    # same way install.sh's build_frontend() and
                    # UpdateScript's frontend_build do -- three build paths,
                    # one rule, or a deploy fails on a box the installer built.
                    RAM_MB=$(awk '/MemTotal/ {printf "%d", $2/1024}' /proc/meminfo)
                    if [ "$RAM_MB" -ge 7500 ]; then HEAP_MB=4096
                    elif [ "$RAM_MB" -ge 3500 ]; then HEAP_MB=3072
                    else HEAP_MB=2048; fi
                    echo "frontend build heap: ${HEAP_MB} MB (${RAM_MB} MB RAM)"

                    sudo -u panel -H env "PATH=$NODE_BIN_DIR:/usr/local/bin:/usr/bin:/bin" npm --prefix "$FRONTEND_DIR" ci --no-audit --no-fund
                    sudo -u panel -H env "PATH=$NODE_BIN_DIR:/usr/local/bin:/usr/bin:/bin" "NODE_OPTIONS=--max-old-space-size=$HEAP_MB" npm --prefix "$FRONTEND_DIR" run build
                '''
            }
        }

        // Must run after the code is in place and before the services are
        // restarted, so the workers come back up on freshly built caches.
        //
        // Not optional, and not merely a tidy-up: install.sh runs `optimize`,
        // so a deployed server has route/config/view caches on disk. Those are
        // built from the *previous* release and Laravel prefers them over the
        // source, so new routes 404 and changed config is ignored until they
        // are rebuilt -- the code deploys fine and the panel keeps serving the
        // old behaviour, which reads as "the deploy didn't work".
        //
        // Same pair, in the same order, as UpdateScript::STEPS' `optimize`
        // step -- the two paths must not drift.
        stage('Backend: rebuild caches') {
            steps {
                sh '''
                    set -eu
                    sudo -u panel -H "$PHP_BIN" "$BACKEND_DIR/artisan" optimize:clear
                    sudo -u panel -H "$PHP_BIN" "$BACKEND_DIR/artisan" optimize
                '''
            }
        }

        stage('Restart services') {
            steps {
                sh '''
                    set -eu
                    sudo -u panel -H sudo systemctl reload panel-fpm.service
                    if [ "$FRONTEND_CHANGED" = "true" ]; then
                        sudo -u panel -H sudo systemctl restart panel-frontend.service
                    else
                        echo "frontend unchanged — skipping panel-frontend.service restart"
                    fi
                    sudo -u panel -H sudo systemctl restart panel-queue.service
                '''
            }
        }
    }

    post {
        success {
            echo "panel deployed from ${params.BRANCH}: db backed up, backend migrated, frontend built=${env.FRONTEND_CHANGED}, caches rebuilt, services restarted."
        }
        failure {
            echo 'Deploy failed — see the failing stage above.'
        }
    }
}
