import { test } from "node:test";
import assert from "node:assert/strict";
import { orderedTypes, DEFAULT_TYPE } from "../lib/backups/frequency.js";
import { backupTargetOptionsSchema } from "../lib/schemas/backup.js";

// The shape GET /backup-targets/options answers with since the Docker work.
const OPTIONS = backupTargetOptionsSchema.parse({
  frequencies: [{ value: "daily", label: "Daily", time: "time" }],
  default_frequency: "daily",
  types: [
    { value: "filesystem", label: "Files", family: "hosted" },
    { value: "database", label: "Database", family: "hosted" },
    { value: "full", label: "Files and database", family: "hosted" },
    { value: "volumes", label: "Volumes", family: "container" },
    { value: "config", label: "Compose and config", family: "container" },
    { value: "volumes_config", label: "Volumes, compose and config", family: "container" },
  ],
  retention: { min: 1, max: 365 },
});

test("the options schema keeps `family`; stripping it put Docker types on every site", () => {
  assert.equal(OPTIONS.types.find((t) => t.value === "volumes").family, "container");
});

test("a WordPress-style site is offered only the hosted types, full first", () => {
  assert.deepEqual(orderedTypes(OPTIONS, false).map((t) => t.value), ["full", "filesystem", "database"]);
});

test("a Docker site is offered only the container types, volumes and config first", () => {
  assert.deepEqual(orderedTypes(OPTIONS, true).map((t) => t.value), ["volumes_config", "volumes", "config"]);
  assert.equal(DEFAULT_TYPE.container, "volumes_config");
});

test("an older API without `family` still offers the hosted types", () => {
  const legacy = { types: [{ value: "full", label: "Full" }, { value: "database", label: "DB" }] };
  assert.deepEqual(orderedTypes(legacy, false).map((t) => t.value), ["full", "database"]);
  assert.deepEqual(orderedTypes(legacy, true), []);
});
