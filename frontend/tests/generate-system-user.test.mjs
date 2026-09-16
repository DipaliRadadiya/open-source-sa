import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = path.join(import.meta.dirname, "..");
const read = (p) => fs.readFileSync(path.join(root, p), "utf8");

const form = read("components/applications/create-application-form.jsx");
const schema = read("lib/schemas/application.js");

const { locales } = await import("../i18n/routing.js");

test("generating is the default", () => {
  // But only for someone who may create accounts — the API refuses to generate
  // otherwise, and a form that defaults to a refusal is wrong on open.
  assert.match(form, /generate_system_user: canCreateSystemUser,/);
  assert.match(schema, /generate_system_user: z\.boolean\(\)\.default\(true\)/);
});

test("the payload carries one answer, never both", () => {
  // The API refuses a body with a generate flag and an id together: a client
  // that sends both has not decided, and choosing for it is how a site ends up
  // owned by an account nobody picked.
  assert.match(
    form,
    /\.\.\.\(values\.generate_system_user\s*\?\s*\{ generate_system_user: true \}\s*:\s*\{ system_user_id: Number\(values\.system_user_id\) \}\)/,
  );
});

test("switching into generate mode clears any id already chosen", () => {
  // Otherwise a stale id rides along beside the flag and the API 422s.
  assert.match(form, /if \(choice\.generate\) \{\s*form\.setValue\("system_user_id", ""/);
});

test("the picker is hidden while generating", () => {
  // Nothing to pick between, and the Create link would open a dialog whose
  // result the form would then ignore.
  assert.match(form, /\{generateSystemUser \? null : \(/);
  assert.match(form, /canCreateSystemUser && !generateSystemUser \?/);
});

test("the choice is not offered without permission", () => {
  // One possible answer means no choice to present; a disabled radio pair
  // would be two controls saying so.
  //
  // Matched on the gate and the grid, NOT on the gap: pinning a spacing value
  // here made a purely visual change fail a test about permissions, which
  // teaches the next reader to edit the test rather than think about it.
  assert.match(form, /\{canCreateSystemUser \? \(\s*<div className="grid gap-/);
});

test("the review row reads as answered when generating", () => {
  // The name does not exist yet, so a blank would look like something still to
  // fill in rather than a decision already made.
  assert.match(form, /ready: generateSystemUser \|\| Boolean\(systemUserId\)/);
  assert.match(form, /t\("form\.systemUserWillBeCreated"\)/);
});

test("an existing user is still required when not generating", () => {
  assert.match(schema, /if \(!values\.generate_system_user && !values\.system_user_id\)/);
  // The message has to land on system_user_id — that is where the control is,
  // and where the form scrolls to.
  assert.match(schema, /path: \["system_user_id"\]/);
});

test("every string exists in every locale", () => {
  const keys = [
    "generateSystemUser",
    "generateSystemUserHint",
    "pickSystemUser",
    "pickSystemUserHint",
    "systemUserWillBeCreated",
  ];

  for (const locale of locales) {
    const messages = JSON.parse(read(`messages/${locale}.json`));
    for (const key of keys) {
      const value = messages.applications?.form?.[key];
      assert.equal(typeof value, "string", `${locale}: form.${key} must exist`);
      assert.ok(value.length > 0, `${locale}: form.${key} must not be empty`);
    }
  }
});
