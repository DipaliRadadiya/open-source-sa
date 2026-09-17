import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";

/*
 * Reported on the blocked-bot list: "this is not clearly scannable or
 * visible."
 *
 * Twenty-three chips under a heading lighter than the chips themselves, in the
 * API's own order. You come to this list asking one question — "is my crawler
 * in here?" — and answering it meant reading every pill, because nothing told
 * you where to look.
 */

const read = (p) => fs.readFileSync(p, "utf8");
const section = read("components/applications/bot-blocker/bot-blocker-section.jsx");
const code = section.replace(/\/\*[\s\S]*?\*\//g, "").replace(/^\s*\/\/.*$/gm, "");

test("the names are sorted, so a name can be found rather than hunted", () => {
  assert.match(code, /a\.localeCompare\(b, undefined, \{ sensitivity: "base" \}\)/);
  // Case-insensitive on purpose: the API ships both `Meta-ExternalAgent` and
  // `meta-externalagent`, and a default sort files those in separate A–Z and
  // a–z runs, which is worse than not sorting.
  assert.match(code, /const sorted = \[\.\.\.bots\]\.sort\(/);
});

test("sorting is display-only and does not touch grouping", () => {
  /*
   * `botGroups` walks the policies in its own order and dedupes with a `seen`
   * set — which bot lands in which group depends on that walk. Sorting there
   * would silently move names between groups; sorting in the leaf that renders
   * them cannot.
   */
  assert.match(code, /function BotList\(\{ bots \}\) \{\s*\n\s*const sorted/);

  // Sliced to the function body. A window-of-N-characters regex runs straight
  // past the closing brace into BotList's own sort and fails on it — which is
  // exactly what the first version of this assertion did.
  const start = code.indexOf("function botGroups");
  const body = code.slice(start, code.indexOf("\nfunction ", start + 1));
  assert.ok(body.includes("const seen = new Set()"), "botGroups should still be findable");
  assert.doesNotMatch(body, /\.sort\(/, "grouping must not reorder — it decides which group a bot lands in");
});

test("the group heading outweighs the chips it labels", () => {
  // It was `text-xs font-medium text-muted-foreground` — lighter than the
  // things underneath it, so three groups read as one wall.
  assert.match(code, /text-xs font-semibold uppercase tracking-wide text-foreground/);
  assert.doesNotMatch(code, /<p className="text-xs font-medium text-muted-foreground">\{label\}<\/p>/);
});

test("each group says how many it holds", () => {
  // Answers "how big is this one" without counting pills.
  assert.match(code, /tabular-nums text-muted-foreground">\s*\n?\s*\{bots\.length\}/);
});

test("the chips sit on their own surface inside the tinted panel", () => {
  // Outline-only chips on `bg-muted/30` have almost no edge; a solid fill is
  // what separates 23 of them from the panel behind.
  assert.match(code, /className="border-primary\/20 bg-primary\/5 font-mono font-normal text-primary"/);
});
