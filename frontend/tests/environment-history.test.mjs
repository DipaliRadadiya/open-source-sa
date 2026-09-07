import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

import {
  actorOf,
  changedKeys,
  unrestorableReason,
} from "../lib/applications/environment-history.js";

const root = path.join(import.meta.dirname, "..");

test("a first save is distinguished from a version that was pruned", () => {
  // Two rows that look alike and mean different things. Collapsing them tells
  // someone their first save had a version they could have restored.
  assert.equal(unrestorableReason({ backup: null, restorable: false }), "first");
  assert.equal(
    unrestorableReason({ backup: ".env.bak-20260907-120000", restorable: false }),
    "pruned",
  );
});

test("a restorable row gives no reason to block it", () => {
  assert.equal(
    unrestorableReason({ backup: ".env.bak-20260907-120000", restorable: true }),
    null,
  );
});

test("changed keys come back as a list", () => {
  assert.deepEqual(changedKeys({ keys: "APP_ENV, DB_PASSWORD" }), [
    "APP_ENV",
    "DB_PASSWORD",
  ]);
  assert.deepEqual(changedKeys({ keys: "APP_ENV" }), ["APP_ENV"]);
});

test("a save that changed nothing is an empty list, not a phantom key", () => {
  // The backend writes "—" when a save touched no key at all.
  assert.deepEqual(changedKeys({ keys: "—" }), []);
  assert.deepEqual(changedKeys({ keys: "" }), []);
  assert.deepEqual(changedKeys({}), []);
});

test("the system is credited as the system, never as a person", () => {
  assert.deepEqual(actorOf({ is_system: true, user: null }), { kind: "system" });
});

test("a deleted account is its own answer, not the system", () => {
  // The panel writes system actions with no user on purpose rather than
  // blaming an admin who was not there — so a null user without that flag
  // means the account is gone, which is a different sentence.
  assert.deepEqual(actorOf({ is_system: false, user: null }), { kind: "unknown" });
});

test("a named user is credited", () => {
  assert.deepEqual(actorOf({ is_system: false, user: { username: "suresh" } }), {
    kind: "user",
    username: "suresh",
  });
});

test("the history card never renders a value from the file", () => {
  // The one assertion that matters. The API sends key names only; if a future
  // edit reaches for a value field, this is what should stop it.
  const card = fs.readFileSync(
    path.join(root, "components/applications/environment/environment-history-card.jsx"),
    "utf8",
  );

  assert.ok(
    !/entry\.(value|raw|contents)/.test(card),
    "the history card must not read a value off a history entry",
  );
});

test("every path that changes the file refreshes the page that renders its history", () => {
  // The history card is server-rendered from the page. The editor saves over
  // the API and updates its own state, so without a refresh the card keeps
  // showing the file's past as of page load — missing the very edit whose
  // "Saved" toast is still on screen.
  const editor = fs.readFileSync(
    path.join(root, "components/applications/environment/environment-editor.jsx"),
    "utf8",
  );

  assert.match(editor, /useRouter\(\)/, "the editor needs the router to refresh");

  // Once after a save, once after a restore from the backup dialog.
  const refreshes = editor.match(/router\.refresh\(\)/g) ?? [];
  assert.ok(
    refreshes.length >= 2,
    `expected a refresh after both save and restore, found ${refreshes.length}`,
  );

  const card = fs.readFileSync(
    path.join(root, "components/applications/environment/environment-history-card.jsx"),
    "utf8",
  );

  assert.match(
    card,
    /router\.refresh\(\)/,
    "restoring from a history row must refresh the page too",
  );
});

test("every history message the card uses exists in all three locales", () => {
  const card = fs.readFileSync(
    path.join(root, "components/applications/environment/environment-history-card.jsx"),
    "utf8",
  );

  const used = [...card.matchAll(/\bt\("([a-zA-Z]+)"\)/g)].map(([, key]) => key);

  assert.ok(used.length >= 10, "expected the card to use its message keys");

  for (const locale of ["en", "es", "hi"]) {
    const messages = JSON.parse(
      fs.readFileSync(path.join(root, `messages/${locale}.json`), "utf8"),
    );
    const history = messages.applications.environment.history;

    for (const key of used) {
      assert.equal(
        typeof history[key],
        "string",
        `${locale}.json is missing applications.environment.history.${key}`,
      );
    }
  }
});
