import assert from "node:assert/strict";
import { afterEach, before, beforeEach, test } from "node:test";
import { readdirSync, readFileSync } from "node:fs";
import { installDom, loadComponent } from "./support/dom.mjs";

installDom();
const { createElement } = await import("react");
const { IntlProvider } = await import("use-intl");
const { act, cleanup, fireEvent, render, screen, waitFor } = await import("@testing-library/react");
const messages = JSON.parse(readFileSync("messages/en.json", "utf8"));
const common = messages.common;

let RestoreBackupDialog, RestoreFileBackupDialog, TooltipProvider;
let calls, closes, restored, answer;

before(async () => {
  const stubs = {
    "@/lib/api/environment": "tests/support/restore-api-stub.js",
    "@/lib/api/files": "tests/support/restore-api-stub.js",
    sonner: "tests/support/sonner-stub.js",
  };
  ({ RestoreBackupDialog } = await loadComponent("components/applications/environment/restore-backup-dialog.jsx", stubs));
  ({ RestoreFileBackupDialog } = await loadComponent("components/applications/files/restore-file-backup-dialog.jsx", stubs));
  ({ TooltipProvider } = await loadComponent("components/ui/tooltip.jsx"));
});

beforeEach(() => {
  calls = [];
  closes = [];
  restored = [];
  answer = () => ({ environment: { raw: "APP_ENV=production" } });
  globalThis.__toasts = [];
  globalThis.__restoreApi = {
    restoreEnvironment: async (...args) => {
      calls.push({ name: "restoreEnvironment", args });
      return answer();
    },
    restoreFileContent: async (...args) => {
      calls.push({ name: "restoreFileContent", args });
      return answer();
    },
  };
});
afterEach(() => cleanup());

const BACKUPS = [
  { name: ".env.bak-20261120-101500", created_at: "20-11-2026 10:15:00" },
  { name: ".env.bak-20261119-093000", created_at: "19-11-2026 09:30:00" },
];

const KINDS = [
  {
    label: "environment",
    m: messages.applications.environment,
    Dialog: () => RestoreBackupDialog,
    props: { appId: 5 },
    api: "restoreEnvironment",
    sent: (backup, safetyCopy) => [5, { backup, restart: false, safetyCopy }],
  },
  {
    label: "file",
    m: messages.applications.files,
    Dialog: () => RestoreFileBackupDialog,
    props: { appId: 5, path: "public_html/index.php" },
    api: "restoreFileContent",
    sent: (backup, safetyCopy) => [5, "public_html/index.php", backup, { safetyCopy }],
  },
];

function mount(kind, props = {}) {
  const all = {
    ...kind.props,
    backups: BACKUPS,
    open: true,
    onOpenChange: (next) => closes.push(next),
    onRestored: (...args) => restored.push(args),
    ...props,
  };
  const tree = (p) =>
    createElement(IntlProvider, { locale: "en", messages, timeZone: "UTC" }, createElement(TooltipProvider, null, createElement(kind.Dialog(), p)));
  const view = render(tree(all));
  return { ...view, rerender: (p) => view.rerender(tree({ ...all, ...p })) };
}

const settle = () => new Promise((resolve) => setTimeout(resolve, 450));
const restoreButton = (kind) => screen.getByRole("button", { name: kind.m.restore.action });
const option = (i) => screen.getAllByRole("radio")[i];

for (const kind of KINDS) {
  test(`${kind.label}: lists every backup; Restore stays off until one is picked`, () => {
    mount(kind);
    assert.ok(screen.getByText(kind.m.restore.title));
    assert.equal(screen.getAllByRole("radio").length, 2);
    assert.equal(restoreButton(kind).disabled, true);
    fireEvent.click(option(1));
    assert.equal(option(1).getAttribute("aria-checked"), "true");
    assert.equal(option(0).getAttribute("aria-checked"), "false");
    assert.equal(restoreButton(kind).disabled, false);
  });

  test(`${kind.label}: restoring sends the picked backup with a safety copy, then closes`, async () => {
    mount(kind);
    fireEvent.click(option(1));
    await settle();
    fireEvent.click(restoreButton(kind));
    await waitFor(() => assert.deepEqual(closes, [false]));
    assert.deepEqual(calls, [{ name: kind.api, args: kind.sent(BACKUPS[1].name, true) }]);
    assert.deepEqual(globalThis.__toasts, [{ kind: "success", message: kind.m.restore.done }]);
    assert.equal(restored.length, 1);
  });

  test(`${kind.label}: turning the safety copy off says so and sends it`, async () => {
    mount(kind);
    assert.ok(screen.getByText(kind.m.restore.subtitle));
    fireEvent.click(screen.getByRole("switch", { name: common.keepFileCopy }));
    assert.ok(screen.getByText(kind.m.restore.subtitleNoCopy));
    assert.ok(screen.getByText(common.keepFileCopyOff));
    fireEvent.click(option(0));
    await settle();
    fireEvent.click(restoreButton(kind));
    await waitFor(() => assert.equal(calls.length, 1));
    assert.deepEqual(calls[0].args, kind.sent(BACKUPS[0].name, false));
  });

  test(`${kind.label}: a refused restore shows the API's reason and keeps the dialog open`, async () => {
    answer = () => {
      throw { response: { status: 422, data: { message: "That backup is gone." } } };
    };
    mount(kind);
    fireEvent.click(option(0));
    await settle();
    fireEvent.click(restoreButton(kind));
    await waitFor(() => assert.equal(globalThis.__toasts.length, 1));
    assert.equal(globalThis.__toasts[0].kind, "error");
    assert.match(globalThis.__toasts[0].message, /That backup is gone\./);
    assert.deepEqual(closes, []);
    assert.equal(restored.length, 0);
    await waitFor(() => assert.equal(restoreButton(kind).disabled, false));
  });

  test(`${kind.label}: cannot be closed while the restore runs`, async () => {
    let finish;
    answer = () => new Promise((resolve) => (finish = resolve));
    mount(kind);
    fireEvent.click(option(0));
    await settle();
    fireEvent.click(restoreButton(kind));
    await waitFor(() => assert.equal(calls.length, 1));
    fireEvent.click(screen.getByRole("button", { name: kind.m.cancel }));
    fireEvent.keyDown(screen.getByRole("alertdialog"), { key: "Escape" });
    assert.deepEqual(closes, []);
    await act(async () => finish({}));
    await waitFor(() => assert.deepEqual(closes, [false]));
  });

  test(`${kind.label}: closing forgets the pick and turns the safety copy back on`, async () => {
    const view = mount(kind);
    fireEvent.click(option(0));
    fireEvent.click(screen.getByRole("switch", { name: common.keepFileCopy }));
    fireEvent.click(screen.getByRole("button", { name: kind.m.cancel }));
    assert.deepEqual(closes, [false]);
    view.rerender({ open: false });
    view.rerender({ open: true });
    await waitFor(() => assert.equal(restoreButton(kind).disabled, true));
    assert.equal(screen.getByRole("switch", { name: common.keepFileCopy }).getAttribute("aria-checked"), "true");
  });
}

