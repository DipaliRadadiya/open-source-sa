// The four editors render a `web_server_reload_failed` answer as saved but not live:
// new saved baseline, no success toast, no green "live" claim, and a warning that
// survives remounts. Real components under jsdom; only the API and refresh are stubbed.
import assert from "node:assert/strict";
import { afterEach, before, beforeEach, test } from "node:test";
import { readFileSync } from "node:fs";
import { installDom, loadComponent } from "./support/dom.mjs";
installDom();
const { createElement: h } = await import("react");
const { renderToString } = await import("react-dom/server");
const { act, render, screen, cleanup, fireEvent, waitFor } = await import("@testing-library/react");
const lib = await import("../lib/applications/reload-pending.js");

const messages = JSON.parse(readFileSync("messages/en.json", "utf8"));
const m = messages.applications;
const p = m.reloadPending;
const REF = "3f2b8c1e-9a4d-4e57-8b0a-1c2d3e4f5a6b";
const PASSWORD = "Tr0ub4dor-and-3-horses";

const STUBS = {
  "@/lib/api/applications": "tests/support/reload-pending-api-stub.js",
  "@/lib/api/files": "tests/support/reload-pending-api-stub.js",
  "@/hooks/use-refresh": "tests/support/use-refresh-stub.js",
  "@/components/ui/app-link": "tests/support/app-link-stub.jsx",
  "next/navigation": "tests/support/next-navigation-stub.js",
  sonner: "tests/support/sonner-stub.js",
};

let H;
before(async () => {
  H = await loadComponent("tests/support/reload-pending-harness.jsx", STUBS);
});
beforeEach(() => {
  document.cookie = `${lib.RELOAD_PENDING_COOKIE}=; path=/; max-age=0`;
  globalThis.__toasts = [];
  globalThis.__apiCalls = [];
  globalThis.__apiRespond = undefined;
});
afterEach(() => cleanup());

const reloadFailed = () =>
  Promise.reject({
    isAxiosError: true,
    response: { status: 500, data: { code: "web_server_reload_failed", message: "Saved but not reloaded.", reference: REF } },
  });
const otherFailure = () =>
  Promise.reject({
    isAxiosError: true,
    response: { status: 500, data: { code: "server_operation_failed", message: "It could not be changed.", reference: REF } },
  });

// What app/(app)/layout.jsx hands the provider: the raw cookie, parsed on the server.
function serverInitial(userId = "7") {
  const pair = document.cookie.split("; ").find((part) => part.startsWith(`${lib.RELOAD_PENDING_COOKIE}=`));
  const raw = pair ? decodeURIComponent(pair.slice(lib.RELOAD_PENDING_COOKIE.length + 1)) : undefined;
  return lib.recordsForUser(lib.parseReloadPending(raw), userId);
}

const mount = (child, options = {}) => render(h(H.Harness, { messages, ...options }, child));
const toasts = (kind) => globalThis.__toasts.filter((toast) => toast.kind === kind).map((toast) => toast.message);
const button = (name) => screen.getByRole("button", { name });
const queryButton = (name) => screen.queryByRole("button", { name });

function assertCookieSafe(...secrets) {
  const records = lib.readReloadPending();
  assert.equal(records.length, 1);
  assert.deepEqual(Object.keys(records[0]).sort(), ["a", "r", "t", "u"]);
  assert.equal(records[0].u, "7");
  assert.equal(records[0].a, "12");
  assert.equal(records[0].r, REF);
  for (const secret of secrets) assert.equal(decodeURIComponent(document.cookie).includes(secret), false, secret);
}

const catalog = [
  { value: "query_string", title: "Query string" },
  { value: "user_agent", title: "User agent" },
];
const modes = [
  { value: "detect", title: "Watch" },
  { value: "enforce", title: "Block" },
];
const wafApp = {
  waf_enabled: true,
  waf_mode: "enforce",
  waf_categories: ["query_string", "user_agent"],
  waf_exceptions: [],
  waf_custom_rules: [],
};
const firewall = (application = wafApp) =>
  h(H.FirewallSection, { appId: "12", application, categories: catalog, modes, canManage: true });

