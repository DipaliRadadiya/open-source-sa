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
    ["title"],
    ["subtitle"],
    ["create"],
    ["remove"],
    ["failed"],
    ["loadFailed"],
    ["unavailable", "title"],
    ["unavailable", "body"],
    ["columns", "name"],
    ["columns", "size"],
    ["columns", "usedBy"],
    ["columns", "attached"],
    ["networks", "title"],
    ["networks", "builtIn"],
    ["networks", "removeTitle"],
    ["networks", "removeBody"],
    ["networks", "removeBusy"],
    ["networks", "hint"],
    ["volumes", "title"],
    ["volumes", "inUse"],
    ["volumes", "removeTitle"],
    ["volumes", "removeBody"],
    ["volumes", "removeBusy"],
    ["volumes", "hint"],
  ];

  for (const locale of LOCALES) {
    for (const keyPath of needed) {
      const value = keyPath.reduce(
        (node, key) => node?.[key],
        messages[locale].docker,
      );
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
    assert.ok(
      busy.length > 40,
      `${locale}: removeBusy is too terse to explain the risk`,
    );
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
// The container's settings moved off the Dashboard onto their own sidebar screen —
// they were a full-width card under the domains and the backups, which is a long
// way from where anybody looks for "what is this container doing".
const containerPage = read(
  "app/(app)/applications/[application]/container/page.jsx",
);
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
  // Gated by the PERMISSION now, not by a profile string read in the page. The
  // grant is only issued for container site types, so the screen cannot exist for
  // anything else — and a PHP site reaching the URL is told no rather than shown an
  // empty card whose only outcome would be a 422.
  assert.match(
    containerPage,
    /can\(appPermissions, "app_container", "view", "application"\)/,
  );
  assert.match(containerPage, /<ContainerCard/);

  // And it is gone from the Dashboard, so there is one home for it.
  assert.doesNotMatch(appPage, /<ContainerCard/);
});

test("the empty network choice is one constant, not three strings", () => {
  // Radix refuses `value=""`, so "Docker's default bridge" needs a value of its
  // own — and the defaults, the item and the submit mapping have to agree on
  // it. They did not in the first version, and picking the default sent the
  // sentinel to the API as a network name.
  assert.match(card, /const DEFAULT_NETWORK = "__default__"/);
  const uses = card.match(/DEFAULT_NETWORK/g) ?? [];
  assert.ok(
    uses.length >= 4,
    `expected the constant to be used throughout, saw ${uses.length}`,
  );
  assert.doesNotMatch(card, /docker_network: values\.docker_network === ""/);
});

