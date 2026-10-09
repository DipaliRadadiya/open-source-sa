import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";

// 9 Oct: the frontend now uses the backend's fixes instead of guessing around them.
const read = (p) => fs.readFileSync(new URL(`../${p}`, import.meta.url), "utf8");
const LOCALES = ["en", "es", "hi", "de", "fr", "pt", "ja", "ru"];
const msg = Object.fromEntries(LOCALES.map((l) => [l, JSON.parse(read(`messages/${l}.json`))]));
const at = (o, p) => p.split(".").reduce((n, k) => n?.[k], o);

test("B7: deleting a system user can end its sessions first", () => {
  assert.match(read("lib/api/system-users.js"), /params: endSessions \? \{ end_sessions: 1 \} : undefined/);
  assert.match(read("components/system-users/delete-system-user-dialog.jsx"), /deleteSystemUser\(user\.id, \{ endSessions \}\)/);
  // Named exactly as the API's refusal names it.
  assert.equal(at(msg.en, "systemUsers.delete.endSessions"), "End sessions");
});

test("B9: a staging push can back the live application up first, and its database copies can be put back", async () => {
  const { stagingSafetyCopiesSchema } = await import("../lib/schemas/application-staging.js");
  assert.equal(stagingSafetyCopiesSchema.parse({ safety_copies: [{ name: "pre-push-x.sql", size_bytes: 1, created_at: "2026-10-09T06:33:34+00:00" }], kept: 3 }).safety_copies.length, 1);
  assert.match(read("lib/api/applications.js"), /\{ mode, \.\.\.\(backup \? \{ backup: true \} : \{\}\) \}/);
  assert.match(read("components/applications/staging/push-staging-dialog.jsx"), /useState\(canBackUp\)/);
});

test("B10: application logs can be downloaded", () => {
  assert.match(read("components/applications/logs/application-logs-panel.jsx"), /downloadUrl=\{source \? applicationLogDownloadUrl\(appId, source\.key\) : undefined\}/);
});

test("B11: the app's own bans are listed, banned and unbanned under app_fail2ban", async () => {
  const { applicationBansResponseSchema } = await import("../lib/schemas/application-fail2ban.js");
  assert.deepEqual(applicationBansResponseSchema.parse({ jail: null, banned: [] }), { jail: null, banned: [] });
  const api = read("lib/api/applications.js");
  assert.match(api, /api\.post\(`\/applications\/\$\{id\}\/fail2ban\/bans`, \{ ip \}\)/);
  assert.match(api, /api\.delete\(`\/applications\/\$\{id\}\/fail2ban\/bans\/\$\{encodeURIComponent\(ip\)\}`\)/);
});

test("B12: the environment is checked before it is saved", () => {
  const editor = read("components/applications/environment/environment-editor.jsx");
  assert.match(editor, /await checkEnvironment\(appId, sent\)\.catch\(\(\) => null\)/);
  assert.match(editor, /onConfirm=\{\(\) => onSave\(\{ confirmed: true, checked: true \}\)\}/);
});

test("B13: Server Sync shows the handover only when the server says it is needed", async () => {
  const { syncHandoverSchema } = await import("../lib/schemas/sync.js");
  assert.equal(syncHandoverSchema.parse({ needed: false, agent: { unit: null, running: false }, users: [] }).needed, false);
  assert.match(read("app/(app)/sync/page.jsx"), /handover\.data\?\.needed \? <HandoverCard/);
});
