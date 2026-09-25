import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import { hasBeenDeployed, isRedeploying, isSettled } from "../lib/applications/settled.js";
import { cloneBlockedReason } from "../lib/schemas/clone.js";

const read = (p) => fs.readFileSync(p, "utf8");
const LOCALES = ["en", "es", "hi", "de", "fr", "pt", "ja", "ru"];
const APP = "app/(app)/applications/[application]";

test("a redeploy of a live site is not a site being set up", () => {
  // Shapes from his panel: a redeploy flips status to provisioning and keeps
  // last_deployed_at; a first install has neither marker.
  const live = { status: "provisioning", last_deployed_at: "25-09-2026 06:33:00", code_on_disk: { commit: "10acbe4" } };
  const first = { status: "provisioning", last_deployed_at: null, code_on_disk: { commit: null } };
  assert.equal(isSettled(live), true);
  assert.equal(isRedeploying(live), true);
  assert.equal(isSettled(first), false);
  assert.equal(isRedeploying(first), false);
  assert.equal(isSettled({ status: "active", last_deployed_at: null }), true);
  assert.equal(isRedeploying({ status: "active", last_deployed_at: "x" }), false);
  assert.equal(hasBeenDeployed({ code_on_disk: { commit: "abc" } }), true);
});

test("every application page judges 'still being set up' the same way", () => {
  const pages = ["backups", "bot-blocker", "domains", "environment", "fail2ban", "files", "firewall", "logs", "php", "security", "staging", "workers"]
    .map((p) => `${APP}/${p}/page.jsx`)
    .concat(`${APP}/page.jsx`);
  for (const p of pages) {
    const src = read(p);
    assert.match(src, /const settled = isSettled\(application\);/, p);
    assert.doesNotMatch(src, /const settled = application\.status === "active"/, p);
  }
});

test("clone waits for a running deploy, and says that is why", () => {
  const live = { status: "provisioning", last_deployed_at: "x" };
  assert.equal(cloneBlockedReason(live, null), "deploying");
  assert.equal(cloneBlockedReason({ status: "provisioning" }, null), "provisioning");
  for (const l of LOCALES) {
    const m = JSON.parse(read(`messages/${l}.json`)).applications;
    assert.ok(m.clone.blocked.deploying.title && m.clone.blocked.deploying.body, l);
    assert.ok(m.deploying, l);
  }
});

test("the status badge and dot say Deploying during a redeploy", () => {
  const src = read("components/applications/application-status-badge.jsx");
  assert.equal((src.match(/isRedeploying\(application\)/g) ?? []).length, 2);
  assert.equal((src.match(/t\("deploying"\)/g) ?? []).length, 2);
});

test("an oversized .env is refused before sending, in words about size", () => {
  const editor = read("components/applications/environment/environment-editor.jsx");
  assert.match(editor, /const MAX_CHARS = 262144;/);
  assert.match(editor, /if \(!dirty \|\| saving \|\| tooLarge\) return;/);
  assert.match(editor, /disabled=\{!dirty \|\| saving \|\| tooLarge\}/);
  assert.match(editor, /<NotSaved title=\{t\("tooLargeTitle"\)\}>\{t\("tooLarge"\)\}<\/NotSaved>/);
});

test("an empty .env shows what to do with it", () => {
  const editor = read("components/applications/environment/environment-editor.jsx");
  assert.match(editor, /useState\(editable\(initialEnv\.raw\)\)/);
  assert.match(editor, /const dirty = contents !== editable\(env\.raw\);/);
  assert.match(editor, /placeholder=\{env\.exists \? t\("emptyFilePlaceholder"\) : t\("emptyPlaceholder"\)\}/);
});

test("the change history pages past its first twenty rows", () => {
  const card = read("components/applications/environment/environment-history-card.jsx");
  assert.match(card, /getEnvironmentHistoryPage\(appId, older\.page \+ 1\)/);
  assert.match(card, /t\("showOlder", \{ count: remaining \}\)/);
  // A write adds a row and shifts the rest: pages loaded before it are dropped.
  assert.match(card, /if \(older\.from !== entries\) \{/);
  assert.match(read("lib/schemas/environment.js"), /meta: listMetaSchema\.nullish\(\),/);
  assert.match(read(`${APP}/environment/page.jsx`), /meta=\{historyResult\.meta\}/);
});

test("the restore list scrolls inside the dialog, keeping its buttons in view", () => {
  assert.match(read("components/applications/environment/restore-backup-dialog.jsx"), /max-h-\[min\(20rem,45dvh\)\] space-y-2 overflow-y-auto/);
});

test("a site type with no .env is not found, and a viewer is told they can only view", () => {
  const page = read(`${APP}/environment/page.jsx`);
  assert.match(page, /if \(\(await getApplicationEnvironment\(id\)\)\.status === 404\) notFound\(\);/);
  assert.match(page, /subtitle=\{canManage \? t\("pageSubtitle"\) : t\("pageSubtitleReadOnly"\)\}/);
});

test("every new Environment string exists in every locale", () => {
  for (const l of LOCALES) {
    const e = JSON.parse(read(`messages/${l}.json`)).applications.environment;
    for (const k of ["pageSubtitleReadOnly", "emptyFilePlaceholder", "tooLargeTitle", "tooLarge"]) assert.ok(e[k], `${l} ${k}`);
    assert.ok(e.history.showOlder.includes("{count, plural,") && e.history.olderFailed, l);
  }
});
