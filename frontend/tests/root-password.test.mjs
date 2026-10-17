import { test } from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import { rootPasswordSchema, connectionSchema } from "../lib/schemas/database.js";

const check = (password, confirmation = password) =>
  rootPasswordSchema.safeParse({ password, password_confirmation: confirmation });
const messages = (result) => result.error.issues.map((i) => i.message);

test("root password follows ChangeRootPasswordRequest: 12–128, no line breaks, must match", () => {
  assert.ok(check("a".repeat(12)).success);
  assert.ok(check(`it's "quoted" and spaced`).success, "quotes and spaces are fine (v7 refused them)");
  assert.deepEqual(messages(check("a".repeat(11))), ["min12"]);
  assert.deepEqual(messages(check("a".repeat(129))), ["max128"]);
  assert.deepEqual(messages(check("abcdefghijkl\nmnop")), ["noControlChars"]);
  assert.deepEqual(messages(check("a".repeat(12), "b".repeat(12))), ["passwordsMismatch"]);
});

test("the connection keeps has_root_password, and null on other engines", () => {
  assert.equal(connectionSchema.parse({ engine: "mariadb", has_root_password: true }).has_root_password, true);
  assert.equal(connectionSchema.parse({ engine: "postgresql", has_root_password: null }).has_root_password, null);
});

test("Root password is offered on the engine strip for running MySQL/MariaDB only", () => {
  const bar = fs.readFileSync(new URL("../components/databases/engine-bar.jsx", import.meta.url), "utf8");
  assert.match(bar, /running\.filter\(\(engine\) => engine\.engine === "mysql" \|\| engine\.engine === "mariadb"\)/);
  assert.match(bar, /<RootPasswordDialog/);
  const api = fs.readFileSync(new URL("../lib/api/databases.js", import.meta.url), "utf8");
  assert.match(api, /\/databases\/connections\/\$\{encodeURIComponent\(engine\)\}\/root-password/);
});
