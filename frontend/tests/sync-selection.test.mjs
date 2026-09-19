import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";

/* -------------------------------------------------------------------------
 * The scan has to ask for the firewall, or the opt-in is unreachable
 * ---------------------------------------------------------------------- */

test("a scan asks for firewall rules; only adopting them is opt-in", () => {
  /*
   * Krishna: "where to tick checkbox?" — nowhere. It could never appear.
   *
   *   scan starts a preview with no options
   *     → FirewallRuleDiscoverer returns [] (backend gates on include_firewall)
   *       → no firewall items in the results
   *         → the adopt checkbox only renders when there ARE firewall items
   *
   * The backend's own ServerSyncTest proves the first step: `runSync()` with
   * no options records 0 firewall items. The UI only ever called it that way,
   * so firewall rules could not be adopted through the panel at all.
   *
   * Scanning is read-only. The opt-in belongs at adopt, which is where the
   * half-imported-rule-list risk actually is, and that checkbox is unchanged.
   */
  const panel = fs.readFileSync("components/sync/sync-panel.jsx", "utf8");
  const code = panel.replace(/\/\*[\s\S]*?\*\//g, "").replace(/\{\/\*[\s\S]*?\*\/\}/g, "");

  assert.match(code, /includeFirewall: mode === "preview"/, "a scan must ask for firewall rules");

  // The caller's own value has to win for `apply`, or ticking the box would
  // be ignored and unticking it would adopt them anyway.
  const spreadAfter = code.indexOf("...options") > code.indexOf('includeFirewall: mode === "preview"');
  assert.ok(spreadAfter, "apply's explicit choice must override the default");

  // And the adopt path still passes the checkbox through.
  assert.match(code, /begin\("apply", \{ only, includeFirewall \}\)/);
});
