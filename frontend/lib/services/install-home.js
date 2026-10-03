// Not `/setup`: it marks a component installed once ANY part is, hiding the failed one.
// `label`/`retryLabel` keys are built dynamically; scripts/check-install-home.mjs asserts them.
const HOMES = [
  {
    // The service key is `postgresql`, not `postgres`.
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
    // A failed Docker install has somewhere to land that can say more than
    // the setup page can: /setup reports a component installed as soon as
    // any part of it is, and the Docker screen reads the daemon directly.
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

// Used by scripts/check-install-home.mjs.
export const INSTALL_HOMES = [...HOMES, SETUP];
