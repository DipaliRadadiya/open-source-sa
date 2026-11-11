// Saved-but-not-live (`web_server_reload_failed`): the guard, the browser record and
// what may clear it. The record holds ids and a reference only, never settings.
import assert from "node:assert/strict";
import { beforeEach, test } from "node:test";
import { installDom, loadComponent } from "./support/dom.mjs";
installDom();

const {
  RELOAD_PENDING_COOKIE,
  clearsReloadPending,
  isReloadFailed,
  parseReloadPending,
  readReloadPending,
  recordsForUser,
  reloadReference,
  serializeReloadPending,
  settleReloadPending,
  withReloadPending,
  withoutTokens,
  writeReloadPending,
} = await import("../lib/applications/reload-pending.js");

const REF = "3f2b8c1e-9a4d-4e57-8b0a-1c2d3e4f5a6b";
const reloadFailed = (data = {}) => ({
  isAxiosError: true,
  response: { status: 500, data: { code: "web_server_reload_failed", message: "Saved, not reloaded.", reference: REF, ...data } },
});

function clearCookie() {
  document.cookie = `${RELOAD_PENDING_COOKIE}=; path=/; max-age=0`;
}
beforeEach(() => {
  clearCookie();
  globalThis.__apiCalls = [];
  globalThis.__apiRespond = undefined;
});

test("only an HTTP 500 with the exact code is a saved-but-not-live answer", () => {
  assert.equal(isReloadFailed(reloadFailed()), true);
  // Same code on another status, another code on a 500, or no answer at all.
  assert.equal(isReloadFailed({ response: { status: 503, data: { code: "web_server_reload_failed" } } }), false);
  assert.equal(isReloadFailed({ response: { status: 500, data: { code: "server_operation_failed" } } }), false);
  assert.equal(isReloadFailed({ response: { status: 500, data: { message: "Server Error" } } }), false);
  assert.equal(isReloadFailed({ response: { status: 504, data: {} } }), false);
  assert.equal(isReloadFailed({ response: { status: 422, data: { code: "web_server_reload_failed", errors: { web_root: ["x"] } } } }), false);
  assert.equal(isReloadFailed({ isAxiosError: true, code: "ECONNABORTED", config: { method: "put" } }), false);
  assert.equal(isReloadFailed(null), false);
});

test("the reference is kept only when it looks like one", () => {
  assert.equal(reloadReference(reloadFailed()), REF);
  assert.equal(reloadReference(reloadFailed({ reference: "<script>" })), "");
  assert.equal(reloadReference(reloadFailed({ reference: "x".repeat(65) })), "");
  assert.equal(reloadReference(reloadFailed({ reference: 42 })), "");
});

test("malformed, oversized or tampered cookies yield nothing unsafe", () => {
  assert.deepEqual(parseReloadPending(undefined), []);
  assert.deepEqual(parseReloadPending("not json"), []);
  assert.deepEqual(parseReloadPending('{"u":"1"}'), []);
  assert.deepEqual(parseReloadPending(`[${'{"u":"1","a":"2","r":"","t":"a"},'.repeat(200)}{}]`), []);
  const kept = parseReloadPending(
    JSON.stringify([
      { u: "1", a: "2", r: REF, t: "abc", password: "hunter2", name: "Shop" },
      { u: "1", a: "../etc", r: "", t: "abd" },
      { u: "1", a: "3", r: "<b>", t: "abe" },
      { u: 1, a: "3", r: "", t: "abf" },
      { u: "1", a: "4", r: "", t: "ABC!" },
    ]),
  );
  // Extra fields are dropped, bad entries skipped.
  assert.deepEqual(kept, [{ u: "1", a: "2", r: REF, t: "abc" }]);
  const many = Array.from({ length: 40 }, (_, i) => ({ u: "1", a: String(i), r: "", t: `t${i}` }));
  assert.equal(parseReloadPending(JSON.stringify(many)).length, 20);
});

test("records are per user and per application; the newest replaces the older", () => {
  let records = withReloadPending([], { userId: 7, appId: 12, reference: REF, token: "one" });
  records = withReloadPending(records, { userId: 8, appId: 12, reference: "", token: "two" });
  records = withReloadPending(records, { userId: 7, appId: 12, reference: "", token: "three" });
  assert.deepEqual(recordsForUser(records, 7), [{ u: "7", a: "12", r: "", t: "three" }]);
  assert.deepEqual(recordsForUser(records, 8), [{ u: "8", a: "12", r: "", t: "two" }]);
  assert.deepEqual(recordsForUser(records, null), []);
  // Invalid ids are refused rather than stored.
  assert.equal(withReloadPending(records, { userId: "7 ", appId: 1, token: "x" }), records);
});

test("the serialised cookie carries ids, reference and token only", () => {
  const raw = serializeReloadPending([{ u: "7", a: "12", r: REF, t: "abc", password: "hunter2", username: "admin" }]);
  assert.equal(raw, JSON.stringify([{ u: "7", a: "12", r: REF, t: "abc" }]));
  writeReloadPending(parseReloadPending(raw));
  assert.doesNotMatch(document.cookie, /hunter2|admin/);
  assert.deepEqual(readReloadPending(), [{ u: "7", a: "12", r: REF, t: "abc" }]);
  writeReloadPending([]);
  assert.deepEqual(readReloadPending(), []);
  assert.deepEqual(withoutTokens([{ t: "a" }, { t: "b" }], new Set(["a"])), [{ t: "b" }]);
});

