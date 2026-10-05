import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import { roleName, roleDescription } from "../lib/roles/role-label.js";

// Admin QA on the fresh server, 2026-10-05 (reports/fresh/F-admin.md).
const root = path.join(import.meta.dirname, "..");
const read = (p) => fs.readFileSync(path.join(root, p), "utf8");
const en = JSON.parse(read("messages/en.json"));

test("the built-in Administrator role is shown translated; other roles as written", () => {
  const t = (key) => `t:${key}`;
  const admin = { name: "Administrator", description: "Full access", slug: "administrator", is_system: true };
  assert.equal(roleName(admin, t), "t:builtIn.administrator.name");
  assert.equal(roleDescription(admin, t), "t:builtIn.administrator.description");
  const own = { name: "Support", description: "Our team", slug: "support", is_system: false };
  assert.equal(roleName(own, t), "Support");
  assert.equal(roleDescription(own, t), "Our team");
  // A user's roles arrive as {id, name}; the page adds the flag from the roles list.
  assert.match(read("app/admin/users/page.jsx"), /roles: \(u\.roles \?\? \[\]\)\.map\(\(r\) => \(\{ \.\.\.r, \.\.\.\(roleById\.get\(r\.id\) \?\? \{\}\) \}\)\)/);
  assert.ok(en.roles.builtIn.administrator.name);
});

test("a failed update run from another build is not shown as this panel's state", () => {
  const src = read("components/admin/panel-update/panel-update-panel.jsx");
  assert.match(src, /run\.from_commit !== state\.installed\.commit_hash/);
  assert.match(src, /const visibleRun = staleRun \|\|/);
});

test("user and role saves name what failed", () => {
  assert.match(read("components/admin/users/user-form-dialog.jsx"), /fallback: isEdit \? t\("toast\.updateFailed"\) : t\("toast\.createFailed"\)/);
  assert.match(read("components/admin/users/reset-password-dialog.jsx"), /fallback: t\("toast\.resetFailed"\)/);
  assert.match(read("components/admin/roles/role-form.jsx"), /fallback: isEdit \? t\("toast\.updateFailed"\) : t\("toast\.createFailed"\)/);
});

test("closing a row menu returns focus to it; deleting a row sends focus to Add", () => {
  for (const f of ["components/admin/users/user-row-actions.jsx", "components/admin/roles/role-row-actions.jsx"]) {
    const src = read(f);
    assert.doesNotMatch(src, /onCloseAutoFocus=\{\(e\) => e\.preventDefault\(\)\}/, f);
    assert.match(src, /if \(!openingDialog\.current\) return;/, f);
  }
  assert.match(read("components/admin/users/delete-user-dialog.jsx"), /\[data-users-add\]/);
  assert.match(read("components/admin/roles/delete-role-dialog.jsx"), /\[data-roles-add\]/);
  assert.match(read("components/admin/users/users-toolbar.jsx"), /aria-label=\{t\("columns\.accountType"\)\}/);
});

test("the sync dialog speaks plainly and role delete says when it is refused", () => {
  assert.doesNotMatch(en.roles.sync.description, /seeder/i);
  assert.match(en.roles.delete.description, /only role/);
});
