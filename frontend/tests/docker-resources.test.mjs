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
  // And a busy resource cannot be confirmed even if the row was stale — now
  // including a network a site names, which is a second refusal the endpoint
  // makes and `busy` cannot see (a stopped site is attached to no container).
  assert.match(panel, /confirmDisabled=\{confirm\?\.busy === true \|\|/);
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

/*
 * The network a site joins.
 *
 * A network the panel creates is only useful if something can join it, and the
 * generated compose file is the only thing that can. These cover the frontend
 * half: the card exists, the empty choice round-trips, and the delete gate on
 * this page matches the one the endpoint enforces.
 */

const card = read("components/applications/container-card.jsx");
const appPage = read("app/(app)/applications/[application]/page.jsx");
const schemas = read("lib/schemas/docker.js");

test("the networks table shows which sites joined a network", () => {
  // Not the same as the `ownedByApp` badge, which is inferred from Compose's
  // naming and says nothing about a site that joined someone else's network.
  assert.match(panel, /network\.sites\.length > 0/);
  assert.match(panel, /site\.name/);
});

test("the delete gate matches the endpoint's second refusal", () => {
  // The endpoint 409s when a site names the network — including a STOPPED site,
  // which is attached to no container. A button whose only outcome is that 409
  // is not a button.
  assert.match(panel, /confirm\?\.sites\?\.length/);
  assert.match(panel, /networks\.removeUsedBySites/);
  for (const locale of LOCALES) {
    assert.ok(
      messages[locale].docker.networks.removeUsedBySites,
      `${locale} is missing docker.networks.removeUsedBySites`,
    );
  }
});

test("the network schema declares sites, or Zod drops it", () => {
  // Zod strips what the schema does not name, so an unlisted key never reaches
  // the page — silently, and looking exactly like an API that did not send it.
  assert.match(schemas, /sites: z\s*\n?\s*\.array/);
});

test("the container card is offered only to containers", () => {
  // The endpoint refuses the fields for any other profile, so rendering it
  // elsewhere would be a card whose only outcome is a 422.
  assert.match(appPage, /const isContainer = application\.serving_profile === "docker"/);
  assert.match(appPage, /\{isContainer \? \(\s*<ContainerCard/);
});

test("the empty network choice is one constant, not three strings", () => {
  // Radix refuses `value=""`, so "Docker's default bridge" needs a value of its
  // own — and the defaults, the item and the submit mapping have to agree on
  // it. They did not in the first version, and picking the default sent the
  // sentinel to the API as a network name.
  assert.match(card, /const DEFAULT_NETWORK = "__default__"/);
  const uses = card.match(/DEFAULT_NETWORK/g) ?? [];
  assert.ok(uses.length >= 4, `expected the constant to be used throughout, saw ${uses.length}`);
  assert.doesNotMatch(card, /docker_network: values\.docker_network === ""/);
});

test("saving the card refreshes the server-rendered siblings", () => {
  // The Docker page's "used by" column and this site's own facts are both
  // server-rendered. Without a refresh they keep showing page-load state.
  assert.match(card, /router\.refresh\(\)/);
});

test("the card says the site restarts before the click, not after", () => {
  // Saving recreates the container. That is downtime, and it is not something
  // to discover from a graph.
  assert.match(card, /note=\{t\("restartNote"\)\}/);
  for (const locale of LOCALES) {
    assert.ok(
      messages[locale].applications.container.restartNote,
      `${locale} is missing applications.container.restartNote`,
    );
  }
});

test("every container card string exists in every locale", () => {
  const reference = Object.keys(messages.en.applications.container);
  for (const locale of LOCALES) {
    const keys = Object.keys(messages[locale].applications.container ?? {});
    assert.deepEqual(
      keys.slice().sort(),
      reference.slice().sort(),
      `${locale} disagrees with en on applications.container`,
    );
  }
});
