import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = path.join(import.meta.dirname, "..");
const history = fs.readFileSync(
  path.join(root, "components/backups/backups-history.jsx"),
  "utf8",
);
const table = fs.readFileSync(
  path.join(root, "components/backups/backups-history-table.jsx"),
  "utf8",
);
const messages = JSON.parse(
  fs.readFileSync(path.join(root, "messages/en.json"), "utf8"),
);

/*
 * Retry was the one action in this table that fired on click.
 *
 * Restore makes you type the site's domain. Clear opens a dialog. Retry — which
 * rebuilds a multi-gigabyte archive and re-uploads it, spending hours of
 * bandwidth and the same again of the destination's quota — went straight to
 * the API with nothing in between.
 *
 * It is also the only button a *failed* row offers, so after a restore failed,
 * "Retry" read as "retry the restore" while it actually started a brand new
 * backup. That is precisely how it was hit on 2026-09-22.
 */

test("retry asks before spending hours and gigabytes", () => {
  // The handler behind the button must open a confirmation, never call the API
  // directly. `onRetry: retry` is the regression.
  assert.match(history, /onRetry:\s*askRetry/);
  assert.doesNotMatch(history, /onRetry:\s*retry\b/);

  // And the confirmation has to actually reach the API on confirm, or the
  // button becomes decorative.
  assert.match(history, /onConfirm=\{confirmRetry\}/);
  assert.match(history, /async function confirmRetry\(\)[\s\S]*await retry\(backup\)/);
});

test("the confirmation names the site and the size it is about to re-upload", () => {
  // "A new backup" is abstract; "24.1 GB" is the number that makes someone
  // reconsider on a slow link.
  assert.match(history, /t\("retryConfirm\.description",[\s\S]*size:/);
  assert.match(history, /formatBytes\(retrying\.size_bytes, format\)/);

  const copy = messages.backups.history.retryConfirm;
  assert.ok(copy, "retryConfirm copy must exist");
  assert.match(copy.description, /\{name\}/);
  assert.match(copy.description, /\{size\}/);
  // The mismatch that caused the incident has to be said out loud.
  assert.match(copy.description, /does not retry the restore/i);
});

test("retry is blocked while a restore is in flight", () => {
  // The old guard knew only about backups, so after a restore failed — the
  // exact moment someone is clicking around trying to work out why — Retry was
  // fully enabled.
  const start = history.indexOf("retryBlockedFor:");
  assert.notEqual(start, -1, "retryBlockedFor must exist");
  const guard = history.slice(start, start + 900);

  assert.match(guard, /if \(restoreInFlight\) return /);
});

test("a blocked retry says why rather than going quiet", () => {
  // `disabledReason` is what the disabled-control check enforces panel-wide; a
  // button that is merely greyed out sends people to support.
  // Anchored on the ActionsCell branch specifically: `StatusCell` contains the
  // same comparison, and matching that one asserted against unrelated markup.
  const start = table.indexOf("if (backup.status === \"failed\") {");
  assert.notEqual(start, -1, "the failed-row action branch must exist");
  const branch = table.slice(start, start + 900);

  assert.match(branch, /disabled=\{busyId === backup\.id \|\| Boolean\(retryBlocked\)\}/);
  assert.match(branch, /disabledReason=\{retryBlocked\}/);
});
