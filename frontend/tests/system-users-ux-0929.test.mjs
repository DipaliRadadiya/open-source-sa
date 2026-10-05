import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import { offeredShells } from "../lib/system-users/offered-shells.js";

// System Users UX review, 2026-09-29 (reports/server/system-users.md, UX-1 … UX-11).
const root = path.join(import.meta.dirname, "..");
const read = (p) => fs.readFileSync(path.join(root, p), "utf8");
const su = (f) => read(`components/system-users/${f}`);
const locales = ["en", "es", "de", "fr", "hi", "pt", "ja", "ru"];
const msgs = (l) => JSON.parse(read(`messages/${l}.json`)).systemUsers;

test("UX-1: a sudo user's SSH switch shows on, locked, with the reason", () => {
  const src = su("access-switch.jsx");
  assert.match(src, /const viaSudo = field === "ssh" && user\.sudo && user\.shell_allows_login !== false/);
  assert.match(src, /checked=\{viaSudo \? true : shown\}/);
  assert.match(src, /disabled=\{!canManage \|\| locked\}/);
  assert.match(src, /t\("sshViaSudo"\)/);
  const create = su("create-system-user-dialog.jsx");
  assert.match(create, /: sshViaSudo\s*\?\s*t\("sshViaSudo"\)/);
  assert.match(create, /checked=\{toggle\.locked \? toggle\.lockedValue : field\.value\}/);
  assert.match(create, /lockedValue: !noLoginShell/);
  for (const l of locales) assert.ok(msgs(l).sshViaSudo, l);
});

test("UX-2/3/7/10: wording", () => {
  const en = msgs("en");
  assert.doesNotMatch(en.create.passwordHint, /Set password/);
  // One line (Krishna 5 Oct): the password is already visible in the Password column.
  assert.doesNotMatch(en.create.passwordHint, /⋯/);
  assert.ok(en.create.passwordHint.length < 70);
  assert.equal(en.password.title, en.password.open);
  assert.equal(en.create.publicKey, "SSH public key");
  assert.ok(en.sshNotEnforced.body.length < 140);
  for (const l of locales) {
    const m = msgs(l);
    assert.equal(m.password.title, m.password.open, `${l}: dialog title matches the menu item`);
    assert.ok(m.sshNotEnforced.body.length < 200, l);
  }
});

test("UX-4: the legacy no-login shell is offered only to an account that has it", () => {
  const shells = [
    { value: "/bin/bash" },
    { value: "/usr/sbin/nologin" },
    { value: "/bin/false" },
  ];
  assert.deepEqual(offeredShells(shells, "/bin/bash").map((s) => s.value), ["/bin/bash", "/usr/sbin/nologin"]);
  assert.equal(offeredShells(shells, "/bin/false").length, 3);
  assert.equal(offeredShells(shells.slice(0, 2), "/bin/bash").length, 2);
});

test("UX-5/6: zero applications is plain text; the popup links to each application", () => {
  assert.match(su("apps-cell.jsx"), /<span className="text-xs text-muted-foreground">\{t\("appsCount", \{ count: 0 \}\)\}<\/span>/);
  const dialog = su("system-user-apps-dialog.jsx");
  assert.match(dialog, /href=\{`\/applications\/\$\{app\.id\}`\}/);
  assert.match(dialog, /prefetch=\{false\}/);
});

test("UX-9: the password dialog can generate, filling both fields", () => {
  const src = su("system-user-password-dialog.jsx");
  assert.match(src, /generatePassword\(\)/);
  assert.match(src, /setValue\("password_confirmation", value/);
});

test("UX-11: a locked switch carries a marker in the spinner slot", () => {
  assert.match(read("components/ui/pending-switch.jsx"), /\) : \(\s*aside\s*\)\}/);
  assert.match(su("access-switch.jsx"), /aside=\{canManage && locked \?/);
});

test("UX-11: every disabled switch lets the tap through to its reason (phones)", () => {
  assert.match(read("components/ui/switch.jsx"), /data-disabled:pointer-events-none/);
});

test("Services: the locked boot switch explains itself on a tap too", () => {
  const src = read("components/services/service-boot-switch.jsx");
  assert.match(src, /<ReasonTooltip reason=\{canToggle \? null : t\("noPermission"\)\}>\{control\}<\/ReasonTooltip>/);
});
