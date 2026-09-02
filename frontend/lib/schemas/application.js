import { z } from "zod";
import { listMetaSchema } from "./list.js";

const textField = z.object({
  name: z.string(),
  label: z.string(),
  type: z.string().default("text"),
  required: z.boolean().default(false),
  advanced: z.boolean().default(false),
  default: z.unknown().optional(),
  help: z.string().nullish(),
  placeholder: z.string().nullish(),
  options: z.array(z.object({ value: z.string(), label: z.string() })).default([]),
  source: z.string().nullish(),
  depends_on: z.string().nullish(),
  generate: z.boolean().default(false),
});

export const siteTypeSchema = z.object({
  name: z.string(),
  title: z.string(),
  tagline: z.string().nullish(),
  icon: z.string().nullish(),
  category: z.string().nullish(),
  popular: z.boolean().default(false),
  method: z.string().nullish(),
  serving_profile: z.string().nullish(),
  needs_database: z.boolean().default(false),
  available: z.boolean().default(true),
  unavailable_reason: z.string().nullish(),
  installable_runtime: z.string().nullish(),
  has_installer: z.boolean().default(false),
  fields: z.array(textField).default([]),
});

export const siteTypesResponseSchema = z.object({
  site_types: z.array(siteTypeSchema).default([]),
});

export const systemUserOptionSchema = z.object({
  id: z.number(),
  username: z.string(),
}).passthrough();

export const systemUsersResponseSchema = z.object({
  system_users: z.array(systemUserOptionSchema).default([]),
  meta: listMetaSchema,
});

// Read live from systemd on every request, so it is never stale — and absent
// entirely unless `has_process`.
const processSchema = z.object({
  state: z.string().nullish(),
  sub_state: z.string().nullish(),
  since: z.string().nullish(),
  memory: z.union([z.number(), z.string()]).nullish(),
  restarts: z.number().nullish(),
}).passthrough();

const webhookSchema = z.object({
  enabled: z.boolean().default(false),
  provider: z.string().nullish(),
  url: z.string().nullish(),
  secret: z.string().nullish(),
  verification: z.string().nullish(),
  last_delivered_at: z.string().nullish(),
  last_delivered_at_human: z.string().nullish(),
}).passthrough();

