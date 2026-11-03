import assert from "node:assert/strict";
import fs from "node:fs";
import test from "node:test";
import { switchTarget } from "../lib/applications/switch-target.js";

// Tester request (2 Nov): switch application without going back to the list.
const item = (url, view = true) => ({ level: "application", url, permissions: { view } });
const WORDPRESS = [item(""), item("/files"), item("/logs"), item("/staging"), item("/backups")];
const PHP = [item(""), item("/files"), item("/logs"), item("/backups")];

test("the same section on the other application when it has one", () => {
  assert.equal(switchTarget("/applications/2/files", 2, 5, PHP), "/applications/5/files");
  assert.equal(switchTarget("/applications/2/logs", 2, 5, WORDPRESS), "/applications/5/logs");
});

test("its dashboard when it does not (Staging is WordPress only)", () => {
  assert.equal(switchTarget("/applications/2/staging", 2, 1, PHP), "/applications/1");
  assert.equal(switchTarget("/applications/2", 2, 1, PHP), "/applications/1");
});

test("the section, never a page inside it, and never one the user may not open", () => {
  // A folder path belongs to that site alone.
  assert.equal(switchTarget("/applications/2/files/wp-content/uploads", 2, 5, PHP), "/applications/5/files");
  assert.equal(switchTarget("/applications/2/logs", 2, 5, [item(""), item("/logs", false)]), "/applications/5");
  // An unreadable catalog still lands somewhere real.
  assert.equal(switchTarget("/applications/2/files", 2, 5, null), "/applications/5");
  // "/applications/20" is not inside "/applications/2".
  assert.equal(switchTarget("/applications/20/files", 2, 5, PHP), "/applications/5");
});

test("the sidebar card opens it: searched on the server, one page, only when opened", () => {
  const sidebar = fs.readFileSync(new URL("../components/sections/app-sidebar.jsx", import.meta.url), "utf8");
  const switcher = fs.readFileSync(new URL("../components/sections/application-switcher.jsx", import.meta.url), "utf8");
  const api = fs.readFileSync(new URL("../lib/api/applications.js", import.meta.url), "utf8");
  assert.match(sidebar, /<ApplicationSwitcher application=\{application\}/);
  assert.match(api, /params: \{ per_page: 20, sort: "name", \.\.\.\(search \? \{ search \} : \{\}\) \}/);
  assert.match(switcher, /if \(!open\) return undefined;/);
  // Unsaved work is asked about before leaving, like every other sidebar link.
  assert.match(switcher, /if \(!guardNavigation\(href, leave\)\)/);
  assert.match(switcher, /t\("showingSome", \{ shown, total: state\.total \}\)/);
});

test("unsearched, the current application comes first, even when it is past the first page", async () => {
  const { switcherRows } = await import("../lib/applications/switcher-list.js");
  const page = [{ id: 4, name: "aksc" }, { id: 1, name: "phpsite" }, { id: 6, name: "qa-api-01" }];
  const ids = (rows) => rows.map((row) => row.id);
  // In the page: moved to the top, not repeated.
  assert.deepEqual(ids(switcherRows(page, { id: 1, name: "phpsite" }, "")), [1, 4, 6]);
  // Past the page (wpsc is 32nd of 33): added on top from the card's own data.
  assert.deepEqual(ids(switcherRows(page, { id: "2", name: "wpsc" }, "")), ["2", 4, 1, 6]);
  // A search lists the matches as sent; while loading (no rows yet) nothing is added.
  assert.deepEqual(ids(switcherRows(page, { id: 2 }, "qa")), [4, 1, 6]);
  assert.deepEqual(switcherRows([], { id: 2 }, null), []);
  assert.deepEqual(switcherRows([], { id: 2 }, ""), []);
});
