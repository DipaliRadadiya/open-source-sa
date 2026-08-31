import { test } from "node:test";
import assert from "node:assert/strict";
import { resolveFollow, AUTO_FOLLOW_MAX_BYTES } from "../lib/logs/follow-preference.js";

const small = { readable: true, size: 4096 };
const huge = { readable: true, size: AUTO_FOLLOW_MAX_BYTES + 1 };

test("turning it off stays off across a refresh", () => {
  // The bug: nothing was remembered, so a refresh re-applied the default.
  assert.equal(resolveFollow("off", small), false);
});

test("no stored choice keeps the size-based default", () => {
  assert.equal(resolveFollow(null, small), true);
  assert.equal(resolveFollow(null, huge), false);
});

test("an explicit yes is still refused on a file past the limit", () => {
  // Saying yes to a 4 KB log is not consent to tail a 10 MB one.
  assert.equal(resolveFollow("on", huge), false);
  assert.equal(resolveFollow("on", small), true);
});

test("a log that cannot be read is never followed", () => {
  assert.equal(resolveFollow("on", { readable: false, size: 10 }), false);
  assert.equal(resolveFollow(null, null), false);
});
