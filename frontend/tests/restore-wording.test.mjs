import assert from "node:assert/strict";
import fs from "node:fs";
import test from "node:test";

const read = (p) => fs.readFileSync(p, "utf8");
const LOCALES = ["en", "es", "de", "fr", "hi", "pt", "ja", "ru"];

// Testers' sheet, 29 Oct: say plainly that a restore overwrites, then how it is undone.
test("the restore dialog warns in red that it overwrites, before the undo note", () => {
  const dialog = read("components/backups/restore-dialog.jsx");
  const warn = dialog.indexOf('<Caution tone="destructive" size="md">');
  assert.ok(warn > 0 && warn < dialog.indexOf('t("safetyPromise")'), "red warning before the safety note");
  assert.match(dialog, /<p>\s*\{t\("overwriteWarning"\)\}/);
});

test("application and database each have their own wording in all 8 languages", () => {
  for (const locale of LOCALES) {
    const b = JSON.parse(read(`messages/${locale}.json`)).backups;
    for (const [where, set] of [["application", b.restore], ["database", b.database.restore]]) {
      for (const key of ["descriptionShort", "safetyPromise", "overwriteWarning"]) assert.ok(set[key], `${locale} ${where}: ${key}`);
    }
  }
  const en = JSON.parse(read("messages/en.json")).backups;
  assert.equal(en.restore.overwriteWarning, "This will overwrite your current application.");
  assert.equal(en.database.restore.overwriteWarning, "This will overwrite your current database.");
});

// 36b72a4a (Dipali, 29 Oct): POST /backups/{backup}/restore takes safety_copy (default true).
test("an application restore has the copy switch, on by default and on again after closing; off says no undo", () => {
  const dialog = read("components/backups/restore-dialog.jsx");
  assert.match(dialog, /const \[safetyCopy, setSafetyCopy\] = useState\(true\);/);
  assert.match(dialog, /if \(!next\) setSafetyCopy\(true\);/);
  assert.match(dialog, /safety_copy: safetyCopy,/);
  assert.match(dialog, /\{offersSafetyChoice && !safetyCopy \? ` \$\{t\("noCopyWarning"\)\}` : null\}/);
  assert.match(dialog, /<Switch id="restore-safety-copy" checked=\{safetyCopy\}/);
  // A database restore always takes a copy: it keeps the promise, no switch.
  assert.match(dialog, /const offersSafetyChoice = !isDatabase;/);
  for (const locale of LOCALES) {
    const r = JSON.parse(read(`messages/${locale}.json`)).backups.restore;
    assert.ok(r.safetyCopyLabel && r.noCopyWarning, locale);
  }
});

