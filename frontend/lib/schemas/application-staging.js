import { z } from "zod";
// Extension included: these schemas are also imported by node:test, whose ESM
// loader does not guess it.
import { applicationSchema, isValidApplicationDomain } from "./application.js";

// `staging: null` means the site never had one; a 404 means the site type cannot
// (staging is WordPress-only). Render them differently.
// A push or update-from-live, which runs on the queue since RC-16 (20 Oct): the POST
// answers 202 with this, and GET …/staging carries the latest one so a reload still
// shows a run in progress and how the last one ended.
export const stagingRunSchema = z.object({
  id: z.number(),
  kind: z.string(),
  mode: z.string().nullish(),
  backup: z.boolean().nullish(),
  status: z.string(),
  // What the endpoint used to answer when it failed: message, code/reason, reference.
  error: z
    .object({
      message: z.string().nullish(),
      code: z.string().nullish(),
      reason: z.string().nullish(),
      reference: z.string().nullish(),
    })
    .passthrough()
    .nullish(),
  error_status: z.number().nullish(),
  backup_id: z.number().nullish(),
  started_at_human: z.string().nullish(),
  finished_at_human: z.string().nullish(),
});

export const stagingRunActive = (run) => run?.status === "queued" || run?.status === "running";

export const applicationStagingResponseSchema = z.object({
  staging: applicationSchema.nullable().default(null),
  latest_run: stagingRunSchema.nullish(),
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
