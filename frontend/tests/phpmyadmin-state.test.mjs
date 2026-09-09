import { test } from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { phpmyadminState, userCount } from "../lib/databases/phpmyadmin-state.js";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");

test("MongoDB gets no button at all", () => {
  assert.equal(phpmyadminState({ engine: "mongodb", installed: true, users: 3 }), "hidden");
  // Even with everything else wrong, the engine decides first.
  assert.equal(phpmyadminState({ engine: "mongodb", installed: false, users: 0 }), "hidden");
});

test("no phpMyAdmin site offers the install instead of a refusal", () => {
  assert.equal(phpmyadminState({ engine: "mysql", installed: false, users: 2 }), "install");
});

test("a database with no users cannot sign in to phpMyAdmin", () => {
  assert.equal(phpmyadminState({ engine: "mysql", installed: true, users: 0 }), "needs-user");
});

test("the ordinary case still opens", () => {
  assert.equal(phpmyadminState({ engine: "mysql", installed: true, users: 1 }), "open");
  assert.equal(phpmyadminState({ engine: "mariadb", installed: true, users: 5 }), "open");
});

test("a failed lookup must not offer to install a second copy", () => {
  /*
   * The regression this guards: treating null as false. One timed-out request
   * would tell somebody with a working phpMyAdmin to install another one.
   */
  assert.equal(phpmyadminState({ engine: "mysql", installed: null, users: 2 }), "open");
  assert.equal(phpmyadminState({ engine: "mysql", users: 2 }), "open");
});

test("an unknown user count is not zero users", () => {
  // `users: null` means we did not count, and a button greyed out with
  // "add a user first" would be a guess presented as a fact.
  assert.equal(phpmyadminState({ engine: "mysql", installed: true, users: null }), "open");
  assert.equal(phpmyadminState({ engine: "mysql", installed: true }), "open");
});

test("the user count is read from either payload shape", () => {
  // The list sends a count, the detail page sends the array.
  assert.equal(userCount({ users_count: 0 }), 0);
  assert.equal(userCount({ users_count: 3 }), 3);
  assert.equal(userCount({ users: [] }), 0);
  assert.equal(userCount({ users: [{ id: 1 }, { id: 2 }] }), 2);
  // Neither present — unknown, not zero.
  assert.equal(userCount({}), null);
  assert.equal(userCount(undefined), null);
});

test("the install link matches the create page's own parameter", () => {
  /*
   * The button links to /applications/create?type=phpmyadmin and the page
   * validates that value against the server's site-type list. If either side
   * renames the parameter the link silently stops prefilling — it still opens
   * a working page, so nothing would fail.
   */
  const button = fs.readFileSync(
    path.join(root, "components/databases/phpmyadmin-button.jsx"),
    "utf8",
  );
  const page = fs.readFileSync(
    path.join(root, "app/(app)/applications/create/page.jsx"),
    "utf8",
  );

  assert.match(button, /\/applications\/create\?type=phpmyadmin/, "the button no longer links with ?type=");
  assert.match(page, /sp\?\.type/, "the create page no longer reads ?type=");
  assert.match(page, /siteTypes\.some/, "the create page no longer validates ?type= against the real list");
});

test("a loaded users array outranks a missing count", () => {
  /*
   * The regression this file exists for. `GET /databases/{id}` loads the users
   * but does not count them, so `users_count` is absent on every detail
   * payload while `users` holds the real rows. The schema used to fill that
   * absence with `.default(0)`, and userCount used to read the count first — so
   * a database with a user answered 0, and its detail page disabled phpMyAdmin
   * with "add a database user first" printed directly above the user.
   */
  assert.equal(userCount({ users: [{ id: 1 }] }), 1, "a loaded user was not counted");
  assert.equal(userCount({ users: [{ id: 1 }], users_count: 0 }), 1, "a stale zero outranked the real rows");
  assert.equal(
    phpmyadminState({ engine: "mysql", installed: true, users: userCount({ users: [{ id: 1 }], users_count: 0 }) }),
    "open",
    "the detail page still refuses a database that has a user",
  );

  // The list shape keeps working: no array, real count.
  assert.equal(userCount({ users_count: 4 }), 4);
  assert.equal(userCount({ users_count: 0 }), 0, "a counted zero must stay zero");
  // Neither shape present is unknown, never zero.
  assert.equal(userCount({}), null);
  // An empty loaded array IS a positive zero.
  assert.equal(userCount({ users: [] }), 0);
});

test("the schema never invents a user count the API did not send", () => {
  const schema = fs.readFileSync(path.join(root, "lib/schemas/database.js"), "utf8");
  const line = schema.split("\n").find((l) => l.includes("users_count:"));
  assert.ok(line, "users_count left the schema");
  assert.doesNotMatch(
    line,
    /\.default\(/,
    "users_count defaults again — an absent count becomes 0 and phpMyAdmin locks on every detail page",
  );
});