test("firewall: a reload failure becomes the saved state without claiming Blocking", async () => {
  mount(firewall());
  assert.ok(screen.getByText(m.firewall.statusBlocking));

  fireEvent.click(screen.getByRole("switch", { name: m.firewall.enable }));
  assert.ok(queryButton(messages.common.saveFooter.discard), "the edit is unsaved");

  globalThis.__apiRespond = reloadFailed;
  await act(async () => fireEvent.click(button(messages.common.saveFooter.save)));
  await waitFor(() => assert.deepEqual(toasts("warning"), [p.toast]));

  assert.deepEqual(toasts("success"), []);
  assert.deepEqual(toasts("error"), []);
  assert.equal(globalThis.__apiCalls[0].payload.enabled, false);
  // The props never refreshed, yet nothing is "unsaved": the baseline moved to what was sent.
  assert.equal(queryButton(messages.common.saveFooter.discard), null);
  assert.ok(screen.getByText(p.badge));
  assert.ok(screen.getByText(p.hint));
  assert.equal(screen.queryByText(m.firewall.statusBlocking), null);
  assert.equal(screen.queryByText(m.firewall.statusOff), null);
  assertCookieSafe("query_string", "enforce");
});

test("firewall: the warning survives a remount and a server render", async () => {
  lib.writeReloadPending(lib.withReloadPending([], { userId: 7, appId: 12, reference: REF, token: "abc" }));
  const saved = { ...wafApp };
  const html = renderToString(h(H.Harness, { messages, initial: serverInitial() }, firewall(saved)));
  assert.ok(html.includes(p.badge));
  assert.equal(html.includes(m.firewall.statusBlocking), false);

  mount(firewall(saved), { initial: serverInitial() });
  assert.ok(screen.getByText(p.badge));
  cleanup();
  // Another signed-in user on this browser sees their own state only.
  mount(firewall(saved), { userId: "8", initial: serverInitial("8") });
  assert.ok(screen.getByText(m.firewall.statusBlocking));
});

test("firewall: categories sent as all six are the baseline, not an unsaved empty list", async () => {
  mount(firewall({ ...wafApp, waf_enabled: false, waf_categories: [] }));
  fireEvent.click(screen.getByRole("switch", { name: m.firewall.enable }));
  globalThis.__apiRespond = reloadFailed;
  await act(async () => fireEvent.click(button(messages.common.saveFooter.save)));
  await waitFor(() => assert.deepEqual(toasts("warning"), [p.toast]));
  assert.deepEqual(globalThis.__apiCalls[0].payload.categories, ["query_string", "user_agent"]);
  assert.equal(queryButton(messages.common.saveFooter.discard), null);
});

test("firewall: any other failure is still an ordinary failed save", async () => {
  mount(firewall());
  fireEvent.click(screen.getByRole("switch", { name: m.firewall.enable }));
  globalThis.__apiRespond = otherFailure;
  await act(async () => fireEvent.click(button(messages.common.saveFooter.save)));
  await waitFor(() => assert.equal(toasts("error").length, 1));
  assert.match(toasts("error")[0], /It could not be changed\./);
  assert.deepEqual(toasts("warning"), []);
  assert.ok(queryButton(messages.common.saveFooter.discard), "still unsaved");
  assert.ok(screen.getByText(m.firewall.statusBlocking));
  assert.deepEqual(lib.readReloadPending(), []);
});

const policies = {
  allow_all: { title: "Allow all", description: "Nothing blocked.", blocked_bots: [] },
  block_training: { title: "Block training", description: "Training crawlers.", blocked_bots: ["GPTBot", "CCBot"] },
};
const bots = () =>
  h(H.BotBlockerSection, { appId: "12", policies, currentPolicy: "allow_all", currentBlocked: [], currentAllowed: [], canManage: true });

