import { z } from "zod";
// Extension included deliberately: these schemas are imported by the node:test
// suite as well as the bundler, and Node's ESM loader does not guess it.
import { applicationSchema } from "./application.js";

/**
 * One site's staging copy.
 *
 * The staging site IS an application — the same resource, with `is_staging`
 * true and `production_application_id` pointing back here. That is why there
 * is no delete endpoint: you remove it the way you remove any site.
 *
 * `staging: null` means this site has never had one. A 404 from the endpoint
 * means something else entirely — staging is WordPress-only, so the site type
 * cannot have one at all. The screen says those two things differently.
 */
export const applicationStagingResponseSchema = z.object({
  staging: applicationSchema.nullable().default(null),
});

/**
 * The domain rule, copied from `CreateStagingRequest` deliberately.
 *
 * Identical to the clone rule because the backend validates both with the same
 * expression. Duplicated rather than shared so that if one of them changes on
 * the server, the other does not silently inherit it — a client rule that is
 * laxer than the server's promises an acceptance that will be refused.
 */
export const STAGING_DOMAIN_PATTERN = /^[a-z0-9.-]+\.[a-z]{2,}$/;

export const createStagingFormSchema = z.object({
  domain: z
    .string()
    .trim()
    .toLowerCase()
    .min(1, "stagingDomainRequired")
    .max(255, "tooLong")
    .regex(STAGING_DOMAIN_PATTERN, "stagingDomainInvalid"),
});

/**
 * What a push overwrites, per mode.
 *
 * Deliberately no default. `PushStagingRequest` calls `files` "the only mode
 * that cannot lose data" and tells the form to pre-select it; that is wrong.
 * `files` takes no safety copy of anything and still runs `rsync --delete`, so
 * production-only files go. `full` does dump the database first, but replaces
 * production's database wholesale. Both destroy something, they just destroy
 * different things — so the screen makes you choose rather than shipping one
 * of them as the thoughtless click.
 */
export const PUSH_MODES = ["files", "full"];

export const pushStagingFormSchema = z.object({
  mode: z.enum(PUSH_MODES, { message: "modeRequired" }),
});
