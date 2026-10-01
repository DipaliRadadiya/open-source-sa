import { z } from "zod";
// Extension included: these schemas are also imported by node:test, whose ESM
// loader does not guess it.
import { applicationSchema } from "./application.js";

/**
 * One site's staging copy. The staging site IS an application (`is_staging`
 * true, `production_application_id` pointing back), so it is deleted like any
 * site.
 *
 * `staging: null` means this site never had one; a 404 means the site type
 * cannot have one (staging is WordPress-only). Render them differently.
 */
export const applicationStagingResponseSchema = z.object({
  staging: applicationSchema.nullable().default(null),
});

/**
/**
 * The domain rule from `CreateStagingRequest`. Identical to the clone rule but
 * duplicated so a server-side change to one does not silently apply to the
 * other. A laxer client rule promises an acceptance that will be refused.
 */
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

/**
/**
 * What a push overwrites, per mode. Deliberately no default: each mode destroys
 * something different.
 * - `files` runs `rsync --delete`, so production-only files go (uploads are kept).
 * - `database` replaces production's database under its existing files, which
 *   can white-screen if staging has plugin/theme versions production lacks.
 * - `full` does both. Pre-push dumps cannot be restored from the panel.
 *
 * Ordered by what each one replaces: files, database, both.
 */
export const PUSH_MODES = ["files", "database", "full"];

export const pushStagingFormSchema = z.object({
  mode: z.enum(PUSH_MODES, { message: "modeRequired" }),
});
