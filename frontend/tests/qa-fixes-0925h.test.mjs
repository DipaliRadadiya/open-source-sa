import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

const read = (path) => readFileSync(new URL(`../${path}`, import.meta.url), "utf8");
const LOCALES = ["en", "es", "hi", "de", "fr", "pt", "ja", "ru"];
const messages = Object.fromEntries(LOCALES.map((l) => [l, JSON.parse(read(`messages/${l}.json`))]));
const SECTION = read("components/applications/security/security-section.jsx");
const SCHEMA = read("lib/schemas/application.js");

test("PP-A: the status badge shows the saved state, not the switch", () => {
  assert.match(SECTION, /<Badge variant=\{alreadyProtected \? "success" : "muted"\}>/);
  assert.doesNotMatch(SECTION, /<Badge variant=\{field\.value/);
});

test("PP-B: username and password rules mirror UpdateBasicAuthRequest", () => {
  assert.match(SCHEMA, /\/\\s\/\.test\(username\)[\s\S]*"securityUsernameSpaces"/);
  assert.match(SCHEMA, /username\.length > 255[\s\S]*"max255"/);
  assert.match(SCHEMA, /data\.password\.length > 255[\s\S]*"max255"/);
  for (const l of LOCALES) assert.ok(messages[l].validation.securityUsernameSpaces, l);
});

test("PP-C: a refusal of the switch has a message slot", () => {
  const item = SECTION.slice(SECTION.indexOf('name="enabled"'), SECTION.indexOf('name="username"'));
  assert.match(item, /<FormMessage \/>/);
});

test("PP-D/E: warn on a conflicting protected site; say why a password is needed", () => {
  assert.match(SECTION, /conflicts && alreadyProtected \?/);
  assert.match(SECTION, /alreadyProtected && isDirty && !passwordValue \?/);
  for (const l of LOCALES) {
    assert.ok(messages[l].applications.security.unsupportedProtected, l);
    assert.ok(messages[l].applications.security.passwordAgainHint, l);
  }
});

test("PP-B: the schema refuses what the server refuses", async () => {
  const { securityFormSchema } = await import("../lib/schemas/application.js");
  const issue = (values) => securityFormSchema.safeParse({ enabled: true, password: "longenough", username: "qa", ...values }).error?.issues[0]?.message;
  assert.equal(issue({ username: "qa user" }), "securityUsernameSpaces");
  assert.equal(issue({ username: "qa:user" }), "securityUsernameColon");
  assert.equal(issue({ username: "a".repeat(256) }), "max255");
  assert.equal(issue({ password: "x".repeat(256) }), "max255");
  assert.equal(issue({}), undefined);
  // Turning protection off needs neither field.
  assert.equal(securityFormSchema.safeParse({ enabled: false, username: "", password: "" }).success, true);
});
