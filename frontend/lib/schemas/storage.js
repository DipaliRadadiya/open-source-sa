import { z } from "zod";

// Endpoint shapes for the S3-compatible providers people actually use. These
// are HINTS, not auto-filled values: every one of them contains a part only
// the account owner knows (an account id, a region), so filling the field with
// a template would just hand the user a value that fails validation. The
// backend has no concept of a provider — it takes any S3-compatible endpoint —
// so this list exists purely to answer "what does mine look like?".
export const STORAGE_PROVIDERS = [
  { value: "aws", endpointHint: "" },
  { value: "r2", endpointHint: "https://<account-id>.r2.cloudflarestorage.com" },
  { value: "b2", endpointHint: "https://s3.<region>.backblazeb2.com" },
  { value: "wasabi", endpointHint: "https://s3.<region>.wasabisys.com" },
  { value: "spaces", endpointHint: "https://<region>.digitaloceanspaces.com" },
  { value: "minio", endpointHint: "https://storage.example.com" },
  { value: "other", endpointHint: "" },
];

export const storageDestinationSchema = z
  .object({
    id: z.number(),
    name: z.string(),
    driver: z.string().default("s3"),
    endpoint: z.string().nullish(),
    region: z.string().nullish(),
    bucket: z.string(),
    prefix: z.string().nullish(),
    // Computed from the raw encrypted columns — the secrets themselves are
    // never sent, so this is the only thing the UI can know about them.
    has_credentials: z.boolean().default(false),
    // The last connection probe, as the backend now REMEMBERS it. This used to
    // be ephemeral and the UI was built on that assumption; it is persisted,
    // and cleared automatically when a key, endpoint, region or bucket changes,
    // so it never claims a rotated-out credential still works.
    status: z.string().nullish(),
    status_title: z.string().nullish(),
    last_tested_at: z.string().nullish(),
    last_tested_at_human: z.string().nullish(),
    // `null` is "never tested", which is a different state from `false`.
    last_test_success: z.boolean().nullish(),
    // A stable category — `invalid_credentials` | `unreachable`. Branch on it;
    // the raw SDK message is never sent (it can carry a partial access key).
    last_test_error: z.string().nullish(),
    created_at: z.string().nullish(),
    created_at_human: z.string().nullish(),
    updated_at: z.string().nullish(),
    updated_at_human: z.string().nullish(),
  })
  .passthrough();

export const storageDestinationsResponseSchema = z.object({
  storage_destinations: z.array(storageDestinationSchema).default([]),
});

/**
 * The connection probe.
 *
 * This endpoint answers **200 even when the probe fails** — deliberately: the
 * request succeeded, the panel went and looked, and this is what it found. The
 * verdict lives in `test.success`, so a caller that only checks for a thrown
 * error will report a dead bucket as working. (It did. That is why this schema
 * exists.)
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
// that isn't https.
const nameField = z.string().trim().min(1, "required_name").max(100, "max100");
const bucketField = z
  .string()
  .trim()
  .min(1, "required_bucket")
  .max(255, "max255")
  .regex(/^[A-Za-z0-9._-]+$/, "bucketFormat");
const regionField = z
  .union([z.literal(""), z.string().trim().max(64, "max64").regex(/^[A-Za-z0-9-]+$/, "regionFormat")])
  .optional();
const prefixField = z
  .union([z.literal(""), z.string().trim().max(255, "max255").regex(/^[A-Za-z0-9._/-]*$/, "prefixFormat")])
  .optional();
// Optional, but when given it has to be an https URL — the backend refuses
// loopback and the cloud metadata range, and a plain http endpoint would send
// the credentials in clear.
const endpointField = z
  .union([
    z.literal(""),
    z
      .string()
      .trim()
      .max(255, "max255")
      .regex(/^https:\/\/[^\s/$.?#].[^\s]*$/i, "endpointFormat")
      // An endpoint still containing <…> is the example copied verbatim with
      // the account id or region never filled in. It passes every other check
      // — no spaces, valid https — and saves happily, then fails at the first
      // backup. Someone did exactly this on the live panel while this feature
      // was being built, which is how the guard got written.
      .refine((value) => !/[<>]/.test(value), "endpointPlaceholder"),
  ])
  .optional();

export const createStorageDestinationSchema = z.object({
  provider: z.string().default("other"),
  name: nameField,
  endpoint: endpointField,
  region: regionField,
  bucket: bucketField,
  prefix: prefixField,
  access_key: z.string().trim().min(1, "required_accessKey").max(255, "max255"),
  secret_key: z.string().trim().min(1, "required_secretKey").max(512, "max512"),
});

// No credentials here at all. PATCH treats a present key as "rotate", so the
// only safe way to rename a destination is to never send them — rotation is
// its own dialog.
export const editStorageDestinationSchema = z.object({
  name: nameField,
  endpoint: endpointField,
  region: regionField,
  bucket: bucketField,
  prefix: prefixField,
});

export const replaceCredentialsSchema = z.object({
  access_key: z.string().trim().min(1, "required_accessKey").max(255, "max255"),
  secret_key: z.string().trim().min(1, "required_secretKey").max(512, "max512"),
});
