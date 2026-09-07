/**
 * Which of three states a Quick add tile is in.
 *
 * This lived inside the card as two lines and shipped a bug that no test could
 * have caught there: "is there a rule for this port" was being used to answer
 * "is this port open", so a rule that existed but was switched off lit the tile
 * green and labelled it Added — on the same screen as a table showing the rule
 * off and a warning listing that port as blocked.
 */

/**
 * The rule a tile stands for, or undefined.
 *
 * Deliberately ignores `enabled`: switching a closed port back on needs the
 * rule's id, so the lookup has to find rules in both states. Everything that
 * asks "is it open?" must go through `quickTileState`, not this.
 *
 * Protocol has to match — a UDP rule on 443 is not the HTTPS rule — and a rule
 * restricted to one address, or covering a port range, is a different rule.
 */
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
