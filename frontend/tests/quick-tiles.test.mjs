import test from "node:test";
import assert from "node:assert/strict";
import { isPortOpen, matchRule, quickTileState } from "../lib/firewall/quick-tiles.js";

const HTTP = { key: "http", label: "HTTP", port: 80, protocol: "tcp" };
const rule = (over = {}) => ({
  id: 1,
  action: "allow",
  port_from: 80,
  protocol: "tcp",
  enabled: true,
  ...over,
});

test("a rule that exists but is switched off is not an open port", () => {
  /*
   * The bug this exists for. The card asked "is there a rule?" and printed
   * "Added" with a green tick, while the table below the same tile showed the
   * rule switched off and the warning above it listed port 80 as blocked. One
   * screen, two opposite answers, and the wrong one was the reassuring one.
   */
  const rules = [rule({ enabled: false })];

  assert.equal(quickTileState(HTTP, rules), "off");
  assert.equal(isPortOpen(HTTP, rules), false);
  // The rule is still findable — turning it back on needs its id, and looking
  // it up by "open ports only" would make the fix unreachable from the tile.
  assert.equal(matchRule(HTTP, rules)?.id, 1);
});

test("the three states are distinct", () => {
  assert.equal(quickTileState(HTTP, []), "missing");
  assert.equal(quickTileState(HTTP, [rule()]), "on");
  assert.equal(quickTileState(HTTP, [rule({ enabled: false })]), "off");
});

test("a missing `enabled` counts as on, because most rules omit it", () => {
  const bare = { id: 2, action: "allow", port_from: 80, protocol: "tcp" };
  assert.equal(quickTileState(HTTP, [bare]), "on");
});

test("only a rule that actually covers the tile counts", () => {
  // Wrong protocol: a UDP rule on 80 opens nothing for a TCP tile.
  assert.equal(quickTileState(HTTP, [rule({ protocol: "udp" })]), "missing");
  // "all" covers tcp.
  assert.equal(quickTileState(HTTP, [rule({ protocol: "all" })]), "on");
  // Deny is not allow.
  assert.equal(quickTileState(HTTP, [rule({ action: "deny" })]), "missing");
  // Restricted to one address, or part of a range — a different rule.
  assert.equal(quickTileState(HTTP, [rule({ source_ip: "1.2.3.4" })]), "missing");
  assert.equal(quickTileState(HTTP, [rule({ port_to: 90 })]), "missing");
  // Different port.
  assert.equal(quickTileState(HTTP, [rule({ port_from: 8080 })]), "missing");
});

test("the port is compared by value, not by type", () => {
  // The API has returned ports as strings; a === here would have read every
  // existing rule as missing and offered to create duplicates.
  assert.equal(quickTileState(HTTP, [rule({ port_from: "80" })]), "on");
});

test("no rules at all is not a crash", () => {
  assert.equal(quickTileState(HTTP), "missing");
  assert.equal(isPortOpen(HTTP), false);
  assert.equal(matchRule(HTTP), undefined);
});
