import test from "node:test";
import assert from "node:assert/strict";
import {
  applicationOptions,
  countByApplication,
  hasNoDatabase,
  siteNeedsDatabase,
  sitesMissingDatabase,
} from "../lib/backups/database-availability.js";

test("databases are counted against the site that owns them", () => {
  const counts = countByApplication([
    { id: 1, name: "shop", application_id: 26 },
    { id: 2, name: "shop_logs", application_id: 26 },
    { id: 3, name: "blog", application_id: 31 },
  ]);
  assert.deepEqual(counts, { 26: 2, 31: 1 });
});

test("a database belonging to no site is credited to no site", () => {
  /*
   * Server-level databases carry a null `application_id`. Counting one against
   * some site would tell a site with no database that it has one, which is the
   * exact wrong answer this whole feature exists to prevent.
   */
  assert.deepEqual(
    countByApplication([
      { id: 1, name: "scratch", application_id: null },
      { id: 2, name: "adhoc" },
      { id: 3, name: "shop", application_id: 26 },
    ]),
    { 26: 1 },
  );
});

test("nothing in, nothing out", () => {
  assert.deepEqual(countByApplication([]), {});
  assert.deepEqual(countByApplication(undefined), {});
  assert.deepEqual(countByApplication(null), {});
});

test("a site absent from the counts has no database", () => {
  const counts = { 26: 2 };
  assert.equal(hasNoDatabase(counts, true, 26), false);
  assert.equal(hasNoDatabase(counts, true, 31), true);
  // Object keys are strings; a numeric id must still find its own row.
  assert.equal(hasNoDatabase({ 26: 1 }, true, "26"), false);
});

test("unknown is never reported as 'no database'", () => {
  /*
   * `known: false` means the list was truncated or the call failed. Answering
   * `true` there would print a confident "this site has no database" under a
   * site whose databases we simply never loaded — and that warning argues
   * someone out of backing up data they actually have.
   */
  assert.equal(hasNoDatabase({}, false, 26), null);
  assert.equal(hasNoDatabase(null, false, 26), null);
  // No site chosen yet: also not an answer.
  assert.equal(hasNoDatabase({ 26: 1 }, true, null), null);
  assert.equal(hasNoDatabase({ 26: 1 }, true, undefined), null);
});

const APPS = [
  { id: 26, name: "prestashop", domain: "shop.example.com" },
  { id: 31, name: "static-site", domain: "static.example.com" },
];

test("a site that already has a database is offered, but blocked with the reason", () => {
  /*
   * Blocked rather than hidden: the site is visible in the applications table,
   * so a picker that silently omits it reads as broken. And "it already has
   * one" is precisely what the reader must act on — detach that one first.
   */
  const options = applicationOptions(APPS, { 26: 1 }, true, "Already has a database");

  assert.equal(options.length, 2);
  assert.equal(options[0].disabledReason, "Already has a database");
  assert.equal(options[1].disabledReason, undefined);
});

test("options carry the domain as a hint, and string values for the picker", () => {
  const [first] = applicationOptions(APPS, {}, true, "x");
  assert.equal(first.value, "26");
  assert.equal(first.label, "prestashop");
  assert.equal(first.hint, "shop.example.com");
});

test("unknown counts block nothing", () => {
  /*
   * The mirror of `hasNoDatabase`: when we could not read the list, barring a
   * site that is actually free is the worse error. Let the API refuse instead.
   */
  const options = applicationOptions(APPS, { 26: 1 }, false, "Already has a database");
  assert.deepEqual(options.map((o) => o.disabledReason), [undefined, undefined]);
});

test("no applications, no options", () => {
  assert.deepEqual(applicationOptions([], {}, true, "x"), []);
  assert.deepEqual(applicationOptions(undefined, null, true, "x"), []);
});

const TYPES = [
  { name: "wordpress", needs_database: true },
  { name: "static", needs_database: false },
  { name: "php-blank" },
];

test("only a site type that declares it needs a database", () => {
  assert.equal(siteNeedsDatabase(TYPES, "wordpress"), true);
  assert.equal(siteNeedsDatabase(TYPES, "static"), false);
  // Absent flag is not a missing database, it is a type that never wanted one.
  assert.equal(siteNeedsDatabase(TYPES, "php-blank"), false);
});

