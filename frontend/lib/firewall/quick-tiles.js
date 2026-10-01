// A disabled rule must not show the tile as Added: having a rule is not the port being open.

// Deliberately ignores `enabled` (re-enabling needs the rule's id); "is it open?" must use
// `quickTileState`. Protocol must match; a source-restricted or range rule is a different rule.
export function matchRule(preset, rules = []) {
  const wanted = preset.protocol || "tcp";
  return rules.find(
    (r) =>
      r.action === "allow" &&
      !r.source_ip &&
      !r.port_to &&
      Number(r.port_from) === Number(preset.port) &&
      // "all" covers both, so it satisfies a tcp or udp tile.
      ((r.protocol ?? "tcp") === wanted || r.protocol === "all"),
  );
}

/** "missing" | "on" | "off" */
export function quickTileState(preset, rules = []) {
  const rule = matchRule(preset, rules);
  if (!rule) return "missing";
  return rule.enabled === false ? "off" : "on";
}

/** Open right now — not merely present. */
export function isPortOpen(preset, rules = []) {
  return quickTileState(preset, rules) === "on";
}
