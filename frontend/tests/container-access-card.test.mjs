import assert from "node:assert/strict";
import { afterEach, before, beforeEach, test } from "node:test";
import { readFileSync } from "node:fs";
import { installDom, loadComponent } from "./support/dom.mjs";
installDom();
const { createElement } = await import("react");
const { IntlProvider } = await import("use-intl");
const { render, screen, cleanup, fireEvent, waitFor } = await import("@testing-library/react");
const { buildAccessCommand, readySshUsers, defaultContainer, showsContainerAccess, PLACEHOLDER_USER } =
  await import("../lib/applications/container-access.js");
const messages = JSON.parse(readFileSync("messages/en.json", "utf8"));
const m = messages.applications.containerAccess;

let ContainerAccessCard;
let TooltipProvider;
let warnings;
const dockerApp = { id: 12, serving_profile: "docker" };
// Synthetic host from the documentation range; never a real server.
const single = {
  host: "203.0.113.10",
  ssh_port: 22,
  project: "sv-app-12",
  containers: [{ service: "openclaw", name: "sv-app-12-openclaw-1", main: true }],
  ssh_users: [],
};
const multi = {
  ...single,
  containers: [
    { service: "web", name: "sv-app-12-web-1", main: true },
    { service: "worker", name: "custom-worker", main: false },
  ],
  ssh_users: [
    { username: "adarsh", ssh_access: true, sudo: true, has_password: true },
    { username: "nosudo", ssh_access: true, sudo: false, has_password: true },
    { username: "nossh", ssh_access: false, sudo: true, has_password: true },
    { username: "nopass", ssh_access: true, sudo: true, has_password: false },
  ],
};

before(async () => {
  ContainerAccessCard = (await loadComponent("components/applications/container-access-card.jsx", {
    "@/lib/api/docker": "tests/support/docker-api-stub.js",
    "@/components/ui/app-link": "tests/support/app-link-stub.jsx",
    sonner: "tests/support/sonner-stub.js",
  })).ContainerAccessCard;
  TooltipProvider = (await loadComponent("components/ui/tooltip.jsx")).TooltipProvider;
});
let originalWarn;
beforeEach(() => {
  globalThis.__dockerApiCalls = {};
  globalThis.__dockerApi = { access: async () => ({ data: single }) };
  globalThis.__toasts = [];
  warnings = [];
  originalWarn = console.warn;
  console.warn = (...args) => warnings.push(args);
});
afterEach(() => {
  cleanup();
  console.warn = originalWarn;
});

function show(props = {}) {
  return render(createElement(IntlProvider, { locale: "en", messages, timeZone: "UTC" },
    createElement(TooltipProvider, null,
      createElement(ContainerAccessCard, { application: dockerApp, canSeeSystemUsers: true, ...props }))));
}
const command = () => screen.getByTestId("container-access-command").textContent;

test("command builder: default port, custom port, placeholder user, picked user, container switch", () => {
  const base = { host: "203.0.113.10", container: "sv-app-12-openclaw-1" };
  assert.equal(buildAccessCommand({ ...base, port: 22 }),
    "ssh -t your-user@203.0.113.10 'sudo docker exec -it sv-app-12-openclaw-1 sh'");
  assert.equal(buildAccessCommand({ ...base, port: null }),
    "ssh -t your-user@203.0.113.10 'sudo docker exec -it sv-app-12-openclaw-1 sh'", "no port means 22");
  assert.equal(buildAccessCommand({ ...base, port: 2222 }),
    "ssh -t -p 2222 your-user@203.0.113.10 'sudo docker exec -it sv-app-12-openclaw-1 sh'");
  assert.equal(buildAccessCommand({ ...base, port: 22, user: "" }).includes(`${PLACEHOLDER_USER}@`), true);
  assert.equal(buildAccessCommand({ ...base, port: 22, user: "adarsh" }),
    "ssh -t adarsh@203.0.113.10 'sudo docker exec -it sv-app-12-openclaw-1 sh'");
  assert.equal(buildAccessCommand({ ...base, port: 22, user: "adarsh", container: "custom-worker" }),
    "ssh -t adarsh@203.0.113.10 'sudo docker exec -it custom-worker sh'");
});

