import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = path.resolve(import.meta.dirname, "..");
const read = (file) => fs.readFileSync(path.join(root, file), "utf8");

// RC-42 (19 Nov): the Overview loaded 100 rows and filtered them in the browser, so sites past
// the 100th never showed. It pages with the server now; these pin that it stays so.
test("the Overview asks the server for one page, searched and filtered from the URL", () => {
  const src = read("lib/backups/get-backups.js");
  const fn = src.slice(src.indexOf("export async function getBackupCoverage"), src.indexOf("export async function getBackupDestinationUsage"));
  assert.match(fn, /page: searchParams\.page/);
  assert.match(fn, /per_page: await perPage\(searchParams\.per_page\)/);
  assert.match(fn, /search: searchParams\.search \|\| undefined/);
  assert.match(fn, /\.\.\.coverageStateParams\(searchParams\.state\)/);
  assert.match(fn, /"filter\[type\]": oneOf\(searchParams\.type, BACKUP_TYPES\)/);
  assert.doesNotMatch(fn, /per_page: 100/);
  // "Not protected" is everything but protected; failing and paused also alone.
  assert.match(src, /if \(value === "unprotected"\) return \{ "filter\[protected\]": 0 \};/);
  assert.match(src, /return \{ "filter\[state\]": value \};/);
});

test("the card renders the server's rows and pager, with nothing filtered or sorted in the browser", () => {
  const card = read("components/backups/coverage-card.jsx");
  assert.doesNotMatch(card, /LocalSearchInput|sortCoverage|useMemo/);
  assert.match(card, /<SearchInput placeholder=\{t\("searchPlaceholder"\)\} \/>/);
  assert.match(card, /paramKey="state"/);
  assert.match(card, /paramKey="type"/);
  assert.match(card, /const footer = pager;/);
  const page = read("app/(app)/backups/page.jsx");
  assert.match(page, /getBackupCoverage\(sp\)/);
  assert.match(page, /redirectOutOfRange\("\/backups", sp, coverage\.meta, coverage\.failed\)/);
  assert.match(page, /const nothingConfigured = coverage\.configured === 0;/);
});

test("the pager counts what matched, the banner counts every site", () => {
  const src = read("lib/backups/get-backups.js");
  assert.match(src, /meta: \{ \.\.\.meta, total: meta\.matched \}/);
  assert.match(src, /total: meta\.total,/);
  const card = read("components/backups/coverage-card.jsx");
  assert.match(card, /const exposed = coverage\.total - coverage\.protected;/);
});
