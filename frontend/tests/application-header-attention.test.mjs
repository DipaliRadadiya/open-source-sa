import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";

/*
 * Reported on the application detail page:
 *   "yellow full width section looks too bad and takes too much empty unused
 *    space. also top section of app name, domain status etc looks very simple
 *    not even looks like application panel main content"
 *
 * Both were layout, not colour:
 *
 * - Each finding was a full-width band with three words at one edge and its
 *   link at the other — ~900px of nothing between them, twice over.
 * - The header was four text nodes on white above the cards, so nothing said
 *   it was the subject of the page.
 *
 * Shape comes from memory/research-application-dashboard.md: Forge, Plesk and
 * MaxPlane all open a site page with an identity card — mark, name, state,
 * hostname, quick actions, one surface.
 */

const read = (p) => fs.readFileSync(p, "utf8");
const tiles = read("components/applications/app-status-tiles.jsx");
const page = read("app/(app)/applications/[application]/page.jsx");

/*
 * Redesign (Krishna, 6 Oct 2026): status tiles, each fact said once. The health
 * list repeated what the Domains, Backups and Deployment cards already said.
 */

test("each tile is claimed only from a read that succeeded", () => {
  assert.match(page, /canSeeDomains && !certificate\.failed\s*\?/);
  assert.match(page, /canSeeBackups && !backup\.failed\s*\?/);
  assert.match(tiles, /if \(https\)/);
  assert.match(tiles, /if \(backup\)/);
});

test("problems the tiles already show are not repeated as lines", () => {
  assert.match(page, /const tileKeys = new Set\(\["ssl", "backups"\]\);/);
  assert.match(page, /!\/\^issue-\(certificate\|deploy_failed\)-\/\.test\(item\.key\)/);
  // No coloured top bars: the rejected tile look.
  assert.doesNotMatch(tiles, /absolute inset-x-0 top-0/);
});

test("the header is an identity card carrying the site's own mark", () => {
  assert.match(page, /import \{ SiteTypeLogo \}/);
  assert.match(page, /<SiteTypeLogo\s+name=\{application\.site_type\}[\s\S]{0,120}size="h-6 w-6"\s*\/>/);
  // Compact (Krishna, 6 and 7 Oct: the name read as a banner): an 18px name.
  assert.match(page, /<h1 className="min-w-0 text-lg leading-tight font-semibold tracking-tight break-words">/);
  // The actions sit beside the name block, never wrapped under it (7 Oct).
  assert.match(page, /@3xl\/masthead:flex @3xl\/masthead:items-center @3xl\/masthead:justify-between/);
  // Narrow: ⋯ beside the name, not alone on a line under the buttons (8 Oct).
  assert.match(page, /grid grid-cols-\[minmax\(0,1fr\)_auto\]/);
  assert.match(page, /"col-start-2 row-start-1 self-start[^"]*">\s*<ApplicationRowActions/);
  assert.match(page, /<MagicLoginLauncher appId=\{id\} variant="default" size="default" \/>/);
});

test("the application's details lead the page, inside the header card", () => {
  // Krishna, 7 Oct: "Application detail is main thing on that page but that is far down".
  const header = page.slice(page.indexOf("@container/masthead"), page.indexOf("<AppStatusTiles"));
  assert.match(header, /<SiteFactsCard[\s\S]*?\bstrip\b/);
  assert.ok(page.indexOf("<SiteFactsCard") < page.indexOf("<AppStatusTiles"));
});

test("the page has no quick actions: the sidebar already lists every screen", () => {
  assert.doesNotMatch(page, /QuickActions/);
  // Status first; then the deploy card, then the packed cards.
  assert.ok(page.indexOf("<AppStatusTiles") < page.indexOf("<SourceCard"));
  assert.ok(page.indexOf("<SourceCard") < page.indexOf("<DomainsCard"));
});

test("a git site's header shows its Git host, as the list does", () => {
  // Krishna 2026-09-29: the list showed GitHub and the dashboard the generic
  // git mark. The accounts are already loaded here for the source card.
  assert.match(page, /provider=\{gitProviderFor\(application, providersByAccountId\(gitAccounts\)\)\}/);
});

