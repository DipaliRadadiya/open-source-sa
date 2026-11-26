// Ignores enabled on purpose: re-enabling needs the rule's id.
export function matchRule(preset, rules = []) {
  const wanted = preset.protocol || "tcp";
  return rules.find(
    (r) =>
      r.action === "allow" &&
      !r.source_ip &&
      !r.port_to &&
      Number(r.port_from) === Number(preset.port) &&
      ((r.protocol ?? "tcp") === wanted || r.protocol === "all"),
  );
}

export function quickTileState(preset, rules = []) {
  const rule = matchRule(preset, rules);
  if (!rule) return "missing";
  return rule.enabled === false ? "off" : "on";
}

export function isPortOpen(preset, rules = []) {
  return quickTileState(preset, rules) === "on";
}
