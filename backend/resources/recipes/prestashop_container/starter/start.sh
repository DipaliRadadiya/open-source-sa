#!/bin/sh
# Managed by the panel's PrestaShop recipe (RC-37). The container runs this
# before the image's own /tmp/docker_run.sh.
root=/var/www/html

# HTTPS behind the panel's proxy: PHP sees HTTPS=on for requests the proxy
# received over HTTPS (PrestaShop's secure-mode check and the Symfony back
# office both read it).
echo 'SetEnvIf X-Forwarded-Proto https HTTPS=on' > /etc/apache2/conf-enabled/zz-forwarded-https.conf

if [ -f "$root/app/config/parameters.php" ]; then
    # Installed. Drop the installation-time alias (below) and follow a domain change.
    if [ -L "$root/admin-dev" ]; then
        rm -f "$root/admin-dev"
    fi
    if [ -f /ps-init/sync-domain.php ]; then
        runuser -u www-data -- php /ps-init/sync-domain.php || true
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
