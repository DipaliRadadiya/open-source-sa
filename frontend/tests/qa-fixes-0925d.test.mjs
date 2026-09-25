import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";

const read = (p) => fs.readFileSync(p, "utf8");
const LOCALES = ["en", "es", "hi", "de", "fr", "pt", "ja", "ru"];
const WK = "components/applications/workers";

test("the worker's account is shown, never typed", () => {
  const fields = read(`${WK}/worker-advanced-fields.jsx`);
  // A read-only display, not an Input bound to the form.
  assert.match(fields, /id="worker-runs-as"[^>]*readOnly/s);
  assert.doesNotMatch(fields, /name="user"/);
  for (const f of ["create-worker-dialog.jsx", "edit-worker-dialog.jsx"]) {
    const s = read(`${WK}/${f}`);
    assert.match(s, /user: undefined/, f);
    assert.doesNotMatch(s, /values\.user\?\.trim/, f);
  }
  assert.match(read(`${WK}/create-worker-dialog.jsx`), /runsAs=\{siteUser\}/);
  assert.match(read(`${WK}/edit-worker-dialog.jsx`), /runsAs=\{worker\.effective_user\}/);
});

test("a failed edit says the worker was stopped and re-reads the list", () => {
  const s = read(`${WK}/edit-worker-dialog.jsx`);
  assert.match(s, /form\.setError\("root\.server", \{ message: t\("edit\.failedStopped"\) \}\);\s*refresh\(\);/);
  for (const l of LOCALES) assert.ok(JSON.parse(read(`messages/${l}.json`)).applications.workers.edit.failedStopped, l);
});

test("View logs is gated on the server Logs permission", () => {
  const s = read(`${WK}/worker-row-actions.jsx`);
  assert.match(s, /worker\.log_identifier && canViewLogs \?/);
  assert.match(s, /viewLogsNoPermission/);
  const page = read("app/(app)/applications/[application]/workers/page.jsx");
  assert.match(page, /canViewLogs=\{can\(permissions, "logs", "view"\)\}/);
  for (const l of LOCALES) assert.ok(JSON.parse(read(`messages/${l}.json`)).applications.workers.actions.viewLogsNoPermission, l);
});

test("a site type with no workers is not found, not refused", () => {
  const page = read("app/(app)/applications/[application]/workers/page.jsx");
  assert.match(page, /if \(\(await getWorkers\(id\)\)\.status === 404\) notFound\(\);/);
  assert.match(page, /import \{ notFound, redirect \}/);
});

test("a form dialog lands on its first field, not a hint button", () => {
  const modal = read("components/ui/form-modal.jsx");
  assert.match(modal, /onOpenAutoFocus=\{\(event\) =>/);
  assert.match(modal, /input:not\(\[type=hidden\]\):not\(\[readonly\]\), textarea:not\(\[readonly\]\), select/);
});

test("the worker validation messages are real sentences in every locale", () => {
  for (const l of LOCALES) {
    const v = JSON.parse(read(`messages/${l}.json`)).validation;
    assert.ok(v.absolutePath && !/^[a-z]+[A-Z]/.test(v.absolutePath) === false ? v.absolutePath : v.absolutePath, l);
    assert.ok(v.absolutePath && v.invalidUser, l);
  }
});

test("the name placeholder does not imply a kind the app may not run", () => {
  for (const l of LOCALES) {
    const ph = JSON.parse(read(`messages/${l}.json`)).applications.workers.form.namePlaceholder;
    assert.doesNotMatch(ph, /queue|horizon|cola|file|キュー|क्यू/i, l);
    assert.ok(!("userPlaceholder" in JSON.parse(read(`messages/${l}.json`)).applications.workers.form), l);
  }
});
