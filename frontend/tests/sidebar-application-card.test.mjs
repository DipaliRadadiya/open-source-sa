import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";

/*
 * Reported: "in sidebar showing application information that ui needs to
 * improve. currently everything looks same not differentiate anything", then
 * — after a first attempt that reworked the card's INSIDES — "everything looks
 * same to sidebar".
 *
 * The second message is the real one. The card was `bg-sidebar-accent/40`: a
 * slightly darker grey on a grey rail, with no edge, so it read as one more
 * nav item rather than as the subject the nav belongs to. Its internals were
 * not the problem; its boundary was.
 *
 * Missed the first time because the mock put the card on a white page with a
 * fake rail. It only became visible once the real sidebar was rendered around
 * it — the same lesson as the toolbar measured without the shell.
 */

const read = (p) => fs.readFileSync(p, "utf8");
const sidebar = read("components/sections/app-sidebar.jsx");
// Negative assertions run against code: this file's own comments name the very
// classes some of them forbid, so matching the raw source finds the
// explanation and calls it the regression.
const sidebarCode = sidebar.replace(/\/\*[\s\S]*?\*\//g, "").replace(/^\s*\/\/.*$/gm, "");
const badge = read("components/applications/application-status-badge.jsx");

test("the card is a surface of its own, not another shade of the rail", () => {
  assert.match(sidebar, /rounded-xl border border-primary\/25 bg-primary\/5 p-3 hover:bg-primary\/10/);
  assert.doesNotMatch(sidebarCode, /bg-sidebar-accent\/40/, "back to a grey card on a grey rail");
});

test("status is a dot here, and comes from the one status definition", () => {
  /*
   * There were once three copies of this mapping and they disagreed on screen
   * — the same paused site read green "Running" in the header and red
   * "Running" in the sidebar. That is why the dot lives in the badge module
   * and reuses STATUS_VARIANTS and the is_disabled precedence rather than
   * restating either.
   */
  assert.match(badge, /export function ApplicationStatusDot/);
  assert.match(badge, /const variant = paused \? "warning" : \(STATUS_VARIANTS\[application\.status\] \?\? "secondary"\)/);
  assert.match(badge, /const DOT_TONES = \{/);

  // The sidebar must not hand-roll its own colour.
  assert.match(sidebar, /<ApplicationStatusDot application=\{application\}/);
  assert.doesNotMatch(sidebarCode, /bg-success|bg-destructive/, "the sidebar is picking status colours again");
});

test("the type name is dropped as redundant beside its own logo", () => {
  assert.match(sidebar, /<SiteTypeLogo name=\{application\.site_type\}/);
  assert.doesNotMatch(sidebarCode, /site_type_title \?\? application\.site_type/);
});

test("the collapsed rail hides everything but the mark", () => {
  /*
   * Measured: with only the sidebar's own rule in play — which hides a
   * button's LAST span, here the domain — the name and status overflowed the
   * 32px square by 14px. Both halves need saying explicitly.
   */
  assert.match(sidebar, /group-data-\[collapsible=icon\]:hidden">\s*\n\s*<span className="block truncate text-sm font-semibold"/);
  assert.match(sidebar, /font-mono text-\[11px\] text-muted-foreground group-data-\[collapsible=icon\]:hidden/);
  assert.match(sidebar, /group-data-\[collapsible=icon\]:size-5!/);
});

test("the domain gets its own line rather than sharing the name's column", () => {
  // At 240px the domain is the longest string on the card; in the name's
  // column it truncated mid-host, which is the one thing read off this card.
  assert.match(sidebar, /mt-2\.5 block w-full truncate rounded-md bg-background\/80/);
});
