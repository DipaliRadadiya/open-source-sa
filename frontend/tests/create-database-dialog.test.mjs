import assert from "node:assert/strict";
import { afterEach, before, beforeEach, test } from "node:test";
import { readFileSync } from "node:fs";
import { installDom, loadComponent } from "./support/dom.mjs";

installDom();
const { createElement } = await import("react");
const { IntlProvider } = await import("use-intl");
const { cleanup, fireEvent, render, screen, waitFor } = await import("@testing-library/react");
const messages = JSON.parse(readFileSync("messages/en.json", "utf8"));
const db = messages.databases;
const v = messages.validation;

let CreateDatabaseDialog, TooltipProvider;
let calls, closes, answers;

before(async () => {
  const stubs = {
    "@/lib/api/databases": "tests/support/database-actions-api-stub.js",
    "@/hooks/use-refresh": "tests/support/use-refresh-stub.js",
    "@/components/ui/app-link": "tests/support/app-link-stub.jsx",
    sonner: "tests/support/sonner-stub.js",
  };
  ({ CreateDatabaseDialog } = await loadComponent("components/databases/create-database-dialog.jsx", stubs));
  ({ TooltipProvider } = await loadComponent("components/ui/tooltip.jsx"));
});

const created = (payload) => ({ data: { database: { id: 9, name: payload.name, engine: payload.engine, users: [] } } });

beforeEach(() => {
  calls = [];
  closes = [];
  answers = [];
  globalThis.__toasts = [];
  globalThis.__refreshes = 0;
  globalThis.__databaseActionsApi = {
    createDatabase: async (payload) => {
      calls.push(payload);
      const next = answers.shift();
      if (next) return next(payload);
      return created(payload);
    },
  };
});
afterEach(() => cleanup());

const MYSQL = { engine: "mysql", driver: "sql", running: true, supports_remote_users: true, charsets: {} };
const PG = { engine: "postgresql", driver: "pgsql", running: true, supports_remote_users: false, charsets: {} };

function mount(props = {}) {
  const all = { open: true, onOpenChange: (next) => closes.push(next), engines: [MYSQL, PG], ...props };
  const tree = (p) => createElement(IntlProvider, { locale: "en", messages, timeZone: "UTC" }, createElement(TooltipProvider, null, createElement(CreateDatabaseDialog, p)));
  const view = render(tree(all));
  return { ...view, rerender: (p) => view.rerender(tree({ ...all, ...p })) };
}

const field = (name) => document.querySelector(`[role=dialog] [name="${name}"]`);
const type = (name, value) => fireEvent.change(field(name), { target: { value } });
const submit = () => fireEvent.click(screen.getByRole("button", { name: db.create.submit }));
const access = (key) => screen.queryByRole("radio", { name: db.access[key].label });
const errors = () => [...document.querySelectorAll("[role=dialog] [data-slot=form-message]")].map((e) => e.textContent);
const userSwitch = () => screen.getByRole("switch", { name: db.create.withUser });

async function pickEngine(name) {
  const combo = screen.getAllByRole("combobox")[0];
  combo.focus();
  fireEvent.keyDown(combo, { key: "Enter" });
  fireEvent.click(await screen.findByRole("option", { name }));
}

test("opens with a user to create: a random username, this-server access, all three choices", async () => {
  mount();
  await waitFor(() => assert.match(field("username").value, /^[a-z0-9_]{4,}$/));
  assert.equal(field("username").disabled, false);
  assert.equal(userSwitch().getAttribute("aria-checked"), "true");
  assert.equal(access("localhost").getAttribute("aria-checked"), "true");
  assert.ok(access("remote") && access("anywhere"));
  assert.equal(field("host"), null);
});

test("a database with its local user is sent, the list re-read, and the credentials shown", async () => {
  mount();
  await waitFor(() => assert.ok(field("username").value));
  type("name", "shop");
  type("username", "shop_user");
  submit();
  await waitFor(() => assert.equal(calls.length, 1));
  assert.deepEqual(calls[0], { name: "shop", engine: "mysql", create_user: { username: "shop_user", connection_preference: "localhost" } });
  await waitFor(() => assert.deepEqual(globalThis.__toasts, [{ kind: "success", message: db.create.created.replace("{name}", "shop") }]));
  assert.equal(globalThis.__refreshes, 1);
  await waitFor(() => assert.equal(screen.queryByRole("button", { name: db.create.submit }), null));
});

