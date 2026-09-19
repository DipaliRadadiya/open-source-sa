import { z } from "zod";
// Relative, not `@/`: the alias is a bundler feature, so a file that uses it
// cannot be imported by `node --test`. This schema had no test for exactly
// that reason, and shipped a create form whose button did nothing.
import { fieldsFor, isRequired, providerForPreset } from "../storage/providers.js";

export const storageDestinationSchema = z
  .object({
    id: z.number(),
    name: z.string(),
    // The real provider, read from the API. This used to be a `driver` that
    // was always the string "s3", and the panel inferred the truth by matching
    // the endpoint hostname — a guess that was wrong for a self-hosted MinIO
    // and meaningless for an FTP host.
    provider: z.string().default("s3"),
    provider_title: z.string().nullish(),
    // Addressing detail only — bucket and region for S3, host and port for
    // FTP/SFTP. Credentials are never sent, so the shape varies by provider
    // and nothing here may assume a bucket exists.
    config: z.record(z.string(), z.any()).default({}),
    prefix: z.string().nullish(),
    // Computed from the encrypted config — the secrets themselves are never
    // sent, so this is the only thing the UI can know about them.
    has_credentials: z.boolean().default(false),
    // The last connection probe, as the backend REMEMBERS it. Cleared
    // automatically when anything about the connection changes, so it never
    // claims a rotated-out credential still works.
    status: z.string().nullish(),
    status_title: z.string().nullish(),
    last_tested_at: z.string().nullish(),
    last_tested_at_human: z.string().nullish(),
    // `null` is "never tested", which is a different state from `false`.
    last_test_success: z.boolean().nullish(),
    // A stable category — `invalid_credentials` | `unreachable` |
    // `host_key_mismatch` | `invalid_private_key` | `mismatch`. Branch on it,
    // but always with a fallback: a driver added later can return a category
    // this build has never heard of, and an unknown one must degrade to the
    // generic failure message rather than render blank.
    last_test_error: z.string().nullish(),
    created_at: z.string().nullish(),
    created_at_human: z.string().nullish(),
    updated_at: z.string().nullish(),
    updated_at_human: z.string().nullish(),
  })
  .passthrough();

export const storageDestinationsResponseSchema = z.object({
  storage_destinations: z.array(storageDestinationSchema).default([]),

  // The callback URL an operator registers with Google. Panel-wide, so it
  // rides along with the list rather than needing a destination to exist —
  // it is needed before the first one is created. Optional so an older API
  // does not fail the whole read over a string the Drive form alone uses.
  google_oauth_redirect_uri: z.string().optional().nullable(),
});

/**
 * The connection probe.
 *
 * This endpoint answers **200 even when the probe fails** — deliberately: the
 * request succeeded, the panel went and looked, and this is what it found. The
 * verdict lives in `test.success`, so a caller that only checks for a thrown
 * error will report a dead destination as working. (It did. That is why this
 * schema exists.)
 */
export const storageTestResponseSchema = z.object({
  test: z.object({
    success: z.boolean().default(false),
    latency_ms: z.number().nullish(),
    message: z.string().nullish(),
    error_class: z.string().nullish(),
    tested_at: z.string().nullish(),
  }),
});

export const storageDestinationResponseSchema = z.object({
  storage_destination: storageDestinationSchema,
});

// Mirrors the backend rules so the common mistakes are caught before a round
// trip: a bucket with a slash in it, a region with an underscore, an endpoint
// that isn't https, a host that is really a pasted URL.
const nameField = z.string().trim().min(1, "required_name").max(100, "max100");
const prefixField = z
  .union([z.literal(""), z.string().trim().max(255, "max255").regex(/^[A-Za-z0-9._/-]*$/, "prefixFormat")])
  .optional();

const bucketField = z.string().trim().max(255, "max255").regex(/^[A-Za-z0-9._-]+$/, "bucketFormat");
const regionField = z.string().trim().max(64, "max64").regex(/^[A-Za-z0-9-]+$/, "regionFormat");

