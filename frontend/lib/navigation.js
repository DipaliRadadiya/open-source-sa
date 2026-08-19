// Shared sidebar nav-item styling — the polished active state (soft brand-tint
// pill + rounded left accent bar, roomy h-9 rows, hidden bar when collapsed).
// Used by BOTH the server panel and admin panel sidebars so they never drift.
export const NAV_ITEM_CLASS =
  "relative h-9 pl-3 transition-colors data-[active=true]:bg-primary/10 data-[active=true]:font-medium data-[active=true]:text-primary data-[active=true]:hover:bg-primary/15 data-[active=true]:hover:text-primary data-[active=true]:before:absolute data-[active=true]:before:inset-y-1 data-[active=true]:before:left-0 data-[active=true]:before:w-1 data-[active=true]:before:rounded-r-full data-[active=true]:before:bg-primary group-data-[collapsible=icon]:before:hidden";

// A nav item stays active on its own sub-pages: Settings is split into real
// routes (`/settings/server`, `/settings/security`, …), so an exact-match
// check would leave the sidebar with nothing highlighted the moment you open
// one. Longest match wins when two items share a prefix.
export function isNavActive(pathname, url) {
  if (!pathname || !url) return false;
  return pathname === url || pathname.startsWith(`${url}/`);
}

export function findActiveNavItem(items, pathname) {
  return (items || [])
    .filter((item) => isNavActive(pathname, item.href ?? item.url))
    .sort((a, b) => (b.href ?? b.url).length - (a.href ?? a.url).length)[0];
}

// Application-level `url`s from the catalog are relative segments (`/domains`,
// `""` for the dashboard), not paths. Unprefixed they either 404 or — for
// `/settings`, `/php`, `/firewall`, `/logs` — land on the SERVER-wide screen of
// the same name, which is the worse failure of the two.
export function applicationNavHref(applicationId, url) {
  return `/applications/${applicationId}${url ?? ""}`;
}

// The catalog advertises every application screen the backend supports; the
// frontend has built the dashboard so far. Linking to the rest would promise
// pages that do not exist, so they are held back until their route lands.
const BUILT_APPLICATION_URLS = new Set([
  "",
  "/domains",
  "/deployment",
  "/environment",
  "/logs",
  "/workers",
  "/files",
  "/security",
  "/bot-blocker",
  "/firewall",
  "/backups",
  "/clone",
  "/php",
  "/fail2ban",
  "/staging",
]);

export function isApplicationNavBuilt(url) {
  return BUILT_APPLICATION_URLS.has(url ?? "");
}

// The same contract for the SERVER panel, which had none — every catalog item
// was linked unconditionally, so the moment the backend advertised a screen
// this frontend had not built (Backups, Storage), the sidebar linked straight
// into a 404. Held back as "SOON" instead, exactly like the application side.
const BUILT_SERVER_URLS = new Set([
  "/dashboard",
  "/applications",
  "/databases",
  "/system-users",
  "/firewall",
  "/cron-jobs",
  "/fail2ban",
  "/logs",
  "/services",
  "/php",
  "/node",
  "/settings",
  "/disk-cleaner",
  "/backups",
  "/activity-log",
  "/integrations/git",
  "/integrations/storage",
  "/sync",
]);

export function isServerNavBuilt(url) {
  return BUILT_SERVER_URLS.has(url ?? "");
}

export function isNavBuilt(panel, url) {
  return panel === "application" ? isApplicationNavBuilt(url) : isServerNavBuilt(url);
}

// Resolves a catalog item for the panel it belongs to: application items get
// the `/applications/{id}` prefix, server items are already absolute.
export function resolveNavItems(items, applicationId) {
  return (items || []).map((item) =>
    item.level === "application" && applicationId
      ? { ...item, href: applicationNavHref(applicationId, item.url) }
      : { ...item, href: item.url },
  );
}

/**
 * The catalog's title, unless the frontend calls the screen something clearer.
 *
 * "8G" is the name of the upstream ruleset, not a description of the feature —
 * it tells somebody looking for a web firewall nothing at all. The API keeps
 * its name; only the label changes.
 */
export function navTitle(item, t) {
  if (item.name === "app_firewall") return t("navTitles.app_firewall");
  return item.title;
}

// Buckets a flat, already-filtered list of nav items by their sub_level so the
// sidebar can render them under section headers. Pure grouping — no permission
// or panel logic lives here.
//
// Returns an array, not a keyed object, for two reasons: the catalog's own
// order is preserved, and each group carries `sub_level_title` — the label the
// backend means you to show ("Integrations"). The raw `sub_level` is an id
// ("integration"), and printing it is how the sidebar ended up shouting
// INTEGRATION at people. It stays as the fallback for a catalog that predates
// the title.
export function groupBySubLevel(items) {
  const groups = [];
  const byKey = new Map();

  for (const item of items || []) {
    const key = item.sub_level || "";
    let group = byKey.get(key);
    if (!group) {
      group = { key, title: item.sub_level_title || key, items: [] };
      byKey.set(key, group);
      groups.push(group);
    }
    group.items.push(item);
  }

  return groups;
}
