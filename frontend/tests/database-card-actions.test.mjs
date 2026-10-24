import assert from "node:assert/strict";
import { afterEach, before, beforeEach, test } from "node:test";
import { readFileSync } from "node:fs";
import { installDom, loadComponent } from "./support/dom.mjs";

installDom();
const { createElement } = await import("react");
const { IntlProvider } = await import("use-intl");
const { act, cleanup, fireEvent, render, screen, waitFor } = await import("@testing-library/react");
const messages = JSON.parse(readFileSync("messages/en.json", "utf8"));
const database = { id: 17, name: "store", engine: "mariadb", driver: "sql", users_count: 1 };
const site = { id: 11, domain: "db.example.test" };
let DatabaseRowActions, DatabasesCards, TooltipProvider;
let calls, tabs, deletes;

before(async () => {
  const stubs = {
    "@/lib/api/databases": "tests/support/database-actions-api-stub.js",
    "@/components/ui/app-link": "tests/support/app-link-stub.jsx",
    sonner: "tests/support/sonner-stub.js",
  };
  ({ DatabaseRowActions } = await loadComponent("components/databases/database-row-actions.jsx", stubs));
  ({ DatabasesCards } = await loadComponent("components/databases/databases-cards.jsx", stubs));
  ({ TooltipProvider } = await loadComponent("components/ui/tooltip.jsx"));
});
beforeEach(() => {
  calls = [];
  tabs = [];
  deletes = [];
  globalThis.__toasts = [];
  globalThis.__databaseActionsApi = Object.fromEntries(["adminerSso", "phpmyadminSso"].map((name) => [name, async (...args) => {
    calls.push({ name, args });
    return { data: { redirect_url: `https://db.example.test/${name}` } };
  }]));
  window.open = (...args) => { tabs.push(args); return {}; };
});
afterEach(() => cleanup());

function mount(props = {}, cards = false) {
  const child = cards
    ? createElement(DatabasesCards, {
      databases: [database, { ...database, id: 18, name: "second", users_count: 0 }],
      engineName: (name) => name,
      ...props,
    })
    : createElement(DatabaseRowActions, { database, menu: true, ...props });
  render(createElement(IntlProvider, { locale: "en", messages, timeZone: "UTC" },
    createElement(TooltipProvider, null, child)));
}

async function openMenu() {
  const trigger = screen.getByRole("button", { name: "Actions: store" });
  act(() => trigger.focus());
  fireEvent.keyDown(trigger, { key: "Enter" });
  return screen.findByRole("menu");
}

const manageProps = () => ({
  canManage: true,
  phpmyadminSites: [],
  adminerSites: [],
  onDelete: (db) => deletes.push(db.id),
});

test("cards keep only Manage and the overflow trigger in their footer", () => {
  mount(manageProps(), true);
  assert.equal(screen.getAllByRole("link", { name: "Manage" }).length, 2);
  assert.equal(screen.getAllByRole("button", { name: /^Actions:/ }).length, 2);
  assert.equal(screen.queryAllByText(/Install (phpMyAdmin|Adminer)/).length, 0);
  for (const link of screen.getAllByRole("link", { name: "Manage" })) {
    assert.equal(link.parentElement.classList.contains("flex-wrap"), false);
    assert.equal(link.parentElement.parentElement.classList.contains("mt-auto"), true);
  }
});

test("install links remain available inside the card menu", async () => {
  mount(manageProps());
  await openMenu();
  assert.equal(screen.getByRole("menuitem", { name: "Install phpMyAdmin" }).getAttribute("href"), "/applications/create?type=phpmyadmin");
  assert.equal(screen.getByRole("menuitem", { name: "Install Adminer" }).getAttribute("href"), "/applications/create?type=adminer");
  assert.equal(calls.length, 0);
});

test("Delete invokes the existing confirmation callback, not an API", async () => {
  mount(manageProps());
  await openMenu();
  fireEvent.click(screen.getByRole("menuitem", { name: messages.databases.delete.action }));
  assert.deepEqual(deletes, [17]);
  assert.equal(calls.length, 0);
});

test("view-only readers retain Manage but get no install, sign-in or delete actions", () => {
  mount({ ...manageProps(), canManage: false });
  assert.equal(screen.getByRole("link", { name: "Manage" }).getAttribute("href"), "/databases/17");
  assert.equal(screen.queryAllByRole("button").length, 0);
});

test("MongoDB offers only Delete, not incompatible database clients", async () => {
  mount({ ...manageProps(), database: { ...database, engine: "mongodb", driver: "mongo" } });
  await openMenu();
  assert.deepEqual(screen.getAllByRole("menuitem").map((item) => item.textContent.trim()), [messages.databases.delete.action]);
});

