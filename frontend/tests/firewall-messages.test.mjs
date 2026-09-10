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

// --- Logs: order and a typed line count (2026-09-10) ---

test("a custom line count is clamped to what the API accepts", async () => {
  const { normalizeLineCount, MIN_LINES, MAX_LINES } = await import("../lib/schemas/log.js");

  assert.equal(normalizeLineCount("40"), 40);
  assert.equal(normalizeLineCount(" 2500 "), 2500);
  // Clamped, not refused: 9000 means "as much as I can have", and the server
  // clamps to the same ceiling anyway.
  assert.equal(normalizeLineCount("9000"), MAX_LINES);
  assert.equal(normalizeLineCount("0"), MIN_LINES);
  assert.equal(normalizeLineCount("-5"), MIN_LINES);

  for (const junk of ["", "   ", "abc", null, undefined]) {
    assert.equal(normalizeLineCount(junk), null, `${JSON.stringify(junk)} is not a line count`);
  }
});

test("the viewer numbers lines by their place in the file, not on the screen", async () => {
  const fs = await import("node:fs");
  const viewer = fs.readFileSync("components/logs/log-viewer.jsx", "utf8");

  assert.match(
    viewer,
    /newestFirst \? rows\.length - item\.index : item\.index \+ 1/,
    "reversed, row 0 is the LAST line — numbering it 1 is the one thing the gutter must not say",
  );
  // The follow anchor has to move with the order, or "stick to the newest"
  // sticks to the oldest.
  assert.match(viewer, /el\.scrollTop = newestFirst \? 0 : el\.scrollHeight/);
  assert.match(viewer, /newestFirst\s*\n?\s*\? el\.scrollTop <= BOTTOM_SLACK/);
  // The reversal is for rendering only: appends are still counted at the end.
  assert.match(viewer, /newestFirst \? \[\.\.\.lines\]\.reverse\(\) : lines/);
});

test("the order toggle and the custom option exist in every locale", async () => {
  const fs = await import("node:fs");
  for (const locale of ["en", "es", "hi"]) {
    const logs = JSON.parse(fs.readFileSync(`messages/${locale}.json`, "utf8")).logs;
    for (const key of ["orderNewestFirst", "orderOldestFirst", "linesCustom", "linesUnit"]) {
      assert.ok(logs[key], `${locale}.logs.${key}`);
    }
  }
});
