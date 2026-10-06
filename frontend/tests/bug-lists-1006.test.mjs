import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";

// From the QA bug lists, 6 Oct (list A #29).
const root = path.join(import.meta.dirname, "..");
const read = (p) => fs.readFileSync(path.join(root, p), "utf8");

test("a covered name that does not point here is not called Secured", () => {
  const src = read("components/applications/domains/domains-section.jsx");
  assert.match(src, /coverage === "covered" && !domain\.dns_verified && !domain\.behind_proxy/);
  assert.match(src, /sslRowState\(certificate, coverageOf\(domain\.domain\), domain\)/);
  for (const l of ["en", "es", "hi", "de", "fr", "pt", "ja", "ru"]) {
    assert.ok(JSON.parse(read(`messages/${l}.json`)).applications.domains.sslRow.coveredNotPointing, l);
  }
});
