/*
 * DS-15 item 1: during a deploy, nginx reloaded and one server-side render could not
 * connect to the API for ~10 s. Every SSR read gave up on that first attempt and the
 * whole page became "Your server is not answering". Loads the real fetchers with only
 * the server-only imports stubbed, and drives them through a stubbed global fetch that
 * memoises like Next's: a GET without a signal gets the first answer back, rejection
 * included, without a new request. That is why the old retries never reached the API.
 */
import { before, beforeEach, test } from "node:test";
import assert from "node:assert/strict";
import { loadComponent } from "./support/dom.mjs";

const STUBS = {
  "next/headers": "tests/support/ssr-stubs.js",
  "next/navigation": "tests/support/ssr-stubs.js",
  "@/lib/i18n/server-locale": "tests/support/ssr-stubs.js",
  "@/lib/applications/get-applications": "tests/support/ssr-stubs.js",
  "@/lib/auth/signed-out-path": "tests/support/ssr-stubs.js",
};

let read, getPermissions, TRANSPORT_RETRY_DELAY_MS;
const schema = { safeParse: (data) => ({ success: true, data }) };

before(async () => {
  process.env.NEXT_PUBLIC_API_URL = "https://api.example.test";
  ({ read } = await loadComponent("lib/api/read.js", STUBS));
  ({ getPermissions } = await loadComponent("lib/permissions/get-permissions.js", STUBS));
  ({ TRANSPORT_RETRY_DELAY_MS } = await import("../lib/api/retry.js"));
});

let calls;
// `answers`: an Error is a connection that never completed, a number is a status.
function stubFetch(...answers) {
  calls = [];
  const memo = new Map();
  const network = async (url) => {
    calls.push({ url: String(url), at: Date.now() });
    const answer = answers[Math.min(calls.length - 1, answers.length - 1)];
    if (answer instanceof Error) throw answer;
    return new Response(JSON.stringify({ permissions: [{ key: "application" }], ok: true }), {
      status: answer,
      headers: { "content-type": "application/json" },
    });
  };
  // Next's dedupe-fetch: only a request carrying a signal skips the per-render memo.
  globalThis.fetch = (url, init) => {
    if (init?.signal) return network(url);
    if (!memo.has(String(url))) memo.set(String(url), network(url));
    return memo.get(String(url)).then((res) => res.clone());
  };
}
const connectTimeout = () =>
  Object.assign(new TypeError("fetch failed"), { cause: { code: "UND_ERR_CONNECT_TIMEOUT" } });

beforeEach(() => {
  console.error = () => {};
});

test("a page read that cannot connect once is retried, and the page gets its data", async () => {
  stubFetch(connectTimeout(), 200);
  const result = await read("/site-types", schema);
  assert.equal(result.failed, false);
  assert.equal(calls.length, 2);
  assert.ok(calls[1].at - calls[0].at >= TRANSPORT_RETRY_DELAY_MS - 20, "retried after a backoff, not instantly");
});

test("a page read that cannot connect twice still reports a network failure, after one retry only", async () => {
  stubFetch(connectTimeout(), connectTimeout());
  const result = await read("/site-types", schema);
  assert.equal(result.failure, "network");
  assert.equal(calls.length, 2);
});

test("two identical reads in one render still share a request", async () => {
  stubFetch(200);
  await Promise.all([read("/site-types", schema), read("/site-types", schema)]);
  assert.equal(calls.length, 1);
});

test("an API answer is not a transport failure: a 500 page read is not retried", async () => {
  stubFetch(500);
  const result = await read("/site-types", schema);
  assert.equal(result.status, 500);
  assert.equal(calls.length, 1);
});

test("the layout's permission read survives a blip the length of an nginx reload", async () => {
  // The old 250 ms retry could land inside the same reload window.
  stubFetch(connectTimeout(), 200);
  const permissions = await getPermissions();
  assert.deepEqual(permissions, [{ key: "application" }]);
  assert.equal(calls.length, 2);
  assert.ok(calls[1].at - calls[0].at >= 900, `waited ${calls[1].at - calls[0].at} ms`);
});

test("a 5xx on the permission read is asked again, not answered from the memo", async () => {
  stubFetch(502, 200);
  const permissions = await getPermissions();
  assert.deepEqual(permissions, [{ key: "application" }]);
  assert.equal(calls.length, 2);
});
