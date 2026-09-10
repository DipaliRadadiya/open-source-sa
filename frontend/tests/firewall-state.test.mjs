import { test } from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { firewallState } from "../lib/firewall/state.js";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");

test("a firewall that is on but allows everything in is not 'on'", () => {
  // The bug: the card claimed "Your server is protected" and "Anything not
  // listed below is blocked" on the strength of `enabled` alone.
  assert.equal(firewallState(true, { incoming: "allow", outgoing: "allow" }), "exposed");
});

test("the ordinary states still resolve", () => {
  assert.equal(firewallState(true, { incoming: "deny", outgoing: "allow" }), "on");
  assert.equal(firewallState(false, { incoming: "deny", outgoing: "allow" }), "off");
  // Off wins regardless of the policy — nothing is being enforced either way.
  assert.equal(firewallState(false, { incoming: "allow" }), "off");
});

test("an unrecognised policy does not raise an alarm", () => {
  // `default_policy.incoming` is a free-form string. Treating anything
  // non-deny as exposed would flag a healthy server the day the backend adds
  // a third value like "reject".
  for (const incoming of [null, undefined, "", "reject", "disabled", 42, {}]) {
    assert.equal(
      firewallState(true, { incoming }),
      "on",
      `${JSON.stringify(incoming)} must not be reported as exposed`,
    );
  }
  assert.equal(firewallState(true, undefined), "on");
  assert.equal(firewallState(true, {}), "on");
});

test("casing and stray whitespace cannot hide an exposed firewall", () => {
  for (const incoming of ["ALLOW", "Allow", " allow", "allow "]) {
    assert.equal(firewallState(true, { incoming }), "exposed");
  }
});

test("every state has a title and a body in every locale", () => {
  /*
   * The card builds these keys as `status.${state}Title`, which is invisible
   * to grep and to check-i18n's resolution pass. A missing one renders the raw
   * key as the headline of a security card.
   */
  const locales = fs
    .readdirSync(path.join(root, "messages"))
    .filter((f) => f.endsWith(".json"));

  assert.ok(locales.length >= 3, "expected en, es and hi");

  for (const file of locales) {
    const status = JSON.parse(
      fs.readFileSync(path.join(root, "messages", file), "utf8"),
    ).firewall?.status;

    for (const state of ["off", "on", "exposed"]) {
      assert.equal(
        typeof status?.[`${state}Title`],
        "string",
        `${file}: firewall.status.${state}Title missing`,
      );
    }
    // offBody takes a plural count and is called separately, but it still has
    // to exist.
    for (const key of ["onBody", "exposedBody", "offBody", "secureNow", "secured"]) {
      assert.equal(typeof status?.[key], "string", `${file}: firewall.status.${key} missing`);
    }
  }
});

// --- The seeded-rule trap (reported 2026-09-10, found live on port 80) ---

test("a protected rule can always be switched back ON", async () => {
  const { protectedReasonFor } = await import("../lib/firewall/protected-rule.js");
  const labels = { noPermission: "no permission", protectedReason: "keeps you connected" };
  const rule = { id: 1, port_from: 80, protected: true, enabled: false };

  // This is the way out for a server already locked into the trap, so it must
  // hold whether the firewall is enforcing or not.
  for (const enabled of [true, false]) {
    assert.equal(
      protectedReasonFor({ rule, enabled, canManage: true, labels, turningOff: false }),
      null,
      `opening a seeded port cannot cut anyone off (firewall enabled=${enabled})`,
    );
  }
});

test("a protected rule can never be switched OFF, enforcing or not", async () => {
  const { protectedReasonFor } = await import("../lib/firewall/protected-rule.js");
  const labels = { noPermission: "no permission", protectedReason: "keeps you connected" };
  const rule = { id: 1, port_from: 22, protected: true, enabled: true };

  // `enabled: false` is the case that used to slip through: nothing was being
  // enforced, so the switch was offered — and the rule stayed off when the
  // firewall came back on.
  for (const enabled of [true, false]) {
    assert.equal(
      protectedReasonFor({ rule, enabled, canManage: true, labels, turningOff: true }),
      labels.protectedReason,
      `switching a seeded rule off is what sets the trap (firewall enabled=${enabled})`,
    );
  }
});

test("a rule the user made stays fully theirs", async () => {
  const { protectedReasonFor } = await import("../lib/firewall/protected-rule.js");
  const labels = { noPermission: "no permission", protectedReason: "keeps you connected" };
  const rule = { id: 2, port_from: 3306, protected: false, enabled: true };
  assert.equal(protectedReasonFor({ rule, enabled: true, canManage: true, labels, turningOff: true }), null);
});

test("permission outranks the guard, so the reason names the real blocker", async () => {
  const { protectedReasonFor } = await import("../lib/firewall/protected-rule.js");
  const labels = { noPermission: "no permission", protectedReason: "keeps you connected" };
  const rule = { id: 1, port_from: 22, protected: true, enabled: true };
  assert.equal(
    protectedReasonFor({ rule, enabled: true, canManage: false, labels, turningOff: true }),
    labels.noPermission,
  );
});

test("both rule surfaces ask which direction the switch is going", async () => {
  const fs = await import("node:fs");
  for (const path of ["components/firewall/rules-card.jsx", "components/firewall/rules-cards.jsx"]) {
    const source = fs.readFileSync(path, "utf8");
    assert.match(source, /turningOff: shownEnabled\(rule\)/, `${path} must pass the direction`);
  }
});

test("the reason no longer tells people to do the thing that traps them", async () => {
  const fs = await import("node:fs");
  for (const locale of ["en", "es", "hi"]) {
    const text = JSON.parse(fs.readFileSync(`messages/${locale}.json`, "utf8")).firewall.rules
      .protectedReason;
    assert.doesNotMatch(
      text,
      /firewall off|desactiva.*firewall|फ़ायरवॉल.*बंद/i,
      `${locale} still advises turning the firewall off, which is step 1 of the lockout`,
    );
  }
});
