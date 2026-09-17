import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";

/*
 * Reported on the Logs page: "does not create enough visual distinction
 * between tabs, secondary actions, and primary action buttons... everything
 * looks equally important."
 *
 * The cause was one picture doing two jobs. The page tabs (Access log / Error
 * log) and the severity filter (All / Errors / Warnings+) were both a grey
 * segmented tray with one lit cell — one switches WHICH log you read, the
 * other filters the one you are in.
 *
 * Fixed from the filter's side, not the tabs'. Tabs keep the pill/segmented
 * look they have on every other screen in the panel; the filter becomes what
 * it actually is, a set of toggles. (An earlier attempt moved the tabs to
 * underline instead — reverted, because the pill IS the panel's tab style and
 * changing ten screens to unbreak one was the wrong end to pull.)
 */

const read = (p) => fs.readFileSync(p, "utf8");
const toolbar = read("components/logs/log-toolbar.jsx");
const tabs = read("components/ui/tabs.jsx");
const code = toolbar.replace(/\/\*[\s\S]*?\*\//g, "").replace(/^\s*\/\/.*$/gm, "");

// Just the severity control. Scoped, because the icon group further down the
// same toolbar is legitimately a joined tray — five view actions on one object
// — and a file-wide search for that shape finds it and calls it the bug.
const severityBlock = code.slice(
  code.indexOf('aria-label={t("severityLabel")}'),
  code.indexOf("{customLines ?"),
);

test("the severity filter is separate toggles, not a segmented tray", () => {
  assert.ok(severityBlock.length > 0, "the severity group should still be findable");
  assert.match(severityBlock, /className="flex items-center gap-1"/);
  // The tray: one rounded border around the set, hairlines between the cells.
  assert.doesNotMatch(severityBlock, /overflow-hidden rounded-lg border divide-x/);
  assert.doesNotMatch(severityBlock, /divide-x/);
});

test("the active filter is tinted, not dressed as a selected tab", () => {
  const chrome = read("lib/theme/filter-toggle.js");
  assert.match(chrome, /border-primary\/40 bg-primary\/10 font-medium text-primary/);
  // `bg-secondary` is the tab strip's active cell — the exact collision.
  assert.doesNotMatch(code, /bg-secondary font-medium text-secondary-foreground/);
});

test("all three filters stay visible rather than folding into a dropdown", () => {
  // "Show me only errors" is the reason most people open this page; it should
  // cost one click, not two.
  assert.match(code, /SEVERITY_FILTERS\.map\(\(key\) => \(/);
  assert.match(code, /aria-pressed=\{severity === key\}/);
});

test("tabs are left alone, still the panel's segmented style", () => {
  assert.match(tabs, /defaultVariants: \{\s*\n\s*variant: "default",/);
  assert.match(tabs, /variant = "default",/);
  assert.match(tabs, /default: "bg-muted"/);
});

test("every control on the band is one size, radius and border", () => {
  /*
   * Reported after the first pass: "that all looks different not looks
   * together." Measured, the row was five different controls —
   *
   *   filter input      h=36  radius=10  border=1
   *   severity toggles  h=36  radius=10  border=0   (transparent until active)
   *   Last 200 lines    h=36  radius=10  border=1
   *   Clear log         h=32  radius=8   border=1   (size="sm")
   *   icon tray         h=38  radius=10  border=1   (36px children + border)
   *
   * Rank belongs in COLOUR — blue for the live filter, red for the
   * destructive action. Size and shape are what make them one set, and three
   * of the five were off.
   */
  // An inactive toggle keeps a real edge instead of floating in the row. The
  // look moved into lib/theme/filter-toggle.js when the bot traffic card was
  // found to have the same fault — see tests/filter-toggle-chrome.test.mjs.
  assert.match(severityBlock, /filterToggleClass\(severity === key\)/);
  assert.doesNotMatch(severityBlock, /border-transparent/);
  // `size="sm"` is 32px with an 8px radius; the band is 36 and 10.
  assert.match(code, /className="h-9 rounded-lg border-destructive\/40/);
  // The tray held 36px children inside a 1px border, coming to 38.
  assert.match(code, /<div className="flex h-9 items-center overflow-hidden rounded-lg border divide-x">/);
  assert.match(code, /"size-9 h-full rounded-none"/);
});

test("Clear log carries destructive weight, and actions are divided from view", () => {
  // Kept from the same round: it was a plain outline button beside the "Last
  // 200 lines" dropdown — one changes what you see, the other empties the file
  // for good.
  assert.match(code, /border-destructive\/40 text-destructive hover:bg-destructive\/10/);
  // Same rule the Files toolbar uses, so one grouping language covers both.
  assert.match(code, /<Separator orientation="vertical" className="mx-0\.5 !h-5 !self-center" \/>/);
});
