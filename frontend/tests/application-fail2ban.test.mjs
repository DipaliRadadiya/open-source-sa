import { test } from "node:test";
import assert from "node:assert/strict";
import {
  bannedAddresses,
  isIpAddress,
  jailKind,
  jailTotals,
} from "../lib/schemas/application-fail2ban.js";

test("jail names are reduced to the only part that means anything", () => {
  assert.equal(jailKind("app-7-generic"), "generic");
  assert.equal(jailKind("app-142-wplogin"), "wplogin");
});

test("an unrecognised jail name survives intact rather than becoming empty", () => {
  // The screen falls back to showing this, so it must not be mangled.
  assert.equal(jailKind("sshd"), "sshd");
  assert.equal(jailKind(""), "");
  assert.equal(jailKind(null), "");
});

test("an address banned in both jails is listed once", () => {
  const jails = [
    { jail: "app-1-generic", banned: ["1.2.3.4", "5.6.7.8"] },
    { jail: "app-1-wplogin", banned: ["1.2.3.4"] },
  ];
  assert.deepEqual(bannedAddresses(jails), ["1.2.3.4", "5.6.7.8"]);
});

test("no jails means nothing banned, not a crash", () => {
  assert.deepEqual(bannedAddresses([]), []);
  assert.deepEqual(bannedAddresses(), []);
});

test("totals add up across jails", () => {
  const jails = [
    { jail: "a", stats: { currently_failed: 2, total_failed: 40, currently_banned: 1, total_banned: 9 } },
    { jail: "b", stats: { currently_failed: 3, total_failed: 2, currently_banned: 0, total_banned: 1 } },
  ];
  assert.deepEqual(jailTotals(jails), {
    currentlyFailed: 5,
    totalFailed: 42,
    currentlyBanned: 1,
    totalBanned: 10,
  });
});

test("an inactive jail contributes nothing rather than counting as zero", () => {
  // `stats: null` means "not being counted", which is not the same as "none".
  const jails = [
    { jail: "a", stats: { currently_failed: 4, total_failed: 4, currently_banned: 1, total_banned: 1 } },
    { jail: "b", stats: null },
  ];
  assert.equal(jailTotals(jails).currentlyFailed, 4);
});

test("accepts real addresses of both families", () => {
  for (const value of ["203.0.113.10", "8.8.8.8", "0.0.0.0", "255.255.255.255", "::1", "2001:db8::1"]) {
    assert.equal(isIpAddress(value), true, value);
  }
});

test("refuses the things people actually mistype here", () => {
  for (const value of ["203.0.113.0/24", "example.com", "203.0.113.10:8080", "256.1.1.1", "1.2.3", ""]) {
    assert.equal(isIpAddress(value), false, value);
  }
});

test("refuses malformed IPv6 rather than waving anything with a colon through", () => {
  for (const value of ["2001::db8::1", "2001:db8:00000::1", "1:2:3:4:5:6:7:8:9", "1:2:3:4:5:6:7"]) {
    assert.equal(isIpAddress(value), false, value);
  }
});
