import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";

// System Users QA, 2026-09-28 (reports/server/system-users.md, SU-1 … SU-18).
const root = path.join(import.meta.dirname, "..");
const read = (p) => fs.readFileSync(path.join(root, p), "utf8");
const su = (f) => read(`components/system-users/${f}`);

test("SU-1: every change is announced once the list shows it", () => {
  for (const f of ["access-switch.jsx", "shell-select.jsx", "delete-system-user-dialog.jsx"]) {
    const src = su(f);
    assert.match(src, /useRefresh/, f);
    assert.doesNotMatch(src, /^\s*router\.refresh\(\);/m, f);
  }
  for (const f of ["create-system-user-dialog.jsx", "system-user-password-dialog.jsx"]) {
    const src = su(f);
    const wait = src.indexOf("await new Promise((resolve) => refreshThen(resolve))");
    assert.ok(wait > 0, f);
    assert.ok(src.indexOf("toast.success", wait) > wait, `${f}: toast after the refresh`);
  }
});

test("SU-2/12: the table fits at 1280 — cards below xl, home under the name, shell sized to its title", () => {
  const table = su("system-users-table.jsx");
  assert.match(table, /className="xl:hidden"/);
  assert.match(table, /className="hidden xl:block"/);
  assert.doesNotMatch(table, /accessorKey: "home_path"/);
  assert.match(su("shell-select.jsx"), /h-8 w-auto max-w-72/);
});

test("SU-3: a user deleted elsewhere says so on every action", () => {
  for (const f of ["access-switch.jsx", "shell-select.jsx", "delete-system-user-dialog.jsx", "system-user-password-dialog.jsx", "ssh-keys-dialog.jsx"]) {
    const src = su(f);
    assert.match(src, /status === 404/, f);
    assert.match(src, /toast\.alreadyGone/, f);
  }
});

test("SU-4/5: the keys dialog stays open for the next key, and caps the label", async () => {
  const src = su("ssh-keys-dialog.jsx");
  const add = src.slice(src.indexOf("async function onAdd"), src.indexOf("async function onRemove"));
  assert.doesNotMatch(add, /handleOpenChange\(false\)/);
  assert.match(add, /form\.reset\(\)/);
  assert.match(add, /await load\(\)/);
  const { sshKeySchema } = await import("../lib/schemas/system-user.js");
  const key = "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl test";
  assert.equal(sshKeySchema.safeParse({ name: "x".repeat(255), public_key: key }).success, true);
  const long = sshKeySchema.safeParse({ name: "x".repeat(256), public_key: key });
  assert.equal(long.success, false);
  assert.equal(long.error.issues[0].message, "max255");
});

test("SU-6: switching SSH off says it waits for Access & security while that is unsaved", () => {
  assert.match(su("access-switch.jsx"), /!v && sshEnforced === false \? t\("toast\.sshOffNotEnforced"\)/);
});

test("SU-8/9: deleting the last row leaves the page directly and lands focus on Add", () => {
  const del = su("delete-system-user-dialog.jsx");
  assert.match(del, /navigateThen\(\{ page: prevPage > 1 \? prevPage : undefined \}/);
  assert.match(del, /querySelector\("\[data-su-add\]"\)\?\.focus\(\)/);
  assert.match(su("system-users-table.jsx"), /data-su-add/);
  // Escape on the menu hands focus back to ⋯; only a dialog-opening item keeps it off.
  assert.match(su("system-user-row-actions.jsx"), /if \(!openingDialog\.current\) return;/);
});

test("SU-9/11: clearing the search and refreshing keep keyboard focus", () => {
  const search = read("components/data-table/search-input.jsx");
  assert.match(search, /setValue\(""\);\s*input\.current\?\.focus\(\);/);
  assert.match(su("system-users-table.jsx"), /querySelector\("\[data-search-input\]"\)\?\.focus\(\)/);
  const refresh = read("components/data-table/refresh-button.jsx");
  assert.doesNotMatch(refresh, /\sdisabled=\{pending\}/);
  assert.match(refresh, /onClick=\{pending \? undefined : refresh\}/);
});

test("SU-10: the password dialog opens on the new password, not the eye button", () => {
  assert.match(su("system-user-password-dialog.jsx"), /initialFocus="input\[name=password\]"/);
  assert.match(read("components/ui/form-modal.jsx"), /querySelector\(initialFocus\)/);
});

test("SU-13/14/15/18: shells seeded, one error wording, no commands in the copy, app names at once", () => {
  assert.match(su("create-system-user-dialog.jsx"), /useState\(initialShells\)/);
  assert.match(su("system-users-table.jsx"), /initialShells=\{shells\}/);
  for (const f of ["access-switch.jsx", "shell-select.jsx", "delete-system-user-dialog.jsx", "ssh-keys-dialog.jsx"]) {
    assert.doesNotMatch(su(f), /toast\.failed/, f);
  }
  for (const locale of ["en", "es", "hi", "de", "fr", "pt", "ja", "ru"]) {
    const s = JSON.parse(read(`messages/${locale}.json`)).systemUsers;
    assert.doesNotMatch(s.create.subtitle, /useradd/, locale);
    assert.doesNotMatch(s.delete.description, /userdel/, locale);
    assert.match(s.toast.alreadyGone, /\{username\}/, locale);
    assert.ok(s.toast.sshOffNotEnforced, locale);
  }
  assert.match(su("system-user-apps-dialog.jsx"), /const shown = apps \?\? minimal/);
});

test("SU-16/17: viewers reach SSH keys read-only; the enforcement notice is for managers", () => {
  const table = su("system-users-table.jsx");
  assert.doesNotMatch(table, /canManage\s*\?\s*\[\s*\{\s*id: "actions"/);
  const actions = su("system-user-row-actions.jsx");
  assert.match(actions, /<SshKeysDialog[^>]*canManage=\{canManage\}/);
  assert.match(actions, /disabled=\{!canManage \|\| ownsApps\}/);
  const keys = su("ssh-keys-dialog.jsx");
  assert.match(keys, /\{!canManage \? null : removing === key\.id/);
  assert.match(table, /canManage && sshEnforced === false/);
});
