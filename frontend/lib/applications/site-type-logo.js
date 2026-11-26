const LOGOS = {
  wordpress: "wordpress.svg",
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
  adminer: "adminer.png",
  uptimekuma: "uptime-kuma.svg",
  n8n: "n8n.svg",
  nodered: "node-red.svg",
  nodebb: "nodebb.svg",
  git: "git.svg",
  php: "php.svg",
  static: "html5.svg",
  docker: "docker.svg",
};

export function siteTypeLogo(name) {
  const file = LOGOS[name];
  return file ? `/site-types/${file}` : null;
}

export { LOGOS as SITE_TYPE_LOGOS };
