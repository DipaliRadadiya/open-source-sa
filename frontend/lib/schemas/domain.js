import { z } from "zod";

/**
 * A site is not one hostname: every name it answers to is a row, and `type`
 * says what that name does — canonical, a second name for the same content, or
 * a redirect that serves nothing.
 */
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
  force_https: z.boolean().default(false),
  auto_renew: z.boolean().default(false),
  renewable: z.boolean().default(false),
  issued_at: z.string().nullish(),
  expires_at: z.string().nullish(),
  expires_at_human: z.string().nullish(),
  days_remaining: z.number().nullish(),
  expired: z.boolean().default(false),
  expiring_soon: z.boolean().default(false),
  reason: z.string().nullish(),
  message: z.string().nullish(),
  reference: z.string().nullish(),
}).passthrough();

/**
 * What this site can actually be issued, decided server-side.
 *
 * `available` is the only thing that gates a choice. `reason` explains it
 * either way: on an unavailable type it says what to fix, on an available one
 * it is informational (self-signed works everywhere, browsers warn) — so
 * branching on the presence of a reason would refuse a type that works.
 */
export const certificateTypeSchema = z.object({
  type: z.string(),
  label: z.string(),
  available: z.boolean().default(false),
  recommended: z.boolean().default(false),
  renewable: z.boolean().default(false),
  reason: z.string().nullish(),
});

// `null` is a normal answer — "this site has no certificate" is a state to
// render, not an error. `available_types` sits beside it, not inside it: it
// describes what the site COULD have, which is exactly the question when there
// is no certificate yet.
export const certificateResponseSchema = z.object({
  certificate: certificateSchema.nullable(),
  available_types: z.array(certificateTypeSchema).default([]),
});

// Redirect targets used by the add-domain form.
export const REDIRECT_STATUSES = [301, 302, 307, 308];

// Add-domain form. Messages are `validation`-namespace keys (FormMessage
// translates them); the backend does the authoritative hostname/uniqueness
// check and its 422 is mapped onto the field. `primary` is intentionally not an
// option — promoting a name is a separate endpoint.
export const addDomainFormSchema = z
  .object({
    domain: z
      .string()
      .trim()
      .min(1, "domainRequired")
      .regex(/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/, "hostnameInvalid"),
    type: z.enum(["alias", "redirect"]).default("alias"),
    redirect_to: z.string().trim().optional().default(""),
    redirect_status: z.coerce.number().refine((n) => REDIRECT_STATUSES.includes(n)).default(301),
  })
  .refine((v) => v.type !== "redirect" || v.redirect_to.length > 0, {
    path: ["redirect_to"],
    message: "redirectTargetRequired",
  });
