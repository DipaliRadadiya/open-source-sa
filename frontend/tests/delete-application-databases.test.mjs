import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

const read = (path) => readFileSync(new URL(`../${path}`, import.meta.url), "utf8");
const DIALOG = "components/applications/delete-application-dialog.jsx";

/*
 * Deleting a site used to leave its database behind, detached and easy to
 * forget — which is how a test server collects databases nobody can name.
 *
 * `DELETE /applications/{id}?remove_databases=true` does it in one call:
 * site first, databases after, 200 even when a database survives, with what
 * survived named in the body.
 */

test("the flag is sent, and nothing else is", () => {
  const api = read("lib/api/applications.js");
  assert.match(api, /if \(removeDatabases\) params\.remove_databases = true/);
  /*
   * No ids. The API resolves the site's databases as it deletes, so a database
   * attached since this dialog opened is still taken — sending the list this
   * dialog fetched would reintroduce exactly the staleness the one-call design
   * exists to avoid.
   */
  assert.doesNotMatch(api, /database_ids|databases: \[/);
});

test("the checkbox names the databases rather than counting them", () => {
  const dialog = read(DIALOG);
  // "Also delete 1 database" is a promise the reader cannot check. The name is.
  assert.match(dialog, /databases: databases\.map\(\(row\) => row\.name\)\.join\(", "\)/);
  // And no checkbox at all when the site has none — the old note claimed a
  // database "is kept" on every site, including those that never had one.
  assert.match(dialog, /\{databases\.length \? \(/);
});

test("the hint agrees with the number of databases", () => {
  /*
   * Shipped wrong first time: "shop_live, shop_reports stays on the server …
   * Remove it from Databases". One sentence cannot serve both counts.
   */
  const dialog = read(DIALOG);
  assert.match(dialog, /count: databases\.length/);

  for (const locale of ["en", "es", "hi"]) {
    const strings = JSON.parse(read(`messages/${locale}.json`)).applications.delete;
    for (const key of ["removeDatabases", "removeDatabasesOn", "removeDatabasesOff"]) {
      assert.ok(strings[key], `${locale} is missing ${key}`);
      assert.match(strings[key], /\{count, plural,/, `${locale}.${key} does not pluralise`);
    }
    assert.ok(strings.databasesFailed && strings.goToDatabases, `${locale} cannot report a partial delete`);
    // Replaced by the checkbox, which says it per site instead of always.
    assert.equal(strings.databaseNote, undefined, `${locale} still carries the old blanket note`);
  }
});

test("a site that went while a database stayed is neither a success nor a failure", () => {
  /*
   * The API answers 200: the site really is gone, and a red toast would say
   * nothing happened when nearly all of it did. A green one would bury a
   * database still sitting on the server. So a warning, naming it, with a way
   * to finish the job — and long enough to read, because the dialog it would
   * otherwise be shown in is closing on a site that no longer exists.
   */
  const dialog = read(DIALOG);
  assert.match(dialog, /const failed = data\?\.databases\?\.failed \?\? \[\]/);
  assert.match(dialog, /if \(failed\.length\) \{/);
  assert.match(dialog, /toast\.warning\(/);
  assert.match(dialog, /duration: 20000/);
  assert.match(dialog, /t\("goToDatabases"\)/);
  // The API's own sentence first — it names the databases in the reader's
  // language; ours is the fallback for an older API that sends none.
  assert.match(dialog, /data\?\.message \?\? t\("databasesFailed"/);
});

test("the lookup parses rows, not the envelope", () => {
  /*
   * `databasesResponseSchema` also requires `meta`. This needs names only, so
   * parsing the whole response would hide the checkbox the day pagination
   * changes shape — a control vanishing is not a failure anyone would notice.
   */
  const dialog = read(DIALOG);
  assert.match(dialog, /z\.array\(databaseSchema\)\.safeParse\(data\?\.databases\)/);
  // Fetched when it opens, not on mount: this renders once per row on the list.
  assert.match(dialog, /if \(!open \|\| !application\?\.id\) return undefined/);
  assert.match(dialog, /controller\.abort\(\)/);
});
