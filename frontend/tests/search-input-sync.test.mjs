import { test } from "node:test";
import assert from "node:assert/strict";

/**
 * The rule SearchInput follows when the URL changes underneath it.
 *
 * The box seeded itself from the URL once and then owned its value, so
 * "Clear filters" emptied the table and left the term in the input — the list
 * then said "no matches" for a search nothing was applying. Extracted here so
 * the decision is pinned; the component wires it during render.
 */
function nextValue({ urlValue, seenUrlValue, value }) {
  if (seenUrlValue === urlValue) return value; // nothing external changed
  return urlValue !== value.trim() ? urlValue : value;
}

test("clearing the URL empties the box", () => {
  assert.equal(nextValue({ urlValue: "", seenUrlValue: "zzz", value: "zzz" }), "");
});

test("a link that sets a different term adopts it", () => {
  assert.equal(nextValue({ urlValue: "nginx", seenUrlValue: "", value: "" }), "nginx");
});

test("the box's own debounced push does not clobber what was typed", () => {
  // URL catches up to the typed value; they already agree, so nothing moves.
  assert.equal(nextValue({ urlValue: "abc", seenUrlValue: "", value: "abc" }), "abc");
  // Trailing space is trimmed on the way out, so it must not count as a change.
  assert.equal(nextValue({ urlValue: "abc", seenUrlValue: "", value: "abc " }), "abc ");
});

test("typing between pushes is left alone", () => {
  assert.equal(nextValue({ urlValue: "ab", seenUrlValue: "ab", value: "abcd" }), "abcd");
});
