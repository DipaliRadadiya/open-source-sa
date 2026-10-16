import { z } from "zod";
// Extension included: these schemas are also imported by node:test, whose ESM
// loader does not guess it.
import { applicationSchema, isValidApplicationDomain } from "./application.js";

// `staging: null` means the site never had one; a 404 means the site type cannot
// (staging is WordPress-only). Render them differently.
export const applicationStagingResponseSchema = z.object({
  staging: applicationSchema.nullable().default(null),
});

export const createStagingFormSchema = z.object({
  domain: z
    .string()
    .trim()
    .toLowerCase()
    .min(1, "stagingDomainRequired")
    .max(255, "max255")
    // `CreateStagingRequest` uses the same Hostname rule as every other domain field.
    .refine(isValidApplicationDomain, "stagingDomainInvalid"),
});

// No default: `files` runs `rsync --delete` (uploads kept), `database` can white-screen
// production if plugin versions differ, `full` does both; pre-push dumps can't be restored.
export const PUSH_MODES = ["files", "database", "full"];

export const pushStagingFormSchema = z.object({
  mode: z.enum(PUSH_MODES, { message: "modeRequired" }),
});

// Database dumps taken before each push; the newest few are kept.
export const stagingSafetyCopiesSchema = z.object({
  safety_copies: z
    .array(z.object({ name: z.string(), size_bytes: z.number().nullish(), created_at: z.string().nullish() }))
    .default([]),
  kept: z.number().nullish(),
});
