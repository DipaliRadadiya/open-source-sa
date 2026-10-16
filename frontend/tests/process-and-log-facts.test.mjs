import { test } from "node:test";
import assert from "node:assert/strict";
import { processSchema } from "../lib/schemas/application.js";
import { applicationLogSourceSchema } from "../lib/schemas/application-log.js";

test("the process keeps pid and cpu_percent, and null stays null (shown as —)", () => {
  const running = processSchema.parse({ state: "active", memory: 1024, cpu_percent: 12.5, pid: 4242, restarts: 0 });
  assert.equal(running.cpu_percent, 12.5);
  assert.equal(running.pid, 4242);
  // systemd's first read and a stopped process.
  const first = processSchema.parse({ state: "inactive", cpu_percent: null, pid: null });
  assert.equal(first.cpu_percent, null);
  assert.equal(first.pid, null);
});

test("an application log source keeps size and modified; unknown is null, never 0", () => {
  const file = applicationLogSourceSchema.parse({ key: "access", label: "Access log", kind: "file", exists: true, size: 1048576, modified: "16-10-2026 12:00:00" });
  assert.equal(file.size, 1048576);
  assert.equal(file.modified, "16-10-2026 12:00:00");
  const journal = applicationLogSourceSchema.parse({ key: "application", label: "Output", kind: "journal", exists: true, size: null, modified: null });
  assert.equal(journal.size, null);
  // An older backend without the fields still parses.
  assert.equal(applicationLogSourceSchema.parse({ key: "error", label: "Error log" }).size, undefined);
});
