import { test } from "node:test";
import assert from "node:assert/strict";
import { groupErrorLogs } from "../lib/admin/group-error-logs.js";
import { errorLogsResponseSchema } from "../lib/schemas/error-log.js";

const opEntry = {
  occurred_at: "2026-08-31T06:03:43.855779+00:00",
  status: null,
  method: null,
  route: null,
  exception: null,
  message: "Server operation failed.",
  reference: "7122f589-6ba2-47d0-bc00-72e1321f8652",
  user_id: null,
  feature: "log",
  operation: "exists",
  exit_code: 1,
  error: "test: cannot stat",
};

test("the raw payload is the API's entry, not our grouped shape", () => {
  const [group] = groupErrorLogs([opEntry]);
  const { raw } = group.occurrences[0];

  // `at` is a Date we derive for sorting; it never came from the backend and
  // would render as an unexplained extra key in a view labelled "raw".
  assert.equal("at" in raw, false);
  assert.equal("raw" in raw, false);
  assert.deepEqual(raw, opEntry);
});

test("the grouped copy still carries what the row reads", () => {
  const [group] = groupErrorLogs([opEntry]);
  const occurrence = group.occurrences[0];

  assert.ok(occurrence.at instanceof Date);
  assert.equal(occurrence.error, opEntry.error);
  assert.equal(occurrence.reference, opEntry.reference);
});

test("a field the backend adds later survives into the raw view", () => {
  // The whole point of the schema's passthrough: `command` is recorded in the
  // log today but dropped before it reaches us. When it starts arriving it has
  // to appear here without a frontend change.
  const withCommand = { ...opEntry, command: "sudo -n test -f /var/log/x.log", duration_ms: 17 };
  const parsed = errorLogsResponseSchema.safeParse({ error_logs: [withCommand] });

  assert.equal(parsed.success, true);
  const [group] = groupErrorLogs(parsed.data.error_logs);
  assert.equal(group.occurrences[0].raw.command, "sudo -n test -f /var/log/x.log");
  assert.equal(group.occurrences[0].raw.duration_ms, 17);
});

test("JSON.stringify of the raw payload does not throw on a real entry", () => {
  const [group] = groupErrorLogs([opEntry]);
  const json = JSON.stringify(group.occurrences[0].raw, null, 2);
  assert.match(json, /"feature": "log"/);
  assert.doesNotMatch(json, /"at":/);
});
