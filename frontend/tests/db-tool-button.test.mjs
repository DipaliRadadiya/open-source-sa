import assert from "node:assert/strict";
import { afterEach, before, beforeEach, test } from "node:test";
import { readFileSync } from "node:fs";
import { installDom, loadComponent } from "./support/dom.mjs";

installDom();
const { createElement } = await import("react");
const { IntlProvider } = await import("use-intl");
const { act, cleanup, fireEvent, render, screen, waitFor } = await import("@testing-library/react");
const messages = JSON.parse(readFileSync("messages/en.json", "utf8"));

let PhpmyadminButton, AdminerButton, TooltipProvider;
let calls, tabs, ssoAnswer;

before(async () => {
  const stubs = {
    "@/lib/api/databases": "tests/support/database-actions-api-stub.js",
    "@/components/ui/app-link": "tests/support/app-link-stub.jsx",
    sonner: "tests/support/sonner-stub.js",
  };
  ({ PhpmyadminButton } = await loadComponent("components/databases/phpmyadmin-button.jsx", stubs));
  ({ AdminerButton } = await loadComponent("components/databases/adminer-button.jsx", stubs));
  ({ TooltipProvider } = await loadComponent("components/ui/tooltip.jsx"));
});

beforeEach(() => {
  calls = [];
  tabs = [];
  ssoAnswer = (name) => ({ data: { redirect_url: `https://tool.example.test/${name}` } });
  globalThis.__toasts = [];
  globalThis.__databaseActionsApi = Object.fromEntries(
    ["phpmyadminSso", "adminerSso"].map((name) => [
      name,
      async (...args) => {
        calls.push({ name, args });
        return ssoAnswer(name);
      },
    ]),
  );
  window.open = (...args) => {
    tabs.push(args);
    return {};
  };
});
afterEach(() => cleanup());

const TOOLS = [
  { name: "phpMyAdmin", Button: () => PhpmyadminButton, sso: "phpmyadminSso", type: "phpmyadmin", ns: "phpmyadmin" },
  { name: "Adminer", Button: () => AdminerButton, sso: "adminerSso", type: "adminer", ns: "adminer" },
];
const mysql = { id: 7, name: "shop", engine: "mysql", driver: "sql", users_count: 1 };
const one = [{ id: 11, domain: "tool-a.example.test" }];
const two = [...one, { id: 12, domain: "tool-b.example.test" }];

function mount(Button, props) {
  return render(
    createElement(
      IntlProvider,
      { locale: "en", messages, timeZone: "UTC" },
      createElement(TooltipProvider, null, createElement(Button, { database: mysql, canManage: true, sites: one, ...props })),
    ),
  );
}

for (const tool of TOOLS) {
  const m = messages.databases[tool.ns];

  test(`${tool.name}: nothing for a role that cannot manage`, () => {
    const { container } = mount(tool.Button(), { canManage: false });
    assert.equal(container.textContent, "");
  });

  test(`${tool.name}: nothing for MongoDB`, () => {
    const { container } = mount(tool.Button(), { database: { ...mysql, engine: "mongodb", driver: "mongodb" } });
    assert.equal(container.textContent, "");
  });

  test(`${tool.name}: not installed links to the create page with its own type`, () => {
    mount(tool.Button(), { sites: [] });
    const link = screen.getByRole("link", { name: m.install });
    assert.equal(link.getAttribute("href"), `/applications/create?type=${tool.type}`);
  });

  test(`${tool.name}: no database user means a disabled button with the reason`, async () => {
    mount(tool.Button(), { database: { ...mysql, users_count: 0 } });
    const button = screen.getByRole("button", { name: m.open });
    assert.equal(button.disabled, true);
    act(() => button.parentElement.focus());
    assert.ok((await screen.findAllByText(m.needsUser)).length > 0);
    assert.equal(calls.length, 0);
  });

  test(`${tool.name}: compact shows the tool's own name`, () => {
    mount(tool.Button(), { compact: true });
    assert.ok(screen.getByRole("button", { name: tool.name }));
  });

  test(`${tool.name}: one installation signs in with its own endpoint and opens the URL in a new tab`, async () => {
    mount(tool.Button(), {});
    fireEvent.click(screen.getByRole("button", { name: m.open }));
    assert.ok(screen.getByRole("button", { name: m.signingShort }).disabled, "busy while signing in");
    await waitFor(() => assert.equal(tabs.length, 1));
    assert.deepEqual(calls, [{ name: tool.sso, args: [7, undefined, undefined] }]);
    assert.deepEqual(tabs[0], [`https://tool.example.test/${tool.sso}`, "_blank"]);
    await waitFor(() => assert.equal(screen.getByRole("button", { name: m.open }).disabled, false));
  });

  test(`${tool.name}: several installations ask which one and send its id`, async () => {
    mount(tool.Button(), { sites: two });
    const trigger = screen.getByRole("button", { name: m.open });
    act(() => trigger.focus());
    fireEvent.keyDown(trigger, { key: "Enter" });
    await screen.findByText(m.choose);
    fireEvent.click(screen.getByRole("menuitem", { name: "tool-b.example.test" }));
    await waitFor(() => assert.equal(tabs.length, 1));
    assert.deepEqual(calls, [{ name: tool.sso, args: [7, undefined, 12] }]);
  });

  test(`${tool.name}: a refused sign-in shows the API's reason and opens no tab`, async () => {
    ssoAnswer = () => {
      throw { response: { status: 422, data: { message: "No HTTPS on this site." } } };
    };
    mount(tool.Button(), {});
    fireEvent.click(screen.getByRole("button", { name: m.open }));
    await waitFor(() => assert.equal(globalThis.__toasts.length, 1));
    assert.equal(globalThis.__toasts[0].kind, "error");
    assert.match(globalThis.__toasts[0].message, /No HTTPS on this site\./);
    assert.equal(tabs.length, 0);
    assert.equal(screen.getByRole("button", { name: m.open }).disabled, false);
  });

  test(`${tool.name}: an answer without a URL is an error, not a blank tab`, async () => {
    ssoAnswer = () => ({ data: {} });
    mount(tool.Button(), {});
    fireEvent.click(screen.getByRole("button", { name: m.open }));
    await waitFor(() => assert.equal(globalThis.__toasts.length, 1));
    assert.equal(globalThis.__toasts[0].message, m.failed);
    assert.equal(tabs.length, 0);
  });
}

test("PostgreSQL: Adminer is offered, phpMyAdmin is not", () => {
  const pg = { ...mysql, engine: "postgresql", driver: "pgsql" };
  const { container } = mount(PhpmyadminButton, { database: pg });
  assert.equal(container.textContent, "");
  cleanup();
  mount(AdminerButton, { database: pg });
  assert.ok(screen.getByRole("button", { name: messages.databases.adminer.open }));
});

test("a failed installation lookup (sites null) never offers an install", () => {
  mount(PhpmyadminButton, { sites: null });
  assert.equal(screen.queryByRole("link", { name: messages.databases.phpmyadmin.install }), null);
  assert.ok(screen.getByRole("button", { name: messages.databases.phpmyadmin.open }));
  cleanup();
  // Adminer on MySQL is hidden when the lookup failed; on PostgreSQL it still opens.
  const { container } = mount(AdminerButton, { sites: null });
  assert.equal(container.textContent, "");
  cleanup();
  mount(AdminerButton, { sites: null, database: { ...mysql, engine: "postgresql", driver: "pgsql" } });
  assert.ok(screen.getByRole("button", { name: messages.databases.adminer.open }));
});
