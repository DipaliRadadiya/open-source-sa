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

test("ticking the firewall box counts the rules it is about to adopt", () => {
  /*
   * Krishna: "why this button is disabled?" — because the dialog computed the
   * adopt twice and the two copies disagreed.
   *
   * Firewall rules have no tick-box of their own; the warning checkbox gates
   * them instead, so `selected` never contains the type. That type WAS added
   * to the `only` list sent to the API, and was NOT added to the list the
   * summary counts. So on a server whose scan found nothing else, ticking the
   * box adopted three rules while the dialog said "Nothing will be added
   * across 0 types" and disabled the button that would have done it.
   *
   * One value now feeds both, which is the only thing that stops a third
   * reader picking the wrong one.
   */
  const dialog = fs.readFileSync("components/sync/adopt-dialog.jsx", "utf8");
  const code = dialog.replace(/\/\*[\s\S]*?\*\//g, "").replace(/\{\/\*[\s\S]*?\*\/\}/g, "");

  assert.match(code, /const adopting = useMemo\(/);
  assert.match(code, /includeFirewall \? \[\.\.\.selected, FIREWALL_RESOURCE_TYPE\] : selected/);
  assert.match(code, /selectedTypes: adopting/, "the count must use it");
  assert.match(code, /only: adopting,/, "and so must the request");

  // Exactly one place builds the list. Two is how they drifted.
  assert.equal((code.match(/FIREWALL_RESOURCE_TYPE\] : selected/g) ?? []).length, 1);
});

test("a Drive destination that is not connected offers Connect on its row", () => {
  /*
   * The panel said "Not connected yet. Use Connect to approve access" and the
   * row's only button was Replace credentials — Connect lived at the bottom of
   * the Edit dialog. Read from `config.connected`, the flag the API publishes
   * for exactly this question, not from the failed-test category (which is
   * wrong for this case anyway: the backend derives it with
   * `Str::after($key, 'storage.test.')` and this driver's key is
   * `storage.oauth.not_connected`, so the whole key comes through).
   */
  const row = fs.readFileSync("components/integrations/storage/destination-row.jsx", "utf8");
  const code = row.replace(/\/\*[\s\S]*?\*\//g, "").replace(/\{\/\*[\s\S]*?\*\/\}/g, "");

  assert.match(code, /destination\.provider === "google_drive_oauth" && destination\.config\?\.connected === false/);
  assert.match(code, /<GoogleDriveConnect destination=\{destination\} compact \/>/);

  // Above the live test result, or pressing Test buries the button again —
  // which is the bug.
  assert.ok(
    code.indexOf("needsConnect ? (") < code.indexOf(") : result ? ("),
    "the connect prompt must outrank a failed test result",
  );
});

test("adding a Drive destination is not reported as a failure", () => {
  /*
   * The whole flow, not the step in the screenshot — which is the habit that
   * produced three half-fixes in one session.
   *
   * Approving access needs the destination to exist first, so a brand-new
   * Drive destination is never connected and the post-create probe can only
   * fail. The reader filled the form in correctly and got a red error toast
   * for it, with nothing to press from where they stood.
   *
   * Now: no probe for this provider, a neutral message naming the next step,
   * and the Connect button waiting on the row behind the dialog.
   */
  const dialog = fs.readFileSync("components/integrations/storage/connect-dialog.jsx", "utf8");
  const code = dialog.replace(/\/\*[\s\S]*?\*\//g, "").replace(/\{\/\*[\s\S]*?\*\/\}/g, "");

  const connectBranch = code.indexOf('provider === "google_drive_oauth"');
  const probe = code.indexOf("probeDestination(");
  assert.ok(connectBranch > 0, "no branch for the provider that cannot pass");
  assert.ok(connectBranch < probe, "the branch must come before the probe");
  assert.match(code, /toast\.info\(t\("addedNeedsConnect"\)/);
  assert.match(code, /return;/);
});

test("an unconnected Drive destination warns before backups are pointed at it", () => {
  /*
   * A hole opened by the fix above, found by auditing rather than by someone
   * hitting it.
   *
   * Adding a Drive destination no longer probes it — the probe could only
   * fail — so a brand-new unconnected one has `last_test_success: null`, never
   * asked. The backup form only warned on an outright FAILED test, so that
   * destination could be chosen as a backup target in silence and discovered
   * at 3 a.m.
   *
   * Reads `config.connected`, the same flag the storage row reads, so the two
   * screens cannot disagree about whether a destination is ready.
   */
  const form = fs.readFileSync("components/backups/backup-settings-fields.jsx", "utf8");
  const code = form.replace(/\/\*[\s\S]*?\*\//g, "").replace(/\{\/\*[\s\S]*?\*\/\}/g, "");

  assert.match(code, /chosenDestination\?\.config\?\.connected === false/);
  assert.match(code, /last_test_success === false \|\| notConnected/);
  // Its own sentence: "the test failed" is not what happened.
  assert.match(code, /t\("destinationNotConnected"/);
});

test("all three reads in the adopt dialog use the same list", () => {
  // The count, the request and the dependency warning. Two of them disagreeing
  // is what disabled the button while the request would have worked.
  const dialog = fs.readFileSync("components/sync/adopt-dialog.jsx", "utf8");
  const code = dialog.replace(/\/\*[\s\S]*?\*\//g, "").replace(/\{\/\*[\s\S]*?\*\/\}/g, "");

  assert.match(code, /selectedTypes: adopting/);
  assert.match(code, /only: adopting,/);
  assert.match(code, /unmetDependencies\(adopting\)/);
  assert.doesNotMatch(code, /unmetDependencies\(selected\)/);
});