test("helpers: ready users need ssh, sudo and a password; main container is the default", () => {
  assert.deepEqual(readySshUsers(multi.ssh_users).map((u) => u.username), ["adarsh"]);
  assert.deepEqual(readySshUsers(undefined), []);
  assert.equal(defaultContainer(multi.containers).name, "sv-app-12-web-1");
  assert.equal(defaultContainer([{ service: "a", name: "a-1", main: false }]).name, "a-1");
  assert.equal(showsContainerAccess(dockerApp), true);
  for (const profile of ["php", "node", null, undefined]) {
    assert.equal(showsContainerAccess({ serving_profile: profile }), false);
  }
});

test("renders only for docker apps and never asks the API for others", async () => {
  for (const profile of ["php", "node", null]) {
    const rendered = show({ application: { id: 3, serving_profile: profile } });
    assert.equal(rendered.container.textContent, "");
    cleanup();
  }
  assert.equal(globalThis.__dockerApiCalls.access ?? 0, 0);
});

test("single container: skeleton first, then the placeholder command, no container select", async () => {
  let finish;
  globalThis.__dockerApi.access = () => new Promise((resolve) => { finish = resolve; });
  show();
  assert.ok(screen.getByText(m.title));
  assert.ok(screen.getByLabelText(m.loading));
  finish({ data: single });
  await screen.findByTestId("container-access-command");
  assert.equal(command(), "ssh -t your-user@203.0.113.10 'sudo docker exec -it sv-app-12-openclaw-1 sh'");
  assert.equal(screen.queryByRole("combobox", { name: m.step2.container }), null);
  assert.equal(screen.queryByRole("combobox", { name: m.step1.pick }), null, "no ready users, no picker");
  assert.equal(screen.getByRole("link", { name: m.step1.open }).getAttribute("href"), "/system-users");
  assert.ok(screen.getByText("exit").tagName === "CODE");
});

test("custom SSH port adds -p", async () => {
  globalThis.__dockerApi.access = async () => ({ data: { ...single, ssh_port: 2200 } });
  show();
  await screen.findByTestId("container-access-command");
  assert.equal(command(), "ssh -t -p 2200 your-user@203.0.113.10 'sudo docker exec -it sv-app-12-openclaw-1 sh'");
});

test("multi container: defaults to main, the select switches; a picked user fills the command", async () => {
  globalThis.__dockerApi.access = async () => ({ data: multi });
  show();
  await screen.findByTestId("container-access-command");
  assert.equal(command(), "ssh -t your-user@203.0.113.10 'sudo docker exec -it sv-app-12-web-1 sh'");

  fireEvent.click(screen.getByRole("combobox", { name: m.step2.container }));
  const options = screen.getAllByRole("option").map((o) => o.textContent);
  assert.deepEqual(options, ["web", "worker"], "service names, main first");
  fireEvent.click(screen.getByRole("option", { name: "worker" }));
  await waitFor(() => assert.equal(command(), "ssh -t your-user@203.0.113.10 'sudo docker exec -it custom-worker sh'"));

  fireEvent.click(screen.getByRole("combobox", { name: m.step1.pick }));
  assert.deepEqual(screen.getAllByRole("option").map((o) => o.textContent), ["adarsh"], "only ready users");
  fireEvent.click(screen.getByRole("option", { name: "adarsh" }));
  await waitFor(() => assert.equal(command(), "ssh -t adarsh@203.0.113.10 'sudo docker exec -it custom-worker sh'"));
});

test("Copy writes the exact command and toasts", async () => {
  const copies = [];
  Object.defineProperty(navigator, "clipboard", { configurable: true, value: { writeText: async (value) => copies.push(value) } });
  show();
  await screen.findByTestId("container-access-command");
  fireEvent.click(screen.getByRole("button", { name: messages.common.copy }));
  await waitFor(() => assert.deepEqual(copies, [command()]));
  assert.deepEqual(globalThis.__toasts, [{ kind: "success", message: m.copied }]);
});

test("no System Users button without that permission", async () => {
  show({ canSeeSystemUsers: false });
  await screen.findByTestId("container-access-command");
  assert.equal(screen.queryByRole("link", { name: m.step1.open }), null);
});

for (const [label, answer] of [
  ["a refused request", async () => { throw { response: { status: 403 } }; }],
  ["a server error", async () => { throw { response: { status: 500 } }; }],
  ["a response that breaks the contract", async () => ({ data: { host: "203.0.113.10", containers: [] } })],
]) {
  test(`hides quietly with a console warning on ${label}`, async () => {
    globalThis.__dockerApi.access = answer;
    const rendered = show();
    await waitFor(() => assert.equal(rendered.container.textContent, ""));
    assert.equal(warnings.length, 1);
  });
}
