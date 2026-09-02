import test from "node:test";
import assert from "node:assert/strict";

import { getMessageFallback } from "../i18n/message-fallback.js";

/**
 * 151 places in this panel build a translation key out of a value the API
 * supplied. next-intl's own answer to a key it cannot resolve is to print the
 * key, so the day the backend sends a status we have no wording for, the screen
 * shows `applications.details.steps.set_ownership` mid-sentence — which is how
 * a deploy failure came to read `failed at "verify"`.
 */
test("a snake_case identifier from the API becomes a sentence fragment", () => {
  assert.equal(getMessageFallback({ key: "a.b.set_ownership" }), "Set ownership");
  assert.equal(getMessageFallback({ key: "x.restart_workers" }), "Restart workers");
});

test("the namespace is dropped — only the last segment says anything", () => {
  assert.equal(
    getMessageFallback({ key: "applications.details.steps.seed_env" }),
    "Seed env",
  );
});

test("a camelCase key of our own is split too", () => {
  // These only go missing when we typo one, and `ThisKeyDoesNotExist` reads
  // worse than the identifier it replaced.
  assert.equal(getMessageFallback({ key: "x.thisKeyDoesNotExist" }), "This key does not exist");
});

test("only the first letter is capitalised", () => {
  // Title case turns "seed env" into "Seed Env", which reads like a product.
  assert.equal(getMessageFallback({ key: "x.cache_warm_up" }), "Cache warm up");
});

test("a single word still works", () => {
  assert.equal(getMessageFallback({ key: "x.verify" }), "Verify");
});

test("a key with nothing to say falls back to itself rather than empty", () => {
  // An empty string would render as a gap the reader cannot even report.
  assert.equal(getMessageFallback({ key: "e." }), "e.");
  assert.equal(getMessageFallback({ key: "" }), "");
});
