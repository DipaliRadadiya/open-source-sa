import assert from "node:assert/strict";
import { afterEach, before, beforeEach, test } from "node:test";
import { readFileSync } from "node:fs";
import { installDom, loadComponent } from "./support/dom.mjs";
installDom();
const { createElement } = await import("react");
const { IntlProvider } = await import("use-intl");
const { render, screen, cleanup, fireEvent, waitFor } = await import("@testing-library/react");
const { defaultContainer, shellCommandFor, showsContainerAccess, turnOnNeeds } =
  await import("../lib/applications/container-access.js");
const messages = JSON.parse(readFileSync("messages/en.json", "utf8"));
const m = messages.applications.containerAccess;
const v = messages.validation;
const fill = (text, values) => text.replace(/\{(\w+)\}/g, (_, key) => values[key]);

let ContainerAccessCard;
let TooltipProvider;
let warnings;
const dockerApp = { id: 12, serving_profile: "docker" };
// Synthetic host from the documentation range; never a real server.
const KEY = "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIExampleOnlyNotARealKey card@test";
const owner = {
  username: "oc-app",
  ssh_access: false,
  container_login: false,
  has_password: false,
  ssh_keys: 0,
  other_apps: 0,
};
const commands = {
  shell: "ssh -t oc-app@203.0.113.10",
  exec_example: "ssh oc-app@203.0.113.10 'ls -la'",
  logs: "ssh oc-app@203.0.113.10 @logs --tail 100",
};
const off = {
  app: "openclaw",
  host: "203.0.113.10",
  ssh_port: 22,
  password_login: true,
  owner,
  commands: null,
  cli_examples: [],
  containers: [{ service: "openclaw", name: "sv-app-12-openclaw-1", main: true }],
  ssh_users: [],
};
const on = {
  ...off,
  owner: { ...owner, ssh_access: true, container_login: true, ssh_keys: 1 },
  commands,
};
const multi = {
  ...on,
  app: "ghost",
  containers: [
    { service: "ghost", name: "sv-app-12-ghost-1", main: true },
    { service: "db", name: "sv-app-12-db-1", main: false },
  ],
};
const examples = [
  { label: "List pending browsers", command: "ssh oc-app@203.0.113.10 'openclaw devices list'" },
  { label: "Approve a browser", command: "ssh oc-app@203.0.113.10 'openclaw devices approve <request-id>'" },
];

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
  globalThis.__dockerApi = { access: async () => ({ data: off }) };
  globalThis.__toasts = [];
  warnings = [];
  originalWarn = console.warn;
  console.warn = (...args) => warnings.push(args);
});
afterEach(() => {
  cleanup();
  console.warn = originalWarn;
});

const admin = { canSeeSystemUsers: true, canManageSystemUsers: true, canOpenSecurity: true };
function show(props = admin) {
  return render(createElement(IntlProvider, { locale: "en", messages, timeZone: "UTC" },
    createElement(TooltipProvider, null,
      createElement(ContainerAccessCard, { application: dockerApp, ...props }))));
}
const text = (id) => screen.getByTestId(id).textContent;
// The label's hint button shares its name; the field is the one to type in.
const keyField = () => screen.getByLabelText(m.turnOn.key, { selector: "textarea" });
const passwordField = () => screen.getByLabelText(m.turnOn.password, { selector: "input" });
const answer = (data) => async () => ({ data });
// The form is a dialog behind the card's Turn on button.
async function openTurnOn() {
  fireEvent.click(await screen.findByRole("button", { name: m.turnOn.open }));
  return screen.findByRole("button", { name: m.turnOn.submit });
}
async function showMore() {
  fireEvent.click(await screen.findByRole("button", { name: m.commands.more }));
}
function recordPost(result = async () => ({ data: {} })) {
  const sent = [];
  globalThis.__dockerApi.enableAccess = async (id, payload) => {
    sent.push({ id, payload });
    return result();
  };
  return sent;
}

