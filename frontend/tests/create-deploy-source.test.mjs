import { afterEach, before, beforeEach, test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { installDom, loadComponent } from "./support/dom.mjs";

installDom();
const { createElement } = await import("react");
const { cleanup, fireEvent, render, screen, waitFor } = await import("@testing-library/react");
const { siteTypeSchema, siteTypesResponseSchema } = await import("../lib/schemas/application.js");
const messages = JSON.parse(readFileSync("messages/en.json", "utf8"));
let harness;
let pickerHarness;
const stubs = {
  "@/lib/api/docker": "tests/support/docker-api-stub.js",
  "@/lib/api/applications": "tests/support/applications-api-stub.js",
  "@/components/ui/app-link": "tests/support/app-link-stub.jsx",
  "next/navigation": "tests/support/next-navigation-stub.js",
};
before(async () => {
  harness = await loadComponent("tests/support/deploy-source-harness.jsx", stubs);
  pickerHarness = harness;
});
beforeEach(() => {
  globalThis.__dockerApi = {
    search: async () => ({ data: { results: [] } }),
    tags: async () => ({ data: { tags: [{ name: "1.0" }], recommended: "1.0" } }),
    inspect: async () => ({ data: { found: true, suggested_port: 3000, port_confidence: "declared" } }),
  };
});
afterEach(cleanup);

const DOCKER = siteTypeSchema.parse({
  name: "docker", title: "Docker container", method: "docker", recipe: null,
  fields: [
    { name: "docker_mode", label: "Mode", type: "select", default: "simple", options: [{ value: "simple", label: "Image" }, { value: "compose", label: "Compose" }] },
    { name: "image", label: "Image", type: "text", depends_on: "docker_mode:simple" },
    { name: "container_port", label: "Container port", type: "number", default: 80, depends_on: "docker_mode:simple" },
    { name: "compose", label: "Compose file", type: "textarea", required: true, depends_on: "docker_mode:compose" },
  ],
});
function recipeType(name, extra = {}) {
  return siteTypeSchema.parse({
    name, title: name === "ghost" ? "Ghost" : "Vaultwarden", tagline: `${name} application`,
    popular: true, category: name === "ghost" ? "cms" : "tools", method: "one_click", fields: [],
    recipe: {
      slug: name, version: 1,
      services: [{ name, role: "app" }, { name: "db", role: "database" }],
      volumes: [{ role: "content", path: "/var/lib/ghost/content" }, { role: "db", path: "/var/lib/mysql" }],
      images: [{ role: "app", ref: "ghost:5-alpine", tested: ["5.100.0"] }],
      memory_floor: "1g", first_run: { kind: "setup_wizard" }, docs_url: "https://ghost.org/docs/",
      ...extra,
    },
  });
}
const GHOST = recipeType("ghost");
const VAULTWARDEN = recipeType("vaultwarden");
const TYPES = [DOCKER, GHOST, VAULTWARDEN];
const PHP = siteTypeSchema.parse({ name: "php", title: "Custom PHP", method: "custom", fields: [] });
function renderForm(props = {}) {
  harness.sent.length = 0;
  render(createElement(harness.CreateFormHarness, { messages, siteTypes: TYPES, initialName: "recipeapp", systemUsers: [{ id: 1, username: "recipeapp" }], ...props }));
}
function renderPicker(props = {}) {
  render(createElement(pickerHarness.DeploySourceHarness, { messages, siteTypes: TYPES, ...props }));
}
function chooseTab(name) {
  fireEvent.mouseDown(screen.getByRole("tab", { name, exact: true }), { button: 0, ctrlKey: false });
}
function pickerValues() { return JSON.parse(screen.getByTestId("deploy-values").textContent); }
async function fillDetails() {
  fireEvent.change(screen.getByRole("textbox", { name: /Domain/ }), { target: { value: "recipeapp.example.com" } });
  fireEvent.click(screen.getByRole("combobox", { name: "System user" }));
  fireEvent.click(screen.getByRole("option", { name: "recipeapp" }));
}
async function submit() {
  // Readiness removes the disabled-reason wrapper and replaces its button.
  // Re-query instead of waiting on the disconnected, permanently disabled node.
  await waitFor(() => assert.equal(screen.getAllByRole("button", { name: "Create application" })[0].disabled, false), { timeout: 5000 });
  fireEvent.click(screen.getAllByRole("button", { name: "Create application" })[0]);
  await waitFor(() => assert.equal(harness.sent.length, 1));
  return harness.sent[0];
}

test("Docker server defaults to One-click recipes and offers no Docker container card", () => {
  renderForm();
  assert.equal(screen.getByRole("tab", { name: "One-click apps" }).getAttribute("aria-selected"), "true");
  assert.ok(screen.getByRole("button", { name: /Ghost/ }));
  assert.ok(screen.getByRole("button", { name: /Vaultwarden/ }));
  assert.equal(screen.queryByRole("button", { name: /Docker container/ }), null);
  assert.equal(screen.getAllByText("2 containers · Needs at least 1g RAM").length, 2);
});

test("empty recipe search cross-links into Docker and seeds the exact image query", async () => {
  renderForm();
  fireEvent.change(screen.getByRole("textbox", { name: "Search one-click apps" }), { target: { value: "zzz" } });
  assert.ok(screen.getByText("No one-click app matches “zzz”."));
  fireEvent.click(screen.getByRole("button", { name: "Search Docker images for “zzz”" }));
  assert.equal(screen.getByRole("tab", { name: "Docker", exact: true }).getAttribute("aria-selected"), "true");
  assert.equal((await screen.findByRole("combobox", { name: /^Docker image/ })).value, "zzz");
  assert.equal(harness.sent.length, 0);
});

test("selecting Ghost renders its summary and sends Ghost without UI-only source/query", async () => {
  renderForm();
  fireEvent.click(screen.getByRole("button", { name: /Ghost/ }));
  assert.ok(screen.getByRole("heading", { name: "What you get" }));
  assert.ok(screen.getByText("Runs"));
  assert.ok(screen.getByText("ghost, db"));
  assert.ok(screen.getByText("/var/lib/ghost/content"));
  assert.ok(screen.getByText(messages.applications.recipes.firstRunSetupWizard));
  await fillDetails();
  const body = await submit();
  assert.equal(body.site_type, "ghost");
  assert.equal("docker_source" in body, false);
  assert.equal("docker_search_query" in body, false);
});

test("LEMP keeps the existing type picker with no source tabs", () => {
  renderForm({ siteTypes: [PHP] });
  assert.equal(screen.queryByRole("tab"), null);
  assert.ok(screen.getByRole("textbox", { name: messages.applications.form.typeSearch }));
  assert.ok(screen.getByRole("button", { name: "Custom PHP" }));
});

test("compose URL props activate Docker and submit existing Compose fields, not docker_source", async () => {
  renderForm({ initialType: "docker", initialDockerSource: "compose" });
  assert.equal(screen.getByRole("tab", { name: "Docker", exact: true }).getAttribute("aria-selected"), "true");
  const compose = "services:\n  app:\n    image: nginx:alpine\n";
  fireEvent.change(await screen.findByRole("textbox", { name: /^Compose file/ }), { target: { value: compose } });
  assert.equal(screen.queryByRole("combobox", { name: /^Docker image/ }), null);
  await fillDetails();
  const body = await submit();
  assert.equal(body.site_type, "docker");
  assert.equal(body.docker_mode, "compose");
  assert.equal(body.compose, compose);
  assert.equal("docker_source" in body, false);
  assert.equal("docker_search_query" in body, false);
});

test("direct image request still sends detected port and omits source/query", async () => {
  globalThis.__dockerApi = {
    search: async () => ({ data: { results: [] } }),
    tags: async () => ({ data: { tags: [{ name: "1.0" }], recommended: "1.0" } }),
    inspect: async () => ({ data: { found: true, suggested_port: 3000, port_confidence: "declared" } }),
  };
  renderForm({ initialType: "docker" });
  await fillDetails();
  const input = screen.getAllByRole("combobox").find((element) => element.tagName === "INPUT");
  fireEvent.change(input, { target: { value: "usememos/memos:1.0" } });
  fireEvent.click(await screen.findByRole("option", { name: /as typed/ }));
  const body = await submit();
  assert.equal(body.site_type, "docker");
  assert.equal(body.image, "usememos/memos:1.0");
  assert.equal(body.container_port, 3000);
  assert.equal("docker_source" in body, false);
  assert.equal("docker_search_query" in body, false);
});

test("explicit Docker cross-link replaces a prior image while ordinary tab round-trips preserve it", async () => {
  renderForm({ initialType: "docker" });
  const input = screen.getByRole("combobox", { name: /^Docker image/ });
  fireEvent.change(input, { target: { value: "usememos/memos:1.0" } });
  fireEvent.click(await screen.findByRole("option", { name: /as typed/ }));
  await waitFor(() => assert.ok(screen.getAllByText("3000").length));
  chooseTab("One-click apps");
  chooseTab("Docker");
  assert.ok(screen.getByText("usememos/memos", { exact: true }));
  assert.equal(screen.queryByRole("combobox", { name: /^Docker image/ }), null);
  chooseTab("One-click apps");
  fireEvent.change(screen.getByRole("textbox", { name: "Search one-click apps" }), { target: { value: "new-query" } });
  fireEvent.click(screen.getByRole("button", { name: "Search Docker images for “new-query”" }));
  assert.equal((await screen.findByRole("combobox", { name: /^Docker image/ })).value, "new-query");
  assert.equal(screen.queryByText("usememos/memos", { exact: true }), null);
  await waitFor(() => assert.equal(document.activeElement, screen.getByRole("tab", { name: "Docker", exact: true })));
  assert.equal(harness.sent.length, 0);
});

test("tabs restore the last selected recipe, or clear when none was chosen", () => {
  renderPicker();
  chooseTab("Docker");
  assert.equal(pickerValues().site_type, "docker");
  chooseTab("One-click apps");
  assert.equal(pickerValues().site_type, "");
  fireEvent.click(screen.getByRole("button", { name: /Ghost/ }));
  chooseTab("Docker");
  chooseTab("One-click apps");
  assert.equal(pickerValues().site_type, "ghost");
  assert.ok(screen.getByText("ghost, db"));
});

test("initial compose mode selects Docker with form-only compose source preserved", () => {
  renderPicker({ initialType: "docker", initialSource: "compose" });
  assert.equal(screen.getByRole("tab", { name: "Docker", exact: true }).getAttribute("aria-selected"), "true");
  assert.equal(pickerValues().docker_source, "compose");
});

test("no-match cross-link clears Compose mode and records search/query without choosing an image", () => {
  renderPicker({ initialType: "docker", initialSource: "compose" });
  chooseTab("One-click apps");
  fireEvent.change(screen.getByRole("textbox", { name: "Search one-click apps" }), { target: { value: "  missing-app  " } });
  fireEvent.click(screen.getByRole("button", { name: "Search Docker images for “missing-app”" }));
  assert.deepEqual(pickerValues(), {
    site_type: "docker", docker_source: "search", docker_mode: "simple", docker_search_query: "missing-app",
    image: "", container_port: "", docker_image_state: "", docker_volumes: [], docker_env: [], docker_required_env: [],
  });
});

test("a match hidden by Popular offers All categories instead of a false Docker no-match", () => {
  const unpopular = { ...VAULTWARDEN, popular: false };
  renderPicker({ siteTypes: [DOCKER, GHOST, unpopular] });
  fireEvent.change(screen.getByRole("textbox", { name: "Search one-click apps" }), { target: { value: "vaultwarden" } });
  assert.equal(screen.queryByRole("button", { name: /Search Docker images/ }), null);
  fireEvent.click(screen.getByRole("button", { name: "Search all categories" }));
  assert.ok(screen.getByRole("button", { name: /Vaultwarden/ }));
});

test("whitespace search does not claim no recipe match, and query markup stays text", () => {
  renderPicker();
  const input = screen.getByRole("textbox", { name: "Search one-click apps" });
  fireEvent.change(input, { target: { value: "   " } });
  assert.equal(screen.queryByRole("button", { name: /Search Docker images/ }), null);
  fireEvent.change(input, { target: { value: "<script>bad()</script>" } });
  assert.ok(screen.getByText('No one-click app matches “<script>bad()</script>”.'));
  assert.equal(document.querySelector("script"), null);
});

test("catalog parser retains recipe metadata/default arrays and accepts non-recipe null", () => {
  const parsed = siteTypesResponseSchema.parse({ site_types: [DOCKER, { name: "demo", title: "Demo", recipe: { slug: "demo", version: 1, first_run: { kind: "none" } } }] });
  assert.equal(parsed.site_types[0].recipe, null);
  assert.deepEqual(parsed.site_types[1].recipe.services, []);
  assert.deepEqual(parsed.site_types[1].recipe.images, []);
  assert.deepEqual(parsed.site_types[1].recipe.volumes, []);
  assert.deepEqual(parsed.site_types[1].recipe.first_run.credential_labels, []);
  assert.equal(siteTypeSchema.safeParse({ ...GHOST, recipe: { ...GHOST.recipe, version: "1" } }).success, false);
});

for (const [kind, key] of [
  ["credentials", "firstRunCredentials"], ["claimed", "firstRunClaimed"], ["setup_wizard", "firstRunSetupWizard"],
  ["open_registration", "firstRunOpenRegistration"], ["default_login", "firstRunDefaultLogin"], ["none", "firstRunNone"],
]) {
  test(`recipe summary translates the ${kind} first-run story`, () => {
    render(createElement(pickerHarness.RecipeSummaryHarness, { messages, type: recipeType("ghost", { first_run: { kind } }) }));
    assert.ok(screen.getByText(messages.applications.recipes[key]));
  });
}

test("recipe summary handles no storage, unknown first-run and optional docs/images", () => {
  render(createElement(pickerHarness.RecipeSummaryHarness, { messages, type: recipeType("ghost", { volumes: [], images: [], first_run: { kind: "future_kind" }, docs_url: null, memory_floor: null }) }));
  assert.ok(screen.getByText(messages.applications.recipes.noStorage));
  assert.equal(screen.queryByText("After install"), null);
  assert.equal(screen.queryByText(messages.applications.recipes.firstRunNone), null);
  assert.equal(screen.queryByRole("button", { name: "Tested versions" }), null);
  assert.equal(screen.queryByRole("link"), null);
});

test("tested versions Collapsible reveals refs/tested versions and docs opens safely", () => {
  renderPicker();
  fireEvent.click(screen.getByRole("button", { name: /Ghost/ }));
  assert.equal(screen.queryByText(/ghost:5-alpine/), null);
  fireEvent.click(screen.getByRole("button", { name: "Tested versions" }));
  assert.ok(screen.getByText(/ghost:5-alpine/));
  assert.ok(screen.getByText(/5.100.0/));
  const docs = screen.getByRole("link", { name: "Documentation" });
  assert.equal(docs.getAttribute("href"), "https://ghost.org/docs/");
  assert.equal(docs.getAttribute("target"), "_blank");
  assert.equal(docs.getAttribute("rel"), "noreferrer");
});

test("a new recipe without a built-in logo uses the catalog's data URI", () => {
  const uri = "data:image/svg+xml;base64,PHN2Zy8+";
  const demo = { ...recipeType("demo", { icon_data_uri: uri }), title: "Demo" };
  renderPicker({ siteTypes: [DOCKER, demo] });
  const image = document.querySelector(`img[src="${uri}"]`);
  assert.ok(image);
  assert.equal(image.getAttribute("alt"), "");
});
