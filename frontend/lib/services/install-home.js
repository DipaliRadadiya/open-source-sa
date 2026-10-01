/**
 * Where a failed service install is retried. Not `/setup`: setup marks a
 * component installed once ANY part is (any engine, any PHP version), hiding
 * the part that failed. Matched on the service key; `/setup` is the fallback.
 *
 * `label` (services.attention.*) and `retryLabel` (services.state.*) are built
 * as template keys at the call sites, so grep will not find them; they are
 * asserted by scripts/check-install-home.mjs.
 */
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
