import test from "node:test";
import assert from "node:assert/strict";
import {
  FIELDS,
  PRESETS,
  defaultConfig,
  describeDestination,
  fieldsFor,
  isRequired,
  keyDocsUrl,
  presetForProvider,
  providerForPreset,
  secretFieldsFor,
} from "../lib/storage/providers.js";

/*
 * This file replaces storage-provider.test.mjs and storage-requirements.test.mjs,
 * both of which tested machinery that existed only because the API had no
 * provider field: one inferred the provider from the endpoint hostname, the
 * other decided requiredness client-side because "the API cannot enforce this".
 * `provider` is a real, validated column now, so both were deleted rather than
 * updated — there is nothing left for them to assert.
 */

test("every preset maps to a provider the form knows how to render", () => {
  for (const preset of PRESETS) {
    const provider = providerForPreset(preset.value);
    assert.ok(FIELDS[provider], `preset ${preset.value} maps to unknown provider ${provider}`);
    assert.ok(fieldsFor(provider).length > 0, `provider ${provider} has no fields`);
  }
});

test("the S3 services are presets of one provider, not providers of their own", () => {
  // The backend has three providers; the picker has nine entries. Collapsing
  // the S3 services into a single "S3-compatible" option would throw away the
  // endpoint examples and key-docs links that make the form fillable.
  for (const value of ["aws", "r2", "b2", "wasabi", "spaces", "minio", "other"]) {
    assert.equal(providerForPreset(value), "s3");
  }

  assert.equal(providerForPreset("ftp"), "ftp");
  assert.equal(providerForPreset("sftp"), "sftp");
});

test("an unknown preset falls back to s3 rather than throwing", () => {
  assert.equal(providerForPreset("dropbox"), "s3");
  assert.equal(providerForPreset(undefined), "s3");
});

test("editing an S3 destination does not re-guess which S3 service it is", () => {
  // The old code matched the endpoint hostname to decide. A rename must not
  // start claiming a destination is Backblaze because its endpoint looks like
  // it — so editing resolves to the generic preset and asks for nothing extra.
  assert.equal(presetForProvider("s3"), "other");
  assert.equal(presetForProvider("ftp"), "ftp");
  assert.equal(presetForProvider("sftp"), "sftp");
});

test("AWS needs a region and no endpoint; everything else is the other way round", () => {
  const fields = fieldsFor("s3");
  const region = fields.find((f) => f.name === "region");
  const endpoint = fields.find((f) => f.name === "endpoint");

  // AWS carries the region in the bucket host, so the endpoint stays blank —
  // and a blank endpoint is precisely what *means* AWS to the S3 driver.
  assert.equal(isRequired(region, "aws"), true);
  assert.equal(isRequired(endpoint, "aws"), false);

  for (const preset of ["r2", "b2", "wasabi", "spaces", "minio", "other"]) {
    assert.equal(isRequired(region, preset), false, `region should be optional for ${preset}`);
    assert.equal(isRequired(endpoint, preset), true, `endpoint should be required for ${preset}`);
  }
});

test("neither SFTP credential is marked required on its own", () => {
  // One of the two is needed and the schema enforces that pair rule. Marking
  // either as required here would put an asterisk on a field the user is
  // entitled to leave empty.
  const fields = fieldsFor("sftp");
  const password = fields.find((f) => f.name === "password");
  const key = fields.find((f) => f.name === "private_key");

  assert.equal(isRequired(password, "sftp"), false);
  assert.equal(isRequired(key, "sftp"), false);
  assert.equal(password.oneOf, "auth");
  assert.equal(key.oneOf, "auth");
});

test("FTP defaults to TLS and passive mode", () => {
  // The default must match what the backend applies, or the toggle renders off
  // on first paint and saves as on — telling the user the opposite of what
  // will happen.
  assert.deepEqual(defaultConfig("ftp"), { ssl: true, passive: true });

  const ssl = fieldsFor("ftp").find((f) => f.name === "ssl");
  // Turning it off sends the password and every backup in the clear, so the
  // form has to say so rather than treating it as an ordinary preference.
  assert.equal(ssl.warnWhenOff, "plainFtpWarning");
});

test("S3 has no config defaults to apply", () => {
  assert.deepEqual(defaultConfig("s3"), {});
});

test("rotation asks for the credentials this provider actually has", () => {
  // Rotating an SFTP private key is not rotating an access key pair. The
  // dialog used to assume the S3 pair for every destination.
  assert.deepEqual(
    secretFieldsFor("s3").map((f) => f.name),
    ["access_key", "secret_key"],
  );
  assert.deepEqual(
    secretFieldsFor("ftp").map((f) => f.name),
    ["password"],
  );
  assert.deepEqual(
    secretFieldsFor("sftp").map((f) => f.name),
    ["password", "private_key", "passphrase"],
  );
});

test("a row describes an S3 bucket and an FTP host differently", () => {
  assert.deepEqual(
    describeDestination({
      provider: "s3",
      prefix: "app1",
      config: { bucket: "backups-prod", endpoint: "https://s3.wasabisys.com" },
    }),
    { location: "backups-prod/app1", address: "https://s3.wasabisys.com" },
  );

  // No bucket, no endpoint — an empty bucket column beside a hostname is
  // worse than no column at all.
  assert.deepEqual(
    describeDestination({
      provider: "ftp",
      prefix: "shop.example.com",
      config: { host: "backup.example.com", port: 21, username: "backups", root: "archive" },
    }),
    { location: "archive/shop.example.com", address: "backups@backup.example.com:21" },
  );
});

test("an S3 destination with no endpoint reports no address, so the row can say AWS default", () => {
  const described = describeDestination({ provider: "s3", prefix: "", config: { bucket: "b" } });

  assert.equal(described.location, "b");
  assert.equal(described.address, null);
});

test("describing a half-loaded destination does not throw", () => {
  // Rows render from list data that a schema has defaulted; a missing config
  // must degrade to empty strings rather than crashing the page.
  assert.deepEqual(describeDestination({}), { location: "", address: null });
  assert.deepEqual(describeDestination(undefined), { location: "", address: null });
});

test("key docs exist for the services that have a console, and not for the ones that do not", () => {
  for (const preset of ["aws", "r2", "b2", "wasabi", "spaces"]) {
    assert.match(keyDocsUrl(preset), /^https:\/\//);
  }

  // A self-hosted MinIO has no common console, and an FTP server's password
  // came from whoever set it up — a link here would go nowhere useful.
  for (const preset of ["minio", "other", "ftp", "sftp"]) {
    assert.equal(keyDocsUrl(preset), null);
  }
});
