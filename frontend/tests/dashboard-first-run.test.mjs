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

test("the empty state is not imported by the server dashboard any more", () => {
  /*
   * It needed its own "use client" while the dashboard (a Server Component)
   * rendered its compact variant. The dashboard now has the Get started
   * checklist, so the card's only caller is the client applications table.
   */
  assert.doesNotMatch(dashboard, /application-empty-state/);
});

test("an empty server is claimed only on a list we actually received", () => {
  /*
   * `failed` is not the same as zero. A list read that errored means "could
   * not ask", and treating that as an empty server would greet someone who
   * has fifty sites by inviting them to create their first. The same guard
   * covers the health chip: "nothing is wrong" over an unanswered request is
   * the more dangerous of the two lies.
   */
  const known = dashboard.match(/const known = ([^;]+);/);
  assert.ok(known, "`known` is derived in one place");
  assert.match(known[1], /!appResult\.failed/);

  const firstRun = dashboard.match(/const firstRun = ([^;]+);/);
  assert.ok(firstRun, "firstRun is derived in one place");
  assert.match(firstRun[1], /known/);
  assert.match(firstRun[1], /length === 0/);
});

test("the list is not fetched for a reader who could be shown neither", () => {
  assert.match(dashboard, /canViewApplications = can\(permissions, "application", "view"\)/);
  assert.match(dashboard, /canViewApplications \? getAllApplications\(\) : Promise\.resolve\(null\)/);
});

test("every site is scanned, not just the first page", () => {
  /*
   * `getApplications("")` stops at ten. That answers "are there none", which
   * is all the first-run card needed, but the health chip scans for problems —
   * and a broken site on page two would simply never have been mentioned.
   * `getAllApplications` asks for the API's maximum instead.
   */
  // Comments stripped first: the comment above that line explains the change
  // by naming the old call, and a bare grep reads its own explanation as the
  // bug it is describing.
  const code = dashboard.replace(/\/\*[\s\S]*?\*\//g, "").replace(/\/\/.*$/gm, "");
  assert.match(code, /getAllApplications\(\)/);
  assert.doesNotMatch(code, /getApplications\(""\)/);
});

test("the card is fetched alongside the rest, not after it", () => {
  // Serial awaits would add a round trip to the slowest screen in the panel.
  const block = dashboard.slice(dashboard.indexOf("await Promise.all(["), dashboard.indexOf("firstRun"));
  assert.match(block, /getAllApplications\(\)/);
});

test("creating is gated on manage, viewing the card on view", () => {
  // The Applications page draws exactly this distinction: a viewer sees the
  // empty state, only a manager gets the button inside it.
  assert.match(dashboard, /canCreate=\{can\(permissions, "application", "manage"\)\}/);
});

/*
 * Redesign (6 Oct 2026): the dashboard's compact copy of the empty state became
 * a "Get started" checklist, the First visit screen Krishna approved.
 */
const start = read("components/dashboard/getting-started.jsx");

test("the dashboard shows the checklist on an empty server, the Applications page keeps its card", () => {
  assert.match(dashboard, /\{firstRun \? \(\s*<GettingStarted/);
  assert.match(table, /<ApplicationEmptyState/);
});

test("only the step that can be done now has a button", () => {
  // Steps 3–5 need an application; a button there would lead nowhere yet.
  assert.match(start, /\{current \? \(/);
  assert.match(start, /: !complete \? \(\s*<span[^>]*>\{t\("afterApp"\)\}/);
  // And a viewer reads why there is no Create, rather than meeting a missing button.
  assert.match(start, /\{tApplications\("noPermission"\)\}<\/span>/);
});

test("every checklist string exists in every locale", () => {
  const get = (o, p) => p.split(".").reduce((a, k) => a?.[k], o);
  const keys = ["title", "progress", "afterApp"];
  for (const step of ["server", "app", "domain", "https", "backups"]) keys.push(`${step}.title`, `${step}.body`);
  for (const locale of locales) {
    const m = JSON.parse(read(`messages/${locale}.json`));
    for (const key of keys) assert.equal(typeof get(m.serverDashboard.start, key), "string", `${locale} start.${key}`);
    assert.match(m.serverDashboard.start.domain.body, /\{ip\}/, `${locale} lost {ip}`);
  }
});

test("the empty-state card has every string it uses", () => {
  // `empty.steps.*` is built with a template literal and is invisible to grep,
  // so the steps are named here.
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