test("bot blocker: the saved policy is marked waiting, not Active now", async () => {
  mount(bots());
  assert.ok(screen.getByText(m.botBlocker.activeNow));
  await act(async () => fireEvent.click(document.getElementById("ai-bot-block_training")));
  globalThis.__apiRespond = reloadFailed;
  await act(async () => fireEvent.click(button(messages.common.saveFooter.save)));
  await waitFor(() => assert.deepEqual(toasts("warning"), [p.toast]));

  assert.deepEqual(toasts("success"), []);
  assert.deepEqual(toasts("error"), []);
  assert.equal(globalThis.__apiCalls[0].payload.policy, "block_training");
  assert.equal(screen.queryByText(m.botBlocker.activeNow), null);
  assert.ok(screen.getByText(p.badge));
  assert.equal(queryButton(messages.common.saveFooter.discard), null);
  assertCookieSafe("block_training", "GPTBot");
});

const securityApp = { basic_auth_enabled: false, basic_auth_username: "", basic_auth_supported: true, url: "https://shop.test" };
const security = (application = securityApp) =>
  h(H.SecuritySection, { appId: "12", application, domain: "shop.test", canManage: true });

test("password protection: credentials stay in memory only and nothing says Protected", async () => {
  mount(security());
  fireEvent.click(screen.getByRole("switch", { name: m.security.enable }));
  fireEvent.change(screen.getByPlaceholderText(m.security.usernamePlaceholder), { target: { value: "guard" } });
  fireEvent.change(screen.getByPlaceholderText(m.security.passwordPlaceholder), { target: { value: PASSWORD } });

  globalThis.__apiRespond = reloadFailed;
  await act(async () => fireEvent.click(button(messages.common.saveFooter.save)));
  await waitFor(() => assert.deepEqual(toasts("warning"), [p.toast]));

  assert.deepEqual(toasts("success"), []);
  assert.deepEqual(toasts("error"), []);
  assert.equal(globalThis.__apiCalls[0].payload.password, PASSWORD);
  assert.equal(screen.getByPlaceholderText(m.security.passwordPlaceholder).value, "");
  // Shown once for copying, as after a full success.
  assert.ok(screen.getByText(m.security.savedCredentials));
  assert.equal(screen.queryByText(m.security.statusProtected), null);
  assert.ok(screen.getByText(p.badge));
  assert.equal(queryButton(messages.common.saveFooter.discard), null);
  assertCookieSafe(PASSWORD, "guard");
});

test("password protection: switching off over a failed reload is not shown as Not protected", async () => {
  mount(security({ ...securityApp, basic_auth_enabled: true, basic_auth_username: "guard" }));
  assert.ok(screen.getByText(m.security.statusProtected));
  fireEvent.click(screen.getByRole("switch", { name: m.security.enable }));
  globalThis.__apiRespond = reloadFailed;
  await act(async () => fireEvent.click(button(messages.common.saveFooter.save)));
  await waitFor(() => assert.deepEqual(toasts("warning"), [p.toast]));
  assert.deepEqual(globalThis.__apiCalls[0].payload, { enabled: false });
  assert.equal(screen.queryByText(m.security.statusNotProtected), null);
  assert.equal(screen.queryByText(m.security.statusProtected), null);
  assert.ok(screen.getByText(p.badge));
});

test("password protection: a validation error keeps its field message", async () => {
  mount(security());
  fireEvent.click(screen.getByRole("switch", { name: m.security.enable }));
  fireEvent.change(screen.getByPlaceholderText(m.security.usernamePlaceholder), { target: { value: "guard" } });
  fireEvent.change(screen.getByPlaceholderText(m.security.passwordPlaceholder), { target: { value: PASSWORD } });
  globalThis.__apiRespond = (_name, payload) =>
    Promise.reject({
      config: { data: JSON.stringify(payload) },
      response: { status: 422, data: { message: "Invalid.", errors: { username: ["That name is taken."] } } },
    });
  await act(async () => fireEvent.click(button(messages.common.saveFooter.save)));
  await waitFor(() => assert.ok(screen.getByText("That name is taken.")));
  assert.deepEqual(toasts("warning"), []);
  assert.deepEqual(lib.readReloadPending(), []);
  assert.ok(screen.getByText(m.security.statusNotProtected));
});

