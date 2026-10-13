<?php
// Managed by the panel's PrestaShop recipe (RC-37). Run by the container's start
// command, as www-data, before Apache starts on an installed shop.
//
// PrestaShop keeps its domain in the database. The panel passes the site's
// current domain as PS_DOMAIN, so after a domain change this moves the main shop
// URL to it and regenerates what depends on it, the same steps as the image's
// own docker_updt_ps_domains.php. Nothing happens when they already match.
// Delete this file to manage the domain yourself (Shop Parameters > Traffic & SEO).

$domain = strtolower((string) getenv('PS_DOMAIN'));
if (!preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $domain)) {
    exit(0);
}

$_SERVER['HTTP_HOST'] = $domain;
$_SERVER['SERVER_NAME'] = $domain;
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/';
chdir('/var/www/html');
require '/var/www/html/config/config.inc.php';

if (Shop::isFeatureActive()) {
    echo "sync-domain: multistore is on, domains are left alone\n";
    exit(0);
}

if (Configuration::get('PS_SHOP_DOMAIN') === $domain && Configuration::get('PS_SHOP_DOMAIN_SSL') === $domain) {
    exit(0);
}

$url = ShopUrl::getShopUrls((int) Configuration::get('PS_SHOP_DEFAULT'))->where('main', '=', 1)->getFirst();
if ($url) {
    $url->domain = $domain;
    $url->domain_ssl = $domain;
    $url->save();
}

Configuration::updateValue('PS_SHOP_DOMAIN', $domain);
Configuration::updateValue('PS_SHOP_DOMAIN_SSL', $domain);
Tools::generateHtaccess();
Tools::generateRobotsFile();
Tools::clearSmartyCache();
Media::clearCache();
Tools::clearSf2Cache();

echo "sync-domain: shop domain set to {$domain}\n";
