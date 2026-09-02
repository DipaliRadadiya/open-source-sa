// Turn a raw activity key like "user.created" into a readable "User Created".
export function humanizeActivity(key) {
  if (!key) return "";
  return key
    .replace(/[._]/g, " ")
    .replace(/\b\w/g, (c) => c.toUpperCase());
}

// Semantic badge variant for an activity verb (green for additions, red for
// removals/failures, neutral otherwise).
export function actionBadgeVariant(action) {
  if (!action) return "secondary";
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
  return "secondary";
}

// Tailwind bg class for a status dot, keyed off the same verb semantics.
export function actionDotClass(action) {
  const variant = actionBadgeVariant(action);
  if (variant === "destructive") return "bg-destructive";
  if (variant === "success") return "bg-success";
  if (variant === "warning") return "bg-warning";
  return "bg-muted-foreground/40";
}

/**
 * A colour for the entity an activity row is about.
 *
 * Grouped into five families rather than one colour per type: with ~15 types,
 * a colour each stops being a signal and becomes decoration. The families are
 * the ones you actually scan for — "was this security, or someone's account?"
 *
 * Uses the theme's chart palette, which is the panel's only categorical scale
 * and already has dark-mode values. Inventing new hues here would repeat the
 * mistake the lifecycle badge made.
 */
// Full class strings, not built from a variable: Tailwind scans source text, so
// a composed `bg-${family}/12` is never generated and the badge comes out bare.
const PEOPLE = "border-transparent bg-chart-5/12 text-chart-5";
const SECURITY = "border-transparent bg-chart-4/12 text-chart-4";
const RUNTIME = "border-transparent bg-chart-1/12 text-chart-1";
const SITES = "border-transparent bg-chart-2/12 text-chart-2";
const HOUSEKEEPING = "border-transparent bg-chart-3/12 text-chart-3";

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

export function typeBadgeClass(type) {
  return TYPE_FAMILY[type] ?? "border-border text-muted-foreground";
}

// Which entities belong to which scope, per the API docs: `account` is the
// panel's people, `server` is the machine. The filters endpoint isn't
// scope-aware — it returns every type the caller has rows for — so a page that
// fixes its scope has to narrow the list itself, or it offers `user` on the
// server page where it can never match.
const ACCOUNT_TYPES = new Set(["user", "role", "permission"]);

export function typesForScope(types = [], scope) {
  if (!scope) return types;
  return types.filter((type) =>
    scope === "account" ? ACCOUNT_TYPES.has(type) : !ACCOUNT_TYPES.has(type),
  );
}

/**
 * The verbs worth offering for a scope.
 *
 * `actions.all` spans both scopes, so on the server page it would list
 * "Logged In". Rebuilt from the per-type lists of the types that survive the
 * scope filter; falls back to `all` when the API sends no per-type breakdown.
 */
export function actionsForScope(actions = {}, types = [], scope) {
  if (!scope) return actions;
  const allowed = typesForScope(types, scope);
  const union = new Set();
  for (const type of allowed) for (const action of actions[type] ?? []) union.add(action);
  const scoped = { ...actions, all: union.size ? [...union].sort() : (actions.all ?? []) };
  for (const type of Object.keys(scoped)) {
    if (type !== "all" && !allowed.includes(type)) delete scoped[type];
  }
  return scoped;
}
