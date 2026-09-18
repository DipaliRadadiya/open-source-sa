import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";

/*
 * Krishna, on the applications list: the logo already shows the framework, so
 * drop the Type column; show the PHP version (a dash where it does not apply);
 * show whether SSL is installed.
 *
 * Three decisions in here are worth pinning because a green build cannot see
 * any of them:
 *
 *  - The logo was `alt="" aria-hidden` ON PURPOSE while the Type column held
 *    the name. Removing that column without naming the logo would have left
 *    the type unreadable to a screen reader and unavailable on touch.
 *  - The column widths are percentages under `fixedLayout`, which squeezes a
 *    column silently when they do not total 100 rather than failing.
 *  - TLS is read from `url`, not from a certificate field, because the server
 *    only says `https` when the certificate is servable AND covers the domain.
 */

const read = (p) => fs.readFileSync(p, "utf8");
const strip = (s) => s.replace(/\/\*[\s\S]*?\*\//g, "").replace(/\{\/\*[\s\S]*?\*\/\}/g, "").replace(/^\s*\/\/.*$/gm, "");

const table = read("components/applications/applications-table.jsx");
const cards = read("components/applications/applications-cards.jsx");
const logo = read("components/applications/site-type-logo.jsx");
const mark = read("components/applications/tls-mark.jsx");
const tableCode = strip(table);
const cardsCode = strip(cards);
const { locales } = await import("../i18n/routing.js");

test("the Type column is gone and PHP stands in its place", () => {
  assert.doesNotMatch(tableCode, /function TypeCell/, "TypeCell should be gone, not orphaned");
  assert.doesNotMatch(tableCode, /columns\.type/);
  assert.doesNotMatch(tableCode, /SortHeader col="site_type"/);
  assert.match(tableCode, /\{ id: "php", header: t\("columns\.php"\)/);
  assert.match(tableCode, /function PhpCell/);
});

test("a site with no PHP shows a dash in the table", () => {
  // Node, static, anything the API nulls. The same em-dash OwnerCell uses, so
  // an empty cell looks the same everywhere in this table.
  assert.match(tableCode, /\{value \?\? "—"\}/);
});

test("...but the card omits it rather than dashing it", () => {
  /*
   * Deliberately different, and the card itself already set this rule for
   * unmeasured size: in a wrapped line of facts a dash reads as a value, where
   * in a table column it reads as an empty cell.
   */
  assert.match(cardsCode, /\{application\.php_version \? \(/);
  assert.doesNotMatch(cardsCode, /php_version \?\? "—"/);
});

test("the column widths still total 100 at both breakpoints", () => {
  // fixedLayout squeezes silently when they do not.
  const widths = [...tableCode.matchAll(/className: "([^"]*w-\[[^"]*)"/g)].map((m) => m[1]);
  const base = (cls) => {
    const hit = cls.match(/(?:^|\s)w-\[(\d+)%\]/);
    return hit ? Number(hit[1]) : 0;
  };
  const atXl = (cls) => {
    const hit = cls.match(/xl:w-\[(\d+)%\]/);
    // Falls back to the base width, which is how Actions (`w-[7%]`, no xl
    // variant) participates. Summing only `xl:` classes reads 93 and calls a
    // correct layout broken — which is exactly what the first version did.
    return hit ? Number(hit[1]) : base(cls);
  };

  // lg: the Created column is `hidden xl:table-cell`, so it is out of the flow.
  const lg = widths.filter((c) => !c.includes("hidden xl:table-cell")).reduce((t, c) => t + base(c), 0);
  assert.equal(lg, 100, `lg widths total ${lg}, not 100`);

  const xl = widths.reduce((t, c) => t + atXl(c), 0);
  assert.equal(xl, 100, `xl widths total ${xl}, not 100`);
});

test("the logo names the type in the table, and stays silent everywhere else", () => {
  // Opt-in: the cards, the detail header and the picker all print the type as
  // text next to the mark, so naming it there would say it twice.
  assert.match(logo, /label = null/);
  assert.match(logo, /role=\{label \? "img" : undefined\}/);
  assert.match(logo, /aria-label=\{label \?\? undefined\}/);
  assert.match(logo, /title=\{label \?\? undefined\}/);
  // The name is on the wrapper, not the <img>: two of the three branches are
  // not images (the provider mark and the Globe2 fallback).
  assert.match(logo, /<img src=\{logo\} alt="" aria-hidden/);

  assert.match(tableCode, /<SiteTypeLogo name=\{row\.original\.site_type\} provider=\{gitProvider\} label=\{row\.original\.site_type_title \?\? row\.original\.site_type\}/);
  // The card must NOT pass a label — it prints site_type_title below.
  const cardLogo = cardsCode.slice(cardsCode.indexOf("<SiteTypeLogo"), cardsCode.indexOf("</SiteTypeLogo>") + 1 || cardsCode.indexOf("/>", cardsCode.indexOf("<SiteTypeLogo")));
  assert.doesNotMatch(cardLogo, /label=/, "the card already names the type in text");
});

test("TLS is read from the scheme the server decided, not guessed", () => {
  /*
   * There is no certificate field on the list payload. `url` is https only
   * when the certificate is servable AND covers this domain, which is a
   * stronger claim than "a certificate row exists".
   */
  assert.match(mark, /String\(application\?\.url \?\? ""\)\.startsWith\("https:\/\/"\)/);
  // No answer yet is not the same as no certificate.
  assert.match(mark, /if \(!application\?\.url\) return null;/);
});

test("the padlock reuses the detail page's icons and its non-alarming tone", () => {
  // A green shield on the detail page and a different mark in the list would
  // be two answers to one question.
  assert.match(mark, /secure \? ShieldCheck : ShieldOff/);
  // `domains-card.jsx` uses secondary, NOT destructive, for "no certificate":
  // every site lacks one for its first few minutes.
  assert.match(mark, /secure \? "text-success" : "text-muted-foreground"/);
  assert.doesNotMatch(mark, /text-destructive/);
});

test("both surfaces label the padlock, reusing the existing strings", () => {
  for (const [name, code] of [["table", tableCode], ["cards", cardsCode]]) {
    assert.match(code, /isServedOverTls\([a-zA-Z.]*\) \? t\("domains\.secured"\) : t\("domains\.noCertificate"\)/, name);
  }
  // Reused, not re-authored: one wording for HTTPS across the panel, and no
  // new translation work for eight locales.
  for (const locale of locales) {
    const m = JSON.parse(read(`messages/${locale}.json`));
    assert.ok(m.applications?.domains?.secured, `${locale} missing domains.secured`);
    assert.ok(m.applications?.domains?.noCertificate, `${locale} missing domains.noCertificate`);
  }
});

test("PHP is a technical token, so it is untranslated in every locale", () => {
  // Same rule as ServerAvatar / SSH / sudo.
  for (const locale of locales) {
    const m = JSON.parse(read(`messages/${locale}.json`));
    assert.equal(m.applications?.columns?.php, "PHP", `${locale} columns.php`);
    assert.equal(m.applications?.phpFact, "PHP {version}", `${locale} phpFact`);
  }
});
