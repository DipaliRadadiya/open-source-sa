// Ubuntu's first-boot updates (system_updates_running) and the restart 503 right after an install.
import assert from "node:assert/strict";
import { afterEach, before, beforeEach, mock, test } from "node:test";
import { readFileSync } from "node:fs";
import { installDom, loadComponent } from "./support/dom.mjs";

installDom();
const { createElement } = await import("react");
const { IntlProvider } = await import("use-intl");
const { act, render, screen, cleanup, fireEvent, waitFor } = await import("@testing-library/react");
const { setupResponseSchema } = await import("../lib/schemas/setup.js");
const { isRestartBlip, RESTART_RETRY_MS, RETRY_RESTART } = await import("../lib/api/restart-blip.js");
const { apiMessage } = await import("../lib/api/error-message.js");
const { serverRestartingMessage } = await import("../lib/api/generic-error.js");

const LOCALES = ["en", "es", "de", "fr", "hi", "pt", "ja", "ru"];
const messages = Object.fromEntries(LOCALES.map((l) => [l, JSON.parse(readFileSync(`messages/${l}.json`, "utf8"))]));
const en = messages.en;

const blip = (data = "<html>503 Service Temporarily Unavailable</html>") => ({ response: { status: 503, data } });

const mariadb = {
  key: "database",
  title: "Database",
  state: "pending",
  recommended: true,
  action: null,
  options: [],
};
const setupWith = (values = {}) =>
  setupResponseSchema.parse({ setup: { complete: false, status: "idle", components: [mariadb], ...values } }).setup;

test("system_updates_running is read as a boolean and never breaks the page", () => {
  assert.equal(setupWith({ system_updates_running: true }).system_updates_running, true);
  assert.equal(setupWith({ system_updates_running: false }).system_updates_running, false);
  assert.equal(setupWith().system_updates_running, false, "an older API without the field");
  assert.equal(setupWith({ system_updates_running: "yes" }).system_updates_running, false, "junk is not a note");
});

test("a restart 503 is one without the panel's own sentence", () => {
  assert.equal(isRestartBlip(blip()), true, "nginx's HTML page");
  assert.equal(isRestartBlip(blip({ message: "Service Unavailable" })), true, "Laravel's bare page");
  assert.equal(isRestartBlip(blip({})), true);
  assert.equal(isRestartBlip(blip({ message: "Another operation is running. Try again shortly." })), false, "a panel busy answer");
  assert.equal(isRestartBlip({ response: { status: 500, data: {} } }), false);
  assert.equal(isRestartBlip({ message: "Network Error" }), false);
});

test("a restart 503 that is left says try again in a moment; a busy 503 keeps its own words", () => {
  assert.equal(apiMessage(blip(), "Could not install."), serverRestartingMessage());
  assert.equal(apiMessage(blip({ message: "Busy, try later." }), "Could not install."), "Busy, try later.");
  for (const locale of LOCALES) {
    const sentence = messages[locale].errors.serverRestarting;
    assert.ok(sentence && sentence !== (locale === "en" ? "" : en.errors.serverRestarting), `${locale}: errors.serverRestarting`);
    const note = messages[locale].setup.systemUpdatesRunning;
    assert.ok(note && note.includes("Ubuntu"), `${locale}: setup.systemUpdatesRunning keeps the name Ubuntu`);
    if (locale !== "en") assert.notEqual(note, en.setup.systemUpdatesRunning, `${locale}: translated`);
  }
});

test("install calls opt in to the replay; nothing else does", () => {
  const read = (p) => readFileSync(p, "utf8");
  assert.match(read("lib/api/databases.js"), /api\.post\(`\/databases\/engines\/\$\{encodeURIComponent\(engine\)\}`, undefined, RETRY_RESTART\)/);
  assert.match(read("lib/api/php.js"), /api\.post\("\/php\/versions", \{ version \}, RETRY_RESTART\)/);
  assert.match(read("lib/api/node.js"), /api\.post\("\/node\/versions", \{ version \}, RETRY_RESTART\)/);
  assert.match(read("lib/api/fail2ban.js"), /api\.post\("\/fail2ban\/install", undefined, RETRY_RESTART\)/);
  assert.match(read("lib/api/setup.js"), /\.\.\.RETRY_RESTART,/);
  assert.deepEqual(RETRY_RESTART, { retryRestart: true });
});

