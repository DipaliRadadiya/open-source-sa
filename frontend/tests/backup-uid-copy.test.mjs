import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import { backupHasArchive, BACKUP_SUCCEEDED } from "../lib/schemas/backup.js";

/*
 * Reported: the copy button beside a backup's destination "copies a random
 * string" — on a row reading Failed / No archive / "The archive could not be
 * uploaded".
 *
 * The string is not random. `uid` is documented in BackupResource as "the name
 * this archive has in the bucket", and it looks arbitrary because it is a UUID.
 * The fault is narrower and worse: it is stamped in `Backup::booted()` when the
 * row is CREATED, long before any upload is attempted, so a run that failed to
 * upload still carries one — and the button offered to copy the name of an
 * object that was never written.
 */

const table = fs.readFileSync("components/backups/backups-history-table.jsx", "utf8");

test("the object name is offered only when there is an object", () => {
  assert.match(table, /uid && backupHasArchive\(row\.original\.status\)/);

  const code = table.replace(/\/\*[\s\S]*?\*\//g, "").replace(/^\s*\/\/.*$/gm, "");
  assert.doesNotMatch(
    code,
    /\{uid \? \(\s*<CopyButton/,
    "the uid is copyable again regardless of whether the archive exists",
  );
});

test("it asks the same question the Download button asks", () => {
  /*
   * Both answer "is there an archive?". Asking that with two separate literals
   * is exactly how they came to disagree before: Download tested for a
   * "completed" status the API never sends, so it was blocked on every backup
   * that had ever worked.
   */
  assert.match(table, /import \{ BACKUP_IN_FLIGHT, backupHasArchive \}/);

  const download = fs.readFileSync("components/backups/download-backup-button.jsx", "utf8");
  assert.match(download, /backupHasArchive\(backup\.status\)/);

  // And the predicate itself still means what both callers assume.
  assert.equal(backupHasArchive(BACKUP_SUCCEEDED), true);
  for (const status of ["failed", "pending", "running", "verifying"]) {
    assert.equal(backupHasArchive(status), false, `${status} must not claim an archive`);
  }
});

test("the destination name itself is unaffected", () => {
  // Only the copy button is conditional — which bucket a failed backup was
  // aimed at is still worth knowing, and is the first thing to check.
  assert.match(table, /<span className="truncate text-sm" title=\{name\}>/);
  assert.match(table, /if \(!name\) return/);
});
