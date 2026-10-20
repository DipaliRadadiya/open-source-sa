import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";

const root = path.join(import.meta.dirname, "..");
const read = (p) => fs.readFileSync(path.join(root, p), "utf8");

// The Container settings card is one <form>; anything rendered inside it must not
// open another one (React #418 on the live Container page, 19 Oct).
test("nothing inside the Container settings form opens its own form", () => {
  assert.match(read("components/applications/container-card.jsx"), /<form noValidate onSubmit=\{form\.handleSubmit\(save\)\}>/);
  for (const file of ["components/applications/container-volumes.jsx", "components/applications/container-credentials.jsx"]) {
    assert.doesNotMatch(read(file), /<form\b/, file);
  }
});

test("Enter in the mount path adds the mount instead of saving the settings", () => {
  const volumes = read("components/applications/container-volumes.jsx");
  assert.match(volumes, /if \(event\.key !== "Enter"\) return;\s*event\.preventDefault\(\);/);
  assert.match(volumes, /type="button"\s*onClick=\{add\}/);
});

// With no network chosen, Compose puts the site on its own `sv-app-{id}_default`,
// not Docker's default bridge; and Docker's own or another application's network
// is not something to join from here (19 Oct).
test("the network chooser names the site's own network and offers only shared ones", () => {
  const card = read("components/applications/container-card.jsx");
  assert.doesNotMatch(card, /defaultBridge/);
  assert.match(card, /\(!network\.built_in && !network\.application_id\) \|\|\s*network\.name === defaults\.docker_network/);
  assert.match(card, /\{joinable\.map\(\(network\) => \(/);
  assert.match(card, /\{joinable\.length === 0 \? \(/);
});

// A container's output is Docker's: the API refuses its download (422) and its
// clear changes nothing, so neither is offered (20 Oct).
test("container logs offer neither Download nor Clear, and the failure panel opens them", () => {
  const panel = read("components/applications/logs/application-logs-panel.jsx");
  assert.match(panel, /downloadUrl=\{source\?\.kind === "file" \?/);
  assert.match(panel, /showDownload=\{source\?\.kind === "file"\}/);
  assert.match(panel, /onClear=\{canManage && source\?\.kind === "file" \?/);
  assert.match(read("components/applications/container-failure-panel.jsx"), /logs\?source=container`/);
});

// A compose file the user owns is what runs; port, limits, network and volumes
// saved on the Container page answered "saved" and changed nothing (20 Oct).
test("an application on its own compose file gets a pointer, not inert fields", () => {
  const card = read("components/applications/container-card.jsx");
  assert.match(card, /\{ownCompose \? \(\s*<Note icon=\{FileCode\} title=\{t\("ownCompose\.title"\)\}>/);
  const page = read("app/(app)/applications/[application]/container/page.jsx");
  assert.match(page, /const ownCompose = Boolean\(compose && !compose\.failed && compose\.generated === false\);/);
  assert.match(page, /ownCompose=\{ownCompose\}/);
});

// "Add registry" opened on Docker Hub with an empty, read-only address (20 Oct).
test("a new registry credential opens with Docker Hub's preset filled in", () => {
  const card = read("components/docker/registries-card.jsx");
  assert.match(card, /name: registry\?\.name \?\? t\("providers\.dockerhub\.label"\),/);
  assert.match(card, /registry: registry\?\.registry \?\? registryProvider\("dockerhub"\)\.address,/);
});

// A refused login is a 200 with success:false; and an empty token on edit means
// "keep", which the token rule used to reject so Save did nothing (20 Oct).
test("registry test reads the outcome, and edit keeps the stored token", () => {
  const card = read("components/docker/registries-card.jsx");
  assert.match(card, /if \(data\?\.success\) toast\.success\(t\("testPassed"/);
  assert.match(card, /else toast\.error\(t\(`errors\.\$\{data\?\.registry\?\.last_test_error \?\? "unknown"\}`\)\)/);
  assert.match(card, /!creating && form\.token === "" \? \{ \.\.\.form, token: undefined \} : form/);
});

// Attaching from the Docker page to an application on its own compose file was
// stored and answered 200, and the container never got the mount (20 Oct).
test("the Docker page offers attaching only to applications on a generated compose file", () => {
  const page = read("app/(app)/docker/page.jsx");
  assert.match(page, /serving\.map\(\(application\) => getApplicationCompose\(application\.id\)\)/);
  assert.match(page, /!\(composes\[index\]\.failed === false && composes\[index\]\.generated === false\)/);
});

// An application's own network/volume is its private one; attaching another
// application to it from the Docker page breaks that isolation (20 Oct).
test("the Docker page does not offer attaching to an application's own network or volume", () => {
  const panel = read("components/docker/docker-resources-panel.jsx");
  assert.match(panel, /canManageSites && !network\.application_id \? \(/);
  assert.match(panel, /canManageSites && !volume\.application_id \? \(/);
});
