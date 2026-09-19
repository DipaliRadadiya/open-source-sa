import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

const read = (p) => readFileSync(new URL(`../${p}`, import.meta.url), "utf8");

/*
 * The Memory screen can remove the swap the panel needs to update itself, so
 * the server refuses below a computed floor. These pin the frontend half:
 * without them the refusal only ever arrives on submit, which is a worse way
 * to learn a rule than being shown it.
 */

/*
 * The one that would fail silently. Zod strips keys it does not declare, so an
 * undeclared `minimum_mb` means the API sends the floor, the parse drops it,
 * and the card offers an "Off" the server will refuse — with no error anywhere
 * to notice.
 */
test("the swap schema keeps the floor the API sends", () => {
  const schema = read("lib/schemas/settings.js");

  assert.match(schema, /minimum_mb:\s*z\.number\(\)/);
  assert.match(schema, /build_requirement_mb:\s*z\.number\(\)/);
});

test("presets below the floor are refused, and say why", () => {
  const form = read("components/settings/swap-form.jsx");

  assert.match(form, /const belowFloor = mb < minimumMb/);
  // Disabled without a reason is worse than silence — and check-disabled-reasons
  // fails the build over it.
  assert.match(form, /reason=\{belowFloor \? floorReason : null\}/);
});

// Readable without hovering: the rule replaces the generic hint on the row.
test("the floor is stated on the row, not only in a tooltip", () => {
  const form = read("components/settings/swap-form.jsx");

  assert.match(form, /hint=\{floorReason \?\? t\("swap\.sizeHint"\)\}/);
});

// The copy has to name both numbers, or "at least 1536 MB" is an arbitrary
// rule the reader cannot check.
test("the floor copy explains the requirement behind it", () => {
  const en = JSON.parse(read("messages/en.json"));
  const reason = en.settings.performance.swap.floorReason;

  assert.match(reason, /\{minimum\}/);
  assert.match(reason, /\{required\}/);
  assert.match(reason, /update/i);
});
