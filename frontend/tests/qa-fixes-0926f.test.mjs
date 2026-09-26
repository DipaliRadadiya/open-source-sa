import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import { createStagingFormSchema } from "../lib/schemas/application-staging.js";

const read = (p) => fs.readFileSync(p, "utf8");
const LOCALES = ["en", "es", "hi", "de", "fr", "pt", "ja", "ru"];
const page = read("app/(app)/applications/[application]/staging/page.jsx");
const panel = read("components/applications/staging/staging-panel.jsx");
const push = read("components/applications/staging/push-staging-dialog.jsx");
const create = read("components/applications/staging/create-staging-dialog.jsx");

test("ST-A: an administrator on a non-WordPress site is told staging is WordPress-only", () => {
  assert.match(page, /getCurrentUser\(\)\.catch\(\(\) => null\)\)\?\.is_admin/);
  assert.match(page, /is_admin\) return <TypeNotSupported application=\{application\} t=\{t\} \/>;/);
});

test("ST-B: push is only offered for a copy that finished being created", () => {
  assert.match(panel, /const ready = staging\?\.status === "active";/);
  assert.match(panel, /\{canManage && ready \? \(/);
  assert.match(panel, /t\("push\.notReady"\)/);
});

test("ST-D: a failed push or create re-reads the page", () => {
  assert.match(push, /toast\.error\(apiMessage\(error, t\("failed"\)\)\);\n\s*\/\/[^\n]*\n\s*\/\/[^\n]*\n\s*router\.refresh\(\);/);
  assert.match(create, /router\.refresh\(\);\n\s*\}\n\s*\} finally/);
});

test("ST-E: view-only is told why there is no button", () => {
  assert.equal((panel.match(/t\("noPermission"\)/g) ?? []).length, 2);
});

test("ST-F: the length limit is named", () => {
  const r = createStagingFormSchema.safeParse({ domain: "a".repeat(250) + ".nip.io" });
  assert.ok(r.error.issues.some((i) => i.message === "max255"));
});

test("ST-G: dialogs put focus back on what opened them", () => {
  for (const f of ["components/ui/alert-dialog.jsx", "components/ui/dialog.jsx"]) {
    const s = read(f);
    assert.match(s, /const focus = useReturnFocus\(onCloseAutoFocus\);/, f);
    assert.match(s, /onCloseAutoFocus=\{focus\.onCloseAutoFocus\}\n\s*\{\.\.\.props\}>\n\s*<OpenerCapture onCapture=\{focus\.capture\} \/>/, f);
  }
});

test("ST-H: a failed read carries the server's message; a 403 is the no-access page", () => {
  assert.match(read("lib/applications/get-applications.js"), /getApplicationStaging[\s\S]*?message: result\.message,/);
  assert.match(page, /if \(staging\?\.status === 403\) return <PermissionDenied/);
});

test("ST-I: no separator left hanging when the copy age wraps", () => {
  assert.doesNotMatch(panel, />\s*·\s*</);
});

test("ST-C: the push wording matches the backend, in every locale", () => {
  for (const l of LOCALES) {
    const s = JSON.parse(read(`messages/${l}.json`)).applications.staging;
    for (const k of ["noPermission"]) assert.ok(s[k], `${l} ${k}`);
    assert.ok(s.push.notReady && s.pushDialog.failSafe, l);
    const m = s.pushDialog.modes;
    for (const [mode, row] of [["files", "undo"], ["database", "undo"], ["full", "undo"]]) assert.ok(m[mode][row], `${l} ${mode}.${row}`);
  }
  const en = JSON.parse(read("messages/en.json")).applications.staging.pushDialog.modes;
  assert.match(en.files.deletes, /Uploads are kept/);
  assert.doesNotMatch(en.files.undo, /nothing is copied first/);
  assert.match(en.database.undo, /None from the panel/);
});
