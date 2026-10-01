/**
 * Application type logos, keyed on the API's stable `name` (not the translated
 * title or the generic `icon`). Filenames are explicit, not derived, so an
 * unknown type gets the fallback icon rather than a broken image.
 */
const LOGOS = {
  wordpress: "wordpress.svg",
  nextcloud: "nextcloud.svg",
  joomla: "joomla.svg",
  moodle: "moodle.svg",
  mautic: "mautic.svg",
  craftcms: "craft.svg",
  akaunting: "akaunting.svg",
  statamic: "statamic.svg",
  prestashop: "prestashop.svg",
  phpmyadmin: "phpmyadmin.png",
  uptimekuma: "uptime-kuma.svg",
  n8n: "n8n.svg",
  nodered: "node-red.svg",
  nodebb: "nodebb.svg",
  git: "git.svg",
  php: "php.svg",
  // Not a brand: a static site is plain HTML, CSS and JavaScript.
  static: "html5.svg",
};

/** The public path for a type's logo, or null when it has none. */
export function siteTypeLogo(name) {
  const file = LOGOS[name];
  return file ? `/site-types/${file}` : null;
}

export { LOGOS as SITE_TYPE_LOGOS };
