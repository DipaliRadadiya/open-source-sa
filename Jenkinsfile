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
// Assumes the Jenkins agent executes this pipeline AS the `panel` user — that's
// who install.sh's sudoers rule grants systemctl rights to. Running as any other
// user means these sudo calls get denied unless that NOPASSWD rule is extended.

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
                    set -euo pipefail
                    git config --global --add safe.directory "$APP_DIR" || true
                    cd "$APP_DIR"
                    git fetch --depth 1 origin "$BRANCH"
                    git reset --hard "origin/$BRANCH"
                    git config core.fileMode false
                '''
            }
        }

        stage('Backend: install & migrate') {
            steps {
                dir("${env.BACKEND_DIR}") {
                    sh '''
                        set -euo pipefail
                        composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
                        chmod -R 775 storage bootstrap/cache
                        "$PHP_BIN" artisan migrate --force
                    '''
                }
            }
        }

        stage('Frontend: install & build') {
            steps {
                dir("${env.FRONTEND_DIR}") {
                    sh '''
                        set -euo pipefail
                        NODE_BIN_DIR=$(grep -E '^PANEL_NODE_BIN_DIR=' "$BACKEND_DIR/.env" | cut -d= -f2-)
                        [ -n "$NODE_BIN_DIR" ] || { echo "PANEL_NODE_BIN_DIR missing from backend/.env"; exit 1; }
                        export PATH="$NODE_BIN_DIR:/usr/local/bin:/usr/bin:/bin"

                        npm ci --no-audit --no-fund
                        npm run build
                    '''
                }
            }
        }

        stage('Restart services') {
            steps {
                sh '''
                    set -euo pipefail
                    sudo systemctl reload panel-fpm.service
                    sudo systemctl restart panel-frontend.service
                    sudo systemctl restart panel-queue.service
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
