import test from "node:test";
import assert from "node:assert/strict";

import { clampPercent } from "../lib/disk-cleaner/clamp-percent.js";

/**
 * The threshold box took any three digits while the API accepts 1–100, so
 * `150` was typeable, savable, and answered with a 422 that named no field.
 */
test("a normal value passes through", () => {
  assert.equal(clampPercent("80"), "80");
  assert.equal(clampPercent("1"), "1");
  assert.equal(clampPercent("100"), "100");
});

test("above the maximum clamps rather than being refused later", () => {
  assert.equal(clampPercent("150"), "100");
  assert.equal(clampPercent("999"), "100");
});

test("nothing but digits survives", () => {
  assert.equal(clampPercent("8o"), "8");
  assert.equal(clampPercent("-5"), "5");
  assert.equal(clampPercent("4 2"), "42");
});

test("zero empties the field, because that is what it means", () => {
  // "Run when usage is above 0%" is the empty box's own meaning; keeping both
  // would make one of the two ways of saying "always" a 422.
  assert.equal(clampPercent("0"), "");
  assert.equal(clampPercent("00"), "");
});

test("leading zeros do not survive", () => {
  assert.equal(clampPercent("080"), "80");
});

test("an empty box stays empty", () => {
  assert.equal(clampPercent(""), "");
  assert.equal(clampPercent(null), "");
  assert.equal(clampPercent(undefined), "");
});

test("a fourth digit cannot be typed", () => {
  // Truncated before clamping, so 1000 does not silently become 100.
  assert.equal(clampPercent("1000"), "100");
});