// Optional at the field level, but when given it has to be an https URL — the
// backend refuses loopback and the cloud metadata range, and a plain http
// endpoint would send the credentials in clear.
const endpointField = z
  .string()
  .trim()
  .max(255, "max255")
  /*
   * `http://` gets its own message. The generic one — "must be a full https://
   * address" — is true and unhelpful when someone HAS typed a full address and
   * the only thing wrong is a missing "s": they read it, look at their
   * perfectly complete URL, and try again. Naming the scheme and why it
   * matters is the difference between one attempt and three.
   */
  .refine((value) => !/^http:\/\//i.test(value.trim()), "endpointInsecure")
  .regex(/^https:\/\/[^\s/$.?#].[^\s]*$/i, "endpointFormat")
  // An endpoint still containing <…> is the example copied verbatim with the
  // account id or region never filled in. It passes every other check — no
  // spaces, valid https — and saves happily, then fails at the first backup.
  // Someone did exactly this on the live panel while this feature was being
  // built, which is how the guard got written.
  .refine((value) => !/[<>]/.test(value), "endpointPlaceholder");

// A bare hostname or IP, never a URL. Mirrors `SafeRemoteHost`: the backend
// refuses a pasted URL outright rather than silently keeping the part before
// the slash, because the value meant and the value stored would differ and the
// difference surfaces as a failed backup much later.
const hostField = z
  .string()
  .trim()
  .max(255, "max255")
  .refine((v) => !/:\/\//.test(v), "hostIsUrl")
  .refine((v) => !/[/@\s]/.test(v), "hostIsUrl")
  .refine(
    (v) => !["localhost", "127.0.0.1", "0.0.0.0", "::1", "169.254.169.254"].includes(v.toLowerCase()),
    "hostForbidden",
  );

const portField = z.union([z.literal(""), z.coerce.number().int().min(1, "portRange").max(65535, "portRange")]).optional();

// A remote path. Traversal is refused: `..` in a destination root is either a
// mistake or an attempt to climb out of the backup directory.
const rootField = z
  .union([
    z.literal(""),
    z
      .string()
      .trim()
      .max(255, "max255")
      .regex(/^[A-Za-z0-9._/-]*$/, "rootFormat")
      .refine((v) => !/(^|\/)\.\.(\/|$)/.test(v), "rootTraversal"),
  ])
  .optional();

/** Per-field validators, keyed by the config key they validate. */
const CONFIG_FIELDS = {
  bucket: bucketField,
  region: regionField,
  endpoint: endpointField,
  access_key: z.string().trim().max(255, "max255"),
  secret_key: z.string().trim().max(512, "max512"),
  host: hostField,
  port: portField,
  username: z.string().trim().max(255, "max255"),
  password: z.string().trim().max(512, "max512"),
  private_key: z.string().trim().max(16384, "max16384"),
  passphrase: z.string().trim().max(512, "max512"),
  root: rootField,
  ssl: z.boolean(),
  passive: z.boolean(),
};

/**
 * Whatever the chosen preset needs, and nothing it doesn't.
 *
 * Built from the same declaration the form renders from, so a field cannot be
 * shown without being validated or validated without being shown — the two
 * drifting apart is how a form starts rejecting a value it never asked for.
 */
function configSchemaFor(preset, { requireSecrets = true } = {}) {
  const provider = providerForPreset(preset);
  const shape = {};

  for (const field of fieldsFor(provider)) {
    let schema = CONFIG_FIELDS[field.name] ?? z.any();
    const required = isRequired(field, preset) && (requireSecrets || field.kind !== "secret");

    if (required) {
      /*
       * Emptiness is judged BEFORE the field's own rules, and stops there.
       *
       * Chaining a refinement after the base type gave the wrong answer twice
       * over. An untouched field is `undefined`, which `z.string()` rejects
       * before any refinement runs, so the user read Zod's own "Invalid input:
       * expected string, received undefined" — in English, on a panel with
       * eight locales. Seeding the form with "" moved the failure rather than
       * fixing it: "" fails the bucket-name pattern, so a field someone simply
       * had not filled in was told it may only contain letters, numbers, dots,
       * dashes and underscores.
       *
       * `requiredField` rather than `required_<name>`: these names are the
       * API's snake_case — `access_key`, `service_account_json` — and the
       * `required_*` catalogue is camelCase. FormMessage builds "Bucket is
       * required" from the label the field already carries.
       */
      const rules = schema;
      schema = z.any().superRefine((value, ctx) => {
        if (String(value ?? "").trim() === "") {
          ctx.addIssue({ code: "custom", message: "requiredField" });
          return;
        }
        const parsed = rules.safeParse(value);
        // Something WAS typed, so the field's own rule is the useful answer.
        if (!parsed.success) for (const issue of parsed.error.issues) ctx.addIssue(issue);
      });
    } else {
      schema = schema.optional().or(z.literal(""));
    }

    shape[field.name] = schema;
  }

  return z.object(shape).passthrough();
}

/*
 * No `preset` key, and that is the whole bug this once had.
 *
 * The preset is not a form value — it lives in component state, because it
 * selects the SCHEMA and a resolver cannot be rebuilt from a value it is
 * validating. Declaring it here anyway made every submit fail on a field the
 * form never held and no input ever renders: Zod raised "required" at path
 * `preset`, react-hook-form had nowhere to show it, and the Add button did
 * nothing at all, for every provider, with no error and no request. The
 * preset is already accounted for — it is the ARGUMENT to this function.
 */
export function createStorageDestinationSchema(preset) {
  const provider = providerForPreset(preset);

  return z
    .object({
      name: nameField,
      prefix: prefixField,
      config: configSchemaFor(preset),
    })
    .superRefine((values, ctx) => {
      // SFTP authenticates with a password OR a private key. Neither can be
      // required on its own, but a destination with neither cannot connect at
      // all — and storing one means the failure arrives at the first backup
      // rather than at the form that could have prevented it.
      if (provider !== "sftp") return;

      const hasPassword = Boolean(values.config?.password?.trim?.());
      const hasKey = Boolean(values.config?.private_key?.trim?.());

      if (!hasPassword && !hasKey) {
        ctx.addIssue({ code: "custom", path: ["config", "password"], message: "sftpAuthRequired" });
      }
    });
}

/**
 * Editing sends no credentials at all.
 *
 * PATCH treats a present credential as "rotate", so the only safe way to
 * rename a destination is never to send them — rotation is its own dialog.
 * The provider is absent too: it is immutable on the API, and offering a
 * control whose only outcome is a 422 is not a control.
 */
export function editStorageDestinationSchema(destination) {
  const preset = destination?.provider === "s3" ? "other" : destination?.provider;

  return z.object({
    name: nameField,
    prefix: prefixField,
    config: configSchemaFor(preset, { requireSecrets: false }),
  });
}

/** Rotation: only the credential fields, all of them required. */
export function replaceCredentialsSchema(destination) {
  const provider = destination?.provider ?? "s3";
  const shape = {};

  for (const field of fieldsFor(provider)) {
    if (field.kind !== "secret" && field.kind !== "textarea") continue;
    shape[field.name] = (CONFIG_FIELDS[field.name] ?? z.string()).optional().or(z.literal(""));
  }

  return z
    .object(shape)
    .superRefine((values, ctx) => {
      // At least one credential must actually be typed, or "replace
      // credentials" silently replaces them with nothing and the destination
      // keeps working until the next backup runs.
      const supplied = Object.entries(values).filter(([, v]) => String(v ?? "").trim() !== "");

      if (supplied.length === 0) {
        const first = Object.keys(shape)[0];
        ctx.addIssue({ code: "custom", path: [first], message: `required_${first}` });
      }
    });
}
