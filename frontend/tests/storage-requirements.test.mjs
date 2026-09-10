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

test("an http endpoint is told which scheme is wrong, not just that it is", async () => {
  const fs = await import("node:fs");
  const schema = fs.readFileSync("lib/schemas/storage.js", "utf8");

  // Ordered before the generic regex, because react-hook-form renders the
  // first issue and "must be a full https:// address" is what someone with a
  // perfectly full http:// address reads and disagrees with.
  const insecure = schema.indexOf("endpointInsecure");
  const generic = schema.indexOf('"endpointFormat"');
  assert.ok(insecure > 0 && insecure < generic, "the specific message must come first");
  assert.match(schema, /\^http:\\\/\\\/\/i/, "matched on the scheme, not on the absence of https");

  for (const locale of ["en", "es", "hi"]) {
    const messages = JSON.parse(fs.readFileSync(`messages/${locale}.json`, "utf8"));
    const found = JSON.stringify(messages).includes("endpointInsecure");
    assert.ok(found, `${locale} is missing endpointInsecure`);
  }
});
