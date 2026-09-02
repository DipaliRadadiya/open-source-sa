import test from "node:test";
import assert from "node:assert/strict";
import { pickFeedRows } from "../lib/activity-log/pick-feed-rows.js";

const group = (action, id) => ({ key: String(id), newest: { id, action }, count: 1 });

// The case this exists for: runs of logins separated by other logins fill every
// row, and the one event worth reading never gets one.
test("logins get an allowance so other events still get rows", () => {
  const groups = [
    group("logged_in", 9),
    group("logged_in", 8),
    group("logged_in", 7),
    group("role_assigned", 6),
    group("logged_in", 5),
    group("password_changed", 4),
  ];
  const rows = pickFeedRows(groups, { max: 6 });
  assert.deepEqual(rows.map((r) => r.newest.action), [
    "logged_in",
    "logged_in",
    "role_assigned",
    "password_changed",
  ]);
});

// Dropping rows must never reorder the ones that survive.
test("the surviving rows stay in the order they happened", () => {
  const groups = [group("logged_in", 5), group("deleted", 4), group("logged_in", 3), group("created", 2)];
  assert.deepEqual(pickFeedRows(groups, { max: 4, maxQuiet: 1 }).map((r) => r.newest.id), [5, 4, 2]);
});

test("the cap is still a cap", () => {
  const groups = Array.from({ length: 20 }, (_, i) => group("created", 100 - i));
  assert.equal(pickFeedRows(groups, { max: 6 }).length, 6);
});

// A genuinely quiet panel has nothing but logins. Enforcing the allowance there
// would print a nearly empty card about a server that is simply idle.
test("a panel with nothing but logins still shows logins", () => {
  const groups = [group("logged_in", 3), group("logged_in", 2), group("logged_in", 1)];
  assert.equal(pickFeedRows(groups, { max: 6, maxQuiet: 0 }).length, 3);
});

test("which action counts as quiet is the caller's decision", () => {
  const groups = [group("synced", 3), group("synced", 2), group("created", 1)];
  const rows = pickFeedRows(groups, { max: 6, quiet: ["synced"], maxQuiet: 1 });
  assert.deepEqual(rows.map((r) => r.newest.id), [3, 1]);
});

test("nothing in, nothing out", () => {
  assert.deepEqual(pickFeedRows([]), []);
  assert.deepEqual(pickFeedRows(), []);
});