test("from one address needs a valid IP and sends it", async () => {
  mount();
  type("name", "shop");
  type("username", "shop_user");
  fireEvent.click(access("remote"));
  await waitFor(() => assert.ok(field("host")));
  submit();
  await waitFor(() => assert.ok(errors().includes(v.required_host), errors().join(" | ")));
  type("host", "300.1.1.1");
  submit();
  await waitFor(() => assert.ok(errors().includes(v.databaseHost)));
  assert.equal(calls.length, 0);
  type("host", " 203.0.113.10 ");
  submit();
  await waitFor(() => assert.equal(calls.length, 1));
  assert.deepEqual(calls[0].create_user, { username: "shop_user", connection_preference: "remote", host: "203.0.113.10" });
});

test("from anywhere sends no host", async () => {
  mount();
  type("name", "shop");
  type("username", "shop_user");
  fireEvent.click(access("remote"));
  await waitFor(() => assert.ok(field("host")));
  type("host", "203.0.113.10");
  fireEvent.click(access("anywhere"));
  await waitFor(() => assert.equal(field("host"), null));
  submit();
  await waitFor(() => assert.equal(calls.length, 1));
  assert.deepEqual(calls[0].create_user, { username: "shop_user", connection_preference: "anywhere" });
});

test("username rules apply before sending", async () => {
  mount();
  type("name", "shop");
  type("username", "");
  submit();
  await waitFor(() => assert.ok(errors().includes(v.required_username)));
  type("username", "shop-user");
  submit();
  await waitFor(() => assert.ok(errors().includes(v.databaseUsername)));
  type("username", "root");
  submit();
  await waitFor(() => assert.ok(errors().includes(v.databaseUsernameReserved)));
  assert.equal(calls.length, 0);
});

test("without a user: the user fields go and no user is sent", async () => {
  mount();
  type("name", "shop");
  type("username", "");
  fireEvent.click(userSwitch());
  await waitFor(() => assert.equal(field("username"), null));
  assert.equal(access("localhost"), null);
  submit();
  await waitFor(() => assert.equal(calls.length, 1));
  assert.deepEqual(calls[0], { name: "shop", engine: "mysql" });
});

test("PostgreSQL offers this server only, and a remote choice is put back to local", async () => {
  mount();
  type("name", "shop");
  type("username", "shop_user");
  fireEvent.click(access("remote"));
  await waitFor(() => assert.ok(field("host")));
  type("host", "203.0.113.10");
  await pickEngine(db.engines.postgresql);
  await waitFor(() => assert.equal(access("remote"), null));
  assert.equal(access("anywhere"), null);
  assert.equal(access("localhost").getAttribute("aria-checked"), "true");
  assert.equal(field("host"), null);
  submit();
  await waitFor(() => assert.equal(calls.length, 1));
  assert.deepEqual(calls[0], { name: "shop", engine: "postgresql", create_user: { username: "shop_user", connection_preference: "localhost" } });
});

test("a server error about the user lands under the user field", async () => {
  answers.push((payload) => {
    throw { config: { data: JSON.stringify(payload) }, response: { status: 422, data: { message: "Invalid.", errors: { "create_user.username": ["That user already exists."] } } } };
  });
  mount();
  type("name", "shop");
  type("username", "taken_user");
  submit();
  const message = await screen.findByText("That user already exists.");
  assert.ok(message.closest("[data-slot=form-item]")?.querySelector('[name="username"]'));
});

test("remote access that needs a restart asks first, then resends with the restart", async () => {
  answers.push(() => {
    throw { response: { status: 409, data: { code: "restart_required", message: "Restart needed." } } };
  });
  mount();
  type("name", "shop");
  type("username", "shop_user");
  fireEvent.click(access("anywhere"));
  submit();
  const confirm = await screen.findByRole("button", { name: db.restartForRemote.confirm });
  await new Promise((resolve) => setTimeout(resolve, 450));
  fireEvent.click(confirm);
  await waitFor(() => assert.equal(calls.length, 2));
  assert.equal(calls[1].create_user.restart_cluster, true);
  assert.equal(calls[1].create_user.connection_preference, "anywhere");
});

test("Cancel, then reopen: empty name and a fresh username", async () => {
  const view = mount();
  type("name", "half");
  type("username", "typed_user");
  fireEvent.click(access("anywhere"));
  fireEvent.click(screen.getByRole("button", { name: db.cancel }));
  assert.deepEqual(closes, [false]);
  view.rerender({ open: false });
  view.rerender({ open: true });
  await waitFor(() => assert.equal(field("name").value, ""));
  await waitFor(() => assert.ok(field("username").value && field("username").value !== "typed_user"));
  assert.equal(access("localhost").getAttribute("aria-checked"), "true");
});

test("the username placeholder is the translated one the user dialogs use", async () => {
  mount();
  await waitFor(() => assert.ok(field("username")));
  assert.equal(field("username").getAttribute("placeholder"), db.create.usernamePlaceholder);
});
