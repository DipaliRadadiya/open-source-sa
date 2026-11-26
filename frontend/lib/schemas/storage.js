import { z } from "zod";
// Relative import: the @/ alias breaks node --test.
import { fieldsFor, isRequired, providerForPreset } from "../storage/providers.js";
import { localStorageSettings } from "../storage/local-settings.js";

export const storageDestinationSchema = z
  .object({
    id: z.number(),
    name: z.string(),
    provider: z.string().default("s3"),
    provider_title: z.string().nullish(),
    config: z.record(z.string(), z.any()).default({}),
    prefix: z.string().nullish(),
    has_credentials: z.boolean().default(false),
    status: z.string().nullish(),
    status_title: z.string().nullish(),
    last_tested_at: z.string().nullish(),
    last_tested_at_human: z.string().nullish(),
    last_test_success: z.boolean().nullish(),
    last_test_error: z.string().nullish(),
    created_at: z.string().nullish(),
    created_at_human: z.string().nullish(),
    updated_at: z.string().nullish(),
    updated_at_human: z.string().nullish(),
  })
  .passthrough();

export const storageDestinationsResponseSchema = z.object({
  storage_destinations: z.array(storageDestinationSchema).default([]),

  google_oauth_redirect_uri: z.string().optional().nullable(),
  local: z.object({
    allowed_roots: z.array(z.string()),
    default_path: z.string(),
  }).nullish(),
});

export const storageTestResponseSchema = z.object({
  test: z.object({
    success: z.boolean().default(false),
    latency_ms: z.number().nullish(),
    message: z.string().nullish(),
    error_class: z.string().nullish(),
    tested_at: z.string().nullish(),
  }),
});

const nameField = z.string().trim().min(1, "required_name").max(100, "max100");
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

// Must be https; plain http would send credentials in clear text.
const endpointField = z
  .string()
  .trim()
  .max(255, "max255")
  .refine((value) => !/^http:\/\//i.test(value.trim()), "endpointInsecure")
  .regex(/^https:\/\/[^\s/$.?#].[^\s]*$/i, "endpointFormat")
  .refine((value) => !/[<>]/.test(value), "endpointPlaceholder");

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

function localPathField(settings) {
  const roots = localStorageSettings(settings).allowed_roots.map((root) => root.replace(/\/+$/, ""));
  return z.string().trim().max(255, "max255")
    .regex(/^\//, "localPathAbsolute")
    .refine((v) => !roots.includes(v.replace(/\/+$/, "")), "localPathRoot")
    .refine((v) => !/(^|\/)\.{1,2}(\/|$)/.test(v), "rootTraversal")
    .refine((v) => settings == null || roots.some((root) => v.startsWith(`${root}/`) && v.replace(/\/+$/, "") !== root), "localPathAllowed");
}

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
  path: localPathField(),
};

function configSchemaFor(preset, { requireSecrets = true, localSettings = null } = {}) {
  const provider = providerForPreset(preset);
  const shape = {};

  for (const field of fieldsFor(provider)) {
    let schema = field.name === "path" ? localPathField(localSettings) : CONFIG_FIELDS[field.name] ?? z.any();
    const required = isRequired(field, preset) && (requireSecrets || field.kind !== "secret");

    if (required) {
      const rules = schema;
      schema = z.any().superRefine((value, ctx) => {
        if (String(value ?? "").trim() === "") {
          ctx.addIssue({ code: "custom", message: "requiredField" });
          return;
        }
        const parsed = rules.safeParse(value);
        if (!parsed.success) for (const issue of parsed.error.issues) ctx.addIssue(issue);
      });
    } else {
      schema = schema.optional().or(z.literal(""));
    }

    shape[field.name] = schema;
  }

  return z.object(shape).passthrough();
}

export function createStorageDestinationSchema(preset, localSettings = null) {
  const provider = providerForPreset(preset);

  return z
    .object({
      name: nameField,
      prefix: prefixField,
      config: configSchemaFor(preset, { localSettings }),
    })
    .superRefine((values, ctx) => {
      if (provider !== "sftp") return;

      const hasPassword = Boolean(values.config?.password?.trim?.());
      const hasKey = Boolean(values.config?.private_key?.trim?.());

      if (!hasPassword && !hasKey) {
        ctx.addIssue({ code: "custom", path: ["config", "password"], message: "sftpAuthRequired" });
      }
    });
}

export function editStorageDestinationSchema(destination, localSettings = null) {
  if (destination?.provider !== "s3") {
    return z.object({
      name: nameField,
      prefix: prefixField,
      config: configSchemaFor(destination?.provider, { requireSecrets: false, localSettings }),
    });
  }

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
      // At least one credential, or replace would blank them.
      const supplied = Object.entries(values).filter(([, v]) => String(v ?? "").trim() !== "");

      if (supplied.length === 0) {
        const first = Object.keys(shape)[0];
        ctx.addIssue({ code: "custom", path: [first], message: `required_${first}` });
      }
    });
}
