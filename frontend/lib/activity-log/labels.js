export function humanizeActivity(key) {
  if (!key) return "";
  return key
    .replace(/[._]/g, " ")
    .replace(/\b\w/g, (c) => c.toUpperCase());
}

// Badge variant for a verb: green for additions, red for removals/failures.
export function actionBadgeVariant(action) {
  if (!action) return "muted";
  const a = action.toLowerCase();
  if (
    a.includes("fail") ||
    a.startsWith("delete") ||
    a.includes("removed") ||
    a.includes("disabled")
  ) {
    return "destructive";
  }
  if (
    a.startsWith("create") ||
    a.includes("added") ||
    a.includes("registered") ||
    a.includes("enabled") ||
    a.includes("_set")
  ) {
    return "success";
  }
  if (a.includes("password") || a.includes("reset") || a.includes("sudo")) {
    return "warning";
  }
  return "muted";
}

export function actionDotClass(action) {
  const variant = actionBadgeVariant(action);
  if (variant === "destructive") return "bg-destructive";
  if (variant === "success") return "bg-success";
  if (variant === "warning") return "bg-warning";
  return "bg-muted-foreground/40";
}

// Full class strings: Tailwind scans source, so a composed `bg-${family}/12` is never generated.
// Text mixes in the foreground: chart colours alone are too low-contrast at 12px.
const PEOPLE = "border-transparent bg-chart-5/12 text-[color-mix(in_oklch,var(--chart-5)_55%,var(--foreground))]";
const SECURITY = "border-transparent bg-chart-4/12 text-[color-mix(in_oklch,var(--chart-4)_55%,var(--foreground))]";
const RUNTIME = "border-transparent bg-chart-1/12 text-[color-mix(in_oklch,var(--chart-1)_55%,var(--foreground))]";
const SITES = "border-transparent bg-chart-2/12 text-[color-mix(in_oklch,var(--chart-2)_55%,var(--foreground))]";
const HOUSEKEEPING = "border-transparent bg-chart-3/12 text-[color-mix(in_oklch,var(--chart-3)_55%,var(--foreground))]";

const TYPE_FAMILY = {
  user: PEOPLE,
  role: PEOPLE,
  permission: PEOPLE,
  system_user: PEOPLE,

  firewall: SECURITY,
  fail2ban: SECURITY,

  php: RUNTIME,
  node: RUNTIME,
  runtime: RUNTIME,
  service: RUNTIME,

  application: SITES,
  database: SITES,
  git_account: SITES,

  cronjob: HOUSEKEEPING,
  disk_cleaner: HOUSEKEEPING,
  log: HOUSEKEEPING,
  setting: HOUSEKEEPING,
  server: HOUSEKEEPING,
};

// Translated type name; a type without a key falls back to its humanised id.
export function typeLabel(t, type) {
  if (!type) return "";
  return t.has(`types.${type}`) ? t(`types.${type}`) : humanizeActivity(type);
}

export function typeBadgeClass(type) {
  return TYPE_FAMILY[type] ?? "border-border text-muted-foreground";
}

// The filters endpoint is not scope-aware, so a fixed-scope page narrows the list itself.
// Mirrors config/activity.php `scopes.account`.
const ACCOUNT_TYPES = new Set(["user", "role", "permission", "central"]);

export function typesForScope(types = [], scope) {
  if (!scope) return types;
  return types.filter((type) =>
    scope === "account" ? ACCOUNT_TYPES.has(type) : !ACCOUNT_TYPES.has(type),
  );
}

// Union of the per-type verbs for types in scope; `all` only when the API sends no breakdown.
export function actionsForScope(actions = {}, types = [], scope) {
  if (!scope) return actions;
  const allowed = typesForScope(types, scope);
  const union = new Set();
  for (const type of allowed) for (const action of actions[type] ?? []) union.add(action);
  // With per-type lists, an empty union means none of these verbs belong here.
  const perType = Object.keys(actions).some((key) => key !== "all");
  const scoped = { ...actions, all: union.size || perType ? [...union].sort() : (actions.all ?? []) };
  for (const type of Object.keys(scoped)) {
    if (type !== "all" && !allowed.includes(type)) delete scoped[type];
  }
  return scoped;
}
