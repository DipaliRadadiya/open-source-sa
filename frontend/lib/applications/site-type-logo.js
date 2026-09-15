/**
 * The logo for an application type.
 *
 * Keyed on the type's `name` — the stable identifier the API sends — and not
 * on its title, which is translated, nor on its `icon`, which mixes brand
 * slugs ("wordpress") with generic ones ("shopping-cart") and so cannot tell a
 * brand apart from a category.
 *
 * The filenames are written out rather than derived from the name. Two of them
 * do not match (`craftcms` is `craft.svg`, `static` is `selfhosted.png`), and a
 * clever transform that handles those would also happily invent a path for a
 * type we have no logo for — which renders as a broken image rather than as
 * the fallback icon, on the one screen that is meant to look considered.
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
  static: "selfhosted.png",
};

/** The public path for a type's logo, or null when it has none. */
export function siteTypeLogo(name) {
  const file = LOGOS[name];
  return file ? `/site-types/${file}` : null;
}

export { LOGOS as SITE_TYPE_LOGOS };
