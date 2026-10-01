import { z } from "zod";
// Relative, not `@/`: the alias is a bundler feature and breaks `node --test`.
import { fieldsFor, isRequired, providerForPreset } from "../storage/providers.js";

export const storageDestinationSchema = z
  .object({
    id: z.number(),
    name: z.string(),
    // The real provider, read from the API; never infer it from the endpoint.
    provider: z.string().default("s3"),
    provider_title: z.string().nullish(),
    // Addressing detail only (bucket/region for S3, host/port for FTP/SFTP);
    // shape varies by provider, so never assume a bucket exists.
    config: z.record(z.string(), z.any()).default({}),
    prefix: z.string().nullish(),
    // Computed from the encrypted config; secrets are never sent.
    has_credentials: z.boolean().default(false),
    // The last connection probe, as the backend remembers it. Cleared whenever
    // the connection changes.
    status: z.string().nullish(),
    status_title: z.string().nullish(),
    last_tested_at: z.string().nullish(),
    last_tested_at_human: z.string().nullish(),
    // `null` is "never tested", which is a different state from `false`.
    last_test_success: z.boolean().nullish(),
    // A stable category: `invalid_credentials` | `unreachable` |
    // `host_key_mismatch` | `invalid_private_key` | `mismatch`. Always keep a
    // fallback: an unknown category must degrade to the generic message.
    last_test_error: z.string().nullish(),
    created_at: z.string().nullish(),
    created_at_human: z.string().nullish(),
    updated_at: z.string().nullish(),
    updated_at_human: z.string().nullish(),
  })
  .passthrough();

export const storageDestinationsResponseSchema = z.object({
  storage_destinations: z.array(storageDestinationSchema).default([]),

  // The callback URL to register with Google. Panel-wide and needed before
  // the first destination exists; optional for older APIs.
  google_oauth_redirect_uri: z.string().optional().nullable(),
});

/**
 * The connection probe. This endpoint answers **200 even when the probe
 * fails**; the verdict is in `test.success`, not in a thrown error.
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

// Mirror the backend rules so common mistakes are caught before a round trip.
const nameField = z.string().trim().min(1, "required_name").max(100, "max100");
// `.` and `..` segments refused: they escape the bucket or the FTP/SFTP root.
const prefixField = z
  .union([
    z.literal(""),
    z
      .string()
      .trim()
      .max(255, "max255")
      .regex(/^[A-Za-z0-9._/-]*$/, "prefixFormat")
      .refine((v) => !/(^|\/)\.{1,2}(\/|$)/.test(v), "prefixTraversal"),
  ])
  .optional();

const bucketField = z.string().trim().max(255, "max255").regex(/^[A-Za-z0-9._-]+$/, "bucketFormat");
const regionField = z.string().trim().max(64, "max64").regex(/^[A-Za-z0-9-]+$/, "regionFormat");

// Optional, but when given it must be https: the backend refuses loopback and
// the metadata range, and plain http would send credentials in clear.
const endpointField = z
  .string()
  .trim()
  .max(255, "max255")
  // `http://` gets its own message so a missing "s" is named, not reported as
  // an incomplete address.
  .refine((value) => !/^http:\/\//i.test(value.trim()), "endpointInsecure")
  .regex(/^https:\/\/[^\s/$.?#].[^\s]*$/i, "endpointFormat")
  // <…> means the example was copied without filling in the account id or
  // region; it passes every other check and fails at the first backup.
  .refine((value) => !/[<>]/.test(value), "endpointPlaceholder");

// A bare hostname or IP, never a URL. Mirrors `SafeRemoteHost`, which refuses
// a pasted URL rather than silently truncating it.
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

// A remote path; `..` traversal is refused.
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
 * Whatever the chosen preset needs, and nothing it doesn't. Built from the same
 * declaration the form renders from, so shown and validated fields cannot drift.
 */
function configSchemaFor(preset, { requireSecrets = true } = {}) {
  const provider = providerForPreset(preset);
  const shape = {};

  for (const field of fieldsFor(provider)) {
    let schema = CONFIG_FIELDS[field.name] ?? z.any();
    const required = isRequired(field, preset) && (requireSecrets || field.kind !== "secret");

    if (required) {
      /*
       * Emptiness is judged BEFORE the field's own rules, and stops there: an
       * untouched field is `undefined` (Zod's raw English error) and "" would
       * fail format rules like the bucket pattern.
       *
       * `requiredField`, not `required_<name>`: these names are the API's
       * snake_case, the `required_*` catalogue is camelCase. FormMessage builds
       * the sentence from the field's label.
       */
      const rules = schema;
      schema = z.any().superRefine((value, ctx) => {
        if (String(value ?? "").trim() === "") {
          ctx.addIssue({ code: "custom", message: "requiredField" });
          return;
        }
        const parsed = rules.safeParse(value);
        // Something was typed, so the field's own rule is the useful answer.
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
 * No `preset` key: the preset lives in component state because it selects the
 * schema. Declaring it here fails every submit on a field the form never
 * renders, with no visible error.
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
      // SFTP authenticates with a password OR a private key; require at least one.
      if (provider !== "sftp") return;

      const hasPassword = Boolean(values.config?.password?.trim?.());
      const hasKey = Boolean(values.config?.private_key?.trim?.());

      if (!hasPassword && !hasKey) {
        ctx.addIssue({ code: "custom", path: ["config", "password"], message: "sftpAuthRequired" });
      }
    });
}

/**
 * Editing sends no credentials: PATCH treats a present credential as "rotate"
 * (rotation is its own dialog). Provider is absent too; it is immutable on the API.
 */
export function editStorageDestinationSchema(destination) {
  if (destination?.provider !== "s3") {
    return z.object({
      name: nameField,
      prefix: prefixField,
      config: configSchemaFor(destination?.provider, { requireSecrets: false }),
    });
  }

  /*
   * The S3 preset is not known on edit, so neither field can carry a preset's
   * rule. The rule both presets share: an endpoint, or a region for Amazon S3.
   */
  return z
    .object({
      name: nameField,
      prefix: prefixField,
      config: configSchemaFor("other", { requireSecrets: false }).extend({
        endpoint: endpointField.optional().or(z.literal("")),
        region: regionField.optional().or(z.literal("")),
      }),
    })
    .superRefine((values, ctx) => {
      const endpoint = String(values.config?.endpoint ?? "").trim();
      const region = String(values.config?.region ?? "").trim();
      if (!endpoint && !region) {
        ctx.addIssue({ code: "custom", path: ["config", "region"], message: "requiredField" });
      }
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
      // At least one credential must be typed, or "replace" would blank them.
      const supplied = Object.entries(values).filter(([, v]) => String(v ?? "").trim() !== "");

      if (supplied.length === 0) {
        const first = Object.keys(shape)[0];
        ctx.addIssue({ code: "custom", path: [first], message: `required_${first}` });
      }
    });
}
