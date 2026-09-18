import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";

/*
 * The dashboard is the panel's landing route, and it had no idea whether the
 * server had fifty sites or none — so a first-time user met an idle machine's
 * vital signs and nothing they could act on. The good onboarding already
 * existed; it was on the Applications page, one navigation away, and nothing
 * pointed at it.
 */

const read = (p) => fs.readFileSync(p, "utf8");
const { locales } = await import("../i18n/routing.js");
const dashboard = read("app/(app)/dashboard/page.jsx");
const emptyState = read("components/applications/application-empty-state.jsx");
const table = read("components/applications/applications-table.jsx");

test("the empty state declares its own client boundary", () => {
  /*
   * It calls useTranslations and useBranding, and got away with no directive
   * for as long as its only caller was the client applications table, which
   * carried the boundary for it. The dashboard is a Server Component: without
   * this line the import builds completely clean and throws at render, which
   * is the failure mode plain JS is worst at surfacing.
   */
  assert.match(emptyState.split("\n")[0], /^"use client";$/);
});

test("an empty server is claimed only on a total we actually received", () => {
  /*
   * `failed` is not the same as zero. A list read that errored means "could
   * not ask", and treating that as an empty server would greet someone who
   * has fifty sites by inviting them to create their first.
   */
  const match = dashboard.match(/const firstRun = ([^;]+);/);
  assert.ok(match, "firstRun is derived in one place");
  assert.match(match[1], /!appResult\.failed/);
  assert.match(match[1], /meta\.total === 0/);
});

test("the count is not fetched for a reader who could never be offered the card", () => {
  assert.match(dashboard, /canViewApplications = can\(permissions, "application", "view"\)/);
  assert.match(dashboard, /canViewApplications \? getApplications\(""\) : Promise\.resolve\(null\)/);
});

test("the card is fetched alongside the rest, not after it", () => {
  // Serial awaits would add a round trip to the slowest screen in the panel.
  const block = dashboard.slice(dashboard.indexOf("await Promise.all(["), dashboard.indexOf("firstRun"));
  assert.match(block, /getApplications/);
});

test("creating is gated on manage, viewing the card on view", () => {
  // The Applications page draws exactly this distinction: a viewer sees the
  // empty state, only a manager gets the button inside it.
  assert.match(dashboard, /canManage=\{can\(permissions, "application", "manage"\)\}/);
});

test("the dashboard takes the compact card and the Applications page does not", () => {
  /*
   * Measured on the real build at 1280x900: the full-height card is 394px and
   * pushed all five live stat cards below the fold; compact is 223px and they
   * fit. On the Applications page the card IS the page, so it stays generous.
   */
  assert.match(dashboard, /<ApplicationEmptyState[\s\S]{0,120}compact\s*\/?>/);
  const call = table.slice(table.indexOf("<ApplicationEmptyState"));
  assert.doesNotMatch(call.slice(0, 120), /compact/);
});

test("compact lays the three steps across, which is where the height went", () => {
  const compact = emptyState.slice(
    emptyState.indexOf("if (compact)"),
    emptyState.lastIndexOf("return ("),
  );
  assert.match(compact, /sm:grid-cols-3/);
  // The stacked variant's spacing must not follow it in.
  assert.doesNotMatch(compact, /space-y-4/);
});

test("compact leaves vertical padding to the Card", () => {
  /*
   * `Card` pads itself with `py-(--card-spacing)` (16px). A `py-*` on the
   * CardContent inside it does not replace that — the two are different
   * elements, so tailwind-merge has nothing to dedupe and they stack. `py-5`
   * here read as a reasonable 20px and rendered as 36px top and bottom, which
   * is the padding Krishna could see and I could not explain until I measured
   * the computed style. Horizontal only; the height fix depends on it.
   */
  const compact = emptyState.slice(
    emptyState.indexOf("if (compact)"),
    emptyState.lastIndexOf("return ("),
  );
  const content = compact.match(/<CardContent className="([^"]*)"/);
  assert.ok(content, "compact uses CardContent with an explicit class");
  assert.doesNotMatch(content[1], /\bp-|\bpy-|\bpt-|\bpb-/);
  assert.match(content[1], /\bpx-/);
});

test("compact needs no string the full card did not already have", () => {
  /*
   * The whole point of a second layout inside one component rather than a
   * second component: one copy of the words, so the two surfaces cannot drift
   * and neither can ship an untranslated key. `empty.steps.*` is built with a
   * template literal and is invisible to grep, so the steps are named here.
   */
  const used = new Set();
  for (const [, key] of emptyState.matchAll(/\bt\("([^"]+)"/g)) used.add(key);
  for (const step of ["choose", "configure", "provision"]) {
    used.add(`empty.steps.${step}.title`);
    used.add(`empty.steps.${step}.description`);
  }
  assert.ok(used.size >= 9, `expected the full key set, saw ${used.size}`);

  const get = (o, p) => p.split(".").reduce((a, k) => a?.[k], o);
  for (const locale of locales) {
    const applications = JSON.parse(read(`messages/${locale}.json`)).applications;
    for (const key of used) {
      assert.equal(
        typeof get(applications, key),
        "string",
        `${locale} is missing applications.${key}`,
      );
    }
  }
});
