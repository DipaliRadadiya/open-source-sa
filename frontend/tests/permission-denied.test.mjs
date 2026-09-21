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
  const guarded = pages.filter((p) => read(p).includes("<PermissionDenied"));
  assert.ok(guarded.length >= 30, `expected the whole set, found ${guarded.length}`);
  for (const p of guarded) {
    assert.match(
      read(p),
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
  assert.match(read("components/forms/login-form.jsx"), /router\.push\("\/"\)/);
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
