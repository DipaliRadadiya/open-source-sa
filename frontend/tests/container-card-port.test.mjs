/*
 * Renders the real ContainerCard (jsdom + testing-library) against a stubbed
 * inspect answer, and checks how the saved port is shown next to the port the
 * image declares. Replaces a regex over the card's source.
 */
import { after, before, test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { installDom, loadComponent } from "./support/dom.mjs";

installDom();
const { createElement } = await import("react");
const { cleanup, fireEvent, render, screen, within } = await import("@testing-library/react");

const messages = JSON.parse(readFileSync("messages/en.json", "utf8"));
let ContainerCardHarness;

before(async () => {
  ({ ContainerCardHarness } = await loadComponent("tests/support/container-card-harness.jsx", {
    "@/lib/api/docker": "tests/support/docker-api-stub.js",
    "@/components/ui/app-link": "tests/support/app-link-stub.jsx",
    "next/navigation": "tests/support/next-navigation-stub.js",
  }));
});
after(() => cleanup());

function renderCard({ port, detected, canManage = true }) {
  cleanup();
  globalThis.__dockerApi = {
    inspect: async () => ({
      data: detected
        ? { found: true, suggested_port: detected, port_confidence: "declared" }
        : { found: true, suggested_port: null, port_confidence: "none" },
    }),
  };
  render(
    createElement(ContainerCardHarness, {
      messages,
      canManage,
      application: { id: 6, slug: "memos", image: "usememos/memos:0.31.0", container_port: port },
    }),
  );
}

const portField = () => within(screen.getByText("Container port").closest("[data-slot=form-item]"));

test("a saved port that matches the image is a fact with a Change link", async () => {
  renderCard({ port: 5230, detected: 5230 });
  await screen.findByText("detected from the image");
  assert.equal(portField().queryAllByRole("spinbutton").length, 0);

  fireEvent.click(portField().getByRole("button", { name: "Change" }));
  assert.equal(portField().getByRole("spinbutton").value, "5230");
});

test("without manage permission the matching port has no Change link", async () => {
  renderCard({ port: 5230, detected: 5230, canManage: false });
  await screen.findByText("detected from the image");
  assert.equal(Boolean(portField().queryByRole("button", { name: "Change" })), false);
});

test("a saved port the image does not declare stays editable, with a fix to the declared one", async () => {
  renderCard({ port: 8082, detected: 5230 });
  const fix = await portField().findByRole("button", { name: "Use 5230" });
  assert.ok(portField().getByText(/The image listens on 5230/));
  assert.equal(portField().getByRole("spinbutton").value, "8082");

  fireEvent.click(fix);
  // Back to the declared port: shown as a fact again.
  await screen.findByText("detected from the image");
  assert.equal(portField().queryAllByRole("spinbutton").length, 0);
});

test("an image that declares no port leaves a plain input and no warning", async () => {
  renderCard({ port: 8080, detected: null });
  const input = await portField().findByRole("spinbutton");
  assert.equal(input.value, "8080");
  assert.equal(Boolean(portField().queryByText(/The image listens on/)), false);
  assert.ok(portField().getByText(messages.applications.container.containerPortHint));
});
