import { afterEach, before, beforeEach, test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { installDom, loadComponent } from "./support/dom.mjs";
import { setDockerSource } from "../lib/docker/create-request.js";

installDom();
const { createElement } = await import("react");
const { cleanup, fireEvent, render, screen, waitFor } = await import("@testing-library/react");
const { siteTypeSchema } = await import("../lib/schemas/application.js");
const messages = JSON.parse(readFileSync("messages/en.json", "utf8"));
const mode = messages.applications.dockerModes;
let harness;
before(async () => {
  // Unique entry: concurrent suites cannot overwrite the module being imported.
  harness = await loadComponent("tests/support/docker-modes-harness.jsx", {
    "@/lib/api/docker": "tests/support/docker-api-stub.js",
    "@/lib/api/applications": "tests/support/applications-api-stub.js",
    "@/components/ui/app-link": "tests/support/app-link-stub.jsx",
    "next/navigation": "tests/support/next-navigation-stub.js",
  });
});
beforeEach(() => {
  globalThis.__dockerApiCalls = {};
  globalThis.__dockerApi = {
    search: async () => ({ data: { results: [] } }),
    tags: async () => ({ data: { tags: [{ name: "1.2.3" }], recommended: "1.2.3" } }),
    inspect: async () => ({ data: { found: true, suggested_port: 3000, port_confidence: "declared", env: [{ key: "CUSTOM", default: "original", required: false }] } }),
  };
});
afterEach(cleanup);

const DOCKER = siteTypeSchema.parse({
  name: "docker", title: "Docker", method: "docker", recipe: null,
  fields: [
    { name: "docker_mode", label: "Mode", type: "select", default: "simple", options: [{ value: "simple", label: "Image" }, { value: "compose", label: "Compose" }] },
    { name: "image", label: "Image", type: "text", required: true, depends_on: "docker_mode:simple" },
    { name: "container_port", label: "Container port", type: "number", depends_on: "docker_mode:simple" },
    { name: "registry_id", label: "Registry login", type: "select", options: [{ value: "7", label: "Private registry fixture" }], depends_on: "docker_mode:simple" },
    { name: "compose", label: "Compose file", type: "textarea", required: true, depends_on: "docker_mode:compose" },
  ],
});
const GHOST = siteTypeSchema.parse({ name: "ghost", title: "Ghost", method: "one_click", popular: true, fields: [], recipe: { slug: "ghost", version: 1, first_run: { kind: "setup_wizard" } } });
const GHOST_SECOND = siteTypeSchema.parse({ ...GHOST, name: "ghost_second", title: "Ghost Second", recipe: { ...GHOST.recipe, slug: "ghost_second" } });
function renderForm(props = {}) {
  harness.sent.length = 0;
  render(createElement(harness.CreateFormHarness, {
    messages, siteTypes: [DOCKER, GHOST], initialType: "docker", initialName: "modeapp",
    systemUsers: [{ id: 1, username: "modeapp" }], ...props,
  }));
}
function changeTab(name) {
  fireEvent.mouseDown(screen.getByRole("tab", { name, exact: true }), { button: 0, ctrlKey: false });
}
function changeMode(name) { fireEvent.click(screen.getByRole("radio", { name, exact: true })); }
const searchInput = () => screen.getByRole("combobox", { name: /^Docker image/ });
const manualInput = () => screen.getByRole("textbox", { name: /^Image reference/ });
async function fillDetails() {
  fireEvent.change(screen.getByRole("textbox", { name: /Domain/ }), { target: { value: "modeapp.example.com" } });
  fireEvent.click(screen.getByRole("combobox", { name: "System user" }));
  fireEvent.click(screen.getByRole("option", { name: "modeapp" }));
}
async function submit() {
  await waitFor(() => assert.equal(screen.getAllByRole("button", { name: "Create application" })[0].disabled, false), { timeout: 5000 });
  fireEvent.click(screen.getAllByRole("button", { name: "Create application" })[0]);
  await waitFor(() => assert.equal(harness.sent.length, 1));
  return harness.sent[0];
}
async function chooseManual(reference = "ghcr.io/acme/app:1.2.3") {
  fireEvent.change(manualInput(), { target: { value: reference } });
  fireEvent.click(screen.getByRole("button", { name: mode.manualUse }));
  await screen.findByRole("textbox", { name: "Value for CUSTOM" });
}

for (const source of ["search", "compose", "manual"]) {
  test(`setDockerSource maps ${source} to its backend mode and marks both fields dirty`, () => {
    const writes = [];
    setDockerSource({ setValue: (...args) => writes.push(args) }, source);
    assert.deepEqual(writes, [
      ["docker_source", source, { shouldDirty: true }],
      ["docker_mode", source === "compose" ? "compose" : "simple", { shouldDirty: true }],
    ]);
  });
}

test("Search is the default, and its first local Ghost hint selects the recipe and One-click tab", async () => {
  renderForm({ siteTypes: [DOCKER, GHOST, GHOST_SECOND] });
  assert.equal(screen.getByRole("radio", { name: mode.search }).getAttribute("aria-checked"), "true");
  assert.ok(screen.getByRole("radiogroup", { name: mode.label }));
  fireEvent.change(searchInput(), { target: { value: "  gHoSt  " } });
  assert.ok(screen.getByText(messages.applications.recipes.recipeAvailable.replace("{title}", "Ghost")));
  assert.equal(screen.queryByRole("button", { name: "Use Ghost Second in 1-Click Recipes" }), null);
  fireEvent.click(screen.getByRole("button", { name: "Use Ghost in 1-Click Recipes", exact: true }));
  await waitFor(() => assert.equal(screen.getByRole("tab", { name: "1-Click Recipes" }).getAttribute("aria-selected"), "true"));
  assert.ok(screen.getByRole("heading", { name: "What you get" }));
  await fillDetails();
  const body = await submit();
  assert.equal(body.site_type, "ghost");
  assert.equal("docker_source" in body, false);
  assert.equal("image" in body, false);
});

test("local recipe hint needs two characters, accepts title prefixes, and leaves unmatched search alone", () => {
  renderForm();
  fireEvent.change(searchInput(), { target: { value: "g" } });
  assert.equal(screen.queryByRole("button", { name: /in 1-Click Recipes/ }), null);
  fireEvent.change(searchInput(), { target: { value: "gh" } });
  assert.ok(screen.getByRole("button", { name: "Use Ghost in 1-Click Recipes" }));
  fireEvent.change(searchInput(), { target: { value: "nginx" } });
  assert.equal(screen.queryByRole("button", { name: /in 1-Click Recipes/ }), null);
});

test("clicking the active mode cannot deselect it", () => {
  renderForm();
  changeMode(mode.search);
  assert.equal(screen.getByRole("radio", { name: mode.search }).getAttribute("aria-checked"), "true");
  assert.ok(searchInput());
});

test("Compose sends compose and docker_mode only, never a stale image or docker_source", async () => {
  renderForm();
  changeMode(mode.compose);
  const compose = "services:\n  app:\n    image: nginx:alpine\n";
  fireEvent.change(await screen.findByRole("textbox", { name: /^Compose file/ }), { target: { value: compose } });
  assert.equal(screen.queryByRole("combobox", { name: /^Docker image/ }), null);
  await fillDetails();
  const body = await submit();
  assert.equal(body.docker_mode, "compose");
  assert.equal(body.compose, compose);
  for (const key of ["image", "container_port", "docker_source", "docker_search_query"]) assert.equal(key in body, false, key);
});

test("Manual opens registry credentials, validates the reference, then tags/inspects without ever searching", async () => {
  renderForm();
  changeMode(mode.manual);
  assert.ok(screen.getByRole("combobox", { name: messages.applications.dockerImage.privateRegistry }));
  assert.equal(screen.queryByRole("listbox"), null);
  assert.equal(screen.queryByRole("button", { name: "memos", exact: true }), null);
  fireEvent.change(manualInput(), { target: { value: "bad ref" } });
  fireEvent.click(screen.getByRole("button", { name: mode.manualUse }));
  assert.ok(await screen.findByText(mode.manualInvalid));
  assert.equal(manualInput().getAttribute("aria-invalid"), "true");
  assert.equal(globalThis.__dockerApiCalls.tags ?? 0, 0);
  assert.equal(globalThis.__dockerApiCalls.inspect ?? 0, 0);
  await chooseManual("  ghcr.io/acme/app:1.2.3  ");
  await fillDetails();
  const body = await submit();
  assert.equal(body.image, "ghcr.io/acme/app:1.2.3");
  assert.equal(body.docker_mode, "simple");
  assert.equal(body.container_port, 3000);
  assert.ok(globalThis.__dockerApiCalls.tags > 0);
  assert.ok(globalThis.__dockerApiCalls.inspect > 0);
  assert.equal(globalThis.__dockerApiCalls.search ?? 0, 0);
  assert.equal("docker_source" in body, false);
  assert.equal("compose" in body, false);
});

test("Manual uses the saved registry credential for inspection and the simple create request", async () => {
  const inspected = [];
  globalThis.__dockerApi.inspect = async (reference, options) => {
    inspected.push({ reference, registryId: options.registryId });
    return { data: { found: true, suggested_port: 3000, port_confidence: "declared", env: [{ key: "CUSTOM", default: "original" }] } };
  };
  renderForm({ initialDockerSource: "manual" });
  fireEvent.click(screen.getByRole("combobox", { name: messages.applications.dockerImage.privateRegistry }));
  fireEvent.click(screen.getByRole("option", { name: "Private registry fixture" }));
  await chooseManual();
  assert.deepEqual(inspected, [{ reference: "ghcr.io/acme/app:1.2.3", registryId: "7" }]);
  await fillDetails();
  const body = await submit();
  assert.equal(body.registry_id, "7");
  assert.equal(body.docker_mode, "simple");
  assert.equal(globalThis.__dockerApiCalls.search ?? 0, 0);
});

test("Manual URL props start with no Hub search, and Enter uses the image without submitting", async () => {
  renderForm({ initialDockerSource: "manual" });
  assert.equal(screen.getByRole("radio", { name: mode.manual }).getAttribute("aria-checked"), "true");
  fireEvent.change(manualInput(), { target: { value: "ghcr.io/acme/app:1.2.3" } });
  fireEvent.keyDown(manualInput(), { key: "Enter" });
  await screen.findByRole("textbox", { name: "Value for CUSTOM" });
  assert.equal(harness.sent.length, 0);
  assert.equal(globalThis.__dockerApiCalls.search ?? 0, 0);
});

test("empty manual input is invalid and editing it clears the inline error", async () => {
  renderForm({ initialDockerSource: "manual" });
  fireEvent.click(screen.getByRole("button", { name: mode.manualUse }));
  assert.ok(await screen.findByText(mode.manualInvalid));
  fireEvent.change(manualInput(), { target: { value: "ghcr.io/acme/app:1.2.3" } });
  assert.equal(screen.queryByText(mode.manualInvalid), null);
});

test("moving to Manual cancels a debounced search, hides recipe hints and opens the registry section", async () => {
  renderForm();
  fireEvent.change(searchInput(), { target: { value: "ghost" } });
  changeMode(mode.manual);
  assert.equal(screen.queryByRole("button", { name: /in 1-Click Recipes/ }), null);
  assert.equal(screen.queryByRole("listbox"), null);
  assert.ok(screen.getByRole("combobox", { name: messages.applications.dockerImage.privateRegistry }));
  await new Promise((resolve) => setTimeout(resolve, 350));
  assert.equal(globalThis.__dockerApiCalls.search ?? 0, 0);
});

test("changing modes keeps a chosen image and edited settings without inspecting again", async () => {
  renderForm({ initialDockerSource: "manual" });
  await chooseManual();
  fireEvent.change(screen.getByRole("textbox", { name: "Value for CUSTOM" }), { target: { value: "user setting" } });
  const inspections = globalThis.__dockerApiCalls.inspect;
  changeMode(mode.search);
  assert.equal(screen.getByRole("textbox", { name: "Value for CUSTOM" }).value, "user setting");
  changeMode(mode.manual);
  assert.equal(screen.getByRole("textbox", { name: "Value for CUSTOM" }).value, "user setting");
  assert.equal(globalThis.__dockerApiCalls.inspect, inspections);
});

test("Compose survives a recipe tab round-trip even though type reset unregisters backend fields", async () => {
  renderForm({ initialType: "ghost" });
  changeTab("Container");
  changeMode(mode.compose);
  changeTab("1-Click Recipes");
  assert.ok(screen.getByRole("heading", { name: "What you get" }));
  changeTab("Container");
  assert.equal(screen.getByRole("radio", { name: mode.compose }).getAttribute("aria-checked"), "true");
  assert.ok(await screen.findByRole("textbox", { name: /^Compose file/ }));
  assert.equal(screen.queryByRole("combobox", { name: /^Docker image/ }), null);
});

test("the old Use compose and Use an image links update the visible mode switch too", async () => {
  renderForm();
  fireEvent.click(screen.getByRole("button", { name: messages.applications.dockerImage.useCompose }));
  assert.equal(screen.getByRole("radio", { name: mode.compose }).getAttribute("aria-checked"), "true");
  fireEvent.click(screen.getByRole("button", { name: messages.applications.dockerImage.useImage }));
  assert.equal(screen.getByRole("radio", { name: mode.search }).getAttribute("aria-checked"), "true");
  assert.ok(searchInput());
});

test("manual discovery failure keeps the existing explicit-port fallback with no Hub call", async () => {
  globalThis.__dockerApi.inspect = async () => { throw new Error("offline fixture"); };
  renderForm({ initialDockerSource: "manual" });
  fireEvent.change(manualInput(), { target: { value: "ghcr.io/acme/app:1.2.3" } });
  fireEvent.click(screen.getByRole("button", { name: mode.manualUse }));
  const port = await screen.findByRole("spinbutton", { name: /Port/ });
  assert.equal(port.value, "");
  assert.ok(screen.getByText(messages.applications.dockerImage.inspectUnavailableTitle));
  fireEvent.change(port, { target: { value: "8080" } });
  await fillDetails();
  const body = await submit();
  assert.equal(body.container_port, 8080);
  assert.equal(body.docker_mode, "simple");
  assert.equal(globalThis.__dockerApiCalls.search ?? 0, 0);
});
