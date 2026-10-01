import { z } from "zod";
import { isRedeploying } from "../applications/settled.js";

// From `CreateCloneRequest`; keep in step with the backend.
export const CLONE_DOMAIN_PATTERN = /^[a-z0-9.-]+\.[a-z]{2,}$/;

export const cloneFormSchema = z.object({
  // Optional: omitted, the backend names the copy "{source} (Clone)". `name`
  // is not unique, so this lets clones of one site be told apart.
  name: z.string().trim().max(255, "max255").optional(),
  domain: z
    .string()
    .trim()
    .toLowerCase()
    .min(1, "cloneDomainRequired")
    .max(255, "max255")
    .regex(CLONE_DOMAIN_PATTERN, "cloneDomainInvalid"),
});

/** One clone job, as `GET /api/clones/{id}` reports it. */
export const cloneSchema = z
  .object({
    id: z.number(),
    source_application_id: z.number().nullish(),
    source_application_name: z.string().nullish(),
    // Null until the job finishes.
    target_application_id: z.number().nullish(),
    // The copy's own deploy-on-push endpoint, when the source had one.
    target_webhook: z
      .object({
        url: z.string(),
        secret: z.string().nullish(),
        provider: z.string().nullish(),
      })
      .nullish(),
    name: z.string().nullish(),
    domain: z.string(),
    status: z.string(),
    status_title: z.string().nullish(),
    current_step: z.string().nullish(),
    current_step_title: z.string().nullish(),
    // Position in the sequence, so a bar needs no frontend copy of the step list.
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

// Types with a backend `CloneStrategy`; `CloneManager` refuses a database-backed type without one.
const CLONE_STRATEGY_SITE_TYPES = ["wordpress"];

// Ordered by what the person can do about it.
export function cloneBlockedReason(application, siteType) {
  // A failed build is not "still being set up"; that state is not coming.
  if (application?.status === "failed") return "sourceFailed";
  // A live site mid-deploy has code, but half of it may be the new commit.
  if (isRedeploying(application)) return "deploying";
  if (application?.status !== "active") return "provisioning";
  if (!siteType) return null;
  if (siteType.needs_database && !CLONE_STRATEGY_SITE_TYPES.includes(siteType.name)) {
    return "noRecipe";
  }
  return null;
}

// Read off `CloneManager`'s create array and the per-application tables.
export const CLONE_CARRIES = ["files", "phpVersion", "webRoot", "buildCommand", "repository"];

export const CLONE_DROPS = ["ssl", "backups", "cronJobs", "workers", "passwordProtection", "deploys"];

export function cloneCarries(siteType, application = null) {
  const git = Boolean(application?.repository);
  return [
    "files",
    ...(siteType?.needs_database ? ["database"] : []),
    "phpVersion",
    "webRoot",
    ...(git ? ["buildCommand", "repository"] : []),
  ];
}

/** Deploy-on-push is only something a copy "loses" when the source has a repository. */
export function cloneDrops(application = null) {
  return application?.repository ? CLONE_DROPS : CLONE_DROPS.filter((key) => key !== "deploys");
}

// Mirrors `Application::uniqueName()`: `{source} (Clone)`, then `(Clone) 2`… Untranslated, as stored.
export function defaultCloneName(sourceName, takenNames = []) {
  const taken = new Set(takenNames.map((value) => String(value)));
  const base = `${sourceName} (Clone)`;
  if (!taken.has(base)) return base;
  for (let suffix = 2; suffix < 1000; suffix += 1) {
    if (!taken.has(`${base} ${suffix}`)) return `${base} ${suffix}`;
  }
  return base;
}

// The backend may echo raw exception text in `reason_title`; that falls back to the generic sentence.
export function cloneFailureTitle(clone) {
  const title = clone?.reason_title;
  if (!title) return null;
  if (/^clone\./.test(title) || /SQLSTATE|Connection:|\/var\/|\.php\b|Stack trace/i.test(title)) return null;
  return title;
}

// `blog.example.com` → `copy.blog.example.com`, then `copy-2.`… so the offer is never one the API rejects.
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
