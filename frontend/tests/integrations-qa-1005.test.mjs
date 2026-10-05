import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";

// Integrations QA on the fresh server, 2026-10-05 (reports/fresh/E7-integrations.md).
const root = path.join(import.meta.dirname, "..");
const read = (p) => fs.readFileSync(path.join(root, p), "utf8");
const LOCALES = ["en", "es", "hi", "de", "fr", "pt", "ja", "ru"];
const messages = Object.fromEntries(LOCALES.map((l) => [l, JSON.parse(read(`messages/${l}.json`))]));
const at = (m, key) => key.split(".").reduce((o, k) => o?.[k], m);

test("registries: a server without Docker explains itself before the permission check", () => {
  // The backend leaves `registry` out of everyone's permissions there, so the
  // permission check first told an admin "no access".
  const src = read("app/(app)/integrations/registries/page.jsx");
  const conflict = src.indexOf("list.status === 409");
  const permission = src.indexOf('can(permissions, "registry", "view")');
  assert.ok(conflict > 0 && permission > 0);
  assert.ok(conflict < permission);
});

test("a refused git token is described by the panel, other failures are not called a refusal", () => {
  for (const f of ["components/integrations/git/connect-form.jsx", "components/integrations/git/replace-token-dialog.jsx"]) {
    const src = read(f);
    assert.match(src, /error\.response\?\.status === 422 \? rejected : apiMessage\(error, t\("failed"\)\)/, f);
  }
});

test("a storage test that never ran is not reported as a failed connection", () => {
  const probe = read("lib/storage/probe.js");
  assert.match(probe, /return \{ ok: false, notRun: true, message: apiMessage\(error, notRunMessage\) \}/);
  const row = read("components/integrations/storage/destination-row.jsx");
  // No "Replace credentials" offer: the keys are not what failed.
  assert.match(row, /!result\.ok && !result\.notRun && canManage/);
  assert.match(read("components/integrations/storage/destinations-card.jsx"), /t\("row\.testNotRun"\)/);
  assert.match(read("components/integrations/storage/connect-dialog.jsx"), /probeDestination\(created\.id, t\("testFailed"\), t\("testNotRun"\)\)/);
  assert.match(read("components/integrations/storage/replace-credentials-dialog.jsx"), /t\("replacedNotTested"\)/);
});

test("storage and git saves name what failed", () => {
  for (const f of [
    "components/integrations/storage/connect-dialog.jsx",
    "components/integrations/storage/edit-dialog.jsx",
    "components/integrations/storage/replace-credentials-dialog.jsx",
    "components/integrations/git/edit-dialog.jsx",
  ]) assert.match(read(f), /handleValidationError\(error, form, \{ fallback: t\("failed"\) \}\)/, f);
});

test("new integration strings exist in every locale", () => {
  const keys = [
    "validation.hostForbidden",
    "storage.connect.failed", "storage.connect.testNotRun", "storage.edit.failed",
    "storage.replace.failed", "storage.replace.replacedNotTested", "storage.row.testNotRun",
    "git.connect.failed", "git.edit.failed", "git.replace.failed",
  ];
  for (const l of LOCALES) for (const k of keys) {
    const v = at(messages[l], k);
    assert.equal(typeof v, "string", `${l} ${k}`);
    if (l !== "en") assert.notEqual(v, at(messages.en, k), `${l} ${k} is untranslated`);
  }
  // FTP and SFTP use a password, Drive a client secret: the note is not about "keys".
  assert.doesNotMatch(messages.en.storage.connect.credentialsNote, /^Keys/);
});

test("closing either connect step returns focus to Connect account", () => {
  const dialog = read("components/integrations/git/connect-dialog.jsx");
  assert.match(dialog, /document\.querySelector\("\[data-git-connect\]"\)/);
  assert.equal(dialog.match(/onCloseAutoFocus=\{focusConnectButton\}/g)?.length, 2);
  assert.match(read("components/integrations/git/connect-form.jsx"), /onCloseAutoFocus=\{onCloseAutoFocus\}/);
  assert.match(read("components/integrations/git/accounts-card.jsx"), /data-git-connect/);
  assert.match(read("components/ui/form-modal.jsx"), /onCloseAutoFocus=\{onCloseAutoFocus\}/);
});
