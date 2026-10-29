<?php

// Managed by the panel's Mautic recipe (RC-37). Run by start.sh, as www-data,
// on every start of an installed Mautic. Mautic keeps its address as site_url
// in config/local.php; the panel passes the site's current address as
// MAUTIC_URL, so after a domain change this writes the new address there.
// Nothing happens when they already match.

$file = (string) getenv('MAUTIC_LOCAL_PHP');
$url = (string) getenv('MAUTIC_URL');
if ($file === '' || ! preg_match('#^https://[A-Za-z0-9.-]+/?$#', $url)) {
    exit(0);
}

$parameters = [];
include $file;
if (empty($parameters['site_url']) || $parameters['site_url'] === $url) {
    exit(0);
}

$parameters['site_url'] = $url;
file_put_contents($file, "<?php\n\$parameters = ".var_export($parameters, true).";\n", LOCK_EX);
echo "[mautic-init] site_url set to {$url}\n";