test("a card with no supported secondary action has no empty menu", () => {
  mount({ canManage: true, database: { ...database, engine: "mongodb", driver: "mongo" } });
  assert.equal(screen.queryAllByRole("button").length, 0);
});

test("PostgreSQL offers Adminer but not phpMyAdmin", async () => {
  mount({ ...manageProps(), database: { ...database, engine: "postgresql", driver: "pgsql" } });
  await openMenu();
  assert.ok(screen.getByRole("menuitem", { name: "Install Adminer" }));
  assert.equal(screen.queryByRole("menuitem", { name: "Install phpMyAdmin" }), null);
});

test("a failed installation lookup does not claim that a client needs installing", async () => {
  mount({ ...manageProps(), phpmyadminSites: null, adminerSites: null });
  await openMenu();
  assert.ok(screen.getByRole("menuitem", { name: "Open in phpMyAdmin" }));
  assert.equal(screen.queryAllByText(/Install (phpMyAdmin|Adminer)/).length, 0);
});

test("without database users the installed clients are disabled, with their specific explanations", async () => {
  mount({ ...manageProps(), database: { ...database, users_count: 0 }, phpmyadminSites: [site], adminerSites: [site] });
  await openMenu();
  for (const name of ["Open in phpMyAdmin", "Open in Adminer"]) {
    assert.equal(screen.getByRole("menuitem", { name }).getAttribute("aria-disabled"), "true");
  }
  assert.equal(calls.length, 0);
});

for (const [name, api] of [["phpMyAdmin", "phpmyadminSso"], ["Adminer", "adminerSso"]]) {
  test(`${name} single-install sign-in waits for the URL and blocks repeated clicks`, async () => {
    let complete;
    globalThis.__databaseActionsApi[api] = (...args) => {
      calls.push({ name: api, args });
      return new Promise((resolve) => { complete = resolve; });
    };
    mount({ ...manageProps(), phpmyadminSites: [site], adminerSites: [site] });
    await openMenu();
    fireEvent.click(screen.getByRole("menuitem", { name: `Open in ${name}` }));
    assert.equal(screen.getByRole("menuitem", { name: "Signing you in…" }).getAttribute("aria-disabled"), "true");
    assert.equal(tabs.length, 0);
    assert.deepEqual(calls, [{ name: api, args: [17, undefined, undefined] }]);
    await act(async () => complete({ data: { redirect_url: "https://db.example.test/sso" } }));
    await waitFor(() => assert.deepEqual(tabs, [["https://db.example.test/sso", "_blank"]]));
  });

  test(`${name} several-install submenu submits the selected installation`, async () => {
    const sites = [site, { id: 22, domain: "other.example.test" }];
    mount({ ...manageProps(), phpmyadminSites: sites, adminerSites: sites });
    await openMenu();
    const trigger = screen.getByRole("menuitem", { name: `Open in ${name}` });
    act(() => trigger.focus());
    fireEvent.keyDown(trigger, { key: "ArrowRight" });
    const choice = await screen.findByRole("menuitem", { name: "other.example.test" });
    fireEvent.click(choice);
    await waitFor(() => assert.deepEqual(calls, [{ name: api, args: [17, undefined, 22] }]));
    await waitFor(() => assert.equal(tabs.length, 1));
  });
}

test("sign-in errors keep the menu usable and show the backend's reason", async () => {
  globalThis.__databaseActionsApi.adminerSso = async () => {
    throw { response: { data: { message: "Fixture sign-in refused" } } };
  };
  mount({ ...manageProps(), adminerSites: [site] });
  await openMenu();
  fireEvent.click(screen.getByRole("menuitem", { name: "Open in Adminer" }));
  await waitFor(() => assert.deepEqual(globalThis.__toasts, [{ kind: "error", message: "Fixture sign-in refused" }]));
  assert.equal(screen.getByRole("menuitem", { name: "Open in Adminer" }).getAttribute("aria-disabled"), null);
  assert.equal(tabs.length, 0);
});

test("Escape closes the menu and returns keyboard focus to its trigger", async () => {
  mount(manageProps());
  const menu = await openMenu();
  fireEvent.keyDown(menu, { key: "Escape" });
  await waitFor(() => assert.equal(screen.queryByRole("menu"), null));
  await waitFor(() => assert.equal(document.activeElement, screen.getByRole("button", { name: "Actions: store" })));
});

test("table mode keeps the existing visible client and delete buttons", () => {
  mount({ ...manageProps(), menu: false, showManage: false });
  assert.ok(screen.getByRole("link", { name: "Install phpMyAdmin" }));
  assert.ok(screen.getByRole("link", { name: "Install Adminer" }));
  assert.ok(screen.getByRole("button", { name: "Delete store" }));
  assert.equal(screen.queryByRole("button", { name: "Actions: store" }), null);
});