test("helpers: shell line per container, main by default, what turning on needs", () => {
  assert.equal(shellCommandFor(on, on.containers[0]), "ssh -t oc-app@203.0.113.10");
  assert.equal(shellCommandFor(multi, multi.containers[1]), "ssh -t oc-app@203.0.113.10 @db");
  assert.equal(
    shellCommandFor({ ...multi, commands: { ...commands, shell: "ssh -t -p 2222 oc-app@203.0.113.10 @ghost" } }, multi.containers[1]),
    "ssh -t -p 2222 oc-app@203.0.113.10 @ghost/db",
    "an owner of several apps already picks one; the service goes after it",
  );
  assert.equal(shellCommandFor(off, off.containers[0]), "", "no line while it is off");
  assert.equal(defaultContainer(multi.containers).service, "ghost");
  assert.equal(defaultContainer([{ service: "a", name: "a-1", main: false }]).name, "a-1");

  assert.equal(turnOnNeeds(off), "keyOrPassword");
  assert.equal(turnOnNeeds({ ...off, owner: { ...owner, has_password: true } }), "nothing");
  assert.equal(turnOnNeeds({ ...off, owner: { ...owner, ssh_keys: 2 }, password_login: false }), "nothing");
  assert.equal(turnOnNeeds({ ...off, password_login: false }), "key");
  assert.equal(turnOnNeeds({ ...off, password_login: null }), "nothing", "an unread sshd setting is not refused here");

  assert.equal(showsContainerAccess(dockerApp), true);
  for (const profile of ["php", "node", null, undefined]) {
    assert.equal(showsContainerAccess({ serving_profile: profile }), false);
  }
});

test("renders only for docker apps and never asks the API for others", async () => {
  for (const profile of ["php", "node", null]) {
    const rendered = show({ ...admin, application: { id: 3, serving_profile: profile } });
    assert.equal(rendered.container.textContent, "");
    cleanup();
  }
  assert.equal(globalThis.__dockerApiCalls.access ?? 0, 0);
});

test("off: skeleton first, then a short card, and the turn-on form in a dialog", async () => {
  let finish;
  globalThis.__dockerApi.access = () => new Promise((resolve) => { finish = resolve; });
  show();
  assert.ok(screen.getByLabelText(m.loading));
  finish({ data: off });
  await screen.findByRole("button", { name: m.turnOn.open });

  assert.equal(text("container-access-owner"), `${m.systemUser}oc-app`);
  assert.equal(text("container-access-state"), m.stateOff);
  assert.equal(screen.getByRole("link", { name: "oc-app" }).getAttribute("href"), "/system-users?search=oc-app");
  assert.ok(screen.getByText(fill(m.turnOn.body, { username: "oc-app" })));
  assert.equal(screen.queryByRole("textbox"), null, "no form on the dashboard itself");

  await openTurnOn();
  const dialog = screen.getByRole("dialog");
  assert.ok(dialog.textContent.includes(fill(m.turnOn.title, { username: "oc-app" })));
  assert.ok(keyField());
  assert.ok(passwordField());
  assert.equal(screen.queryByTestId("container-access-shell"), null, "no command before it is on");
  // The old flow is gone.
  assert.equal(screen.queryByText(/sudo docker exec|your-user/), null);
  assert.equal(screen.queryByRole("combobox"), null, "no user picker");
  assert.equal(screen.queryByRole("checkbox"), null, "no confirm without other apps");
});

test("off: an owner that already has a key turns on with one click and an empty body", async () => {
  globalThis.__dockerApi.access = answer({ ...off, owner: { ...owner, ssh_keys: 1 } });
  const sent = recordPost(async () => ({ data: on }));
  show();
  const button = await openTurnOn();
  assert.equal(screen.queryByLabelText(m.turnOn.key, { selector: "textarea" }), null, "nothing to ask for");
  fireEvent.click(button);
  await screen.findByTestId("container-access-shell");
  await waitFor(() => assert.equal(screen.queryByRole("dialog"), null, "the dialog closes once it is on"));
  assert.equal(text("container-access-state"), m.stateOn);
  assert.deepEqual(sent, [{ id: 12, payload: {} }]);
  assert.deepEqual(globalThis.__toasts, [{ kind: "success", message: fill(m.turnOn.done, { username: "oc-app" }) }]);
  assert.equal(globalThis.__dockerApiCalls.access, 1, "the POST answer switched the card, no re-read");
});

