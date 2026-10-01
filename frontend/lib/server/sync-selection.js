import { FIREWALL_RESOURCE_TYPE, SYNC_RESOURCE_TYPES } from "@/lib/schemas/sync";

/**
 * Ignore-list identity: (resource_type, resource_key), matching the backend's
 * unique index. Never use item ids; they change every run.
 */
export function ignoreKey(item) {
  return `${item.resource_type} ${item.resource_key}`;
}

export function ignoreKeySet(ignores) {
  return new Set((ignores ?? []).map(ignoreKey));
}

/**
 * What pressing Adopt would do. The API's `only` takes types, not ids, so the
 * plan follows the backend's rules in order:
 * 1. a type not in `selectedTypes` is not run;
 * 2. firewall rules are excluded unless `includeFirewall` (lockout risk);
 * 3. an ignored (type, key) is dropped.
 * Only `found` rows count; earlier applies are already adopted.
 */
export function adoptionPlan({ items, ignoredKeys, selectedTypes, includeFirewall }) {
  const selected = new Set(selectedTypes ?? []);
  const ignored = ignoredKeys ?? new Set();

  const perType = new Map();
  let total = 0;
  let ignoredCount = 0;

  for (const item of items ?? []) {
    if (item.action !== "found") continue;

    const type = item.resource_type;
    if (type === FIREWALL_RESOURCE_TYPE && !includeFirewall) continue;
    if (!selected.has(type)) continue;

    if (ignored.has(ignoreKey(item))) {
      ignoredCount += 1;
      continue;
    }

    perType.set(type, (perType.get(type) ?? 0) + 1);
    total += 1;
  }

  return { total, ignoredCount, perType, typeCount: perType.size };
}

/* Mirrors each discoverer's dependsOn(); `database_user` has no dependency. */
const DEPENDS_ON = {
  ssh_key: "system_user",
  application: "system_user",
  php_settings: "application",
  worker: "application",
  certificate: "application",
  cronjob: "system_user",
};

/**
 * Selected types that ServerSync will skip entirely because the type they
 * depend on was not selected.
 */
export function unmetDependencies(selectedTypes) {
  const selected = new Set(selectedTypes ?? []);
  return SYNC_RESOURCE_TYPES.filter(
    (type) => selected.has(type) && DEPENDS_ON[type] && !selected.has(DEPENDS_ON[type]),
  ).map((type) => ({ type, requires: DEPENDS_ON[type] }));
}

/** Types this run actually produced, in the backend's dependency order. */
export function typesPresent(items) {
  const present = new Set((items ?? []).map((item) => item.resource_type));
  return SYNC_RESOURCE_TYPES.filter((type) => present.has(type));
}

/**
 * The four counts summed across types. `totals` arrives keyed by resource type
 * ({application: {found, adopted, …}}), not flat as API_REFERENCE.md shows.
 */
export function runTotals(totals) {
  const sum = { found: 0, adopted: 0, skipped: 0, failed: 0 };
  for (const perType of Object.values(totals ?? {})) {
    for (const key of Object.keys(sum)) sum[key] += perType?.[key] ?? 0;
  }
  return sum;
}
