import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import { frequencyLabel } from "../lib/backups/frequency.js";

// Backups QA on the fresh server, 2026-10-05 (reports/fresh/C4-backups.md).
const root = path.join(import.meta.dirname, "..");
const read = (p) => fs.readFileSync(path.join(root, p), "utf8");
const en = JSON.parse(read("messages/en.json"));

test("a paused schedule says so; manual and running ones do not", () => {
  const paused = (f) => `Paused: ${f}`;
  assert.equal(frequencyLabel({ enabled: false, frequency: "daily", frequency_title: "Daily" }, paused), "Paused: Daily");
  assert.equal(frequencyLabel({ enabled: true, frequency: "daily", frequency_title: "Daily" }, paused), "Daily");
  assert.equal(frequencyLabel({ enabled: false, frequency: "manual", frequency_title: "Manual only" }, paused), "Manual only");
  assert.equal(frequencyLabel(null, paused), undefined);
});

test("overview cards show a running or failed backup, like the table", () => {
  const cards = read("components/backups/coverage-cards.jsx");
  assert.match(cards, /BACKUP_IN_FLIGHT\.includes\(lastBackup\.status\) \|\| lastBackup\.status === "failed"/);
  assert.match(cards, /<BackupStatusBadge backup=\{lastBackup\} \/>/);
});

test("Run backup is refused on the overview for the site being restored", () => {
  const card = read("components/backups/coverage-card.jsx");
  assert.match(card, /const restoringId = RESTORE_IN_FLIGHT\.includes\(active\?\.status\) \? active\.application_id : null;/);
  for (const f of ["coverage-cards.jsx", "coverage-table.jsx"]) {
    const src = read(`components/backups/${f}`);
    assert.match(src, /restoringId === application\.id/, f);
    assert.match(src, /ta\("restoreRunning"\)/, f);
  }
});

test("viewers see Set up and Run backup disabled with the reason, not hidden", () => {
  for (const f of ["coverage-cards.jsx", "coverage-table.jsx"]) {
    const src = read(`components/backups/${f}`);
    assert.match(src, /th\("noPermission"\)/, f);
    assert.doesNotMatch(src, /canManage \? \(\s*<ReasonTooltip/, f);
  }
  assert.doesNotMatch(read("components/backups/backups-empty-state.jsx"), /\{!canManage \? null :/);
});

test("the restore banner ticks its finished time and never says 'now'", () => {
  const src = read("components/backups/restore-progress.jsx");
  assert.match(src, /parseApiWallClock\(restore\?\.finished_at\)/);
  assert.match(src, /now\.getTime\(\) - 1000/);
  assert.match(src, /suppressHydrationWarning/);
});

test("the Backups pages keep a finished restore's banner until it is dismissed", () => {
  const layout = read("app/(app)/backups/layout.jsx");
  assert.match(layout, /getActiveRestore\(undefined, \{\s*dismissed:/);
  assert.doesNotMatch(layout, /getRestores\(/);
});

test("lowering 'keep' warns with the number really deleted", () => {
  const src = read("components/backups/backup-settings-fields.jsx");
  assert.match(src, /Math\.max\(0, keptCount - Number\(retention\)\)/);
  assert.match(en.backups.form.warnings.retentionDownUnknown, /\{keep, plural/);
  assert.match(read("app/(app)/applications/[application]/backups/page.jsx"), /backup\.status === "verified" && !backup\.is_safety/);
});

test("retry names the application in its title and has a no-size wording", () => {
  const src = read("components/backups/backups-history.jsx");
  assert.match(src, /t\("retryConfirm\.title", \{ name:/);
  assert.match(en.backups.history.retryConfirm.descriptionNoSize, /does not retry the restore/i);
  assert.equal(en.backups.history.retryConfirm.unknownSize, undefined);
});

test("history and restores filters have names; an unknown application drops the filter", () => {
  for (const f of ["backups-history.jsx", "restores-list.jsx"]) {
    const src = read(`components/backups/${f}`);
    assert.equal((src.match(/label=\{t\("columns\./g) ?? []).length, 4, f);
    assert.doesNotMatch(src, /className="w-full sm:w-(44|48|56)"/, f);
  }
  for (const p of ["app/(app)/backups/history/page.jsx", "app/(app)/backups/restores/page.jsx"]) {
    assert.match(read(p), /if \(failed && status === 422\) redirectUnknownApplication\(/, p);
  }
});

test("ConfirmDialog's default buttons are translated", () => {
  const src = read("components/ui/confirm-dialog.jsx");
  assert.doesNotMatch(src, /cancelLabel = "Cancel"/);
  assert.match(src, /cancelLabel \?\?= tc\("cancel"\)/);
  assert.equal(en.common.cancel, "Cancel");
});

test("a destination whose last upload failed is named as failing, not 'never tested'", () => {
  const src = read("components/backups/destination-health.jsx");
  assert.match(src, /const UPLOAD_REASONS = \["upload_artifact", "upload_stalled"\];/);
  assert.match(src, /backup\.storage_destination_id === destination\.id \|\| backup\.storage_destination_name === destination\.name/);
  // The overview's `last_backup` carries no destination name, so the card passes the site's id.
  assert.match(read("components/backups/coverage-card.jsx"), /storage_destination_id: row\.target\?\.storage_destination_id/);
  assert.match(en.backups.destinationHealth.uploadFailedBody, /could not be uploaded/);
});

test("Restores offers Undo of the exact safety copy, and unlocks when a restore ends", () => {
  const button = read("components/backups/undo-restore-button.jsx");
  assert.match(button, /fetchBackup\(restore\.safety_backup_id\)/);
  assert.match(button, /preferred_type: restore\.type/);
  assert.doesNotMatch(read("components/backups/restores-list.jsx"), /findSafetyCopy/);
  assert.equal(en.backups.restores.findSafetyCopy, undefined);
  // Without the status fed back, every Restore/Undo stayed "restore in progress" until a reload.
  assert.match(read("components/backups/restore-watch.jsx"), /onStatusChange=\{\(status\) => setStatusById/);
});
