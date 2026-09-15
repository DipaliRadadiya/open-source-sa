import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";

const SOURCE = fs.readFileSync("components/ui/truncated-text.jsx", "utf8");

test("the observer follows the element through the tooltip swap", () => {
  /*
   * The bug this pins, which shipped and showed zero tooltips:
   *
   * Measuring the text as clipped swaps the plain span for one inside a
   * TooltipTrigger — a different position in the tree, so React unmounts the
   * measured element and mounts a new one. A `useRef` does not re-run the
   * effect, so the ResizeObserver kept watching the DETACHED node, which
   * reports 0×0 and therefore "fits" — flipping the state straight back. Every
   * measurement was correct and the result was always false.
   *
   * A callback ref into state re-runs the effect on whichever node is on
   * screen. `node` in the dependency array is the half that makes it work.
   */
  assert.match(SOURCE, /const \[node, setNode\] = useState\(null\)/);
  assert.match(SOURCE, /ref=\{setNode\}/);
  // Matched on the CALL and the import, not on the word: the comment above
  // names `useRef` to explain what went wrong, and a test that fails on its
  // own documentation is a test about nothing.
  assert.doesNotMatch(SOURCE, /useRef\s*\(/, "a ref object cannot re-run the effect on a new node");
  assert.doesNotMatch(SOURCE, /^import \{[^}]*useRef[^}]*\} from "react"/m);
  assert.match(SOURCE, /\}, \[node, children\]\)/, "re-measure when the element or the text changes");
  assert.match(SOURCE, /observer\.disconnect\(\)/, "and stop watching the old one");
});

test("a line that fits gets no tooltip at all", () => {
  /*
   * A bubble that repeats what is already on screen teaches people that
   * tooltips are noise, and then the one that mattered goes unread. The
   * exception is an explicit `tooltip`, where the visible line is a SUMMARY —
   * "Needs MySQL or MariaDB" over the server's whole sentence — so there is
   * more to say whether or not it fits.
   */
  assert.match(SOURCE, /if \(!clipped && !tooltip\) return line;/);
  assert.match(SOURCE, /\{tooltip \?\? children\}/);
});

test("the measurement is visible to a test, not only its consequence", () => {
  // Without `data-clipped` on the element, a test can only observe the tooltip
  // that was supposed to appear — which is exactly how the swap bug above went
  // unseen through a green build and a green suite.
  assert.match(SOURCE, /data-clipped=\{clipped \? "true" : "false"\}/);
});

test("the width is compared with a pixel of slack", () => {
  // Sub-pixel layout leaves scrollWidth a hair over clientWidth on text that
  // is plainly not clipped; without the slack every card in the grid grows a
  // tooltip.
  assert.match(SOURCE, /scrollWidth > node\.clientWidth \+ 1/);
});