test("only a web server restart or reload can clear it", () => {
  for (const key of ["nginx", "apache", "openlitespeed"]) {
    for (const action of ["restart", "reload"]) assert.equal(clearsReloadPending(key, action), true, `${key} ${action}`);
    // start does nothing on a unit that is already running.
    for (const action of ["start", "stop", "enable", "disable"]) assert.equal(clearsReloadPending(key, action), false, `${key} ${action}`);
  }
  for (const key of ["php8.4-fpm", "redis", "mysql", "apache2", "lshttpd"]) assert.equal(clearsReloadPending(key, "restart"), false, key);
});

test("a successful start keeps the warning", async () => {
  writeReloadPending([{ u: "7", a: "12", r: "", t: "old" }]);
  await settleReloadPending("nginx", "start", async () => ({ status: 200, data: {} }));
  assert.deepEqual(readReloadPending().map((record) => record.t), ["old"]);
});

function seed(...records) {
  writeReloadPending(records.map(([u, a, t]) => ({ u, a, r: "", t })));
}

test("a confirmed reload clears what was pending before it, nothing newer", async () => {
  seed(["7", "12", "old"], ["8", "13", "other"]);
  await settleReloadPending("nginx", "reload", async () => {
    // Saved while the reload ran: that save's own reload failed after it.
    writeReloadPending(withReloadPending(readReloadPending(), { userId: 7, appId: 14, token: "newer" }));
    return { status: 200, data: {} };
  });
  assert.deepEqual(readReloadPending().map((record) => record.t), ["newer"]);
});

test("a failed, unanswered, non-2xx or irrelevant action clears nothing", async () => {
  seed(["7", "12", "old"]);
  await assert.rejects(settleReloadPending("nginx", "restart", async () => {
    throw { response: { status: 500, data: { code: "server_operation_failed" } } };
  }));
  await assert.rejects(settleReloadPending("nginx", "reload", async () => {
    throw { isAxiosError: true, code: "ERR_NETWORK", config: { method: "put" } };
  }));
  await settleReloadPending("nginx", "reload", async () => ({ status: 304 }));
  await settleReloadPending("nginx", "reload", async () => undefined);
  await settleReloadPending("nginx", "stop", async () => ({ status: 200 }));
  await settleReloadPending("php8.4-fpm", "restart", async () => ({ status: 200 }));
  await settleReloadPending("redis", "restart", async () => ({ status: 200 }));
  assert.deepEqual(readReloadPending().map((record) => record.t), ["old"]);
});

test("runServiceAction clears pending records only on its own success", async () => {
  const { runServiceAction } = await loadComponent("lib/api/services.js", {
    "@/lib/api/client": "tests/support/reload-pending-api-stub.js",
  });
  seed(["7", "12", "old"]);

  globalThis.__apiRespond = async () => {
    throw { response: { status: 422, data: { message: "Config test failed" } } };
  };
  await assert.rejects(runServiceAction("nginx", "reload"));
  assert.equal(readReloadPending().length, 1);

  globalThis.__apiRespond = async () => ({ status: 200, data: { service: {} } });
  await runServiceAction("nginx", "enable");
  assert.equal(readReloadPending().length, 1);

  await runServiceAction("apache", "restart");
  assert.deepEqual(readReloadPending(), []);
  assert.deepEqual(globalThis.__apiCalls.at(-1), { name: "put", payload: { url: "/services/apache", action: "restart" } });
});

test("the Services link needs restart rights, which also open the Services page", async () => {
  const { readFileSync } = await import("node:fs");
  const { can } = await import("../lib/permissions/can.js");
  const page = readFileSync("app/(app)/services/page.jsx", "utf8");
  const layout = readFileSync("app/(app)/layout.jsx", "utf8");
  assert.match(page, /if \(!can\(permissions, "service", "view"\)\) return <PermissionDenied/);
  assert.match(page, /const canManage = can\(permissions, "service", "manage"\);/);
  assert.match(layout, /<ReloadPendingProvider [^>]*canManageServices=\{can\(permissions, "service", "manage"\)\}/);
  const manager = [{ level: "server", name: "service", permissions: { view: false, manage: true } }];
  assert.equal(can(manager, "service", "view"), true, "manage implies view: no access-denied page");
  const viewer = [{ level: "server", name: "service", permissions: { view: true, manage: false } }];
  assert.equal(can(viewer, "service", "manage"), false, "view only gets the administrator text");
});

test("a full cookie stays under the browser's size limit and keeps the newest", () => {
  const big = (i) => ({ u: "u".repeat(60) + String(i).padStart(4, "0"), a: "a".repeat(64), r: "r".repeat(64), t: "t".repeat(32) });
  const records = Array.from({ length: 20 }, (_, i) => big(i));
  const value = serializeReloadPending(records);
  assert.ok(encodeURIComponent(value).length <= 3500, String(encodeURIComponent(value).length));
  const back = parseReloadPending(value);
  assert.ok(back.length > 0 && back.length < 20);
  assert.equal(back.at(-1).u, records.at(-1).u);
  // Ordinary records all fit.
  const small = Array.from({ length: 20 }, (_, i) => ({ u: "7", a: String(i + 1), r: "3f2b8c1e-9a4d-4e57-8b0a-1c2d3e4f5a6b", t: `t${i}` }));
  assert.equal(parseReloadPending(serializeReloadPending(small)).length, 20);
});

test("the cookie is marked secure on HTTPS", async () => {
  const { readFileSync } = await import("node:fs");
  const source = readFileSync("lib/applications/reload-pending.js", "utf8");
  assert.match(source, /window\.location\.protocol === "https:" \? "; secure" : ""/);
  assert.equal((source.match(/samesite=lax\$\{secure\}/g) ?? []).length, 2);
});
