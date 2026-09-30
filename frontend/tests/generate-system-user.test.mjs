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

test("a generated user is created with the shown name and password, then used by id", () => {
  // Krishna 2026-09-29: the name and password are shown and editable, which
  // `generate_system_user` cannot carry — it picks its own name, sets no password.
  assert.match(form, /createSystemUser\(\{\s*username: values\.system_user_username,\s*\.\.\.\(values\.system_user_password \? \{ password: values\.system_user_password \} : \{\}\)/);
  assert.match(form, /payload\.system_user_id = newUser\?\.id;/);
  assert.doesNotMatch(form, /generate_system_user: true/);
  // A refused application leaves no account behind.
  assert.match(form, /if \(newUser\?\.id\) \{\s*const removed = await deleteSystemUser\(newUser\.id\)/);
});
test("switching into generate mode clears any id already chosen", () => {
  // Otherwise a stale id rides along beside the flag and the API 422s.
  assert.match(form, /if \(choice\.generate\) \{\s*form\.setValue\("system_user_id", ""/);
});

test("the picker is hidden while generating, and there is no second create-user action", () => {
  assert.match(form, /\{generateSystemUser \? null : \(/);
  assert.doesNotMatch(form, /CreateSystemUserDialog|form\.createSystemUser/);
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

test("the review row names the user that will be created", () => {
  assert.match(form, /ready: generateSystemUser \? Boolean\(newUsername\) : Boolean\(systemUserId\)/);
  assert.match(form, /t\("form\.systemUserNew", \{ username: newUsername \}\)/);
});

test("the new user's name and password are validated like System Users does", () => {
  assert.match(schema, /\["system_user_username", usernameField,/);
  assert.match(schema, /\["system_user_password", passwordField,/);
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
    "systemUserNew",
    "systemUserUsername",
    "systemUserPassword",
    "systemUserPasswordHint",
    "systemUserCreateFailed",
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

test("a second click while creating does not create the user twice", () => {
  assert.match(form, /if \(submitting\.current\) return;\s*submitting\.current = true;/);
});

test("a user that could not be removed after a refused application is reported and offered", () => {
  assert.match(form, /if \(!removed\) \{\s*toast\.warning\(t\("form\.systemUserLeftBehind", \{ username: newUser\.username \}\)[\s\S]{0,60}router\.refresh\(\);/);
  for (const locale of locales) {
    const value = JSON.parse(read(`messages/${locale}.json`)).applications.form.systemUserLeftBehind;
    assert.match(value, /\{username\}/, locale);
  }
});

test("the review row takes you to the Username field while generating", () => {
  assert.match(form, /target: generateSystemUser \? "system_user_username" : "system_user_id"/);
});
