import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";

/*
 * Found on the live panel: the firewall screen was "Web Firewall" in the
 * sidebar and in its own <h1>, and "8G Firewall" in the breadcrumb directly
 * above it.
 *
 * "8G" is the name of the upstream ruleset, not a description of the feature,
 * which is why `navTitle` exists to rename it. The rename was applied in the
 * sidebar and skipped in the breadcrumb, so one screen had two names on it at
 * the same time. Nothing was missing — the override, the key and all eight
 * translations were already there; the trail just read the raw catalog title.
 */

// Imported, never re-parsed: a hand-rolled regex over routing.js silently
// stops matching the day the file is reformatted, and a locale list that
// quietly becomes empty makes this test pass by checking nothing.
const { locales } = await import("../i18n/routing.js");

const read = (p) => fs.readFileSync(p, "utf8");
const crumb = read("components/sections/app-breadcrumb.jsx");
const sidebar = read("components/sections/app-sidebar.jsx");
const navigation = read("lib/navigation.js");
const strip = (s) => s.replace(/\/\*[\s\S]*?\*\//g, "").replace(/^\s*\/\/.*$/gm, "");

test("the breadcrumb renames the screen the same way the sidebar does", () => {
  const code = strip(crumb);
  assert.match(code, /import \{ findActiveNavItem, navTitle, resolveNavItems \}/);
  assert.match(code, /const title = current \? navTitle\(current, t\) : undefined;/);
  // The raw catalog title is what put two names on one screen.
  assert.doesNotMatch(code, /const title = current\?\.title/);
});

test("both consumers of the catalog go through the one override", () => {
  for (const [name, source] of [["breadcrumb", crumb], ["sidebar", sidebar]]) {
    assert.match(strip(source), /navTitle\(/, `${name} should use navTitle`);
  }
});

test("the override is keyed on the API name, not on a label or a path", () => {
  // The API keeps calling it `app_firewall`; only what we print changes. Match
  // on the title instead and the rename breaks the moment the catalog is
  // translated, which is exactly when it is needed most.
  assert.match(navigation, /if \(item\.name === "app_firewall"\) return t\("navTitles\.app_firewall"\);/);
});

test("every locale can perform the rename", () => {
  // A locale missing the key would fall back to English mid-trail, which reads
  // as a bug rather than as a fallback.
  assert.ok(locales.length >= 8, "the locale list should not be empty");
  for (const locale of locales) {
    const messages = JSON.parse(read(`messages/${locale}.json`));
    const value = messages.common?.navTitles?.app_firewall;
    assert.ok(value, `${locale} is missing common.navTitles.app_firewall`);
    assert.doesNotMatch(value, /\b8G\b/, `${locale} still shows the ruleset name`);
  }
});
