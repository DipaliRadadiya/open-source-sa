import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";

/*
 * Found on the live panel. With automatic cleanup off, the card printed the
 * same sentence twice, stacked:
 *
 *   Off — clean up by hand whenever you need to.      (text-lg font-semibold)
 *   Off — clean up by hand whenever you need to.      (text-xs muted)
 *
 * `summary` already falls back to `schedule.summaryOff` when the cleaner is
 * disabled, and the else-branch of the badge row rendered the same key again.
 * The enabled branch is fine — there the heading is `summaryOn` and the row
 * below carries the category badges, which is what that slot is for. Off, the
 * slot has nothing to add, so it collapses.
 */

const read = (p) => fs.readFileSync(p, "utf8");
const card = read("components/disk-cleaner/schedule-card.jsx");
const code = card.replace(/\/\*[\s\S]*?\*\//g, "").replace(/\{\/\*[\s\S]*?\*\/\}/g, "");

test("summaryOff is rendered exactly once", () => {
  const hits = code.match(/schedule\.summaryOff/g) ?? [];
  assert.equal(hits.length, 1, `summaryOff appears ${hits.length} times; it is the heading only`);
});

test("it survives as the heading fallback, not as body text", () => {
  // The heading is the one that must keep it: with the cleaner off there is no
  // frequency or count to state, so `summary` has nothing else to say.
  assert.match(code, /:\s*t\("schedule\.summaryOff"\);/);
  assert.doesNotMatch(code, /<p className="mt-2 text-xs text-muted-foreground">\{t\("schedule\.summaryOff"\)\}<\/p>/);
});

test("with a schedule on, the tile still says when it fires", () => {
  // 13 Oct: the badge row became a compact tile (Krishna: too much empty space). The
  // tile keeps the half that answers "will it run": next run, and the disk threshold.
  assert.match(code, /nextRunLine \?\?/);
  assert.match(code, /whenLine,\s*\]\s*\.filter\(Boolean\)/);
});
