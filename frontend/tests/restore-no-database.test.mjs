import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";

const root = path.join(import.meta.dirname, "..");
const read = (p) => fs.readFileSync(path.join(root, p), "utf8");

test("a full backup with no database offers its files only", async () => {
  const { restorableTypes } = await import("../lib/schemas/backup.js");
  assert.deepEqual(restorableTypes("full", false), ["filesystem"]);
  assert.deepEqual(restorableTypes("full", true), ["full", "filesystem", "database"]);
  // An API without has_database keeps the type's own list.
  assert.deepEqual(restorableTypes("full"), ["full", "filesystem", "database"]);
  assert.deepEqual(restorableTypes("full", null), ["full", "filesystem", "database"]);
  assert.deepEqual(restorableTypes("filesystem", false), ["filesystem"]);
});

test("the schema keeps has_database", () => {
  assert.match(read("lib/schemas/backup.js"), /has_database: z\.boolean\(\)\.nullish\(\)/);
});

test("the restore dialog reads the backup's own answer, on every page that opens it", () => {
  const dialog = read("components/backups/restore-dialog.jsx");
  assert.match(dialog, /restorableTypes\(backup\?\.type, backup\?\.has_database\)/);
  // "Contains: Full" would contradict the single "Files only" choice.
  assert.match(dialog, /backup\?\.type === "full" && allowed\.length === 1 && allowed\[0\] === "filesystem"/);
});

test("a dead install job is a named step everywhere", async () => {
  const { PROVISION_STEPS } = await import("../lib/applications/provision-steps.js");
  assert.ok(PROVISION_STEPS.has("worker"));
  for (const locale of ["en", "de", "es", "fr", "hi", "ja", "pt", "ru"]) {
    const steps = JSON.parse(read(`messages/${locale}.json`)).applications.details.steps;
    assert.ok(steps.worker, locale);
  }
});

test("a list row says Files only for a full backup with no database", async () => {
  const { backupTypeLabel } = await import("../lib/backups/type-label.js");
  const t = (key) => `t:${key}`;
  assert.equal(backupTypeLabel({ type: "full", type_title: "Files and database", has_database: false }, t), "t:types.filesystem");
  assert.equal(backupTypeLabel({ type: "full", type_title: "Files and database", has_database: true }, t), "Files and database");
  assert.equal(backupTypeLabel({ type: "full", type_title: "Files and database" }, t), "Files and database");
  assert.match(read("components/backups/backups-history-table.jsx"), /backupTypeLabel\(row\.original, t\)/);
  assert.equal(read("components/backups/backups-cards.jsx").match(/backupTypeLabel\(backup, t\)/g).length, 2);
});

// A container backup's type (volumes_config…) is not one the restore request
// accepts; sent, it was a 422 and the restore never started (20 Oct).
test("a container backup is restored without naming a type", () => {
  // `type` only when there is a choice; the confirm (and the safety-copy choice) always.
  assert.match(read("components/backups/restore-dialog.jsx"), /\.\.\.\(allowed\.length \? \{ type \} : \{\}\),\s*confirm: confirm\.trim\(\),/);
});