export const applicationSchema = z.object({
  id: z.number(),
  name: z.string(),
  domain: z.string(),
  /*
   * The address to actually open, decided by the server — `http://` until the
   * site has a servable certificate, which every site lacks for the first few
   * minutes of its life.
   *
   * Undeclared until now, so Zod stripped it and every reader silently fell
   * back to assembling `https://{domain}` themselves — which is a connection
   * refused on a site with no TLS listener. The ⋯ menu has carried that
   * fallback since it was written and it has been the only branch ever taken.
   * Same class as `disk_io`: the API sends it, the schema drops it, nothing
   * errors.
   */
  url: z.string().nullish(),
  site_type: z.string(),
  site_type_title: z.string().nullish(),
  serving_profile: z.string().nullish(),
  rendering_type: z.string().nullish(),
  status: z.enum(["pending", "provisioning", "active", "failed"]).catch("pending"),
  status_title: z.string().nullish(),
  deployed: z.boolean().default(false),
  system_user: systemUserOptionSchema.nullish(),
  php_version: z.string().nullish(),
  node_version: z.string().nullish(),
  app_port: z.number().nullish(),
  web_root: z.string().nullish(),
  // Where the site's code lives — the document root for most types, its parent
  // for the ones with a fixed web root (Laravel's `public`). That is exactly
  // what a cron command needs: `{path}/artisan` and `{path}/wp-cron.php` both
  // resolve correctly from it. Undeclared here it would be stripped, which is
  // the `disk_io` bug for the fourth time in this file.
  path: z.string().nullish(),
  build_command: z.string().nullish(),
  start_command: z.string().nullish(),
  git_account_id: z.number().nullish(),
  repository: z.string().nullish(),
  repository_url: z.string().nullish(),
  branch: z.string().nullish(),
  // PHP serializes an EMPTY associative array as `[]`, not `{}` — so `settings`
  // arrives as an array when a site has no type-specific answers. Coerce that
  // back to an object, or the whole list fails to parse the moment a real app
  // with empty settings exists.
  settings: z.preprocess(
    (value) => (Array.isArray(value) ? {} : value),
    z.record(z.string(), z.unknown()).default({}),
  ),
  // Declared, or Zod strips them and the dashboard renders blanks for fields
  // the API is sending — the `disk_io` bug again.
  has_process: z.boolean().default(false),
  process: processSchema.nullish(),
  webhook: webhookSchema.nullish(),
  basic_auth_enabled: z.boolean().default(false),
  basic_auth_username: z.string().nullish(),
  // False when the app's own client signs in with the Authorization header,
  // which Basic Auth would consume. Defaults true so a backend that predates
  // the flag keeps the control enabled rather than hiding a working feature.
  basic_auth_supported: z.boolean().default(true),
  is_disabled: z.boolean().default(false),
  disabled_at: z.string().nullish(),
  // "This site has a jail configured" — NOT "fail2ban is protecting this site".
  // Derived by the API from the same jail column its sibling endpoint reports,
  // since 2026-08-22: before that it came from a stored boolean whose only
  // writer was unreachable code, so it read false for every site on the server
  // including ones with a jail actively running, and the dashboard card
  // disagreed with the site's own fail2ban screen.
  //
  // Whether the fail2ban SERVICE is up is a server-wide fact this field cannot
  // answer — `GET /services` does. Live jail state (bans, counters) needs a
  // fail2ban-client call and has its own endpoint.
  fail2ban_enabled: z.boolean().default(false),
  ai_bot_policy: z.string().nullish(),
  ai_bot_policy_title: z.string().nullish(),
  // Per-bot overrides on top of the policy. `whenLoaded('botRules')` on the
  // backend, so today they only come back from the bot-blocker PUT — declared
  // here so they survive the moment a read endpoint exists.
  bot_blocked: z.array(z.string()).default([]),
  bot_allowed: z.array(z.string()).default([]),
  // npm | yarn | pnpm | bun, for the ssr/csr rendering types.
  package_manager: z.string().nullish(),
  is_staging: z.boolean().default(false),
  production_application_id: z.number().nullish(),
  has_staging: z.boolean().default(false),
  cloned_from_application_id: z.number().nullish(),
  // The 8G Firewall. `waf_exceptions`/`waf_custom_rules` are `whenLoaded` on the
  // backend — they come back from GET /applications/{id}/waf and from nowhere
  // else, so a page that needs them cannot reuse the plain application read.
  //
  // Deliberately NOT defaulted to []: absent means "not loaded", and defaulting
  // would render that as "this site has no exceptions" — a wrong answer that
  // looks like a real one. Callers that have loaded them coalesce at the point
  // of use; anywhere else `undefined` is the honest value.
  waf_enabled: z.boolean().default(false),
  waf_mode: z.string().nullish(),
  waf_mode_title: z.string().nullish(),
  waf_categories: z.array(z.string()).default([]),
  waf_exceptions: z.array(z.string()).optional(),
  waf_custom_rules: z.array(z.string()).optional(),
  last_commit: z.union([z.string(), z.record(z.string(), z.unknown())]).nullish(),
  last_deployed_at: z.string().nullish(),
  last_deployed_at_human: z.string().nullish(),
  steps: z.array(z.string()).default([]),
  failed_step: z.string().nullish(),
  // Set only where the cause is genuinely identified — usually null, and the
  // doc is explicit that this is not an omission: for most failures the step
  // plus the reference say more than an invented category would. Render the
  // title when present (already localized by the server), fall back to the
  // step otherwise.
  // True when a git site's account was deleted: the FK is nullOnDelete, so the
  // site keeps a repository and a branch and loses its credential. Nothing said
  // so — it looked exactly like a public-repository site until the next deploy
  // ran `git remote add origin ""` and failed.
  git_account_missing: z.boolean().nullish(),
  failed_reason: z.string().nullish(),
  failed_reason_title: z.string().nullish(),
  reference: z.string().nullish(),
  // The sites list shows these, so they have to be declared — this object does
  // not passthrough, and an undeclared field the API is sending is dropped
  // here in silence. The Size column read "Not measured" on every row of a
  // server whose sizes were all measured and stored, because the number never
  // survived parsing. Third time in this file: see `disk_io` above.
  directory_size_bytes: z.number().nullish(),
  directory_size_measured_at: z.string().nullish(),
  directory_size_measured_at_human: z.string().nullish(),
  created_at: z.string().nullish(),
  created_at_human: z.string().nullish(),
});

export const applicationsResponseSchema = z.object({
  applications: z.array(applicationSchema).default([]),
  meta: listMetaSchema,
});

export const applicationResponseSchema = z.object({
  application: applicationSchema,
});

export const portCheckResponseSchema = z.object({
  port_check: z.object({
    available: z.boolean(),
    reason: z.string().nullish(),
    service: z.string().nullish(),
    suggested_port: z.number().nullish(),
    message: z.string().nullish(),
  }),
});

// The catalog behind the AI Bot Blocker screen. Titles, descriptions, counts and
// the bot names themselves all come from here — `config/ai_bots.php` on the
// backend feeds both this endpoint and the vhost that does the blocking, so
// rendering from the response is what keeps the screen honest about what is
// actually enforced.
export const aiBotPolicySchema = z.object({
  title: z.string(),
  description: z.string(),
  blocked_bots: z.array(z.string()).default([]),
  blocked_count: z.number().default(0),
});

export const aiBotPoliciesResponseSchema = z.object({
  ai_bot_policies: z.record(z.string(), aiBotPolicySchema).default({}),
});