test("off: a key or a password is required when the owner has neither, and the key is sent", async () => {
  const sent = recordPost();
  // The POST answer is not the card's shape, so the card re-reads it.
  let reads = 0;
  globalThis.__dockerApi.access = async () => ({ data: reads++ === 0 ? off : on });
  show();
  const button = await openTurnOn();
  fireEvent.click(button);
  await screen.findByText(v.sshKeyOrPassword);
  assert.equal(sent.length, 0);

  fireEvent.change(keyField(), { target: { value: `  ${KEY}\n` } });
  fireEvent.click(button);
  await screen.findByTestId("container-access-shell");
  assert.deepEqual(sent, [{ id: 12, payload: { ssh_key: KEY } }], "trimmed, no password, no confirm");
  assert.equal(reads, 2);
});

test("off: a password alone is enough while password login is on, and the System Users rules apply", async () => {
  const sent = recordPost(async () => ({ data: on }));
  show();
  await openTurnOn();
  fireEvent.change(passwordField(), { target: { value: "short" } });
  fireEvent.click(screen.getByRole("button", { name: m.turnOn.submit }));
  await screen.findByText(v.min10);
  assert.equal(sent.length, 0);

  fireEvent.change(passwordField(), { target: { value: "Container-Login-42" } });
  fireEvent.click(screen.getByRole("button", { name: m.turnOn.submit }));
  await screen.findByTestId("container-access-shell");
  assert.deepEqual(sent, [{ id: 12, payload: { password: "Container-Login-42" } }]);
});

test("off: password login off and no key: no password field, the key is required, Security is linked", async () => {
  globalThis.__dockerApi.access = answer({ ...off, password_login: false });
  const sent = recordPost();
  show();
  await openTurnOn();
  assert.equal(screen.queryByLabelText(m.turnOn.password, { selector: "input" }), null);
  assert.equal(screen.getByRole("link", { name: "Access & security" }).getAttribute("href"), "/settings/security");
  fireEvent.click(screen.getByRole("button", { name: m.turnOn.submit }));
  await screen.findByText(v.sshKeyRequired);
  assert.equal(sent.length, 0);
});

test("off: Security is plain text for someone who cannot open settings", async () => {
  globalThis.__dockerApi.access = answer({ ...off, password_login: false });
  show({ ...admin, canOpenSecurity: false });
  await openTurnOn();
  assert.equal(screen.queryByRole("link", { name: "Access & security" }), null);
  assert.ok(screen.getByText(/Access & security/));
});

test("off: other apps warn, and the confirm box is required and sent", async () => {
  globalThis.__dockerApi.access = answer({ ...off, owner: { ...owner, ssh_keys: 1, other_apps: 2 } });
  const sent = recordPost(async () => ({ data: on }));
  show();
  await openTurnOn();
  assert.ok(screen.getByText(/oc-app also owns 2 other apps\. After this, its SSH login opens this container only/));
  fireEvent.click(screen.getByRole("button", { name: m.turnOn.submit }));
  await screen.findByText(v.confirmContainerOnly);
  assert.equal(sent.length, 0);

  fireEvent.click(screen.getByRole("checkbox", { name: fill(m.turnOn.confirm, { username: "oc-app" }) }));
  fireEvent.click(screen.getByRole("button", { name: m.turnOn.submit }));
  await screen.findByTestId("container-access-shell");
  assert.deepEqual(sent, [{ id: 12, payload: { confirm_container_only: true } }]);
});

