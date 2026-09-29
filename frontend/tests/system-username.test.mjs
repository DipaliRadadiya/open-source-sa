import test from "node:test";
import assert from "node:assert/strict";
import { suggestSystemUsername as suggest } from "../lib/applications/system-username.js";

test("follows the backend's naming: slug, no leading digits, reserved names prefixed", () => {
  assert.equal(suggest("QA WordPress"), "qa-wordpress");
  assert.equal(suggest("Café Blog"), "cafe-blog");
  assert.equal(suggest("2024 Campaign"), "campaign");
  assert.equal(suggest("root"), "app-root");
  assert.equal(suggest("日本語"), "");
});

test("a taken name gets a suffix and stays within 32 characters", () => {
  assert.equal(suggest("My Blog", ["my-blog", "my-blog-2"]), "my-blog-3");
  const long = suggest("a".repeat(40), ["a".repeat(32)]);
  assert.ok(long.length <= 32 && long.endsWith("-2"));
  assert.match(long, /^[a-z_][a-z0-9_-]{0,31}$/);
});
