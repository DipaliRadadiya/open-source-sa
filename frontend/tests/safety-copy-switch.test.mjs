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
  assert.match(read("components/backups/safety-copy-switch.jsx"), /\{checked && hint \? <span className="block text-xs text-muted-foreground">\{hint\}<\/span> : null\}/);
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

// 9df058c8 + 0a00b09a (Dipali, 29 Oct): .env and file saves and their restores take safety_copy.
test(".env and file saves and restores send safety_copy, true unless switched off", () => {
  const env = read("lib/api/environment.js");
  assert.match(env, /saveEnvironment\(appId, \{ raw, restart = false, safetyCopy = true \}\)[\s\S]*?safety_copy: safetyCopy,/);
  assert.match(env, /restoreEnvironment\(appId, \{ backup, restart = false, safetyCopy = true \}\)[\s\S]*?safety_copy: safetyCopy,/);
  const files = read("lib/api/files.js");
  assert.match(files, /files\/content`, \{ path, content, safety_copy: safetyCopy,/);
  assert.match(files, /files\/content\/restore`, \{ path, backup, safety_copy: safetyCopy \}/);
});

test("each place has the switch, on by default, with the red warning when off", () => {
  for (const [file, id] of [
    ["components/applications/environment/environment-editor.jsx", "env-safety-copy"],
    ["components/applications/environment/restore-backup-dialog.jsx", "env-restore-safety-copy"],
    ["components/applications/environment/environment-history-card.jsx", "history-restore-safety-copy"],
    ["components/applications/files/file-editor-dialog.jsx", "file-safety-copy"],
    ["components/applications/files/restore-file-backup-dialog.jsx", "file-restore-safety-copy"],
  ]) {
    const s = read(file);
    assert.match(s, /const \[safetyCopy, setSafetyCopy\] = useState\(true\);/, file);
    assert.match(s, new RegExp(`id="${id}"`), file);
    assert.match(s, /label=\{tc\("keepFileCopy"\)\}/, file);
    assert.match(s, /\{tc\("keepFileCopyOff"\)\}/, file);
  }
  // A first .env save replaces nothing: no switch, and the request keeps the default.
  const editor = read("components/applications/environment/environment-editor.jsx");
  assert.match(editor, /\{canManage && env\.exists \? \(/);
  assert.match(editor, /safetyCopy: !env\.exists \|\| safetyCopy,/);
  // Restores reset to on when their window closes.
  for (const file of ["components/applications/environment/restore-backup-dialog.jsx", "components/applications/files/restore-file-backup-dialog.jsx", "components/applications/environment/environment-history-card.jsx"]) {
    assert.match(read(file), /setSafetyCopy\(true\);/, file);
  }
});

test("sentences that promise a backup change when the switch is off", () => {
  assert.match(read("components/applications/environment/restore-backup-dialog.jsx"), /safetyCopy \? t\("restore\.subtitle"\) : t\("restore\.subtitleNoCopy"\)/);
  assert.match(read("components/applications/files/restore-file-backup-dialog.jsx"), /safetyCopy \? t\("restore\.subtitle"\) : t\("restore\.subtitleNoCopy"\)/);
  assert.match(read("components/applications/environment/environment-history-card.jsx"), /safetyCopy \? t\("confirmBody"\) : t\("confirmBodyNoCopy"\)/);
  assert.match(read("components/applications/files/file-editor-dialog.jsx"), /t\(safetyCopy \? "editor\.subtitle" : "editor\.subtitleNoCopy"\)/);
  for (const locale of LOCALES) {
    const m = JSON.parse(read(`messages/${locale}.json`));
    const e = m.applications.environment, f = m.applications.files;
    for (const v of [m.common.keepFileCopy, m.common.keepFileCopyOff, e.restore.subtitleNoCopy, e.history.noCopyReason, e.history.confirmBodyNoCopy, f.restore.subtitleNoCopy, f.editor.subtitleNoCopy]) assert.ok(v, locale);
  }
  const en = JSON.parse(read("messages/en.json"));
  assert.equal(en.common.keepFileCopy, "Keep a copy of the current file");
  assert.equal(en.common.keepFileCopyOff, "Without a copy, you can't go back to the current version.");
});

test("a .env change saved without a copy is not called the first save", async () => {
  const { unrestorableReason } = await import("../lib/applications/environment-history.js");
  assert.equal(unrestorableReason({ backup: null, restorable: false, safety_copy: false }), "noCopy");
  assert.equal(unrestorableReason({ backup: null, restorable: false }), "first");
  assert.match(read("components/applications/environment/environment-history-card.jsx"), /blocked === "noCopy"\s*\? t\("noCopyReason"\)/);
});

test("a staging run refused for space shows the field's sentence", async () => {
  const { runErrorText } = await import("../lib/schemas/application-staging.js");
  const sentence = "Not enough free disk space on the server for the safety copy of the database. Free up space, or turn off the safety copy.";
  assert.equal(runErrorText({ message: "The given data was invalid.", errors: { safety_copy: [sentence] } }), sentence);
  assert.equal(runErrorText({ message: "Backup failed" }), "Backup failed");
  assert.equal(runErrorText(null), null);
  assert.match(read("components/applications/staging/staging-run-status.jsx"), /runErrorText\(run\.error\)/);
});

// 8d7b5341 + 6e9ea48f (Dipali, 29 Oct): php.ini, PHP settings, site fail2ban and ionCube take safety_copy.
test("PHP settings, site fail2ban and ionCube send safety_copy, true unless switched off", () => {
  const php = read("lib/api/php.js");
  assert.match(php, /savePhpIni\(version, contents, \{ safetyCopy = true \} = \{\}\)[\s\S]*?safety_copy: safetyCopy,/);
  assert.match(php, /\/settings`, \{ \.\.\.settings, safety_copy: safetyCopy \}\)/);
  assert.match(php, /\/ioncube`, \{ safety_copy: safetyCopy \}\)/);
  assert.match(php, /api\.delete\(`\/php\/versions\/\$\{encodeURIComponent\(version\)\}\/ioncube`, \{ data: \{ safety_copy: safetyCopy \} \}\)/);
  const apps = read("lib/api/applications.js");
  assert.match(apps, /filter_config_content: filter,\s*safety_copy: safetyCopy,/);
  // A DELETE carries the choice in its body.
  assert.match(apps, /api\.delete\(`\/applications\/\$\{id\}\/fail2ban`, \{ data: \{ safety_copy: safetyCopy \} \}\)/);
});

test("each settings place has the switch, on by default, with the red warning when off", () => {
  for (const [file, id] of [
    ["components/php/ini-editor.jsx", "ini-safety-copy-"],
    ["components/php/php-common-settings.jsx", "settings-safety-copy-"],
    ["components/php/ioncube-card.jsx", "ioncube-safety-copy-"],
    ["components/php/ioncube-card.jsx", "ioncube-remove-safety-copy-"],
    ["components/applications/fail2ban/fail2ban-panel.jsx", "fail2ban-safety-copy"],
    ["components/applications/fail2ban/fail2ban-panel.jsx", "fail2ban-remove-safety-copy"],
  ]) {
    const s = read(file);
    assert.match(s, /const \[safetyCopy, setSafetyCopy\] = useState\(true\);/, file);
    assert.ok(s.includes(`id=${id.endsWith("-") ? `{\`${id}` : `"${id}"`}`), `${file} ${id}`);
    assert.match(s, /label=\{tc\("keepSettingsCopy"\)\}/, file);
    assert.match(s, /\{tc\("keepSettingsCopyOff"\)\}/, file);
    assert.match(s, /setSafetyCopy\(true\);/, file);
  }
  // A first fail2ban setup replaces no files, so it is not asked and keeps the default.
  const f2b = read("components/applications/fail2ban/fail2ban-panel.jsx");
  assert.match(f2b, /\{canManage && !isSetup && changed \? \(/);
  assert.match(f2b, /safetyCopy: isSetup \|\| safetyCopy/);
  // php.ini's card stops promising a backup when there will be none.
  assert.match(read("components/php/ini-editor.jsx"), /tPhp\(safetyCopy \? "ini\.description" : "ini\.descriptionNoCopy"\)/);
  for (const locale of LOCALES) {
    const m = JSON.parse(read(`messages/${locale}.json`));
    assert.ok(m.common.keepSettingsCopy && m.common.keepSettingsCopyOff && m.php.ini.descriptionNoCopy, locale);
    assert.doesNotMatch(m.php.ini.descriptionNoCopy, /backed up|backup/i, locale);
  }
  const en = JSON.parse(read("messages/en.json"));
  assert.equal(en.common.keepSettingsCopy, "Keep a copy of the current settings");
  assert.equal(en.common.keepSettingsCopyOff, "Without a copy, the old settings are only kept in memory while the new ones are checked. If the panel stops during the save, they can't be put back.");
});
