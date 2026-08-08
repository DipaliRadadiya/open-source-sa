import { z } from "zod";

/**
 * The domain rule, copied from `CreateCloneRequest` deliberately.
 *
 * Same expression the backend validates with, so someone who types a bad
 * domain hears it from the field they are still standing in rather than from
 * a 422 after the request goes out. If the backend's rule ever changes this
 * has to change with it — a laxer client rule is worse than none, because it
 * promises an acceptance the server will refuse.
 */
export const CLONE_DOMAIN_PATTERN = /^[a-z0-9.-]+\.[a-z]{2,}$/;

export const cloneFormSchema = z.object({
  // Optional on the API: omitted, the backend names the copy "{source}
  // (Clone)". Offered here because `name` has no unique constraint, so two
  // clones of one site are otherwise identically named and tellable apart
  // only by domain.
  name: z.string().trim().max(255, "tooLong").optional(),
  domain: z
    .string()
    .trim()
    .toLowerCase()
    .min(1, "cloneDomainRequired")
    .max(255, "tooLong")
    .regex(CLONE_DOMAIN_PATTERN, "cloneDomainInvalid"),
});

/** One clone job, as `GET /api/clones/{id}` reports it. */
export const cloneSchema = z
  .object({
    id: z.number(),
    source_application_id: z.number().nullish(),
    source_application_name: z.string().nullish(),
    // Null until the job finishes — this is what the copy's own page hangs off.
    target_application_id: z.number().nullish(),
    name: z.string().nullish(),
    domain: z.string(),
    status: z.string(),
    status_title: z.string().nullish(),
    current_step: z.string().nullish(),
    current_step_title: z.string().nullish(),
    // Position in the sequence, so a bar can be drawn without the frontend
    // keeping its own copy of the step list — that list is backend config and
    // would drift the first time it changed.
    step_number: z.number().nullish(),
    total_steps: z.number().nullish(),
    reason: z.string().nullish(),
    reason_title: z.string().nullish(),
    reference: z.string().nullish(),
    started_at: z.string().nullish(),
    started_at_human: z.string().nullish(),
    finished_at: z.string().nullish(),
    finished_at_human: z.string().nullish(),
  })
  .passthrough();

export const cloneResponseSchema = z.object({ clone: cloneSchema });

/** Still working. Polling continues while the status is one of these. */
export const CLONE_IN_FLIGHT = ["pending", "running"];

/**
 * Site types that have a `CloneStrategy` on the backend.
 *
 * A type that needs a database and has no strategy is refused inside
 * `CloneManager` — it will not produce a clone whose config still points at
 * the source's own database. Knowing the list here is what lets the screen say
 * so before anyone types a domain, rather than after.
 *
 * Types with NO database never touch the strategy hook and always clone.
 */
const CLONE_STRATEGY_SITE_TYPES = ["wordpress"];

/**
 * Why this site cannot be cloned, or null when it can.
 *
 * Ordered by what the person can do about it: a site still being built will
 * be cloneable in a minute; a site type with no recipe will not be today.
 */
export function cloneBlockedReason(application, siteType) {
  // A site that never finished building is not "still being set up" — saying
  // so would leave someone waiting for a state that is not coming.
  if (application?.status === "failed") return "sourceFailed";
  if (application?.status !== "active") return "provisioning";
  if (!siteType) return null;
  if (siteType.needs_database && !CLONE_STRATEGY_SITE_TYPES.includes(siteType.name)) {
    return "noRecipe";
  }
  return null;
}

/**
 * What a clone does and does not inherit.
 *
 * Read off `CloneManager`'s create array and the per-application tables, not
 * guessed. Every panel that ships cloning documents this list somewhere;
 * putting it on the screen instead of in a doc is the whole point of this
 * feature's design — a password-protected site cloning to a public one is a
 * security surprise, not a convenience.
 */
export const CLONE_CARRIES = ["files", "phpVersion", "webRoot", "buildCommand", "repository"];

export const CLONE_DROPS = ["ssl", "backups", "cronJobs", "workers", "passwordProtection", "deploys"];

/** The database line only applies to types that have one. */
export function cloneCarries(siteType) {
  return siteType?.needs_database ? ["files", "database", ...CLONE_CARRIES.slice(1)] : CLONE_CARRIES;
}

/**
 * A domain to offer for the copy, derived from the source's own.
 *
 * `blog.example.com` → `copy.blog.example.com`. An empty field is the single
 * biggest piece of friction on this screen — WP Toolkit pre-fills both the
 * target and the database name — and a suggestion beats a placeholder because
 * it can be accepted rather than retyped.
 *
 * Falls back to `copy-2.`, `copy-3.` … when the obvious one is already taken,
 * so the offer is never a domain the API is about to reject.
 */
export function suggestCloneDomain(sourceDomain, takenDomains = []) {
  if (!sourceDomain) return "";
  const taken = new Set(takenDomains.map((value) => String(value).toLowerCase()));

  for (let attempt = 1; attempt < 50; attempt += 1) {
    const prefix = attempt === 1 ? "copy" : `copy-${attempt}`;
    const candidate = `${prefix}.${sourceDomain}`.toLowerCase();
    if (!taken.has(candidate) && candidate.length <= 255) return candidate;
  }
  return "";
}
