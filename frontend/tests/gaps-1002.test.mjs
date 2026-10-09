import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";

const read = (p) => fs.readFileSync(new URL(`../${p}`, import.meta.url), "utf8");

test("create: a generated system user is removed only when the server refused (4xx)", () => {
  // After a 5xx or no answer the application may exist; deleting its user would break it.
  const src = read("components/applications/create-application-form.jsx");
  assert.match(src, /const refused = Boolean\(error\.response\) && error\.response\.status < 500;/);
  assert.match(src, /if \(newUser\?\.id && refused\) \{\s*const removed = await deleteSystemUser/);
  assert.match(src, /form\.setValue\("generate_system_user", false\);\s*form\.setValue\("system_user_id", String\(newUser\.id\)/);
});

test("a failed Retry setup says the retry did not start, not the old step again", () => {
  const card = read("components/applications/provisioning-card.jsx");
  assert.match(card, /apiMessage\(error, t\("retryFailed"\)\)/);
  for (const l of ["en", "es", "hi", "de", "fr", "pt", "ja", "ru"]) assert.ok(JSON.parse(read(`messages/${l}.json`)).applications.details.retryFailed, l);
});

test("database forms say what failed when the server errors without field errors", () => {
  assert.match(read("lib/api/handle-validation-error.js"), /apiMessage\(error, fallback \?\? genericErrorMessage\(\)\)/);
  for (const [f, key] of [["create-database-dialog", "createFailed"], ["add-user-dialog", "addFailed"], ["user-password-dialog", "passwordFailed"], ["edit-user-dialog", "saveFailed"], ["connection-dialog", "saveFailed"]]) {
    assert.match(read(`components/databases/${f}.jsx`), new RegExp(`fallback: t\\("${key}"\\)`), f);
  }
});

test("account forms say what failed on a server error", () => {
  assert.match(read("components/account/profile-form.jsx"), /fallback: t\("profile\.failed"\)/);
  assert.match(read("components/account/change-password-form.jsx"), /fallback: t\("password\.failed"\)/);
});

test("sign in and register say what failed on a server error", () => {
  assert.match(read("components/forms/login-form.jsx"), /fallback: t\("signInFailed"\)/);
  assert.match(read("components/forms/register-form.jsx"), /fallback: t\("registerFailed"\)/);
});

test("app dashboard cards size by their own width, not the window's", () => {
  const src = read("components/applications/source-card.jsx");
  assert.match(src, /cn\("@container\/source", className\)/);
  assert.match(src, /@2xl\/source:flex-row/);
  assert.match(src, /<CardContent className="@container flex flex-1 flex-col gap-3">/);
  assert.match(src, /@xs:grid-cols-2 @2xl:grid-cols-4/);
  assert.equal((read("components/applications/backup-card.jsx").match(/whitespace-normal/g) ?? []).length, 2);
});

test("axe fixes from the orange pass", () => {
  const table = read("components/applications/applications-table.jsx");
  assert.match(table, /allLabel=\{t\("filters\.allStatuses"\)\}\s*label=\{t\("filters\.statusLabel"\)\}/);
  assert.match(table, /allLabel=\{t\("filters\.allTypes"\)\}\s*label=\{t\("filters\.typeLabel"\)\}/);
  assert.match(read("components/ui/badge.jsx"), /default: "rounded-md bg-primary\/10 px-1\.5 text-\[color-mix\(in_oklch,var\(--primary\)_80%,var\(--foreground\)\)\] dark:text-primary/);
  const card = read("components/applications/backup-card.jsx");
  assert.ok(card.indexOf("unprotectedRisk") > card.indexOf("</dl>"), "the note sits outside the <dl>");
});

test("support references are not faded below 4.5:1", () => {
  assert.doesNotMatch(read("components/applications/source-card.jsx"), /font-mono text-xs opacity-90/);
  assert.doesNotMatch(read("components/databases/database-exports.jsx"), /break-all opacity-80/);
});

test("an app whose process is stopped says Stopped, not Running (Krishna 2 Oct)", () => {
  const src = read("components/applications/application-status-badge.jsx");
  assert.match(src, /export function isProcessDown\(application\)/);
  assert.match(src, /if \(isProcessDown\(application\)\) \{\s*return \(\s*<Badge variant="warning"[^>]*>\s*(?:<PillDot[^>]*\/>\s*)?\{t\("processStoppedBadge"\)\}/);
  assert.match(src, /down\s*\?\s*t\("processStoppedBadge"\)/);
  for (const l of ["en", "es", "hi", "de", "fr", "pt", "ja", "ru"]) assert.ok(JSON.parse(read(`messages/${l}.json`)).applications.processStoppedBadge, l);
});