test("environment: the row shows the date and the file name under it", () => {
  mount(KINDS[0]);
  assert.match(option(0).textContent, /\.env\.bak-20261120-101500/);
  assert.match(option(0).textContent, /2026/);
});

test("file: the row shows the date; the file name is in a hover hint", async () => {
  mount(KINDS[1]);
  assert.doesNotMatch(option(0).textContent, /\.env\.bak/);
  assert.match(option(0).textContent, /2026/);
  const date = option(0).firstElementChild;
  fireEvent.pointerMove(date, { pointerType: "mouse" });
  assert.ok((await screen.findAllByText(BACKUPS[0].name)).length > 0);
});

test("file: onRestored gets no arguments; the file editor reloads on its own", async () => {
  mount(KINDS[1]);
  fireEvent.click(option(0));
  await settle();
  fireEvent.click(restoreButton(KINDS[1]));
  await waitFor(() => assert.equal(restored.length, 1));
  assert.deepEqual(restored, [[]]);
});

test("environment: an answer without an environment passes null", async () => {
  answer = () => ({});
  mount(KINDS[0]);
  fireEvent.click(option(0));
  await settle();
  fireEvent.click(restoreButton(KINDS[0]));
  await waitFor(() => assert.equal(restored.length, 1));
  assert.deepEqual(restored, [[null]]);
});

test("a backup with no readable date shows its file name", () => {
  const odd = [{ name: ".env.bak-old", created_at: null }];
  mount(KINDS[0], { backups: odd });
  assert.match(option(0).textContent, /\.env\.bak-old/);
  cleanup();
  mount(KINDS[1], { backups: odd });
  assert.equal(option(0).textContent, ".env.bak-old");
});

test("environment: the restart choice appears only when the app needs it, and is sent", async () => {
  mount(KINDS[0]);
  assert.equal(screen.queryByRole("checkbox"), null);
  cleanup();
  mount(KINDS[0], { requiresRestart: true });
  const box = screen.getByRole("checkbox", { name: messages.applications.environment.restore.restart });
  fireEvent.click(box);
  fireEvent.click(option(0));
  await settle();
  fireEvent.click(restoreButton(KINDS[0]));
  await waitFor(() => assert.equal(calls.length, 1));
  assert.deepEqual(calls[0].args, [5, { backup: BACKUPS[0].name, restart: true, safetyCopy: true }]);
  assert.deepEqual(restored, [[{ raw: "APP_ENV=production" }]]);
});

test("environment: closing unticks restart", async () => {
  const view = mount(KINDS[0], { requiresRestart: true });
  fireEvent.click(screen.getByRole("checkbox", { name: messages.applications.environment.restore.restart }));
  fireEvent.click(screen.getByRole("button", { name: messages.applications.environment.cancel }));
  view.rerender({ open: false });
  view.rerender({ open: true });
  await waitFor(() =>
    assert.equal(
      screen.getByRole("checkbox", { name: messages.applications.environment.restore.restart }).getAttribute("aria-checked"),
      "false",
    ),
  );
});

test("every key the shared dialog reads exists for both callers, in every locale", () => {
  // lint can't check a namespace passed as a prop, so this does
  const src = readFileSync("components/backups/restore-version-dialog.jsx", "utf8");
  const keys = [...new Set([...src.matchAll(/\btx\("([^"]+)"\)/g)].map((m) => m[1]))];
  assert.ok(keys.length >= 9, keys.join());
  const get = (o, k) => k.split(".").reduce((v, p) => v?.[p], o);
  const locales = readdirSync("messages").filter((f) => f.endsWith(".json"));
  assert.equal(locales.length, 8);
  for (const locale of locales) {
    const file = JSON.parse(readFileSync(`messages/${locale}`, "utf8"));
    for (const ns of ["applications.environment", "applications.files"]) {
      for (const key of keys) {
        if (ns === "applications.files" && key.startsWith("restore.restart")) continue;
        assert.equal(typeof get(file, `${ns}.${key}`), "string", `${locale} ${ns}.${key}`);
      }
    }
  }
});
