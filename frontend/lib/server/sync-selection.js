import { FIREWALL_RESOURCE_TYPE, SYNC_RESOURCE_TYPES } from "@/lib/schemas/sync";

// (resource_type, resource_key), matching the backend's unique index. Never item ids:
// they change every run.
export function ignoreKey(item) {
  return `${item.resource_type} ${item.resource_key}`;
}

export function ignoreKeySet(ignores) {
  return new Set((ignores ?? []).map(ignoreKey));
}

// Follows the backend (`only` takes types, not ids): skip unselected types, firewall rules
// unless `includeFirewall` (lockout risk) and ignored keys; `found` rows only.
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
  pm2_process: "application",
  worker: "application",
  certificate: "application",
  cronjob: "system_user",
};

/**
 * Types a preview lists as one "after the applications are added" line: an
 * apply adopts the applications first and only then reads these, so they must
 * be sent with it even though nothing of them was found yet.
 */
export function deferredTypes(items) {
  const types = new Set();
  for (const item of items ?? []) {
    if (
      item.action === "skipped" &&
      item.resource_key === item.resource_type &&
      DEPENDS_ON[item.resource_type] === "application"
    ) {
      types.add(item.resource_type);
    }
  }
  return SYNC_RESOURCE_TYPES.filter((type) => types.has(type));
}

/**
 * Adds the parents a request needs but the dialog does not offer. ServerSync runs
 * a type only after its parent ran in the same request, even when the parent has
 * nothing new — so a site owned by a user the panel already has would be skipped
 * unless "system_user" is sent too. A parent the user unticked stays out.
 */
export function withImplicitParents(types, offered) {
  const sent = [...types];
  for (let i = 0; i < sent.length; i++) {
    const parent = DEPENDS_ON[sent[i]];
    if (parent && !sent.includes(parent) && !offered.includes(parent)) sent.push(parent);
  }
  return SYNC_RESOURCE_TYPES.filter((type) => sent.includes(type));
}

/** Selected types ServerSync skips because the type they depend on was not selected. */
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

// `totals` arrives keyed by resource type ({application: {found, adopted, …}}), not
// flat as API_REFERENCE.md shows.
export function runTotals(totals) {
  const sum = { found: 0, adopted: 0, skipped: 0, failed: 0 };
  for (const perType of Object.values(totals ?? {})) {
    for (const key of Object.keys(sum)) sum[key] += perType?.[key] ?? 0;
  }
  return sum;
}
