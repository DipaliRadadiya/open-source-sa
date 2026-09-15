import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import { createStorageDestinationSchema } from "../lib/schemas/storage.js";
import { PRESETS, defaultConfig, providerForPreset } from "../lib/storage/providers.js";

const DIALOG = fs.readFileSync("components/integrations/storage/connect-dialog.jsx", "utf8");

/** What the dialog actually holds, for a given preset. */
function formValues(preset, config = {}) {
  return {
    name: "My destination",
    prefix: "",
    config: { ...defaultConfig(providerForPreset(preset)), ...config },
  };
}

const FILLED = {
  s3: { bucket: "backups", region: "us-east-1", endpoint: "", access_key: "AKIA", secret_key: "s3cret" },
  ftp: { host: "ftp.example.com", username: "u", password: "p" },
  sftp: { host: "sftp.example.com", username: "u", password: "p" },
  webdav: { base_uri: "https://webdav.pcloud.com", username: "u", password: "p" },
  google_drive: { service_account_json: '{"type":"service_account"}', folder_id: "abc123" },
};

test("the schema validates the shape the form actually submits", () => {
  /*
   * It did not. The schema declared `preset: z.string()` while the preset
   * lives in COMPONENT state — it selects the schema, so it cannot be a value
   * that schema validates — and the form's defaultValues are name/prefix/config
   * only. Every submit therefore failed on a key the form never held, at a path
   * no input renders, so react-hook-form had nowhere to show it: the Add button
   * did nothing, for every provider, with no error and no request. The preset
   * is accounted for as the ARGUMENT to this function.
   */
  for (const { value: preset, provider } of PRESETS) {
    // A blank endpoint is what MEANS AWS to the S3 driver, and is required of
    // everyone else — so the complete form differs by preset, not by provider.
    const filled =
      provider === "s3" && preset !== "aws"
        ? { ...FILLED.s3, endpoint: "https://s3.example.com" }
        : FILLED[provider];
    const result = createStorageDestinationSchema(preset).safeParse(formValues(preset, filled));
    assert.equal(
      result.success,
      true,
      `${preset} rejects a complete form: ${JSON.stringify(result.error?.issues?.map((i) => [i.path.join("."), i.message]))}`,
    );
  }
});

test("a missing field is reported at that field, in words we translate", () => {
  /*
   * An untouched input is `undefined`, and a refinement never runs on a value
   * the base type already rejected — so `z.string()` failed first and the user
   * read Zod's own "Invalid input: expected string, received undefined", in
   * English, on a panel with eight locales.
   *
   * `requiredField` is the key FormMessage turns into "Bucket is required"
   * using the field's own label. Per-field keys would not do: these names are
   * the API's snake_case (`access_key`, `service_account_json`) and the
   * `required_*` catalogue is camelCase.
   */
  const empty = { name: "", prefix: "", config: {} };
  const result = createStorageDestinationSchema("other").safeParse(empty);

  assert.equal(result.success, false);
  const issues = result.error.issues.filter((i) => i.path[0] === "config");
  assert.ok(issues.length >= 3, "the empty config fields are reported");
  for (const issue of issues) {
    assert.equal(issue.message, "requiredField", `${issue.path.join(".")} reports a raw Zod message`);
  }
});

test("the preset is state, not a form field", () => {
  // The pairing that broke: if it ever becomes a form value it has to appear in
  // defaultValues too, or this is the same bug again.
  assert.match(DIALOG, /const \[preset, setPreset\] = useState\(DEFAULT_PRESET\)/);
  assert.doesNotMatch(DIALOG, /defaultValues:\s*\{[^}]*preset/s);
});

test("an optional field left blank is still accepted", () => {
  // `prefix` and S3's `region` are genuinely optional — requiring everything
  // would be the opposite failure, and just as invisible from the button.
  const result = createStorageDestinationSchema("other").safeParse(
    formValues("other", { bucket: "b", endpoint: "https://s3.example.com", access_key: "k", secret_key: "s" }),
  );
  assert.equal(result.success, true);
});

test("a blank field is told it is blank, not that its format is wrong", () => {
  /*
   * The second half of the same bug. Seeding the form with "" made the values
   * parseable, but "" fails the bucket-name pattern — so a field nobody had
   * touched was told it may only contain letters, numbers, dots, dashes and
   * underscores. Emptiness is judged first and stops there; a value that was
   * actually typed still gets the field's own rule.
   */
  const blank = createStorageDestinationSchema("other").safeParse(formValues("other"));
  const messages = Object.fromEntries(
    blank.error.issues.filter((i) => i.path[0] === "config").map((i) => [i.path[1], i.message]),
  );
  assert.deepEqual(messages, {
    bucket: "requiredField",
    endpoint: "requiredField",
    access_key: "requiredField",
    secret_key: "requiredField",
  });

  const typed = createStorageDestinationSchema("other").safeParse(
    formValues("other", {
      bucket: "Not A Bucket!",
      endpoint: "notaurl",
      access_key: "k",
      secret_key: "s",
    }),
  );
  assert.deepEqual(
    typed.error.issues.map((i) => i.message),
    ["bucketFormat", "endpointFormat"],
    "a typed value still answers with the field's own rule",
  );
});
