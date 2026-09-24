import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import { rootLockResponseSchema } from "../lib/schemas/application.js";

const read = (p) => fs.readFileSync(p, "utf8");
const PAGE = read("app/(app)/applications/[application]/page.jsx");
const BUTTON = read("components/applications/root-lock-button.jsx");

test("the documented shape parses, and an unknown status is not read as unlocked", () => {
  const ok = rootLockResponseSchema.parse({ root_lock: { status: "unlocked", path: "/home/brown/brownsite" } });
  assert.equal(ok.root_lock.status, "unlocked");
  assert.equal(rootLockResponseSchema.parse({ root_lock: { status: "weird" } }).root_lock.status, "unknown");
});

test("only a definite answer gets a row; unknown and a failed read stay silent", () => {
  assert.match(PAGE, /\(folderStatus === "locked" \|\| folderStatus === "unlocked"\) && \{/);
  assert.match(PAGE, /folderStatus === "unlocked" && \{\s*key: "folder",/);
  assert.match(PAGE, /href: "#security"/);
});

test("Lock asks first, keeps a refusal in the dialog, and says it worked", () => {
  assert.match(BUTTON, /<ConfirmDialog[\s\S]*error=\{error\}/);
  assert.match(BUTTON, /setError\(apiMessage\(e, t\("failed"\)\)\)/);
  // Before the re-read, which unmounts this button once the row reads Locked.
  assert.match(BUTTON, /toast\.success\(t\("done"\)\);\s*refreshThen/);
  assert.match(BUTTON, /reason=\{canManage \? null : tApp\("noPermission"\)\}/);
});

test("server sync: a whole-type placeholder row is not a name and cannot be ignored", () => {
  const src = read("components/sync/sync-results.jsx");
  assert.match(src, /const wholeType = item\.action === "skipped" && item\.resource_key === item\.resource_type/);
  assert.match(src, /canManage && !wholeType \?/);
  // No details to open, so its reason is shown whole rather than clamped.
  assert.match(src, /\{wholeType \? null : \(/);
  assert.match(src, /wholeType \? "max-w-xl whitespace-normal" : "line-clamp-1"/);
});

test("every new string exists in all eight locales", () => {
  for (const l of ["en", "es", "hi", "de", "fr", "pt", "ja", "ru"]) {
    const m = JSON.parse(read(`messages/${l}.json`));
    for (const k of ["lock", "title", "body", "cancel", "confirm", "locking", "done", "failed"]) assert.ok(m.applications.rootLock[k], `${l} rootLock.${k}`);
    assert.match(m.applications.rootLock.body, /\{path\}/, l);
    assert.ok(m.applications.protection.folder && m.applications.attention.folderUnlocked && m.sync.results.wholeType, l);
  }
});