test("saving the card refreshes the server-rendered siblings", () => {
  // The Docker page's "used by" column and this site's own facts are both
  // server-rendered. Without a refresh they keep showing page-load state — and the
  // refresh has to be awaited BEFORE the toast, or "saved" covers the old state.
  assert.match(card, /await refreshAndWait\(\);\s*toast\.success\(/);
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
  assert.ok(
    schemas.includes(".regex(/^\\//)"),
    "path must be required absolute",
  );
  assert.ok(
    schemas.includes("(^|\\/)\\.\\.(\\/|$)"),
    "path must refuse traversal",
  );
});

test("the volumes list is offered only on a container site", () => {
  // Fetched by the container screen rather than the Dashboard, which no longer asks
  // the box for Docker objects it has nothing to render them in.
  assert.match(containerPage, /volumes=\{volumes\}/);
  assert.match(containerPage, /getDockerVolumes/);
  assert.doesNotMatch(appPage, /getDockerVolumes/);
});

test("a volume already mounted is still offered, at another path", () => {
  // One volume at two paths is legal Docker; only a repeated PATH is refused.
  // Filtering mounted volumes out of the chooser would forbid something valid.
  assert.match(volumesList, /alreadyMounted/);
});

test("add and remove save immediately and refresh", () => {
  // Each change recreates the container, so batching them into a Save button
  // would hide how many restarts one click is worth.
  assert.match(volumesList, /await refreshAndWait\(\);\s*toast\.success\(/);
  assert.match(volumesList, /updateContainerSettings/);
});

test("the server's refusal is shown where it was typed, not only as a toast", () => {
  // "That path is where the site's own files are" needs reading twice, and only
  // the server can say it — the rule needs to know what the compose file mounts.
  assert.match(volumesList, /setError\(/);
  assert.match(volumesList, /text-destructive/);
});

test("the volume schema declares sites, or Zod drops it", () => {
  assert.match(
    schemas,
    /sites: z\.array\(z\.object\(\{ id: z\.number\(\), name: z\.string\(\) \}\)\)/,
  );
});

test("every volumes-list string exists in every locale", () => {
  const reference = Object.keys(messages.en.applications.container.volumes);
  assert.ok(
    reference.length > 10,
    "expected the volumes block to be populated",
  );
  for (const locale of LOCALES) {
    assert.deepEqual(
      Object.keys(messages[locale].applications.container.volumes ?? {})
        .slice()
        .sort(),
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
      Object.keys(messages[locale].docker.attach ?? {})
        .slice()
        .sort(),
      reference.slice().sort(),
      `${locale} disagrees with en on docker.attach`,
    );
  }
});

/*
 * The generated credentials.
 *
 * Ghost's MySQL password and a one-click's signing keys were generated per site and
 * then existed only in a compose file and an encrypted column — correct, and
 * useless to whoever needs them to connect a client or debug the app. It also
 * unblocks apps whose ADMIN account comes from environment variables: a password
 * nobody can read is the same as no account.
 */

const credentials = read("components/applications/container-credentials.jsx");

test("credential values are not on the application payload", () => {
  // Only the KEY NAMES, so nothing is fetched, cached or re-rendered on a page
  // visit. The values have their own endpoint.
  const appSchema = read("lib/schemas/application.js");
  assert.match(appSchema, /container_secret_keys/);
  assert.doesNotMatch(appSchema, /container_secrets:/);
});

test("the values are fetched only when asked for", () => {
  assert.match(credentials, /getContainerSecrets/);
  // No effect that loads them on mount — revealing is a deliberate act.
  assert.doesNotMatch(credentials, /useEffect/);
});

test("the mask does not leak the length", () => {
  // A row of dots as long as the password tells an onlooker how long it is.
  assert.match(credentials, /••••/);
  assert.doesNotMatch(credentials, /repeat\(/);
});

test("the section is offered only to someone who can manage the site", () => {
  // The endpoint is gated on `manage`, so rendering it for anyone else offers a
  // button whose only outcome is 403.
  // Prettier wraps the ternary across lines, so match the guard and the component
  // separately rather than pinning one formatting of them.
  assert.match(card, /\{canManage \? \(/);
  assert.match(card, /<ContainerCredentials application=\{application\} \/>/);
});

test("nothing offers to rotate a credential from here", () => {
  // Rotating means rewriting the compose file AND the credential inside the running
  // database; leaving those disagreeing is how a site comes back up unable to reach
  // its own data.
  assert.doesNotMatch(credentials, /rotate|regenerate/i);
});

test("every credentials string exists in every locale", () => {
  const reference = Object.keys(messages.en.applications.container.credentials);
  for (const locale of LOCALES) {
    assert.deepEqual(
      Object.keys(messages[locale].applications.container.credentials ?? {})
        .slice()
        .sort(),
      reference.slice().sort(),
      `${locale} disagrees with en on applications.container.credentials`,
    );
  }
});

/*
 * Paths in the volumes table, and deleting a site's Docker objects.
 */

const deleteDialog = read(
  "components/applications/delete-application-dialog.jsx",
);

test("the volumes table shows where the volume is on disk", () => {
  // Wanted for the unglamorous reasons: rsyncing it elsewhere, checking what is
  // actually on disk, pointing a support answer at a directory.
  assert.match(panel, /columns\.path/);
  assert.match(panel, /volume\.mountpoint/);
  for (const locale of LOCALES) {
    assert.ok(
      messages[locale].docker.columns.path,
      `${locale} is missing docker.columns.path`,
    );
  }
});

test("the host path truncates from the left", () => {
  // Every mountpoint starts `/var/lib/docker/volumes/`, so truncating from the
  // right hides the only part that differs between rows.
  assert.match(panel, /dir="rtl"/);
});

test("the volumes table says which site mounts it, and where", () => {
  // A container name says which process holds the volume; `alpha → /var/lib/mysql`
  // says what the volume is.
  assert.match(panel, /volume\.sites\.map/);
  assert.match(panel, /site\.path/);
  const schemas = read("lib/schemas/docker.js");
  assert.match(schemas, /path: z\.string\(\)\.default\(""\)/);
});

test("deleting a site offers to remove its Docker objects, opt-in", () => {
  // Off by default and only when there is something to remove — the same rule the
  // databases checkbox follows.
  assert.match(deleteDialog, /useState\(false\)/);
  assert.match(deleteDialog, /dockerResourceNames\.length \?/);
  assert.match(deleteDialog, /removeDockerResources/);
});

test("the dialog names what will go, rather than counting it", () => {
  // "Also delete 2 volumes" is a promise the reader cannot check; the names are.
  assert.match(deleteDialog, /names: dockerResourceNames\.join\(", "\)/);
  for (const locale of LOCALES) {
    const d = messages[locale].applications.delete;
    for (const key of ["removeDocker", "removeDockerOn", "removeDockerOff"]) {
      assert.ok(d[key], `${locale} is missing applications.delete.${key}`);
    }
  }
});

test("the flag is omitted when not asked for", () => {
  // A delete carrying no destructive flag at all is the one you want in a request
  // log — the same treatment the other two flags get.
  const api = read("lib/api/applications.js");
  assert.match(
    api,
    /if \(removeDockerResources\) params\.remove_docker_resources = true;/,
  );
});

/*
 * The Docker create form: a choice between two modes, not both at once.
 */

const createForm = read("components/applications/create-application-form.jsx");

test("a field can depend on another field's value", () => {
  // A general extension of the existing named `depends_on` conditions, not a
  // Docker special case — and watched rather than read once, so the form reacts as
  // soon as the mode changes.
  assert.match(createForm, /depends_on: "<field>:<value>"/);
  assert.match(createForm, /dependsOnSatisfied/);
  assert.match(createForm, /useWatch\(\{ control: form\.control \}\)/);
});

test("an unparseable depends_on does not hide the field", () => {
  // The existing named conditions ("rendering_type", "node_rendering") have no
  // colon, so the new check has to pass them through — defaulting to hidden would
  // have silently emptied the Git card.
  assert.match(createForm, /!dependsOn\.includes\(":"\)\) return true/);
});

/*
 * The CPU and memory limit fields.
 *
 * The field is easy; the instruction is the part that was asked for and the part
 * that is easy to get wrong. Two things have to be true of it and neither is
 * visible from the markup alone:
 *
 *  - The ceiling shown has to be THIS server's core count, read from the API. A
 *    hardcoded number is wrong on every box but the one it was written on, and a
 *    sentence describing the rule ("no more than this server has") cannot be acted
 *    on without leaving the page.
 *  - Empty means different things in the two fields. An empty memory field falls
 *    back to the configured default; an empty CPU field means no limit at all.
 *    A form that implied they were the same would be lying about one of them.
 */

const limitsFetch = read("lib/docker/get-docker.js");

test("the cpu field is bounded by what the API says the box has", () => {
  // Read, not assumed — and the fallback drops the number rather than guessing
  // one, because "this server has 1 CPU" under a field on a sixteen-core machine
  // is the confident kind of wrong.
  assert.match(
    card,
    /limits\.cpus\s*\n?\s*\?\s*t\("cpuLimitHint", \{ cores: limits\.cpus \}\)/,
  );
  assert.match(card, /: t\("cpuLimitHintUnknown"\)/);
  assert.match(limitsFetch, /cpus: null/);
  assert.match(containerPage, /getDockerLimits\(\)/);
});

test("the two fields do not claim the same thing about being empty", () => {
  // The whole reason both hints are spelled out rather than sharing one.
  const en = messages.en.applications.container;
  assert.match(en.memoryLimitHint, /server default/);
  assert.match(en.cpuLimitHint, /no limit/);
  assert.doesNotMatch(en.cpuLimitHint, /default/);
  // And the placeholder says it too, because a placeholder is what people read
  // before they read a description.
  assert.equal(en.cpuLimitPlaceholder, "No limit");
});

test("an emptied cpu field reaches the API as null, not as an empty string", () => {
  // Otherwise a limit once set could never be removed except by deleting the
  // site — the field would be one-way.
  assert.match(
    card,
    /cpu_limit: values\.cpu_limit === "" \? null : values\.cpu_limit/,
  );
});

test("the hint names the unit trap rather than only the format", () => {
  // `512` is bytes to Docker, not megabytes, and it used to save fine and
  // produce a container that would not start. The server refuses it now; the
  // hint is what stops somebody typing it.
  assert.match(
    messages.en.applications.container.memoryLimitHint,
    /bytes, not megabytes/,
  );
});

test("the note explains how the two limits fail, which is the part nobody knows", () => {
  // Over the memory ceiling the container is killed; over the CPU quota it
  // waits. Somebody debugging one while thinking of the other gets nowhere.
  const body = messages.en.applications.container.limitsBody;
  assert.match(body, /killed/);
  assert.match(body, /slow/);
  // Ceilings, not reservations — the misreading that makes people over-provision.
  assert.match(body, /not reservations/);
});

test("every locale carries a cpu hint with the cores placeholder", () => {
  // A translation that dropped `{cores}` would render a sentence promising a
  // number and showing none.
  for (const locale of LOCALES) {
    const container = messages[locale].applications.container;
    assert.match(
      container.cpuLimitHint,
      /\{cores\}/,
      `${locale} cpuLimitHint lost the cores placeholder`,
    );
    assert.match(
      container.memoryLimitHint,
      /\{default\}/,
      `${locale} memoryLimitHint lost the default placeholder`,
    );
  }
});

/*
 * The generated credentials, shown once where the user lands.
 *
 * A one-click container app generates its own admin password and the only route to
 * it was a Reveal button two clicks into the site. A password generated and never
 * read is an account nobody can sign into — and these cannot be rotated from the
 * panel, because changing one means rewriting the compose file AND the credential
 * inside the running database.
 *
 * So the card's contract is unusual and worth pinning: it SHOWS the values rather
 * than masking them, and it is dismissed only by a person saying so.
 */

const firstRun = read("components/applications/first-run-credentials.jsx");
const appDashboard = read("app/(app)/applications/[application]/page.jsx");

test("the first-run card fetches the values instead of hiding them behind a button", () => {
  // The opposite contract from `ContainerCredentials`, on purpose: there the
  // values are masked because that screen is somewhere you return to, and here the
  // entire point is that the first view happens.
  assert.match(firstRun, /useEffect\(/);
  assert.match(firstRun, /getContainerSecrets\(application\.id\)/);
  // No reveal affordance: no `reveal()` handler and no Eye icon, which is what
  // `ContainerCredentials` uses to gate its fetch behind a click. Asserted on the
  // control rather than on the word "Reveal" — this file's own docblock says the
  // values stay available under Container → Reveal, and the first version of this
  // test failed on its own prose.
  assert.doesNotMatch(firstRun, /function reveal\(/);
  assert.doesNotMatch(firstRun, /\bEye\b/);
});

test("it is dismissed only by an explicit acknowledgement", () => {
  // Never as a side effect of rendering. If showing the card marked it seen, a
  // refresh before somebody finished copying would lose an unrecoverable password.
  assert.match(firstRun, /acknowledgeContainerSecrets\(application\.id\)/);
  // And the acknowledgement is a POST of its own, not a flag on the read.
  assert.match(read("lib/api/docker.js"), /container\/secrets\/acknowledge/);
});

test("the dismiss button waits for the values to be on screen", () => {
  // A dismiss beside a row of spinners invites the one click that cannot be undone.
  assert.match(firstRun, /disabled=\{saving \|\| !secrets\}/);
});

test("dismissing refreshes the server-rendered dashboard", () => {
  // Visibility comes from a server-rendered field, so without this the card sits
  // there after a successful dismiss until the next navigation.
  assert.match(firstRun, /router\.refresh\(\)/);
});

test("the card is gated on the permission its own request needs", () => {
  // It fetches the secrets, and that endpoint is `app_container` manage — offering
  // the card to anyone else is a card whose only outcome is a 403.
  assert.match(
    appDashboard,
    /can\(appPermissions, "app_container", "manage", "application"\)/,
  );
  assert.match(appDashboard, /!application\.credentials_acknowledged/);
});

test("an unknown acknowledgement state hides the card rather than showing it", () => {
  // The safe direction. A missing field must not put a card full of passwords on
  // the dashboard of a site that has been running for a year.
  const schema = read("lib/schemas/application.js");
  assert.match(schema, /credentials_acknowledged: z/);
  assert.match(schema, /\.transform\(\(seen\) => seen \?\? true\)/);
});

test("a load failure says so instead of rendering an empty card", () => {
  // The one thing the user must not conclude is that there was nothing to save.
  assert.match(
    firstRun,
    /setError\(apiMessage\(requestError, t\("failed"\)\)\)/,
  );
  assert.match(
    messages.en.applications.container.firstRun.failed,
    /Don't dismiss this/,
  );
});

test("every first-run string exists in every locale", () => {
  const reference = Object.keys(messages.en.applications.container.firstRun);
  for (const locale of LOCALES) {
    const keys = Object.keys(
      messages[locale].applications.container.firstRun ?? {},
    );
    assert.deepEqual(
      keys.slice().sort(),
      reference.slice().sort(),
      `${locale} disagrees with en on applications.container.firstRun`,
    );
  }
});

/*
 * A container site's size is mostly not in its directory.
 */

test("the dashboard explains what share of the size is in volumes", () => {
  // A total with no breakdown is unexplainable: 284 MB against a document root the
  // File Manager shows as almost empty reads as a bug in the panel.
  const facts = read("components/applications/site-facts-card.jsx");

  assert.match(facts, /application\.volume_size_bytes/);
  assert.match(facts, /t\("size\.inVolumes", \{ size: volumeSize \}\)/);
  // Null, not 0, means "no volumes to measure" — so a PHP site gets no second
  // line rather than "0 B in volumes".
  assert.match(
    facts,
    /=== null \|\|\s*\n?\s*application\.volume_size_bytes === undefined/,
  );
  // And nothing is said until there is a measurement to break down.
  assert.match(facts, /size && volumeSize \?/);
});

test("the size breakdown is declared in the schema, or Zod drops it", () => {
  assert.match(
    read("lib/schemas/application.js"),
    /volume_size_bytes: z\.number\(\)\.nullish\(\)/,
  );
});

test("every size string exists in every locale", () => {
  const reference = Object.keys(messages.en.applications.size);
  for (const locale of LOCALES) {
    const keys = Object.keys(messages[locale].applications.size ?? {});
    assert.deepEqual(
      keys.slice().sort(),
      reference.slice().sort(),
      `${locale} disagrees with en on applications.size`,
    );
    assert.match(
      messages[locale].applications.size.inVolumes,
      /\{size\}/,
      `${locale} inVolumes lost the size placeholder`,
    );
  }
});

/*
 * 🔴 Every `application.<field>` a container screen reads must be declared in the
 * schema, or Zod strips it and the screen renders its own fallback.
 *
 * Found in a browser, not here: the Container screen showed "No image recorded",
 * "None — pull anonymously", port 80 and two empty limit fields for a site whose row
 * held `nginx:1.27-alpine`, port 80, `192m` and `0.5`. `image`, `container_port`,
 * `memory_limit`, `cpu_limit` and `registry_id` were all being sent by the API and all
 * being dropped by `applicationSchema`, which does not passthrough.
 *
 * **And the form saved the blanks back.** Pressing Save with nothing meaningfully
 * changed took `memory_limit` from `192m` to NULL and `cpu_limit` from `0.5` to NULL.
 *
 * The existing tests could not see it: they assert the card is handed
 * `application.memory_limit` — which it is. The value dies a layer earlier. This test
 * closes that layer by reading the fields out of the component and checking each one
 * against the schema, so the next field added to a container screen cannot repeat it.
 */

test("the schema declares every application field the container screens read", () => {
  const schema = read("lib/schemas/application.js");
  const start = schema.indexOf("export const applicationSchema = z.object({");
  const end = schema.indexOf("\n});", start);
  const block = schema.slice(start, end);
  const declared = new Set(
    [...block.matchAll(/^ {2}([a-z_][a-z0-9_]*)\s*:/gm)].map((m) => m[1]),
  );

  const sources = [
    "components/applications/container-card.jsx",
    "components/applications/container-volumes.jsx",
    "components/applications/container-credentials.jsx",
    "components/applications/compose-editor.jsx",
  ];

  const missing = new Set();
  for (const file of sources) {
    for (const m of read(file).matchAll(/\bapplication\.([a-z_][a-z0-9_]*)/g)) {
      // `id` and `slug` are declared; anything genuinely absent is the bug.
      if (!declared.has(m[1])) missing.add(`${file.split("/").pop()}:${m[1]}`);
    }
  }

  assert.deepEqual(
    [...missing],
    [],
    "undeclared fields are stripped by Zod before the screen sees them",
  );
});

test("the container fields are declared, by name", () => {
  // Spelled out as well as derived: the sweep above only protects fields something
  // currently reads, and these five are the ones that were lost.
  const schema = read("lib/schemas/application.js");
  for (const field of [
    "image",
    "container_port",
    "memory_limit",
    "cpu_limit",
    "registry_id",
  ]) {
    assert.match(
      schema,
      new RegExp(`^ {2}${field}: z\\.`, "m"),
      `${field} is not declared`,
    );
  }
});
