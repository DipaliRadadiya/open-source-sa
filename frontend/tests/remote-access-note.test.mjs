import assert from "node:assert/strict";
import { afterEach, before, beforeEach, test } from "node:test";
import { readFileSync } from "node:fs";
import { installDom, loadComponent } from "./support/dom.mjs";

installDom();
const { createElement } = await import("react");
const { IntlProvider } = await import("use-intl");
const { cleanup, fireEvent, render, screen, waitFor } = await import("@testing-library/react");
const messages = JSON.parse(readFileSync("messages/en.json", "utf8"));
const ra = messages.databases.remoteAccess;

let RemoteAccessNotes, remoteAccessNote;
let calls, answer;

before(async () => {
  ({ RemoteAccessNotes } = await loadComponent("components/databases/remote-access-note.jsx", {
    "@/lib/api/databases": "tests/support/database-actions-api-stub.js",
    "@/hooks/use-refresh": "tests/support/use-refresh-stub.js",
    sonner: "tests/support/sonner-stub.js",
  }));
  ({ remoteAccessNote } = await loadComponent("lib/databases/remote-access.js"));
});

beforeEach(() => {
  calls = [];
  answer = async () => ({ data: { remote_access: { ...CLOSABLE, listening_remotely: false, can_close: false } } });
  globalThis.__toasts = [];
  globalThis.__refreshesAtToast = [];
  const push = globalThis.__toasts.push.bind(globalThis.__toasts);
  globalThis.__toasts.push = (entry) => {
    globalThis.__refreshesAtToast.push(globalThis.__refreshes);
    return push(entry);
  };
  globalThis.__refreshes = 0;
  globalThis.__databaseActionsApi = {
    closeRemoteAccess: async (engine) => {
      calls.push(engine);
      globalThis.__refreshesAtCall = globalThis.__refreshes;
      return answer(engine);
    },
  };
});
afterEach(() => cleanup());

const CLOSABLE = { engine: "mariadb", available: true, listening_remotely: true, opened_by_panel: true, remote_users: 0, can_close: true };
const SERVER_OPENED = { engine: "postgresql", available: true, listening_remotely: true, opened_by_panel: false, remote_users: 0, can_close: false };

function mount(props) {
  return render(
    createElement(IntlProvider, { locale: "en", messages, timeZone: "UTC" }, createElement(RemoteAccessNotes, props)),
  );
}

const fill = (s, name) => s.replace("{name}", name);
const closeButton = () => screen.queryByRole("button", { name: ra.close });

test("which note each state gets", () => {
  assert.equal(remoteAccessNote(CLOSABLE), "close");
  assert.equal(remoteAccessNote(SERVER_OPENED), "server");
  assert.equal(remoteAccessNote({ ...CLOSABLE, can_close: false, remote_users: 1 }), null, "a remote user still needs it");
  assert.equal(remoteAccessNote({ ...SERVER_OPENED, remote_users: 2 }), null, "open on purpose for its remote users");
  assert.equal(remoteAccessNote({ ...CLOSABLE, can_close: false, listening_remotely: false }), null, "already local");
  assert.equal(remoteAccessNote({ ...CLOSABLE, can_close: false, listening_remotely: null, opened_by_panel: null }), null, "engine not answering");
  assert.equal(remoteAccessNote(null), null);
});

test("nothing renders when no engine needs a note", () => {
  const { container } = mount({ states: [{ ...CLOSABLE, can_close: false, listening_remotely: false }], canManage: true });
  assert.equal(container.textContent, "");
});

test("closable engine: note with the button for a manager", () => {
  mount({ states: [CLOSABLE], canManage: true });
  assert.ok(screen.getByText(fill(ra.stillOpen, "MariaDB")));
  assert.ok(closeButton());
});

test("closable engine: a viewer sees the note but no button", () => {
  mount({ states: [CLOSABLE], canManage: false });
  assert.ok(screen.getByText(fill(ra.stillOpen, "MariaDB")));
  assert.equal(closeButton(), null);
});

test("opened by the server's own settings: information only, never a button", () => {
  mount({ states: [SERVER_OPENED], canManage: true });
  assert.ok(screen.getByText(fill(ra.openedByServer, "PostgreSQL")));
  assert.equal(closeButton(), null);
});

test("Close asks first, and Cancel sends nothing", async () => {
  mount({ states: [CLOSABLE], canManage: true });
  fireEvent.click(closeButton());
  const dialog = await screen.findByRole("alertdialog");
  assert.match(dialog.textContent, new RegExp(fill(ra.confirmTitle, "MariaDB").replace("?", "\\?")));
  assert.match(dialog.textContent, /restarts/);
  fireEvent.click(screen.getByRole("button", { name: messages.databases.cancel }));
  await waitFor(() => assert.equal(screen.queryByRole("alertdialog"), null));
  assert.deepEqual(calls, []);
});

async function confirmClose() {
  fireEvent.click(closeButton());
  await screen.findByRole("alertdialog");
  await new Promise((r) => setTimeout(r, 450)); // ConfirmDialog ignores clicks in its first 400 ms
  fireEvent.click(screen.getByRole("button", { name: ra.confirm }));
}

test("confirming closes it once, says so and re-reads the page", async () => {
  mount({ states: [CLOSABLE], canManage: true });
  await confirmClose();
  await waitFor(() => assert.equal(globalThis.__toasts.length, 1));
  assert.deepEqual(globalThis.__refreshesAtToast, [1], "the page is re-read before the toast");
  assert.equal(globalThis.__refreshesAtCall, 0, "and only after the close answered");
  assert.deepEqual(calls, ["mariadb"]);
  assert.deepEqual([...globalThis.__toasts], [{ kind: "success", message: fill(ra.closed, "MariaDB") }]);
  await waitFor(() => assert.equal(screen.queryByRole("alertdialog"), null));
});

test("a refusal shows the server's reason and keeps the page as it is", async () => {
  const reason = "Remote users still need MariaDB to accept outside connections: app_remote. Delete them or set them to Local first.";
  answer = async () => {
    const error = new Error("422");
    error.response = { status: 422, data: { message: reason, errors: { engine: [reason] } } };
    throw error;
  };
  mount({ states: [CLOSABLE], canManage: true });
  await confirmClose();
  await waitFor(() => assert.equal(globalThis.__toasts.length, 1));
  assert.deepEqual([...globalThis.__toasts], [{ kind: "error", message: reason }]);
  assert.equal(globalThis.__refreshes, 0);
});
