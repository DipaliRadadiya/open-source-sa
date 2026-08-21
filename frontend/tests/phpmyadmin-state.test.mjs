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
