import assert from "node:assert/strict";
import fs from "node:fs";
import test from "node:test";
import { stagingRunSchema } from "../lib/schemas/application-staging.js";

const read = (p) => fs.readFileSync(p, "utf8");
const LOCALES = ["en", "es", "de", "fr", "hi", "pt", "ja", "ru"];
const HINT = "A copy will be taken first, so you can undo this later if needed.";

// 57926891 (Dipali, 29 Oct): safety_copy on database restore and staging push/pull, default true.
test("a database restore sends the switch as safety_copy", () => {
  assert.match(read("lib/api/database-backups.js"), /restoreDatabaseBackup\(databaseId, backupId, confirm, safetyCopy = true\)[\s\S]*\{ confirm, safety_copy: safetyCopy \}/);
  assert.match(read("components/backups/restore-dialog.jsx"), /restoreDatabaseBackup\(database\.id, backup\.id, confirm\.trim\(\), safetyCopy\)/);
});

test("no undo is offered after a restore that took no copy", () => {
  const progress = read("components/backups/restore-progress.jsx");
  assert.equal(progress.match(/restore\.safety_backup_id && restore\.safety_copy !== false && !wasUndo \?/g)?.length, 2);
  assert.match(read("lib/schemas/backup.js"), /safety_copy: z\.boolean\(\)\.nullish\(\)/);
});

test("staging push and pull send safety_copy, and only offer the switch when the database is replaced", () => {
  const api = read("lib/api/applications.js");
  assert.match(api, /\{ mode, safety_copy: safetyCopy, \.\.\.\(backup \? \{ backup: true \} : \{\}\) \}/);
  assert.match(api, /staging\/pull`, \{ mode, safety_copy: safetyCopy \}/);
  for (const [file, id] of [["push-staging-dialog.jsx", "staging-push-safety-copy"], ["pull-staging-dialog.jsx", "staging-pull-safety-copy"]]) {
    const dialog = read(`components/applications/staging/${file}`);
    assert.match(dialog, /const \[safetyCopy, setSafetyCopy\] = useState\(true\);/, file);
    assert.match(dialog, /const replacesDatabase = mode === "database" \|\| mode === "full";/, file);
    assert.match(dialog, /safetyCopy: !replacesDatabase \|\| safetyCopy/, file);
    assert.match(dialog, new RegExp(`<SafetyCopySwitch\\s+id="${id}"`), file);
    assert.match(dialog, /!safetyCopy \? \(\s*<Caution tone="destructive" size="md">\s*<p>\{t\("noCopyWarning"\)\}<\/p>/, file);
  }
  // Push keeps its separate "back up the live application first" option.
  assert.match(read("components/applications/staging/push-staging-dialog.jsx"), /id="staging-push-backup"/);
});

test("the hint promises an undo only while the switch is on", () => {
  assert.match(read("components/backups/safety-copy-switch.jsx"), /\{checked \? <span className="block text-xs text-muted-foreground">\{hint\}<\/span> : null\}/);
});

test("the staging run keeps safety_copy", () => {
  assert.equal(stagingRunSchema.parse({ id: 1, kind: "push", status: "succeeded", safety_copy: false }).safety_copy, false);
});

test("the wording is in all 8 languages, with Dipali's English", () => {
  for (const locale of LOCALES) {
    const m = JSON.parse(read(`messages/${locale}.json`));
    const sets = [m.backups.database.restore, m.applications.staging.pushDialog, m.applications.staging.pullDialog];
    for (const set of sets) {
      assert.ok(set.safetyCopyLabel && set.noCopyWarning, locale);
    }
    assert.ok(m.applications.staging.pushDialog.safetyCopyHint && m.applications.staging.pullDialog.safetyCopyHint, locale);
    assert.equal(m.applications.staging.pullDialog.safetyDatabase, undefined, locale);
  }
  const en = JSON.parse(read("messages/en.json"));
  assert.equal(en.backups.database.restore.safetyPromise, HINT);
  assert.equal(en.applications.staging.pushDialog.safetyCopyHint, HINT);
  assert.equal(en.applications.staging.pullDialog.safetyCopyHint, HINT);
  assert.equal(en.backups.database.restore.noCopyWarning, "Without a copy, this can't be undone. If restoring the database fails partway, it can't be put back.");
});

test("with the copy off, a push no longer promises production is put back as it was", () => {
  assert.match(read("components/applications/staging/push-staging-dialog.jsx"), /t\(replacesDatabase && !safetyCopy \? "whileRunningNoCopy" : "whileRunning"\)/);
  for (const locale of LOCALES) assert.ok(JSON.parse(read(`messages/${locale}.json`)).applications.staging.pushDialog.whileRunningNoCopy, locale);
});
