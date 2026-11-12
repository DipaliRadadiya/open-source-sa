import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import { hasImportAccount } from "../lib/databases/import-formats.js";

const read = (p) => fs.readFileSync(p, "utf8");

// QA 12 Nov: a database with no usable user let you pick a file and confirm "Replace
// database", then the start answered 422. Now the card says so before a file is chosen.
test("an import needs a user with a known password that does not connect only remotely", () => {
  assert.equal(hasImportAccount({ users: [] }), false);
  assert.equal(hasImportAccount({ users: [{ password_known: false, connection_preference: "localhost" }] }), false);
  assert.equal(hasImportAccount({ users: [{ password_known: true, connection_preference: "remote" }] }), false);
  assert.equal(hasImportAccount({ users: [{ password_known: true, connection_preference: "localhost" }] }), true);
  assert.equal(hasImportAccount({ users: [{ password_known: true, connection_preference: "anywhere" }] }), true);
  assert.equal(hasImportAccount({}), true, "an unknown list never blocks; the server still checks");
});

test("the card blocks the file picker and points to the Users tab", () => {
  const card = read("components/databases/database-import.jsx");
  assert.match(card, /const noAccount = !hasImportAccount\(database\);/);
  assert.match(card, /: noAccount\s+\? t\("noAccount"\)/);
  assert.match(card, /href=\{`\/databases\/\$\{database\.id\}\?tab=users`\}/);
  for (const locale of ["en", "es", "de", "fr", "hi", "pt", "ja", "ru"]) {
    const imp = JSON.parse(read(`messages/${locale}.json`)).databases.import;
    assert.ok(imp.noAccount && imp.openUsers, locale);
  }
});

test("the database tabs follow a ?tab= link to the same page", () => {
  const tabs = read("components/databases/database-tabs.jsx");
  assert.match(tabs, /if \(initial !== seenInitial\) \{\s*setSeenInitial\(initial\);[\s\S]*?if \(VALUES\.includes\(wanted\)\) setTab\(wanted\);/);
});
