import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import { logReadResponseSchema } from "../lib/schemas/log.js";
import { fileEntrySchema } from "../lib/schemas/file.js";
import { inFolder } from "../lib/files/path-helpers.js";

const read = (p) => fs.readFileSync(p, "utf8");
const LOCALES = ["en", "es", "hi", "de", "fr", "pt", "ja", "ru"];
const F = "components/applications/files";

test("a journal / privileged / worker log parses (cursor null), and a bad shape is a failed read", () => {
  // Shape from his panel: GET /logs/journal → cursor: null.
  const journal = { log: { key: "journal", label: "System — Journal", group: "system", kind: "journal", lines: ["a"], cursor: null, truncated: false } };
  assert.equal(logReadResponseSchema.safeParse(journal).success, true);
  const getLog = read("lib/logs/get-log.js");
  assert.match(getLog, /: \{ status: "failed", log: null \};/);
  assert.doesNotMatch(getLog, /: \{ status: "ok", log: null \};/);
});

test("the listing keeps where a symlink points and whether it is broken", () => {
  const e = fileEntrySchema.parse({ name: "l", type: "symlink", link_target: "target.txt", link_broken: true });
  assert.equal(e.link_target, "target.txt");
  assert.equal(e.link_broken, true);
});

test("a bare archive name stays in the folder being looked at", () => {
  assert.equal(inFolder("backup.zip", "wp-content/uploads"), "wp-content/uploads/backup.zip");
  assert.equal(inFolder("other/backup.zip", "wp-content"), "other/backup.zip");
  assert.equal(inFolder("backup.zip", ""), "backup.zip");
  assert.match(read(`${F}/bulk-dialogs.jsx`), /compressFiles\(appId, paths, archiveFormat\.complete\(inFolder\(target\.trim\(\), dirname\(paths\[0\]\)\)\)\)/);
  const single = read(`${F}/compress-dialog.jsx`);
  assert.match(single, /normalize=\{\(value\) => format\.complete\(inFolder\(value, folder\)\)\}/);
  assert.match(single, /destinationOf=\{\(value\) => dirname\(inFolder\(value, folder\)\)\}/);
});

test("a missing destination folder is named, not blamed on the item", () => {
  const helper = read("lib/files/missing-folder.js");
  assert.match(helper, /error\?\.response\?\.status !== 404/);
  assert.match(read(`${F}/target-path-dialog.jsx`), /t\("targetDialog\.folderMissing", \{ folder:/);
  assert.match(read(`${F}/bulk-dialogs.jsx`), /t\("targetDialog\.folderMissing", \{ folder \}\)/);
  assert.match(read(`${F}/extract-dialog.jsx`), /targetIsFolder/);
  for (const l of LOCALES) assert.ok(JSON.parse(read(`messages/${l}.json`)).applications.files.targetDialog.folderMissing.includes("{folder}"), l);
});

test("upload says 'uploaded' after the list shows the files", () => {
  const s = read(`${F}/upload-dialog.jsx`);
  assert.match(s, /refreshThen\(\(\) => \{[\s\S]{0,200}report\(\);/);
  assert.match(s, /\} else \{\s*report\(\);\s*\}/);
});

test("the file version list is a radio group", () => {
  const s = read(`${F}/restore-file-backup-dialog.jsx`);
  assert.match(s, /role="radiogroup"/);
  assert.match(s, /role="radio"\s*aria-checked=\{active\}/);
});

test("Japanese clear-log titles: no stray space, full-width question mark", () => {
  const ja = read("messages/ja.json");
  assert.match(ja, /"\{label\}をクリアしますか？"/);
  assert.match(ja, /"\{label\} ログをクリアしますか？"/);
});
