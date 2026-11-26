import { FIREWALL_RESOURCE_TYPE, SYNC_RESOURCE_TYPES } from "@/lib/schemas/sync";

// Item ids change every run, so key on (resource_type, resource_key).
export function ignoreKey(item) {
  return `${item.resource_type} ${item.resource_key}`;
}

export function ignoreKeySet(ignores) {
  return new Set((ignores ?? []).map(ignoreKey));
}

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

const DEPENDS_ON = {
  ssh_key: "system_user",
  application: "system_user",
  php_settings: "application",
  pm2_process: "application",
  worker: "application",
  certificate: "application",
  cronjob: "system_user",
};

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

// ServerSync only runs a type after its parent ran in the same request.
export function withImplicitParents(types, offered) {
  const sent = [...types];
  for (let i = 0; i < sent.length; i++) {
    const parent = DEPENDS_ON[sent[i]];
    if (parent && !sent.includes(parent) && !offered.includes(parent)) sent.push(parent);
  }
  return SYNC_RESOURCE_TYPES.filter((type) => sent.includes(type));
}

export function unmetDependencies(selectedTypes) {
  const selected = new Set(selectedTypes ?? []);
  return SYNC_RESOURCE_TYPES.filter(
    (type) => selected.has(type) && DEPENDS_ON[type] && !selected.has(DEPENDS_ON[type]),
  ).map((type) => ({ type, requires: DEPENDS_ON[type] }));
}

export function typesPresent(items) {
  const present = new Set((items ?? []).map((item) => item.resource_type));
  return SYNC_RESOURCE_TYPES.filter((type) => present.has(type));
}

export function runTotals(totals) {
  const sum = { found: 0, adopted: 0, skipped: 0, failed: 0 };
  for (const perType of Object.values(totals ?? {})) {
    for (const key of Object.keys(sum)) sum[key] += perType?.[key] ?? 0;
  }
  return sum;
}
