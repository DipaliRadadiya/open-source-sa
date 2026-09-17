import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";

/*
 * Reported on the application detail page:
 *   "yellow full width section looks too bad and takes too much empty unused
 *    space. also top section of app name, domain status etc looks very simple
 *    not even looks like application panel main content"
 *
 * Both were layout, not colour:
 *
 * - Each finding was a full-width band with three words at one edge and its
 *   link at the other — ~900px of nothing between them, twice over.
 * - The header was four text nodes on white above the cards, so nothing said
 *   it was the subject of the page.
 *
 * Shape comes from memory/research-application-dashboard.md: Forge, Plesk and
 * MaxPlane all open a site page with an identity card — mark, name, state,
 * hostname, quick actions, one surface.
 */

const read = (p) => fs.readFileSync(p, "utf8");
const strip = read("components/applications/attention-strip.jsx");
const page = read("app/(app)/applications/[application]/page.jsx");

test("findings are chips sized to their text, not bands or half-width tiles", () => {
  // Chips sized to their own text, wrapping when the server sends sentences —
  // measured, the band went 130px -> 56px for two findings.
  assert.match(strip, /<ul className="flex min-w-0 flex-wrap items-center gap-2">/);
  // The two layouts this replaced, in the shapes they could return as.
  assert.doesNotMatch(strip, /divide-y divide-warning\/20/);
  assert.doesNotMatch(strip, /sm:flex-row sm:items-center sm:gap-x-4/);
  assert.doesNotMatch(strip, /sm:grid-cols-2/);
});

test("each finding's action belongs to the finding, not to the row's far edge", () => {
  // Label and action on one line inside one chip — the association the old
  // `justify-between` row could only imply by being roughly level, and the
  // half-width tile still spent two lines making.
  assert.match(strip, /group inline-flex max-w-full items-center gap-x-2/);
  assert.doesNotMatch(strip, /min-w-48 flex-1/);
});

test("a finding with nowhere to go still gets a chip and no hover", () => {
  // An issue kind the panel has no screen for must not become invisible just
  // because the layout changed shape.
  assert.match(strip, /rounded-lg border border-warning\/25 bg-background\/70 px-2\.5 py-1\.5 text-sm/);
});

test("the header is an identity card carrying the site's own mark", () => {
  assert.match(page, /import \{ SiteTypeLogo \}/);
  assert.match(page, /<SiteTypeLogo name=\{application\.site_type\} size="h-6 w-6" \/>/);
  assert.match(page, /flex size-11 shrink-0 items-center justify-center rounded-lg border bg-background/);
  // One surface, matching the Files toolbar so the two pages read as one product.
  assert.match(page, /<div className="rounded-xl border bg-muted\/30 p-4">/);
});

test("no provider is passed to the logo", () => {
  /*
   * `provider` swaps in a Git host's mark and needs the providers map, which
   * this page does not load — `gitProviderFor` lives on the applications list.
   * Passing an undefined field would silently fall back and look identical in
   * a green build, so the absence is asserted rather than assumed.
   */
  assert.doesNotMatch(page, /<SiteTypeLogo[^>]*provider=/);
});
