import test from "node:test";
import assert from "node:assert/strict";
import {
  dominantReason,
  firstLine,
  summarizeAttention,
} from "../lib/admin/attention-summary.js";

const check = (key, status, title) => ({ key, status, title });
const group = (count, error) => ({
  count,
  occurrences: Array.from({ length: count }, () => ({ error })),
});

test("stderr is reduced to its first meaningful line", () => {
  assert.equal(firstLine("sudo: a password is required\nusage: sudo -h"), "sudo: a password is required");
  assert.equal(firstLine("\n\n  spaced  \nsecond"), "spaced");
  assert.equal(firstLine(""), null);
  assert.equal(firstLine(null), null);
  assert.equal(firstLine(123), null);
});

test("a long line is cut, and says that it was", () => {
  const line = firstLine("x".repeat(200), 20);
  assert.equal(line.length, 20);
  assert.ok(line.endsWith("…"));
});

// The case on this panel: one cause, eleven symptoms.
test("a reason shared by most failures is quoted", () => {
  const groups = [group(8, "sudo: a password is required"), group(2, "no such file")];
  assert.equal(dominantReason(groups), "sudo: a password is required");
});

// Saying "most report X" when X is a third of them is worse than saying nothing.
test("failures that disagree get no claim about the cause", () => {
  const groups = [group(2, "a"), group(2, "b"), group(2, "c")];
  assert.equal(dominantReason(groups), null);
  // Exactly half is not "most".
  assert.equal(dominantReason([group(2, "a"), group(2, "b")]), null);
});

test("failures with no stderr yield no reason rather than an empty quote", () => {
  assert.equal(dominantReason([group(3, null)]), null);
  assert.equal(dominantReason([]), null);
});

test("the summary counts occurrences but totals distinct problems", () => {
  const summary = summarizeAttention({
    checks: [
      check("a", "fail", "Privileged commands"),
      check("b", "fail", "Services"),
      check("c", "warn", "Required tools"),
      check("d", "pass", "Database"),
    ],
    errorGroups: [group(8, "sudo: a password is required"), group(3, "sudo: a password is required")],
  });

  assert.deepEqual(summary.failed, { count: 2, names: ["Privileged commands", "Services"] });
  assert.deepEqual(summary.warnings, { count: 1, names: ["Required tools"] });
  assert.equal(summary.failures.count, 11);
  assert.equal(summary.failures.distinct, 2);
  assert.equal(summary.failures.reason, "sudo: a password is required");
  // 2 failed + 1 warning + 2 distinct failures — a passing check is not an issue.
  assert.equal(summary.total, 5);
});

test("a healthy panel summarises to nothing at all", () => {
  const summary = summarizeAttention({ checks: [check("a", "pass", "Database")], errorGroups: [] });
  assert.equal(summary.total, 0);
  assert.equal(summary.failures.count, 0);
  assert.equal(summary.failures.reason, null);
});

test("called with nothing it does not throw", () => {
  assert.equal(summarizeAttention().total, 0);
});
