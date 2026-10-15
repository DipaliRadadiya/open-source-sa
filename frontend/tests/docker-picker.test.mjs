/*
 * Renders the real DockerImageSetup (jsdom + testing-library) with the discovery
 * API stubbed, and drives it the way a user does. Replaces regexes over the
 * source, which passed while review findings 1 and 2 were live.
 *
 * Element assertions compare booleans: a failing assert.equal on a jsdom node
 * tries to print the whole document and never returns.
 */
import { after, before, test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { installDom, loadComponent } from "./support/dom.mjs";

installDom();
const { createElement } = await import("react");
const { act, cleanup, fireEvent, render, screen, waitFor, within } = await import("@testing-library/react");
const { dockerCreateFields, dockerEnvProblem } = await import("../lib/docker/create-request.js");

const messages = JSON.parse(readFileSync("messages/en.json", "utf8"));
let DockerPickerHarness;

before(async () => {
  ({ DockerPickerHarness } = await loadComponent("tests/support/docker-picker-harness.jsx", {
    "@/lib/api/docker": "tests/support/docker-api-stub.js",
  }));
});
after(() => cleanup());

const UMAMI = {
  found: true,
  suggested_port: 3000,
  exposed_ports: [3000],
  port_confidence: "declared",
  env: [
    { key: "DATABASE_URL", default: null, required: true },
    { key: "APP_SECRET", default: "change-me", required: false },
  ],
};
const JELLYFIN = {
  found: true,
  suggested_port: 8096,
  exposed_ports: [8096],
  port_confidence: "declared",
  suggested_volumes: [{ path: "/config" }, { path: "/cache" }],
};

function stubApi({ inspect = UMAMI, search } = {}) {
  globalThis.__dockerApi = {
    tags: async () => ({ data: { tags: [{ name: "1.0" }], recommended: "1.0" } }),
    inspect: async () => ({ data: inspect }),
    search: search ?? (async () => ({ data: { results: [] } })),
  };
}

async function renderPicker(defaultValues) {
  cleanup();
  let form;
  render(
    createElement(DockerPickerHarness, {
      messages,
      defaultValues: { name: "app", docker_env: [], docker_volumes: [], ...defaultValues },
      onForm: (value) => {
        form = value;
      },
    }),
  );
  return () => form;
}

test("umami: DATABASE_URL cannot be removed, and Create stays held until it has a value", async () => {
  stubApi({ inspect: UMAMI });
  const form = await renderPicker({ name: "umami", image: "ghcr.io/umami-software/umami:1.0" });
  await screen.findByRole("textbox", { name: "Value for DATABASE_URL" });

  // Optional settings can go; the required one has no remove control at all.
  assert.ok(screen.getByRole("button", { name: "Remove APP_SECRET" }));
  assert.equal(Boolean(screen.queryByRole("button", { name: "Remove DATABASE_URL" })), false);

  const values = () => [form().getValues("docker_env"), form().getValues("docker_required_env")];
  assert.equal(dockerEnvProblem(...values()), true);

  // Removing the optional row does not release the gate either.
  fireEvent.click(screen.getByRole("button", { name: "Remove APP_SECRET" }));
  assert.equal(dockerEnvProblem(...values()), true);

  fireEvent.change(screen.getByRole("textbox", { name: "Value for DATABASE_URL" }), {
    target: { value: "postgresql://umami:pw@db:5432/umami" },
  });
  assert.equal(dockerEnvProblem(...values()), false);
});

test("jellyfin: unticking both storage boxes sends an explicit empty list", async () => {
  stubApi({ inspect: JELLYFIN });
  const form = await renderPicker({ name: "jelly", image: "jellyfin/jellyfin:1.0" });
  const boxes = await screen.findAllByRole("checkbox");
  assert.equal(boxes.length, 2);
  assert.ok(boxes.every((box) => box.getAttribute("aria-checked") === "true"));

  boxes.forEach((box) => fireEvent.click(box));
  await waitFor(() => assert.equal(screen.getAllByText(messages.applications.dockerImage.storageOff).length, 2));

  const { fields } = dockerCreateFields({
    applicationName: form().getValues("name"),
    volumes: form().getValues("docker_volumes"),
    envRows: form().getValues("docker_env"),
  });
  assert.deepEqual(fields.volume_mounts, []);
});

test("a newer query never shows the previous query's results", async () => {
  const pending = {};
  stubApi({
    search: (query) =>
      new Promise((resolve) => {
        pending[query] = () => resolve({ data: { results: [{ image: query }] } });
      }),
  });
  await renderPicker({ image: "" });
  const input = screen.getByRole("combobox");

  fireEvent.change(input, { target: { value: "nginx" } });
  await waitFor(() => assert.ok(pending.nginx));
  await act(async () => pending.nginx());
  assert.ok(screen.getByRole("option", { name: /nginx/ }));
  assert.match(screen.getByRole("status").textContent, /1 image found/);

  fireEvent.change(input, { target: { value: "wordpress" } });
  assert.equal(Boolean(screen.queryByRole("option", { name: /nginx/ })), false);
  assert.match(screen.getByRole("status").textContent, /Searching Docker Hub/);

  await waitFor(() => assert.ok(pending.wordpress));
  await act(async () => pending.wordpress());
  assert.ok(screen.getByRole("option", { name: /wordpress/ }));
});

test("the Version label points at the version control", async () => {
  stubApi({ inspect: JELLYFIN });
  await renderPicker({ image: "jellyfin/jellyfin:1.0" });
  await screen.findAllByRole("checkbox");
  const label = screen.getByText("Version").closest("label");
  const control = document.getElementById(label.htmlFor);
  assert.ok(control, `label points at "${label.htmlFor}", which does not exist`);
  assert.equal(control.getAttribute("role"), "combobox");
});

test("a server error on a setting shows beside that row and describes its input", async () => {
  stubApi({ inspect: UMAMI });
  const form = await renderPicker({ name: "umami", image: "ghcr.io/umami-software/umami:1.0" });
  const input = await screen.findByRole("textbox", { name: "Value for APP_SECRET" });
  act(() => form().setError("docker_env.1.value", { type: "server", message: "Must be 32 characters." }));
  const message = await screen.findByText("Must be 32 characters.");
  assert.equal(input.getAttribute("aria-invalid"), "true");
  assert.equal(input.getAttribute("aria-describedby"), message.id);

  // Editing the row clears it.
  fireEvent.change(input, { target: { value: "x".repeat(32) } });
  await waitFor(() => assert.equal(Boolean(screen.queryByText("Must be 32 characters.")), false));
});

test("the settings list stops at the API's 100 rows", async () => {
  stubApi({ inspect: { found: true, suggested_port: 80, port_confidence: "declared" } });
  const form = await renderPicker({ image: "nginx:1.0" });
  await screen.findByRole("button", { name: "Add variable" });
  const rows = Array.from({ length: 100 }, (_, i) => ({ id: `u${i}`, key: `K${i}`, value: "v", original: "", required: false, fromImage: false }));
  act(() => form().setValue("docker_env", rows));
  await waitFor(() => assert.equal(Boolean(screen.queryByRole("button", { name: "Add variable" })), false));
  assert.ok(screen.getByText("You can add up to 100 variables."));
});

test("a declared port is shown as a fact; Change opens an input and Use puts it back", async () => {
  stubApi({ inspect: UMAMI });
  const form = await renderPicker({ name: "umami", image: "ghcr.io/umami-software/umami:1.0" });
  await screen.findByText("detected from the image");
  const portField = within(document.querySelector('[data-field-name="container_port"]'));
  const change = portField.getByRole("button", { name: "Change" });
  assert.equal(screen.queryAllByRole("spinbutton").length, 0);
  assert.equal(form().getValues("container_port"), "3000");

  fireEvent.click(change);
  const input = screen.getByRole("spinbutton", { name: /Port/ });
  assert.equal(input.value, "3000");
  fireEvent.change(input, { target: { value: "8082" } });
  assert.equal(form().getValues("container_port"), "8082");

  fireEvent.click(screen.getByRole("button", { name: "Use 3000" }));
  assert.equal(form().getValues("container_port"), "3000");
  assert.equal(screen.queryAllByRole("spinbutton").length, 0);
});

test("an image that declares no port asks for one, with no Change link", async () => {
  stubApi({ inspect: { found: true, suggested_port: null, port_confidence: "none" } });
  const form = await renderPicker({ image: "example/app:1.0" });
  const input = await screen.findByRole("spinbutton", { name: /Port/ });
  assert.equal(input.value, "");
  const portField = within(document.querySelector('[data-field-name="container_port"]'));
  assert.equal(Boolean(portField.queryByRole("button", { name: "Change" })), false);
  assert.equal(form().getValues("docker_image_state"), "ok");
});

test("a failed inspect is 'we could not ask', never 'the image declares nothing'", async () => {
  stubApi({ inspect: UMAMI });
  globalThis.__dockerApi.inspect = async () => {
    throw Object.assign(new Error("Network Error"), { code: "ERR_NETWORK" });
  };
  const form = await renderPicker({ image: "example/app:1.0", container_port: "80" });
  assert.ok(await screen.findByText(messages.applications.dockerImage.inspectUnavailableTitle));
  const input = screen.getByRole("spinbutton", { name: /Port/ });
  assert.equal(input.value, "");
  assert.equal(form().getValues("docker_image_state"), "unknown");
  assert.deepEqual(form().getValues("docker_required_env"), []);
});

test("a reset that keeps dirty values keeps the required keys with their rows", async () => {
  stubApi({ inspect: UMAMI });
  const form = await renderPicker({ name: "umami", image: "ghcr.io/umami-software/umami:1.0" });
  await screen.findByRole("textbox", { name: "Value for DATABASE_URL" });
  act(() =>
    form().reset(
      { name: "umami", docker_env: [], docker_volumes: [], docker_required_env: [] },
      { keepDirtyValues: true },
    ),
  );
  assert.deepEqual(form().getValues("docker_required_env"), ["DATABASE_URL"]);
  assert.equal(dockerEnvProblem(form().getValues("docker_env"), form().getValues("docker_required_env")), true);
});

// DS-15 / DS-11 O1: `changedetection.io` reads as a registry ref, so "Use … as typed" was
// listed first and Enter took the literal name instead of the official image.
const CHANGEDETECTION = { image: "dgtlmoon/changedetection.io", description: "Website change detection", stars: 900, pulls: 5000000 };

function pendingSearch() {
  const pending = {};
  stubApi({
    search: (query) =>
      new Promise((resolve) => {
        pending[query] = (results) => resolve({ data: { results } });
      }),
  });
  return pending;
}
// The search box is replaced by the chosen image; while it shows, nothing is chosen.
const chosenRepository = () =>
  screen.queryByRole("combobox", { name: /Docker image/ })
    ? null
    : document.querySelector('[data-field-name="image"] .font-mono')?.textContent ?? null;

test("registry matches are listed above 'Use … as typed', and Enter picks the top match", async () => {
  const pending = pendingSearch();
  await renderPicker({ image: "" });
  const input = screen.getByRole("combobox");
  fireEvent.change(input, { target: { value: "changedetection.io" } });
  await waitFor(() => assert.ok(pending["changedetection.io"]));
  await act(async () => pending["changedetection.io"]([CHANGEDETECTION]));

  const options = screen.getAllByRole("option").map((option) => option.textContent);
  assert.equal(options.length, 2);
  assert.match(options[0], /^dgtlmoon\/changedetection\.io/);
  assert.match(options[1], /Use changedetection\.io as typed/);

  fireEvent.keyDown(input, { key: "Enter" });
  assert.equal(chosenRepository(), "dgtlmoon/changedetection.io");
});

test("Enter while results are loading does not take the literal term; it waits for a match", async () => {
  const pending = pendingSearch();
  await renderPicker({ image: "" });
  const input = screen.getByRole("combobox");
  fireEvent.change(input, { target: { value: "changedetection.io" } });
  // Only the typed row exists while searching.
  assert.equal(screen.getAllByRole("option").length, 1);
  fireEvent.keyDown(input, { key: "Enter" });
  assert.equal(chosenRepository(), null);
  assert.equal(Boolean(screen.queryByRole("combobox")), true, "still searching, nothing chosen");

  await waitFor(() => assert.ok(pending["changedetection.io"]));
  await act(async () => pending["changedetection.io"]([CHANGEDETECTION]));
  fireEvent.keyDown(input, { key: "Enter" });
  assert.equal(chosenRepository(), "dgtlmoon/changedetection.io");
});

test("the typed term is still one deliberate keypress away", async () => {
  const pending = pendingSearch();
  await renderPicker({ image: "" });
  const input = screen.getByRole("combobox");
  fireEvent.change(input, { target: { value: "ghcr.io/acme/app" } });
  await waitFor(() => assert.ok(pending["ghcr.io/acme/app"]));
  await act(async () => pending["ghcr.io/acme/app"]([{ image: "acme/app" }]));

  fireEvent.keyDown(input, { key: "ArrowUp" });
  assert.match(screen.getAllByRole("option").at(-1).getAttribute("aria-selected"), /true/);
  fireEvent.keyDown(input, { key: "Enter" });
  assert.equal(chosenRepository(), "ghcr.io/acme/app");
});

test("with no matches, Enter takes the term as typed", async () => {
  const pending = pendingSearch();
  await renderPicker({ image: "" });
  const input = screen.getByRole("combobox");
  fireEvent.change(input, { target: { value: "registry.example/app" } });
  await waitFor(() => assert.ok(pending["registry.example/app"]));
  await act(async () => pending["registry.example/app"]([]));
  fireEvent.keyDown(input, { key: "Enter" });
  assert.equal(chosenRepository(), "registry.example/app");
});
