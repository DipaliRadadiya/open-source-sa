import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

const CARD = readFileSync(
  new URL("../components/disk-cleaner/schedule-card.jsx", import.meta.url),
  "utf8",
);

test("a dialog full of dead controls says why, on the page", () => {
  /*
   * Reported as "nothing changes in the popup". Every control below the master
   * switch is disabled while it is off, and the reason was in a hover tooltip:
   * invisible until you happen to hover the right dead control, and — Radix
   * tooltips never opening on touch — unreachable on a phone at all. So the
   * dialog read as broken rather than as switched off.
   */
  assert.match(
    CARD,
    /\{!enabled \? \(\s*<p className="-mt-3 text-xs text-muted-foreground">\s*\{t\("schedule\.turnOnFirst"\)\}/,
    "the off-state reason is hidden again",
  );
});

test("a Save that refuses says what is missing, on the page", () => {
  // Same fault one step later: Save is disabled until a category is ticked,
  // and its reason was hover-only, which reads as a form silently refusing.
  assert.match(
    CARD,
    /enabled && picked\.size === 0 \? \(\s*<span className="font-normal text-warning">\{t\("schedule\.pickSomething"\)\}/,
    "the empty-selection reason is hidden again",
  );
});
