import { z } from "zod";
// Extension included: these schemas are also imported by node:test, whose ESM
// loader does not guess it.
import { applicationSchema } from "./application.js";

// `staging: null` means the site never had one; a 404 means the site type cannot
// (staging is WordPress-only). Render them differently.
export const applicationStagingResponseSchema = z.object({
  staging: applicationSchema.nullable().default(null),
});

// `CreateStagingRequest`'s rule, duplicated from clone's so a server change to one does
// not silently apply to the other.
export const STAGING_DOMAIN_PATTERN = /^[a-z0-9.-]+\.[a-z]{2,}$/;

export const createStagingFormSchema = z.object({
  domain: z
    .string()
    .trim()
    .toLowerCase()
    .min(1, "stagingDomainRequired")
    .max(255, "max255")
    .regex(STAGING_DOMAIN_PATTERN, "stagingDomainInvalid"),
});

// No default: `files` runs `rsync --delete` (uploads kept), `database` can white-screen
// production if plugin versions differ, `full` does both; pre-push dumps can't be restored.
export const PUSH_MODES = ["files", "database", "full"];

export const pushStagingFormSchema = z.object({
  mode: z.enum(PUSH_MODES, { message: "modeRequired" }),
});
