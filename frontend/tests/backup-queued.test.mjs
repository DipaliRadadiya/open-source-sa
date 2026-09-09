import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { isBackupQueued, newestBackupId, queuedApplications } from "../lib/backups/queued.js";

/**
 * "Back up now" answers 202 with the target and no backup row, so the page had
 * nothing to show and nothing to poll until someone reloaded by hand. These
 * cover the state that closes that gap — in particular the two ways it has to
 * end, because a wait that cannot end is worse than no wait at all.
 */

const backup = (id, status) => ({ id, status });

test("nothing is queued until a run is started", () => {
  assert.equal(isBackupQueued([backup(4, "failed")], null), false);
  assert.equal(isBackupQueued([], null), false);
});

test("queued while the list has not grown past the click", () => {
  const before = [backup(4, "failed")];
  const queuedAfter = newestBackupId(before);
  assert.equal(queuedAfter, 4);
  // The refresh right after the click returns the same list.
  assert.equal(isBackupQueued(before, queuedAfter), true);
});

test("a site with no history at all still shows the wait", () => {
  assert.equal(newestBackupId([]), 0);
  assert.equal(isBackupQueued([], 0), true);
  assert.equal(isBackupQueued([backup(1, "pending")], 0), false);
});

test("the wait ends when the worker's row appears", () => {
  const queuedAfter = 4;
  assert.equal(isBackupQueued([backup(5, "pending"), backup(4, "failed")], queuedAfter), false);
});

test("a run that finished between two polls still ends the wait", () => {
  // The reason this is keyed on the id and not on an in-flight status: a small
  // site can go pending → verified inside one five-second poll, and waiting for
  // "running" to be seen would then never resolve.
  assert.equal(isBackupQueued([backup(5, "verified"), backup(4, "failed")], 4), false);
  assert.equal(isBackupQueued([backup(5, "failed"), backup(4, "failed")], 4), false);
});

test("newestBackupId ignores rubbish rather than returning NaN", () => {
  // One bad row must not make every later comparison false and strand the page
  // in a permanent wait.
  assert.equal(newestBackupId([backup(3, "failed"), { id: null }, { id: "x" }]), 3);
  assert.equal(newestBackupId([{ id: "7" }, backup(2, "failed")]), 7);
});

/**
 * The All-backups list spans every site, so the same wait has to be per
 * application. One flag would let site B's run end site A's wait.
 */
const row = (id, app) => ({ id, application_id: app });

test("one site's new run does not end another site's wait", () => {
  const backups = [row(9, 2), row(4, 5)];
  // Both started at their own site's newest id.
  const started = { 2: 9, 5: 4 };
  assert.deepEqual(queuedApplications(backups, started).sort(), ["2", "5"]);

  // Site 2's worker starts. Site 5 must still be waiting.
  assert.deepEqual(queuedApplications([row(10, 2), row(9, 2), row(4, 5)], started), ["5"]);
});

test("a site with no rows in the current page still waits", () => {
  assert.deepEqual(queuedApplications([row(4, 5)], { 7: 0 }), ["7"]);
});

test("nothing started means nothing queued", () => {
  assert.deepEqual(queuedApplications([row(9, 2)], {}), []);
});

test("rows without an application are ignored, not counted as newest", () => {
  assert.deepEqual(queuedApplications([{ id: 99 }, row(4, 5)], { 5: 4 }), ["5"]);
});

test("deleting the newest backup must not re-arm the queued state", () => {
  /*
   * Reported: after deleting one of several backups, "Back up now" starts
   * spinning on its own and a finished backup is drawn as queued.
   *
   * `queuedAfter` is the newest id at the moment of the click. Press it with
   * 5 backups, id 6 appears, the wait ends — then delete id 6 and the newest
   * falls back to 5, which is <= the mark, so the wait comes back from
   * nothing.
   *
   * The helper is right on its own terms: it answers "has anything newer than
   * the mark arrived", and after the delete that answer really is no. What was
   * wrong is that the mark outlived the run it was watching, so the panel now
   * drops it as soon as the wait is satisfied.
   */
  const mark = newestBackupId([backup(5, "verified"), backup(4, "verified")]);
  assert.equal(mark, 5);

  const arrived = [backup(6, "running"), backup(5, "verified"), backup(4, "verified")];
  assert.equal(isBackupQueued(arrived, mark), false, "the wait should end when a newer id appears");

  const deleted = [backup(5, "verified"), backup(4, "verified")];
  assert.equal(isBackupQueued(deleted, mark), true, "the state the panel must no longer be able to reach");
  assert.equal(isBackupQueued(deleted, null), false, "a dropped mark cannot re-arm");
});

test("the panel drops the mark as soon as the wait is over", () => {
  const panel = readFileSync(
    new URL("../components/applications/backups/backups-panel.jsx", import.meta.url),
    "utf8",
  );
  assert.match(
    panel,
    /if \(queuedAfter !== null && !queued\) setQueuedAfter\(null\);/,
    "the queued mark is kept after the run appears again",
  );
});
