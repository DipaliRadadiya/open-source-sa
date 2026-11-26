const HOMES = [
  {
    match: /^(mysql|mariadb|mongodb|postgresql)$/,
    href: "/databases",
    label: "openDatabases",
    retryLabel: "retryOnDatabases",
  },
  { match: /^php[\d.]+-fpm$/, href: "/php", label: "openPhp", retryLabel: "retryOnPhp" },
  {
    match: /^fail2ban$/,
    href: "/fail2ban",
    label: "openFail2ban",
    retryLabel: "retryOnFail2ban",
  },
  {
    match: /^docker$/,
    href: "/docker",
    label: "openDocker",
    retryLabel: "retryOnDocker",
  },
];

const SETUP = { href: "/setup", label: "openSetup", retryLabel: "retryOnSetup" };

export function installHome(serviceKey) {
  return HOMES.find(({ match }) => match.test(serviceKey ?? "")) ?? SETUP;
}

export const INSTALL_HOMES = [...HOMES, SETUP];
