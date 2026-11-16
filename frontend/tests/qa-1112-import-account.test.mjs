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

// QA 12 Nov: on a phone the SSL tab broke "…nip.io" as "…nip.i" / "o" (break-all).
test("a domain wraps before a dot, and a long part still breaks rather than overflowing", () => {
  const src = read("components/ui/domain-text.jsx");
  assert.match(src, /export function WrappedDomain/);
  assert.match(src, /\{index \? <wbr \/> : null\}\s*\{index \? "\." : null\}\s*\{part\}/);
  assert.match(src, /\[overflow-wrap:anywhere\]/);
  for (const f of ["ssl-section", "domains-section", "edit-domain-dialog"]) {
    assert.match(read(`components/applications/domains/${f}.jsx`), /<WrappedDomain domain=/, f);
  }
});

// QA 16 Nov: the Trash header printed the API's raw "16-11-2026 09:16:41".
test("Trash shows when a batch was deleted in the reader's format, like the restore dialog", async () => {
  const { parseApiWallClock } = await import("../lib/format/api-date.js");
  const panel = read("components/applications/files/trash-panel.jsx");
  assert.match(panel, /\{deletedLabel\(entries\[0\]\.deleted_at, batch\)\}/);
  assert.match(panel, /format\.dateTime\(when, \{ dateStyle: "medium", timeStyle: "short", timeZone: "UTC" \}\)/);
  const when = parseApiWallClock("16-11-2026 09:16:41");
  assert.equal(new Intl.DateTimeFormat("en", { dateStyle: "medium", timeStyle: "short", timeZone: "UTC" }).format(when), "Nov 16, 2026, 9:16 AM");
});

// QA 16 Nov: Admin → Recent activity read "From 47 minutes ago to 47 minutes ago".
test("a collapsed activity run whose ends read the same says the time once", () => {
  const feed = read("components/admin/dashboard/activity-feed.jsx");
  assert.match(feed, /oldest\.id !== newest\.id && oldest\.created_at_human !== newest\.created_at_human\s*\?\s*t\("between"/);
});
