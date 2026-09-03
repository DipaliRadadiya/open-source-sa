import test from "node:test";
import assert from "node:assert/strict";
import { initialDomainMode } from "../lib/applications/temporary-domain.js";

const IP = "167.233.229.184";

test("a server with an address opens on temporary", () => {
  // The form used to open on "own" unless something had prefilled a name.
  // Sites are far more often created before their domain is pointed than
  // after, so that asked most people to switch tabs before they could start.
  assert.equal(initialDomainMode({ serverIp: IP }), "temporary");
});

// The bug this guards: gating on the suffix list instead of the address left
// the form on "temporary" with no address to build a domain from — readOnly,
// empty, and the own/temporary toggle hidden, so nothing on screen could
// undo it. Flipping the default made this the common path rather than a
// corner, which makes the guard matter more, not less.
test("without a usable address it stays on own, because the toggle is hidden there", () => {
  assert.equal(initialDomainMode({ serverIp: null }), "own");
  assert.equal(initialDomainMode({ serverIp: "" }), "own");
  assert.equal(initialDomainMode({ serverIp: "not-an-ip" }), "own");
  // IPv6: `temporaryDomain` cannot build a nip.io label from one.
  assert.equal(initialDomainMode({ serverIp: "2a01:4f8::1" }), "own");
  assert.equal(initialDomainMode({}), "own");
  assert.equal(initialDomainMode(), "own");
});

test("a prefilled name no longer changes the answer", () => {
  // It used to be the whole condition. Kept as a test so the argument being
  // dropped is deliberate rather than something that quietly stopped working.
  assert.equal(
    initialDomainMode({ prefilledName: "phpmyadmin", serverIp: IP }),
    initialDomainMode({ serverIp: IP }),
  );
  assert.equal(
    initialDomainMode({ prefilledName: "", serverIp: IP }),
    initialDomainMode({ serverIp: IP }),
  );
});