// Which AI bots actually hit this site, read from its access log.
// `status` carries the distinction that matters: `unavailable` (the log could
// not be read) is NOT the same claim as `empty` (it was read, nothing came),
// and showing "no bots visit you" for the first would be a confident lie.
export const botTrafficBotSchema = z.object({
  bot: z.string(),
  hits: z.number().default(0),
  // training | search | agent | custom
  category: z.string().nullish(),
  // What the CURRENT settings do to it — policy plus any per-bot rules.
  blocked: z.boolean().default(false),
  last_seen: z.string().nullish(),
  last_seen_human: z.string().nullish(),
});

export const botTrafficResponseSchema = z.object({
  bot_traffic: z.object({
    status: z.string().default("unavailable"),
    days: z.number().default(7),
    scanned_lines: z.number().default(0),
    since: z.string().nullish(),
    bots: z.array(botTrafficBotSchema).default([]),
    totals: z
      .object({
        bots: z.number().default(0),
        hits: z.number().default(0),
        blocked_hits: z.number().default(0),
      })
      .default({ bots: 0, hits: 0, blocked_hits: 0 }),
  }),
});

// The six rule categories and the two modes, for the Firewall screen's labels.
// Titles only today — the backend has no description per category, so the
// screen's own plain-language hints live in the message files (and a matching
// `description` field is an open request).
export const wafOptionSchema = z.object({
  value: z.string(),
  title: z.string(),
});

export const wafOptionsResponseSchema = z.object({
  waf_categories: z.array(wafOptionSchema).default([]),
  waf_modes: z.array(wafOptionSchema).default([]),
});

// `web_server` gates the firewall screen — the 8G ruleset has no OpenLiteSpeed
// implementation, and a screen that silently saves settings the server will
// never apply is worse than one that says so.
//
// `server_ip` and `temporary_domain_suffixes` are the server's own answer to
// "what address do sites point at, and which wildcard-DNS host resolves it".
// Both were being guessed on the client: the address came from a second call to
// /server/facts, and the suffix was a hardcoded "nip.io" constant.
export const serverCapabilitiesResponseSchema = z.object({
  capabilities: z.object({
    stack: z.string().nullish(),
    web_server: z.string().nullish(),
    server_ip: z.string().nullish(),
    temporary_domain_suffixes: z.array(z.string()).default([]),
  }),
});

// The API takes username+password together whenever `enabled` is true — there
// is no "just change the password" call — so both are required only in that
// branch, never when turning protection off.
export const securityFormSchema = z
  .object({
    enabled: z.boolean(),
    username: z.string(),
    password: z.string(),
  })
  .superRefine((data, ctx) => {
    if (!data.enabled) return;
    if (!data.username.trim()) {
      ctx.addIssue({ path: ["username"], code: "custom", message: "required_username" });
    } else if (data.username.includes(":")) {
      ctx.addIssue({ path: ["username"], code: "custom", message: "securityUsernameColon" });
    }
    if (!data.password) {
      ctx.addIssue({ path: ["password"], code: "custom", message: "required_password" });
    } else if (data.password.length < 8) {
      ctx.addIssue({ path: ["password"], code: "custom", message: "min8" });
    }
  });

const applicationDomainLabel =
  /^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/;

/** A hostname only — no protocol, path, query, credentials, or port. */
export function isValidApplicationDomain(value) {
  const domain = String(value ?? "").trim().toLowerCase();
  if (
    !domain ||
    domain.length > 253 ||
    domain.endsWith(".") ||
    domain.includes("://") ||
    /[/?#:@]/.test(domain)
  )
    return false;

  const labels = domain.split(".");
  return labels.length >= 2 && labels.every((label) => applicationDomainLabel.test(label));
}

/**
 * Turn a pasted URL into a hostname the form can offer back to the user.
 * This never mutates their input silently — the UI renders an explicit “Use …”
 * action when the candidate is valid and differs from what they entered.
 */
export function suggestApplicationDomain(value) {
  const entered = String(value ?? "").trim();
  if (!entered) return null;

  try {
    const parsed = new URL(
      entered.includes("://") ? entered : `http://${entered}`,
    );
    const candidate = parsed.hostname.toLowerCase().replace(/\.$/, "");
    return candidate !== entered && isValidApplicationDomain(candidate)
      ? candidate
      : null;
  } catch {
    return null;
  }
}

export const createApplicationSchema = z.object({
  site_type: z.string().min(1, "applicationTypeRequired"),
  name: z.string().trim().min(1, "applicationNameRequired").max(255, "tooLong"),
  domain: z
    .string()
    .trim()
    .min(1, "applicationDomainRequired")
    .max(255, "tooLong")
    .refine(isValidApplicationDomain, "hostnameInvalid"),
  system_user_id: z.coerce.number().int().positive("applicationSystemUserRequired"),
}).passthrough();