const webApp = { id: 12, web_root: "", path: "/home/owner/apps/shop" };

test("web root: the dialog stays open with the warning, reference and Services link", async () => {
  const closes = [];
  mount(h(H.WebRootDialog, { application: webApp, open: true, onOpenChange: (next) => closes.push(next) }));
  fireEvent.change(screen.getByPlaceholderText("/public"), { target: { value: "public" } });
  globalThis.__apiRespond = reloadFailed;
  await act(async () => fireEvent.click(button(m.webRoot.submit)));
  await waitFor(() => assert.ok(screen.getByText(p.title)));

  assert.deepEqual(closes, []);
  assert.deepEqual(toasts("success"), []);
  assert.deepEqual(toasts("error"), []);
  assert.equal(screen.getByPlaceholderText("/public").value, "public");
  assert.ok(screen.getByText(REF));
  assert.ok(button(p.copyReference));
  assert.equal(screen.getByRole("link", { name: p.openServices }).getAttribute("href"), "/services");
  // The footer's Cancel became Close: there is nothing left to cancel.
  assert.equal(queryButton(m.webRoot.cancel), null);
  assert.ok(screen.getAllByRole("button", { name: m.webRoot.close }).length >= 2);
  assertCookieSafe("public");

  // Closed and reopened: the warning is still there.
  cleanup();
  mount(h(H.WebRootDialog, { application: { ...webApp, web_root: "public" }, open: true }), { initial: serverInitial() });
  assert.ok(screen.getByText(p.title));
});

test("notice: without service rights it asks for an administrator instead of linking", () => {
  lib.writeReloadPending(lib.withReloadPending([], { userId: 7, appId: 12, reference: "", token: "abc" }));
  mount(h(H.ReloadPendingNotice, { appId: "12" }), { canManageServices: false, initial: serverInitial() });
  assert.ok(screen.getByRole("status").textContent.includes(p.askAdmin));
  assert.equal(screen.queryByRole("link"), null);
  // No reference, no empty copy row.
  assert.equal(queryButton(p.copyReference), null);
});

test("notice: only that application's warning shows", () => {
  lib.writeReloadPending(lib.withReloadPending([], { userId: 7, appId: 13, reference: REF, token: "abc" }));
  mount(h(H.ReloadPendingNotice, { appId: "12" }), { initial: serverInitial() });
  assert.equal(screen.queryByText(p.title), null);
});

test("notice: a confirmed reload clears it; a failed one does not", async () => {
  lib.writeReloadPending(lib.withReloadPending([], { userId: 7, appId: 12, reference: REF, token: "abc" }));
  mount(h(H.ReloadPendingNotice, { appId: "12" }), { initial: serverInitial() });
  assert.ok(screen.getByText(p.title));

  await act(async () => {
    await lib.settleReloadPending("nginx", "reload", () => Promise.reject({ response: { status: 500, data: {} } })).catch(() => {});
  });
  assert.ok(screen.getByText(p.title));

  await act(async () => {
    await lib.settleReloadPending("nginx", "reload", async () => ({ status: 200, data: {} }));
  });
  assert.equal(screen.queryByText(p.title), null);
});

test("notice: a change made in another tab shows on focus", async () => {
  mount(h(H.ReloadPendingNotice, { appId: "12" }));
  assert.equal(screen.queryByText(p.title), null);
  document.cookie = `${lib.RELOAD_PENDING_COOKIE}=${encodeURIComponent(
    lib.serializeReloadPending([{ u: "7", a: "12", r: REF, t: "abc" }]),
  )}; path=/`;
  await act(async () => window.dispatchEvent(new Event("focus")));
  assert.ok(screen.getByText(p.title));
});

// A 200 answer while an earlier save still waits does not prove the web server reloaded.
const pendingFor12 = () =>
  lib.writeReloadPending(lib.withReloadPending([], { userId: 7, appId: 12, reference: REF, token: "abc" }));

