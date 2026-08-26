import test from "node:test";
import assert from "node:assert/strict";
import {
  acknowledgePanelUpdate,
  isPanelUpdateAcknowledged,
} from "../lib/admin/panel-update-acknowledgement.js";

function memoryStorage() {
  const values = new Map();

  return {
    getItem: (key) => values.get(key) ?? null,
    setItem: (key, value) => values.set(key, value),
  };
}

test("only the run reloaded for is acknowledged", () => {
  const storage = memoryStorage();

  assert.equal(isPanelUpdateAcknowledged(storage, 41), false);
  acknowledgePanelUpdate(storage, 41);
  assert.equal(isPanelUpdateAcknowledged(storage, 41), true);
  assert.equal(isPanelUpdateAcknowledged(storage, 42), false);
});

test("disabled browser storage does not break reload handling", () => {
  const storage = {
    getItem: () => {
      throw new Error("disabled");
    },
    setItem: () => {
      throw new Error("disabled");
    },
  };

  assert.doesNotThrow(() => acknowledgePanelUpdate(storage, 41));
  assert.equal(isPanelUpdateAcknowledged(storage, 41), false);
});
