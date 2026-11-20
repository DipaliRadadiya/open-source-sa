import fs from "node:fs";
import { test } from "node:test";
import assert from "node:assert/strict";
import { addressIn, failedSignIns, isMachineActor, neverSignedIn, parseActivityTime, SIGN_IN_ACTIONS } from "../lib/admin/sign-in-facts.js";

const read = (p) => fs.readFileSync(new URL(`../${p}`, import.meta.url), "utf8");
const NOW = Date.UTC(2026, 10, 20, 12, 0, 0);
const at = (hoursAgo) => {
  const d = new Date(NOW - hoursAgo * 3600 * 1000);
  const p = (n) => String(n).padStart(2, "0");
  return `${p(d.getUTCDate())}-${p(d.getUTCMonth() + 1)}-${d.getUTCFullYear()} ${p(d.getUTCHours())}:${p(d.getUTCMinutes())}:${p(d.getUTCSeconds())}`;
};
const fail = (hoursAgo, ip, username) => ({
  id: Math.random(),
  action: username ? "login_failed" : "login_failed_unknown",
  description: `Failed sign-in from ${ip}`,
  user: username ? { id: 1, username } : null,
  created_at: at(hoursAgo),
  created_at_human: `${hoursAgo} hours ago`,
});

test("the API's d-m-Y time is read as UTC", () => {
  assert.equal(parseActivityTime("20-11-2026 12:00:00"), NOW);
  assert.equal(parseActivityTime("2026-11-20"), null);
});

test("the address is found in any language's sentence", () => {
  assert.equal(addressIn("Échec de connexion depuis 203.0.113.9"), "203.0.113.9");
  assert.equal(addressIn("Failed sign-in from 2001:db8::1 with a username"), "2001:db8::1");
  assert.equal(addressIn("Logged in"), null);
});

test("failed sign-ins: last 7 days, last 24 hours, the most frequent address", () => {
  const facts = failedSignIns(
    [fail(1, "203.0.113.9", "admin"), fail(2, "203.0.113.9", null), fail(30, "198.51.100.4", "admin"), fail(200, "203.0.113.9", "old")],
    { now: NOW },
  );
  assert.equal(facts.week, 3);
  assert.equal(facts.day, 2);
  assert.deepEqual(facts.address, { value: "203.0.113.9", count: 2 });
  assert.equal(facts.capped, false);
});

test("a full page inside the window is a floor, shown as N+", () => {
  const page = Array.from({ length: 100 }, (_, i) => fail(i / 10, "203.0.113.9", "admin"));
  assert.equal(failedSignIns(page, { now: NOW, reads: [page, []] }).capped, true);
  assert.equal(failedSignIns([], { now: NOW, reads: [[], []] }).week, 0);
});

test("people who never signed in are counted", () => {
  assert.equal(neverSignedIn([{ last_login_at: "2026-11-19T10:00:00+00:00" }, { last_login_at: null }, {}]), 2);
  assert.equal(neverSignedIn(), 0);
});

test("the Central machine account is an actor missing from the complete people list", () => {
  const people = [{ id: 1 }, { id: 2 }];
  assert.equal(isMachineActor({ user: { id: 9, username: "central" } }, people), true);
  assert.equal(isMachineActor({ user: { id: 1, username: "central" } }, people), false);
  assert.equal(isMachineActor({ user: { id: null, username: "gone", deleted: true } }, people), false);
  assert.equal(isMachineActor({ is_system: true, user: null }, people), false);
  // An incomplete list proves nothing.
  assert.equal(isMachineActor({ user: { id: 9, username: "central" } }, null), false);
});

test("the dashboard summarises: one tile per answer, sign-ins out of the feed", () => {
  const page = read("app/admin/page.jsx");
  assert.match(page, /action: "login_failed", per_page: PAGE/);
  assert.match(page, /action: "login_failed_unknown", per_page: PAGE/);
  assert.match(page, /<AdminSummary signIns=\{signIns\}/);
  assert.match(page, /<AttentionList[^>]*signIns=\{signIns\}/);
  assert.doesNotMatch(page, /PeopleCard|SignInsCard/);
  assert.match(read("components/admin/dashboard/activity-feed.jsx"), /!SIGN_IN_ACTIONS\.includes\(entry\.action\)/);
  assert.match(read("components/admin/dashboard/activity-feed.jsx"), /const SHOWN = 5;/);
  assert.deepEqual(SIGN_IN_ACTIONS, ["logged_in", "login_failed", "login_failed_unknown"]);
  // A failed read never says "none".
  assert.match(read("components/admin/dashboard/admin-summary.jsx"), /!signIns\s*\? \{ tone: "quiet", icon: CircleAlert, value: t\("failed"\) \}\s*: signIns\.week === 0/);
});
