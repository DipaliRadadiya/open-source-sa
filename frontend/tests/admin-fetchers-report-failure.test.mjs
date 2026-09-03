import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

/**
 * Both of these used to swallow every failure into an empty list, so a dead
 * API rendered as "No users yet" on the screen whose whole job is to say who
 * can get into this server. The bug was invisible because the fetcher looked
 * tidy and the page looked fine.
 *
 * Asserted against the source rather than by calling them: they read cookies
 * and hit the network, so exercising them here would test a mock of my own
 * making. What can be checked cheaply is that neither has gone back to
 * returning a bare fallback.
 */
const read = (p) => readFileSync(new URL(`../lib/${p}`, import.meta.url), "utf8");

for (const [name, path] of [
  ["users", "users/get-users.js"],
  ["activity log", "activity-log/get-activity-log.js"],
]) {
  test(`the admin ${name} fetcher reports failure instead of returning empty`, () => {
    const src = read(path);
    assert.match(src, /failed: result\.failed/,
      `${path} must pass \`failed\` out to the page`);
    assert.match(src, /from "@\/lib\/api\/read"/,
      `${path} should use the shared read() helper, which carries failure`);
    // The old shape: `if (!res.ok) return EMPTY` and a catch that does the same.
    assert.doesNotMatch(src, /return EMPTY\b/,
      `${path} must not fall back to an empty result on failure`);
  });
}

for (const [name, page] of [
  ["users", "../app/admin/users/page.jsx"],
  ["activity log", "../app/admin/activity-log/page.jsx"],
]) {
  test(`the admin ${name} page renders the failure it is given`, () => {
    const src = readFileSync(new URL(page, import.meta.url), "utf8");
    // Deliberately not matching the exact statement: this test broke the
    // moment the one-liner became a block, which is a test guarding its own
    // formatting rather than the behaviour. What matters is that `failed`
    // gates a LoadFailed somewhere before the table renders.
    assert.match(src, /\bif \(failed\)/,
      "a page that receives `failed` and ignores it is the same lie one level up");
    assert.match(src, /<LoadFailed/, "the failure has to reach a panel that says so");
    // The redirect reads `meta`, which is empty on failure — bouncing to page 1
    // would send the reader to re-read the same error.
    assert.match(src, /redirectOutOfRange\([^)]*failed\)/s,
      "the out-of-range redirect must be suppressed on a failed load");
  });
}
