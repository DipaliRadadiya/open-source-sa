import test from "node:test";
import assert from "node:assert/strict";
import { isUnknownDetail, megabytes, sizeFromValues } from "../lib/admin/preflight-detail.js";

// OLD-19 (9 Oct): the numbers come from `values`, not from parsing the English detail.
test("the disk and memory figures are read from values", () => {
  assert.deepEqual(sizeFromValues({ free_mb: 261887, required_mb: 2048 }), { haveMb: 261887, kind: "free", swapMb: null, needMb: 2048 });
  assert.deepEqual(sizeFromValues({ available_mb: 1097, swap_mb: null, required_mb: 768 }), { haveMb: 1097, kind: "available", swapMb: null, needMb: 768 });
});

test("swap is counted toward what the build can use", () => {
  // A 700MB box with 2.4GB of swap can finish the build; leading with 700MB would say the opposite.
  assert.deepEqual(sizeFromValues({ available_mb: 700, swap_mb: 2400, required_mb: 2560 }), { haveMb: 3100, kind: "available", swapMb: 2400, needMb: 2560 });
  assert.equal(sizeFromValues({ available_mb: 700, swap_mb: 0, required_mb: 2560 }).haveMb, 700);
});

test("no values, no figure", () => {
  for (const values of [null, undefined, {}, { free_mb: 5 }, { required_mb: 5 }]) assert.equal(sizeFromValues(values), null);
});

test("unknown is the one detail every check can report", () => {
  assert.equal(isUnknownDetail("unknown"), true);
  assert.equal(isUnknownDetail(" unknown "), true);
  assert.equal(isUnknownDetail("Unknown"), false);
  assert.equal(isUnknownDetail(null), false);
  assert.equal(isUnknownDetail("2048MB free, 1MB required"), false);
});

// The number that started this: 261887MB is a quarter of a terabyte.
test("megabytes are shown in the unit a person would say", () => {
  assert.deepEqual(megabytes(261887), { value: 261887 / 1024, unit: "GB", maximumFractionDigits: 0 });
  assert.deepEqual(megabytes(768), { value: 768, unit: "MB", maximumFractionDigits: 0 });
  assert.deepEqual(megabytes(2048), { value: 2, unit: "GB", maximumFractionDigits: 1 });
  // Exactly at the boundary: 1024MB is 1 GB, not 1024 MB.
  assert.equal(megabytes(1024).unit, "GB");
  assert.equal(megabytes(1023).unit, "MB");
});

// A budget of 1.5 GB rounded up to "2 GB" would promise headroom that is not
// there, so under 10 GB keeps a decimal.
test("small sizes keep a decimal so the figure stays honest", () => {
  assert.equal(megabytes(1536).maximumFractionDigits, 1);
  assert.equal(megabytes(10 * 1024).maximumFractionDigits, 0);
});

test("a size that is not a size yields nothing to format", () => {
  for (const value of [NaN, Infinity, -1, null, undefined]) {
    assert.equal(megabytes(value), null);
  }
});
