import test from "node:test";
import assert from "node:assert/strict";
import { initialDomainMode } from "../lib/applications/temporary-domain.js";

const IP = "167.233.229.184";

test("no prefilled name keeps the normal default", () => {
  assert.equal(initialDomainMode({ prefilledName: "", serverIp: IP }), "own");
  assert.equal(initialDomainMode({}), "own");
  assert.equal(initialDomainMode(), "own");
});

test("a prefilled name on a server with an address opens on temporary", () => {
  assert.equal(initialDomainMode({ prefilledName: "phpmyadmin", serverIp: IP }), "temporary");
});

// The bug this guards: gating on the suffix list instead of the address left
// the form on "temporary" with no address to build a domain from — readOnly,
// empty, and the own/temporary toggle hidden, so nothing on screen could
// undo it.
test("a prefilled name without a usable address stays on own", () => {
  assert.equal(initialDomainMode({ prefilledName: "phpmyadmin", serverIp: null }), "own");
  assert.equal(initialDomainMode({ prefilledName: "phpmyadmin", serverIp: "" }), "own");
  assert.equal(initialDomainMode({ prefilledName: "phpmyadmin", serverIp: "not-an-ip" }), "own");
  assert.equal(initialDomainMode({ prefilledName: "phpmyadmin", serverIp: "2a01:4f8::1" }), "own");
});
