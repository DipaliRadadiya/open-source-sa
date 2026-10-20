import { afterEach, before, test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { installDom, loadComponent } from "./support/dom.mjs";

installDom();
const { createElement } = await import("react");
const { cleanup, render } = await import("@testing-library/react");
let SiteTypeLogo;
before(async () => ({ SiteTypeLogo } = await loadComponent("components/applications/site-type-logo.jsx")));
afterEach(cleanup);
const URI = "data:image/svg+xml;base64,PHN2Zy8+";

test("recipe data URI takes precedence over the built-in WordPress map in the same decorative slot", () => {
  const { container } = render(createElement(SiteTypeLogo, { name: "wordpress_container", iconDataUri: URI }));
  const image = container.querySelector("img");
  assert.equal(image.getAttribute("src"), URI);
  assert.equal(image.getAttribute("alt"), "");
  assert.equal(image.getAttribute("aria-hidden"), "true");
  assert.ok(container.firstChild.classList.contains("h-7"));
  assert.ok(container.firstChild.classList.contains("w-12"));
});

for (const iconDataUri of [undefined, null, ""]) {
  test(`absent recipe URI (${String(iconDataUri)}) retains the native static logo`, () => {
    const { container } = render(createElement(SiteTypeLogo, { name: "wordpress", iconDataUri }));
    assert.equal(container.querySelector("img").getAttribute("src"), "/site-types/wordpress.svg");
  });
}

test("unknown types retain the generic glyph when no catalog logo exists", () => {
  const { container } = render(createElement(SiteTypeLogo, { name: "unknown" }));
  assert.equal(container.querySelector("img"), null);
  assert.ok(container.querySelector("svg"));
});

test("a labelled recipe names only the wrapper, not its decorative image", () => {
  const { container } = render(createElement(SiteTypeLogo, { name: "ghost", iconDataUri: URI, label: "Ghost" }));
  assert.equal(container.firstChild.getAttribute("role"), "img");
  assert.equal(container.firstChild.getAttribute("aria-label"), "Ghost");
  assert.equal(container.querySelector("img").getAttribute("alt"), "");
});

test("git providers remain unchanged when no recipe URI is supplied", () => {
  const { container } = render(createElement(SiteTypeLogo, { name: "git", provider: "github" }));
  assert.ok(container.querySelector("svg"));
  assert.equal(container.querySelector("img"), null);
});

test("recipe URI wins even if a provider is supplied", () => {
  const { container } = render(createElement(SiteTypeLogo, { name: "ghost", provider: "github", iconDataUri: URI }));
  assert.equal(container.querySelector("img").getAttribute("src"), URI);
});

test("list, mobile cards and header reuse only their existing catalog for logos", () => {
  const read = (file) => readFileSync(file, "utf8");
  const table = read("components/applications/applications-table.jsx");
  const cards = read("components/applications/applications-cards.jsx");
  const header = read("app/(app)/applications/[application]/page.jsx");
  assert.match(table, /iconDataUri=\{applicationIconDataUri\(row\.original, siteTypes\)\}/);
  assert.match(table, /<ApplicationsCards[^\n]*siteTypes=\{siteTypes\}/);
  assert.match(cards, /iconDataUri=\{applicationIconDataUri\(application, siteTypes\)\}/);
  assert.match(header, /iconDataUri=\{applicationIconDataUri\(application, siteTypes\.siteTypes\)\}/);
  const facts = read("components/applications/site-facts-card.jsx");
  const relabel = read("components/applications/site-type-relabel-dialog.jsx");
  assert.match(facts, /targetTitle=\{relabelTitle\}\s*siteTypes=\{siteTypes\}/);
  assert.match(relabel, /<SiteTypeLogo name=\{name\} iconDataUri=\{iconDataUri\}/);
  assert.match(relabel, /iconDataUri=\{siteTypes\.find\(\(type\) => type\.name === application\.site_type\)\?\.recipe\?\.icon_data_uri\}/);
  assert.match(relabel, /iconDataUri=\{siteTypes\.find\(\(type\) => type\.name === target\)\?\.recipe\?\.icon_data_uri\}/);
  assert.equal((header.match(/getSiteTypes\(\)/g) ?? []).length, 1);
  assert.match(header, /settled && \(canSeeDatabases \|\| canManage\)\s*\? getSiteTypes\(\)/);
});

test("all eight locales preserve 1-Click and use the same tab names in their hints", () => {
  for (const locale of ["en", "es", "de", "fr", "pt", "ja", "ru", "hi"]) {
    const t = JSON.parse(readFileSync(`messages/${locale}.json`, "utf8")).applications.recipes;
    assert.match(t.tabOneClick, /^1-Click/);
    assert.notEqual(t.tabDocker, "Docker");
    assert.ok(t.tabOneClickShort && t.tabDockerShort);
    assert.ok(t.searchDockerInstead.includes(t.tabDocker));
    assert.ok(t.searchDockerInstead.includes("{query}"));
    assert.ok(t.recipeAvailable.includes("{title}"));
    assert.ok(t.useRecipe.includes("{title}"));
  }
});
