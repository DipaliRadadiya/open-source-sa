import test from "node:test";
import assert from "node:assert/strict";
import { collapseRepeats } from "../lib/activity/collapse-repeats.js";

const login = (id, userId = 2) => ({
  id,
  type: "user",
  action: "logged_in",
  user: { id: userId, username: `u${userId}` },
  is_system: false,
});

// The case this exists for: five rows of feed spent on one person signing in.
test("a run of identical events becomes one row with a count", () => {
  const groups = collapseRepeats([login(5), login(4), login(3), login(2), login(1)]);
  assert.equal(groups.length, 1);
  assert.equal(groups[0].count, 5);
  assert.equal(groups[0].newest.id, 5);
  assert.equal(groups[0].oldest.id, 1);
});

// Order is the point of the feed, so only neighbours merge.
test("a different event between two repeats keeps them apart", () => {
  const role = { id: 3, type: "user", action: "role_assigned", user: { id: 2 }, is_system: false };
  const groups = collapseRepeats([login(4), role, login(2), login(1)]);
  assert.deepEqual(groups.map((g) => [g.newest.action, g.count]), [
    ["logged_in", 1],
    ["role_assigned", 1],
    ["logged_in", 2],
  ]);
});

test("two people signing in are two facts, not one row of 2x", () => {
  const groups = collapseRepeats([login(2, 7), login(1, 2)]);
  assert.equal(groups.length, 2);
});

// Whether a person or a timer did it is exactly the distinction the feed marks,
// so it cannot be collapsed away.
test("a scheduled action never merges with the same action by a person", () => {
  const byPerson = { id: 2, type: "setting", action: "auto_rebooted", user: { id: 2 }, is_system: false };
  const byTimer = { ...byPerson, id: 1, is_system: true };
  assert.equal(collapseRepeats([byPerson, byTimer]).length, 2);
});

test("the cap counts rows, not entries", () => {
  const groups = collapseRepeats([login(9), login(8), login(7), login(6, 3), login(5, 4)], { max: 2 });
  assert.equal(groups.length, 2);
  assert.equal(groups[0].count, 3);
});

test("nothing in, nothing out", () => {
  assert.deepEqual(collapseRepeats([]), []);
  assert.deepEqual(collapseRepeats(), []);
});

// Two people signing in over an afternoon interleave, so adjacency alone turned
// one run of many into a pile of ones and the feed reported the first two.
test("a merged action collapses per person, not per run", () => {
  const groups = collapseRepeats(
    [login(6, 2), login(5, 7), login(4, 2), login(3, 2), login(2, 7), login(1, 2)],
    { mergeAcross: ["logged_in"] },
  );
  assert.deepEqual(
    groups.map((g) => [g.newest.user.id, g.count]),
    [
      [2, 4],
      [7, 2],
    ],
  );
});

// Ordered by when each person was last seen, so the card still reads newest first.
test("a merged group keeps the position of its most recent entry", () => {
  const groups = collapseRepeats([login(9, 7), login(8, 2), login(7, 7)], {
    mergeAcross: ["logged_in"],
  });
  assert.deepEqual(groups.map((g) => g.newest.id), [9, 8]);
  assert.equal(groups[0].oldest.id, 7);
});

// Only the named actions merge; everything else still reads as a sequence.
test("actions outside the list keep adjacency-only grouping", () => {
  const role = (id) => ({ id, type: "user", action: "role_assigned", user: { id: 2 }, is_system: false });
  const groups = collapseRepeats([role(4), login(3), role(2), role(1)], {
    mergeAcross: ["logged_in"],
  });
  assert.deepEqual(groups.map((g) => [g.newest.action, g.count]), [
    ["role_assigned", 1],
    ["logged_in", 1],
    ["role_assigned", 2],
  ]);
});
