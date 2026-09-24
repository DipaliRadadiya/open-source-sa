import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";

const DIALOG = fs.readFileSync("components/applications/backups/turn-off-backups-dialog.jsx", "utf8");
const PANEL = fs.readFileSync("components/applications/backups/backups-panel.jsx", "utf8");
const PAGE = fs.readFileSync("app/(app)/applications/[application]/backups/page.jsx", "utf8");
const API = fs.readFileSync("lib/api/backups.js", "utf8");

test("the delete flag is only sent when the backups go too", () => {
  assert.match(API, /api\.delete\(`\/applications\/\$\{applicationId\}\/backup-target`/);
  assert.match(API, /deleteBackups \? \{ delete_backups: true \} : undefined/);
});

test("with backups, confirming requires deleting them — the API refuses otherwise", () => {
  assert.match(DIALOG, /confirmDisabled=\{\(hasBackups && !deleteBackups\)/);
  // An unknown count (failed history read) is treated as "has backups".
  assert.match(DIALOG, /hasBackups = count === null \|\| count > 0/);
});

test("pausing sends the target back unchanged apart from enabled", () => {
  assert.match(DIALOG, /retention_count: target\.retention_count/, "a lower count would delete backups on save");
  assert.match(DIALOG, /enabled: false/);
});

test("gated on backup manage, and blocked while a run is in flight", () => {
  assert.match(PAGE, /canTurnOff=\{canRestore\}/);
  assert.match(PAGE, /const canRestore = can\(permissions, "backup", "manage"\)/);
  assert.match(PANEL, /turnOffBlockedReason=\{running \|\| queued \|\| busy \? t\("turnOff\.running"\) : null\}/);
});
