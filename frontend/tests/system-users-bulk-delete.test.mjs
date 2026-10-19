import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";

const root = path.join(import.meta.dirname, "..");
const read = (p) => fs.readFileSync(path.join(root, p), "utf8");

test("the outcome keeps every kept account's reason, keyed by id", async () => {
  const { bulkDeleteOutcome } = await import("../lib/system-users/bulk-delete.js");
  // The real reply from .71 on 19 Oct.
  const outcome = bulkDeleteOutcome({
    deleted: false,
    succeeded: [16],
    failed: [
      { id: 1, username: "qawp", reason: "has_applications" },
      { id: 17, username: "qabulkb", reason: "has_processes" },
      { id: 18, username: "x", reason: "something_new", reference: "abc123" },
    ],
  });
  assert.deepEqual(outcome.succeeded, [16]);
  assert.deepEqual(outcome.failures[1], { reason: "has_applications", reference: null });
  assert.deepEqual(outcome.failures[17], { reason: "has_processes", reference: null });
  // An unknown reason never reaches t() as a raw key.
  assert.deepEqual(outcome.failures[18], { reason: "failed", reference: "abc123" });
});

test("the outcome survives an empty or odd reply", async () => {
  const { bulkDeleteOutcome } = await import("../lib/system-users/bulk-delete.js");
  assert.deepEqual(bulkDeleteOutcome(), { succeeded: [], failures: {} });
  assert.deepEqual(bulkDeleteOutcome({ succeeded: null, failed: [{ reason: "failed" }] }), { succeeded: [], failures: {} });
});

test("every reason the helper can return has a translation", async () => {
  const { BULK_REASONS, BULK_FIXES } = await import("../lib/system-users/bulk-delete.js");
  for (const locale of ["en", "de", "es", "fr", "hi", "ja", "pt", "ru"]) {
    const { reason, fix } = JSON.parse(read(`messages/${locale}.json`)).systemUsers.bulk;
    for (const key of BULK_REASONS) assert.ok(reason[key], `${locale}: reason.${key}`);
    for (const key of BULK_FIXES) assert.ok(fix[key], `${locale}: fix.${key}`);
  }
});

test("bulk delete is one request for the whole selection", () => {
  assert.match(read("lib/api/system-users.js"), /api\.delete\("\/system-users", \{ data: \{ ids, end_sessions: endSessions \} \}\)/);
  const dialog = read("components/system-users/bulk-delete-dialog.jsx");
  assert.match(dialog, /deleteSystemUsers\(users\.map\(\(user\) => user\.id\), \{ endSessions \}\)/);
  // A success toast only when nothing was kept; otherwise the reasons speak.
  assert.match(dialog, /if \(kept === 0\) toast\.success/);
});

test("the checkboxes are for managers only, and a reason sits under its user", () => {
  const table = read("components/system-users/system-users-table.jsx");
  assert.match(table, /<BulkFailure failure=\{table\.options\.meta\.failures\?\.\[row\.original\.id\]\} \/>/);
  assert.match(table, /<BulkDeleteDialog/);
  // What to do is said once in the banner, only for reasons that occurred.
  assert.match(table, /BULK_FIXES\.filter\(\(reason\) => Object\.values\(failures\)\.some/);
  assert.match(table, /canManage/);
  assert.match(read("components/system-users/system-users-cards.jsx"), /<BulkFailure/);
});
