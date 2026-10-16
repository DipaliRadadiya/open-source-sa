import { test } from "node:test";
import assert from "node:assert/strict";
import { mayStillBeRunning } from "../lib/api/error-message.js";

const write = (extra) => ({ isAxiosError: true, config: { method: "post" }, ...extra });

test("a 504 on a long push or update means the server is still working, not that it failed", () => {
  assert.equal(mayStillBeRunning(write({ response: { status: 504 } })), true);
});

test("a write whose answer never arrived may still be running", () => {
  assert.equal(mayStillBeRunning(write({ code: "ERR_NETWORK" })), true);
});

test("a real refusal or crash is still a failure", () => {
  for (const status of [422, 409, 500, 502]) {
    assert.equal(mayStillBeRunning(write({ response: { status } })), false, String(status));
  }
});

test("a cancelled request is not reported as still running", () => {
  assert.equal(mayStillBeRunning(write({ code: "ERR_CANCELED" })), false);
});
