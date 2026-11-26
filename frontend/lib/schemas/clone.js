import { z } from "zod";
import { isRedeploying } from "../applications/settled.js";
import { isValidApplicationDomain } from "./application.js";

export const cloneFormSchema = z.object({
  name: z.string().trim().max(255, "max255").optional(),
  domain: z
    .string()
    .trim()
    .toLowerCase()
    .min(1, "cloneDomainRequired")
    .max(255, "max255")
    .refine(isValidApplicationDomain, "cloneDomainInvalid"),
});

export const CLONE_IN_FLIGHT = ["pending", "running"];

const CLONE_STRATEGY_SITE_TYPES = ["wordpress"];

export function cloneBlockedReason(application, siteType) {
  if (application?.status === "failed") return "sourceFailed";
  if (isRedeploying(application)) return "deploying";
  if (application?.status !== "active") return "provisioning";
  if (!siteType) return null;
  if (siteType.needs_database && !CLONE_STRATEGY_SITE_TYPES.includes(siteType.name)) {
    return "noRecipe";
  }
  return null;
}

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

export function cloneDrops(application = null) {
  return application?.repository ? CLONE_DROPS : CLONE_DROPS.filter((key) => key !== "deploys");
}

export function defaultCloneName(sourceName, takenNames = []) {
  const taken = new Set(takenNames.map((value) => String(value)));
  const base = `${sourceName} (Clone)`;
  if (!taken.has(base)) return base;
  for (let suffix = 2; suffix < 1000; suffix += 1) {
    if (!taken.has(`${base} ${suffix}`)) return `${base} ${suffix}`;
  }
  return base;
}

export function cloneFailureTitle(clone) {
  const title = clone?.reason_title;
  if (!title) return null;
  if (/^clone\./.test(title) || /SQLSTATE|Connection:|\/var\/|\.php\b|Stack trace/i.test(title)) return null;
  return title;
}

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
