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
  // One key per resource, chosen from `confirm.kind` — a volume a stopped site
  // mounts has to refuse too, and it says something different: a network that
  // goes away breaks a start, a volume that goes away destroys data.
  assert.match(panel, /\$\{confirm\.kind\}s\.removeUsedBySites/);
  for (const locale of LOCALES) {
    for (const resource of ["networks", "volumes"]) {
      assert.ok(
        messages[locale].docker[resource].removeUsedBySites,
        `${locale} is missing docker.${resource}.removeUsedBySites`,
      );
    }
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

/*
 * Which containers use a volume.
 *
 * "1 container(s)" is a number; the name is what somebody can act on.
 */

test("the volumes table names the containers using a volume", () => {
  assert.match(panel, /volume\.container_names\.length > 0/);
  assert.match(panel, /volume\.container_names\.map/);
});

test("the count survives as a fallback, not as the default", () => {
  // Names come from `container inspect` and the count from `system df -v`, so an
  // empty list beside a non-zero count means "could not ask" — a different fact
  // from "nothing is using it", and it must not render as the same thing.
  assert.match(panel, /volumes\.inUseUnnamed/);
  for (const locale of LOCALES) {
    assert.ok(
      messages[locale].docker.volumes.inUseUnnamed,
      `${locale} is missing docker.volumes.inUseUnnamed`,
    );
  }
});

test("the volume schema declares container_names, or Zod drops it", () => {
  assert.match(schemas, /container_names: z\.array\(z\.string\(\)\)/);
});

test("an unused volume still reads as unused, not as unnamed", () => {
  // The three states are distinct: not in use, in use and named, in use and
  // unnameable. Collapsing the first two is what the old cell effectively did.
  assert.match(panel, /!volume\.in_use \?/);
  assert.match(panel, /volumes\.unused/);
});

/*
 * Mounting a volume into a site.
 *
 * The path is the half a container picker cannot express: `shop-db` is only
 * useful at `/var/lib/mysql`, and the same volume at `/app/uploads` is a
 * different thing. That is why the control lives on the site, not in the volume
 * create dialog — and why `docker volume create` taking no container is not the
 * obstacle it looks like.
 */

const volumesList = read("components/applications/container-volumes.jsx");

test("a mount carries both a volume and a path", () => {
  assert.match(schemas, /export const volumeMountSchema/);
  assert.match(schemas, /volume: dockerNameSchema/);
  // Absolute, and no traversal.
  assert.ok(schemas.includes(".regex(/^\\//)"), "path must be required absolute");
  assert.ok(schemas.includes("(^|\\/)\\.\\.(\\/|$)"), "path must refuse traversal");
});

test("the volumes list is offered only on a container site", () => {
  assert.match(appPage, /volumes=\{dockerVolumes\}/);
  assert.match(appPage, /getDockerVolumes/);
});

test("a volume already mounted is still offered, at another path", () => {
  // One volume at two paths is legal Docker; only a repeated PATH is refused.
  // Filtering mounted volumes out of the chooser would forbid something valid.
  assert.match(volumesList, /alreadyMounted/);
});

test("add and remove save immediately and refresh", () => {
  // Each change recreates the container, so batching them into a Save button
  // would hide how many restarts one click is worth.
  assert.match(volumesList, /router\.refresh\(\)/);
  assert.match(volumesList, /updateContainerSettings/);
});

test("the server's refusal is shown where it was typed, not only as a toast", () => {
  // "That path is where the site's own files are" needs reading twice, and only
  // the server can say it — the rule needs to know what the compose file mounts.
  assert.match(volumesList, /setError\(/);
  assert.match(volumesList, /text-destructive/);
});

test("the volume schema declares sites, or Zod drops it", () => {
  assert.match(schemas, /sites: z\.array\(z\.object\(\{ id: z\.number\(\), name: z\.string\(\) \}\)\)/);
});

test("every volumes-list string exists in every locale", () => {
  const reference = Object.keys(messages.en.applications.container.volumes);
  assert.ok(reference.length > 10, "expected the volumes block to be populated");
  for (const locale of LOCALES) {
    assert.deepEqual(
      Object.keys(messages[locale].applications.container.volumes ?? {}).slice().sort(),
      reference.slice().sort(),
      `${locale} disagrees with en on applications.container.volumes`,
    );
  }
});

/*
 * Attaching from the Docker page.
 *
 * Someone who has just created a network is already on this page and should not
 * have to go and find the site. Same endpoint as the Container card, so the two
 * doors cannot disagree about what attaching means.
 */

const attachDialog = read("components/docker/attach-site-dialog.jsx");
const dockerPage = read("app/(app)/docker/page.jsx");

test("attaching goes through the same endpoint as the site's own card", () => {
  // Not a second write path. A `docker network connect` here would be undone by
  // the site's next deploy, which rebuilds the container from the compose file.
  assert.match(attachDialog, /updateContainerSettings/);
  assert.doesNotMatch(attachDialog, /connect/);
});

test("attaching a volume asks for the path, attaching a network does not", () => {
  // A volume needs a path inside the container; a network does not. This is the
  // whole reason the volume create dialog cannot just have a container picker.
  assert.match(attachDialog, /isVolume \? \(/);
  assert.match(attachDialog, /pathHint/);
});

test("a volume attach appends to the site's existing mounts", () => {
  // Replacing them would silently unmount everything else the site has.
  assert.match(attachDialog, /\.\.\.\(chosen\.volume_mounts \?\? \[\]\)/);
});

test("the dialog says the container will be recreated, before the click", () => {
  assert.match(attachDialog, /restartWarning/);
  for (const locale of LOCALES) {
    assert.ok(
      messages[locale].docker.attach.restartWarning,
      `${locale} is missing docker.attach.restartWarning`,
    );
  }
});

test("attaching is gated on the site permission, not the docker one", () => {
  // It writes the SITE's configuration and restarts it, so the permission that
  // governs it is the one that governs the site.
  assert.match(dockerPage, /can\(permissions, "application", "manage"\)/);
  assert.match(panel, /canManageSites \? \(/);
});

test("only running container sites are offered", () => {
  // Applying it brings the container up; on a pending site that would be
  // provisioning it as a side effect of a click on a different screen.
  assert.match(dockerPage, /serving_profile === "docker"/);
  assert.match(dockerPage, /status === "active"/);
});

test("the list schema carries the docker fields, or every site looks unattached", () => {
  // Zod strips what the schema does not name, and the dialog reads the LIST —
  // without these it would offer to attach a site to the network it is already
  // on.
  const appSchema = read("lib/schemas/application.js");
  assert.match(appSchema, /docker_network: z\.string\(\)\.nullish\(\)/);
  assert.match(appSchema, /volume_mounts: z/);
});

test("every attach string exists in every locale", () => {
  const reference = Object.keys(messages.en.docker.attach);
  for (const locale of LOCALES) {
    assert.deepEqual(
      Object.keys(messages[locale].docker.attach ?? {}).slice().sort(),
      reference.slice().sort(),
      `${locale} disagrees with en on docker.attach`,
    );
  }
});
