import assert from "node:assert/strict";
import { afterEach, before, beforeEach, test } from "node:test";
import { readFileSync } from "node:fs";
import { installDom, loadComponent } from "./support/dom.mjs";
installDom();
const { createElement } = await import("react");
const { IntlProvider } = await import("use-intl");
const { render, screen, cleanup, fireEvent, waitFor } = await import("@testing-library/react");
const messages = JSON.parse(readFileSync("messages/en.json", "utf8"));
let FirstRunCredentials;
let TooltipProvider;
let reads;
let acknowledgements;
const application = { id: 7, container_secret_keys: ["ADMIN_PASSWORD", "DB_ROOT_PASSWORD"] };
before(async () => {
  FirstRunCredentials = (await loadComponent("components/applications/first-run-credentials.jsx", {
    "@/lib/api/docker": "tests/support/first-run-api-stub.js",
    "next/navigation": "tests/support/next-navigation-stub.js",
  })).FirstRunCredentials;
  TooltipProvider = (await loadComponent("components/ui/tooltip.jsx")).TooltipProvider;
});
beforeEach(() => {
  reads = []; acknowledgements = [];
  globalThis.__firstRunApi = {
    secrets: async (id) => { reads.push(id); return { data: { secrets: { ADMIN_PASSWORD: "legacy-value", DB_ROOT_PASSWORD: "db-private-fixture" } } }; },
    acknowledge: async (id) => { acknowledgements.push(id); },
  };
});
afterEach(cleanup);
function show(app = application) {
  return render(createElement(IntlProvider, { locale: "en", messages, timeZone: "UTC" },
    createElement(TooltipProvider, null, createElement(FirstRunCredentials, { application: app }))));
}
function curated(items) {
  globalThis.__firstRunApi.secrets = async (id) => { reads.push(id); return { data: { secrets: { ADMIN_PASSWORD: "raw-unused", DB_ROOT_PASSWORD: "db-private-fixture" }, credentials: items } }; };
}
test("curated response shows Username admin and Password <x>, no raw keys or DB passwords", async () => {
  curated([{ label: "username", value: "admin", secret: false }, { label: "password", value: "<x>", secret: true }]);
  show();
  await screen.findByText("admin");
  assert.equal(screen.getByText("Username").closest("li").textContent, "Usernameadmin");
  assert.equal(screen.getByText("Password").closest("li").textContent, "Password<x>");
  assert.equal(screen.queryByText("ADMIN_PASSWORD"), null);
  assert.equal(screen.queryByText("DB_ROOT_PASSWORD"), null);
  assert.equal(screen.queryByText("db-private-fixture"), null);
  assert.equal(document.querySelector("x"), null, "credential values are escaped text");
  assert.deepEqual(reads, [7]);
  assert.deepEqual(acknowledgements, [], "reading never acknowledges");
  const copies = [];
  Object.defineProperty(navigator, "clipboard", { configurable: true, value: { writeText: async (value) => copies.push(value) } });
  fireEvent.click(screen.getByText("Password").closest("li").querySelector("button"));
  await waitFor(() => assert.deepEqual(copies, ["<x>"]));
});
for (const credentials of [undefined, null, { invalid: true }]) {
  test(`non-array credentials ${JSON.stringify(credentials)} falls back to legacy rows`, async () => {
    const old = globalThis.__firstRunApi.secrets;
    globalThis.__firstRunApi.secrets = async (id) => { const result = await old(id); result.data.credentials = credentials; return result; };
    show(); await screen.findByText("legacy-value");
    assert.equal(screen.getByText("ADMIN_PASSWORD").closest("li").textContent, "ADMIN_PASSWORDlegacy-value");
    assert.ok(screen.getByText("DB_ROOT_PASSWORD"));
  });
}
test("an empty curated array stays empty, never exposes legacy secrets", async () => {
  curated([]);show();
  await waitFor(() => assert.equal(screen.getByRole("button", { name: messages.applications.container.firstRun.acknowledge }).disabled, false));
  assert.equal(document.querySelectorAll("li").length, 0);
  assert.equal(screen.queryByText("db-private-fixture"), null);
});
test("all five curated labels are translated literally", async () => {
  curated(["username", "password", "email", "url", "token"].map(label => ({ label, value: `fixture-${label}`, secret: true })));
  show();await screen.findByText("fixture-token");
  for (const label of ["Username", "Password", "Email", "Admin URL", "Token"]) assert.ok(screen.getByText(label));
});
test("loading prevents acknowledgement until values arrive; only the existing button sends it", async () => {
  let finish;
  globalThis.__firstRunApi.secrets = () => new Promise(resolve => { finish = resolve; });
  show();const button = screen.getByRole("button", { name: messages.applications.container.firstRun.acknowledge });
  assert.equal(button.disabled, true);assert.ok(screen.getAllByLabelText(messages.applications.container.firstRun.loading).length);
  finish({ data: { secrets: { ADMIN_PASSWORD: "shown" }, credentials: [{ label: "password", value: "shown" }] } });
  await screen.findByText("shown");
  const ready = screen.getByRole("button", { name: messages.applications.container.firstRun.acknowledge });
  await waitFor(() => assert.equal(ready.disabled, false));assert.deepEqual(acknowledgements, []);
  fireEvent.click(ready);await waitFor(() => assert.deepEqual(acknowledgements, [7]));
});
test("refused secrets show an error and keep acknowledgement disabled", async () => {
  globalThis.__firstRunApi.secrets = async () => { throw { response: { status: 403, data: { message: "Synthetic refusal" } } }; };
  show();await screen.findByText("Synthetic refusal");
  assert.equal(screen.getByRole("button", { name: messages.applications.container.firstRun.acknowledge }).disabled, true);
});
test("empty secret keys keep the existing visibility rule even if the API has curated rows", async () => {
  curated([{ label: "username", value: "admin" }]);
  const rendered = show({ ...application, container_secret_keys: [] });
  await waitFor(() => assert.equal(reads.length, 1));
  assert.equal(rendered.container.textContent, "");
});
