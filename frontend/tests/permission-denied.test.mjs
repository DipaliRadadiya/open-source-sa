import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import { landingPath } from "../lib/permissions/landing-path.js";

/*
 * Found by logging in as a real restricted role (application.view +
 * database.view, nothing else) on Krishna's panel.
 *
 * Asking for /php bounced to /dashboard and said "You don't have access to
 * THE DASHBOARD". The reader asked about PHP. Every one of 13 blocked pages
 * gave the same wrong answer, because 40 guards all redirected to a page this
 * role cannot open either — one wall explaining a different wall.
 *
 * And the first screen after signing in was that same refusal, with two
 * perfectly usable pages sitting in the sidebar beside it.
 */

const read = (p) => fs.readFileSync(p, "utf8");
// Comments mention the components they explain. Asserting against prose finds
// a page that only TALKS about <PermissionDenied /> — the dashboard's does,
// saying why it cannot use one.
const strip = (s) => s.replace(/\/\*[\s\S]*?\*\//g, "").replace(/^\s*\/\/.*$/gm, "");
const LOCALES = read("i18n/routing.js")
  .match(/export const locales = \[([^\]]+)\]/)[1]
  .split(",")
  .map((c) => c.trim().replace(/['"]/g, ""))
  .filter(Boolean);

const pages = [];
const walk = (dir) => {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const p = path.join(dir, entry.name);
    if (entry.isDirectory()) walk(p);
    else if (/^(page|layout)\.jsx?$/.test(entry.name)) pages.push(p);
  }
};
walk("app");

test("no permission guard sends anyone to the dashboard any more", () => {
  const offenders = pages.filter((p) => read(p).includes('redirect("/dashboard")'));
  assert.deepEqual(offenders, [], `\n${offenders.join("\n")}`);
});

test("a refused page names itself, not whatever it would have bounced to", () => {
  /*
   * The title passed in is the page's OWN heading, so the refusal can never
   * drift from what the sidebar calls the screen.
   */
  const guarded = pages.filter((p) => strip(read(p)).includes("<PermissionDenied"));
  assert.ok(guarded.length >= 30, `expected the whole set, found ${guarded.length}`);
  for (const p of guarded) {
    assert.match(
      strip(read(p)),
      /<PermissionDenied title=\{t\("(title|pageTitle)"\)\} \/>/,
      `${p} must name itself`,
    );
  }
});

test("the two shells that cannot refuse in place go home, not to the dashboard", () => {
  // A layout IS the shell, so there is nothing left to render a refusal
  // inside. They redirect — but to "/", which picks a page the caller can open.
  for (const p of ["app/admin/layout.jsx", "app/(setup)/layout.jsx"]) {
    assert.match(read(p), /redirect\("\/"\)/, p);
  }
});

test("the front door picks a page the caller can actually open", () => {
  const catalog = (entries) =>
    entries.map(([name, url, view]) => ({
      level: "server", name, url, permissions: { view, manage: false },
    }));

  // The real shape from /api/permissions, in the order the backend sends it.
  const restricted = catalog([
    ["dashboard", "/dashboard", false],
    ["application", "/applications", true],
    ["database", "/databases", true],
    ["php", "/php", false],
  ]);
  assert.equal(landingPath(restricted), "/applications");

  const admin = catalog([["dashboard", "/dashboard", true], ["application", "/applications", true]]);
  assert.equal(landingPath(admin), "/dashboard", "an administrator still lands where they always did");

  // `manage` implies `view` — a role granted only manage still gets the page.
  assert.equal(
    landingPath([{ level: "server", name: "php", url: "/php", permissions: { view: false, manage: true } }]),
    "/php",
  );

  // An application-level entry's url is a fragment with no id: "/domains"
  // alone is not a page. Skipped, or the front door would 404.
  assert.equal(
    landingPath([
      { level: "application", name: "app_domain", url: "/domains", permissions: { view: true } },
      { level: "server", name: "database", url: "/databases", permissions: { view: true } },
    ]),
    "/databases",
  );

  // Entries that are permissions without a screen.
  assert.equal(
    landingPath([
      { level: "server", name: "activity_log", url: "", permissions: { view: true } },
      { level: "server", name: "php", url: "/php", permissions: { view: true } },
    ]),
    "/php",
  );

  // A role with nothing at all still gets a page that explains itself rather
  // than a crash or a bounce to /login reading as "wrong password".
  assert.equal(landingPath([]), "/dashboard");
  assert.equal(landingPath(null), "/dashboard");
});

test("login hands the choice to the server, which is the only side that knows", () => {
  // "/" is still the default; `?next=` only overrides it when something sets
  // one, and `safeNext` drops anything that is not an in-panel path.
  assert.match(
    read("components/forms/login-form.jsx"),
    /\?\? takeRememberedPath\(\) \?\? "\/"\)/,
    'the last fallback stays "/" — app/page.js picks a landing page this role can open',
  );
  assert.doesNotMatch(read("components/forms/login-form.jsx"), /router\.push\("\/dashboard"\)/);
  assert.match(read("app/page.js"), /redirect\(landingPath\(await getPermissions\(\)\)\)/);
  for (const p of ["app/(auth)/login/page.jsx", "app/(auth)/register/page.jsx"]) {
    assert.match(read(p), /if \(user\) redirect\("\/"\);/, p);
  }
});

test("the refusal reads in every locale", () => {
  for (const locale of LOCALES) {
    const ns = JSON.parse(read(`messages/${locale}.json`)).common?.permissionDenied;
    assert.ok(ns, `${locale} common.permissionDenied`);
    assert.match(ns.title, /\{feature\}/, `${locale} must name the screen`);
    assert.ok(ns.description?.trim(), `${locale} description`);
  }
});

test("the dashboard refuses in the same words as everywhere else", () => {
  /*
   * It cannot use <PermissionDenied /> itself — it is the fallback landing
   * route, so it renders its own header first and a second one would stack.
   * It uses the shared STRINGS instead, which is what a reader notices: it
   * said "the dashboard" in lower case beside twelve screens saying "PHP",
   * "Firewall", "System Users".
   */
  const src = read("app/(app)/dashboard/page.jsx");
  assert.match(src, /getTranslations\("common\.permissionDenied"\)/);
  assert.match(src, /tDenied\("title", \{ feature: t\("title"\) \}\)/);
  assert.doesNotMatch(strip(src), /noPermission\./, "the bespoke pair is gone");

  for (const locale of LOCALES) {
    const dash = JSON.parse(read(`messages/${locale}.json`)).serverDashboard;
    assert.equal(dash.noPermission, undefined, `${locale} still carries serverDashboard.noPermission`);
  }
});

test("a screen is spelled one way", () => {
  // English disagreed with itself one key apart: the Activity Log page titled
  // itself "Activity log" while its own table said "Activity Log".
  const en = JSON.parse(read("messages/en.json")).activity;
  assert.equal(en.title, "Activity Log");
  assert.equal(en.title, en.mine.title);
});

test("the clear button names what it actually clears", () => {
  /*
   * Filtering Applications by status = Failed and getting no rows offered
   * "Clear search" — a control the reader never touched. It clears all three
   * (search, status, type), and Applications is the only list with more than a
   * search box, so it is the only one where "Clear search" is wrong.
   */
  const table = read("components/applications/applications-table.jsx");
  assert.match(table, /setQuery\(\{ search: undefined, status: undefined, site_type: undefined \}/);
  assert.match(table, /tCommon\("clearFilters"\)/);
  assert.doesNotMatch(strip(table), /t\("empty\.clearSearch"\)/);

  for (const locale of LOCALES) {
    const m = JSON.parse(read(`messages/${locale}.json`));
    assert.ok(m.common.clearFilters?.trim(), `${locale} common.clearFilters`);
    assert.equal(m.applications.empty.clearSearch, undefined, `${locale} still carries the old key`);
  }

  // The single-filter lists keep "Clear search", because there it is true.
  assert.match(read("components/databases/databases-table.jsx"), /t\("empty\.clearSearch"\)/);
});

test("deleting an application admits the system user survives", () => {
  /*
   * Create generates a Linux account nobody asked for; delete removes the
   * application, its files and its databases and leaves the account behind.
   * `DestroyApplicationRequest` accepts `remove_files` and `remove_databases`
   * and nothing else, so there is no checkbox to offer — but the dialog listed
   * everything it DID remove and never mentioned the account, which is how
   * this server ended up with `qa-throwaway` and `prestashop` owning nothing.
   */
  const dlg = read("components/applications/delete-application-dialog.jsx");
  assert.match(dlg, /application\?\.system_user\?\.username \?/, "only when there is one to name");
  assert.match(dlg, /t\("systemUserStays", \{ username: application\.system_user\.username \}\)/);

  for (const locale of LOCALES) {
    const s = JSON.parse(read(`messages/${locale}.json`)).applications.delete.systemUserStays;
    assert.ok(s?.trim(), `${locale} delete.systemUserStays`);
    assert.match(s, /\{username\}/, `${locale} must name the account`);
  }
});

test("an application sub-page refuses in place too, not just the server ones", () => {
  /*
   * Yesterday's pass converted the SERVER-level guard on every screen and
   * missed the application-level one sitting four lines below it:
   *
   *     if (!can(permissions, "application", "view"))            <- converted
   *     if (!can(appPermissions, "app_backup", "view", ...))     <- missed
   *          redirect(`/applications/${id}`)
   *
   * The second is the one that fires for a real restricted role: it HAS
   * `application.view`, so the first check passes and the second threw it back
   * to the overview with no explanation. Fourteen screens did that.
   *
   * Missed because the converter searched for `redirect("/dashboard")` and
   * these say `redirect(\`/applications/${id}\`)` — different string, same
   * fault. Found by logging in as the role and typing each URL.
   */
  const dir = "app/(app)/applications/[application]";
  const subs = fs.readdirSync(dir, { withFileTypes: true })
    .filter((e) => e.isDirectory())
    .map((e) => `${dir}/${e.name}/page.jsx`)
    .filter((p) => fs.existsSync(p) && read(p).includes("appPermissions"));

  assert.ok(subs.length >= 14, `expected every sub-page, found ${subs.length}`);
  for (const p of subs) {
    const src = strip(read(p));
    assert.doesNotMatch(
      src,
      /if \(!can\(appPermissions[\s\S]{0,120}?redirect\(/,
      `${p} still bounces instead of refusing`,
    );
    assert.match(
      src,
      // Environment first asks whether the screen exists for this site type.
      /if \(!can\(appPermissions, "\w+", "view", "application"\)\) \{\s*(?:if \(\(await getApplicationEnvironment\(id\)\)\.status === 404\) notFound\(\);\s*)?return <PermissionDenied title=\{t\("(pageTitle|title)"\)\} \/>;/,
      `${p} must refuse in place, named`,
    );
  }
});

test("the files path guard is still a redirect, because it is not a permission", () => {
  /*
   * `isSafePath` rejects a traversal in the ?path= query. Rendering a refusal
   * there would leave the bad path in the address bar; bouncing to the
   * application's own file root is the fix, not a message.
   */
  const src = read("app/(app)/applications/[application]/files/page.jsx");
  assert.match(src, /if \(!isSafePath\(path\)\) redirect\(`\/applications\/\$\{id\}\/files`\);/);
});