test("off: one other app reads in the singular", async () => {
  globalThis.__dockerApi.access = answer({ ...off, owner: { ...owner, ssh_keys: 1, other_apps: 1 } });
  show();
  await openTurnOn();
  await screen.findByText(/oc-app also owns 1 other app\./);
});

test("off: a viewer without System Users manage gets read-only text and no form", async () => {
  show({ canSeeSystemUsers: false, canManageSystemUsers: false });
  await screen.findByTestId("container-access-read-only");
  assert.equal(text("container-access-read-only"), fill(m.readOnly, { username: "oc-app" }));
  assert.equal(screen.queryByRole("button", { name: m.turnOn.open }), null);
  assert.equal(screen.queryByRole("link", { name: "oc-app" }), null, "no System Users link without view");
  assert.ok(screen.getByText("oc-app").tagName === "STRONG");
});

test("POST errors: field errors land on the field, others above the button, a 500 toasts", async () => {
  const sent = recordPost(async () => {
    const error = new Error("422");
    error.response = { status: 422, data: { message: "invalid", errors: { ssh_key: ["That key is already used."] } } };
    error.config = { data: JSON.stringify({ ssh_key: KEY }) };
    throw error;
  });
  show();
  await openTurnOn();
  fireEvent.change(keyField(), { target: { value: KEY } });
  fireEvent.click(screen.getByRole("button", { name: m.turnOn.submit }));
  await screen.findByText("That key is already used.");
  assert.equal(sent.length, 1);

  globalThis.__dockerApi.enableAccess = async () => {
    const error = new Error("422");
    error.response = { status: 422, data: { message: "x", errors: { container_login: ["sshd on this server cannot apply it."] } } };
    error.config = { data: JSON.stringify({ ssh_key: KEY }) };
    throw error;
  };
  fireEvent.click(screen.getByRole("button", { name: m.turnOn.submit }));
  assert.equal((await screen.findByRole("alert")).textContent, "sshd on this server cannot apply it.");

  globalThis.__dockerApi.enableAccess = async () => { throw { response: { status: 500, data: {} } }; };
  fireEvent.click(screen.getByRole("button", { name: m.turnOn.submit }));
  await waitFor(() => assert.deepEqual(globalThis.__toasts.at(-1), { kind: "error", message: m.turnOn.failed }));
  assert.ok(screen.getByRole("button", { name: m.turnOn.submit }), "still off, still offered");
});

