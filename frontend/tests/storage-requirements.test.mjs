import test from "node:test";
import assert from "node:assert/strict";
import {
  createRequirements,
  editRequirements,
} from "../lib/storage/requirements.js";

test("AWS needs a region and no endpoint", () => {
  assert.deepEqual(createRequirements("aws"), { endpoint: false, region: true });
});

test("every other S3-compatible provider needs an endpoint", () => {
  for (const provider of ["wasabi", "r2", "b2", "spaces", "minio", "other"]) {
    assert.deepEqual(
      createRequirements(provider),
      { endpoint: true, region: false },
      provider,
    );
  }
});

test("an unknown or missing provider is treated as not-AWS", () => {
  assert.equal(createRequirements(undefined).endpoint, true);
});

test("editing keeps what the destination already relies on", () => {
  assert.deepEqual(
    editRequirements({ endpoint: "https://s3.wasabisys.com", region: "" }),
    { endpoint: true, region: false },
  );
  assert.deepEqual(
    editRequirements({ endpoint: "", region: "eu-west-1" }),
    { endpoint: false, region: true },
  );
});

test("editing demands nothing a destination never had", () => {
  assert.deepEqual(editRequirements({}), { endpoint: false, region: false });
  assert.deepEqual(editRequirements(null), { endpoint: false, region: false });
});
