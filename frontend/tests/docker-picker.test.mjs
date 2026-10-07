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
const { act, cleanup, fireEvent, render, screen, waitFor } = await import("@testing-library/react");
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
