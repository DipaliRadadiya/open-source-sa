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
}).passthrough();

// `null` is a normal answer — "this site has no certificate" is a state to
// render, not an error.
export const certificateResponseSchema = z.object({
  certificate: certificateSchema.nullable(),
});
