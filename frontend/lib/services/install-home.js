/**
 * Where a failed service install is actually retried.
 *
 * All three "install failed" surfaces on the Services page used to link to
 * `/setup`, on the reasoning that the install came from there. That holds on a
 * fresh server and nowhere else, because of one rule in the setup catalog:
 *
 *     if ($component->installed()) return 'installed';   // wins over everything
 *
 * A component counts as installed the moment ANY part of it is — the database
 * component is `installed()` when *any* engine is running, the php component
 * when *any* version is present. So the second engine or the second version,
 * the one that just failed, is masked: setup reports the component installed,
 * renders no options for it, and puts the page at 100% complete. Someone
 * clicking "Open setup" on a failed MongoDB lands on a page congratulating
 * them, with no mention of MongoDB anywhere on it.
 *
 * Meanwhile every service that can reach `install_failed` already has a screen
 * that handles the failure properly — the server's own reason, and a retry that
 * works:
 *
 *   mysql/mariadb/mongodb  /databases  engine-state.jsx renders one row per
 *                                      engine, so a failed one shows even while
 *                                      another engine runs
 *   php{version}-fpm       /php        version-summary.jsx prints apt's output
 *   fail2ban               /fail2ban   install-prompt.jsx has a failed state
 *
 * Keyed off the service key, which is the only identifier the services payload
 * carries — the backend's `install` marker that names the owning runtime is not
 * serialised. `/setup` stays the fallback: it is right for a component nothing
 * of which is installed, and for any service added later that has no home yet.
 */
/*
 * `label` is the button on the attention list (services.attention.*);
 * `retryLabel` is the inline link on the table and cards (services.state.*).
 * Both are built as template keys at the call sites, so grep will not find
 * them — every one of these eight strings is asserted by
 * scripts/check-install-home.mjs instead.
 */
const HOMES = [
  {
    match: /^(mysql|mariadb|mongodb)$/,
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

// For the check script, so the list of keys to assert cannot drift from the map.
export const INSTALL_HOMES = [...HOMES, SETUP];
