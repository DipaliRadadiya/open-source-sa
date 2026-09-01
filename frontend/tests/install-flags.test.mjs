import test from "node:test";
import assert from "node:assert/strict";
import { installingEngineName } from "../lib/databases/install-lifecycle.js";

/**
 * The polling hook compares "which engine is slow / lost its poll" against
 * "which engine is installing". Both are null when nothing is installing, and
 * `null === null` is true — which put a permanent "we temporarily lost progress
 * updates" warning on a server that was not installing anything.
 *
 * The hook itself needs React; this pins the comparison it now makes.
 */
const flags = (engines, slowEngine, pollIssueEngine) => {
  const installingEngine = installingEngineName(engines);
  const installing = Boolean(installingEngine);
  return {
    slow: installing && slowEngine === installingEngine,
    pollIssue: installing && pollIssueEngine === installingEngine,
  };
};

const IDLE = [
  { engine: "mariadb", install_status: null },
  { engine: "mongodb", install_status: null },
];
const BUSY = [
  { engine: "mariadb", install_status: null },
  { engine: "mysql", install_status: "installing" },
];

test("nothing installing means neither warning can show", () => {
  assert.deepEqual(flags(IDLE, null, null), { slow: false, pollIssue: false });
  assert.deepEqual(flags([], null, null), { slow: false, pollIssue: false });
});

test("the warnings still show for the engine that is installing", () => {
  assert.deepEqual(flags(BUSY, "mysql", "mysql"), { slow: true, pollIssue: true });
});

test("a warning left over from another engine does not show", () => {
  assert.deepEqual(flags(BUSY, "mariadb", "mariadb"), { slow: false, pollIssue: false });
});
