import { test } from "node:test";
import assert from "node:assert/strict";
import { centralState, updateAttention, roleCounts } from "../lib/admin/dashboard-facts.js";

test("Central reads connected only once Central has used the key", () => {
  assert.equal(centralState(null), null, "a failed read gives no verdict");
  assert.equal(centralState({ enabled: false }), "off");
  // The old tile said "Connected" here: a key exists, Central never used it.
  assert.equal(centralState({ enabled: true, connected: false }), "waiting");
  assert.equal(centralState({ enabled: true, connected: true }), "on");
});

test("a failed update and an update that cannot start each get an attention row", () => {
  const blocked = updateAttention({
    update_available: true,
    available: { version: "7.1.0" },
    preflight: {
      ready: false,
      checks: [
        { key: "clean_working_tree", passed: false },
        { key: "free_memory", passed: false, advisory: true },
        { key: "free_disk", passed: true },
      ],
    },
  });
  assert.deepEqual(blocked.blocked, { version: "7.1.0", keys: ["clean_working_tree"] }, "advisory checks never block");
  assert.equal(blocked.failed, null);

  const failed = updateAttention({ latest_run: { status: "failed", reason_title: "Migrations failed" }, update_available: false });
  assert.deepEqual(failed.failed, { reason: "Migrations failed" });
  assert.equal(failed.blocked, null);

  // Up to date and ready, or ready to install: nothing to say.
  assert.deepEqual(updateAttention({ update_available: false, preflight: { ready: false, checks: [] } }), { failed: null, blocked: null });
  assert.deepEqual(updateAttention({ update_available: true, preflight: { ready: true, checks: [] } }), { failed: null, blocked: null });
  assert.deepEqual(updateAttention(null), { failed: null, blocked: null });
});

test("roles in use are counted from the users list, most-held first", () => {
  const admin = { id: 1, name: "Administrator" };
  const view = { id: 2, name: "View only" };
  const counts = roleCounts([{ roles: [admin] }, { roles: [view, admin] }, { roles: [] }, {}]);
  assert.deepEqual(counts.map((c) => [c.role.name, c.count]), [["Administrator", 2], ["View only", 1]]);
  assert.deepEqual(roleCounts(), []);
});
