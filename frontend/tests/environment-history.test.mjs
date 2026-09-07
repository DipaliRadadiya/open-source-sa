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

test("the editor takes new file contents that arrive from the server", () => {
  // Restoring from a history row writes the file and refreshes the page. The
  // editor holds its text in useState, which reads its initial value once and
  // ignores the prop forever after — so the refresh worked and the textarea
  // still showed the pre-restore file until someone reloaded by hand.
  const editor = fs.readFileSync(
    path.join(root, "components/applications/environment/environment-editor.jsx"),
    "utf8",
  );

  assert.match(
    editor,
    /serverRaw/,
    "the editor must track the last contents it saw from the server",
  );
  assert.match(
    editor,
    /!==\s*serverRaw/,
    "…and compare the incoming prop against it",
  );

  // Adjusted during render. An effect here is the cascading-render pattern the
  // lint rules refuse, and it would also paint the stale text for one frame.
  assert.ok(
    !/useEffect\([^)]*setContents/s.test(editor),
    "the sync must not run from an effect",
  );
});

test("a write this editor made is not re-applied by the refresh it triggers", () => {
  // Otherwise anything typed between the "Saved" toast and the refresh landing
  // is wiped by the sync.
  const editor = fs.readFileSync(
    path.join(root, "components/applications/environment/environment-editor.jsx"),
    "utf8",
  );

  const marks = editor.match(/setServerRaw\(/g) ?? [];

  // Once in the render-phase sync, once after save, once after restore.
  assert.ok(
    marks.length >= 3,
    `save and restore must both mark their own write as seen, found ${marks.length}`,
  );
});

test("values are read from the backup files, not from the activity log", () => {
  // The whole reason the diff is its own endpoint. If a future change starts
  // reading values off the history entry, they will have been written into
  // activity_logs — never pruned, in every database backup, and rendered by an
  // admin-wide screen gated on access-admin rather than app_environment.
  const diff = fs.readFileSync(
    path.join(root, "components/applications/environment/environment-diff.jsx"),
    "utf8",
  );

  assert.match(
    diff,
    /getEnvironmentDiff\(/,
    "the diff must come from its own endpoint",
  );
  assert.ok(
    !/entry\.(before|after|changes|values)/.test(diff),
    "the diff must not read values off the history entry itself",
  );
});

test("a change whose backup is gone is not rendered as an empty diff", () => {
  // "available: false" and "changes: []" mean different things: one is "the
  // previous version was deleted", the other is "this change touched nothing".
  const diff = fs.readFileSync(
    path.join(root, "components/applications/environment/environment-diff.jsx"),
    "utf8",
  );

  assert.match(diff, /available/, "the unavailable state must be handled");
  assert.match(
    diff,
    /diffUnavailable/,
    "…and it must say so rather than showing an empty table",
  );
});

test("the values control is offered only where the backup can still be read", () => {
  const card = fs.readFileSync(
    path.join(root, "components/applications/environment/environment-history-card.jsx"),
    "utf8",
  );

  // Manage only: a viewer cannot restore a backup, so for them these values
  // are not otherwise reachable and this would be a real widening.
  assert.match(
    card,
    /canManage && blocked !== "pruned"/,
    "the diff must be manage-only and hidden for a pruned backup",
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