test("on: shell, one command and logs exactly as the API wrote them, plus the note", async () => {
  globalThis.__dockerApi.access = answer({ ...on, commands: { ...commands, shell: "ssh -t -p 2222 oc-app@203.0.113.10" } });
  show();
  await screen.findByTestId("container-access-shell");
  assert.equal(text("container-access-shell"), "ssh -t -p 2222 oc-app@203.0.113.10");
  assert.equal(screen.queryByTestId("container-access-exec"), null, "folded until asked for");
  await showMore();
  assert.equal(text("container-access-exec"), commands.exec_example);
  assert.equal(text("container-access-logs"), commands.logs);
  for (const label of [m.commands.shell, m.commands.exec, m.commands.logs]) assert.ok(screen.getByText(label));
  assert.equal(screen.queryByRole("combobox"), null, "one container, no select");
  assert.equal(screen.queryByText(m.commands.appCommands), null, "no app commands without examples");
  assert.ok(screen.getByText(/No sudo needed\. With an SSH key there is no password prompt; otherwise it asks for oc-app's password once\./));
  assert.equal(
    screen.getByRole("link", { name: "For normal server SSH, create a separate system user." }).getAttribute("href"),
    "/system-users",
  );
  assert.equal(screen.queryByRole("button", { name: m.turnOn.open }), null);
});

test("on: several containers get a select; another service adds @<service>", async () => {
  globalThis.__dockerApi.access = answer(multi);
  show();
  await screen.findByTestId("container-access-shell");
  assert.equal(text("container-access-shell"), "ssh -t oc-app@203.0.113.10");
  fireEvent.click(screen.getByRole("combobox", { name: m.commands.container }));
  assert.deepEqual(screen.getAllByRole("option").map((o) => o.textContent), ["ghost", "db"]);
  fireEvent.click(screen.getByRole("option", { name: "db" }));
  await waitFor(() => assert.equal(text("container-access-shell"), "ssh -t oc-app@203.0.113.10 @db"));
  await showMore();
  assert.equal(text("container-access-exec"), commands.exec_example, "only the shell line follows the select");
});

test("on: cli_examples are listed with their full lines", async () => {
  globalThis.__dockerApi.access = answer({ ...on, cli_examples: examples });
  show();
  await screen.findByText(m.commands.appCommands);
  assert.ok(screen.getByText("Approve a browser"));
  assert.deepEqual(
    screen.getAllByTestId("container-access-example").map((node) => node.textContent),
    examples.map((example) => example.command),
  );
});

test("Copy writes the exact line and toasts", async () => {
  const copies = [];
  Object.defineProperty(navigator, "clipboard", { configurable: true, value: { writeText: async (value) => copies.push(value) } });
  globalThis.__dockerApi.access = answer({ ...on, cli_examples: examples });
  show();
  await screen.findByTestId("container-access-shell");
  fireEvent.click(screen.getByRole("button", { name: `Copy: ${m.commands.shell}` }));
  fireEvent.click(screen.getByRole("button", { name: "Copy: Approve a browser" }));
  await waitFor(() => assert.deepEqual(copies, [commands.shell, examples[1].command]));
  assert.deepEqual(globalThis.__toasts, [
    { kind: "success", message: m.copied },
    { kind: "success", message: m.copied },
  ]);
});

test("on: an admin can turn it off; the card goes back to the turn-on step", async () => {
  let reads = 0;
  globalThis.__dockerApi.access = async () => ({ data: reads++ === 0 ? on : { ...off, owner: { ...owner, ssh_keys: 1 } } });
  globalThis.__dockerApi.disableAccess = async () => ({ data: { message: "ok" } });
  show();
  fireEvent.click(await screen.findByRole("button", { name: m.commands.turnOff }));
  await screen.findByRole("button", { name: m.turnOn.open });
  assert.equal(globalThis.__dockerApiCalls.disableAccess, 1);
  assert.deepEqual(globalThis.__toasts, [{ kind: "success", message: fill(m.commands.turnedOff, { username: "oc-app" }) }]);
});

test("on: a failed turn-off toasts and keeps the commands", async () => {
  globalThis.__dockerApi.access = answer(on);
  globalThis.__dockerApi.disableAccess = async () => { throw { response: { status: 500, data: {} } }; };
  show();
  fireEvent.click(await screen.findByRole("button", { name: m.commands.turnOff }));
  await waitFor(() => assert.deepEqual(globalThis.__toasts, [{ kind: "error", message: m.commands.turnOffFailed }]));
  assert.ok(screen.getByTestId("container-access-shell"));
});

test("on: a viewer sees the commands but no Turn off", async () => {
  globalThis.__dockerApi.access = answer(on);
  show({ canSeeSystemUsers: true, canManageSystemUsers: false });
  await screen.findByTestId("container-access-shell");
  assert.equal(screen.queryByRole("button", { name: m.commands.turnOff }), null);
});

for (const [label, reply] of [
  ["a refused request", async () => { throw { response: { status: 403 } }; }],
  ["a server error", async () => { throw { response: { status: 500 } }; }],
  ["a response that breaks the contract", async () => ({ data: { host: "203.0.113.10", containers: [] } })],
  ["container login on with no commands", async () => ({ data: { ...on, commands: null } })],
]) {
  test(`hides quietly with a console warning on ${label}`, async () => {
    globalThis.__dockerApi.access = reply;
    const rendered = show();
    await waitFor(() => assert.equal(rendered.container.textContent, ""));
    assert.equal(warnings.length, 1);
  });
}
