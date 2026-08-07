// CI/CD for the sv-oss mono-repo (backend + frontend).
//
// Deploys in place: /var/www/sv-oss is both the git checkout and the
// directory the running services serve from (same model install.sh uses),
// so this pipeline updates it directly rather than checking out elsewhere
// and copying over.
//
// Requires: the Jenkins agent runs on THIS server, as a user with the sudo
// rights in TOOLS.md (systemctl reload php8.4-fpm / restart
// sv-oss-frontend.service, passwordless).

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
        APP_DIR      = '/var/www/sv-oss'
        BACKEND_DIR  = '/var/www/sv-oss/backend'
        FRONTEND_DIR = '/var/www/sv-oss/frontend'
    }

    // Enable one of these once the job is wired to your git host:
    //   triggers { githubPush() }        // GitHub webhook
    //   triggers { pollSCM('H/5 * * * *') }  // poll every 5 min

    stages {
        stage('Update code') {
            steps {
                sh '''
                    set -euo pipefail
                    cd "$APP_DIR"
                    git fetch --depth 1 origin "$BRANCH"
                    git reset --hard "origin/$BRANCH"
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
                        php artisan migrate --force
                    '''
                }
            }
        }

        stage('Frontend: install & build') {
            steps {
                dir("${env.FRONTEND_DIR}") {
                    sh '''
                        set -euo pipefail
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
                    sudo systemctl reload php8.4-fpm
                    sudo systemctl restart sv-oss-frontend.service
                '''
            }
        }
    }

    post {
        success {
            echo "sv-oss deployed from ${params.BRANCH}: backend migrated, frontend rebuilt, services reloaded/restarted."
        }
        failure {
            echo 'Deploy failed — see the failing stage above. Services were not restarted past that point.'
        }
    }
}
