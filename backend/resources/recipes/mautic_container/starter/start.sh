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
    [ -f "$config" ] && php -d display_errors=0 -r 'include getenv("MAUTIC_LOCAL_PHP"); exit(!empty($parameters["db_driver"]) && !empty($parameters["site_url"]) ? 0 : 1);'
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

# Mautic compiles cookie_secure (true when site_url is https) into its Symfony
# cache, and the image ships a cache built without a site_url, so the session
# cookie was not Secure (RC-37 smoke). Rebuild the cache once per container
# and address: the marker lives in the container's own filesystem, so a
# recreated container (new image, new domain) rebuilds it and a plain restart
# does not. It takes about a minute.
marker=/var/www/html/var/cache/.panel-site-url
if [ "$(cat "$marker" 2>/dev/null)" != "$MAUTIC_URL" ]; then
    echo "[mautic-init] rebuilding the cache for ${MAUTIC_URL}"
    if su -s /bin/bash www-data -c 'php /var/www/html/bin/console cache:clear --no-interaction'; then
        printf '%s' "$MAUTIC_URL" > "$marker"
    fi
fi

exec /entrypoint.sh
