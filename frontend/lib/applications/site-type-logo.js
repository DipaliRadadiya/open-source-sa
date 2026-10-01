// Keyed on the API's stable `name`. Explicit filenames, so an unknown type gets the fallback icon.
const LOGOS = {
  wordpress: "wordpress.svg",
  // The container WordPress is the same product and gets the same mark. Keyed
  // separately because the key IS the type name, and the two types exist because
  // `name()` has to be unique — see WordPressContainerSiteType.
  //
  // It is also the first Docker one-click with a logo at all: the other sixteen
  // have no entry here and fall back to the generic glyph, which is worth fixing
  // as its own piece of work rather than leaving one card looking different.
  wordpress_container: "wordpress.svg",
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
