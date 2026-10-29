import assert from "node:assert/strict";
import fs from "node:fs";
import test from "node:test";
import { backupTargetKey, isRetryable, newestBackupIds } from "../lib/backups/retry-eligibility.js";

const read = (p) => fs.readFileSync(p, "utf8");
const app = (id, status, application_id = 1) => ({ id, status, application_id });

// Support report (29 Oct): after Retry, the old failed row still offered Retry. Retry leaves the
// failed row as it was and starts a new backup, so only a target's newest backup offers it.
test("on one target's own list, only the newest failed row is retryable", () => {
  const rows = [app(12, "verified"), app(11, "failed"), app(10, "failed")];
  assert.equal(isRetryable(rows[1], rows), false, "a newer backup exists");
  assert.equal(isRetryable(rows[2], rows), false);
  const failedNewest = [app(13, "failed"), ...rows];
  assert.equal(isRetryable(failedNewest[0], failedNewest), true, "the newest one failed");
  assert.equal(isRetryable(app(14, "verified"), [app(14, "verified")]), false, "not failed");
});

test("on a mixed list another site's newer backup does not count", () => {
  const rows = [app(20, "verified", 2), app(19, "failed", 1), { id: 18, status: "failed", database_id: 7 }];
  assert.equal(isRetryable(rows[1], rows), true);
  assert.equal(isRetryable(rows[2], rows), true);
  assert.equal(backupTargetKey(rows[2]), "database:7");
  assert.equal(backupTargetKey({ id: 1, status: "failed" }), null);
  // An archived row names no target: unchanged, still offered.
  assert.equal(isRetryable({ id: 1, status: "failed" }, rows), true);
});

test("a known newest id (coverage) beats the page: filtered or older pages never show Retry", () => {
  const page2 = [app(5, "failed")];
  const known = newestBackupIds({
    applications: [{ application_id: 1, last_backup: { id: 9 } }],
    databases: [{ database: { id: 7 }, lastBackup: { id: 30 } }],
  });
  assert.equal(known.get("application:1"), 9);
  assert.equal(isRetryable(page2[0], page2, known), false, "site 1's newest is id 9, not on this page");
  assert.equal(isRetryable(app(9, "failed"), [], known), true);
  // A plain object works too: the server page hands it to a client component.
  assert.equal(isRetryable(app(9, "failed"), [], Object.fromEntries(known)), true);
  assert.equal(isRetryable({ id: 29, status: "failed", database_id: 7 }, [], Object.fromEntries(known)), false);
});

test("the panel, the history list, the table and the cards use the rule", () => {
  assert.match(read("components/applications/backups/backups-panel.jsx"), /retryable: \(backup\) => isRetryable\(backup, backups\),/);
  assert.match(read("components/backups/backups-history.jsx"), /retryable: \(backup\) => isRetryable\(backup, backups, newest\),/);
  assert.match(read("app/(app)/backups/history/page.jsx"), /newest=\{Object\.fromEntries\(newestBackupIds\(\{ applications: siteNewest, databases: coverage\.rows \}\)\)\}/);
  assert.match(read("components/backups/backups-history-table.jsx"), /canRun && \(retryable\?\.\(backup\) \?\? true\) \?/);
  assert.match(read("components/backups/backups-cards.jsx"), /canRun && \(retryable\?\.\(backup\) \?\? true\) \?/);
});
