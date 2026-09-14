import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = path.join(import.meta.dirname, "..");

const dialog = fs.readFileSync(
  path.join(root, "components/applications/domains/add-domain-dialog.jsx"),
  "utf8",
);
const section = fs.readFileSync(
  path.join(root, "components/applications/domains/domains-section.jsx"),
  "utf8",
);
const page = fs.readFileSync(
  path.join(root, "app/(app)/applications/[application]/domains/page.jsx"),
  "utf8",
);
const en = JSON.parse(fs.readFileSync(path.join(root, "messages/en.json"), "utf8"));

// Read from routing.js, never hardcoded. A hardcoded list silently stops
// checking the locales added after it was written — which is not hypothetical:
// this file was authored when there were five, and three more landed the same
// afternoon. The list that decides what the app ships is the only honest one
// to assert against.
const { locales: LOCALES } = await import("../i18n/routing.js");

test("the certificate reaches the Add domain dialog from the page", () => {
  // Three hops, and a break in any one of them silently removes the warning
  // rather than erroring — the dialog would just render nothing.
  assert.match(page, /certificate=\{cert\}/);
  assert.match(section, /certificate = null,/);
  assert.match(section, /<AddDomainDialog[\s\S]*?certificate=\{certificate\}/);
});

test("the notice is shown only for a certificate that is actually serving", () => {
  // A pending or failed certificate secures nothing, so warning that it will
  // not cover the new name is noise on top of a problem the SSL card already
  // reports.
  assert.match(dialog, /certificate\?\.status === "active"/);
});

test("an uploaded certificate gets different advice from a reissuable one", () => {
  // The panel cannot reissue an uploaded certificate. Telling its owner to
  // "reissue" sends them looking for a button that is not there.
  assert.match(dialog, /uploaded = active\?\.type === "custom"/);
  assert.match(dialog, /uploaded \? "add\.certUploadedNotice" : "add\.certNotice"/);
  assert.match(dialog, /uploaded \? "toast\.addedNeedsUpload" : "toast\.addedNeedsReissue"/);
});

test("the force-https line appears only when the redirect is actually on", () => {
  // Without the redirect the new name still answers on plain HTTP, so the
  // visitor sees the site and no warning at all. With it on there is no way
  // past the certificate error, which is a different severity.
  assert.match(dialog, /active\.force_https \? <p>\{t\("add\.certNoticeForceHttps"\)\}<\/p> : null/);
});

test("the DNS note stops promising HTTPS on a site that already has a certificate", () => {
  // add.dnsNote ends "so HTTPS can be issued", which is true only when there
  // is no certificate yet. On a secured site that reads as a promise the new
  // name will just work.
  assert.match(dialog, /t\(active \? "add\.dnsNoteSecured" : "add\.dnsNote"\)/);
  assert.match(en.applications.domains.add.dnsNote, /HTTPS can be issued/);
  assert.doesNotMatch(en.applications.domains.add.dnsNoteSecured, /HTTPS/);
});

test("every new string exists in every locale, with its placeholder intact", () => {
  const withPlaceholder = {
    "add.certNotice": "{current}",
    "toast.addedNeedsReissue": "{domain}",
    "toast.addedNeedsUpload": "{domain}",
  };
  const plain = ["add.dnsNoteSecured", "add.certUploadedNotice", "add.certNoticeForceHttps"];

  for (const locale of LOCALES) {
    const messages = JSON.parse(
      fs.readFileSync(path.join(root, `messages/${locale}.json`), "utf8"),
    );
    const domains = messages.applications.domains;

    for (const key of [...plain, ...Object.keys(withPlaceholder)]) {
      const [group, name] = key.split(".");
      const value = domains[group]?.[name];
      assert.equal(typeof value, "string", `${locale}: ${key} must exist`);
      assert.ok(value.length > 0, `${locale}: ${key} must not be empty`);
    }

    // A dropped placeholder is the failure that survives review: the sentence
    // still reads correctly, it just never names the domain it is about.
    for (const [key, token] of Object.entries(withPlaceholder)) {
      const [group, name] = key.split(".");
      assert.ok(
        domains[group][name].includes(token),
        `${locale}: ${key} must keep ${token}`,
      );
    }
  }
});