test("the client replays a restart 503 once, then lets the error stand", async () => {
  const { api } = await import("../lib/api/client.js");
  const original = api.defaults.adapter;
  mock.timers.enable({ apis: ["setTimeout"] });
  try {
    const run = async (answers, config) => {
      let calls = 0;
      api.defaults.adapter = async (cfg) => {
        const status = answers[Math.min(calls++, answers.length - 1)];
        if (status === 200) return { data: { ok: true }, status, statusText: "OK", headers: {}, config: cfg };
        const error = new Error(`Request failed with status code ${status}`);
        Object.assign(error, { config: cfg, isAxiosError: true, response: { status, data: "<html>503</html>", headers: {}, config: cfg } });
        throw error;
      };
      const pending = api.post("/php/versions", { version: "8.4" }, config).then(
        (r) => ({ ok: r.status }),
        (e) => ({ failed: e.response?.status }),
      );
      for (let i = 0; i < 5; i += 1) await new Promise((r) => setImmediate(r));
      mock.timers.tick(RESTART_RETRY_MS);
      return { result: await pending, calls };
    };

    assert.deepEqual(await run([503, 200], RETRY_RESTART), { result: { ok: 200 }, calls: 2 }, "one blip, then installed");
    assert.deepEqual(await run([503, 503, 200], RETRY_RESTART), { result: { failed: 503 }, calls: 2 }, "replayed once only");
    assert.deepEqual(await run([503, 200], undefined), { result: { failed: 503 }, calls: 1 }, "not opted in: no replay");
  } finally {
    mock.timers.reset();
    api.defaults.adapter = original;
  }
});

let SetupChecklist;
before(async () => {
  SetupChecklist = (await loadComponent("components/setup/setup-checklist.jsx", {
    "@/lib/api/setup": "tests/support/setup-api-stub.js",
    "@/components/ui/app-link": "tests/support/app-link-stub.jsx",
    "next/navigation": "tests/support/next-navigation-stub.js",
    sonner: "tests/support/sonner-stub.js",
  })).SetupChecklist;
});
beforeEach(() => {
  globalThis.__setupApi = {};
  globalThis.__setupApiCalls = {};
  globalThis.__toasts = [];
});
afterEach(() => {
  cleanup();
});

const show = (setup) =>
  render(createElement(IntlProvider, { locale: "en", messages: en }, createElement(SetupChecklist, { initialSetup: setup })));

// Records the page's polls instead of waiting for them; `fire()` runs the newest live one.
function capturePolls() {
  const polls = [];
  const realSet = globalThis.setInterval;
  const realClear = globalThis.clearInterval;
  globalThis.setInterval = (fn, ms) => {
    const poll = { fn, ms, live: true };
    polls.push(poll);
    return poll;
  };
  globalThis.clearInterval = (id) => {
    if (polls.includes(id)) id.live = false;
    else realClear(id);
  };
  return {
    live: () => polls.filter((p) => p.live),
    fire: () => act(async () => polls.filter((p) => p.live).at(-1).fn()),
    restore: () => {
      globalThis.setInterval = realSet;
      globalThis.clearInterval = realClear;
    },
  };
}

test("setup page: the note shows while Ubuntu updates and goes away by itself", async () => {
  const polls = capturePolls();
  try {
    globalThis.__setupApi.fetchSetup = async () => setupWith({ system_updates_running: false });
    show(setupWith({ system_updates_running: true }));
    const note = screen.getByTestId("system-updates-note");
    assert.equal(note.getAttribute("role"), "status");
    assert.equal(note.textContent, en.setup.systemUpdatesRunning);
    // Nothing installs, so a slow poll, only to clear the note.
    assert.deepEqual(polls.live().map((p) => p.ms), [15000]);
    await polls.fire();
    assert.equal(globalThis.__setupApiCalls.fetchSetup, 1);
    assert.equal(screen.queryByTestId("system-updates-note"), null);
    assert.deepEqual(polls.live(), [], "no more polling once Ubuntu is done");
  } finally {
    polls.restore();
  }
});

test("setup page: no note and no poll when Ubuntu is not updating", () => {
  const polls = capturePolls();
  try {
    show(setupWith({ system_updates_running: false }));
    assert.equal(screen.queryByTestId("system-updates-note"), null);
    assert.deepEqual(polls.live(), []);
  } finally {
    polls.restore();
  }
});

test("setup page: a second restart 503 tells the user to try again in a moment", async () => {
  const withAction = { ...mariadb, action: { method: "POST", endpoint: "/api/wp-cli/install" } };
  globalThis.__setupApi.runSetupAction = async () => {
    throw blip();
  };
  show(setupWith({ components: [withAction] }));
  fireEvent.click(screen.getByRole("button", { name: new RegExp(en.setup.install) }));
  await waitFor(() => assert.deepEqual(globalThis.__toasts, [{ kind: "error", message: serverRestartingMessage() }]));
});

test("dashboard shows the same note from the setup it already reads", () => {
  const page = readFileSync("app/(app)/dashboard/page.jsx", "utf8");
  assert.match(page, /<SystemUpdatesNote running=\{setupResult\.setup\?\.system_updates_running\} \/>/);
});
