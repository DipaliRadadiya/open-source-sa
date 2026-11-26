import { z } from "zod";
import { isValidApplicationDomain } from "./application.js";

export const domainSchema = z.object({
  id: z.number(),
  domain: z.string(),
  type: z.enum(["primary", "alias", "redirect"]).catch("alias"),
  type_title: z.string().nullish(),
  redirect_to: z.string().nullish(),
  redirect_status: z.number().nullish(),
  is_test: z.boolean().default(false),
  dns_verified: z.boolean().default(false),
  dns_verified_at_human: z.string().nullish(),
  dns_resolved_ip: z.string().nullish(),
  behind_proxy: z.boolean().default(false),
  certifiable: z.boolean().default(true),
  created_at_human: z.string().nullish(),
}).passthrough();

export const domainsResponseSchema = z.object({
  domains: z.array(domainSchema).default([]),
});

export const certificateSchema = z.object({
  id: z.number().nullish(),
  type: z.string().nullish(),
  type_title: z.string().nullish(),
  status: z.string().nullish(),
  domains: z.array(z.string()).default([]),
  missing_domains: z.array(z.string()).default([]),
  // certbot fails the whole renewal if one of these names can't be validated.
  stale_domains: z.array(z.string()).default([]),
  force_https: z.boolean().default(false),
  auto_renew: z.boolean().default(false),
  renewable: z.boolean().default(false),
  issued_at: z.string().nullish(),
  expires_at: z.string().nullish(),
  expires_at_human: z.string().nullish(),
  // null means no TLS handshake completed, not all good.
  serving_stale: z.boolean().nullish(),
  served_expires_at: z.string().nullish(),
  served_checked_at: z.string().nullish(),
  days_remaining: z.number().nullish(),
  expired: z.boolean().default(false),
  expiring_soon: z.boolean().default(false),
  reason: z.string().nullish(),
  message: z.string().nullish(),
  reference: z.string().nullish(),
}).passthrough();

export const certificateTypeSchema = z.object({
  type: z.string(),
  label: z.string(),
  available: z.boolean().default(false),
  recommended: z.boolean().default(false),
  renewable: z.boolean().default(false),
  reason: z.string().nullish(),
});

export const certificateResponseSchema = z.object({
  certificate: certificateSchema.nullable(),
  available_types: z.array(certificateTypeSchema).default([]),
});

export const REDIRECT_STATUSES = [301, 302, 307, 308];

export const addDomainFormSchema = z
  .object({
    domain: z
      .string()
      .trim()
      .toLowerCase()
      .min(1, "domainRequired")
      .max(253, "hostnameTooLong")
      .refine((value) => value.split(".").every((label) => label.length <= 63), "hostnameLabelTooLong")
      .refine(isValidApplicationDomain, "hostnameInvalid"),
    type: z.enum(["alias", "redirect"]).default("alias"),
    redirect_to: z.string().trim().optional().default(""),
    redirect_status: z.coerce.number().refine((n) => REDIRECT_STATUSES.includes(n)).default(301),
  })
  .superRefine(redirectRules);

export const editDomainFormSchema = z
  .object({
    type: z.enum(["alias", "redirect"]).default("alias"),
    redirect_to: z.string().trim().optional().default(""),
    redirect_status: z.coerce.number().refine((n) => REDIRECT_STATUSES.includes(n)).default(301),
  })
  .superRefine(redirectRules);

function redirectRules(values, ctx) {
  if (values.type !== "redirect") return;
  if (!values.redirect_to.length) {
    ctx.addIssue({ code: "custom", path: ["redirect_to"], message: "redirectTargetRequired" });
    return;
  }
  if (!isHttpUrl(values.redirect_to)) {
    ctx.addIssue({ code: "custom", path: ["redirect_to"], message: "redirectTargetUrl" });
  }
}

function isHttpUrl(value) {
  let url;
  try {
    url = new URL(value);
  } catch {
    return false;
  }
  return (url.protocol === "http:" || url.protocol === "https:") && Boolean(url.hostname);
}