test("an unknown type never raises the warning", () => {
  /*
   * The types list failing, or a type this build has not heard of, must not
   * put a permanent amber warning on a site that is fine. A false warning here
   * is worse than a missing one: it is the thing that teaches people to ignore
   * the warning on the sites that really are missing a database.
   */
  assert.equal(siteNeedsDatabase(TYPES, "laravel"), false);
  assert.equal(siteNeedsDatabase([], "wordpress"), false);
  assert.equal(siteNeedsDatabase(undefined, "wordpress"), false);
  assert.equal(siteNeedsDatabase(TYPES, null), false);
  assert.equal(siteNeedsDatabase(TYPES, undefined), false);
});

const SITES = [
  { id: 1, name: "shop", site_type: "wordpress" },
  { id: 2, name: "brochure", site_type: "static" },
  { id: 3, name: "blog", site_type: "wordpress" },
];

test("only sites that need a database AND have none are marked", () => {
  // Site 1 has one; site 3 does not. Site 2 never wanted one.
  const missing = sitesMissingDatabase(SITES, TYPES, { 1: 1 }, true);
  assert.deepEqual([...missing], [3]);
});

test("a static site is never marked, however many databases it lacks", () => {
  /*
   * The whole point of joining the site type. Marking every database-less site
   * would put a permanent badge on every static site on the server, and a
   * badge on half the list marks nothing.
   */
  const missing = sitesMissingDatabase(SITES, TYPES, {}, true);
  assert.equal(missing.has(2), false);
  assert.deepEqual([...missing].sort(), [1, 3]);
});

test("unknown counts mark nothing at all", () => {
  assert.equal(sitesMissingDatabase(SITES, TYPES, { 1: 1 }, false).size, 0);
  assert.equal(sitesMissingDatabase(SITES, TYPES, null, false).size, 0);
});

test("nothing in, empty set out", () => {
  assert.equal(sitesMissingDatabase([], TYPES, {}, true).size, 0);
  assert.equal(sitesMissingDatabase(undefined, undefined, {}, true).size, 0);
});

// --- A site whose application cannot speak this engine ---

test("a MongoDB database cannot be attached to a MySQL-only site, and the picker says so", async () => {
  const { engineAccepted, acceptedEnginesFor } = await import(
    "../lib/databases/engine-acceptance.js"
  );

  const types = [
    { name: "wordpress", needs_database: true, accepted_engines: ["mysql", "mariadb"] },
    { name: "nodebb", needs_database: true, accepted_engines: ["mongodb"] },
    { name: "custom", needs_database: false, accepted_engines: [] },
  ];
  const wp = { id: 1, site_type: "wordpress" };
  const forum = { id: 2, site_type: "nodebb" };
  const custom = { id: 3, site_type: "custom" };

  assert.equal(engineAccepted({ application: wp, siteTypes: types, engine: "mongodb" }), false);
  assert.equal(engineAccepted({ application: wp, siteTypes: types, engine: "mariadb" }), true);
  assert.equal(engineAccepted({ application: forum, siteTypes: types, engine: "mongodb" }), true);
  assert.equal(
    engineAccepted({ application: custom, siteTypes: types, engine: "mongodb" }),
    true,
    "a type that needs no database accepts anything — the backend's own rule",
  );

  assert.deepEqual(acceptedEnginesFor({ application: wp, siteTypes: types }), ["mysql", "mariadb"]);
});

test("nothing is blocked on a catalogue we could not load", async () => {
  const { engineAccepted } = await import("../lib/databases/engine-acceptance.js");
  const wp = { id: 1, site_type: "wordpress" };
  assert.equal(engineAccepted({ application: wp, siteTypes: [], engine: "mongodb" }), true);
  assert.equal(engineAccepted({ application: wp, siteTypes: null, engine: "mongodb" }), true);
  assert.equal(engineAccepted({ application: wp, siteTypes: [], engine: null }), true);
});

test("'already has one' outranks the engine reason", async () => {
  const { applicationOptions } = await import("../lib/backups/database-availability.js");
  const options = applicationOptions(
    [{ id: 1, name: "shop", domain: "shop.test" }],
    { 1: 1 },
    true,
    "taken",
    () => "wrong engine",
  );
  assert.equal(options[0].disabledReason, "taken", "the more actionable of the two wins");
});
