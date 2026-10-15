/*
 * Renders the real CreateApplicationForm for the Docker type (jsdom +
 * testing-library), fills it in the way a user does and reads the request it
 * sends. Replaces regexes over the form's source.
 */
import { after, before, test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { installDom, loadComponent } from "./support/dom.mjs";

installDom();
const { createElement } = await import("react");
const { cleanup, fireEvent, render, screen, waitFor } = await import("@testing-library/react");
const { siteTypeSchema } = await import("../lib/schemas/application.js");

const messages = JSON.parse(readFileSync("messages/en.json", "utf8"));
let harness;

before(async () => {
  harness = await loadComponent("tests/support/create-form-harness.jsx", {
    "@/lib/api/docker": "tests/support/docker-api-stub.js",
    "@/lib/api/applications": "tests/support/applications-api-stub.js",
    "@/components/ui/app-link": "tests/support/app-link-stub.jsx",
    "next/navigation": "tests/support/next-navigation-stub.js",
  });
});
after(() => cleanup());

// The API's Docker type, with the `default: 80` it used to declare: the form must not apply it.
const DOCKER = siteTypeSchema.parse({
  name: "docker",
  title: "Docker",
  method: "docker",
  fields: [
    {
      name: "docker_mode",
      label: "Mode",
      type: "select",
      default: "simple",
      options: [
        { value: "simple", label: "Image" },
        { value: "compose", label: "Compose" },
      ],
    },
    { name: "image", label: "Image", type: "text", depends_on: "docker_mode:simple" },
    { name: "container_port", label: "Container port", type: "number", default: 80, depends_on: "docker_mode:simple" },
  ],
});

function stubApi(inspect) {
  globalThis.__dockerApi = {
    tags: async () => ({ data: { tags: [{ name: "1.0" }], recommended: "1.0" } }),
    inspect: async () => ({ data: inspect }),
    search: async () => ({ data: { results: [] } }),
  };
}

function renderForm(name) {
  cleanup();
  harness.sent.length = 0;
  render(
    createElement(harness.CreateFormHarness, {
      messages,
      siteTypes: [DOCKER],
      initialType: "docker",
      initialName: name,
      systemUsers: [{ id: 1, username: name }],
    }),
  );
}

async function fillDetailsAndImage(name, image) {
  fireEvent.change(screen.getByRole("textbox", { name: /Domain/ }), { target: { value: `${name}.example.com` } });
  fireEvent.click(screen.getByRole("combobox", { name: "System user" }));
  fireEvent.click(screen.getByRole("option", { name }));
  const imageInput = screen.getAllByRole("combobox").find((element) => element.tagName === "INPUT");
  fireEvent.change(imageInput, { target: { value: image } });
  fireEvent.click(await screen.findByRole("option", { name: /as typed/ }));
}

async function submit() {
  fireEvent.click(screen.getAllByRole("button", { name: "Create application" })[0]);
  await waitFor(() => assert.equal(harness.sent.length, 1));
  return harness.sent[0];
}

test("choosing Docker does not fill the port with the type's default of 80", async () => {
  stubApi({ found: true, suggested_port: 3000, port_confidence: "declared" });
  renderForm("memos");
  // Before any image is picked the review lists the port as still to do, not as "80".
  assert.ok((await screen.findAllByRole("button", { name: "Missing: Container port" })).length > 0);
  assert.equal(screen.queryAllByDisplayValue("80").length, 0);
});

test("an image that declares no port leaves the port empty and asked for", async () => {
  stubApi({ found: true, suggested_port: null, port_confidence: "none" });
  renderForm("memos");
  await fillDetailsAndImage("memos", "usememos/memos:1.0");
  const port = await screen.findByRole("spinbutton", { name: /Port/ });
  assert.equal(port.value, "");
  assert.ok(screen.getAllByRole("button", { name: "Missing: Container port" }).length > 0);
});

test("the request carries the detected port, the image's volume and the required setting", async () => {
  stubApi({
    found: true,
    suggested_port: 3000,
    exposed_ports: [3000],
    port_confidence: "declared",
    suggested_volumes: [{ path: "/data" }],
    env: [
      { key: "DATABASE_URL", default: null, required: true },
      { key: "APP_SECRET", default: "change-me", required: false },
    ],
  });
  renderForm("umami");
  await fillDetailsAndImage("umami", "ghcr.io/umami-software/umami:1.0");
  fireEvent.change(await screen.findByRole("textbox", { name: "Value for DATABASE_URL" }), {
    target: { value: "postgresql://umami:pw@db/umami" },
  });

  const body = await submit();
  assert.equal(body.image, "ghcr.io/umami-software/umami:1.0");
  assert.equal(body.container_port, 3000);
  // One volume uses the single-volume fields; an untouched optional default is not sent.
  assert.equal(body.volume_new, "umami-data");
  assert.equal(body.volume_path, "/data");
  assert.equal("volume_mounts" in body, false);
  assert.deepEqual(body.env, [{ key: "DATABASE_URL", value: "postgresql://umami:pw@db/umami" }]);
  // Form-only state never reaches the API.
  for (const key of ["docker_env", "docker_volumes", "docker_required_env", "docker_image_state"]) {
    assert.equal(key in body, false, key);
  }
});

test("two folders become volume_mounts, and an unticked one is left out", async () => {
  stubApi({
    found: true,
    suggested_port: 8096,
    port_confidence: "declared",
    suggested_volumes: [{ path: "/config" }, { path: "/cache" }, { path: "/media" }],
  });
  renderForm("jelly");
  await fillDetailsAndImage("jelly", "jellyfin/jellyfin:1.0");
  const boxes = await screen.findAllByRole("checkbox");
  fireEvent.click(boxes[1]);

  const body = await submit();
  assert.equal(body.container_port, 8096);
  assert.deepEqual(body.volume_mounts, [
    { volume: "jelly-config", path: "/config" },
    { volume: "jelly-media", path: "/media" },
  ]);
  assert.equal("volume_new" in body, false);
});
