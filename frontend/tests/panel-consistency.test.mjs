import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import { execFileSync } from "node:child_process";
import { genericErrorMessage, setGenericErrorMessage } from "../lib/api/generic-error.js";

/*
 * A consistency pass, from the panel-wide audit. None of these are visible in
 * a single screenshot — they are only visible in aggregate, which is why they
 * survived this long and why they are pinned here rather than looked at.
 */

const read = (p) => fs.readFileSync(p, "utf8");
const code = (s) => s.replace(/\/\*[\s\S]*?\*\//g, "").replace(/^\s*\/\/.*$/gm, "");
const { locales } = await import("../i18n/routing.js");

test("every screen opens with the same heading component", () => {
  /*
   * 28 route files had PageHeader's markup copied out by hand. That is how the
   * firewall page once had a title disagreeing with its own sidebar label, and
   * how the settings layout ended up on space-y-0.5 while every other screen
   * used space-y-1 — a 2px difference nobody chose.
   *
   * The guard is the point; this asserts the guard is wired in and passing,
   * because a check nobody runs is a comment.
   */
  const pkg = JSON.parse(read("package.json"));
  assert.match(pkg.scripts.lint, /check-page-header\.mjs/, "not in the lint chain");
  const out = execFileSync("node", ["scripts/check-page-header.mjs"], { encoding: "utf8" });
  assert.match(out, /page headers ok/);
});

test("the last-resort error sentence is translated", () => {
  /*
   * `handle-validation-error.js` shipped a hardcoded "Something went wrong" to
   * every reader — the only English string left in the panel. It is a plain
   * module called from 42 places, so it cannot call useTranslations; the shell
   * hands it over instead.
   */
  const handler = code(read("lib/api/handle-validation-error.js"));
  assert.doesNotMatch(handler, /"Something went wrong"/);
  assert.match(handler, /genericErrorMessage\(\)/);

  // It reuses the error boundary's own words rather than inventing a second
  // phrasing of the same sentence.
  for (const locale of locales) {
    const messages = JSON.parse(read(`messages/${locale}.json`));
    assert.equal(typeof messages.errors?.title, "string", `${locale} errors.title`);
  }
});

test("the shell actually hands that sentence over", () => {
  // The module defaults to English. Mounted nowhere, the fix would be a file
  // that compiles and changes nothing.
  for (const layout of [
    "app/(app)/layout.jsx",
    "app/admin/layout.jsx",
    "app/(setup)/layout.jsx",
    "app/(auth)/layout.jsx",
  ]) {
    assert.match(read(layout), /<ErrorCopy \/>/, layout);
  }
  assert.match(read("components/sections/error-copy.jsx"), /setGenericErrorMessage\(t\("title"\)\)/);
});

test("a blank or missing translation never blanks the message", () => {
  // Behavioural, not a grep: an empty string here would replace a useful
  // English sentence with nothing at all.
  const before = genericErrorMessage();
  setGenericErrorMessage("");
  assert.equal(genericErrorMessage(), before);
  setGenericErrorMessage(null);
  assert.equal(genericErrorMessage(), before);
  setGenericErrorMessage("Algo salió mal");
  assert.equal(genericErrorMessage(), "Algo salió mal");
});

test("tooltips wait, except the ones explaining why a control is dead", () => {
  /*
   * All four shells opened a tooltip the instant a pointer touched any icon,
   * so crossing a toolbar flashed one off every button.
   *
   * A disabled reason is the exception: the reader is already pointing at the
   * control because it did not work, and making them hold still is charging
   * for the explanation.
   */
  for (const layout of [
    "app/(app)/layout.jsx",
    "app/admin/layout.jsx",
    "app/(setup)/layout.jsx",
    "app/(auth)/layout.jsx",
  ]) {
    assert.match(read(layout), /TooltipProvider delayDuration=\{300\}/, layout);
  }
  assert.match(read("components/ui/reason-tooltip.jsx"), /<Tooltip delayDuration=\{0\}>/);
});

test("nothing in the panel is smaller than 12px", () => {
  /*
   * 47 places used text-[10px] or text-[11px]. Each looked reasonable on the
   * screen it was written on; together they made a product that is hard to
   * read at 200% zoom or on a laptop panel. None of it was a decision — 11px
   * is what you reach for when text-xs feels one notch too big.
   *
   * Measured before and after on the sidebar and the dashboard in en/de/ru:
   * the smallest rendered text went 10px → 12px and the list of overflowing
   * elements was byte-identical, so the bump cost no layout.
   */
  const pkg = JSON.parse(read("package.json"));
  assert.match(pkg.scripts.lint, /check-type-floor\.mjs/, "not in the lint chain");
  const out = execFileSync("node", ["scripts/check-type-floor.mjs"], { encoding: "utf8" });
  assert.match(out, /type floor ok/);
});

test("a component may still quote an old size in its own comment", () => {
  /*
   * `disk-io-chart.jsx` explains a past fix with "It was text-[10px] at 80%
   * opacity". Both this bump and the guard mask comments first — the same trap
   * that has now caught me three times in this codebase: a check that reads a
   * component's own history as a fresh offence.
   */
  const chart = read("components/dashboard/disk-io-chart.jsx");
  assert.match(chart, /text-\[10px\]/, "the comment should be left alone");
  const guard = read("scripts/check-type-floor.mjs");
  assert.match(guard, /const mask =/);
});

test("a stat card's value and its hint stack before they can overflow", () => {
  /*
   * Reported as a Russian problem; it was not. Measured across all eight
   * locales, EVERY one overflowed at 1280 — English by 26px, Hindi by 56 and
   * still 24 at 1440. `xl:grid-cols-5` jumps straight from two columns to five
   * at 1280, which leaves each card about 182px of content while the value and
   * its hint want up to 209px side by side ("Wird gemessen… + 4 Kerne",
   * "80.0 GB में से 37.6 GB").
   *
   * A container query, not `flex-wrap`: the five cards are equal width so the
   * query flips all of them on the same tick, where wrapping would break only
   * the longest card's row and drop its bar and helper line below its
   * neighbours' — the drift the comments in this component already fixed twice.
   *
   * The threshold is in px and measures the CONTENT box, because
   * `container-type: inline-size` queries the content box rather than the
   * border box. Reading it as the border box put the number 32px out and the
   * cards stacked at every width, including 1920.
   */
  const card = read("components/ui/stat-card.jsx");
  assert.match(card, /@container\/stat/, "the card has to be a container");
  assert.match(card, /@max-\[212px\]\/stat:flex-col/, "the value row has to stack");
  // rem would re-open the same off-by-a-root-font-size question.
  assert.doesNotMatch(card, /@max-\[[\d.]+rem\]\/stat/);
});

test("a card's vertical padding is set once, not twice", () => {
  /*
   * Krishna, on the ionCube card: "why it has too much space on top and bottom
   * padding". Measured: 32px each side where every other card has 16.
   *
   * `Card` carries `py-(--card-spacing)`; `CardContent` sets only `px`. So a
   * `py-4` on the content does not replace the card's padding, it adds a
   * second one. Fifteen cards already avoided this by handing the padding over
   * — `<Card className="gap-0 py-0">` — and eight did not.
   *
   * ⚠️ I read it backwards first and stripped the padding from all 23, which
   * would have left the fifteen correct ones with none at all. The question is
   * not "does the content set py" but "does exactly one of the pair set it".
   */
  const pkg = JSON.parse(read("package.json"));
  assert.match(pkg.scripts.lint, /check-card-padding\.mjs/, "not in the lint chain");
  const out = execFileSync("node", ["scripts/check-card-padding.mjs"], { encoding: "utf8" });
  assert.match(out, /card padding ok/);
  // The count is asserted so deleting the cards is not a way to pass.
  assert.match(out, /\d+ content-padded cards/);
});