test("on a phone the domain wraps at its dots and the icons follow its last letter", () => {
  // Krishna, 8 Oct: break-all split "116" and the copy button floated beside the first line.
  assert.doesNotMatch(page, /<span className="break-all">\{application\.domain\}<\/span>/);
  assert.match(page, /function BreakableDomain\(/);
  assert.match(page, /\{label\}\.<wbr \/>/);
  assert.match(page, /\{"\\u2060"\}\s*<CopyButton value=\{application\.domain\} className="-my-1 ml-0\.5 inline-flex align-middle" \/>/);
});

test("Server Sync: the row buttons sit on the first text line", () => {
  const sync = fs.readFileSync(new URL("../components/sync/sync-results.jsx", import.meta.url), "utf8");
  assert.equal((sync.match(/className="-my-1 size-7"/g) ?? []).length, 2);
});

test("Databases list: states in grey text, not yellow badges (Krishna, 8 Oct)", () => {
  const table = fs.readFileSync(new URL("../components/databases/databases-table.jsx", import.meta.url), "utf8");
  const cards = fs.readFileSync(new URL("../components/databases/databases-cards.jsx", import.meta.url), "utf8");
  assert.doesNotMatch(table, /variant="warning"/);
  assert.doesNotMatch(cards, /variant="warning"/);
  assert.match(table, /underline decoration-dotted underline-offset-4/);
});

test("Databases list: a long name truncates instead of running into the next columns", () => {
  const table = fs.readFileSync(new URL("../components/databases/databases-table.jsx", import.meta.url), "utf8");
  assert.match(table, /<span className="max-w-54 truncate">\{row\.original\.name\}<\/span>/);
});

test("Databases list fits the widest locale: no Created column, Application wraps", () => {
  const table = fs.readFileSync(new URL("../components/databases/databases-table.jsx", import.meta.url), "utf8");
  // Russian at 1440 squeezed Application to 32px, and Created (2xl) pushed it 84px over at 1920.
  assert.doesNotMatch(table, /id: "created"/);
  assert.match(table, /meta: \{ className: "min-w-24 whitespace-normal" \}/);
});

test("Database health: Running queries matches the chart's height and scrolls inside", () => {
  const page = fs.readFileSync(new URL("../app/(app)/databases/monitor/page.jsx", import.meta.url), "utf8");
  const list = fs.readFileSync(new URL("../components/databases/process-list.jsx", import.meta.url), "utf8");
  assert.match(page, /<div className="xl:relative xl:order-2">\s*<div className="xl:absolute xl:inset-0">\s*<ProcessList/);
  assert.match(list, /fill && "-mx-5 max-h-\[32rem\] min-h-0 flex-1 overflow-y-auto px-5 xl:max-h-none"/);
});

test("Database page follows Krishna's reference: Overview tab, phpMyAdmin in the header (8 Oct)", () => {
  const page = fs.readFileSync(new URL("../app/(app)/databases/[database]/page.jsx", import.meta.url), "utf8");
  const tabs = fs.readFileSync(new URL("../components/databases/database-tabs.jsx", import.meta.url), "utf8");
  assert.match(tabs, /const VALUES = \["overview", "users", "tables", "exports"\]/);
  assert.match(tabs, /VALUES\.includes\(wanted\) \? wanted : "overview"/);
  assert.match(page, /actions=\{\s*<PhpmyadminButton[\s\S]{0,200}variant="default"/);
  assert.match(page, /overview=\{\s*<div className="space-y-4">\s*<ConnectionDetails[\s\S]*<DatabaseDetailsCard[\s\S]*<DeleteDatabaseCard/);
  assert.match(read("components/databases/phpmyadmin-button.jsx"), /^"use client";/);
});

test("Database Details: all six facts on one row when the card is wide (Krishna, 8 Oct)", () => {
  const card = read("components/databases/database-details-card.jsx");
  assert.match(card, /@container\/details/);
  assert.match(card, /@4xl\/details:grid-cols-6/);
  assert.match(card, /flex flex-wrap items-center justify-between gap-x-2/);
});

test("Database Details: Used by is one truncated line with the full name in a tooltip", () => {
  const card = read("components/databases/database-details-card.jsx");
  assert.match(card, /className="block truncate underline-offset-4 hover:underline"/);
  assert.match(card, /<TooltipContent side="bottom">\{application\.name\}<\/TooltipContent>/);
});

test("Database Tables: columns share the width from sm, so Rows and Size are not pushed together", () => {
  const src = read("components/databases/database-tables.jsx");
  assert.match(src, /t\("columns\.table"\), "sm:w-\[40%\]"\)/);
  assert.match(src, /"text-right sm:w-\[20%\]"/);
  assert.match(src, /header\("size", t\("columns\.size"\), "text-right sm:w-\[40%\]"\)/);
});

test("Dashboard: the banner speaks for the server; the attention count heads its own rows (8 Oct)", () => {
  const hero = read("components/dashboard/dashboard-hero.jsx");
  const panel = read("components/dashboard/attention-panel.jsx");
  assert.doesNotMatch(hero, /t\("hero\.attention"/);
  assert.match(hero, /problems\s*\?\s*t\("hero\.running"\)/);
  assert.match(panel, /<h2 id="attention-heading"[\s\S]{0,200}t\("hero\.attention", \{ count \}\)/);
});

test("Backups history tallies: an icon per count, colour only when not zero (8 Oct)", () => {
  const src = read("components/backups/backups-history.jsx");
  assert.match(src, /<Tally icon=\{CircleX\} label=\{t\("counts\.failed"\)\}/);
  assert.match(src, /active \|\| always \? TALLY_TONE\[tone\] : "bg-muted text-muted-foreground"/);
});
