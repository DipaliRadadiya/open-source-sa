export function centralState(central) {
  if (!central) return null;
  if (!central.enabled) return "off";
  return central.connected ? "on" : "waiting";
}

export function updateAttention(update) {
  const run = update?.latest_run;
  const failed = run?.status === "failed" ? { reason: run.reason_title ?? null } : null;
  const blocked =
    update?.update_available && !update.preflight?.ready
      ? {
          version: update.available?.version ?? "",
          keys: (update.preflight?.checks ?? []).filter((c) => !c.passed && !c.advisory).map((c) => c.key),
        }
      : null;
  return { failed, blocked };
}
