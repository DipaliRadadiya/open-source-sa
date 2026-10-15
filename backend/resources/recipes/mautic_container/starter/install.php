<?php
// Managed by the panel's Mautic recipe (RC-37). Run once by start.sh, as
// www-data, while Mautic is not installed. It runs Mautic's own installer
// (bin/console mautic:install) with the admin account from the container's
// environment. The values are read here instead of being passed on a command
// line, so the password never shows in the server's process list.
// The database settings come from config/local.php (the image's template reads
// MAUTIC_DB_*).

$_SERVER['argv'] = $argv = [
    '/var/www/html/bin/console',
    'mautic:install',
    '--no-interaction',
    '--force',
    '--admin_username=admin',
    '--admin_email='.getenv('MAUTIC_ADMIN_EMAIL'),
    '--admin_password='.getenv('MAUTIC_ADMIN_PASSWORD'),
    (string) getenv('MAUTIC_URL'),
];
$_SERVER['argc'] = $argc = count($argv);

chdir('/var/www/html');
require '/var/www/html/bin/console';
