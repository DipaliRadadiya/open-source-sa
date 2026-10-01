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
];

const SETUP = { href: "/setup", label: "openSetup", retryLabel: "retryOnSetup" };

export function installHome(serviceKey) {
  return HOMES.find(({ match }) => match.test(serviceKey ?? "")) ?? SETUP;
}

// Used by scripts/check-install-home.mjs.
export const INSTALL_HOMES = [...HOMES, SETUP];
