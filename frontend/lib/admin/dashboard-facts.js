// Decisions behind the admin dashboard, kept out of the components so each state
// can be tested without a server that is actually in it.

/** `enabled` is only "a key exists"; Central is connected once it has used it. */
export function centralState(central) {
  if (!central) return null;
  if (!central.enabled) return "off";
  return central.connected ? "on" : "waiting";
}

/** The panel-update rows for the attention list: a failed run, an update that cannot start. */
export function updateAttention(update) {
  const run = update?.latest_run;
  const failed = run?.status === "failed" ? { reason: run.reason_title ?? null } : null;
  const blocked =
    update?.update_available && !update.preflight?.ready
      ? {
          version: update.available?.version ?? "",
          // Advisory checks never gate the update.
          keys: (update.preflight?.checks ?? []).filter((c) => !c.passed && !c.advisory).map((c) => c.key),
        }
      : null;
  return { failed, blocked };
}

/** How many users hold each role, most-held first: the roles endpoint has no member counts. */
export function roleCounts(users = []) {
  const byRole = new Map();
  for (const user of users) {
    for (const role of user.roles ?? []) {
      const entry = byRole.get(role.id) ?? { role, count: 0 };
      entry.count += 1;
      byRole.set(role.id, entry);
    }
  }
  return [...byRole.values()].sort((a, b) => b.count - a.count);
}
