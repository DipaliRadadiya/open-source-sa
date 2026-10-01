import { z } from "zod";
import { isValidApplicationDomain } from "./application.js";

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
  // Names on the certificate the site no longer has. certbot fails the whole
  // renewal if any name cannot be validated, so these stop renewal for all names.
  stale_domains: z.array(z.string()).default([]),
  force_https: z.boolean().default(false),
  auto_renew: z.boolean().default(false),
  renewable: z.boolean().default(false),
  issued_at: z.string().nullish(),
  expires_at: z.string().nullish(),
  expires_at_human: z.string().nullish(),
  /*
   * What the web server is actually presenting, versus what is on disk; they
   * differ when a renewal was never picked up. `serving_stale` is nullable and
   * NOT defaulted: null means no handshake completed, never "all good".
   */
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

/**
 * What this site can be issued, decided server-side. Only `available` gates a
 * choice; `reason` may be informational on an available type, so never branch
 * on its presence.
 */
export const certificateTypeSchema = z.object({
  type: z.string(),
  label: z.string(),
  available: z.boolean().default(false),
  recommended: z.boolean().default(false),
  renewable: z.boolean().default(false),
  reason: z.string().nullish(),
});

// `null` certificate is a normal state, not an error. `available_types` sits
// beside it because it describes what the site could have.
export const certificateResponseSchema = z.object({
  certificate: certificateSchema.nullable(),
  available_types: z.array(certificateTypeSchema).default([]),
});

// Redirect targets used by the add-domain form.
export const REDIRECT_STATUSES = [301, 302, 307, 308];

// Add-domain form. Messages are `validation`-namespace keys; the backend does
// the authoritative check and its 422 is mapped onto the field. `primary` is not
// an option: promoting a name is a separate endpoint.
export const addDomainFormSchema = z
  .object({
    // Lowercased rather than rejected: the backend stores `strtolower(trim(...))`
    // anyway, so the submitted value matches what comes back.
    domain: z
      .string()
      .trim()
      .toLowerCase()
      .min(1, "domainRequired")
      // The backend's own ceiling (`max:253`).
      .max(253, "hostnameTooLong")
      // Each label is capped at 63, as on the backend.
      .refine((value) => value.split(".").every((label) => label.length <= 63), "hostnameLabelTooLong")
      .refine(isValidApplicationDomain, "hostnameInvalid"),
    type: z.enum(["alias", "redirect"]).default("alias"),
    /*
     * A full URL with a scheme (Laravel's `url` rule). http/https only:
     * `javascript:` and `data:` parse as URLs, and this value is written into
     * the web server's redirect directive.
     */
    redirect_to: z.string().trim().optional().default(""),
    redirect_status: z.coerce.number().refine((n) => REDIRECT_STATUSES.includes(n)).default(301),
  })
  .superRefine(redirectRules);

/**
 * Edit form for an attached name: what it does, never what it is called.
 * `PUT …/domains/{domain}` accepts exactly these fields; redirect rules are
 * shared with the add form.
 */
export const editDomainFormSchema = z
  .object({
    type: z.enum(["alias", "redirect"]).default("alias"),
    redirect_to: z.string().trim().optional().default(""),
    redirect_status: z.coerce.number().refine((n) => REDIRECT_STATUSES.includes(n)).default(301),
  })
  .superRefine(redirectRules);

/** A redirect needs a target, and the target has to be a real http(s) one. */
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

/** `http(s)://host…`, and nothing else. */
function isHttpUrl(value) {
  let url;
  try {
    url = new URL(value);
  } catch {
    return false;
  }
  // A bare hostname parses as nothing; `javascript:alert(1)` parses fine.
  return (url.protocol === "http:" || url.protocol === "https:") && Boolean(url.hostname);
}
