import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";

const root = path.join(import.meta.dirname, "..");
const read = (p) => fs.readFileSync(path.join(root, p), "utf8");

/*
 * A list with a per-row action must not track "which row is busy" in one slot.
 * With `useState(null)`, clicking a second row while the first was still
 * working moved the spinner to the second, and whichever request finished
 * first cleared the other's. Krishna found it on Files → Calculate; the same
 * shape was in eight more lists (2026-09-23).
 */

test("lists whose rows can work side by side keep one busy state per row", () => {
  for (const file of [
    "components/applications/files/trash-panel.jsx",
    "components/firewall/rules-card.jsx",
    "components/backups/coverage-card.jsx",
    "components/integrations/git/accounts-card.jsx",
    "components/integrations/storage/destinations-card.jsx",
    "components/sync/sync-panel.jsx",
  ]) {
    const src = read(file);
    assert.match(src, /usePendingKeys\(\)/, `${file} uses the per-row helper`);
    assert.doesNotMatch(src, /set(BusyId|TestingId|PendingKey)\(/, `${file} still has a single busy slot`);
  }
  // Children read a list, not a single id.
  assert.match(read("components/backups/coverage-table.jsx"), /busyIds\.includes\(application\.id\)/);
  assert.match(read("components/backups/coverage-cards.jsx"), /busyIds\.includes\(application\.id\)/);
  assert.match(read("components/firewall/rules-cards.jsx"), /pending\.includes\(rule\.id\)/);
  assert.match(read("components/sync/sync-results.jsx"), /pendingKeys\.includes\(key\)/);
  assert.match(read("components/sync/ignored-sheet.jsx"), /pendingKeys\.includes\(ignoreKey\(ignore\)\)/);
  assert.match(read("components/applications/files/files-panel.jsx"), /const \[sizingPaths, setSizingPaths\] = useState\(\[\]\);/);
});

test("lists where only one can run at a time lock the other rows and say why", () => {
  // Installing a PHP extension runs apt and restarts PHP; a second run while
  // the first holds the lock fails.
  const php = read("components/php/extensions-card.jsx");
  assert.match(php, /pending && pending\.name !== extension\.name\s*\? t\("extensions\.waitForOther", \{ name: pending\.name \}\)/);
  assert.match(php, /async function toggle\(extension\) \{\s*if \(pending\) return;/);
  // One deploy at a time.
  const deploy = read("components/applications/deployment/deploy-history-card.jsx");
  assert.match(deploy, /disabled=\{!canManage \|\| busyId !== null \|\| running\}/);
  assert.match(deploy, /t\("busy"\)/);
});

test("the helper keeps every key, and removes only its own", () => {
  const hook = read("hooks/use-pending-keys.js");
  assert.match(hook, /current\.includes\(key\) \? current : \[\.\.\.current, key\]/);
  assert.match(hook, /current\.filter\(\(entry\) => entry !== key\)/);
});
