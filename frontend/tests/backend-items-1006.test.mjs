import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import { installedNodeVersions } from "../lib/node/installed-node-versions.js";

// Frontend items from the backend team, 6 Oct (junior bugs #4, #5, #9, #12).
const root = path.join(import.meta.dirname, "..");
const read = (p) => fs.readFileSync(path.join(root, p), "utf8");
const LOCALES = ["en", "es", "hi", "de", "fr", "pt", "ja", "ru"];
const messages = Object.fromEntries(LOCALES.map((l) => [l, JSON.parse(read(`messages/${l}.json`))]));

test("#4: Stop is off with the server's reason when a process is not stoppable", () => {
  const src = read("components/dashboard/kill-process-button.jsx");
  assert.match(src, /process\.stoppable === false\s*\? process\.reason \|\| t\("kill\.refused"\)/);
  assert.match(src, /disabled=\{Boolean\(refusal\)\}/);
  assert.match(read("lib/schemas/server.js"), /stoppable: z\.boolean\(\)\.default\(true\)/);
});

test("#5: Paused is a status filter option with the badge's label", () => {
  assert.match(read("components/applications/application-status-badge.jsx"), /\["pending", "provisioning", "active", "paused", "failed"\]/);
  for (const l of LOCALES) assert.equal(messages[l].applications.status.paused, messages[l].applications.paused, l);
});

test("#9: password protection warns when the site has no certificate", () => {
  const src = read("components/applications/security/security-section.jsx");
  assert.match(src, /application\.basic_auth_unencrypted \|\|/);
  assert.match(src, /href=\{`\/applications\/\$\{appId\}\/domains\?tab=ssl`\}/);
  assert.match(read("lib/schemas/application.js"), /basic_auth_unencrypted: z\.boolean\(\)\.default\(false\)/);
});

test("#12: Node version change uses its own endpoint and follows node_version_change", () => {
  assert.match(read("lib/api/applications.js"), /api\.put\(`\/applications\/\$\{id\}\/node-version`, \{ node_version: nodeVersion \}\)/);
  const card = read("components/applications/site-facts-card.jsx");
  assert.match(card, /nodeSwitching \? <AutoRefresh/);
  assert.match(card, /onEdit: canManage && !nodeSwitching/);
  assert.match(read("components/applications/node-version-dialog.jsx"), /versionsInRange\(versions, range\)\.filter\(\(item\) => item\.version !== current\)/);
  assert.match(read("lib/schemas/application.js"), /node_version_change: z/);
});

test("installed Node versions: ready ones, plus the system Node once", () => {
  const list = installedNodeVersions({
    versions: [{ version: "24.21.0", status: "ready" }, { version: "26.1.0", status: "installing" }],
    system: { version: "24.21.0" },
  });
  assert.deepEqual(list.map((v) => v.version), ["24.21.0"]);
  assert.deepEqual(installedNodeVersions({ versions: [], system: { version: "20.1.0" } }).map((v) => v.version), ["20.1.0"]);
  assert.deepEqual(installedNodeVersions(null), []);
});

test(".env: a refused managed key is not called a syntax error", () => {
  const src = read("components/applications/environment/environment-editor.jsx");
  assert.match(src, /setRawRefusal\(guardedChanges\(env\.raw, sent\)\.some\(\(key\) => text\.includes\(key\)\)\)/);
  assert.match(src, /t\(rawRefusal \? "protectedTitle" : "syntaxTitle"\)/);
});

test("new strings exist in every locale", () => {
  for (const l of LOCALES) {
    for (const k of ["title", "switching", "noneInRange", "restartNote", "failed"]) assert.ok(messages[l].applications.nodeVersion[k], `${l} nodeVersion.${k}`);
    assert.ok(messages[l].applications.security.unencrypted.includes("<link>"), l);
    assert.ok(messages[l].applications.environment.protectedTitle, l);
  }
});
