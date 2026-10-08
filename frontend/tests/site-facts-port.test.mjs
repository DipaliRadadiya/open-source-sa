/*
 * DS-15 item 3: on a Docker application the port tile showed `app_port`, the panel's
 * host-side proxy port (3000), not the port inside the container (5230) that the user
 * confirmed in the form. Renders the real SiteFactsCard.
 */
import { after, before, test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { installDom, loadComponent } from "./support/dom.mjs";

installDom();
const { createElement } = await import("react");
const { cleanup, render, screen } = await import("@testing-library/react");

const messages = JSON.parse(readFileSync("messages/en.json", "utf8"));
let SiteFactsHarness;

before(async () => {
  ({ SiteFactsHarness } = await loadComponent("tests/support/site-facts-harness.jsx", {
    "next/navigation": "tests/support/next-navigation-stub.js",
    "@/components/ui/app-link": "tests/support/app-link-stub.jsx",
  }));
});
after(() => cleanup());

const tile = (label) => screen.getByText(label).parentElement;

function renderFacts(application) {
  cleanup();
  render(createElement(SiteFactsHarness, { messages, application: { id: 6, name: "memos", site_type: "docker", ...application } }));
}

test("a container shows the port inside the container, with the proxy port named beside it", () => {
  renderFacts({ serving_profile: "docker", container_port: 5230, app_port: 3000 });
  assert.equal(screen.queryAllByText("Application port").length, 0);
  assert.equal(tile("Container port").textContent, "Container port5230Panel proxy: 3000");
});

test("a non-container application keeps its application port", () => {
  renderFacts({ serving_profile: "node", container_port: null, app_port: 3001 });
  assert.equal(screen.queryAllByText("Container port").length, 0);
  assert.equal(tile("Application port").textContent, "Application port3001");
});
