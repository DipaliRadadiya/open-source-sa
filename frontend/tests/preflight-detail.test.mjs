import test from "node:test";
import assert from "node:assert/strict";
import { isUnknownDetail, megabytes, parseSizeDetail } from "../lib/admin/preflight-detail.js";

// The two sentences UpdatePreflight actually emits. If the backend ever changes
// its wording these stop matching and the raw string is shown instead — which
// is the old behaviour, not a break.
test("the two size sentences the backend builds are understood", () => {
  assert.deepEqual(parseSizeDetail("261887MB free, 2048MB required"), {
    haveMb: 261887,
    kind: "free",
    needMb: 2048,
  });
  assert.deepEqual(parseSizeDetail("1097MB available, 768MB required"), {
    haveMb: 1097,
    kind: "available",
    needMb: 768,
  });
});

test("anything else falls through rather than being guessed at", () => {
  for (const detail of ["unknown", "", null, undefined, "2GB free, 1GB required", "free, required"]) {
    assert.equal(parseSizeDetail(detail), null, `${detail} should not parse`);
  }
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
