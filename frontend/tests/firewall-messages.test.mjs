import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";

/**
 * The add-rule dialog translates a fixed list of keys and passes anything else
 * through as a finished sentence — that is how a server message survives. The
 * cost is that a schema key the list does not know renders as itself: the Port
 * field said "requiredField" to the user for exactly that reason.
 *
 * Read as text rather than imported: the list lives inside a client component
 * that pulls in React and next-intl.
 */
const schema = fs.readFileSync("lib/schemas/firewall.js", "utf8");
const dialog = fs.readFileSync("components/firewall/add-rule-dialog.jsx", "utf8");

test("every firewall validation key the schema emits is one the dialog translates", () => {
  const emitted = new Set(
    [...schema.matchAll(/message: "([a-zA-Z]+)"/g)].map((m) => m[1]),
  );
  const known = new Set(
    [...dialog.matchAll(/^\s*"([a-zA-Z]+)",$/gm)].map((m) => m[1]),
  );
  assert.ok(emitted.size >= 5, "expected the schema to carry validation keys");
  for (const key of emitted) {
    assert.ok(known.has(key), `"${key}" would render to the user as itself`);
  }
});
