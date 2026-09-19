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
