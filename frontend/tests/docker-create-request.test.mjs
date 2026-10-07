import { test } from "node:test";
import assert from "node:assert/strict";
import {
  DOCKER_LIMITS,
  dockerCreateFields,
  dockerEnvProblem,
  dockerServerErrors,
  dockerVolumeProblem,
  envRowProblem,
} from "../lib/docker/create-request.js";

const row = (key, value = "", extra = {}) => ({ id: `r-${key}`, key, value, original: "", required: false, fromImage: false, ...extra });

// The "Settings" readiness item (settingsIncomplete) holds Create while this is true.
test("a required setting with no value holds Create; filling it releases it", () => {
  const required = row("DATABASE_URL", "", { required: true, fromImage: true });
  assert.equal(dockerEnvProblem([required], ["DATABASE_URL"]), true);
  assert.equal(dockerEnvProblem([{ ...required, value: "   " }], ["DATABASE_URL"]), true);
  assert.equal(dockerEnvProblem([{ ...required, value: "postgres://db" }], ["DATABASE_URL"]), false);
});

// Review finding 1: removing the row used to make the problem disappear.
test("a required setting that is no longer in the list still holds Create", () => {
  assert.equal(dockerEnvProblem([row("TZ", "UTC")], ["DATABASE_URL"]), true);
  assert.equal(dockerEnvProblem([row("DATABASE_URL", "x"), row("TZ", "UTC")], ["DATABASE_URL"]), false);
  assert.equal(dockerEnvProblem([], []), false);
});

test("a bad name, a value with no name, or one over the caps holds Create", () => {
  assert.equal(envRowProblem(row("MY-VAR", "x")), "badKey");
  assert.equal(envRowProblem(row("", "orphan")), "badKey");
  assert.equal(envRowProblem(row("A".repeat(DOCKER_LIMITS.envKey + 1), "x")), "badKey");
  assert.equal(envRowProblem(row("BIG", "x".repeat(DOCKER_LIMITS.envValue + 1))), "valueTooLong");
  assert.equal(envRowProblem(row("", "")), null);
  const many = Array.from({ length: DOCKER_LIMITS.envRows + 1 }, (_, i) => row(`K${i}`, "v"));
  assert.equal(dockerEnvProblem(many), true);
  assert.equal(dockerEnvProblem(many.slice(1)), false);
});

test("more ticked folders than the API takes holds Create", () => {
  const folders = (n) => Array.from({ length: n }, (_, i) => ({ path: `/d${i}`, checked: true }));
  assert.equal(dockerVolumeProblem(folders(DOCKER_LIMITS.volumes)), false);
  assert.equal(dockerVolumeProblem(folders(DOCKER_LIMITS.volumes + 1)), true);
  assert.equal(dockerVolumeProblem([...folders(DOCKER_LIMITS.volumes), { path: "/x", checked: false }]), false);
});

// Review finding 2: sending no volume keys let the backend add the image's own.
test("no ticked folder is an explicit empty list, not silence", () => {
  const { fields } = dockerCreateFields({
    applicationName: "jelly",
    volumes: [
      { path: "/config", checked: false },
      { path: "/cache", checked: false },
    ],
  });
  assert.deepEqual(fields.volume_mounts, []);
  assert.equal("volume_new" in fields, false);
});

test("one folder keeps the original pair; several go as a list", () => {
  const one = dockerCreateFields({ applicationName: "jelly", volumes: [{ path: "/config", checked: true }, { path: "/cache", checked: false }] });
  assert.deepEqual(one.fields, { volume_new: "jelly-config", volume_path: "/config" });
  const two = dockerCreateFields({ applicationName: "jelly", volumes: [{ path: "/config", checked: true }, { path: "/cache", checked: true }] });
  assert.deepEqual(two.fields.volume_mounts, [
    { volume: "jelly-config", path: "/config" },
    { volume: "jelly-cache", path: "/cache" },
  ]);
});

test("only settings that differ from the image are sent, required ones always", () => {
  const envRows = [
    row("APP_SECRET", "x", { fromImage: true, original: "x" }),
    row("DATABASE_URL", "postgres://db", { fromImage: true, required: true }),
    row("TZ", "UTC"),
    row("", ""),
  ];
  const { fields, sentEnvIds } = dockerCreateFields({ applicationName: "umami", envRows });
  assert.deepEqual(fields.env, [
    { key: "DATABASE_URL", value: "postgres://db" },
    { key: "TZ", value: "UTC" },
  ]);
  assert.deepEqual(sentEnvIds, ["r-DATABASE_URL", "r-TZ"]);
});

// Review finding 3: these went to a toast because `env` is not a form field.
test("a 422 on a sent setting or folder is placed on the row it came from", () => {
  const envRows = [row("APP_SECRET", "x", { fromImage: true, original: "x" }), row("DATABASE_URL", "bad"), row("TZ", "UTC")];
  const volumes = [{ path: "/config", checked: false }, { path: "/cache", checked: true }, { path: "/data", checked: true }];
  const sent = dockerCreateFields({ applicationName: "a", envRows, volumes });
  const { set, rest } = dockerServerErrors(
    {
      "env.0.value": ["Not a URL"],
      "env.1.key": ["Taken"],
      env: ["DATABASE_URL is required"],
      "volume_mounts.1.path": ["Reserved path"],
      volume_mounts: ["Too many"],
      name: ["Taken"],
    },
    { ...sent, envRows, volumes },
  );
  assert.deepEqual(set, [
    ["docker_env.1.value", "Not a URL"],
    ["docker_env.2.key", "Taken"],
    ["docker_env_list", "DATABASE_URL is required"],
    ["docker_volumes.2", "Reserved path"],
    ["docker_volumes_list", "Too many"],
  ]);
  assert.deepEqual(rest, { name: ["Taken"] });
});

test("the single-folder pair's errors land on that folder", () => {
  const volumes = [{ path: "/config", checked: false }, { path: "/cache", checked: true }];
  const sent = dockerCreateFields({ applicationName: "a", volumes });
  const { set } = dockerServerErrors({ volume_path: ["Reserved path"] }, { ...sent, volumes });
  assert.deepEqual(set, [["docker_volumes.1", "Reserved path"]]);
});
