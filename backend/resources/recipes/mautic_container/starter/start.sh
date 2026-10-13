#!/bin/bash
# Managed by the panel's Mautic recipe (RC-37). The web container runs this
# before the image's own /entrypoint.sh. Delete it to get the image's
# behaviour back (Mautic's web installer at the site address).
set -e

# HTTPS behind the panel's proxy: PHP sees HTTPS=on for requests the proxy
# received over HTTPS, so Symfony builds https links and secure cookies.
echo 'SetEnvIf X-Forwarded-Proto https HTTPS=on' > /etc/apache2/conf-enabled/zz-forwarded-https.conf

config=/var/www/html/config/local.php
installed() {
    php -r 'include getenv("MAUTIC_LOCAL_PHP"); exit(!empty($parameters["db_driver"]) && !empty($parameters["site_url"]) ? 0 : 1);' 2>/dev/null
}
export MAUTIC_LOCAL_PHP="$config"

if ! installed; then
    # The same preparation as the image's own startup checks, done first so the
    # install runs before Apache can serve the web installer to anyone.
    for dir in /var/www/html/config /var/www/html/var/logs /var/www/html/docroot/media/files /var/www/html/docroot/media/images; do
        mkdir -p "$dir"
        chown www-data:www-data "$dir"
    done
    [ -f "$config" ] || cp -p /templates/local.php "$config"
    chown www-data:www-data "$config"
    echo "[mautic-init] installing Mautic for ${MAUTIC_URL}"
    su -s /bin/bash www-data -c 'php /mautic-init/install.php'
    echo "[mautic-init] installation finished"
else
    # Keep site_url in step with the panel's address after a domain change.
    su -s /bin/bash www-data -c 'php /mautic-init/sync-url.php' || true
fi

exec /entrypoint.sh
