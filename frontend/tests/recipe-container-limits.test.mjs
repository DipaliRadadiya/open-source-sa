/*
 * Dipali (31 Oct): a 1-Click Recipe's Container page offers Memory and CPU, the same fields and
 * rules as a simple app, and saves only those two. A compose file taken over by hand keeps the note.
 */
import { after, before, test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { installDom, loadComponent } from "./support/dom.mjs";

installDom();
const { createElement } = await import("react");
const { cleanup, fireEvent, render, screen, waitFor } = await import("@testing-library/react");

const messages = JSON.parse(readFileSync("messages/en.json", "utf8"));
const c = messages.applications.container;
const v = messages.validation;
let ContainerCardHarness;

before(async () => {
  ({ ContainerCardHarness } = await loadComponent("tests/support/container-card-harness.jsx", {
    "@/lib/api/docker": "tests/support/docker-api-stub.js",
    "@/components/ui/app-link": "tests/support/app-link-stub.jsx",
    "next/navigation": "tests/support/next-navigation-stub.js",
  }));
});
after(() => cleanup());

const ghost = { id: 9, slug: "ghost", image: null, container_port: 2368, memory_limit: "512m", cpu_limit: null, origin: { kind: "recipe", recipe: { slug: "ghost", title: "Ghost", version: 1 } } };

function renderCard(application, { canManage = true } = {}) {
  cleanup();
  const sent = [];
  globalThis.__dockerApiCalls = {};
  globalThis.__dockerApi = {
    inspect: async () => ({ data: { found: true, suggested_port: null, port_confidence: "none" } }),
    update: async (id, payload) => { sent.push([id, payload]); return { data: {} }; },
  };
  render(createElement(ContainerCardHarness, { messages, canManage, ownCompose: true, limits: { defaultMemoryLimit: "512m", cpus: 4 }, application }));
  return sent;
}

test("a recipe shows Memory and CPU, says they cover its own container, and keeps port/network in the compose file", () => {
  renderCard(ghost);
  assert.ok(screen.getByText(c.recipeLimits.title));
  assert.ok(screen.getByText(c.recipeLimits.hint), "the limit is the app container's; the database keeps its own");
  assert.equal(screen.getByLabelText(c.memoryLimit).value, "512m");
  assert.equal(screen.getByLabelText(c.cpuLimit).value, "");
  assert.equal(screen.queryByLabelText(c.network), null, "network stays in the compose file");
  assert.equal(screen.queryByText(c.containerPort), null, "port stays in the compose file");
  assert.ok(screen.getByText(c.recipeLimits.composeTitle));
  assert.equal(screen.queryByText(c.ownCompose.body), null, "not the old 'changed in the compose file' note");
});

test("saving a recipe sends only memory and CPU", async () => {
  const sent = renderCard(ghost);
  fireEvent.change(screen.getByLabelText(c.memoryLimit), { target: { value: "768m" } });
  fireEvent.change(screen.getByLabelText(c.cpuLimit), { target: { value: "0.5" } });
  fireEvent.click(screen.getByRole("button", { name: c.saveAction }));
  await waitFor(() => assert.equal(sent.length, 1));
  assert.deepEqual(sent[0], [9, { memory_limit: "768m", cpu_limit: "0.5" }]);
});

test("a recipe uses the same validation as a simple app", async () => {
  const sent = renderCard(ghost);
  fireEvent.change(screen.getByLabelText(c.memoryLimit), { target: { value: "abc" } });
  fireEvent.click(screen.getByRole("button", { name: c.saveAction }));
  await screen.findByText(v.dockerMemory);
  assert.equal(sent.length, 0);
});

test("a compose file taken over by hand keeps the note and has no limit fields", () => {
  renderCard({ ...ghost, origin: { kind: "compose" } });
  assert.ok(screen.getByText(c.ownCompose.body));
  assert.equal(screen.queryByLabelText(c.memoryLimit), null);
});

test("without manage permission the recipe limits are read-only", () => {
  renderCard(ghost, { canManage: false });
  assert.equal(screen.getByLabelText(c.memoryLimit).disabled, true);
  assert.equal(screen.getByLabelText(c.cpuLimit).disabled, true);
});
