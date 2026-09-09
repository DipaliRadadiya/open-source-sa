import test from "node:test";
import assert from "node:assert/strict";
import { providerFromEndpoint } from "../lib/storage/provider-from-endpoint.js";

test("the endpoint names the provider the API will not", () => {
  /*
   * The API stores `driver: "s3"` for every destination and the name someone
   * typed, so a Backblaze bucket could sit in the list saying nothing but
   * "S3-compatible". The endpoint is the fact that distinguishes them.
   */
  assert.equal(providerFromEndpoint("https://abc123.r2.cloudflarestorage.com/bucket"), "r2");
  assert.equal(providerFromEndpoint("https://s3.us-west-002.backblazeb2.com"), "b2");
  assert.equal(providerFromEndpoint("https://s3.eu-central-1.wasabisys.com"), "wasabi");
  assert.equal(providerFromEndpoint("https://ams3.digitaloceanspaces.com"), "spaces");
  assert.equal(providerFromEndpoint("https://s3.eu-west-1.amazonaws.com"), "aws");
});

test("no endpoint is AWS, which is the only provider that needs none", () => {
  assert.equal(providerFromEndpoint(null), "aws");
  assert.equal(providerFromEndpoint(undefined), "aws");
  assert.equal(providerFromEndpoint("   "), "aws");
});

test("an unrecognised host says nothing rather than guessing", () => {
  // A MinIO box on a company domain is unknowable from here, and a wrong
  // provider label is worse than none.
  assert.equal(providerFromEndpoint("https://storage.example.com"), null);
  assert.equal(providerFromEndpoint("not a url at all"), null);
});

test("a longer host wins, so wasabi is never read as AWS", () => {
  // `s3.…wasabisys.com` contains "s3." and must not match on that alone.
  assert.equal(providerFromEndpoint("https://s3.wasabisys.com"), "wasabi");
  // And a lookalike suffix does not match a different registrable domain.
  assert.equal(providerFromEndpoint("https://notamazonaws.com"), null);
  assert.equal(providerFromEndpoint("https://bucket.s3.amazonaws.com"), "aws");
});

test("a bare host is accepted, not only a URL", () => {
  assert.equal(providerFromEndpoint("s3.eu-central-1.wasabisys.com"), "wasabi");
  assert.equal(providerFromEndpoint("abc.r2.cloudflarestorage.com/bucket"), "r2");
});
