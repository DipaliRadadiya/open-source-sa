import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = path.join(import.meta.dirname, "..");
const read = (p) => fs.readFileSync(path.join(root, p), "utf8");

const page = read("app/(app)/applications/page.jsx");
const table = read("components/applications/applications-table.jsx");
const cards = read("components/applications/applications-cards.jsx");
const actions = read("components/applications/application-row-actions.jsx");

test("the list asks for the application catalog once, not once per row", () => {
  // Role grants are global; `application_id` only narrows the catalog by one
  // site's features. Passing an id here would mean a request per row.
  assert.match(page, /getPermissions\("application"\)\.catch\(\(\) => \[\]\)/);
  assert.doesNotMatch(page, /getPermissions\("application",\s*\w+\)/);
});

test("the row is gated on the Magic Login permission, not on application manage", () => {
  // The API enforces `app_magic_login`. Showing the item to an application
  // manager who lacks that grant renders a button whose only outcome is 403.
  assert.match(
    page,
    /canMagicLogin=\{can\(appPermissions, "app_magic_login", "manage", "application"\)\}/,
  );
});

test("the flag reaches the row through both the table and the cards", () => {
  // A break anywhere along here hides the item silently rather than erroring.
  assert.match(table, /canMagicLogin = false,/);
  assert.match(table, /meta=\{\{ canManage, canMagicLogin \}\}/);
  assert.match(table, /table\.options\.meta\?\.canMagicLogin \?\? false/);
  assert.match(cards, /canMagicLogin = false/);
  assert.match(cards, /canMagicLogin=\{canMagicLogin\}/);
});

test("the row checks the site type itself", () => {
  // Unlike the Dashboard — whose catalog is fetched for one application and so
  // is already filtered by site type — the list's catalog is unfiltered.
  assert.match(actions, /application\.site_type === "wordpress"/);
  assert.match(actions, /application\.status === "active"/);
});

test("it does not appear twice on the application's own dashboard", () => {
  // The detail header renders a full Magic Login button and also renders this
  // menu. The prop defaults to false so only the list opts in.
  assert.match(actions, /canMagicLogin = false,/);
  const detail = read("app/(app)/applications/[application]/page.jsx");
  assert.match(detail, /<MagicLoginLauncher appId=\{id\}/);
  assert.doesNotMatch(detail, /canMagicLogin=\{/);
});

test("each row's dialog is mounted only once opened", () => {
  // Ten rows would otherwise each carry a dialog nobody asked for.
  // Mounted only while there is a choice to make — which is also what keeps
  // the list fresh, since `choice` only ever holds what `start()` just read.
  assert.match(actions, /magicLogin\.choice \? \(\s*<MagicLoginDialog/);
  assert.match(actions, /const magicLogin = useMagicLogin\(application\.id\)/);
  /*
   * preventDefault on select: Radix closes the menu on its own, and if that
   * teardown runs first the user gesture is spent and `window.open` is blocked.
   * Driven in a browser from a real dropdown to confirm the tab still opens.
   */
  assert.match(actions, /event\.preventDefault\(\);\s*setMenuOpen\(false\);\s*magicLogin\.start\(\)/);
});

test("Magic Login does not draw its own separator", () => {
  // It did, and the `canManage` group below draws one whenever anything sits
  // above it — so the list showed two rules between Magic Login and Pause
  // site. A group that ends with a separator and a group that begins with one
  // cannot both be right.
  const block = actions.slice(
    actions.indexOf("{showMagicLogin ? ("),
    actions.indexOf("{shortcuts.length > 0 ? ("),
  );

  assert.ok(block.length > 0, "the Magic Login block must be findable");
  assert.doesNotMatch(block, /DropdownMenuSeparator/);
});

test("the group below counts Magic Login as something above it", () => {
  // Otherwise hiding the navigation block would leave Magic Login welded to
  // Pause and Delete with no rule between them.
  assert.match(
    actions,
    /\{showNavigation \|\| showRetry \|\| showMagicLogin \? <DropdownMenuSeparator \/> : null\}/,
  );
});

test("the menu item reuses the existing string", () => {
  // Same action, same words as the Dashboard button — a second key would let
  // the two drift apart.
  assert.match(actions, /t\("magicLogin\.action"\)/);
  const en = JSON.parse(read("messages/en.json"));
  assert.equal(typeof en.applications.magicLogin.action, "string");
});
