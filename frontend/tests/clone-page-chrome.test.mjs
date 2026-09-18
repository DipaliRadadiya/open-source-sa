import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";

/*
 * Krishna, on the Site Clone screen: "make it user friendly, attractive look
 * and modern".
 *
 * It was none of those for reasons that were structural rather than a matter
 * of taste, and the same reasons had already been found once on the
 * Deployments page: the screen was built BESIDE the design system instead of
 * on it. Four cards of identical weight, three of them with no icon and a
 * label-sized heading, none of them wearing the chrome every dashboard card
 * wears.
 *
 * These pin the parts that would quietly come undone. Layout taste is not
 * testable and is not tested here; the system's own rules are.
 */

const source = fs.readFileSync("components/applications/clone/clone-panel.jsx", "utf8");
const code = source.replace(/\/\*[\s\S]*?\*\//g, "").replace(/\{\/\*[\s\S]*?\*\/\}/g, "");

// Slice to ONE component. The first cut of these tests read from a function to
// the end of the file and picked up `truncate` from the copies list three
// components later, then counted the in-flight view's grid tracks as if they
// were the form's — both of which failed for reasons that had nothing to do
// with what was being asserted.
const between = (from, to) => code.slice(code.indexOf(from), to ? code.indexOf(to) : undefined);

test("every card on the page wears the panel's chrome", () => {
  // One shell, so a fifth card cannot disagree about shadow and ring — which
  // is exactly how the dashboard's cards drifted apart before PANEL_CARD.
  assert.match(code, /import \{ PANEL_CARD \}/);
  assert.match(code, /function PanelCard\(/);
  assert.match(code, /PANEL_CARD, className/);
  // And nothing hand-rolls a Card beside it.
  assert.doesNotMatch(code, /<Card\s+className="[^"]*shadow-sm/);
});

test("every card carries a mark, and only one of them is filled", () => {
  /*
   * The filled mark is the page's single point of focus — the form is what you
   * came here to use. A second filled mark, or a fourth card added without
   * one, is how a page goes flat again.
   */
  const filled = code.match(/bg-primary text-primary-foreground/g) ?? [];
  assert.equal(filled.length, 1, "exactly one filled mark");

  const titles = code.match(/<CardTitle/g) ?? [];
  const marks = (code.match(/<CardMark/g) ?? []).length + filled.length;
  assert.equal(marks, titles.length, "every card title has a mark beside it");
});

test("the copied / not-copied list never abbreviates an item", () => {
  /*
   * It was a two-column grid of bare words with `truncate`, which rendered
   * "Repository, branch & git acco…" — a list whose entire job is to state
   * what comes across, abbreviating one of the things that comes across.
   */
  const list = between("function ImpactList", "function BeforeCard");
  assert.doesNotMatch(list, /truncate/);
  assert.doesNotMatch(list, /grid-cols-2/);
});

test("the not-copied half is not greyed out", () => {
  /*
   * All six lines were `text-muted-foreground`, which reads as "disabled
   * options" rather than "facts about what you are about to get" — and buried
   * the only real surprise on the page, that password protection does not
   * come across.
   */
  const list = between("function ImpactList", "function BeforeCard");
  assert.doesNotMatch(list, /text-muted-foreground"[^>]*>\{label\}/);
  assert.match(list, /warned && "font-medium text-warning"/);
});

test("password protection is only flagged when the site actually has it on", () => {
  // Warning tone on a non-event is how a screen teaches people to ignore its
  // warnings. A site with no password protection loses nothing by the copy not
  // having it either.
  assert.match(code, /warn=\{sourceProtected \? "passwordProtection" : null\}/);
});

test("the two columns can shrink below their content", () => {
  /*
   * A grid item's min-width is `auto`, so a card whose content has a wide
   * minimum pushes past its track — measured at 390px, the page scrolled
   * sideways by 17px. Caught by asserting the overflow, not by looking at it.
   */
  // The FORM's grid — the in-flight progress view has one of its own, above.
  const grid = between('<div className="grid gap-6 lg:grid-cols-12 lg:items-stretch">', "<ConfirmDialog");
  const items = grid.match(/className="[^"]*lg:col-span-[57][^"]*"/g) ?? [];
  assert.equal(items.length, 2, "two tracks");
  for (const item of items) {
    assert.match(item, /min-w-0/, item);
  }
});

test("the source and the target are shown as the same kind of thing", () => {
  // Two chips and an arrow IS the feature. Rendering the source as a name and
  // the target as a bare domain made them look like different kinds of object.
  assert.match(code, /function SiteChip\(/);
  assert.equal((code.match(/<SiteChip/g) ?? []).length, 2);
});