test("web root: a normal save while a warning waits says a reload is still needed", async () => {
  pendingFor12();
  const closes = [];
  mount(h(H.WebRootDialog, { application: webApp, open: true, onOpenChange: (next) => closes.push(next) }), {
    initial: serverInitial(),
  });
  fireEvent.change(screen.getByPlaceholderText("/public"), { target: { value: "public" } });
  await act(async () => fireEvent.click(button(m.webRoot.submit)));
  await waitFor(() => assert.deepEqual(toasts("warning"), [p.stillPendingToast]));
  assert.deepEqual(toasts("success"), []);
  assert.deepEqual(closes, [false]);
  assert.equal(lib.readReloadPending().length, 1, "a 200 save never clears the warning");
});

test("web root: a normal save with nothing waiting is still a plain success", async () => {
  mount(h(H.WebRootDialog, { application: webApp, open: true, onOpenChange: () => {} }));
  fireEvent.change(screen.getByPlaceholderText("/public"), { target: { value: "public" } });
  await act(async () => fireEvent.click(button(m.webRoot.submit)));
  await waitFor(() => assert.deepEqual(toasts("success"), [m.webRoot.saved]));
  assert.deepEqual(toasts("warning"), []);
});

test("bot blocker: a normal save while a warning waits is not Active now", async () => {
  pendingFor12();
  mount(bots(), { initial: serverInitial() });
  assert.ok(screen.getByText(p.badge));
  await act(async () => fireEvent.click(document.getElementById("ai-bot-block_training")));
  await act(async () => fireEvent.click(button(messages.common.saveFooter.save)));
  await waitFor(() => assert.deepEqual(toasts("warning"), [p.stillPendingToast]));
  assert.deepEqual(toasts("success"), []);
  assert.equal(screen.queryByText(m.botBlocker.activeNow), null);
  assert.ok(screen.getByText(p.badge));
});

test("firewall: a normal save while a warning waits is not Blocking", async () => {
  pendingFor12();
  mount(firewall({ ...wafApp, waf_enabled: false }), { initial: serverInitial() });
  fireEvent.click(screen.getByRole("switch", { name: m.firewall.enable }));
  await act(async () => fireEvent.click(button(messages.common.saveFooter.save)));
  await waitFor(() => assert.deepEqual(toasts("warning"), [p.stillPendingToast]));
  assert.deepEqual(toasts("success"), []);
  assert.equal(screen.queryByText(m.firewall.statusBlocking), null);
  assert.ok(screen.getByText(p.badge));
});

test("notice: props from a refresh that started before the last write do not win", () => {
  const notice = h(H.ReloadPendingNotice, { appId: "12" });
  const { rerender } = mount(notice);
  act(() => pendingFor12());
  assert.ok(screen.getByText(p.title));
  // The refresh's server render read the cookie before the write.
  rerender(h(H.Harness, { messages, initial: [{ u: "7", a: "99", r: "", t: "old" }] }, notice));
  assert.ok(screen.getByText(p.title));

  // And the other way: cleared in the browser, stale props still holding it.
  const stale = serverInitial();
  act(() => lib.writeReloadPending([]));
  rerender(h(H.Harness, { messages, initial: [...stale, { u: "7", a: "98", r: "", t: "old2" }] }, notice));
  assert.equal(screen.queryByText(p.title), null);
});


test("watch-mode claims are hidden while that application's reload is pending", async () => {
  pendingFor12();
  mount(h(H.HideWhileReloadPending, { appId: "12" }, h("p", null, "Current watch-mode summary")), { initial: serverInitial() });
  assert.equal(screen.queryByText("Current watch-mode summary"), null);
  await act(async () => lib.writeReloadPending([]));
  assert.ok(screen.getByText("Current watch-mode summary"));
});

test("another application's pending reload does not hide the current summary", () => {
  pendingFor12();
  mount(h(H.HideWhileReloadPending, { appId: "99" }, h("p", null, "Unaffected summary")), { initial: serverInitial() });
  assert.ok(screen.getByText("Unaffected summary"));
});
