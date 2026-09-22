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
  /*
   * Names ON the certificate that the site no longer has — the mirror of
   * `missing_domains`, and the more dangerous one. certbot fails a whole
   * renewal if any single name in the lineage cannot be validated, so a
   * certificate carrying a domain that has gone away has silently stopped
   * renewing for every other name on it too.
   */
  stale_domains: z.array(z.string()).default([]),
  force_https: z.boolean().default(false),
  auto_renew: z.boolean().default(false),
  renewable: z.boolean().default(false),
  issued_at: z.string().nullish(),
  expires_at: z.string().nullish(),
  expires_at_human: z.string().nullish(),
  /*
   * What the web server is actually PRESENTING, as against what is on disk.
   * They agree on a healthy site; when they do not, the file renewed and the
   * running server never picked it up, so the countdown above is reassuring
   * while every visitor gets a browser warning.
   *
   * `serving_stale` is deliberately nullable and NOT defaulted to false: null
   * means nobody managed to complete a handshake to look, which is not the
   * same as agreement and must never render as a tick.
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
    /*
     * Lowercased before it is checked, not rejected for being typed in caps.
     *
     * Hostnames are case-insensitive, and the backend already does
     * `strtolower(trim(...))` on this field before validating it — so
     * `Example.com` was always going to be accepted and stored as
     * `example.com`. Only this regex refused it, with "Enter a valid
     * hostname", which is both wrong and unactionable: the name IS valid.
     *
     * Normalising here rather than loosening the regex to /i means the value
     * the form submits is the value the server will store, so the row that
     * comes back is not a surprise.
     */
    domain: z
      .string()
      .trim()
      .toLowerCase()
      .min(1, "domainRequired")
      // The backend's own ceiling (`max:253`). Without it a long name was
      // accepted here and refused by the server, which puts a field problem in
      // a toast and leaves the box looking fine.
      .max(253, "hostnameTooLong")
      .regex(/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/, "hostnameInvalid"),
    type: z.enum(["alias", "redirect"]).default("alias"),
    /*
     * A full URL, checked here as well as server-side.
     *
     * The backend rule is Laravel's `url`, which requires a scheme — so
     * `example.com`, the most natural thing to type into a box labelled
     * "Redirect to", was accepted by this form and refused by the server with
     * "The redirect to field must be a valid URL." A field problem answered by
     * a round trip, and the message never says what is missing.
     *
     * http/https only, deliberately narrower than `URL` alone: `javascript:`
     * and `data:` both parse as URLs, and this value is written into the web
     * server's redirect directive.
     */
    redirect_to: z.string().trim().optional().default(""),
    redirect_status: z.coerce.number().refine((n) => REDIRECT_STATUSES.includes(n)).default(301),
  })
  .refine((v) => v.type !== "redirect" || v.redirect_to.length > 0, {
    path: ["redirect_to"],
    message: "redirectTargetRequired",
  })
  .refine((v) => v.type !== "redirect" || v.redirect_to.length === 0 || isHttpUrl(v.redirect_to), {
    path: ["redirect_to"],
    message: "redirectTargetUrl",
  });

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
