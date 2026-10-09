import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import { logReadResponseSchema } from "../lib/schemas/log.js";
import { fileEntrySchema, fileContentSchema } from "../lib/schemas/file.js";
import { inFolder } from "../lib/files/path-helpers.js";

const read = (p) => fs.readFileSync(p, "utf8");
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
  // Rename / Copy / Compress / Extract share one rule, in the path dialog.
  assert.match(read(`${F}/target-path-dialog.jsx`), /const trimmed = normalize\(place\(value\.trim\(\)\)\);/);
  // A leading slash means the application's top folder.
  assert.equal(inFolder("/backup.zip", "wp-content"), "backup.zip");
});

test("a missing destination folder is named by the API on `target`, with no probe request (9 Oct)", () => {
  // FI-B: the API answers 422 errors.target "The folder … does not exist", so the
  // listing probe that guessed it from a 404 is gone.
  assert.ok(!fs.existsSync("lib/files/missing-folder.js"));
  assert.match(read(`${F}/target-path-dialog.jsx`), /errors\?\.target\?\.\[0\]/);
  assert.match(read(`${F}/bulk-dialogs.jsx`), /errors\?\.target\?\.\[0\]/);
  for (const f of ["target-path-dialog.jsx", "bulk-dialogs.jsx"]) assert.doesNotMatch(read(`${F}/${f}`), /destinationMissing|folderMissing/);
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

test("the editor sends the version it opened and stops on changed_on_disk (OLD-27, 9 Oct)", () => {
  const s = read(`${F}/file-editor-dialog.jsx`);
  assert.match(s, /saveFileContent\(appId, file\.path, contents, loaded\?\.version\)/);
  assert.match(s, /reason === "changed_on_disk"/);
  assert.match(s, /disabled=\{!dirty \|\| saving \|\| loading \|\| stale\}/);
  assert.match(read("lib/api/files.js"), /\.\.\.\(version \? \{ version \} : \{\}\)/);
  assert.equal(fileContentSchema.parse({ path: "a", content: "", version: "x".repeat(40) }).version, "x".repeat(40));
});
