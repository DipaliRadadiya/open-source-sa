#!/bin/sh
# Managed by the panel's PrestaShop recipe (RC-37). The container runs this
# before the image's own /tmp/docker_run.sh.
root=/var/www/html

# HTTPS behind the panel's proxy: PHP sees HTTPS=on for requests the proxy
# received over HTTPS (PrestaShop's secure-mode check and the Symfony back
# office both read it).
echo 'SetEnvIf X-Forwarded-Proto https HTTPS=on' > /etc/apache2/conf-enabled/zz-forwarded-https.conf

if [ -f "$root/app/config/parameters.php" ]; then
    # Installed, or an installation that stopped half-way (the image then
    # takes its "already installed" path and would serve the web installer
    # to anyone). A completed setup removes both install.lock and the
    # installer folder, so either one still being there means the last
    # installation never finished: remove the installer so it is not public.
    if [ -e "$root/install.lock" ] || [ -d "$root/$PS_FOLDER_INSTALL" ]; then
        echo "[ps-init] the PrestaShop installation did not finish (see the first start's log); the web installer was removed so it is not public. Delete and recreate the app to install again."
        rm -rf "${root:?}/${PS_FOLDER_INSTALL:?}"
    fi
    # Drop the installation-time alias (below) and follow a domain change.
    if [ -L "$root/admin-dev" ]; then
        rm -f "$root/admin-dev"
    fi
    if [ -f /ps-init/sync-domain.php ]; then
        # The database may still be starting (a restart restarts it too):
        # wait for it, up to two minutes, with the password in the
        # environment rather than on a command line. The script itself runs
        # with exception arguments and error display off, so a failure can
        # never print a connection string with the password into the log.
        n=0
        until MYSQL_PWD="$DB_PASSWD" mysqladmin ping -h "$DB_SERVER" -P "$DB_PORT" -u "$DB_USER" --silent >/dev/null 2>&1 || [ "$n" -ge 60 ]; do
            n=$((n + 1))
            sleep 2
        done
        runuser -u www-data -- php -d zend.exception_ignore_args=1 -d display_errors=0 -d log_errors=0 /ps-init/sync-domain.php || true
    fi
elif [ ! -e "$root/admin-dev" ]; then
    # PrestaShop 9.2's installer installs the back-office assets into admin/,
    # or into admin-dev/ when admin/ is gone. The image has already renamed
    # admin/ to PS_FOLDER_ADMIN by then, so the install died on a missing
    # admin-dev/ and left the web installer public (RC-37 smoke). Point
    # admin-dev at the real folder for the installation, and remove the alias
    # as soon as the image reports the setup completed.
    ln -s "$PS_FOLDER_ADMIN" "$root/admin-dev"
    (
        n=0
        until [ -f "$root/app/config/parameters.php" ] && [ ! -e "$root/install.lock" ]; do
            n=$((n + 1))
            [ "$n" -ge 360 ] && exit 0
            sleep 5
        done
        rm -f "$root/admin-dev"
    ) &
fi

exec /tmp/docker_run.sh
