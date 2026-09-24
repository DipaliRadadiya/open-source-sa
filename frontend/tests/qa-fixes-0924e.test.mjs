import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import { addDomainFormSchema } from "../lib/schemas/domain.js";

const read = (p) => fs.readFileSync(p, "utf8");
const firstError = (domain) => {
  const r = addDomainFormSchema.safeParse({ domain, type: "alias" });
  return r.success ? null : r.error.issues[0].message;
};

test("a rate-limited certificate keeps Remove and Reissue, and closes only Let's Encrypt", () => {
  const card = read("components/applications/domains/ssl-section.jsx");
  assert.doesNotMatch(card, /canManage && !noRetry/);
  assert.match(card, /rateLimited=\{cert\?\.status === "failed" && NO_RETRY\.has\(cert\.reason\)\}/);
  const dialog = read("components/applications/domains/issue-cert-dialog.jsx");
  assert.match(dialog, /rateLimited && entry\.type === "letsencrypt"\s*\? \{ \.\.\.entry, available: false, reason: t\("ssl\.rateLimitedMethod"\) \}/);
  // A choice that became unavailable falls back instead of staying selected.
  assert.match(dialog, /entry\.type === chosen && entry\.available !== false/);
});

test("a dry run that passes with a failing name says which names are left off", () => {
  const dialog = read("components/applications/domains/issue-cert-dialog.jsx");
  assert.match(dialog, /const leftOff = dryRun\?\.status === "passed" \? \(dryRun\.domains \?\? \[\]\)\.filter\(\(entry\) => !entry\.ok\) : \[\];/);
  assert.match(dialog, /t\("ssl\.dryRunPartial", \{/);
  for (const l of ["en", "es", "hi", "de", "fr", "pt", "ja", "ru"]) {
    const ssl = JSON.parse(read(`messages/${l}.json`)).applications.domains.ssl;
    assert.ok(ssl.dryRunPartial.includes("{names}") && ssl.rateLimitedMethod, l);
  }
});

test("domain dialogs close only after the list has re-read", () => {
  const section = read("components/applications/domains/domains-section.jsx");
  assert.equal((section.match(/refreshThen\(\(\) => \{/g) ?? []).length, 3);
  assert.equal((section.match(/pending=\{pending \|\| refreshing\}/g) ?? []).length, 2);
  // The outgoing primary is frozen when the dialog opens.
  assert.match(section, /setPromoteTarget\(\{ \.\.\.domain, from: currentPrimary\?\.domain \}\)/);
  for (const f of ["add-domain-dialog", "edit-domain-dialog"]) {
    const src = read(`components/applications/domains/${f}.jsx`);
    assert.match(src, /refreshThen\(\(\) => \{/, f);
    assert.match(src, /form\.formState\.isSubmitting \|\| refreshing/, f);
    assert.doesNotMatch(src, /router\.refresh\(\)/, f);
  }
});

test("a pasted address is offered back as a domain", () => {
  const dialog = read("components/applications/domains/add-domain-dialog.jsx");
  assert.match(dialog, /isValidApplicationDomain\(typedDomain\) \? null : suggestApplicationDomain\(typedDomain\)/);
  assert.match(dialog, /tForm\("useDomain", \{ domain: suggestedDomain \}\)/);
});

test("a label over 63 characters is refused in the form with its own message", () => {
  assert.equal(firstError("a".repeat(64) + ".example.com"), "hostnameLabelTooLong");
  assert.equal(firstError("a".repeat(63) + ".example.com"), null);
  assert.equal(firstError("https://x.example.com/"), "hostnameInvalid");
  assert.equal(firstError("EXAMPLE.com"), null);
});

test("domain rows bleed to the card's own padding", () => {
  const section = read("components/applications/domains/domains-section.jsx");
  assert.match(section, /-mx-\(--card-spacing\) -mb-\(--card-spacing\) divide-y/);
  assert.doesNotMatch(section, /-mx-6 -mb-6/);
});
