import assert from "node:assert/strict";
import fs from "node:fs";
import test from "node:test";

import { importSchema } from "../lib/schemas/database.js";

const read = (p) => fs.readFileSync(p, "utf8");
const strip = (s) => s.replace(/\{\/\*[\s\S]*?\*\/\}/g, "").replace(/\/\/.*$/gm, "");
const LOCALES = ["en", "es", "de", "fr", "hi", "pt", "ja", "ru"];
const dialog = strip(read("components/databases/database-import.jsx"));
const api = strip(read("lib/api/databases.js"));

// 7d45173d: POST …/imports/{import}/start takes `safety_copy` (default true).
test("start sends safety_copy with confirm_replace, true unless the user unticked it", () => {
  assert.match(api, /export function startImport\(databaseId, importId, \{ safetyCopy = true \} = \{\}\)/);
  assert.match(api, /confirm_replace: true,\s*safety_copy: safetyCopy,/);
  assert.match(api, /uploadAndStartImport\(databaseId, file, \{ onProgress, onBegun, signal, safetyCopy = true \} = \{\}\)/);
  assert.match(api, /return startImport\(databaseId, importId, \{ safetyCopy \}\);/);
});

test("the confirm step has the safety-copy checkbox, ticked for every new file", () => {
  assert.match(dialog, /const \[safetyCopy, setSafetyCopy\] = useState\(true\);/);
  // Reset at the open site: a file chosen after an unticked one starts ticked again.
  assert.match(dialog, /setSafetyCopy\(true\);\s*setChosen\(file\);/);
  assert.match(dialog, /id="import-safety-copy"[\s\S]*?checked=\{safetyCopy\}/);
  assert.match(dialog, /safetyCopy: withCopy,/);
  // "Start again" repeats what this tab chose, else the API default.
  assert.match(dialog, /safetyChoiceRef\.current\.get\(row\.id\) \?\? true/);
});

test("unticked shows a red warning instead of the backup promise", () => {
  const warn = dialog.slice(dialog.indexOf("{!safetyCopy ? ("), dialog.indexOf("</Caution>"));
  assert.match(warn, /<Caution tone="destructive"/);
  assert.match(warn, /t\("noSafetyCopyWarning"\)/);
  assert.match(dialog, /\{safetyCopy \? \(\s*<span[^>]*>\s*<ShieldCheck[\s\S]*?t\("confirmSafety"\)/);
});

test("without a copy the stepper has no Backing up step", () => {
  assert.match(dialog, /IMPORT_STEPS\.filter\(\(step\) => step !== "backing_up"\)/);
  assert.match(dialog, /running\?\.safety_copy === false/);
});

test("history says whether a copy was taken, and keeps the API's failure message", () => {
  assert.equal(importSchema.parse({ id: 1, status: "failed", safety_copy: false }).safety_copy, false);
  assert.equal(importSchema.parse({ id: 1, status: "completed" }).safety_copy, undefined);
  assert.match(dialog, /row\.status !== "uploading" && row\.safety_copy != null \? t\(row\.safety_copy \? "withCopy" : "withoutCopy"\)/);
  assert.match(dialog, /\{row\.message \?\? t\("failedFallback"\)\}/);
});

test("every new string exists in all 8 languages, and no text promises a 7-day removal", () => {
  for (const locale of LOCALES) {
    const m = JSON.parse(read(`messages/${locale}.json`)).databases;
    for (const key of ["safetyCopy", "noSafetyCopyWarning", "withCopy", "withoutCopy", "backupNote"]) {
      assert.ok(m.import[key]?.length > 0, `${locale}: import.${key}`);
    }
    assert.doesNotMatch(JSON.stringify(m.exports) + JSON.stringify(m.import), /\b7\b/, `${locale}: a 7-day promise`);
  }
});
