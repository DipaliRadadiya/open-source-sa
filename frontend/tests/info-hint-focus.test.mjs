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
 */

const source = fs.readFileSync("components/ui/info-hint.jsx", "utf8");
const code = source.replace(/\/\*[\s\S]*?\*\//g, "").replace(/^\s*\/\/.*$/gm, "");

test("focus opens the note only when it came from the keyboard", () => {
  assert.match(code, /const openOnKeyboardFocus = \(event\) => \{/);
  assert.match(code, /if \(!event\.currentTarget\.matches\(":focus-visible"\)\) return;/);

  // The handler must be the guarded one. Wiring `openOnHover` straight to
  // onFocus is the bug: a dialog's own focus call would open it again.
  assert.match(code, /onFocus=\{openOnKeyboardFocus\}/);
  assert.doesNotMatch(code, /onFocus=\{openOnHover\}/);
});

test("hover and touch are untouched", () => {
  // The reason this is a Popover and not a Tooltip in the first place: Radix
  // tooltips never open on touch. Narrowing the focus route must not quietly
  // cost the pointer routes.
  assert.match(code, /onMouseEnter=\{openOnHover\}/);
  assert.match(code, /onMouseLeave=\{closeOnLeave\}/);
  assert.match(code, /matchMedia\("\(hover: hover\)"\)/);
});

test("blur still closes it", () => {
  // Asymmetry here would strand an open note: opened by Tab, never dismissed
  // by tabbing away.
  assert.match(code, /onBlur=\{closeOnLeave\}/);
});

test("the docblock no longer promises plain focus-opening", () => {
  // This component's comments are the only place its behaviour is written
  // down, and a stale one here is how the guard gets "simplified" back out.
  assert.match(source, /never from focus a dialog handed over/);
});
