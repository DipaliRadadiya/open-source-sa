import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";

/*
 * Reported on Storage → Add Destination: the provider field's note opened by
 * itself and sat on top of the dropdown.
 *
 * Nothing opened it. A dialog hands focus to the first focusable thing it can
 * find, the "?" icon sits ahead of its own field in the DOM, and the icon
 * opened on any focus at all. So it was not a Storage bug — every dialog whose
 * first label carries a hint did it, about 18 of them.
 *
 * The scroll half of the report was a consequence, not a second fault: the
 * popover tracks its trigger correctly (measured — the gap held at 34px
 * through a 200px scroll). It only looked wrong because it was open while the
 * form moved under it.
 *
 * The behaviour now lives in `useHoverPopover`, shared with the dashboard's
 * site-health chip. These assertions moved with it rather than being relaxed:
 * a guard that stops applying the moment the code is refactored is not a guard.
 */

const read = (p) => fs.readFileSync(p, "utf8");
const strip = (s) => s.replace(/\/\*[\s\S]*?\*\//g, "").replace(/^\s*\/\/.*$/gm, "");

const hookSource = read("lib/hooks/use-hover-popover.js");
const hook = strip(hookSource);
const hintSource = read("components/ui/info-hint.jsx");
const hint = strip(hintSource);

test("focus opens the note only when it came from the keyboard", () => {
  assert.match(hook, /const openOnKeyboardFocus = useCallback\(/);
  assert.match(hook, /if \(!event\.currentTarget\.matches\(":focus-visible"\)\) return;/);

  // The handler must be the guarded one. Wiring `openOnHover` straight to
  // onFocus is the bug: a dialog's own focus call would open it again.
  assert.match(hook, /onFocus: openOnKeyboardFocus/);
  assert.doesNotMatch(hook, /onFocus: openOnHover/);
});

test("a caller has to ask for the focus route", () => {
  /*
   * The second consumer — a chip in the dashboard footer — must NOT open on
   * Tab: it is a button, Enter already opens it, and a panel appearing as you
   * tab past is the same complaint in a new place. So the route is opt-in, and
   * the hint is the one that opts in.
   */
  assert.match(hook, /if \(!focusOpens\) return;/);
  assert.match(hint, /useHoverPopover\(\{\s*focusOpens: true,?\s*\}\)/);
  assert.doesNotMatch(strip(read("components/dashboard/site-attention.jsx")), /focusOpens/);
});

test("hover and touch are untouched", () => {
  // The reason this is a Popover and not a Tooltip in the first place: Radix
  // tooltips never open on touch. Narrowing the focus route must not quietly
  // cost the pointer routes.
  assert.match(hook, /onMouseEnter: openOnHover/);
  assert.match(hook, /onMouseLeave: closeOnLeave/);
  assert.match(hook, /matchMedia\("\(hover: hover\)"\)/);
  // And the hint must actually spread them onto its trigger.
  assert.match(hint, /\{\.\.\.triggerProps\}/);
  assert.match(hint, /\{\.\.\.contentProps\}/);
});

test("blur still closes a note the pointer or a Tab opened", () => {
  // Asymmetry here would strand an open note: opened by Tab, never dismissed
  // by tabbing away. Both routes set `hoverOpened`, so both are covered.
  assert.match(hook, /onBlur: closeOnLeave/);
});

test("a panel opened by a CLICK does not close itself", () => {
  /*
   * Found by driving it: clicking the dashboard chip opened the panel and it
   * vanished ~120ms later. Radix hands focus to the content on open, that
   * blurs the trigger, and the blur handler above closed it — the panel
   * closing in response to its own opening.
   *
   * A hover is a glance and may evaporate; a click asked for something.
   */
  assert.match(hook, /if \(!hoverOpened\.current\) return;/);
});

test("the docblocks no longer promise plain focus-opening", () => {
  // These comments are the only place the behaviour is written down, and a
  // stale one is how the guard gets "simplified" back out.
  assert.match(hintSource, /never from focus a dialog handed over/);
  assert.match(hookSource, /focus-visible/);
});
