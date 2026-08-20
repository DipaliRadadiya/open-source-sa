/**
 * What the firewall card is actually looking at.
 *
 * There are three states, not two. The card used to pick from `enabled` alone,
 * which cannot express the one that matters most:
 *
 *   off       not running. The rules below are saved but inert.
 *   on        running, and anything not listed is blocked.
 *   exposed   running, and anything not listed is let straight in.
 *
 * `exposed` renders green-and-reassuring under the old logic — "Your server is
 * protected", "Anything not listed below is blocked before it reaches your
 * server" — while both sentences are false.
 *
 * The panel cannot cause it: ToggleFirewall::execute(true) runs
 * `ufw default deny incoming` before `ufw --force enable`, so enabling here
 * always lands on a safe posture. It takes somebody running
 * `ufw default allow incoming` over SSH afterwards. Rare, and worth catching
 * precisely because nothing else on the box would tell you.
 *
 * Only an explicit allow counts. `default_policy.incoming` is typed as a
 * free-form string, and a value we do not recognise is not evidence of
 * anything — raising an alarm over an unfamiliar word would be a worse failure
 * than the silence this replaces. Compared case-insensitively because the
 * severity of a security signal should not rest on the backend never changing
 * how it cases a word.
 */
export function firewallState(enabled, policy) {
  if (!enabled) return "off";

  const incoming = typeof policy?.incoming === "string" ? policy.incoming.trim().toLowerCase() : null;

  return incoming === "allow" ? "exposed" : "on";
}
