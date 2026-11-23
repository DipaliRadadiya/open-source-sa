import assert from "node:assert/strict";
import fs from "node:fs";
import test from "node:test";

import { canOpenApplication, firstAllowedScreen } from "../lib/permissions/app-screens.js";
import { attentionFindings } from "../lib/dashboard/attention.js";

const read = (p) => fs.readFileSync(p, "utf8");
const grant = (name, url, view = true) => ({ level: "application", name, url, permissions: { view, manage: false } });

// 23 Nov (Krishna): a role with only server-level "Applications: view" opened a half-empty
// app dashboard with an empty menu. No link or page may lead a role to a refusal.
test("the first screen a role may open, in sidebar order", () => {
  assert.equal(firstAllowedScreen([grant("app_dashboard", "", false), grant("app_domain", "/domains"), grant("app_log", "/logs")]), "/domains");
  assert.equal(firstAllowedScreen([grant("app_dashboard", "")]), "");
  assert.equal(firstAllowedScreen([grant("app_domain", "/domains", false)]), null);
  assert.equal(firstAllowedScreen([]), null);
});

test("a role with no screen inside applications cannot open one; unknown grants can", () => {
  assert.equal(canOpenApplication([]), false);
  assert.equal(canOpenApplication([grant("app_domain", "/domains", false)]), false);
  assert.equal(canOpenApplication([grant("app_log", "/logs")]), true);
  assert.equal(canOpenApplication(null), true);
});

test("the app dashboard needs its own grant, else the first allowed screen", () => {
  const page = read("app/(app)/applications/[application]/page.jsx");
  assert.match(page, /if \(appPermissions && !can\(appPermissions, "app_dashboard", "view", "application"\)\)/);
  assert.match(page, /redirect\(`\/applications\/\$\{id\}\$\{first\}`\)/);
  assert.match(page, /getPermissions\("application", id\)\.catch\(\(\) => null\)/);
});

test("the list links into an application only when the role can open something there", () => {
  const list = read("app/(app)/applications/page.jsx");
  assert.match(list, /canOpen=\{canOpenApplication\(appPermissions\)\}/);
  assert.match(read("components/applications/applications-cards.jsx"), /\{canOpen \? \(/);
  assert.match(read("components/applications/application-row-actions.jsx"), /\{canOpen \? \(/);
});

test("dashboard findings link only to screens the role may open", () => {
  const insecure = { id: 7, name: "shop", status: "active", is_disabled: false, url: "http://shop.test" };
  assert.equal(attentionFindings([insecure], [grant("app_log", "/logs")])[0].href, null);
  assert.equal(attentionFindings([insecure], [grant("app_domain", "/domains")])[0].href, "/applications/7/domains");
  // Unknown grants keep the link; the page itself refuses if need be.
  assert.equal(attentionFindings([insecure])[0].href, "/applications/7/domains");
});

test("the logo goes to the role's first screen, and the security count covers hidden rows", () => {
  assert.match(read("components/sections/app-sidebar.jsx"), /const home = landingPath\(items\);/);
  const card = read("components/applications/protection-card.jsx");
  assert.match(card, /totals && totals\.total > items\.length/);
});

test("the new refusal text exists in every locale", () => {
  for (const l of ["en", "es", "de", "fr", "hi", "pt", "ja", "ru"]) {
    assert.ok(JSON.parse(read(`messages/${l}.json`)).applications.noScreens, l);
  }
});
