import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";

// Application pages QA on the fresh server, 2026-10-05 (reports/fresh/G-app-pages.md).
const root = path.join(import.meta.dirname, "..");
const read = (p) => fs.readFileSync(path.join(root, p), "utf8");
const LOCALES = ["en", "es", "hi", "de", "fr", "pt", "ja", "ru"];
const messages = Object.fromEntries(LOCALES.map((l) => [l, JSON.parse(read(`messages/${l}.json`))]));
const at = (m, key) => key.split(".").reduce((o, k) => o?.[k], m);

test("SSL names: a dropped name is listed once, as stale, and not counted", () => {
  const src = read("components/applications/domains/ssl-section.jsx");
  assert.match(src, /const stale = new Set\(cert\.stale_domains \?\? \[\]\)/);
  assert.match(src, /\.filter\(\(domain\) => !stale\.has\(domain\)\)/);
  assert.match(src, /total: names\.filter\(\(n\) => n\.state !== "stale"\)\.length/);
});

test("renewal warnings only for a certificate that renews", () => {
  assert.match(read("components/applications/domains/ssl-section.jsx"), /t\(cert\.renewable \? "ssl\.coverageGap" : "ssl\.coverageGapManual"\)/);
  assert.match(read("components/applications/domains/domains-section.jsx"), /deleteTarget && certificate\?\.renewable && coverageOf\(deleteTarget\.domain\) === "covered"/);
});

test("removing a domain returns focus to Add domain", () => {
  const src = read("components/applications/domains/domains-section.jsx");
  assert.match(src, /data-domains-add/);
  assert.match(src, /document\.querySelector\("\[data-domains-add\]"\)\?\.focus\(\)/);
  assert.equal(src.match(/removed\.current = true;/g)?.length, 2);
});

test("saves on Domains and Password Protection name what failed", () => {
  assert.match(read("components/applications/domains/add-domain-dialog.jsx"), /fallback: t\("toast\.addFailed"\)/);
  assert.match(read("components/applications/domains/edit-domain-dialog.jsx"), /fallback: t\("toast\.updateFailed"\)/);
  assert.match(read("components/applications/security/security-section.jsx"), /fallback: t\("saveFailed"\)/);
});

test("the selected domain type truncates instead of being cut", () => {
  for (const f of ["components/applications/domains/add-domain-dialog.jsx", "components/applications/domains/edit-domain-dialog.jsx"]) {
    assert.match(read(f), /<SelectValue>\s*<span className="min-w-0 truncate">\{t\(`type\.\$\{field\.value\}`\)\}<\/span>/, f);
  }
});

test("PHP budget shows a dash for a half-typed memory limit", () => {
  assert.match(read("components/applications/php/php-panel.jsx"), /limit: \/\^\\d\+\\s\*\[KMG\]\?\$\/i\.test\(String\(limit \?\? ""\)\.trim\(\)\) \? limit : "—"/);
});

test("environment history buttons can wrap on a phone", () => {
  const src = read("components/applications/environment/environment-history-card.jsx");
  assert.match(src, /<div className="flex min-w-0 flex-wrap gap-2">/);
  assert.doesNotMatch(src, /"flex shrink-0 flex-wrap gap-2"/);
});

test("the .env editor's Revert / Save row wraps on a phone", () => {
  assert.match(read("components/applications/environment/environment-editor.jsx"), /<div className="flex min-w-0 flex-wrap items-center gap-2">/);
});

test("new and reworded strings exist and are translated in every locale", () => {
  const keys = [
    "applications.domains.ssl.coverageGapManual",
    "applications.domains.toast.addFailed",
    "applications.domains.toast.updateFailed",
    "applications.security.saveFailed",
    "applications.siteTypeDetection.detectFailed",
  ];
  for (const l of LOCALES) for (const k of keys) {
    const v = at(messages[l], k);
    assert.equal(typeof v, "string", `${l} ${k}`);
    if (l !== "en") assert.notEqual(v, at(messages.en, k), `${l} ${k} is untranslated`);
  }
  // A detection that crashed does not know the directory was unreadable.
  assert.doesNotMatch(messages.en.applications.siteTypeDetection.detectFailed, /directory/);
  assert.notEqual(messages.pt.applications.clone.what.dropsItems.workers, "Queue workers");
  assert.notEqual(messages.ru.applications.clone.what.dropsItems.workers, "Queue workers");
  assert.notEqual(messages.pt.applications.firewall.pageTitle, "Web Firewall");
});
