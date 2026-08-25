import assert from "node:assert/strict";
import test from "node:test";
import { shouldRecoverPanelUpdate } from "../lib/admin/recover-panel-update.js";

test("an active run is recovered even when the start reply was lost", () => {
  assert.equal(shouldRecoverPanelUpdate({ id: "12", status: "pending" }, "12"), true);
  assert.equal(shouldRecoverPanelUpdate({ id: "12", status: "running" }, "12"), true);
});

test("a newly settled run is recovered after an ambiguous start response", () => {
  assert.equal(shouldRecoverPanelUpdate({ id: "13", status: "failed" }, "12"), true);
  assert.equal(shouldRecoverPanelUpdate({ id: "13", status: "succeeded" }, "12"), true);
});

test("an old settled run does not disguise a genuine start failure", () => {
  assert.equal(shouldRecoverPanelUpdate({ id: "12", status: "failed" }, "12"), false);
  assert.equal(shouldRecoverPanelUpdate(null, "12"), false);
});
