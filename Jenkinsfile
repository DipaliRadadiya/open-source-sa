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
    }

    // Enable one of these once the job is wired to your git host:
    //   triggers { githubPush() }
    //   triggers { pollSCM('H/5 * * * *') }

    stages {
        stage('Update code') {
            steps {
                sh '''
                    set -eu
                    sudo -u panel -H git config --global --add safe.directory "$APP_DIR" || true
                    sudo -u panel -H git -C "$APP_DIR" fetch --depth 1 origin "$BRANCH"
                    sudo -u panel -H git -C "$APP_DIR" reset --hard "origin/$BRANCH"
                    sudo -u panel -H git -C "$APP_DIR" config core.fileMode false
                '''
            }
        }

        stage('Backend: install & migrate') {
            steps {
                sh '''
                    set -eu
                    sudo -u panel -H composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader -d "$BACKEND_DIR"
                    sudo -u panel -H chmod -R 775 "$BACKEND_DIR/storage" "$BACKEND_DIR/bootstrap/cache"
                    sudo -u panel -H "$PHP_BIN" "$BACKEND_DIR/artisan" migrate --force
                '''
            }
        }

        stage('Frontend: install & build') {
            steps {
                sh '''
                    set -eu
                    NODE_BIN_DIR=$(sudo -u panel -H sh -c 'grep -E "^PANEL_NODE_BIN_DIR=" "$1/.env" | cut -d= -f2-' -- "$BACKEND_DIR")
                    [ -n "$NODE_BIN_DIR" ] || { echo "PANEL_NODE_BIN_DIR missing from backend/.env"; exit 1; }

                    sudo -u panel -H env "PATH=$NODE_BIN_DIR:/usr/local/bin:/usr/bin:/bin" npm --prefix "$FRONTEND_DIR" ci --no-audit --no-fund
                    sudo -u panel -H env "PATH=$NODE_BIN_DIR:/usr/local/bin:/usr/bin:/bin" npm --prefix "$FRONTEND_DIR" run build
                '''
            }
        }

        stage('Restart services') {
            steps {
                sh '''
                    set -eu
                    sudo -u panel -H sudo systemctl reload panel-fpm.service
                    sudo -u panel -H sudo systemctl restart panel-frontend.service
                    sudo -u panel -H sudo systemctl restart panel-queue.service
                '''
            }
        }
    }

    post {
        success {
            echo "panel deployed from ${params.BRANCH}: backend migrated, frontend rebuilt, fpm reloaded, frontend + queue restarted."
        }
        failure {
            echo 'Deploy failed — see the failing stage above.'
        }
    }
}
