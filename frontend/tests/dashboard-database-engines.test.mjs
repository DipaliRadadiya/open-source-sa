import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import { shortVersion } from "../lib/databases/short-version.js";

/*
 * Krishna: "currently it shows only mysql but i have mariadb, mongodb,
 * postgresql installed. then from where you show mysql on dashboard??"
 *
 * From `/server/facts`, which answers "which database" by running
 * `mysql --version`. On a MariaDB box that prints
 *
 *   mysql  Ver 15.1 Distrib 10.11.14-MariaDB, for debian-linux-gnu
 *
 * and the backend's version parser takes the first number it sees. So the
 * dashboard published 15.1 — the version of the CLIENT TOOL — under the name
 * of an engine the server was not running, and never mentioned the other two
 * because nothing asked about them.
 *
 * The databases page had been right the whole time; it reads
 * `/databases/engines`. Two sources for one fact, disagreeing in public.
 */

const read = (p) => fs.readFileSync(p, "utf8");
const card = read("components/dashboard/server-info-card.jsx");
const page = read("app/(app)/dashboard/page.jsx");
const strip = (s) => s.replace(/\/\*[\s\S]*?\*\//g, "").replace(/^\s*\/\/.*$/gm, "");

test("the dashboard never publishes the `mysql` key from /server/facts", () => {
  /*
   * Dropped rather than relabelled. Renaming it to "MariaDB" would have put
   * the right name on the wrong number, which is worse than the bug: a wrong
   * version that looks plausible is one nobody checks.
   */
  // Now one of the engine names filtered out of the runtimes row: the
  // databases API lists every engine once, with its real version.
  assert.match(strip(card), /DATABASE_ENGINES = new Set\(\[[^\]]*"mysql"/, "the key has to be filtered out");
  assert.match(strip(card), /!DATABASE_ENGINES\.has\(name\)/);
});

test("the engines come from the databases API, not from the facts probe", () => {
  assert.match(page, /getEngines/, "the dashboard has to ask the right endpoint");
  assert.match(strip(card), /engines = \[\]/, "the card takes them as a prop");
  // Same fetcher the databases page uses, and it is `cache()`d there — one
  // more copy of this list would be one more place to disagree from.
  assert.match(page, /from "@\/lib\/databases\/get-databases"/);
});

test("a reader who cannot see databases is not told what runs on them", () => {
  // The chips disappear; nothing else on the row changes. Same rule the
  // services badge follows — say nothing rather than guess.
  assert.match(strip(page), /canViewDatabases \? getEngines\(\)/);
  assert.match(strip(page), /can\(permissions, "database", "view"\)/);
});

test("installed, not running — this row says what is on the machine", () => {
  /*
   * An engine that is installed but down still belongs in a list of what the
   * server has. Whether it is up is the services badge's question, and it is
   * three chips to the right of this one.
   */
  assert.match(strip(card), /engines\.filter\(\(engine\) => engine\.installed\)/);
});

test("the version trimmer is shared with the databases page", () => {
  /*
   * It lived inside `engine-bar.jsx`, private. The dashboard needed the same
   * answer, and the second copy would have been the place a fourth engine's
   * packaging got handled differently.
   */
  assert.match(card, /from "@\/lib\/databases\/short-version"/);
  assert.match(read("components/databases/engine-bar.jsx"), /from "@\/lib\/databases\/short-version"/);
  assert.doesNotMatch(read("components/databases/engine-bar.jsx"), /function shortVersion/);
});

test("every engine's packaging is trimmed to the number people asked for", () => {
  // Real strings from Krishna's server. Each buries the version differently,
  // which is why splitting on a space was not enough.
  assert.equal(shortVersion("10.11.14-MariaDB-0ubuntu0.24.04.1"), "10.11.14");
  assert.equal(shortVersion("16.15 (Ubuntu 16.15-0ubuntu0.24.04.1)"), "16.15");
  assert.equal(shortVersion("8.0.31"), "8.0.31");
  // Nothing installed, or an API that stopped answering: no chip, not "null".
  assert.equal(shortVersion(null), null);
  assert.equal(shortVersion(undefined), null);
});

test("the database tile uses a mark, so its printed name is said once", () => {
  /*
   * A wordmark is unreadable at tile size (redesign, 6 Oct), so the tile shows
   * the engine's mark-only icon (aria-hidden) and prints the name beside it —
   * one name, read once.
   */
  assert.match(strip(card), /src=\{`\/runtimes\/\$\{mainEngine\.engine\}\.svg`\} alt=""/);
  assert.doesNotMatch(strip(card), /EngineLogo/);
});

test("the full packaged string stays reachable", () => {
  // Somebody debugging a build needs "10.11.14-MariaDB-0ubuntu0.24.04.1", so
  // the chip keeps it on `title` rather than throwing it away.
  assert.match(strip(card), /title=\{mainEngine\.version \?\? undefined\}/);
});

test("the services verdict sits on the heading row, not among the software chips", () => {
  /*
   * In a row of chips "All 9 services running" read as one more runtime that
   * had spilled over. It now shares the "Main software" heading row instead.
   */
  assert.match(strip(card), /<h3 className="text-sm font-semibold">\{t\("info\.mainSoftware"\)\}<\/h3>\s*<ServiceHealthLine/);
});

test("the empty row still says so when there is nothing at all", () => {
  // The fallback used to be reached whenever `runtimes` was empty. With the
  // engines in a second list, checking only the first one would print "none"
  // beside three visible chips.
  assert.match(strip(card), /runtimes\.length \|\| installedEngines\.length/);
});
