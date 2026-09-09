import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import { orphanFieldNames, sharedFieldNames } from "../lib/applications/form-reset.js";

// The real field lists, from GET /site-types.
const PRESTASHOP = ["name", "domain", "system_user_id", "shop_name", "admin_first_name",
  "admin_last_name", "admin_email", "admin_password", "country", "language", "timezone",
  "table_prefix", "php_version", "web_root"].map((name) => ({ name }));
const WORDPRESS = ["name", "domain", "system_user_id", "site_title", "admin_user",
  "admin_email", "admin_password", "site_language", "timezone", "table_prefix",
  "php_version", "web_root"].map((name) => ({ name }));

// What the form owns rather than the site type. Mirrors COMMON_FIELD_NAMES.
const COMMON = new Set(["site_type", "name", "domain", "system_user_id", "git_source",
  "git_account_id", "repository", "repository_url", "branch"]);

test("PrestaShop's own answers do not follow you to WordPress", () => {
  const orphans = orphanFieldNames(PRESTASHOP, WORDPRESS, COMMON);
  assert.deepEqual(orphans.sort(), [
    "admin_first_name", "admin_last_name", "country", "language", "shop_name",
  ]);
  // The reported symptom: these survived a switch away and back.
  assert.ok(orphans.includes("shop_name"));
  assert.ok(orphans.includes("admin_first_name"));
});

test("a question both types ask keeps its answer", () => {
  const shared = sharedFieldNames(PRESTASHOP, WORDPRESS, COMMON);
  assert.deepEqual(shared.sort(), [
    "admin_email", "admin_password", "php_version", "table_prefix", "timezone", "web_root",
  ]);
  // Never dropped — the value is kept and only the stale error is cleared.
  const orphans = new Set(orphanFieldNames(PRESTASHOP, WORDPRESS, COMMON));
  for (const name of shared) assert.ok(!orphans.has(name), `${name} was dropped as well as kept`);
});

test("the form's own fields belong to no type and are never touched", () => {
  const both = [
    ...orphanFieldNames(PRESTASHOP, WORDPRESS, COMMON),
    ...sharedFieldNames(PRESTASHOP, WORDPRESS, COMMON),
  ];
  for (const name of COMMON) assert.ok(!both.includes(name), `${name} would be reset`);
});

test("switching to a type with nothing in common drops everything type-specific", () => {
  const nodebb = ["name", "domain", "system_user_id", "admin_username", "admin_email",
    "admin_password", "node_version", "app_port"].map((name) => ({ name }));
  const orphans = orphanFieldNames(WORDPRESS, nodebb, COMMON);
  assert.ok(orphans.includes("site_title"));
  assert.ok(orphans.includes("php_version"), "a PHP version has no meaning on a Node app");
  assert.deepEqual(sharedFieldNames(WORDPRESS, nodebb, COMMON).sort(), ["admin_email", "admin_password"]);
});

test("missing, empty and malformed field lists are answered, not thrown on", () => {
  assert.deepEqual(orphanFieldNames(undefined, undefined), []);
  assert.deepEqual(orphanFieldNames(null, WORDPRESS, COMMON), []);
  assert.deepEqual(sharedFieldNames(PRESTASHOP, null, COMMON), []);
  // Unnamed entries cannot be unregistered and must not reach the form.
  assert.deepEqual(orphanFieldNames([{}, { name: "" }, { name: "x" }], [], new Set()), ["x"]);
  // A type that declares the same field twice yields it once.
  assert.deepEqual(orphanFieldNames([{ name: "x" }, { name: "x" }], [], new Set()), ["x"]);
});

test("the form drops and clears on a type change", () => {
  const source = fs.readFileSync(
    new URL("../components/applications/create-application-form.jsx", import.meta.url),
    "utf8",
  );
  assert.match(source, /form\.unregister\(orphans\)/, "orphan fields are no longer dropped");
  assert.match(source, /form\.clearErrors\(shared\)/, "stale errors are no longer cleared");
  // `unregister`, not setValue(""): a blanked field stays dirty and keeps the
  // whole form marked unsaved over a type the user abandoned.
  assert.doesNotMatch(source, /orphans\.forEach[\s\S]{0,80}setValue/, "orphans are blanked instead of dropped");
});
