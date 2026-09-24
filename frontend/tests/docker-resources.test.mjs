import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = path.join(import.meta.dirname, "..");
const read = (p) => fs.readFileSync(path.join(root, p), "utf8");

const panel = read("components/docker/docker-resources-panel.jsx");
const page = read("app/(app)/docker/page.jsx");
const LOCALES = ["en", "es", "fr", "de", "hi", "ja", "pt", "ru"];
const messages = Object.fromEntries(
  LOCALES.map((l) => [l, JSON.parse(read(`messages/${l}.json`))]),
);

/*
 * Networks and volumes are server-level, and every destructive control on the
 * screen is either absent or confirmed. The API refuses the same cases
 * independently — a hidden button whose endpoint still works is worse than a
 * visible one — so these tests are about what the screen *offers*, not about
 * what is allowed.
 */

test("Docker's own networks get no delete button at all", () => {
  // A label, not a disabled control. Disabled says "not now"; the truth is
  // "never" — Docker recreates bridge/host/none on restart, so a delete could
  // only fail or do damage.
  assert.match(panel, /network\.built_in \?/);
  assert.match(panel, /networks\.builtIn/);
});

test("removal always goes through a confirmation", () => {
  assert.match(panel, /<ConfirmDialog/);
  assert.match(panel, /confirmVariant="destructive"/);
  // And a busy resource cannot be confirmed even if the row was stale.
  assert.match(panel, /confirmDisabled=\{confirm\?\.busy === true\}/);
});

test("the server's own refusal is shown, not a generic one", () => {
  // The API names the containers on a busy network and the count on a volume
  // in use. A generic client message would throw away the only specific thing
  // the user is told.
  assert.match(panel, /apiMessage\(error, t\("failed"\)\)/);
});

test("a failed read is not rendered as an empty list", () => {
  // "This server has no networks" is a claim about the machine, and Docker
  // always has at least three.
  assert.match(page, /failed \?/);
  assert.match(page, /LoadFailed/);
  // And a 409 is the honest case — the endpoints are gated on the server
  // hosting containers — so it gets its own empty state rather than an error.
  assert.match(page, /status === 409/);
});

test("the page is gated on the docker permission", () => {
  assert.match(page, /can\(permissions, "docker", "view"\)/);
  assert.match(page, /can\(permissions, "docker", "manage"\)/);
});

test("every string the screen uses exists in every locale", () => {
  const needed = [
    ["title"], ["subtitle"], ["create"], ["remove"], ["failed"], ["loadFailed"],
    ["unavailable", "title"], ["unavailable", "body"],
    ["columns", "name"], ["columns", "size"], ["columns", "usedBy"], ["columns", "attached"],
    ["networks", "title"], ["networks", "builtIn"], ["networks", "removeTitle"],
    ["networks", "removeBody"], ["networks", "removeBusy"], ["networks", "hint"],
    ["volumes", "title"], ["volumes", "inUse"], ["volumes", "removeTitle"],
    ["volumes", "removeBody"], ["volumes", "removeBusy"], ["volumes", "hint"],
  ];

  for (const locale of LOCALES) {
    for (const keyPath of needed) {
      const value = keyPath.reduce((node, key) => node?.[key], messages[locale].docker);
      assert.ok(
        typeof value === "string" && value.length > 0,
        `${locale}: docker.${keyPath.join(".")} is missing`,
      );
    }
  }
});

test("the busy-volume warning says what is lost, not just 'are you sure'", () => {
  // The question people can answer is "does this delete my database". The
  // dialog should be the thing that answers it.
  for (const locale of LOCALES) {
    const busy = messages[locale].docker.volumes.removeBusy;
    assert.ok(busy.length > 40, `${locale}: removeBusy is too terse to explain the risk`);
  }
});
