import assert from "node:assert/strict";
import { afterEach, before, beforeEach, test } from "node:test";
import { readFileSync } from "node:fs";
import { installDom, loadComponent } from "./support/dom.mjs";

installDom();
const { createElement } = await import("react");
const { act, cleanup, fireEvent, render, screen, within } = await import("@testing-library/react");
const messages = JSON.parse(readFileSync("messages/en.json", "utf8"));
let SiteFactsHarness;
let copied;

before(async () => {
  ({ SiteFactsHarness } = await loadComponent("tests/support/site-facts-harness.jsx", {
    "next/navigation": "tests/support/next-navigation-stub.js",
    "@/components/ui/app-link": "tests/support/app-link-stub.jsx",
  }));
});
beforeEach(() => {
  copied = [];
  Object.defineProperty(navigator, "clipboard", {
    configurable: true,
    value: { writeText: async (value) => { copied.push(value); } },
  });
});
afterEach(() => cleanup());

const layouts = { strip: { strip: true }, list: { list: true }, card: {} };
const cases = [
  { serving_profile: "php", web_root: "/", document_root: "/home/php/public_html" },
  { serving_profile: "static", web_root: "/public", document_root: "/home/static/public_html/public" },
  // The API's resolved path is authoritative; never reconstruct it from `path`.
  { serving_profile: "node", web_root: "/dist", document_root: "/srv/releases/current/dist" },
];

function mount(application = {}, layout = "strip", canManage = false) {
  render(createElement(SiteFactsHarness, {
    messages,
    application: {
      id: 7,
      name: "webroot-test",
      site_type: "php",
      serving_profile: "php",
      path: "/home/example/public_html",
      web_root: "/",
      document_root: "/home/example/public_html",
      ...application,
    },
    canManage,
    ...layouts[layout],
  }));
}

function fact(label, layout = "strip") {
  const parent = screen.getByText(label).parentElement;
  return layout === "card" ? parent.parentElement : parent;
}

async function copyFrom(element) {
  await act(async () => { fireEvent.click(within(element).getByRole("button", { name: "Copy", exact: true })); });
}

for (const layout of Object.keys(layouts)) {
  for (const application of cases) {
    test(`${layout}: ${application.serving_profile} copies the full document root while showing its relative setting`, async () => {
      mount(application, layout);
      const root = fact("Web root", layout);
      assert.ok(within(root).getByText(application.web_root, { exact: true }));
      await copyFrom(root);
      assert.deepEqual(copied, [application.document_root]);
    });
  }

  for (const documentRoot of [undefined, null, ""]) {
    test(`${layout}: document_root=${String(documentRoot)} does not copy a guessed or relative path`, () => {
      mount({ document_root: documentRoot }, layout);
      const root = fact("Web root", layout);
      assert.ok(within(root).getByText("/", { exact: true }));
      assert.equal(within(root).queryByRole("button", { name: "Copy", exact: true }), null);
      assert.deepEqual(copied, []);
    });
  }

  test(`${layout}: Docker does not acquire a webroot fact or copy action`, () => {
    mount({ serving_profile: "docker", site_type: "docker" }, layout);
    assert.equal(screen.queryByText("Web root"), null);
    assert.equal(screen.queryByRole("button", { name: "Copy", exact: true }), null);
  });
}

test("view-only users can copy the displayed application's full path without getting an edit control", async () => {
  mount({}, "strip", false);
  const root = fact("Web root");
  await copyFrom(root);
  assert.deepEqual(copied, ["/home/example/public_html"]);
  assert.equal(within(root).queryByRole("button", { name: messages.applications.webRoot.title }), null);
});

test("the webroot editor still opens with the relative value and never the copied full path", async () => {
  mount({ web_root: "/public", document_root: "/home/example/public_html/public" }, "strip", true);
  const root = fact("Web root");
  await copyFrom(root);
  fireEvent.click(within(root).getByRole("button", { name: messages.applications.webRoot.title }));
  const dialog = await screen.findByRole("dialog");
  assert.equal(within(dialog).getByRole("textbox").value, "/public");
  assert.deepEqual(copied, ["/home/example/public_html/public"]);
  fireEvent.click(within(dialog).getByRole("button", { name: "Cancel", exact: true }));
});

test("a missing full path hides only Copy, not an authorized user's relative-setting editor", () => {
  mount({ document_root: null }, "strip", true);
  const root = fact("Web root");
  assert.equal(within(root).queryByRole("button", { name: "Copy", exact: true }), null);
  assert.ok(within(root).getByRole("button", { name: messages.applications.webRoot.title }));
});

test("other facts still copy their own values, including numeric ports and Docker image references", async () => {
  mount({
    serving_profile: "docker",
    site_type: "docker",
    container_port: 5230,
    app_port: 3000,
    origin: { kind: "image", image: "registry.example.test/application:1.0" },
  });
  await copyFrom(fact("Container port"));
  await copyFrom(fact(messages.applications.origin.factLabel));
  assert.deepEqual(copied, ["5230", "registry.example.test/application:1.0"]);
});
