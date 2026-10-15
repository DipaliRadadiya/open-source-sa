import { test } from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { adminerState } from "../lib/databases/adminer-state.js";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const read = (file) => fs.readFileSync(path.join(root, file), "utf8");

test("MongoDB gets no Adminer button: the API refuses it", () => {
  assert.equal(adminerState({ driver: "mongo", installed: true, users: 2 }), "hidden");
  assert.equal(adminerState({ engine: "mongodb", installed: true, users: 2 }), "hidden");
});

test("PostgreSQL opens in Adminer, and is offered the install when there is none", () => {
  assert.equal(adminerState({ driver: "pgsql", installed: true, users: 1 }), "open");
  assert.equal(adminerState({ driver: "pgsql", installed: false, users: 1 }), "install");
  // A failed lookup is not "none": never offer installing a second copy.
  assert.equal(adminerState({ driver: "pgsql", installed: null, users: 1 }), "open");
  assert.equal(adminerState({ engine: "postgresql", installed: true, users: 1 }), "open");
});

test("MySQL and MariaDB show Adminer only once one is installed (phpMyAdmin covers the rest)", () => {
  assert.equal(adminerState({ driver: "sql", installed: true, users: 1 }), "open");
  assert.equal(adminerState({ driver: "sql", installed: false, users: 1 }), "hidden");
  assert.equal(adminerState({ driver: "sql", installed: null, users: 1 }), "hidden");
});

test("no database user, no sign-in", () => {
  assert.equal(adminerState({ driver: "pgsql", installed: true, users: 0 }), "needs-user");
  // An unknown count is not zero.
  assert.equal(adminerState({ driver: "pgsql", installed: true, users: null }), "open");
});

test("the button follows phpMyAdmin's flow: manage only, tab only on the URL, a picker for several", () => {
  const button = read("components/databases/adminer-button.jsx");
  assert.match(button, /if \(state === "hidden" \|\| !canManage\) return null;/);
  const body = button.slice(button.indexOf("async function open("));
  const call = body.indexOf("await adminerSso(");
  const tab = body.indexOf("openUrlInNewTab(url)");
  assert.ok(call !== -1 && tab > call, "the tab must open only once the URL is known");
  assert.match(button, /sites !== null && sites\.length > 1/);
  assert.match(button, /onSelect=\{\(\) => open\(site\.id\)\}/);
  assert.match(button, /onClick=\{\(\) => open\(\)\}/);
  assert.match(button, /\/applications\/create\?type=adminer/);
});

test("the API call and the lookup match the endpoint", () => {
  const client = read("lib/api/databases.js");
  const fn = client.slice(client.indexOf("export function adminerSso"));
  assert.match(fn, /`\/databases\/\$\{databaseId\}\/adminer-sso`/);
  assert.match(fn, /params\.application_id = applicationId/);
  const fetcher = read("lib/applications/get-applications.js");
  const lookup = fetcher.slice(fetcher.indexOf("export const getAdminerSite"));
  assert.match(lookup, /"filter\[site_type\]": "adminer", "filter\[status\]": "active", per_page: 100/);
  assert.match(lookup, /if \(result\.failed\) return \{ sites: null/);
});

test("every place with a phpMyAdmin button also offers Adminer", () => {
  assert.match(read("components/databases/database-row-actions.jsx"), /<AdminerButton[\s\S]*?sites=\{adminerSites\}/);
  assert.match(read("app/(app)/databases/[database]/page.jsx"), /<AdminerButton[\s\S]*?sites=\{adminer\.known \? adminer\.sites : null\}/);
  assert.match(read("app/(app)/databases/page.jsx"), /adminerSites=\{adminer\?\.known \? adminer\.sites : null\}/);
  assert.match(read("components/databases/databases-cards.jsx"), /adminerSites=\{adminerSites\}/);
  assert.match(read("components/databases/databases-table.jsx"), /adminerSites=\{table\.options\.meta\.adminerSites\}/);
});
